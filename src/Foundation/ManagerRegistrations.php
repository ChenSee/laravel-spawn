<?php

namespace Spawn\Laravel\Foundation;

use Illuminate\Support\Manager;

/**
 * Seeder for anything built on Illuminate's Manager.
 *
 * Every such manager takes its custom drivers through extend(), which writes into
 * the object. A coroutine builds its manager from the container factory and would
 * start without them, reporting the application's own driver as unsupported.
 *
 * Resolved drivers are not carried over — they are what the scoping is for.
 */
final class ManagerRegistrations
{
    /**
     * Give the target manager the drivers registered on the prototype.
     *
     * Silently does nothing unless both are managers, so it is safe to register for a
     * service an application may have rebound to something else entirely.
     */
    public static function seed(object $target, object $prototype): void
    {
        if (! $target instanceof Manager || ! $prototype instanceof Manager) {
            return;
        }

        foreach ((fn () => $this->customCreators)->call($prototype) as $driver => $creator) {
            // A static closure cannot be bound, so Manager::extend() stored null for it
            // and there is nothing here to adopt — it was already broken at registration.
            if (! $creator instanceof \Closure) {
                continue;
            }

            // extend() re-binds the creator to the target, so an adopted registration
            // resolves against this coroutine's manager.
            $target->extend($driver, $creator);
        }
    }
}
