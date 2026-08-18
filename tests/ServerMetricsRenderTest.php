<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\ServerMetrics;

/**
 * The Prometheus rendering, on a stats array written by hand.
 *
 * What the exposition has to satisfy is decided by Prometheus, not by the server: a
 * `_total` is a counter and everything else a gauge, a name carries its suffix rather
 * than its prefix, and a per-worker series repeats the metric under a label. The e2e
 * test drives the same code against a running server; this one pins the shape, including
 * the cases a live server rarely produces.
 */
class ServerMetricsRenderTest extends TestCase
{
    private const STATS = [
        'workers' => [
            0 => ['total_requests' => 7, 'active_requests' => 1],
            3 => ['total_requests' => 5, 'active_requests' => 0],
        ],
        'totals' => ['total_requests' => 12, 'active_requests' => 1],
    ];

    public function test_a_monotonic_counter_is_typed_as_a_counter_and_named_with_the_suffix(): void
    {
        $text = ServerMetrics::render(self::STATS);

        $this->assertStringContainsString("# TYPE spawn_requests_total counter\n", $text);
        $this->assertStringContainsString("\nspawn_requests_total 12\n", $text);
        $this->assertStringNotContainsString('spawn_total_requests', $text);
    }

    public function test_a_point_in_time_reading_is_typed_as_a_gauge(): void
    {
        $text = ServerMetrics::render(self::STATS);

        $this->assertStringContainsString("# TYPE spawn_active_requests gauge\n", $text);
        $this->assertStringContainsString("\nspawn_active_requests 1\n", $text);
    }

    public function test_every_counter_repeats_per_worker_under_its_own_id(): void
    {
        $text = ServerMetrics::render(self::STATS);

        $this->assertStringContainsString('spawn_requests_total{worker="0"} 7', $text);
        $this->assertStringContainsString('spawn_requests_total{worker="3"} 5', $text);
        $this->assertStringContainsString('spawn_active_requests{worker="3"} 0', $text);
    }

    public function test_the_number_of_workers_is_a_metric_of_its_own(): void
    {
        $text = ServerMetrics::render(self::STATS);

        $this->assertStringContainsString("# TYPE spawn_workers gauge\nspawn_workers 2\n", $text);
    }

    public function test_the_prefix_is_the_callers_to_choose(): void
    {
        $text = ServerMetrics::render(self::STATS, 'laravel');

        $this->assertStringContainsString("laravel_requests_total 12\n", $text);
        $this->assertStringNotContainsString('spawn_', $text);
    }

    /**
     * A counter the totals carry and a worker does not: a worker that retired between
     * the two reads leaves its counts in the totals alone.
     */
    public function test_a_counter_a_worker_does_not_report_yields_no_series_for_it(): void
    {
        $text = ServerMetrics::render([
            'workers' => [0 => ['total_requests' => 7]],
            'totals'  => ['total_requests' => 12, 'requests_shed_total' => 4],
        ]);

        $this->assertStringContainsString("\nspawn_requests_shed_total 4\n", $text);
        $this->assertStringNotContainsString('spawn_requests_shed_total{', $text);
    }

    public function test_the_body_ends_with_a_newline(): void
    {
        $this->assertStringEndsWith("\n", ServerMetrics::render(self::STATS));
    }
}
