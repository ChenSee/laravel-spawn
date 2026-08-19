<?php

namespace Spawn\Laravel\Database;

use RuntimeException;

/**
 * The PDO attributes that put a connection behind the TrueAsync connection pool.
 *
 * They are constants of PDO in the TrueAsync build of PHP and in no other, so they are read by
 * name rather than written as `PDO::ATTR_POOL_ENABLED`: a build without the pool then reports
 * which attribute it is missing instead of dying on an undefined constant, and static analysis,
 * which reads the stock PDO, stops guessing about a class it cannot see the whole of.
 */
final class PoolAttributes
{
    /**
     * The options a pooled connection is configured with.
     *
     * @param  array<string, mixed>  $pool  The `async.db_pool` section of the configuration.
     * @return array<int, mixed>  Keyed by PDO attribute, ready to merge into a connection's options.
     *
     * @throws RuntimeException When this PHP build has no connection pool.
     */
    public static function forPool(array $pool): array
    {
        return [
            self::attribute('ATTR_POOL_ENABLED') => true,
            self::attribute('ATTR_POOL_MIN') => $pool['min'] ?? 2,
            self::attribute('ATTR_POOL_MAX') => $pool['max'] ?? 10,
            // The attribute is in milliseconds, the config in seconds.
            self::attribute('ATTR_POOL_HEALTHCHECK_INTERVAL') => (int) (($pool['healthcheck_interval'] ?? 30) * 1000),
        ];
    }

    private static function attribute(string $name): int
    {
        if (! defined("PDO::$name")) {
            throw new RuntimeException(
                "This PHP build has no PDO connection pool: PDO::$name is not defined. "
                .'Serve on the TrueAsync build, or switch the pool off with async.db_pool.enabled.'
            );
        }

        return constant("PDO::$name");
    }
}
