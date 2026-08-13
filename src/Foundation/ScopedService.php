<?php

namespace Spawn\Laravel\Foundation;

/**
 * String-backed enum keys for TrueAsync Context storage.
 *
 * Each case maps a Laravel service alias to a unique object key.
 * ScopedService::tryFrom($alias) resolves alias → key in one call.
 *
 * NOTE: 'db' is intentionally absent. DatabaseServiceProvider::boot() sets
 * Model::setConnectionResolver($app['db']) as a static property. A scoped
 * DatabaseManager tied to a specific scope context gets GC'd after the scope
 * finishes, leaving Model::$resolver pointing to a destroyed object → segfault.
 * Physical connection isolation is handled by PDO Pool at the C level instead.
 * Per-coroutine transaction counter isolation is handled by CoroutineTransactions trait
 * in Async*Connection subclasses registered via Connection::resolverFor().
 */
enum ScopedService: string
{
    case REQUEST     = 'request';
    case SESSION     = 'session';

    /**
     * The store behind the session manager is bound on its own, and the stock session
     * guard reads the authenticated user out of it. Left shared, it answers one
     * request with another request's session.
     */
    case SESSION_STORE = 'session.store';

    /**
     * The redirector takes the session store once, when it is constructed, and flashes
     * into it for the life of the process. Shared, it flashes one user's validation
     * errors and status messages into another user's session.
     */
    case REDIRECT = 'redirect';

    case AUTH        = 'auth';
    case AUTH_DRIVER = 'auth.driver';
    case COOKIE      = 'cookie';

    /**
     * The types a constructor asks for when it wants this service.
     *
     * Both the concrete class and the contracts it is bound under are listed: a
     * constructor typed against the interface receives the same per-request object as
     * one typed against the class. Static analysis has no container to ask, so the
     * binding has to be written down here.
     *
     * @return class-string[]
     */
    public function types(): array
    {
        return match ($this) {
            self::REQUEST       => [\Illuminate\Http\Request::class],
            self::SESSION       => [\Illuminate\Session\SessionManager::class],
            self::SESSION_STORE => [
                \Illuminate\Session\Store::class,
                \Illuminate\Contracts\Session\Session::class,
            ],
            self::REDIRECT      => [\Illuminate\Routing\Redirector::class],
            self::AUTH          => [
                \Illuminate\Auth\AuthManager::class,
                \Illuminate\Contracts\Auth\Factory::class,
            ],
            self::AUTH_DRIVER   => [
                \Illuminate\Contracts\Auth\Guard::class,
                \Illuminate\Contracts\Auth\StatefulGuard::class,
            ],
            self::COOKIE        => [
                \Illuminate\Cookie\CookieJar::class,
                \Illuminate\Contracts\Cookie\Factory::class,
                \Illuminate\Contracts\Cookie\QueueingFactory::class,
            ],
        };
    }
}
