<?php

namespace Spawn\Laravel\Auth;

use Closure;
use Illuminate\Auth\AuthManager;

/**
 * Auth manager that can hand its registrations to another manager.
 *
 * Driver registrations are made on the object, not in the container factory: an
 * application calls Auth::extend() or Auth::viaRequest() during boot, and a
 * coroutine that builds its own manager from that factory starts with none of
 * them. seedInto() carries them across.
 *
 * The one thing a stock manager cannot report is whether the application replaced
 * its user resolver or left the constructor default, and the difference matters:
 * the default resolves through the manager that created it, so adopting it would
 * send every coroutine back to the boot-time one.
 *
 * In every other respect this is the stock manager, and deliberately so: the same
 * class serves bootstrap, artisan and queue workers, where nothing is per-coroutine.
 */
class AsyncAuthManager extends AuthManager
{
    /**
     * True once the application supplied its own user resolver, as opposed to the
     * constructor default — which resolves through the manager that created it and
     * must never be handed to another one.
     */
    private bool $userResolverReplaced = false;

    public function resolveUsersUsing(Closure $userResolver)
    {
        $this->userResolverReplaced = true;

        return parent::resolveUsersUsing($userResolver);
    }

    /**
     * Give one manager the driver and provider registrations of another.
     *
     * Resolved guards are deliberately left behind: a guard caches the authenticated
     * user of the request that built it, so carrying them over would hand one
     * request's user to every other one.
     *
     * Neither manager has to be an AsyncAuthManager — an application is free to bind
     * its own, and losing its registrations is exactly the failure being prevented.
     *
     * Re-binding reaches $this and nothing else. A registration that captured a
     * manager or a guard with `use` goes on resolving against what it captured, in
     * this coroutine and in every other one.
     */
    public static function seedInto(AuthManager $target, AuthManager $prototype): void
    {
        foreach ($prototype->customCreators as $driver => $creator) {
            // A static closure cannot be bound, so extend() stored null for it and there
            // is nothing here to adopt — it was already broken at registration.
            if (! $creator instanceof Closure) {
                continue;
            }

            // extend() re-binds the closure to the target, which is what makes an
            // adopted registration resolve against this coroutine's state.
            $target->extend($driver, $creator);
        }

        foreach ($prototype->customProviderCreators as $name => $creator) {
            $target->provider($name, $creator);
        }

        if ($prototype instanceof self && $prototype->userResolverReplaced) {
            $target->resolveUsersUsing($prototype->userResolver);
        }
    }
}
