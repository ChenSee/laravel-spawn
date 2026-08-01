<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Session;
use Spawn\Laravel\Foundation\AsyncApplication;

class ScopedSeederTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * A container that has not started serving yet, with 'widgets' declared scoped.
     *
     * @param  string[]  $scoped
     */
    private function container(array $scoped = ['widgets']): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new Repository(['async' => ['scoped_services' => $scoped]]));

        return $app;
    }

    public function test_extend_decorates_the_instance_each_coroutine_receives(): void
    {
        $app = $this->container();
        $app->bind('widgets', fn () => new Widget());
        $app->extend('widgets', function (Widget $widget) {
            $widget->decorated = true;

            return $widget;
        });

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertTrue($results['a']->decorated, 'extend() is part of how a service is built');
    }

    public function test_extend_registered_after_the_first_resolve_still_decorates(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());

        /* A provider that resolves the service and only then decorates it. */
        $app->make('widgets');
        $app->extend('widgets', function (Widget $widget) {
            $widget->decorated = true;

            return $widget;
        });

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertTrue($results['a']->decorated, 'a scoped service is resolved again after extend()');
    }

    public function test_extend_registered_while_serving_reaches_later_coroutines(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());
        $app->make('widgets');
        $app->enableAsyncMode();

        /* A deferred provider loaded inside the first request decorates the service. */
        $this->runParallel(['first' => function () use ($app) {
            $app->extend('widgets', function (Widget $widget) {
                $widget->decorated = true;

                return $widget;
            });
        }]);

        $results = $this->runParallel(['later' => fn () => $app->make('widgets')]);

        $this->assertTrue($results['later']->decorated);
    }

    public function test_scoped_bindings_of_the_framework_are_resolved_per_coroutine(): void
    {
        $app = $this->container([]);

        /* Laravel's own request-scoped registration, which only a queue worker flushes. */
        $app->scoped('widgets', fn () => new Widget());
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make('widgets'),
            'b' => fn () => $app->make('widgets'),
        ]);

        $this->assertNotSame($results['a'], $results['b']);
    }

    public function test_a_service_declared_scoped_by_class_name_is_scoped(): void
    {
        $app = $this->container([Widget::class]);
        $app->bind('widgets', fn () => new Widget());
        $app->alias('widgets', Widget::class);

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make(Widget::class),
            'b' => fn () => $app->make(Widget::class),
        ]);

        $this->assertNotSame($results['a'], $results['b'], 'config/async.php is written in class names');
    }

    public function test_a_build_with_parameters_is_not_served_from_the_context(): void
    {
        $app = $this->container();
        $app->bind('widgets', fn ($container, array $parameters) => new Widget($parameters['mark'] ?? null));

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => function () use ($app) {
            $app->make('widgets');

            return $app->makeWith('widgets', ['mark' => 'built-for-me']);
        }]);

        $this->assertSame('built-for-me', $results['a']->mark, 'parameters must reach the factory');
    }

    public function test_after_resolving_callback_fires_once_per_instance(): void
    {
        $app = $this->container();
        $app->bind('widgets', fn () => new Widget());

        $calls = 0;
        $app->afterResolving('widgets', function () use (&$calls) {
            $calls++;
        });

        $app->enableAsyncMode();

        $this->runParallel(['a' => fn () => $app->make('widgets')]);
        $this->assertSame(1, $calls);

        $this->runParallel(['b' => fn () => $app->make('widgets')]);
        $this->assertSame(2, $calls, 'one call per instance handed out, no more and no fewer');
    }

    public function test_a_callback_may_resolve_the_service_it_is_called_for(): void
    {
        $app = $this->container();
        $app->bind('widgets', fn () => new Widget());

        $resolvedFromCallback = null;
        $app->afterResolving('widgets', function ($widget, $container) use (&$resolvedFromCallback) {
            $resolvedFromCallback = $container->make('widgets');
        });

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertSame($results['a'], $resolvedFromCallback, 're-entrant resolution must reach the same instance');
    }

    public function test_seeder_carries_boot_time_registrations_into_every_coroutine(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());
        $app->scopedSeeder('widgets', fn (Widget $fresh, Widget $bootTime) => $fresh->register($bootTime->registered()));

        $bootTime = $app->make('widgets');
        $bootTime->register(['at-boot']);

        $app->enableAsyncMode();

        $use = fn (string $mark) => function () use ($app, $mark) {
            $widget = $app->make('widgets');
            $widget->register([$mark]);

            return $widget->registered();
        };

        $results = $this->runParallel(['a' => $use('per-request-a'), 'b' => $use('per-request-b')]);

        $this->assertSame(['at-boot', 'per-request-a'], $results['a']);
        $this->assertSame(['at-boot', 'per-request-b'], $results['b'], 'one coroutine must not see another one');
        $this->assertSame(['at-boot'], $bootTime->registered(), 'serving must not write back into the template');
    }

    public function test_seeder_is_skipped_when_bootstrap_left_nothing_behind(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());

        $seederCalls = 0;
        $app->scopedSeeder('widgets', function () use (&$seederCalls) {
            $seederCalls++;
        });

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertInstanceOf(Widget::class, $results['a']);
        $this->assertSame(0, $seederCalls, 'a service never resolved at boot carries no configuration');
    }

    public function test_facade_is_not_pinned_to_the_boot_time_instance(): void
    {
        $app = $this->container([]);
        $app->bind('session', fn () => new Widget());

        Facade::setFacadeApplication($app);
        $bootId = Session::identify();

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => Session::identify(),
            'b' => fn () => Session::identify(),
        ]);

        $this->assertNotSame($results['a'], $results['b'], 'the facade must reach each coroutine own instance');
        $this->assertNotSame($bootId, $results['a']);
    }
}

/**
 * Stand-in for a manager: something registered once at boot, something written per
 * request, and an identity a test can compare.
 */
class Widget
{
    public bool $decorated = false;

    private array $registered = [];

    public function __construct(public readonly ?string $mark = null)
    {
    }

    public function register(array $names): void
    {
        $this->registered = array_merge($this->registered, $names);
    }

    public function registered(): array
    {
        return $this->registered;
    }

    public function identify(): int
    {
        return spl_object_id($this);
    }
}
