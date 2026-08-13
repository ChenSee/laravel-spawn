<?php

namespace Spawn\Laravel\Foundation;

use Async\Context;

use function Async\current_context;
use function Async\request_context;

/**
 * The context that per-request state belongs to, for every adapter in this package.
 *
 * The request scope is the one the server assigns per request and every coroutine of
 * that request inherits, so a nested `Scope::inherit()` inside a handler reads the same
 * session, the same config overlay and the same Blade sections as the handler that
 * spawned it. The scope's own context would give that coroutine a second set of
 * everything, and a context reaches its parents but never its children, so what the
 * nested coroutine writes would never reach the response.
 *
 * Server extensions are what assign a request scope. There is none under DevServer,
 * under artisan or inside PHPUnit, and the scope's own context is the answer there.
 */
final class RequestContext
{
    public static function current(): Context
    {
        return request_context() ?? current_context();
    }
}
