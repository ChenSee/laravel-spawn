<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\Auth\AsyncAuthManager;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\ScopedService;
use Spawn\Laravel\Tests\Fixtures\BotTokenAuthServiceProvider;
use Spawn\Laravel\Tests\Fixtures\BotUser;

use function Async\current_context;

class AuthIsolationTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * Serve one request: whatever the server puts in the context, the application
     * reads back out of it.
     */
    private function withRequest(string $token, callable $handle): mixed
    {
        $request = Request::create('/bot', 'GET');
        $request->headers->set(BotTokenAuthServiceProvider::TOKEN_HEADER, $token);

        current_context()->set(ScopedService::REQUEST, $request);

        return $handle($request);
    }

    private function reboundRequestCallbacks(AsyncApplication $app): int
    {
        return count((fn () => $this->reboundCallbacks)->call($app)['request'] ?? []);
    }

    public function test_driver_registered_at_boot_is_usable_in_a_coroutine(): void
    {
        $app = $this->bootedApp();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->withRequest('token-a', fn () => Auth::guard('bot')->user()),
        ]);

        $this->assertInstanceOf(BotUser::class, $results['a']);
        $this->assertSame('token-a', $results['a']->token);
    }

    public function test_authenticated_user_does_not_leak_between_coroutines(): void
    {
        $app = $this->bootedApp();
        $app->enableAsyncMode();

        $authenticate = fn (string $token) => $this->withRequest(
            $token,
            fn () => Auth::guard('bot')->user()->token,
        );

        $results = $this->runParallel([
            'a' => fn () => $authenticate('a'),
            'b' => fn () => $authenticate('b'),
            'c' => fn () => $authenticate('c'),
        ]);

        $this->assertSame(['a' => 'a', 'b' => 'b', 'c' => 'c'], $results);
    }

    public function test_each_coroutine_gets_its_own_manager_and_guard(): void
    {
        $app = $this->bootedApp();
        $bootManager = $app->make('auth');
        $app->enableAsyncMode();

        $identify = fn (string $token) => $this->withRequest($token, function () use ($app) {
            $manager = $app->make('auth');
            $guard   = $manager->guard('bot');
            $guard->user();

            return [spl_object_id($manager), spl_object_id($guard)];
        });

        $results = $this->runParallel([
            'a' => fn () => $identify('a'),
            'b' => fn () => $identify('b'),
        ]);

        $this->assertNotSame($results['a'][0], $results['b'][0], 'the manager must not be shared');
        $this->assertNotSame($results['a'][1], $results['b'][1], 'the guard must not be shared');
        $this->assertNotSame(spl_object_id($bootManager), $results['a'][0]);
    }

    public function test_user_authenticated_during_bootstrap_is_not_inherited(): void
    {
        $app = $this->bootedApp();

        $bootRequest = Request::create('/bot', 'GET');
        $bootRequest->headers->set(BotTokenAuthServiceProvider::TOKEN_HEADER, 'boot');
        $app->instance('request', $bootRequest);
        $this->assertSame('boot', $app->make('auth')->guard('bot')->user()->token);

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->withRequest('a', fn () => Auth::guard('bot')->user()->token),
        ]);

        $this->assertSame('a', $results['a'], 'a guard holds the user of the request that built it');
    }

    public function test_serving_requests_leaves_the_boot_time_manager_untouched(): void
    {
        $app = $this->bootedApp();
        $bootManager = $app->make('auth');
        $app->enableAsyncMode();

        $this->runParallel([
            'a' => fn () => $this->withRequest('a', fn () => Auth::guard('bot')->user()),
            'b' => fn () => $this->withRequest('b', fn () => Auth::guard('bot')->user()),
        ]);

        $this->assertFalse(
            $bootManager->hasResolvedGuards(),
            'the boot-time manager is the template for every coroutine and must stay unused',
        );
    }

    public function test_extend_and_provider_registrations_are_adopted(): void
    {
        $app = $this->bootedApp();

        $app->make('config')->set('auth.guards.custom', ['driver' => 'custom-driver', 'provider' => 'custom-provider']);
        $app->make('config')->set('auth.providers.custom-provider', ['driver' => 'custom-provider']);

        Auth::provider('custom-provider', fn () => new \stdClass());
        Auth::extend('custom-driver', function ($container, $name, array $config) {
            return new CustomDriverGuard(
                $this->createUserProvider($config['provider'] ?? null),
                spl_object_id($this),
            );
        });

        $app->enableAsyncMode();

        $describe = fn (string $token) => $this->withRequest($token, function () use ($app) {
            $guard = $app->make('auth')->guard('custom');

            return ['provider' => $guard->userProvider, 'manager' => $guard->managerId];
        });

        $results = $this->runParallel([
            'a' => fn () => $describe('a'),
            'b' => fn () => $describe('b'),
        ]);

        $this->assertInstanceOf(\stdClass::class, $results['a']['provider'], 'provider() must be adopted too');
        $this->assertNotSame(
            $results['a']['manager'],
            $results['b']['manager'],
            'an adopted creator must resolve against the manager of its own coroutine',
        );
    }

    public function test_user_resolver_supplied_by_the_application_is_adopted(): void
    {
        $app = $this->bootedApp();
        $app->make('auth')->resolveUsersUsing(fn () => new BotUser('resolver-marker'));

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->withRequest('a', fn () => call_user_func($app->make('auth')->userResolver())->token),
        ]);

        $this->assertSame('resolver-marker', $results['a']);
    }

    public function test_default_user_resolver_is_not_adopted(): void
    {
        $app = $this->bootedApp();
        $app->make('auth');

        $app->enableAsyncMode();

        $resolve = fn (string $token) => $this->withRequest(
            $token,
            fn () => call_user_func($app->make('auth')->userResolver(), 'bot')?->token,
        );

        $results = $this->runParallel([
            'a' => fn () => $resolve('a'),
            'b' => fn () => $resolve('b'),
        ]);

        $this->assertSame(
            ['a' => 'a', 'b' => 'b'],
            $results,
            'the constructor default resolves through the manager that made it',
        );
    }

    /**
     * A driver registered twice is the registration made last, in a coroutine as at boot.
     *
     * Two ways exist to register the same driver name — viaRequest() and extend() — and
     * the manager keeps whichever came last. A coroutine that adopts the earlier one
     * authenticates every request with a guard the application replaced on purpose.
     */
    public function test_a_driver_re_registered_with_extend_keeps_the_later_registration(): void
    {
        $app = $this->bootedApp();

        /* The application overrides the driver its own package registered via viaRequest(). */
        Auth::extend(BotTokenAuthServiceProvider::DRIVER, fn ($container) => new RequestGuard(
            fn () => new BotUser('from-extend'),
            $container['request'],
        ));

        $this->assertSame('from-extend', $app->make('auth')->guard('bot')->user()->token, 'last one wins at boot');

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->withRequest('a', fn () => $app->make('auth')->guard('bot')->user()->token),
        ]);

        $this->assertSame('from-extend', $results['a'], 'and last one wins in a coroutine');
    }

    public function test_manager_rebound_by_the_application_still_gets_its_registrations(): void
    {
        $app = $this->bootedApp([]);
        $app->singleton('auth', fn ($container) => new AuthManager($container));

        $app->make('auth')->viaRequest(
            BotTokenAuthServiceProvider::DRIVER,
            fn (Request $request) => new BotUser((string) $request->headers->get(BotTokenAuthServiceProvider::TOKEN_HEADER)),
        );

        $app->enableAsyncMode();

        $authenticate = fn (string $token) => $this->withRequest(
            $token,
            fn () => $app->make('auth')->guard('bot')->user()->token,
        );

        $results = $this->runParallel([
            'a' => fn () => $authenticate('a'),
            'b' => fn () => $authenticate('b'),
        ]);

        $this->assertSame(['a' => 'a', 'b' => 'b'], $results);
    }

    /**
     * scopedSingleton() is what replaces a scoped service, and it replaces it whole.
     *
     * A test that wants a double in place of the auth manager has one way to install
     * it: the container binding a coroutine actually builds from. It takes precedence
     * over the ordinary binding, it reaches the facade as well as make(), and the
     * seeder leaves an object it does not recognise alone.
     */
    public function test_a_scoped_singleton_replaces_the_manager_for_every_coroutine(): void
    {
        $app = $this->bootedApp();
        $double = new AuthManagerDouble();

        $app->scopedSingleton('auth', fn () => $double);
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->withRequest('a', fn () => [$app->make('auth'), Auth::guard('bot')]),
        ]);

        $this->assertSame($double, $results['a'][0], 'make() must reach the double');
        $this->assertSame('double', $results['a'][1], 'and so must the facade');
    }

    public function test_a_service_never_resolved_at_boot_reports_its_own_missing_driver(): void
    {
        $app = $this->bootedApp([]);
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->withRequest('a', function () use ($app) {
                $manager = $app->make('auth');

                try {
                    $manager->guard('bot');
                } catch (\InvalidArgumentException $e) {
                    return [$manager::class, $e->getMessage()];
                }

                return [$manager::class, null];
            }),
        ]);

        $this->assertSame(AsyncAuthManager::class, $results['a'][0]);
        $this->assertStringContainsString('is not defined', (string) $results['a'][1]);
    }

    public function test_enabling_async_mode_twice_keeps_the_registrations(): void
    {
        $app = $this->bootedApp();
        $app->enableAsyncMode();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->withRequest('a', fn () => Auth::guard('bot')->user()->token),
        ]);

        $this->assertSame('a', $results['a']);
    }

    public function test_guards_that_follow_the_request_do_not_pile_up_in_the_container(): void
    {
        $app = $this->bootedApp();
        $app->make('config')->set('auth.guards.refreshing', ['driver' => 'refreshing-driver']);

        // How every stock guard is built — session and token drivers included.
        Auth::extend('refreshing-driver', function ($container) {
            $guard = new RequestGuard(fn (Request $request) => new BotUser(
                (string) $request->headers->get(BotTokenAuthServiceProvider::TOKEN_HEADER)
            ), $container['request']);

            $container->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        $app->enableAsyncMode();
        $before = $this->reboundRequestCallbacks($app);

        $authenticate = fn (string $token) => $this->withRequest(
            $token,
            fn () => $app->make('auth')->guard('refreshing')->user()->token,
        );

        $results = $this->runParallel([
            'a' => fn () => $authenticate('a'),
            'b' => fn () => $authenticate('b'),
            'c' => fn () => $authenticate('c'),
        ]);

        $this->assertSame(['a' => 'a', 'b' => 'b', 'c' => 'c'], $results);
        $this->assertSame(
            $before,
            $this->reboundRequestCallbacks($app),
            'a guard that outlives nothing must not subscribe to the shared container',
        );
    }

    public function test_objects_shared_across_requests_still_follow_the_request(): void
    {
        $app = $this->bootedApp();
        $app->enableAsyncMode();

        // How the framework keeps the URL generator on the current request.
        $shared = new \stdClass();
        $shared->request = null;
        $app->rebinding('request', function ($container, $request) use ($shared) {
            $shared->request = $request;
        });

        $this->runParallel([
            'a' => fn () => $this->withRequest('a', fn (Request $request) => $app->instance('request', $request)),
        ]);

        $this->assertSame(
            'a',
            $shared->request?->headers->get(BotTokenAuthServiceProvider::TOKEN_HEADER),
            'rebinding() is how shared singletons track the request and must keep working',
        );
    }

    public function test_session_store_is_not_shared_between_coroutines(): void
    {
        $app = $this->bootedApp();
        $app->singleton('session', fn () => new SessionManagerStub());
        $app->singleton('session.store', fn ($container) => $container->make('session')->driver());
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->withRequest('a', fn () => spl_object_id($app->make('session.store'))),
            'b' => fn () => $this->withRequest('b', fn () => spl_object_id($app->make('session.store'))),
        ]);

        $this->assertNotSame(
            $results['a'],
            $results['b'],
            'the stock session guard reads the authenticated user out of this store',
        );
    }

    public function test_redirector_flashes_into_the_session_of_its_own_coroutine(): void
    {
        $app = $this->bootedApp();
        $app->singleton('session', fn () => new SessionManagerStub());
        $app->singleton('session.store', fn ($container) => $container->make('session')->driver());
        $app->singleton('url', fn () => new \Illuminate\Routing\UrlGenerator(
            new \Illuminate\Routing\RouteCollection(),
            Request::create('/', 'GET'),
        ));
        $app->enableAsyncMode();

        $flashTarget = fn (string $token) => $this->withRequest($token, function () use ($app) {
            $redirector = $app->make('redirect');

            return spl_object_id((fn () => $this->session)->call($redirector));
        });

        $results = $this->runParallel([
            'a' => fn () => $flashTarget('a'),
            'b' => fn () => $flashTarget('b'),
        ]);

        $this->assertNotSame(
            $results['a'],
            $results['b'],
            'redirect()->with() must reach the session of the request that redirected',
        );
    }

    public function test_auth_driver_alias_resolves_the_guard_of_its_coroutine(): void
    {
        $app = $this->bootedApp();
        $app->enableAsyncMode();

        $authenticate = fn (string $token) => $this->withRequest(
            $token,
            fn () => $app->make('auth.driver')->user()->token,
        );

        $results = $this->runParallel([
            'a' => fn () => $authenticate('a'),
            'b' => fn () => $authenticate('b'),
        ]);

        $this->assertSame(['a' => 'a', 'b' => 'b'], $results);
    }
}

/**
 * Session manager stripped to what the container cares about: it hands out a driver,
 * and each manager hands out its own.
 */
class SessionManagerStub
{
    private ?\Illuminate\Session\Store $driver = null;

    public function driver(): \Illuminate\Session\Store
    {
        return $this->driver ??= new \Illuminate\Session\Store('async-test', new \SessionHandler());
    }

    public function extend(string $driver, \Closure $callback): void
    {
    }
}

/**
 * What a test puts in place of the auth manager: not an AuthManager at all, which is
 * the case a seeder has to survive.
 */
class AuthManagerDouble
{
    public function guard(?string $name = null): string
    {
        return 'double';
    }
}

/**
 * Guard that reports which manager built it, so a test can tell whose registrations
 * an adopted creator resolved against.
 */
class CustomDriverGuard extends RequestGuard
{
    public function __construct(public readonly mixed $userProvider, public readonly int $managerId)
    {
        parent::__construct(fn () => null, Request::create('/', 'GET'));
    }
}
