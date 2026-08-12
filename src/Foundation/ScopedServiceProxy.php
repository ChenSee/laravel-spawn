<?php

namespace Spawn\Laravel\Foundation;

/**
 * Proxy placed in the Laravel facade cache for a per-request service.
 *
 * Facades cache the resolved instance in a static array. In a concurrent
 * environment this cache becomes shared across coroutines, causing state
 * leaks. Instead of clearing the cache on every request (which races with
 * other coroutines), we cache this proxy once. Every facade call goes through
 * __call → resolver → the container → the correct per-request instance.
 *
 * Only facades hold one. Type-hinted injection resolves through the container and
 * receives the real instance, which is what makes it safe for a service that gets
 * passed to a typed parameter — the Redirector and the CookieJar both do.
 */
class ScopedServiceProxy
{
    public function __construct(
        private readonly \Closure $resolver,
    ) {}

    public function __call(string $method, array $args): mixed
    {
        return ($this->resolver)()->$method(...$args);
    }

    public function __get(string $property): mixed
    {
        return ($this->resolver)()->$property;
    }

    public function __set(string $property, mixed $value): void
    {
        ($this->resolver)()->$property = $value;
    }

    public function __isset(string $property): bool
    {
        return isset(($this->resolver)()->$property);
    }
}
