<?php

namespace Spawn\Laravel\Foundation;

use Illuminate\Support\Facades\Facade;

/**
 * A handle on the static array facades resolve through.
 *
 * Facade::resolveFacadeInstance() answers from that array before it asks the container,
 * so whatever is written here decides what every call to that facade gets. The array is
 * protected and shared by the whole Facade hierarchy; extending Facade is what reaches
 * it. This class is a handle, never a facade — it has no accessor and resolves nothing.
 */
final class FacadeCache extends Facade
{
    /**
     * Make $instance the answer for every facade whose accessor is $name.
     *
     * Overwrites whatever the facade had cached, including an instance left there by
     * bootstrap.
     */
    public static function put(string $name, object $instance): void
    {
        static::$resolvedInstance[$name] = $instance;
    }

    /**
     * Stop facades from remembering what they resolve.
     *
     * A proxy in the cache answers per coroutine, but the framework empties entries while
     * serving — `Kernel::sendRequestThroughRouter()` drops `request` on every request —
     * and whatever coroutine resolves the facade next would otherwise leave its own object
     * there for every other coroutine to read. With caching off that window closes: a
     * facade whose entry is gone asks the container on every call, and the container
     * answers per coroutine.
     *
     * The proxies stay and still answer first, so a per-request facade costs one array
     * lookup rather than a resolve.
     */
    public static function stopCaching(): void
    {
        static::$cached = false;
    }

    /**
     * Whether the answer waiting for this facade is one of ours.
     *
     * False both when the entry is missing and when something else put a concrete
     * instance there: between the kernel removing an entry and the middleware restoring
     * it, any coroutine that touches the facade caches its own object, and that object
     * is what every other coroutine would read until the worker dies. Asking whether the
     * slot is merely occupied would leave exactly that case in place.
     */
    public static function holdsProxy(string $name): bool
    {
        return (static::$resolvedInstance[$name] ?? null) instanceof ScopedServiceProxy;
    }
}
