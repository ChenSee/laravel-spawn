<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Request as RequestFacade;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\ScopedService;
use Spawn\Laravel\Http\Middleware\RestorePerRequestFacades;
use Symfony\Component\HttpFoundation\Response;

use function Async\current_context;

/**
 * The entry that makes a facade answer per coroutine, put back after the framework
 * removes it.
 *
 * `Illuminate\Foundation\Http\Kernel::sendRequestThroughRouter()` calls
 * `Request::clearResolvedInstance()` on every request, before the pipeline runs. From
 * that moment the request facade has no per-coroutine entry, and the first coroutine to
 * touch it caches its own request in a static array every other coroutine reads.
 * `RestorePerRequestFacades` runs first in the pipeline, which is the earliest point
 * after the removal.
 */
class FacadeRestoreTest extends AsyncTestCase
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
            'async'   => ['scoped_services' => []],
            'session' => ['path' => '/', 'domain' => null, 'secure' => false, 'same_site' => 'lax'],
        ]));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $app->register(CookieServiceProvider::class);
        $app->boot();
        $app->enableAsyncMode();

        return $app;
    }

    private function restore(AsyncApplication $app): void
    {
        (new RestorePerRequestFacades($app))->handle(Request::create('/'), fn () => new Response());
    }

    /**
     * @return string the path the request facade reports in this coroutine
     */
    private function pathSeenBy(AsyncApplication $app, string $path): string
    {
        current_context()->set(ScopedService::REQUEST, Request::create($path, 'GET'));

        \Async\suspend();

        return RequestFacade::path();
    }

    public function test_a_removed_entry_is_put_back(): void
    {
        $app = $this->bootedApp();

        RequestFacade::clearResolvedInstance();
        $this->restore($app);

        $seen = $this->runParallel([
            'first'  => fn () => $this->pathSeenBy($app, '/first'),
            'second' => fn () => $this->pathSeenBy($app, '/second'),
        ]);

        $this->assertSame('first', $seen['first']);
        $this->assertSame('second', $seen['second'], 'one request read another request\'s URL');
    }

    /**
     * Restoring the entry is not enough on its own. The kernel clears it once per
     * request, so with requests overlapping there is always a coroutine whose entry has
     * just been cleared by somebody else's; whatever it resolves in that window would be
     * cached for every other coroutine to read. Found on a server under 60 concurrent
     * requests, where 59 of 60 responses reported another request's URL.
     */
    public function test_a_facade_resolved_while_its_entry_is_missing_caches_nothing(): void
    {
        $app = $this->bootedApp();

        RequestFacade::clearResolvedInstance();

        $seen = $this->runParallel([
            'first'  => fn () => $this->pathSeenBy($app, '/first'),
            'second' => fn () => $this->pathSeenBy($app, '/second'),
        ]);

        $this->assertSame('first', $seen['first']);
        $this->assertSame('second', $seen['second'], 'one request read another request\'s URL');
    }

    /**
     * The window the kernel opens is not empty. Between the removal and the middleware
     * the framework runs its bootstrappers, which suspend on the first request, so a
     * concurrent coroutine can resolve the facade and leave its own request in the slot.
     * A restore that only asks whether the slot is occupied would walk past it.
     */
    public function test_an_instance_another_coroutine_cached_is_replaced(): void
    {
        $app = $this->bootedApp();

        RequestFacade::clearResolvedInstance();

        /* What resolveFacadeInstance() does when it finds the slot empty. */
        RequestFacade::swap(Request::create('/somebody-else', 'GET'));

        $this->restore($app);

        $seen = $this->runParallel([
            'first'  => fn () => $this->pathSeenBy($app, '/first'),
            'second' => fn () => $this->pathSeenBy($app, '/second'),
        ]);

        $this->assertSame('first', $seen['first']);
        $this->assertSame('second', $seen['second']);
    }
}
