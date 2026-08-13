<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\FacadeCache;
use stdClass;

/**
 * A facade of a service that belongs to the whole worker still answers with one object.
 *
 * Async mode switches facade caching off so that a per-request facade asks the container
 * on every call. The same switch reaches every other facade, and a root the container
 * does not register is built again on each call: Laravel registers neither
 * `Illuminate\Http\Client\Factory` nor `Illuminate\Process\Factory`, so `Http::fake()`,
 * `Http::globalMiddleware()` and `Process::preventStrayProcesses()` would each configure
 * an object thrown away before the next call.
 */
class FacadeRootIdentityTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function asyncApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new Repository(['async' => ['scoped_services' => []]]));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $app->enableAsyncMode();

        return $app;
    }

    public function test_a_root_the_container_does_not_register_is_one_object_for_the_worker(): void
    {
        $this->asyncApp();

        $this->assertSame(
            Http::getFacadeRoot(),
            Http::getFacadeRoot(),
            'the HTTP client factory is rebuilt on every call, so its global options are lost',
        );
    }

    public function test_what_was_set_on_such_a_root_is_there_on_the_next_call(): void
    {
        $this->asyncApp();

        Process::preventStrayProcesses();

        $this->assertTrue(Process::preventingStrayProcesses());
    }

    /**
     * The narrowing this fix ships with. A key that is not a class name — `view`, `db` —
     * belongs to a provider, and an application without that provider answers `bound()`
     * with false on purpose: framework code asks exactly that question to find out
     * whether the feature is installed.
     */
    public function test_a_key_that_names_no_class_is_left_unregistered(): void
    {
        $app = $this->asyncApp();

        $this->assertFalse($app->bound('view'));
    }

    /**
     * Caching is switched off for the life of the process, and a worker never leaves
     * async mode. A test process does: it runs one application after another, and the
     * next one has to start where a fresh process would.
     */
    public function test_caching_is_on_again_for_the_application_that_follows(): void
    {
        $this->asyncApp();

        FacadeCache::resumeCaching();

        $next = new AsyncApplication(sys_get_temp_dir());
        $next->bind('thing', fn () => new stdClass());

        Facade::setFacadeApplication($next);
        Facade::clearResolvedInstances();

        $this->assertSame(ThingFacade::getFacadeRoot(), ThingFacade::getFacadeRoot());
    }
}

/**
 * A facade of a binding the container rebuilds on every resolve: what it answers with
 * twice says whether the facade remembered the first answer.
 */
class ThingFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'thing';
    }
}
