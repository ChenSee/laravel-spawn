<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
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
        $app = new \Spawn\Laravel\Foundation\AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new Repository([
            'async' => ['db_pool' => ['enabled' => true, 'min' => 3, 'max' => 7, 'healthcheck_interval' => 15]],
            'database' => ['connections' => ['mysql' => ['driver' => 'mysql'], 'pgsql' => ['driver' => 'pgsql']]],
        ]));

        /* Adapters the initialization pokes at; plain objects are simply skipped. */
        foreach (['view', 'translator', 'events', 'router'] as $service) {
            $app->instance($service, new \stdClass());
        }

        return $app;
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
            $options = $app->make('config')->get("database.connections.{$name}.options");

            $this->assertTrue($options[PDO::ATTR_POOL_ENABLED], "{$name} must be pooled");
            $this->assertSame(3, $options[PDO::ATTR_POOL_MIN]);
            $this->assertSame(7, $options[PDO::ATTR_POOL_MAX]);
            $this->assertSame(15, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
        }
    }

    public function test_a_disabled_pool_does_not_stop_the_rest_of_the_initialization(): void
    {
        $app = $this->appWithDatabase();
        $app->make('config')->set('async.db_pool.enabled', false);

        TrueAsyncServer::initializeApp($app);

        $this->assertTrue($app->isAsyncModeEnabled(), 'Async mode must not depend on the DB pool');
        $this->assertNull($app->make('config')->get('database.connections.mysql.options'));
    }
}
