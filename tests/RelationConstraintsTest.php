<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use ReflectionProperty;
use Spawn\Laravel\Database\Eloquent\RelationConstraints;
use Spawn\Laravel\Tests\Fixtures\RelationItem;
use Spawn\Laravel\Tests\Fixtures\RelationOwner;
use stdClass;

use function Async\suspend;

/**
 * Eloquent switches Relation::$constraints off while it builds a relation for an eager load,
 * and back on afterwards. The property is thread state shared by every coroutine of a worker,
 * and the callback it wraps yields as soon as a model boots against config or cache — so
 * without the patch a sibling coroutine builds its relations inside somebody else's window and
 * loses its `where foreign_key = ?`, and two overlapping windows restore each other's captured
 * value and leave the flag off for the life of the worker.
 *
 * The interleaves here are driven by handshakes rather than by sleeping for long enough: a
 * timing-based test that misses its interleave passes, and passes for the wrong reason.
 */
class RelationConstraintsTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetPatchState();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('relation_owners', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('owner_key');
        });

        Capsule::schema()->create('relation_items', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('owner_id');
        });

        Capsule::table('relation_owners')->insert([
            ['id' => 1, 'owner_key' => 1],
            ['id' => 2, 'owner_key' => 2],
        ]);

        Capsule::table('relation_items')->insert([
            ['id' => 1, 'owner_id' => 1],
            ['id' => 2, 'owner_id' => 2],
            ['id' => 3, 'owner_id' => 2],
        ]);
    }

    public function test_patch_is_installed(): void
    {
        $this->assertStringStartsWith('installed:', RelationConstraints::status());
        $this->assertTrue(RelationConstraints::startServing());
    }

    public function test_sibling_coroutine_keeps_its_where_clause(): void
    {
        $gate = $this->gate();

        $results = $this->runParallel([
            'window' => function () use ($gate) {
                Relation::noConstraints(function () use ($gate) {
                    $gate->open = true;
                    $this->until(fn () => $gate->read);

                    return null;
                });
            },
            'owner-1' => function () use ($gate) {
                $this->until(fn () => $gate->open);

                try {
                    return RelationOwner::find(1)->items()->count();
                } finally {
                    $gate->read = true;
                }
            },
        ]);

        $this->assertSame(1, $results['owner-1'], 'a sibling must see its own row, not every owner\'s');
    }

    public function test_one_of_many_keeps_its_where_clause(): void
    {
        $gate = $this->gate();

        $results = $this->runParallel([
            'window' => function () use ($gate) {
                Relation::noConstraints(function () use ($gate) {
                    $gate->open = true;
                    $this->until(fn () => $gate->read);

                    return null;
                });
            },
            'owner-2' => function () use ($gate) {
                $this->until(fn () => $gate->open);

                try {
                    return RelationOwner::find(2)->latestItem()->first()?->id;
                } finally {
                    $gate->read = true;
                }
            },
        ]);

        $this->assertSame(3, $results['owner-2'], 'latestOfMany() constrains a subquery of its own');
    }

    public function test_a_window_survives_a_yield_inside_add_constraints(): void
    {
        $gate = $this->gate();

        $results = $this->runParallel([
            'window' => function () use ($gate) {
                Relation::noConstraints(function () use ($gate) {
                    $gate->open = true;
                    $this->until(fn () => $gate->read);

                    return null;
                });
            },
            // The relation is built inside the sibling's window, and the accessor of its local
            // key suspends in the middle of addConstraints() — the constraint has to survive
            // both the window and the suspension.
            'reader' => function () use ($gate) {
                $this->until(fn () => $gate->open);

                try {
                    return RelationOwner::find(2)->slowItems()->count();
                } finally {
                    $gate->read = true;
                }
            },
        ]);

        $this->assertSame(2, $results['reader']);
        $this->assertTrue($this->flag()->getValue(), 'the flag must be back on once the last window closes');
    }

    public function test_overlapping_windows_leave_the_flag_on(): void
    {
        $gate = $this->gate();

        $this->runParallel([
            'first' => function () use ($gate) {
                Relation::noConstraints(function () use ($gate) {
                    $gate->open = true;
                    $this->until(fn () => $gate->second);

                    return null;
                });

                $gate->read = true;
            },
            'second' => function () use ($gate) {
                $this->until(fn () => $gate->open);

                Relation::noConstraints(function () use ($gate) {
                    $gate->second = true;
                    $this->until(fn () => $gate->read);

                    return null;
                });
            },
        ]);

        $this->assertTrue($this->flag()->getValue(), 'the flag must be back on once the last window closes');
        $this->assertSame(2, RelationOwner::find(2)->items()->count(), 'a later request must still be constrained');
    }

    public function test_eager_loading_still_asks_for_every_owner_at_once(): void
    {
        Capsule::connection()->enableQueryLog();

        $owners = RelationOwner::with('items')->get();

        $log = Capsule::connection()->getQueryLog();

        $this->assertCount(2, $log, 'one query for the owners and one for all their items');
        $this->assertStringContainsString(' in (', $log[1]['query']);
        $this->assertStringNotContainsString('"owner_id" = ?', $log[1]['query']);
        $this->assertSame(1, $owners[0]->items->count());
        $this->assertSame(2, $owners[1]->items->count());
    }

    public function test_lazy_load_is_constrained_outside_any_window(): void
    {
        $this->assertSame(1, RelationOwner::find(1)->items()->count());
        $this->assertSame(2, RelationItem::query()->where('owner_id', 2)->count());
    }

    /**
     * The rewritten class holds its window stack in thread state, and a test that leaves one
     * behind would answer for the next. Reset before each, so a leak fails here rather than
     * somewhere unrelated, and put the thread into the serving mode a worker runs in.
     */
    private function resetPatchState(): void
    {
        foreach (['spawnWindows' => [], 'spawnBaseline' => true, 'constraints' => true] as $name => $value) {
            // The three named here exist only once the patch is in place, and the suite has to
            // reach its assertions with it switched off too — that is how the tests are shown
            // to fail for the bug rather than for a missing property.
            if (property_exists(Relation::class, $name)) {
                (new ReflectionProperty(Relation::class, $name))->setValue(null, $value);
            }
        }

        RelationConstraints::startServing();
    }

    private function flag(): ReflectionProperty
    {
        return new ReflectionProperty(Relation::class, 'constraints');
    }

    /**
     * A handshake between coroutines: plain flags one side sets and the other waits on.
     */
    private function gate(): stdClass
    {
        $gate = new stdClass();
        $gate->open = false;
        $gate->read = false;
        $gate->second = false;

        return $gate;
    }

    /**
     * Yield until the condition holds. runParallel() caps the whole thing at five seconds,
     * so a condition that never becomes true fails the test instead of hanging the suite.
     */
    private function until(callable $condition): void
    {
        while (! $condition()) {
            suspend();
        }
    }
}
