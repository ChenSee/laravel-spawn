<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\Foundation\AsyncApplication;

use function Async\delay;

/**
 * The path a request really takes: instance('request'), the rebind handlers
 * registered at boot, and the user resolver they install on the request.
 */
class HttpKernelAuthIsolationTest extends AsyncTestCase
{
    use BootsHttpApplication;

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function botApp(): AsyncApplication
    {
        $app = $this->httpApp(PlainKernel::class, [
            'auth' => ['defaults' => ['guard' => 'bot'], 'guards' => ['bot' => ['driver' => 'bot-token']]],
        ]);

        Auth::viaRequest('bot-token', function (Request $request) {
            delay(20);

            return new MemoryUser((string) $request->headers->get('X-Bot-Token'));
        });

        $app->make('router')->get('/me', fn (Request $request) => 'user='.($request->user()?->getAuthIdentifier() ?? 'none'));

        return $app;
    }

    public function test_the_route_sees_the_user_of_the_request_it_is_serving(): void
    {
        $app = $this->botApp();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $this->get($app, '/me', ['X-Bot-Token' => 'AAA']),
            'b' => fn () => $this->get($app, '/me', ['X-Bot-Token' => 'BBB']),
        ]);

        $this->assertSame(['a' => 'user=AAA', 'b' => 'user=BBB'], $results);
    }

    public function test_serving_requests_does_not_grow_the_shared_container(): void
    {
        $app = $this->botApp();
        $app->enableAsyncMode();

        $snapshot = fn () => [
            'instances'        => count((fn () => $this->instances)->call($app)),
            'resolved'         => count((fn () => $this->resolved)->call($app)),
            'extenders'        => array_sum(array_map('count', (fn () => $this->extenders)->call($app))),
            'reboundCallbacks' => array_sum(array_map('count', (fn () => $this->reboundCallbacks)->call($app))),
            'afterResolving'   => array_sum(array_map('count', (fn () => $this->afterResolvingCallbacks)->call($app))),
        ];

        $after = [];

        for ($i = 1; $i <= 5; $i++) {
            $this->runParallel(['x' => fn () => $this->get($app, '/me', ['X-Bot-Token' => "T{$i}"])]);
            $after[$i] = $snapshot();
        }

        $this->assertSame($after[2], $after[5], 'a served request must leave nothing behind in the shared container');
    }

    public function test_the_url_generator_answers_for_the_request_being_served(): void
    {
        $app = $this->botApp();
        $app->make('router')->get('/here', fn () => 'current='.url()->current());
        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $this->get($app, 'http://localhost/here?who=a')]);

        $this->assertSame('current=http://localhost/here', $results['a']);
    }

    public function test_the_url_generator_is_resolvable_before_the_first_request(): void
    {
        $app = $this->botApp();
        $app->enableAsyncMode();

        $this->assertInstanceOf(\Illuminate\Routing\UrlGenerator::class, $app->make('url'));
    }
}
