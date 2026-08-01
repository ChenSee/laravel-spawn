<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\RequestGuard;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Auth\AsyncAuthManager;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\ScopedService;
use Spawn\Laravel\Tests\Fixtures\BotTokenAuthServiceProvider;
use Spawn\Laravel\Tests\Fixtures\BotUser;

use function Async\current_context;

class AuthIsolationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * An application that has booted its providers and has not started serving yet —
     * the state every worker is in when async mode is switched on.
     *
     * @param  string[]  $providers  application providers, booted after the package's own
     */
    private function bootedApp(array $providers = [BotTokenAuthServiceProvider::class]): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('files', new Filesystem());
        $app->instance('config', new Repository([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'auth'  => [
                'defaults' => ['guard' => 'bot'],
                'guards'   => ['bot' => ['driver' => BotTokenAuthServiceProvider::DRIVER]],
            ],
        ]));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $app->register(EventServiceProvider::class);
        $app->register(\Illuminate\Auth\AuthServiceProvider::class);
        $app->register(AsyncServiceProvider::class);

        foreach ($providers as $provider) {
            $app->register($provider);
        }

        $app->boot();

        return $app;
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
