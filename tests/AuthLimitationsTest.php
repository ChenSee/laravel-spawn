<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\Foundation\ScopedService;
use Spawn\Laravel\Tests\Fixtures\BotTokenAuthServiceProvider;
use Spawn\Laravel\Tests\Fixtures\ManagerProbe;

use function Async\current_context;

/**
 * What adopting a boot-time registration cannot do, asserted so that it stays visible.
 *
 * A creator registered with Auth::extend() is re-bound to each coroutine's own manager,
 * which fixes `$this` inside it and nothing else. A creator that captured the manager in
 * a `use` clause goes on resolving against the object it captured, in every coroutine,
 * because a captured variable is part of the closure and not of its scope.
 *
 * This test asserts the broken behaviour. A change that makes it pass differently has
 * removed the limitation, and should say so in ASYNC_KNOWN_ISSUES.md §2.
 */
class AuthLimitationsTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * Serve one request: whatever the server puts in the context, the application reads
     * back out of it.
     */
    private function withRequest(string $token, callable $handle): mixed
    {
        $request = Request::create('/bot', 'GET');
        $request->headers->set(BotTokenAuthServiceProvider::TOKEN_HEADER, $token);

        current_context()->set(ScopedService::REQUEST, $request);

        return $handle();
    }

    public function test_a_creator_that_captured_the_manager_keeps_it_in_every_coroutine(): void
    {
        $app = $this->bootedApp();

        $app->make('config')->set('auth.guards.captured', ['driver' => 'captured-driver', 'provider' => null]);

        $bootManager = $app->make('auth');

        /* The form the limitation is about: the manager comes from the closure, not $this. */
        Auth::extend('captured-driver', function () use ($bootManager) {
            return new ManagerProbe(spl_object_id($bootManager));
        });

        $app->enableAsyncMode();

        $managerBehind = fn (string $token) => $this->withRequest(
            $token,
            fn () => $app->make('auth')->guard('captured')->managerId,
        );

        $seen = $this->runParallel([
            'a' => fn () => $managerBehind('a'),
            'b' => fn () => $managerBehind('b'),
        ]);

        $this->assertSame(spl_object_id($bootManager), $seen['a']);
        $this->assertSame(
            $seen['a'],
            $seen['b'],
            'both coroutines resolve against the captured manager, which is the limitation',
        );
    }

    public function test_the_same_creator_written_without_a_capture_is_isolated(): void
    {
        $app = $this->bootedApp();

        $app->make('config')->set('auth.guards.rebound', ['driver' => 'rebound-driver', 'provider' => null]);

        /* $this is the manager of the coroutine asking, because the creator is re-bound. */
        Auth::extend('rebound-driver', function () {
            return new ManagerProbe(spl_object_id($this));
        });

        $app->enableAsyncMode();

        $managerBehind = fn (string $token) => $this->withRequest(
            $token,
            fn () => $app->make('auth')->guard('rebound')->managerId,
        );

        $seen = $this->runParallel([
            'a' => fn () => $managerBehind('a'),
            'b' => fn () => $managerBehind('b'),
        ]);

        $this->assertNotSame($seen['a'], $seen['b']);
    }
}
