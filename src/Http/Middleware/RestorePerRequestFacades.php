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
 * per coroutine. From there the first coroutine to call `Request::` caches its own
 * request in a static array the whole process reads. Running first in the pipeline is
 * the earliest point after the removal, and nothing between the two touches a facade.
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
