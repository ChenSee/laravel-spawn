<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Vite;
use Illuminate\Log\Context\Repository as LogContext;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;

/**
 * State the framework attaches to the process because php-fpm ends with the request.
 *
 * A worker does not end, so each of these has to belong to the request instead: a
 * terminating callback registered by one request, and the CSP nonce and preloaded assets
 * Vite collects while rendering one page.
 */
class RequestLifecycleIsolationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function bootedApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new Repository([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'app'   => ['asset_url' => null, 'key' => null],
        ]));

        $app->register(EventServiceProvider::class);
        $app->register(AsyncServiceProvider::class);

        /* What FoundationServiceProvider does, and what makes Vite one object per worker. */
        $app->singleton(Vite::class);

        $app->boot();

        return $app;
    }

    /**
     * How many callbacks the container itself is holding, as opposed to a request.
     */
    private function sharedTerminating(AsyncApplication $app): int
    {
        return count((fn () => $this->terminatingCallbacks)->call($app));
    }

    public function test_a_terminating_callback_runs_for_the_request_that_registered_it(): void
    {
        $app = $this->bootedApp();
        $app->enableAsyncMode();

        $ran = [];

        $this->runParallel([
            'first' => function () use ($app, &$ran) {
                $app->terminating(function () use (&$ran) {
                    $ran[] = 'first';
                });
                $app->terminate();
            },
        ]);

        $this->runParallel(['second' => fn () => $app->terminate()]);

        $this->assertSame(['first'], $ran, 'a callback of a finished request ran again for the next one');
    }

    public function test_two_requests_do_not_run_each_other_s_callbacks(): void
    {
        $app = $this->bootedApp();
        $app->enableAsyncMode();

        $ran = [];

        $terminateWith = function (string $name) use ($app, &$ran): void {
            $app->terminating(function () use ($name, &$ran) {
                $ran[] = $name;
            });
            $app->terminate();
        };

        $this->runParallel([
            'first'  => fn () => $terminateWith('first'),
            'second' => fn () => $terminateWith('second'),
        ]);

        sort($ran);

        $this->assertSame(
            ['first', 'second'],
            $ran,
            'two requests ran more callbacks between them than they registered',
        );
    }

    public function test_serving_requests_does_not_grow_the_container_s_callback_list(): void
    {
        $app = $this->bootedApp();
        $app->enableAsyncMode();

        $before = $this->sharedTerminating($app);

        for ($request = 0; $request < 5; $request++) {
            $this->runParallel([
                'one' => function () use ($app) {
                    $app->terminating(fn () => null);
                    $app->terminate();
                },
            ]);
        }

        $this->assertSame($before, $this->sharedTerminating($app));
    }

    public function test_a_callback_registered_outside_a_request_stays_on_the_container(): void
    {
        $app = $this->bootedApp();
        $app->enableAsyncMode();

        $before = $this->sharedTerminating($app);
        $app->terminating(fn () => null);

        $this->assertSame(
            $before + 1,
            $this->sharedTerminating($app),
            'an artisan command has no request to attach a callback to',
        );
    }

    public function test_each_request_gets_its_own_csp_nonce(): void
    {
        $app = $this->bootedApp();

        /* Boot configures Vite the way an application does, before any request exists. */
        $app->make(Vite::class)->useHotFile('/tmp/spawn-vite-hot');

        $app->enableAsyncMode();

        /*
         * A middleware sets the nonce, the view reads it back, and the two are separated
         * by everything the request does in between — which is where the other request
         * gets its turn. suspend() is that turn, made deterministic.
         */
        $nonceSeenAfterSetting = function (string $nonce) use ($app): ?string {
            $app->make(Vite::class)->useCspNonce($nonce);

            \Async\suspend();

            return $app->make(Vite::class)->cspNonce();
        };

        $seen = $this->runParallel([
            'first'  => fn () => $nonceSeenAfterSetting('nonce-first'),
            'second' => fn () => $nonceSeenAfterSetting('nonce-second'),
        ]);

        $this->assertSame('nonce-first', $seen['first']);
        $this->assertSame('nonce-second', $seen['second'], 'a page went out carrying another request\'s nonce');
    }

    public function test_the_boot_time_configuration_survives_into_every_request(): void
    {
        $app = $this->bootedApp();
        $app->make(Vite::class)->useHotFile('/tmp/spawn-vite-hot');
        $app->enableAsyncMode();

        $seen = $this->runParallel([
            'first'  => fn () => $app->make(Vite::class)->hotFile(),
            'second' => fn () => $app->make(Vite::class)->hotFile(),
        ]);

        $this->assertSame(['first' => '/tmp/spawn-vite-hot', 'second' => '/tmp/spawn-vite-hot'], $seen);
    }

    /**
     * Log context is per request through Laravel's own scoped(), which this container
     * treats as per-request. What it does not carry over on its own is what bootstrap
     * put there, and a fresh repository starting empty is how a deployment id disappears
     * from every log line the worker writes.
     */
    public function test_log_context_added_at_boot_reaches_every_request_and_nothing_else_does(): void
    {
        $app = $this->bootedApp();
        $app->scoped(LogContext::class);
        $app->make(LogContext::class)->add('deployment', 'abc123');
        $app->enableAsyncMode();

        $seen = $this->runParallel([
            'first' => function () use ($app) {
                $app->make(LogContext::class)->add('user_id', 1);

                return $app->make(LogContext::class)->all();
            },
            'second' => function () use ($app) {
                \Async\suspend();

                return $app->make(LogContext::class)->all();
            },
        ]);

        $this->assertSame(['deployment' => 'abc123', 'user_id' => 1], $seen['first']);
        $this->assertSame(
            ['deployment' => 'abc123'],
            $seen['second'],
            'one request tagged another request\'s log lines',
        );
    }

    public function test_deferred_callbacks_belong_to_the_request_that_deferred_them(): void
    {
        $app = $this->bootedApp();
        $app->scoped(DeferredCallbackCollection::class);
        $app->enableAsyncMode();

        $queued = $this->runParallel([
            'first' => function () use ($app) {
                $app->make(DeferredCallbackCollection::class)[] = new DeferredCallback(fn () => null);

                \Async\suspend();

                return count($app->make(DeferredCallbackCollection::class));
            },
            'second' => function () use ($app) {
                \Async\suspend();

                return count($app->make(DeferredCallbackCollection::class));
            },
        ]);

        $this->assertSame(1, $queued['first']);
        $this->assertSame(0, $queued['second'], 'a deferred callback ran inside another request');
    }

    public function test_preloaded_assets_do_not_carry_from_one_request_into_the_next(): void
    {
        $app = $this->bootedApp();
        $app->make(Vite::class);
        $app->enableAsyncMode();

        $assets = $this->runParallel([
            'first' => function () use ($app) {
                $vite = $app->make(Vite::class);

                (fn () => $this->preloadedAssets['/build/app.js'] = ['crossorigin'])->call($vite);

                return count($vite->preloadedAssets());
            },
            'second' => fn () => count($app->make(Vite::class)->preloadedAssets()),
        ]);

        $this->assertSame(1, $assets['first']);
        $this->assertSame(0, $assets['second'], 'the Link: rel=preload header grows on every response');
    }
}
