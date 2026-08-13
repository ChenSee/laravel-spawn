<?php

namespace Spawn\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Route;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\CaptureSweep;
use Spawn\Laravel\Foundation\WorkerBootstrap;

/**
 * Reports shared services holding an object that belongs to one request.
 *
 * A singleton that took a per-request service in its constructor answers every later
 * request through the first request's object. `StartSession` was one, and two concurrent
 * logins left with the same session cookie before anybody noticed. This command turns
 * finding the next one into a procedure: it puts the application in the state a worker is
 * in, drives requests through it, and reports what each one left captured.
 *
 * Exits non-zero when anything is found, so a baseline can be held in CI.
 */
class AuditCapturesCommand extends Command
{
    protected $signature = 'async:audit
        {--url=*     : Drive this URL as well as the routes below}
        {--only-urls : Drive nothing but the URLs given with --url}';

    protected $description = 'Report shared services holding an object that belongs to one request';

    public function handle(): int
    {
        $app = $this->laravel;

        if (! $app instanceof AsyncApplication) {
            $this->error('The application is not an AsyncApplication, so nothing is scoped per request.');

            return self::FAILURE;
        }

        WorkerBootstrap::run($app);

        $urls = $this->urls($app);

        if ($urls === []) {
            $this->error('No URL to drive: every route takes a parameter. Name one with --url.');

            return self::FAILURE;
        }

        $this->line(sprintf('Driving %d URL(s) through a worker.', count($urls)));

        $kernel = $app->make(HttpKernel::class);

        $sweep = new CaptureSweep($app, function ($request) use ($kernel): void {
            $kernel->terminate($request, $kernel->handle($request));
        });

        $found = $sweep->over($urls);

        if ($found === []) {
            $this->info('No shared service holds a per-request object.');

            return self::SUCCESS;
        }

        $this->table(
            ['Shared service', 'Holds it in', 'Which serves', 'First seen at'],
            array_map(
                fn (array $capture) => [
                    $capture['alias'],
                    '->' . $capture['path'],
                    $capture['captured'],
                    $capture['url'],
                ],
                array_values($found),
            ),
        );

        $this->error(sprintf(
            '%d capture(s). Each one is a request answering through another request\'s object.',
            count($found),
        ));

        return self::FAILURE;
    }

    /**
     * The URLs to drive: what --url names, and every GET route that needs no parameters.
     *
     * A route with a parameter is skipped rather than guessed at, because a made-up
     * identifier reaches an error page and exercises nothing. Name those with --url.
     *
     * @return string[]
     */
    private function urls(AsyncApplication $app): array
    {
        $urls = (array) $this->option('url');

        if ($this->option('only-urls')) {
            return $urls;
        }

        foreach ($app->make('router')->getRoutes() as $route) {
            if ($this->isDrivable($route)) {
                $urls[] = '/' . ltrim($route->uri(), '/');
            }
        }

        return array_values(array_unique($urls));
    }

    private function isDrivable(Route $route): bool
    {
        return in_array('GET', $route->methods(), true) && $route->parameterNames() === [];
    }
}
