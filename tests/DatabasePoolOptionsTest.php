<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use PDO;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\WorkerBootstrap;

/**
 * The pool options every worker injects on its way to its first request.
 *
 * One place injects them for all three servers, so the conversion is checked once.
 */
class DatabasePoolOptionsTest extends AsyncTestCase
{
    private function configure(array $poolConfig): array
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new Repository([
            'async' => ['db_pool' => $poolConfig],
            'database' => ['connections' => ['mysql' => ['driver' => 'mysql']]],
        ]));

        WorkerBootstrap::run($app);

        return $app->make('config')->get('database.connections.mysql.options') ?? [];
    }

    public function test_the_healthcheck_interval_is_converted_from_seconds_to_milliseconds(): void
    {
        $options = $this->configure(['enabled' => true, 'healthcheck_interval' => 15]);

        $this->assertSame(15_000, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
    }

    public function test_the_default_interval_is_converted_too(): void
    {
        $options = $this->configure(['enabled' => true]);

        $this->assertTrue($options[PDO::ATTR_POOL_ENABLED]);
        $this->assertSame(2, $options[PDO::ATTR_POOL_MIN]);
        $this->assertSame(10, $options[PDO::ATTR_POOL_MAX]);
        $this->assertSame(30_000, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
    }

    public function test_zero_still_disables_the_healthcheck(): void
    {
        $options = $this->configure(['enabled' => true, 'healthcheck_interval' => 0]);

        $this->assertSame(0, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
    }

    public function test_a_sub_second_interval_survives_as_milliseconds(): void
    {
        $options = $this->configure(['enabled' => true, 'healthcheck_interval' => 0.5]);

        $this->assertSame(500, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
    }
}
