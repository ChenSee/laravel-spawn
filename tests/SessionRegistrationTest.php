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

    /**
     * The seeder is registered for a service the application may have rebound to
     * anything at all, so it has to be a no-op on what it does not understand — and a
     * silent one: a PHP warning per resolve is a warning on every request.
     *
     * @dataProvider nonManagerPairs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonManagerPairs')]
    public function test_seeding_between_a_manager_and_a_stranger_is_silent(string $case): void
    {
        $app = $this->bootedApp([]);
        $manager = new \Illuminate\Session\SessionManager($app);
        $stranger = new \stdClass();

        [$target, $prototype] = match ($case) {
            'stranger-prototype' => [$manager, $stranger],
            'stranger-target'    => [$stranger, $manager],
            'both-strangers'     => [$stranger, new \stdClass()],
        };

        $prototype instanceof \Illuminate\Session\SessionManager
            && $prototype->extend('stub', fn () => new \SessionHandler());

        $raised = [];
        set_error_handler(function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            \Spawn\Laravel\Foundation\ManagerRegistrations::seed($target, $prototype);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised, 'seeding a stranger must not report anything to the log');

        $registeredOnTarget = $target instanceof \Illuminate\Support\Manager
            ? (fn () => $this->customCreators)->call($target)
            : (array) $target;

        $this->assertSame([], $registeredOnTarget, 'the target must come out of it exactly as it went in');
    }

    public static function nonManagerPairs(): array
    {
        return [
            'prototype is not a manager' => ['stranger-prototype'],
            'target is not a manager'    => ['stranger-target'],
            'neither is a manager'       => ['both-strangers'],
        ];
    }

    /**
     * Registrations cross over; the drivers built from them do not.
     *
     * A driver holds the state of the request that built it — a session Store holds one
     * visitor's session — which is the whole reason the manager is resolved per
     * coroutine in the first place.
     */
    public function test_a_driver_resolved_at_boot_is_not_carried_into_a_coroutine(): void
    {
        $app = $this->bootedApp([]);
        $app->singleton('session', fn ($container) => new \Illuminate\Session\SessionManager($container));

        Session::extend('stub', fn () => new \SessionHandler());

        /* Something during bootstrap touches the session — a health check, a warm-up. */
        $bootManager = $app->make('session');
        $bootDriver  = $bootManager->driver();

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('session')->driver()]);

        $this->assertNotSame($bootDriver, $results['a'], 'a coroutine must not inherit a resolved driver');
        $this->assertSame(
            ['stub' => $bootDriver],
            $bootManager->getDrivers(),
            'and must not write its own into the template either',
        );
    }

    /**
     * Within one request the store the session guard reads is the store the manager
     * hands out. Two objects here means the user is written to one session and read
     * back from another.
     */
    public function test_the_session_store_is_the_driver_of_the_manager_of_the_same_request(): void
    {
        $app = $this->bootedApp([]);
        $app->make('config')->set('session', [
            'driver'   => 'stub',
            'lifetime' => 120,
            'cookie'   => 'async-test',
        ]);
        $app->register(\Illuminate\Session\SessionServiceProvider::class);

        Session::extend('stub', fn () => new \SessionHandler());

        $identify = fn () => [
            'store'   => $app->make('session.store'),
            'manager' => $app->make('session')->driver(),
        ];

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => $identify, 'b' => $identify]);

        $this->assertSame($results['a']['store'], $results['a']['manager'], 'one request, one session store');
        $this->assertSame($results['b']['store'], $results['b']['manager'], 'and the same for every other request');
        $this->assertNotSame($results['a']['store'], $results['b']['store'], 'two requests, two sessions');
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
