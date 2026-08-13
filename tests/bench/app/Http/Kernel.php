<?php

namespace Bench\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    ];
    protected $middlewareGroups = [];
    protected $routeMiddleware = [];
}
