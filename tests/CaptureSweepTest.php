<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Response;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\CaptureSweep;

/**
 * What the sweep is for: a capture that only exists once a request has been served.
 *
 * Nothing per-request is resolved at worker start, so a singleton that takes a
 * per-request service is built during the first request that needs it and is invisible
 * before then. That is the whole reason `async:audit` drives requests instead of looking
 * at a freshly booted container.
 */
class CaptureSweepTest extends AsyncTestCase
{
    private function bootedApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new Repository([
            'async' => ['scoped_services' => ['widgets']],
        ]));
        $app->singleton('widgets', fn () => new \stdClass());
        $app->enableAsyncMode();

        return $app;
    }

    /**
     * A kernel that does one thing per request: resolve whatever it was given.
     */
    private function kernelResolving(AsyncApplication $app, string ...$aliases): HttpKernel
    {
        return new class ($app, $aliases) implements HttpKernel {
            /**
             * @param  string[]  $aliases
             */
            public function __construct(private readonly AsyncApplication $app, private readonly array $aliases)
            {
            }

            public function bootstrap(): void
            {
            }

            public function handle($request)
            {
                foreach ($this->aliases as $alias) {
                    $this->app->make($alias);
                }

                return new Response();
            }

            public function terminate($request, $response): void
            {
            }

            public function getApplication(): Application
            {
                return $this->app;
            }
        };
    }

    public function test_a_singleton_built_during_a_request_is_reported(): void
    {
        $app = $this->bootedApp();

        /* The StartSession shape: a singleton keeping the per-request service. */
        $app->singleton('middleware', fn ($app) => new class ($app->make('widgets')) {
            public function __construct(public object $captured)
            {
            }
        });

        $found = (new CaptureSweep($app, $this->kernelResolving($app, 'middleware')))->over(['/orders']);

        $this->assertCount(1, $found);
        $this->assertSame(
            ['alias' => 'middleware', 'path' => 'captured', 'captured' => 'widgets', 'url' => '/orders'],
            reset($found),
        );
    }

    public function test_nothing_is_reported_when_the_singleton_keeps_its_own_collaborator(): void
    {
        $app = $this->bootedApp();

        $app->singleton('middleware', fn () => new class (new \stdClass()) {
            public function __construct(public object $ownCollaborator)
            {
            }
        });

        $found = (new CaptureSweep($app, $this->kernelResolving($app, 'middleware')))->over(['/orders']);

        $this->assertSame([], $found);
    }

    public function test_a_capture_found_twice_is_reported_once_under_the_first_url(): void
    {
        $app = $this->bootedApp();

        $app->singleton('middleware', fn ($app) => new class ($app->make('widgets')) {
            public function __construct(public object $captured)
            {
            }
        });

        $found = (new CaptureSweep($app, $this->kernelResolving($app, 'middleware')))->over(['/first', '/second']);

        $this->assertCount(1, $found);
        $this->assertSame('/first', reset($found)['url']);
    }

    public function test_the_worker_sees_nothing_before_a_request_is_driven(): void
    {
        $app = $this->bootedApp();

        $app->singleton('middleware', fn ($app) => new class ($app->make('widgets')) {
            public function __construct(public object $captured)
            {
            }
        });

        $this->assertSame(
            [],
            $app->perRequestCaptures(),
            'the singleton is built lazily, so a booted worker has nothing to walk',
        );
    }
}
