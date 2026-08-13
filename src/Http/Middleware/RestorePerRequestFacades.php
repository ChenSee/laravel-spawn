<?php

namespace Spawn\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Spawn\Laravel\Foundation\AsyncApplication;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restores the facade entries the HTTP kernel removes on its way into the pipeline.
 *
 * `Kernel::sendRequestThroughRouter()` calls `Request::clearResolvedInstance()` after
 * binding the new request, which deletes the entry that makes the request facade answer
 * per coroutine through a proxy. Correctness does not depend on this: with facade caching
 * off, a facade whose entry is gone asks the container on every call. What this restores
 * is the fast path — one array read for a per-request facade instead of a container
 * resolve — and running first in the pipeline is the earliest point after the removal.
 *
 * Prepended by `AsyncServiceProvider` when the HTTP kernel is resolved. Applications do
 * not register it and should not have to.
 */
final class RestorePerRequestFacades
{
    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->app instanceof AsyncApplication) {
            $this->app->restorePerRequestFacades();
        }

        return $next($request);
    }
}
