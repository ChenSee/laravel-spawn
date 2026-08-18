# Server metrics

`Spawn\Laravel\Server\ServerMetrics` reports the counters the running TrueAsync server
keeps: requests served, responses by status class, live connections per protocol,
streaming and HTTP/2 traffic, static-cache hits. The server publishes no endpoint and
speaks no exposition format ([true-async/server#5](https://github.com/true-async/server/issues/5)
decided against embedding either), so the URL, the access rules and the format are the
application's to choose. This package renders Prometheus text for the common case.

Counters exist only under `async:serve`. Under `artisan serve`, `async:dev` and
FrankenPHP there is no TrueAsync server to report on, and `isAvailable()` returns false.

## Turning them on

`config/async.php`:

```php
'server' => [
    'stats'     => (bool) env('ASYNC_STATS', true),      // counters
    'telemetry' => (bool) env('ASYNC_TELEMETRY', false), // request timings + W3C trace context
],
```

With `stats` off the server allocates no counter slab and every read throws a
`RuntimeException`; `isAvailable()` answers the question without the exception. The
per-request increments are always on, so turning statistics on costs nothing per request.

`telemetry` is what makes `latency()` return anything but zeros: the timing stamps it
reports are collected only when a consumer asks for them.

## Reading them

```php
use Spawn\Laravel\Server\ServerMetrics;

$metrics = app(ServerMetrics::class);   // or the trueasync_metrics() helper

$metrics->isAvailable();   // bool — this process serves through TrueAsyncServer, stats on
$metrics->totals();        // array<string,int> — counters summed across workers
$metrics->workers();       // array<int,array<string,int>> — the same counters per worker
$metrics->latency();       // array<string,float|int> — timings of the answering worker
$metrics->toArray();       // the three above in one array
$metrics->toPrometheus();  // string — Prometheus text exposition format
```

## A Prometheus endpoint

```php
Route::get('/metrics', function () {
    return response(
        app(ServerMetrics::class)->toPrometheus(),
        200,
        ['Content-Type' => 'text/plain; version=0.0.4'],
    );
});
```

The body repeats every counter twice — summed, then once per worker:

```
# TYPE spawn_requests_total counter
spawn_requests_total 1204
spawn_requests_total{worker="0"} 603
spawn_requests_total{worker="1"} 601
# TYPE spawn_conns_active_h2 gauge
spawn_conns_active_h2 18
spawn_conns_active_h2{worker="0"} 9
spawn_conns_active_h2{worker="1"} 9
# TYPE spawn_workers gauge
spawn_workers 2
```

`sum()` the per-worker series back up, or watch a single worker go quiet while the
others keep serving. `toPrometheus('laravel')` changes the name prefix; `total_requests`
is renamed to `requests_total` because Prometheus wants that suffix at the end.

Nothing in the package guards the route. A metrics endpoint tells a reader how much
traffic you take and how much of it fails, so put it behind a middleware, or behind a
listener that only the monitoring network can reach.

## A JSON endpoint

```php
Route::get('/metrics.json', fn () => response()->json(trueasync_metrics()->toArray()));
```

## What the counters are

The names come from the extension, and `totals()` returns all of them. The groups:

| Group | Counters |
|---|---|
| Requests | `total_requests`, `responses_2xx_total`, `responses_3xx_total`, `responses_4xx_total`, `responses_5xx_total`, `active_requests`, `requests_shed_total` |
| Connections | `conns_active_h1`, `conns_active_h2`, `conns_active_h3` |
| HTTP/2 | `h2_streams_active`, `h2_streams_opened_total`, `h2_streams_reset_by_peer_total`, `h2_streams_refused_total`, `h2_goaway_recv_total`, `h2_goaway_sent_total`, `h2_data_recv_bytes_total`, `h2_data_sent_bytes_total`, `h2_ping_rtt_ns` |
| Streaming | `streaming_responses_total`, `stream_send_calls_total`, `stream_bytes_sent_total`, `stream_send_backpressure_events_total` |
| Static files | `static_cache_hits_total`, `static_cache_misses_total`, `static_zero_coroutine_total` |
| TLS bytes | `tls_bytes_plaintext_in_total`, `tls_bytes_plaintext_out_total`, `tls_bytes_ciphertext_in_total`, `tls_bytes_ciphertext_out_total` |
| Housekeeping | `log_records_dropped_total`, `worker_wire_dropped_total`, `h1_connection_close_sent_total`, `h3_goaway_sent_total` |

A name ending in `_total` only ever grows; the rest are readings of the moment.

## Timings

```php
trueasync_metrics()->latency();
// ['sojourn_samples' => 5, 'sojourn_avg_ms' => 0.013, 'sojourn_max_ms' => 0.042, 'service_avg_ms' => 7.5]
```

`sojourn` is the wait between the request arriving and the handler starting, `service`
is the handler's own time. They belong to the worker that answered the call, because the
extension keeps timings per thread — under a pool, consecutive scrapes read different
workers. That is why `toPrometheus()` leaves them out: a series drawn from a different
worker each time is not a series.

Percentiles are not available. The extension keeps a sum, a maximum and a sample count,
not buckets, so p95 cannot be derived from what it exposes
([true-async/server#5](https://github.com/true-async/server/issues/5), stage A6).

## What the numbers do not promise

`workers()` lists the workers alive at that moment, and a worker mid-retire is skipped,
so a reading can lag one worker behind. `totals()` also carries the counts of workers
that have already exited, which is what keeps a counter from running backwards across a
hot reload — the sum of `workers()` is therefore smaller than `totals()` after a reload.
