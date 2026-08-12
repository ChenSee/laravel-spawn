<?php

namespace Spawn\Laravel\Tests;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Config\AsyncConfig;
use Spawn\Laravel\Database\AsyncMySqlConnection;
use Spawn\Laravel\Database\AsyncMariaDbConnection;
use Spawn\Laravel\Database\AsyncPgsqlConnection;
use Spawn\Laravel\Database\AsyncSqliteConnection;
use Spawn\Laravel\Database\AsyncSqlServerConnection;
use Spawn\Laravel\Events\AsyncDispatcher;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Routing\AsyncRouter;
use Spawn\Laravel\Session\AsyncDatabaseSessionHandler;
use Spawn\Laravel\Translation\AsyncTranslator;
use Spawn\Laravel\View\AsyncViewFactory;

/**
 * Boots a near-real Laravel application and asserts that every adapted service
 * is the async-safe class, including internal dependencies injected via constructor.
 */
class ServiceBootOrderTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();

        (function () {
            static::$resolvers = [];
        })->bindTo(null, Connection::class)();

        parent::tearDown();
    }

    public function test_all_adapters_are_installed_after_full_boot(): void
    {
        // ── bootstrap ─────────────────────────────────────────────────────────

        $app = new AsyncApplication(sys_get_temp_dir());
        $app->enableAsyncMode();

        $app->instance('config', new \Illuminate\Config\Repository([
            'async'   => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'app'     => ['locale' => 'en', 'fallback_locale' => 'en'],
            'view'    => ['paths' => [], 'compiled' => sys_get_temp_dir()],
            'session' => [
                'driver' => 'database', 'table' => 'sessions', 'lifetime' => 120,
                'connection' => null, 'encrypt' => false,
                'serialization' => 'php', 'cookie' => 'laravel_session',
            ],
        ]));

        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $app->useStoragePath(sys_get_temp_dir());
        $app->useLangPath(sys_get_temp_dir());

        $connection = $this->createMock(\Illuminate\Database\ConnectionInterface::class);
        $db = $this->createMock(\Illuminate\Database\DatabaseManager::class);
        $db->method('connection')->willReturn($connection);
        $app->instance('db', $db);

        // Simulate bootstrap/cache/services.php deferred entries
        $app->addDeferredServices([
            'translator'         => \Illuminate\Translation\TranslationServiceProvider::class,
            'translation.loader' => \Illuminate\Translation\TranslationServiceProvider::class,
        ]);

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        foreach ([
            \Illuminate\Events\EventServiceProvider::class,
            \Illuminate\Routing\RoutingServiceProvider::class,
            \Illuminate\View\ViewServiceProvider::class,
            \Illuminate\Session\SessionServiceProvider::class,
            AsyncServiceProvider::class,
        ] as $provider) {
            $app->register($provider);
        }

        $app->boot();

        // ── container bindings ────────────────────────────────────────────────

        $this->assertInstanceOf(AsyncDispatcher::class,  $app->make('events'),     'events');
        $this->assertInstanceOf(AsyncConfig::class,       $app->make('config'),     'config');
        $this->assertInstanceOf(AsyncRouter::class,       $app->make('router'),     'router');
        $this->assertInstanceOf(AsyncTranslator::class,   $app->make('translator'), 'translator');
        $this->assertInstanceOf(AsyncViewFactory::class,  $app->make('view'),       'view');

        $sessionHandler = $app->make('session')->driver('database')->getHandler();
        $this->assertInstanceOf(AsyncDatabaseSessionHandler::class, $sessionHandler, 'session.database handler');

        // ── database connection resolvers ─────────────────────────────────────

        $mockPdo = new class extends \PDO { public function __construct() {} };

        foreach ([
            'mysql'   => AsyncMySqlConnection::class,
            'mariadb' => AsyncMariaDbConnection::class,
            'pgsql'   => AsyncPgsqlConnection::class,
            'sqlite'  => AsyncSqliteConnection::class,
            'sqlsrv'  => AsyncSqlServerConnection::class,
        ] as $driver => $expected) {
            $resolver = Connection::getResolver($driver);
            $this->assertNotNull($resolver, "Connection::resolverFor('$driver') must be set");
            $this->assertInstanceOf($expected, $resolver($mockPdo, 'test', '', []), "db.$driver");
        }

        // ── internal dependencies ─────────────────────────────────────────────

        // Router::$events — injected via constructor, must be AsyncDispatcher
        $router = $app->make('router');
        $routerEvents = Closure::bind(function () { return $this->events; }, $router, Router::class)();
        $this->assertInstanceOf(AsyncDispatcher::class, $routerEvents,
            'Router::$events must be AsyncDispatcher — if it fails, RoutingServiceProvider ' .
            'injected stock Dispatcher before our binding was set');

        // ViewFactory::$events — injected via constructor, must be AsyncDispatcher
        $view = $app->make('view');
        $viewEvents = Closure::bind(function () { return $this->events; }, $view, AsyncViewFactory::class)();
        $this->assertInstanceOf(AsyncDispatcher::class, $viewEvents,
            'ViewFactory::$events must be AsyncDispatcher');

        // Model::$dispatcher — set by DatabaseServiceProvider::boot(), corrected by our booted() callback
        $this->assertInstanceOf(AsyncDispatcher::class, Model::getEventDispatcher(),
            'Model::$dispatcher must be AsyncDispatcher after boot');
    }

    /**
     * The middleware that puts back the per-request facade entries reaches a kernel that
     * was already resolved when this provider booted.
     *
     * `afterResolving()` fires on a build, and the container answers a resolved singleton
     * without building one. `register()` forgets a kernel resolved before AsyncServiceProvider
     * ran; a provider registered after it can resolve one in its own `register()`, and every
     * `register()` is over before the first `boot()`.
     */
    public function test_the_facade_middleware_reaches_a_kernel_resolved_during_register(): void
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new \Illuminate\Config\Repository([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
        ]));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $app->singleton(\Illuminate\Contracts\Http\Kernel::class, PlainKernel::class);

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $app->register(\Illuminate\Events\EventServiceProvider::class);
        $app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        $app->register(AsyncServiceProvider::class);
        $app->register(ResolvesKernelWhileRegistering::class);

        $app->boot();

        $kernel     = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $middleware = (new \ReflectionProperty($kernel, 'middleware'))->getValue($kernel);

        $this->assertContains(\Spawn\Laravel\Http\Middleware\RestorePerRequestFacades::class, $middleware);
        $this->assertCount(1, array_keys($middleware, \Spawn\Laravel\Http\Middleware\RestorePerRequestFacades::class));
    }
}

/**
 * An application provider that needs the kernel while registering — to read its middleware
 * groups, to push its own, or to hand it to something else.
 */
class ResolvesKernelWhileRegistering extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
    }
}
