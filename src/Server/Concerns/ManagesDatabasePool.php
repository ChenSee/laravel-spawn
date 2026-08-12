<?php

namespace Spawn\Laravel\Server\Concerns;

/**
 * Warms up the PDO pool for a server that needs it before its first connection.
 *
 * Configuring the pool belongs to WorkerBootstrap, which every server runs. Warming it
 * belongs here, because only DevServer has a server coroutine to warm it in.
 *
 * Requires $this->app to be an Illuminate\Contracts\Foundation\Application instance.
 */
trait ManagesDatabasePool
{
    /**
     * Force the DatabaseManager to establish its connection in the current coroutine scope.
     *
     * PDO Pool must be created in the server coroutine (not lazily in a request coroutine),
     * otherwise the pool ends up scoped to a short-lived coroutine and gets destroyed between requests.
     */
    protected function warmUpDatabasePool(): void
    {
        $poolConfig = $this->app->make('config')->get('async.db_pool', []);

        if (empty($poolConfig['enabled'])) {
            return;
        }

        if (!$this->app->bound('db')) {
            return;
        }

        try {
            $this->app->make('db')->connection()->getPdo();
        } catch (\Throwable $e) {
            echo "[async] DB pool warm-up failed: " . $e->getMessage() . "\n";
        }
    }
}
