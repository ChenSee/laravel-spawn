<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\ScopedService;

use function Async\current_context;

class MemoryUser implements Authenticatable
{
    public function __construct(public readonly string $id)
    {
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return 'irrelevant';
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
    }

    public function getRememberTokenName()
    {
        return 'remember_token';
    }
}

class MemoryUserProvider implements UserProvider
{
    public function retrieveById($identifier)
    {
        return new MemoryUser((string) $identifier);
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
    {
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
    {
        return true;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false)
    {
    }
}

/**
 * The worker turns a failure into a 500 page; a test wants the failure itself.
 */
class ThrowingExceptionHandler implements ExceptionHandler
{
    public function report(\Throwable $e)
    {
    }

    public function shouldReport(\Throwable $e)
    {
        return false;
    }

    public function render($request, \Throwable $e)
    {
        throw $e;
    }

    public function renderForConsole($output, \Throwable $e)
    {
        throw $e;
    }
}

class PlainKernel extends HttpKernel
{
    protected $middleware = [];
}

class SessionKernel extends HttpKernel
{
    protected $middleware = [StartSession::class];
}

/**
 * An application in the state a worker starts serving HTTP in: providers registered
 * and booted, the kernel bootstrapped, no request served yet.
 *
 * The difference from BootsAsyncApplication is the kernel: this one exercises the
 * real path a request takes, instance('request') and the rebind handlers included.
 */
trait BootsHttpApplication
{
    private function httpApp(string $kernel = PlainKernel::class, array $config = []): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $sessionFiles = sys_get_temp_dir().'/spawn-test-sessions';
        @mkdir($sessionFiles);

        $app->instance('files', new Filesystem());
        $app->instance('config', new Repository(array_replace_recursive([
            'app'   => ['key' => 'base64:'.base64_encode(random_bytes(32)), 'debug' => true, 'asset_url' => null],
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'auth'  => [
                'defaults'  => ['guard' => 'web'],
                'guards'    => ['web' => ['driver' => 'session', 'provider' => 'memory']],
                'providers' => ['memory' => ['driver' => 'memory']],
            ],
            'session' => [
                'driver' => 'file', 'lifetime' => 120, 'expire_on_close' => false, 'encrypt' => false,
                'files' => $sessionFiles, 'connection' => null, 'table' => 'sessions', 'store' => null,
                'lottery' => [2, 100], 'cookie' => 'spawn_session', 'path' => '/', 'domain' => null,
                'secure' => false, 'http_only' => true, 'same_site' => 'lax', 'partitioned' => false,
            ],
        ], $config)));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $app->singleton(KernelContract::class, $kernel);
        $app->singleton(ExceptionHandler::class, ThrowingExceptionHandler::class);

        $app->register(EventServiceProvider::class);
        $app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        $app->register(\Illuminate\Session\SessionServiceProvider::class);
        $app->register(\Illuminate\Cookie\CookieServiceProvider::class);
        $app->register(\Illuminate\Auth\AuthServiceProvider::class);
        $app->register(AsyncServiceProvider::class);

        $app->boot();

        // A worker bootstraps its kernel before the first request; saying so here stops
        // Kernel::handle() from running bootstrappers this container has no files for.
        $app->bootstrapWith([]);

        return $app;
    }

    /**
     * Serve one request the way the server does: the context first, then the kernel.
     */
    private function serve(AsyncApplication $app, Request $request): string
    {
        current_context()->set(ScopedService::REQUEST, $request);

        $kernel   = $app->make(KernelContract::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return (string) $response->getContent();
    }

    private function get(AsyncApplication $app, string $uri, array $headers = []): string
    {
        $request = Request::create($uri, 'GET');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $this->serve($app, $request);
    }
}
