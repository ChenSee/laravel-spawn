<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Support\Facades\Session;

/**
 * A session handler is registered on the manager during boot, the same way an auth
 * driver is, and is lost the same way if a coroutine builds its own manager.
 */
class SessionRegistrationTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_handler_registered_at_boot_is_available_in_a_coroutine(): void
    {
        $app = $this->bootedApp([]);
        $app->singleton('session', fn ($container) => new \Illuminate\Session\SessionManager($container));

        Session::extend('stub', fn () => new \SessionHandler());

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make('session')->driver()->getHandler(),
            'b' => fn () => $app->make('session')->driver()->getHandler(),
        ]);

        $this->assertInstanceOf(\SessionHandler::class, $results['a']);
        $this->assertNotSame($results['a'], $results['b'], 'each coroutine builds its own handler');
    }

    public function test_a_driver_registered_with_a_static_closure_does_not_break_resolution(): void
    {
        $app = $this->bootedApp([]);
        $app->singleton('session', fn ($container) => new \Illuminate\Session\SessionManager($container));

        /* Manager::extend() cannot bind a static closure and stores null instead. */
        Session::extend('stub', static fn () => new \SessionHandler());

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make('session')::class,
        ]);

        $this->assertSame(\Illuminate\Session\SessionManager::class, $results['a']);
    }

    public function test_seeding_a_service_that_is_not_a_manager_does_nothing(): void
    {
        $app = $this->bootedApp([]);
        $app->singleton('widgets', fn () => new \stdClass());
        $app->scopedSingleton('widgets', fn () => new \stdClass());
        $app->scopedSeeder('widgets', \Spawn\Laravel\Foundation\ManagerRegistrations::seed(...));
        $app->make('widgets');

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertInstanceOf(\stdClass::class, $results['a']);
    }

    public function test_socialite_driver_registered_at_boot_is_available_in_a_coroutine(): void
    {
        $app = $this->bootedApp([]);
        $app->singleton(
            \Laravel\Socialite\Contracts\Factory::class,
            fn ($container) => new \Laravel\Socialite\SocialiteManager($container),
        );

        $app->make(\Laravel\Socialite\Contracts\Factory::class)->extend('acme', fn () => new \stdClass());

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make(\Laravel\Socialite\Contracts\Factory::class)->driver('acme'),
            'b' => fn () => $app->make(\Laravel\Socialite\Contracts\Factory::class)->driver('acme'),
        ]);

        $this->assertInstanceOf(\stdClass::class, $results['a']);
        $this->assertNotSame($results['a'], $results['b'], 'each coroutine builds its own driver');
    }
}
