<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scoped Services
    |--------------------------------------------------------------------------
    |
    | Services listed here will be resolved per-coroutine instead of shared
    | as singletons. Use this for third-party packages that hold request state.
    |
    | Example:
    |   \SomePackage\Manager::class,
    |
    */

    'scoped_services' => [],

    /*
    |--------------------------------------------------------------------------
    | Startup Diagnostics
    |--------------------------------------------------------------------------
    |
    | Report scoped services that bootstrap configured but no coroutine will
    | inherit — a service whose provider called extend() or a setter on the
    | resolved object, with no scopedSeeder() to carry that over. Such a service
    | resolves happily and then behaves as if it had never been configured, so
    | turn this on when a driver, macro or handler registered at boot appears to
    | vanish under the async server.
    |
    */

    'diagnostics' => false,

    /*
    |--------------------------------------------------------------------------
    | Database Connection Pool
    |--------------------------------------------------------------------------
    |
    | When the async server is running, each coroutine gets its own
    | DatabaseManager instance. The underlying PDO connections are managed
    | by TrueAsync's built-in pool, so physical connections are reused
    | across coroutines instead of creating a new one per request.
    |
    */

    'db_pool' => [
        'enabled' => true,
        'min'     => 2,
        'max'     => 10,
        'healthcheck_interval' => 30, // seconds, 0 = disabled
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Connection Pool
    |--------------------------------------------------------------------------
    |
    | RedisManager hands the same connection to every coroutine, so without a
    | pool concurrent commands interleave on one socket and the protocol breaks.
    | With the pool each coroutine borrows its own physical connection.
    |
    | 'mux' reserves connections for multiplexing: stateless commands (GET/SET)
    | from many coroutines share one socket and are pipelined, while MULTI,
    | WATCH, SUBSCRIBE and blocking commands still take a private connection.
    | 0 disables multiplexing and uses checkout only.
    |
    | Requires the TrueAsync build of phpredis. Redis Cluster is not pooled.
    |
    */

    'redis_pool' => [
        'enabled' => true,
        'min'     => 0,
        'max'     => 10,
        'mux'     => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | gRPC Handlers
    |--------------------------------------------------------------------------
    |
    | gRPC messages (protobuf) have no Symfony Request/Response equivalent, so
    | they bypass routing/middleware/the HTTP Kernel entirely. Map each fully
    | qualified method path to a [class, method] pair; the method receives the
    | raw (TrueAsync\HttpRequest $req, TrueAsync\HttpResponse $res) and reads/
    | writes messages with $req->readMessage() / $res->writeMessage().
    |
    | Example:
    |   '/profile.ProfileService/GetProfile' => [
    |       \App\Grpc\ProfileServiceHandler::class, 'getProfile',
    |   ],
    |
    */

    'grpc_handlers' => [],

    /*
    |--------------------------------------------------------------------------
    | Async Server
    |--------------------------------------------------------------------------
    |
    | Configuration for the TrueAsync HTTP server. The server can listen
    | on multiple interfaces and protocols simultaneously.
    |
    */

    'server' => [

        /*
        |--------------------------------------------------------------------------
        | Listeners
        |--------------------------------------------------------------------------
        |
        | Define the TCP interfaces the server should bind to. Each listener
        | can use a specific HTTP protocol version and optional TLS.
        |
        | Available protocols: auto, http1, http2, http3
        |
        */

        'listeners' => [
            [
                'host'     => env('ASYNC_HOST', '0.0.0.0'),
                'port'     => (int) env('ASYNC_PORT', 8080),
                'tls'      => (bool) env('ASYNC_TLS', false),
                'protocol' => env('ASYNC_PROTOCOL', 'auto'), // auto, http1, http2, http3
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Workers
        |--------------------------------------------------------------------------
        |
        | Number of worker threads for the multi-threaded server command
        | (async:workers). 0 means auto-detect based on CPU core count.
        |
        */

        'workers' => (int) env('ASYNC_WORKERS', 0),

        /*
        |--------------------------------------------------------------------------
        | TLS Certificates
        |--------------------------------------------------------------------------
        |
        | Absolute paths to the TLS certificate and private key. Used when
        | at least one listener has 'tls' => true.
        |
        */

        'tls_cert' => env('ASYNC_TLS_CERT', '/certs/server.crt'),
        'tls_key'  => env('ASYNC_TLS_KEY', '/certs/server.key'),

        /*
        |--------------------------------------------------------------------------
        | Socket & HTTP Settings
        |--------------------------------------------------------------------------
        */

        'backlog'       => (int) env('ASYNC_BACKLOG', 2048),
        'compression'   => (bool) env('ASYNC_COMPRESSION', true),
        'max_body_size' => (int) env('ASYNC_MAX_BODY_SIZE', 32 * 1024 * 1024),
        'read_timeout'  => (int) env('ASYNC_READ_TIMEOUT', 60),
        'write_timeout' => (int) env('ASYNC_WRITE_TIMEOUT', 60),

        /*
        |--------------------------------------------------------------------------
        | Static File Handlers
        |--------------------------------------------------------------------------
        |
        | Map URL prefixes to local directories for direct static file serving
        | bypassing the Laravel kernel.
        |
        | Example:
        |   [
        |       'prefix' => '/assets/',
        |       'root'   => public_path('assets'),
        |       'etag'   => true,
        |       'precompressed' => ['br', 'gzip'],
        |   ]
        |
        */

        'static_handlers' => [],

        /*
        |--------------------------------------------------------------------------
        | Hot Reload
        |--------------------------------------------------------------------------
        |
        | Configure hot reload for the TrueAsync server during development.
        | When enabled, the server watches the configured paths and automatically
        | reloads whenever a file changes.
        |
        | Paths are relative to the application base path.
        |
        */

        'hot_reload_paths' => [
            'app',
            'bootstrap',
            'config',
            'resources',
            'routes',
        ],
    ],

];
