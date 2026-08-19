<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end server-metrics test. Drives {@see tests/e2e/run_metrics.php}, which boots a
 * real TrueAsyncServer and reads its counters back through a route of the fixture — the
 * package publishes no metrics endpoint, so the route is part of what is under test.
 *
 * Each mode starts its own server on its own port: the shipped default, `telemetry` on,
 * two workers, and the counters disabled. A single-worker run says nothing about the
 * pool, where the counters live in a slab the workers share and one scrape has to answer
 * for every worker.
 *
 * Run as its own process for the same reason as the other e2e tests: teardown sends
 * SIGTERM to the runner, which must not reach the PHPUnit runner.
 */
class ServerMetricsE2ETest extends TestCase
{
    public static function modes(): array
    {
        return [
            'default'        => ['on'],
            'telemetry on'   => ['timing'],
            'two workers'    => ['pool'],
            'statistics off' => ['off'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_server_metrics_end_to_end_suite(string $mode): void
    {
        $runner = __DIR__ . '/e2e/run_metrics.php';

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner)
            . ' ' . escapeshellarg($mode) . ' 2>&1';
        exec($command, $lines, $code);
        $output = implode("\n", $lines);

        $this->assertSame(0, $code, "e2e runner exited non-zero:\n{$output}");
        // Anchored: 'contains 0 failed' also matches a run that reported 10 failures.
        $this->assertMatchesRegularExpression('/E2E: \d+ passed, 0 failed/', $output, $output);
    }
}
