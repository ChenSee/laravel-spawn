<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end render-load test. Drives {@see tests/e2e/run_render_load.php}, which boots
 * a real TrueAsyncServer in a worker thread and hits it with sixty requests at once,
 * each carrying its own token through a page whose render suspends in the middle.
 *
 * The unit tests render two pages at a time through the factory; this one renders sixty
 * through a server, and checks the cookie jar, the URL generator and the terminating
 * callback's log context as well as the page. The one leak found before this stand
 * existed — the request facade after a suspension point — was invisible to every unit
 * test and showed up in the first run of sixty concurrent requests.
 *
 * Run as its own process for the same reason as the other e2e tests: teardown sends
 * SIGTERM to the runner, which must not reach the PHPUnit runner.
 */
class RenderLoadE2ETest extends TestCase
{
    public function test_render_load_end_to_end_suite(): void
    {
        $runner = __DIR__ . '/e2e/run_render_load.php';

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
        exec($command, $lines, $code);
        $output = implode("\n", $lines);

        $this->assertSame(0, $code, "e2e runner exited non-zero:\n{$output}");
        $this->assertStringContainsString('0 failed', $output, $output);
    }
}
