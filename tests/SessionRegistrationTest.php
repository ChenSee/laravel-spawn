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
