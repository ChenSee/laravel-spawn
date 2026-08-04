<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end request-scope test. Drives {@see tests/e2e/run_request_scope.php}, which
 * boots a real TrueAsyncServer in a worker thread and asks the /request-scope route
 * whether the coroutines of one request share their per-request services.
 *
 * It has to be an end-to-end test. The request scope is created by the server
 * extension's C code, so `Async\request_context()` is null everywhere else — in
 * PHPUnit, under DevServer, under artisan — and the container silently falls back to
 * the current coroutine's own context. An in-process test therefore cannot tell the
 * two apart, and would pass on a build that hands every nested coroutine a second set
 * of everything.
 *
 * Run as its own process for the same reason as the other e2e tests: teardown sends
 * SIGTERM to the runner, which must not reach the PHPUnit runner.
 */
class RequestScopeE2ETest extends TestCase
{
    public function test_request_scope_end_to_end_suite(): void
    {
        $runner = __DIR__ . '/e2e/run_request_scope.php';

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
        exec($command, $lines, $code);
        $output = implode("\n", $lines);

        $this->assertSame(0, $code, "e2e runner exited non-zero:\n{$output}");
        $this->assertStringContainsString('0 failed', $output, $output);
    }
}
