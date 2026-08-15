<?php

namespace Spawn\Laravel\Foundation;

use Illuminate\Contracts\Foundation\Application;

/**
 * Everything a worker does between a booted application and its first request.
 *
 * The three servers each need exactly this, which is why it is here rather than in one
 * of them: they differ in how they accept a connection and in nothing else about start-up.
 *
 * The work has two phases, and the split is what holds the order. configure() runs
 * while every adapter still writes to state the whole worker shares. switchToAsync()
 * is a series of one-way switches: past the first of them, a write through a switched
 * object is kept in the current request's context, and worker bootstrap has no request,
 * so the value sits in an overlay no request reads. Start-up work that configures
 * anything belongs in configure().
 *
 * Warming a pool is not here: it runs inside a server, before the accept loop, and only
 * DevServer has that place — see {@see \Spawn\Laravel\Server\Concerns\ManagesDatabasePool}.
 */
final class WorkerBootstrap
{
    /**
     * Take a booted application to the state a request handler expects.
     *
     * Called once per worker thread, before the first request and after every provider
     * has booted. Calling it twice configures the second time through switched
     * adapters, which drops the configuration.
     */
    public static function run(Application $app): void
    {
        set_time_limit(0);

        self::configure($app);
        self::switchToAsync($app);
    }

    /**
     * Every write that has to reach the whole worker.
     */
    private static function configure(Application $app): void
    {
        self::configureDatabasePool($app);

        // Redis needs the same treatment: one shared connection would let concurrent
        // coroutines interleave commands on a single socket.
        \Spawn\Laravel\Redis\RedisPool::configure($app);
    }

    /**
     * Hand the adapters and then the container over to per-request mode.
     *
     * The adapters go first because one of them registers a per-request service of its
     * own — AsyncRouter::bootCompleted() registers Route — and enableAsyncMode() walks
     * the per-request aliases once, to capture their boot-time prototypes and install
     * their facade proxies. Whatever an adapter registers after that walk is outside it.
     */
    private static function switchToAsync(Application $app): void
    {
        self::completeBoot($app);

        if ($app instanceof AsyncApplication) {
            $app->enableAsyncMode();
        }
    }

    /**
     * Tell every adapter that registration and boot are over.
     *
     * Each of them behaves like the class it replaces until this call and keeps
     * per-request state in the coroutine context after it, so that what bootstrap
     * configured stays configuration rather than becoming the first request's state.
     */
    private static function completeBoot(Application $app): void
    {
        if ($app->bound('view') && ($view = $app->make('view')) instanceof \Spawn\Laravel\View\AsyncViewFactory) {
            $view->bootCompleted();
        }

        if ($app->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            $registrar = $app->make(\Spatie\Permission\PermissionRegistrar::class);

            if ($registrar instanceof \Spawn\Laravel\Permission\AsyncPermissionRegistrar) {
                $registrar->bootCompleted();
            }
        }

        if ($app->bound(\Inertia\ResponseFactory::class)) {
            $inertia = $app->make(\Inertia\ResponseFactory::class);

            if ($inertia instanceof \Spawn\Laravel\Inertia\AsyncResponseFactory) {
                $inertia->bootCompleted();
            }
        }

        if ($app->bound('translator')
            && ($translator = $app->make('translator')) instanceof \Spawn\Laravel\Translation\AsyncTranslator) {
            $translator->bootCompleted();
        }

        if ($app->bound('config') && ($config = $app->make('config')) instanceof \Spawn\Laravel\Config\AsyncConfig) {
            $config->bootCompleted();
        }

        if ($app->bound('events')
            && ($events = $app->make('events')) instanceof \Spawn\Laravel\Events\AsyncDispatcher) {
            $events->bootCompleted();
        }

        if ($app->bound('router') && ($router = $app->make('router')) instanceof \Spawn\Laravel\Routing\AsyncRouter) {
            $router->bootCompleted();
        }

        if (class_exists(\Laravel\Telescope\Telescope::class)
            && method_exists(\Laravel\Telescope\Telescope::class, 'enableAsyncRecording')) {
            \Laravel\Telescope\Telescope::enableAsyncRecording();
        }
    }

    /**
     * Put every database connection behind the PDO pool.
     *
     * Runs before any connection is established, and purges the ones bootstrap opened:
     * a connection created before the options were set is not pooled, and it would go on
     * being handed to coroutine after coroutine.
     */
    private static function configureDatabasePool(Application $app): void
    {
        $config = $app->make('config');
        $pool   = $config->get('async.db_pool', []);

        if (empty($pool['enabled'])) {
            return;
        }

        foreach (array_keys($config->get('database.connections', [])) as $name) {
            $config->set(
                "database.connections.{$name}.options",
                array_replace(
                    $config->get("database.connections.{$name}.options", []),
                    [
                        \PDO::ATTR_POOL_ENABLED              => true,
                        \PDO::ATTR_POOL_MIN                  => $pool['min'] ?? 2,
                        \PDO::ATTR_POOL_MAX                  => $pool['max'] ?? 10,
                        // The attribute is in milliseconds, the config in seconds.
                        \PDO::ATTR_POOL_HEALTHCHECK_INTERVAL => (int) (($pool['healthcheck_interval'] ?? 30) * 1000),
                    ]
                )
            );
        }

        if ($app->bound('db')) {
            $db = $app->make('db');

            // By name, because purge() without one reaches the default connection alone
            // and a second connection a provider touched would keep its unpooled PDO.
            foreach (array_keys($db->getConnections()) as $name) {
                $db->purge($name);
            }
        }
    }
}
