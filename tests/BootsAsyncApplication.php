<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Tests\Fixtures\BotTokenAuthServiceProvider;

/**
 * Builds an application in the state every worker is in when async mode is switched
 * on: providers registered and booted, no request served yet.
 */
trait BootsAsyncApplication
{
    /**
     * @param  string[]  $providers  application providers, registered after the package's own
     */
    private function bootedApp(array $providers = [BotTokenAuthServiceProvider::class]): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('files', new Filesystem());
        $app->instance('config', new Repository([
            'async'   => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'session' => ['driver' => 'stub'],
            'auth'    => [
                'defaults' => ['guard' => 'bot'],
                'guards'   => ['bot' => ['driver' => BotTokenAuthServiceProvider::DRIVER]],
            ],
        ]));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $app->register(EventServiceProvider::class);
        $app->register(\Illuminate\Auth\AuthServiceProvider::class);
        $app->register(AsyncServiceProvider::class);

        foreach ($providers as $provider) {
            $app->register($provider);
        }

        $app->boot();

        return $app;
    }
}
