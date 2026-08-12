<?php

namespace Spawn\Laravel\Foundation;

use Illuminate\Contracts\Foundation\Application;

/**
 * Everything a worker does between a booted application and its first request.
 *
 * The three servers each need exactly this, which is why it is here rather than in one
 * of them: they differ in how they accept a connection and in nothing else about start-up.
 *
 * The order is the point. Adapters are told that boot is over, then the pools are
 * configured, and async mode is switched on last — because switching it on is what
 * snapshots the boot-time objects and starts handing every request a copy. Anything
 * configured after that point is configured on a copy nobody keeps.
 */
final class WorkerBootstrap
{
    public static function run(Application $app): void
    {
        set_time_limit(0);

        self::completeBoot($app);
        self::configureDatabasePool($app);

        // Redis needs the same treatment: one shared connection would let concurrent
        // coroutines interleave commands on a single socket.
        \Spawn\Laravel\Redis\RedisPool::configure($app);

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
        if (($view = $app->make('view')) instanceof \Spawn\Laravel\View\AsyncViewFactory) {
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

        if (($translator = $app->make('translator')) instanceof \Spawn\Laravel\Translation\AsyncTranslator) {
            $translator->bootCompleted();
        }

        if (($config = $app->make('config')) instanceof \Spawn\Laravel\Config\AsyncConfig) {
            $config->bootCompleted();
        }

        if (($events = $app->make('events')) instanceof \Spawn\Laravel\Events\AsyncDispatcher) {
            $events->bootCompleted();
        }

        if (($router = $app->make('router')) instanceof \Spawn\Laravel\Routing\AsyncRouter) {
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
                        \PDO::ATTR_POOL_HEALTHCHECK_INTERVAL => $pool['healthcheck_interval'] ?? 30,
                    ]
                )
            );
        }

        if ($app->bound('db')) {
            $app->make('db')->purge();
        }
    }
}
