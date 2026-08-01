<?php

/** @var Illuminate\Routing\Router $router */

$router->get('/ping', fn() => response('pong'));

$router->get('/json', fn() => response()->json([
    'status' => 'ok',
    'time' => microtime(true),
    'memory' => memory_get_usage(true),
]));

$router->get('/scoped', function (Illuminate\Http\Request $request) {
    return response()->json([
        'method' => $request->method(),
        'path' => $request->path(),
        'query' => $request->query(),
    ]);
});

// ── e2e probes ──

// Request isolation: re-resolve the request across an I/O yield. Under correct
// per-coroutine scoping, `before` and `after` are always this request's own id.
$router->get('/iso', function (Illuminate\Http\Request $request) {
    $before = (string) $request->query('id', '?');
    \Async\delay((int) $request->query('ms', 40));
    $after = (string) app('request')->query('id', '?');
    return response("{$before}:{$after}");
});

// Error handling: the handler throws — the worker must survive (kernel returns
// a 5xx) and concurrent requests must be unaffected.
$router->get('/boom', function () {
    throw new \RuntimeException('boom');
});

// Request-scope probe (RequestScopeE2ETest, TrueAsyncServer only): a handler that
// spawns a coroutine of its own, as anything doing work in parallel does. The nested
// coroutine resolves the scoped service FIRST — that is the case that tells the two
// candidate contexts apart, because a context reaches its parents but never its
// children. Under request_context() both coroutines share this request's redirector;
// under current_context() the nested one gets a second redirector of its own, whose
// flashed data the response never sees.
//
// `request_scope` reports whether this process even has request scopes: they are
// assigned by the server extension, so under DevServer or in a unit test the answer
// is null and the probe proves nothing.
$router->get('/request-scope', function () {
    $inner = null;

    $nested = \Async\Scope::inherit();
    $nested->spawn(function () use (&$inner) {
        $inner = spl_object_id(app('redirect'));
    });
    $nested->awaitCompletion(\Async\timeout(2000));

    $outer = spl_object_id(app('redirect'));

    return response()->json([
        'request_scope' => \Async\request_context() !== null,
        'shared'        => $inner === $outer,
        'id'            => $outer,
    ]);
});

// SSE probe (StreamingE2ETest, TrueAsyncServer only): writes directly to the
// raw HttpResponse and closes it, so TrueAsyncServer::sendResponse() must skip
// the buffered path entirely once isClosed() is true.
$router->get('/stream', function () {
    \Spawn\Laravel\Sse\Sse::start();
    \Spawn\Laravel\Sse\Sse::event(data: 'one', event: 'tick', id: '1');
    \Spawn\Laravel\Sse\Sse::event(data: 'two', event: 'tick', id: '2');
    \Spawn\Laravel\Sse\Sse::end();

    return response()->noContent();
});
