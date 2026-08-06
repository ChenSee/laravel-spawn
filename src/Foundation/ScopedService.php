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
}
