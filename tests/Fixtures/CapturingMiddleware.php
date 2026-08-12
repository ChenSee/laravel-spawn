<?php

namespace Spawn\Laravel\Tests\Fixtures;

/**
 * The StartSession shape: a singleton that keeps the per-request service it was built
 * with, and so answers every later request through the first request's object.
 */
class CapturingMiddleware
{
    public function __construct(public object $captured)
    {
    }
}
