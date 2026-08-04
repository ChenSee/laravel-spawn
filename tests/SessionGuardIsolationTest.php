<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\ScopedService;

use function Async\current_context;
use function Async\delay;

/**
 * The guard a real application uses. Unlike a request-callback guard, it reads the
 * authenticated user out of the session store, so every object between the request
 * and that store has to belong to the request.
 */
class SessionGuardIsolationTest extends AsyncTestCase
{
    use BootsHttpApplication;

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function sessionKey(): string
    {
        return 'login_web_'.sha1(SessionGuard::class);
    }

    private function webApp(): AsyncApplication
    {
        $app = $this->httpApp(SessionKernel::class);

        /* The application's own user provider, registered once at boot. */
        Auth::provider('memory', fn () => new MemoryUserProvider());

        return $app;
    }

    public function test_the_web_guard_writes_the_login_into_the_session_of_its_own_request(): void
    {
        $app = $this->webApp();
        $key = $this->sessionKey();

        $app->make('router')->get('/login', function (Request $request) use ($app, $key) {
            $app->make('auth')->guard('web')->login(new MemoryUser((string) $request->query('id')));
            delay(30);

            return (string) $app->make('session.store')->get($key);
        });

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->get($app, '/login?id=AAA'),
            'b' => fn () => $this->get($app, '/login?id=BBB'),
        ]);

        $this->assertSame(['a' => 'AAA', 'b' => 'BBB'], $results);
    }

    public function test_the_web_guard_holds_the_session_store_of_its_own_request(): void
    {
        $app = $this->webApp();

        $app->make('router')->get('/whose', function () use ($app) {
            $guard = $app->make('auth')->guard('web');
            delay(20);

            return implode(':', [
                spl_object_id((fn () => $this->session)->call($guard)),
                spl_object_id($app->make('session.store')),
            ]);
        });

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->get($app, '/whose'),
            'b' => fn () => $this->get($app, '/whose'),
        ]);

        [$guardStoreA, $containerStoreA] = explode(':', $results['a']);
        [$guardStoreB, $containerStoreB] = explode(':', $results['b']);

        $this->assertSame($containerStoreA, $guardStoreA, 'the guard must read the store of its own request');
        $this->assertSame($containerStoreB, $guardStoreB);
        $this->assertNotSame($guardStoreA, $guardStoreB, 'two requests must not share one store');
    }

    public function test_a_user_provider_registered_at_boot_serves_every_request(): void
    {
        $app = $this->webApp();

        $app->make('router')->get('/provider', function () use ($app) {
            $guard = $app->make('auth')->guard('web');

            return (fn () => $this->provider)->call($guard)::class;
        });

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->get($app, '/provider'),
            'b' => fn () => $this->get($app, '/provider'),
        ]);

        $this->assertSame(
            ['a' => MemoryUserProvider::class, 'b' => MemoryUserProvider::class],
            $results,
            'Auth::provider() at boot must reach the guard of every request',
        );
    }

    public function test_the_session_the_middleware_started_belongs_to_the_request(): void
    {
        $app = $this->webApp();

        $app->make('router')->get('/flash', function (Request $request) {
            $id = (string) $request->query('id');
            $request->session()->put('who', $id);
            delay(30);

            return (string) $request->session()->get('who');
        });

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->get($app, '/flash?id=AAA'),
            'b' => fn () => $this->get($app, '/flash?id=BBB'),
        ]);

        $this->assertSame(['a' => 'AAA', 'b' => 'BBB'], $results);
    }

    public function test_two_concurrent_requests_are_not_handed_the_same_session(): void
    {
        $app = $this->webApp();

        $app->make('router')->get('/login', function (Request $request) use ($app) {
            $app->make('auth')->guard('web')->login(new MemoryUser((string) $request->query('id')));
            delay(30);

            return 'ok';
        });

        $app->enableAsyncMode();

        $sessionCookie = function (string $uri) use ($app): string {
            $request = Request::create($uri, 'GET');
            current_context()->set(ScopedService::REQUEST, $request);

            $kernel   = $app->make(KernelContract::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            foreach ($response->headers->getCookies() as $cookie) {
                if ($cookie->getName() === 'spawn_session') {
                    return $cookie->getValue();
                }
            }

            return 'no-cookie';
        };

        $results = $this->runParallel([
            'a' => fn () => $sessionCookie('/login?id=AAA'),
            'b' => fn () => $sessionCookie('/login?id=BBB'),
        ]);

        $this->assertNotSame(
            $results['a'],
            $results['b'],
            'each request must leave with a session cookie of its own',
        );
    }
}
