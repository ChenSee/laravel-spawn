<?php

namespace Spawn\Laravel\Server;

use TrueAsync\HttpServer;

/**
 * The running server's counters, for application code that has no reference to it.
 *
 * The extension keeps per-worker counters and exposes them on the `HttpServer` object;
 * it serves no endpoint and knows no exposition format, by the decision recorded in
 * true-async/server#5. A controller cannot reach that object — `TrueAsyncServer::start()`
 * builds it and never returns — so the server binds it into the worker's container, where
 * this class answers for it:
 *
 *     Route::get('/metrics', fn () => response(
 *         app(ServerMetrics::class)->toPrometheus(),
 *         200,
 *         ['Content-Type' => 'text/plain; version=0.0.4'],
 *     ));
 *
 * Which URL the metrics live at, who may read it and what format it speaks stay with
 * the application. Counters are collected only when `async.server.stats` is on.
 */
final class ServerMetrics
{
    /**
     * The server this worker serves through, or null where none does.
     *
     * The container holds one of these per worker, and a worker serves through one
     * server for its whole life, so this is set once and read by every request of that
     * worker. Nothing per-request is kept here.
     */
    private ?HttpServer $server = null;

    /**
     * Bind the server whose counters this object reports. Called by the server itself;
     * an application has no reason to call it.
     */
    public function useServer(HttpServer $server): void
    {
        $this->server = $server;
    }

    /**
     * Whether counters can be read at all: this process serves requests through the
     * TrueAsync server and `async.server.stats` was on when it started.
     *
     * False under `artisan serve`, under DevServer and in tests, where reading throws.
     */
    public function isAvailable(): bool
    {
        if ($this->server === null) {
            return false;
        }

        try {
            $this->server->getStats();
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Counters summed across every worker, keyed by counter name.
     *
     * Totals also carry the counts of workers that have already exited, so a hot reload
     * does not make a counter run backwards.
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
     * Request timing of the worker that serves this call, in milliseconds.
     *
     * Scoped to one worker because the extension keeps timings per thread: under a pool
     * each scrape lands on whichever worker took the request. Keys are `sojourn_samples`,
     * `sojourn_avg_ms`, `sojourn_max_ms` and `service_avg_ms` — the wait before the
     * handler ran and the handler's own time. Percentiles are not available.
     *
     * Every value reads zero until `async.server.telemetry` is on: the timing stamps
     * these numbers come from are collected only when something asks for them.
     *
     * @return array<string,float|int>
     */
    public function latency(): array
    {
        $telemetry = $this->server()->getTelemetry();

        return array_intersect_key($telemetry, array_flip([
            'sojourn_samples',
            'sojourn_avg_ms',
            'sojourn_max_ms',
            'service_avg_ms',
        ]));
    }

    /**
     * Everything above in one array: `workers`, `totals` and `latency`.
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
     * Every counter is emitted twice: once summed, once per worker under a `worker`
     * label. Timings are left out — they belong to one worker, and a scrape that lands
     * on a different worker each time would draw a line out of unrelated numbers.
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
     * @param array{workers:array<int,array<string,int>>,totals:array<string,int>} $stats
     */
    public static function render(array $stats, string $prefix = 'spawn'): string
    {
        /* Prometheus wants `_total` as a suffix; the extension spells this one counter
         * the other way round. */
        $rename = ['total_requests' => 'requests_total'];

        $lines = [];

        foreach ($stats['totals'] as $counter => $value) {
            $metric = $prefix . '_' . ($rename[$counter] ?? $counter);

            $lines[] = '# TYPE ' . $metric . ' '
                . (str_ends_with($metric, '_total') ? 'counter' : 'gauge');
            $lines[] = $metric . ' ' . $value;

            foreach ($stats['workers'] as $id => $counters) {
                if (array_key_exists($counter, $counters)) {
                    $lines[] = $metric . '{worker="' . $id . '"} ' . $counters[$counter];
                }
            }
        }

        $lines[] = '# TYPE ' . $prefix . '_workers gauge';
        $lines[] = $prefix . '_workers ' . count($stats['workers']);

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
            /* The one way the extension refuses: counters were never collected. Said in
             * the application's own terms, because `async.server.stats` is what decides
             * it here. */
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
