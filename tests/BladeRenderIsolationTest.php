<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\ViewServiceProvider;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;

/**
 * Two responses being rendered at once do not write into one set of sections.
 *
 * `@section`, `@push` and `@once` are recorded on the view factory while a view renders,
 * and a render suspends whenever it touches the world — a composer querying the database,
 * a lazy relation in a template, the first compile of a template reading the disk. Shared,
 * a page goes out with another page's title, with its `@push` blocks doubled or missing,
 * and with `@once` blocks skipped because another coroutine had already run them.
 *
 * suspend() stands in for those suspension points and makes the interleaving certain
 * rather than occasional.
 */
class BladeRenderIsolationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * @param  ?callable  $configure  run against the factory bootstrap leaves behind, which
     *   is the last moment configuration reaches every request rather than one copy
     */
    private function bootedApp(?callable $configure = null): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('files', new Filesystem());
        $app->instance('config', new Repository([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'view'  => ['paths' => [sys_get_temp_dir()], 'compiled' => sys_get_temp_dir()],
        ]));

        $app->register(EventServiceProvider::class);
        $app->register(ViewServiceProvider::class);
        $app->register(AsyncServiceProvider::class);
        $app->boot();

        /* One factory for the worker; what a request gets of its own is the render state. */
        $factory = $app->make('view');

        if ($configure !== null) {
            $configure($factory);
        }

        /* What WorkerBootstrap does, in the same order. */
        $factory->bootCompleted();
        $app->enableAsyncMode();

        return $app;
    }

    public function test_a_section_opened_in_one_request_is_not_yielded_by_the_other(): void
    {
        $app = $this->bootedApp();

        $titleOf = function (string $title) use ($app): string {
            $app->make('view')->startSection('title', $title);

            \Async\suspend();

            return $app->make('view')->yieldContent('title');
        };

        $rendered = $this->runParallel([
            'first'  => fn () => $titleOf('First page'),
            'second' => fn () => $titleOf('Second page'),
        ]);

        $this->assertSame('First page', $rendered['first']);
        $this->assertSame('Second page', $rendered['second']);
    }

    public function test_a_push_stack_holds_only_what_this_request_pushed(): void
    {
        $app = $this->bootedApp();

        $scriptsOf = function (string $script) use ($app): string {
            $app->make('view')->startPush('scripts', $script);

            \Async\suspend();

            return $app->make('view')->yieldPushContent('scripts');
        };

        $rendered = $this->runParallel([
            'first'  => fn () => $scriptsOf('<script src="first.js"></script>'),
            'second' => fn () => $scriptsOf('<script src="second.js"></script>'),
        ]);

        $this->assertSame('<script src="first.js"></script>', $rendered['first']);
        $this->assertSame('<script src="second.js"></script>', $rendered['second']);
    }

    public function test_a_once_block_runs_once_in_every_request(): void
    {
        $app = $this->bootedApp();

        $renderedHere = function () use ($app): bool {
            \Async\suspend();

            $view = $app->make('view');
            $first = ! $view->hasRenderedOnce('modal-script');

            $view->markAsRenderedOnce('modal-script');

            return $first;
        };

        $rendered = $this->runParallel([
            'first'  => $renderedHere,
            'second' => $renderedHere,
        ]);

        $this->assertSame(
            ['first' => true, 'second' => true],
            $rendered,
            'a @once block another coroutine had already rendered was skipped',
        );
    }

    public function test_the_factory_keeps_what_bootstrap_configured(): void
    {
        $app = $this->bootedApp(fn ($factory) => $factory->share('brand', 'Acme'));

        $shared = $this->runParallel([
            'first'  => fn () => $app->make('view')->getShared()['brand'] ?? null,
            'second' => fn () => $app->make('view')->getShared()['brand'] ?? null,
        ]);

        $this->assertSame(['first' => 'Acme', 'second' => 'Acme'], $shared);
    }
}
