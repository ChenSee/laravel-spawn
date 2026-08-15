<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Spawn\Laravel\Config\AsyncConfig;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\WorkerBootstrap;

/**
 * The invariants that decide where a new start-up step may go: what a switched adapter
 * does to a write, and what start-up is therefore forbidden to do after switching one.
 */
class WorkerBootstrapOrderTest extends AsyncTestCase
{
    /** The prefix of every key these tests write, so that none of them names real configuration. */
    private const PROBE = 'worker_bootstrap_probe';

    /**
     * @return array<string, mixed> the configuration a worker starts with
     */
    private function configItems(array $async = [], ?array $connections = null): array
    {
        $connections ??= ['mysql' => ['driver' => 'mysql']];

        return [
            'async' => $async + ['db_pool' => ['enabled' => true]],
            'database' => ['default' => array_key_first($connections), 'connections' => $connections],
        ];
    }

    private function app(array $async = [], ?array $connections = null): AsyncApplication
    {
        return $this->appWithConfig($this->configItems($async, $connections));
    }

    /**
     * The invariant a start-up step nobody has written yet has to keep: no configuration
     * is written once the config adapter is switched.
     *
     * It watches that adapter alone, so a step losing its state through one of the other
     * six passes.
     */
    public function test_start_up_writes_no_configuration_after_the_config_adapter_switches(): void
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $config = new RecordingConfig($this->configItems());

        $app->instance('config', $config);

        WorkerBootstrap::run($app);

        $this->assertSame([], $config->writesAfterSwitch);
    }

    /**
     * The switch seen from both sides: the writer keeps its own value and no request
     * reads it. Without it, DatabasePoolOptionsTest would pass whatever the order.
     */
    public function test_a_config_write_after_start_up_stays_with_the_writer(): void
    {
        $app = $this->app();

        WorkerBootstrap::run($app);

        $app->make('config')->set(self::PROBE . '.timezone', 'UTC');

        $this->assertSame(
            'UTC',
            $app->make('config')->get(self::PROBE . '.timezone'),
            'the writer keeps its own value'
        );
        $this->assertNull(
            $this->inRequest(fn () => $app->make('config')->get(self::PROBE . '.timezone')),
            'a write made after the switch must not reach a request'
        );
    }

    /**
     * Every connection bootstrap opened is purged, not the default one alone: a
     * connection built before the pool options is unpooled and would be handed to
     * coroutine after coroutine.
     *
     * The manager is the real one, because the claim under test is about it: that it
     * keys connections by name and takes those keys back in purge().
     */
    public function test_every_connection_opened_during_bootstrap_is_purged(): void
    {
        $app = $this->app(connections: [
            'first'  => ['driver' => 'sqlite', 'database' => ':memory:'],
            'second' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        $db = new DatabaseManager($app, new ConnectionFactory($app));
        $app->instance('db', $db);

        $db->connection('first');
        $db->connection('second');
        $this->assertCount(2, $db->getConnections(), 'both connections are open before start-up');

        WorkerBootstrap::run($app);

        $this->assertSame([], $db->getConnections());
    }

    /**
     * A config write from worker-level code in async mode is kept in the root context,
     * which no request reads; with diagnostics on, it is reported.
     */
    public function test_a_config_write_from_the_root_context_is_reported(): void
    {
        $app = $this->app(['diagnostics' => true]);

        WorkerBootstrap::run($app);

        $log = tempnam(sys_get_temp_dir(), 'async-diagnostics-');
        $previous = ini_set('error_log', $log);

        try {
            $app->make('config')->set(self::PROBE . '.from_root', 'UTC');
            $this->inRequest(fn () => $app->make('config')->set(self::PROBE . '.from_request', 'en'));

            $reported = file_get_contents($log);
        } finally {
            ini_set('error_log', $previous);
            unlink($log);
        }

        $this->assertStringContainsString(self::PROBE . '.from_root', $reported);
        $this->assertStringNotContainsString(
            self::PROBE . '.from_request',
            $reported,
            'a request writes its own overlay'
        );
    }
}

/** A config that remembers what was written to it once it stopped writing to the shared array. */
class RecordingConfig extends AsyncConfig
{
    /** @var string[] */
    public array $writesAfterSwitch = [];

    private bool $switched = false;

    public function bootCompleted(): void
    {
        parent::bootCompleted();

        $this->switched = true;
    }

    public function set($key, $value = null)
    {
        if ($this->switched) {
            $this->writesAfterSwitch = array_merge(
                $this->writesAfterSwitch,
                is_array($key) ? array_keys($key) : [$key]
            );
        }

        parent::set($key, $value);
    }
}
