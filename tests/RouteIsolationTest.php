<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\Routing\AsyncRouter;

use function Async\delay;

class RouteIsolationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function router(): AsyncRouter
    {
        $app = $this->createApp();
        $router = new AsyncRouter(new Dispatcher($app), $app);
        $router->bootCompleted();

        return $router;
    }

    public function test_route_parameters_are_isolated_between_coroutines(): void
    {
        $router = $this->router();
        $router->get('/articles/{article_id}', fn ($article_id) => $article_id);

        $dispatch = function (string $id) use ($router) {
            $request = Request::create("/articles/{$id}", 'GET');
            $body = $router->dispatch($request)->getContent();

            /* Whatever the request does next yields at some point. */
            delay(20);

            return ['param' => $request->route('article_id'), 'body' => $body];
        };

        $results = $this->runParallel([
            'a' => fn () => $dispatch('111'),
            'b' => fn () => $dispatch('222'),
        ]);

        $this->assertSame('111', $results['a']['param'], 'RouteCollection binds parameters onto a shared Route object');
        $this->assertSame('222', $results['b']['param']);
        $this->assertSame('111', $results['a']['body']);
        $this->assertSame('222', $results['b']['body']);
    }

    public function test_each_coroutine_gets_its_own_route_object(): void
    {
        $router = $this->router();
        $router->get('/items/{item}', fn ($item) => $item);

        $dispatch = function (string $id) use ($router) {
            $router->dispatch(Request::create("/items/{$id}", 'GET'));
            delay(10);

            return spl_object_id($router->current());
        };

        $results = $this->runParallel([
            'a' => fn () => $dispatch('1'),
            'b' => fn () => $dispatch('2'),
        ]);

        $this->assertNotSame($results['a'], $results['b'], 'The matched Route must be cloned per coroutine');
    }

    public function test_route_is_resolved_from_the_container_per_coroutine(): void
    {
        $router = $this->router();
        $router->get('/posts/{post}', fn ($post) => $post);

        $app = (fn () => $this->container)->call($router);

        $dispatch = function (string $id) use ($router, $app) {
            $router->dispatch(Request::create("/posts/{$id}", 'GET'));
            delay(10);

            return $app->make(Route::class)->parameter('post');
        };

        $results = $this->runParallel([
            'a' => fn () => $dispatch('7'),
            'b' => fn () => $dispatch('9'),
        ]);

        $this->assertSame('7', $results['a']);
        $this->assertSame('9', $results['b']);
    }
}
