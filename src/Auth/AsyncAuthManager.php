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
 * In every other respect this is the stock manager, and deliberately so: the same
 * class serves bootstrap, artisan and queue workers, where nothing is per-coroutine.
 */
class AsyncAuthManager extends AuthManager
{
    /**
     * Callbacks handed to viaRequest(), by driver name. Holds an entry only while
     * $customCreators holds the wrapper built for that same callback.
     */
    private array $requestGuardCallbacks = [];

    /**
     * True once the application supplied its own user resolver, as opposed to the
     * constructor default — which resolves through the manager that created it and
     * must never be handed to another one.
     */
    private bool $userResolverReplaced = false;

    /**
     * Register a request-callback guard, remembering the callback itself.
     *
     * The guard is built exactly as upstream builds it. What makes it safe to adopt
     * elsewhere is the callback kept here: a wrapper closes over the manager that made
     * it, so another manager has to build its own from the original callback.
     *
     * @param  callable  $callback  receives the request and the user provider, returns the user or null
     */
    public function viaRequest($driver, callable $callback)
    {
        $manager = parent::viaRequest($driver, $callback);

        // After extend(), which drops the record for whichever driver it replaces.
        $this->requestGuardCallbacks[$driver] = $callback;

        return $manager;
    }

    /**
     * A driver registered twice keeps its last registration, request-callback or not.
     */
    public function extend($driver, Closure $callback)
    {
        unset($this->requestGuardCallbacks[$driver]);

        return parent::extend($driver, $callback);
    }

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
     * What such a prototype cannot offer is the callback behind a viaRequest driver,
     * so its wrapper is adopted as it stands, still resolving against the manager that
     * created it.
     *
     * The same holds for anything a registration closed over with `use`: rebinding
     * fixes $this and nothing else. A package that captured a manager or a guard in
     * its creator resolves against that captured object, in this coroutine and every
     * other one.
     */
    public static function seedInto(AuthManager $target, AuthManager $prototype): void
    {
        $requestGuards = $prototype instanceof self ? $prototype->requestGuardCallbacks : [];

        foreach ($requestGuards as $driver => $callback) {
            $target->viaRequest($driver, $callback);
        }

        foreach ($prototype->customCreators as $driver => $creator) {
            if (isset($requestGuards[$driver])) {
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
