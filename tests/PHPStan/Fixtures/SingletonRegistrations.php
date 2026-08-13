<?php

namespace Spawn\Laravel\Tests\PHPStan\Fixtures;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;

class SingletonRegistrations
{
    public function registerWithoutAConcrete(Application $app): void
    {
        $app->singleton(StartSession::class);
    }

    public function registerThroughAFactory(Application $app): void
    {
        $app->singleton(StartSession::class, fn ($app) => new StartSession(
            $app->make(SessionManager::class),
            fn () => $app->make(CacheFactory::class),
        ));
    }

    public function registerUnderAnAlias(Application $app): void
    {
        $app->singleton('session.start', fn ($app) => new StartSession($app->make(SessionManager::class)));
    }

    public function registerPerRequest(Application $app): void
    {
        $app->scoped(StartSession::class, fn ($app) => new StartSession($app->make(SessionManager::class)));
    }

    public function registerSomethingSharedAndSafe(Application $app): void
    {
        $app->singleton(Widget::class, fn () => new Widget(new WidgetStore()));
    }
}

class Widget
{
    public function __construct(public WidgetStore $store)
    {
    }
}

class WidgetStore
{
}
