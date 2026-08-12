<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\Foundation\AsyncApplication;

/**
 * The cookie jar behind the Cookie facade belongs to one request.
 *
 * A facade keeps what it resolved in a static array shared by the whole process, so
 * without a per-coroutine entry there the first request to call Cookie::queue() decides
 * whose jar every later request queues into — and the cookies leave with the wrong
 * response. The jar is the case that shows it plainly: what it holds is on its way to a
 * Set-Cookie header, so a jar shared between two requests is a session handed to the
 * wrong browser.
 */
class CookieIsolationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function bootedApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new Repository([
            'async'   => ['scoped_services' => []],
            'session' => ['path' => '/', 'domain' => null, 'secure' => false, 'same_site' => 'lax'],
        ]));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        $app->register(CookieServiceProvider::class);
        $app->boot();

        /* Bootstrap touching the facade is what pins the jar, so the test does it too. */
        Cookie::getQueuedCookies();

        $app->enableAsyncMode();

        return $app;
    }

    /**
     * @return string[] the names queued in the coroutine that asks
     */
    private function queueAndRead(string $name): array
    {
        Cookie::queue($name, 'value');

        return array_map(fn ($cookie) => $cookie->getName(), Cookie::getQueuedCookies());
    }

    public function test_a_cookie_queued_in_one_coroutine_stays_out_of_the_other(): void
    {
        $this->bootedApp();

        $queued = $this->runParallel([
            'first'  => fn () => $this->queueAndRead('first'),
            'second' => fn () => $this->queueAndRead('second'),
        ]);

        $this->assertSame(['first'], $queued['first']);
        $this->assertSame(['second'], $queued['second'], 'the second request queued into the first request\'s jar');
    }

    public function test_the_facade_and_the_container_answer_with_the_same_jar(): void
    {
        $app = $this->bootedApp();

        $same = $this->runParallel([
            'only' => function () use ($app) {
                Cookie::queue('through-the-facade', 'value');

                return array_map(
                    fn ($cookie) => $cookie->getName(),
                    $app->make('cookie')->getQueuedCookies(),
                );
            },
        ]);

        $this->assertSame(
            ['through-the-facade'],
            $same['only'],
            'the facade proxy and a container resolve inside one request must reach one object',
        );
    }
}
