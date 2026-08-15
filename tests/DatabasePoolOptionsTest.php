<?php

namespace Spawn\Laravel\Tests;

use PDO;
use Spawn\Laravel\Config\AsyncConfig;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\WorkerBootstrap;

/**
 * The pool options every worker injects on its way to its first request.
 *
 * One place injects them for all three servers, so the conversion is checked once.
 * The config is the real AsyncConfig: a stock Repository writes to the shared array
 * whatever the order of start-up, which is exactly the difference under test.
 */
class DatabasePoolOptionsTest extends AsyncTestCase
{
    private function app(array $poolConfig): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new AsyncConfig([
            'async' => ['db_pool' => $poolConfig],
            'database' => ['connections' => ['mysql' => ['driver' => 'mysql']]],
        ]));

        return $app;
    }

    private function configure(array $poolConfig): array
    {
        $app = $this->app($poolConfig);

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

    public function test_a_disabled_pool_leaves_the_connection_alone(): void
    {
        $this->assertSame([], $this->configure(['enabled' => false]));
    }

    /**
     * The reader of these options is a request coroutine, and it inherits nothing from
     * the coroutine that started the worker.
     *
     * Written after the config adapter is switched, they are kept in the writing
     * coroutine's overlay: the connection factory then reads the base configuration,
     * finds no pool, and every coroutine in the worker shares one PDO handle.
     */
    public function test_the_options_reach_a_request_coroutine(): void
    {
        $app = $this->app(['enabled' => true]);

        WorkerBootstrap::run($app);

        $options = $this->inRequest(fn () => $app->make('config')->get('database.connections.mysql.options'));

        $this->assertIsArray($options, 'the pool options did not reach the request');
        $this->assertArrayHasKey(PDO::ATTR_POOL_ENABLED, $options);
        $this->assertTrue($options[PDO::ATTR_POOL_ENABLED]);
    }
}
