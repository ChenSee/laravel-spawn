<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Auth\RequestGuard;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Tests\Fixtures\BotUser;

/**
 * Artisan, queue workers and php-fpm never enable async mode. What they get must be
 * the auth manager Laravel documents, not a version of it adapted for coroutines.
 */
class AuthSyncModeTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function app(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('files', new Filesystem());
        $app->instance('config', new Repository([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'auth'  => [
                'defaults' => ['guard' => 'bot'],
                'guards'   => ['bot' => ['driver' => 'bot-token']],
            ],
        ]));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $app->register(EventServiceProvider::class);
        $app->register(\Illuminate\Auth\AuthServiceProvider::class);
        $app->register(AsyncServiceProvider::class);
        $app->boot();

        Auth::viaRequest('bot-token', fn (Request $request) => new BotUser((string) $request->headers->get('X-Bot-Token')));

        return $app;
    }

    private function request(string $token): Request
    {
        $request = Request::create('/bot', 'GET');
        $request->headers->set('X-Bot-Token', $token);

        return $request;
    }

    public function test_the_manager_stays_a_singleton(): void
    {
        $app = $this->app();
        $app->instance('request', $this->request('first'));

        $this->assertSame($app->make('auth'), $app->make('auth'));
        $this->assertSame('first', Auth::guard('bot')->user()->token);
    }

    public function test_a_guard_follows_the_request_it_is_rebound_to(): void
    {
        $app = $this->app();
        $app->instance('request', $this->request('first'));

        /* Built before anyone asks who the user is, as a long-lived process does. */
        $guard = $app->make('auth')->guard('bot');
        $this->assertInstanceOf(RequestGuard::class, $guard);

        $app->instance('request', $this->request('second'));

        $this->assertSame('second', $guard->user()->token, 'upstream rebinding must keep working in sync mode');
    }
}
