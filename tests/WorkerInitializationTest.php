<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Support\Facades\Facade;
use PDO;
use Spawn\Laravel\Server\TrueAsyncServer;

class WorkerInitializationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function appWithDatabase(): \Spawn\Laravel\Foundation\AsyncApplication
    {
        $app = $this->appWithConfig([
            'async' => ['db_pool' => ['enabled' => true, 'min' => 3, 'max' => 7, 'healthcheck_interval' => 15]],
            'database' => ['connections' => ['mysql' => ['driver' => 'mysql'], 'pgsql' => ['driver' => 'pgsql']]],
        ]);

        /* Adapters the initialization pokes at; plain objects are simply skipped. */
        foreach (['view', 'translator', 'events', 'router'] as $service) {
            $app->instance($service, new \stdClass());
        }

        return $app;
    }

    /**
     * The options as a request coroutine sees them, which is the only reader that
     * matters: it inherits nothing from the coroutine that started the worker, so a
     * write kept in that coroutine's own overlay reads back as null here.
     *
     * @return array<int, mixed>|null
     */
    private function poolOptions(\Spawn\Laravel\Foundation\AsyncApplication $app, string $name): ?array
    {
        return $this->inRequest(fn () => $app->make('config')->get("database.connections.{$name}.options"));
    }

    public function test_initialization_enables_async_mode(): void
    {
        $app = $this->appWithDatabase();
        $this->assertFalse($app->isAsyncModeEnabled());

        TrueAsyncServer::initializeApp($app);

        $this->assertTrue($app->isAsyncModeEnabled());
    }

    public function test_initialization_injects_pool_options_into_every_connection(): void
    {
        $app = $this->appWithDatabase();

        TrueAsyncServer::initializeApp($app);

        foreach (['mysql', 'pgsql'] as $name) {
            $options = $this->poolOptions($app, $name);

            $this->assertTrue($options[PDO::ATTR_POOL_ENABLED], "{$name} must be pooled");
            $this->assertSame(3, $options[PDO::ATTR_POOL_MIN]);
            $this->assertSame(7, $options[PDO::ATTR_POOL_MAX]);
            /* The config is in seconds, the attribute in milliseconds. */
            $this->assertSame(15_000, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
        }
    }

    public function test_initialization_falls_back_to_the_documented_pool_defaults(): void
    {
        $app = $this->appWithDatabase();
        $app->make('config')->set('async.db_pool', ['enabled' => true]);

        TrueAsyncServer::initializeApp($app);

        $options = $this->poolOptions($app, 'mysql');

        $this->assertSame(2, $options[PDO::ATTR_POOL_MIN]);
        $this->assertSame(10, $options[PDO::ATTR_POOL_MAX]);
        $this->assertSame(30_000, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
    }

    public function test_a_disabled_pool_does_not_stop_the_rest_of_the_initialization(): void
    {
        $app = $this->appWithDatabase();
        $app->make('config')->set('async.db_pool.enabled', false);

        TrueAsyncServer::initializeApp($app);

        $this->assertTrue($app->isAsyncModeEnabled(), 'Async mode must not depend on the DB pool');
        $this->assertNull($this->poolOptions($app, 'mysql'));
    }
}
