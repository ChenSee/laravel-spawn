<?php

use Spawn\Laravel\Async\RawIo;
use Spawn\Laravel\Server\ServerMetrics;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;

if (! function_exists('trueasync_request')) {
    /**
     * The raw TrueAsync HttpRequest behind the current Illuminate request.
     * Needed for gRPC (readMessage) and anything else the Illuminate Request
     * does not expose.
     */
    function trueasync_request(): HttpRequest
    {
        return RawIo::request();
    }
}

if (! function_exists('trueasync_response')) {
    /**
     * The raw TrueAsync HttpResponse behind the current Illuminate response.
     * Use it to stream (SSE, writeMessage for gRPC) instead of returning
     * a buffered Illuminate Response.
     */
    function trueasync_response(): HttpResponse
    {
        return RawIo::response();
    }
}

if (! function_exists('trueasync_metrics')) {
    /**
     * The running server's counters: request totals, live connections, per-worker
     * numbers and a Prometheus rendering. Same object as app(ServerMetrics::class).
     */
    function trueasync_metrics(): ServerMetrics
    {
        return app(ServerMetrics::class);
    }
}
