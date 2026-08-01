<?php

namespace Spawn\Laravel\Redis;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;

/**
 * Wires the TrueAsync connection pool into Laravel's Redis manager.
 *
 * RedisManager caches one connection per name and hands it to every coroutine,
 * so the pool is what keeps concurrent requests off the same socket. Installed
 * once per worker, before any request is served.
 */
class RedisPool
{
    public static function configure(Application $app): void
    {
        $config = $app->make('config')->get('async.redis_pool', []);

        if (empty($config['enabled'])) {
            return;
        }

        if (! $app->bound('redis')) {
            return;
        }

        $manager = $app->make('redis');

        if (! $manager instanceof RedisManager) {
            return;
        }

        $manager->extend('phpredis', fn () => new AsyncPhpRedisConnector($config));

        // Anything resolved during bootstrap still holds a non-pooled client.
        foreach (array_keys($manager->connections() ?? []) as $name) {
            $manager->purge($name);
        }
    }
}
