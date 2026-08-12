<?php

namespace Spawn\Laravel\Tests;

use Async\Scope;
use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Foundation\AsyncApplication;

abstract class AsyncTestCase extends TestCase
{
    protected function createApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->enableAsyncMode();

        return $app;
    }

    /**
     * Run closures in parallel, each within its own child Scope
     * (simulating per-request isolation as the real servers do).
     */
    protected function runParallel(array $coroutines): array
    {
        $results = [];
        $failures = [];
        $scope = new Scope();

        foreach ($coroutines as $key => $fn) {
            $scope->spawn(function () use ($key, $fn, &$results, &$failures) {
                // Each "request" gets its own child scope so that
                // current_context() is isolated per-request.
                $requestScope = Scope::inherit();

                $requestScope->spawn(function () use ($key, $fn, &$results, &$failures) {
                    try {
                        $results[$key] = $fn();
                    } catch (\Throwable $e) {
                        // Carried out of the coroutine and rethrown below: a throw inside it
                        // otherwise leaves the caller with null and no stack trace,
                        // which reads as a wrong value rather than a failure.
                        $failures[$key] = $e;
                    }
                });

                $requestScope->awaitCompletion(\Async\timeout(5000));
            });
        }

        $scope->awaitCompletion(\Async\timeout(5000));

        // In the order the coroutines were given, for the same reason the results are:
        // which one failed first depends on scheduling, so reporting the earliest failure
        // by completion would name a different exception from run to run.
        foreach (array_keys($coroutines) as $key) {
            if (isset($failures[$key])) {
                throw $failures[$key];
            }
        }

        // In the order they were given, not the order they finished: a test comparing
        // whole result arrays would otherwise pass or fail on scheduling.
        return array_replace(array_fill_keys(array_keys($coroutines), null), $results);
    }
}
