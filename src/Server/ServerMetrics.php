<?php

namespace Spawn\Laravel\Server;

use TrueAsync\HttpServer;

/**
 * The running server's counters, for application code that holds no reference to it.
 *
 * The extension keeps per-worker counters and exposes them on the `HttpServer` object.
 * It serves no endpoint and implements no exposition format, and `TrueAsyncServer::start()`
 * holds the server object for the whole run, so the server binds this class into each
 * worker's container instead:
 *
 *     Route::get('/metrics', fn () => response(
 *         app(ServerMetrics::class)->toPrometheus(),
 *         200,
 *         ['Content-Type' => 'text/plain; version=0.0.4'],
 *     ));
 *
 * The application chooses the URL, the access rules and the format. See docs/METRICS.md.
 */
final class ServerMetrics
{
    /**
     * The HttpServer of this worker; null in a process that serves no requests through
     * TrueAsyncServer.
     *
     * The container holds one of these per worker and a worker serves through one server
     * for its whole life. State here is per-worker and lives as long as the worker.
     */
    private ?HttpServer $server = null;

    /**
     * Bind the server whose counters this object reports.
     *
     * @internal Called by TrueAsyncServer when a worker takes its first request.
     */
    public function useServer(HttpServer $server): void
    {
        $this->server = $server;
    }

    /**
     * Whether the counters can be read: this process serves through TrueAsyncServer and
     * `async.server.stats` was on when the server started.
     *
     * False under `artisan serve`, under DevServer and in tests. `latency()` answers even
     * when this is false, because timings do not depend on `async.server.stats`.
     */
    public function isAvailable(): bool
    {
        return $this->server !== null && $this->server->getConfig()->isStatsEnabled();
    }

    /**
     * Counters summed across every worker, keyed by counter name.
     *
     * Monotonic counters also carry the counts of workers that have exited, so a hot
     * reload does not make one run backwards; gauges belong to live workers only. Requests
     * a reactor thread serves end to end are in these sums and in no worker's numbers.
     *
     * @return array<string,int>
     */
    public function totals(): array
    {
        return $this->stats()['totals'];
    }

    /**
     * The same counters per live worker, keyed by worker id.
     *
     * A worker mid-retire is skipped, so the answer can lag one worker behind.
     *
     * @return array<int,array<string,int>>
     */
    public function workers(): array
    {
        return $this->stats()['workers'];
    }

    /**
     * Request timing of the worker that serves this call, in milliseconds, counted since
     * that worker started.
     *
     * Scoped to one worker because the extension keeps timings per thread: under a pool
     * each scrape lands on whichever worker took the request (true-async/server#169).
     * `sojourn_*` is the wait before the handler ran, `service_avg_ms` the handler's own
     * time; both are averages over the worker's whole life, and `sojourn_max_ms` never
     * falls. Percentiles are not available.
     *
     * The four read zero unless the per-request timing stamps are on. `async.server.telemetry`
     * turns them on; so do a non-zero CoDel target and an access-log sink.
     *
     * @return array{sojourn_samples:int,sojourn_avg_ms:float,sojourn_max_ms:float,service_avg_ms:float}
     */
    public function latency(): array
    {
        $keys = ['sojourn_samples' => 0, 'sojourn_avg_ms' => 0.0, 'sojourn_max_ms' => 0.0, 'service_avg_ms' => 0.0];

        return array_intersect_key($this->server()->getTelemetry(), $keys) + $keys;
    }

    /**
     * Everything above in one array: `workers`, `totals` and `latency`.
     *
     * The two counter readings are taken one after the other, so under traffic the
     * per-worker numbers and the totals are moments apart.
     *
     * @return array{workers:array<int,array<string,int>>,totals:array<string,int>,latency:array<string,float|int>}
     */
    public function toArray(): array
    {
        $stats = $this->stats();

        return [
            'workers' => $stats['workers'],
            'totals'  => $stats['totals'],
            'latency' => $this->latency(),
        ];
    }

    /**
     * The counters in the Prometheus text exposition format, ready to be the body of a
     * `text/plain; version=0.0.4` response.
     *
     * Timings are per worker, and consecutive scrapes read different workers, so a series
     * built from them measures nothing; they are left out.
     *
     * @param string $prefix leading name segment for every metric, without the underscore
     */
    public function toPrometheus(string $prefix = 'spawn'): string
    {
        return self::render($this->stats(), $prefix);
    }

    /**
     * Render a `getStats()` array as Prometheus text. Public so a caller can render a
     * snapshot it holds — a stats array pulled over a queue, or a fixture in a test.
     *
     * Summed and per-worker readings go into separate metric families: one name carrying
     * both an unlabelled total and a series per worker would double every `sum()` over it.
     *
     * @param array{workers?:array<int,array<string,int>>,totals?:array<string,int>} $stats
     * @throws \InvalidArgumentException when the prefix is not a Prometheus name segment
     */
    public static function render(array $stats, string $prefix = 'spawn'): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $prefix) !== 1) {
            throw new \InvalidArgumentException("Metric prefix '{$prefix}' is not a valid Prometheus name.");
        }

        /* Prometheus names a counter with the _total suffix; the extension names this one
         * total_requests. */
        $rename  = ['total_requests' => 'requests_total'];
        $totals  = $stats['totals'] ?? [];
        $workers = $stats['workers'] ?? [];

        $lines = [];

        foreach ($totals as $counter => $value) {
            $name = $rename[$counter] ?? $counter;
            $type = str_ends_with($name, '_total') ? 'counter' : 'gauge';

            $lines[] = '# TYPE ' . $prefix . '_' . $name . ' ' . $type;
            $lines[] = $prefix . '_' . $name . ' ' . $value;

            $series = [];

            foreach ($workers as $id => $counters) {
                if (array_key_exists($counter, $counters)) {
                    $series[] = $prefix . '_worker_' . $name . '{worker="' . (int) $id . '"} ' . $counters[$counter];
                }
            }

            if ($series !== []) {
                $lines[] = '# TYPE ' . $prefix . '_worker_' . $name . ' ' . $type;
                $lines   = array_merge($lines, $series);
            }
        }

        $lines[] = '# TYPE ' . $prefix . '_workers gauge';
        $lines[] = $prefix . '_workers ' . count($workers);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{workers:array<int,array<string,int>>,totals:array<string,int>}
     */
    private function stats(): array
    {
        try {
            return $this->server()->getStats();
        } catch (\TrueAsync\HttpServerRuntimeException $e) {
            /* getStats() throws for one reason: statistics were not enabled. Restated
             * against the config key that sets it here. */
            throw new \RuntimeException(
                'Server statistics are off — set async.server.stats to true.',
                0,
                $e,
            );
        }
    }

    private function server(): HttpServer
    {
        if ($this->server === null) {
            throw new \RuntimeException(
                'Server metrics are available only inside a request served by TrueAsyncServer.'
            );
        }

        return $this->server;
    }
}
