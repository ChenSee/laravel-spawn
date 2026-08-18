<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\ServerMetrics;

/**
 * The Prometheus rendering, on a stats array written by hand.
 *
 * What the exposition has to satisfy is decided by Prometheus, not by the server: a
 * `_total` is a counter and everything else a gauge, a name carries its suffix rather
 * than its prefix, and a summed reading never shares a metric name with the per-worker
 * series it was summed from. The e2e test drives the same code against a running server;
 * this one pins the shape, including the cases a live server rarely produces.
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

    public function test_per_worker_readings_carry_a_name_of_their_own(): void
    {
        $text = ServerMetrics::render(self::STATS);

        $this->assertStringContainsString("# TYPE spawn_worker_requests_total counter\n", $text);
        $this->assertStringContainsString('spawn_worker_requests_total{worker="0"} 7', $text);
        $this->assertStringContainsString('spawn_worker_requests_total{worker="3"} 5', $text);
        $this->assertStringContainsString('spawn_worker_active_requests{worker="3"} 0', $text);
    }

    /**
     * The summed reading and the per-worker series must not share a metric name: with one
     * name for both, `sum(spawn_requests_total)` in PromQL counts every request twice.
     */
    public function test_a_summed_reading_shares_no_name_with_the_series_it_sums(): void
    {
        $text = ServerMetrics::render(self::STATS);

        $labelled = preg_grep('/^spawn_requests_total\{/', explode("\n", $text));

        $this->assertSame([], $labelled);
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

    public function test_a_prefix_that_is_not_a_metric_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServerMetrics::render(self::STATS, 'my metric');
    }

    /**
     * A stats array whose worker slots carry fewer counters than the totals: an older
     * extension against a newer package, or a fixture built by hand.
     */
    public function test_a_counter_no_worker_reports_yields_no_series_for_it(): void
    {
        $text = ServerMetrics::render([
            'workers' => [0 => ['total_requests' => 7]],
            'totals'  => ['total_requests' => 12, 'requests_shed_total' => 4],
        ]);

        $this->assertStringContainsString("\nspawn_requests_shed_total 4\n", $text);
        $this->assertStringNotContainsString('spawn_worker_requests_shed_total', $text);
    }

    public function test_an_empty_stats_array_renders_the_worker_count_alone(): void
    {
        $this->assertSame("# TYPE spawn_workers gauge\nspawn_workers 0\n", ServerMetrics::render([]));
    }

    public function test_the_body_ends_with_a_newline(): void
    {
        $this->assertStringEndsWith("\n", ServerMetrics::render(self::STATS));
    }
}
