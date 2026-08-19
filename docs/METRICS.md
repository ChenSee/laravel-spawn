# Server metrics

`Spawn\Laravel\Server\ServerMetrics` reports the counters the running TrueAsync server
keeps: requests served, responses by status class, live connections per protocol,
streaming and HTTP/2 traffic, static-cache hits. The extension exposes them on the
`HttpServer` object and serves no endpoint of its own, so the URL, the access rules and
the format are the application's ([true-async/server#5](https://github.com/true-async/server/issues/5)
decided against embedding an exporter). This package renders Prometheus text for the
common case.

The counters exist only under `async:serve`. Under `artisan serve`, `async:dev` and
FrankenPHP no TrueAsync server is running and `isAvailable()` returns false.

## Turning them on

`config/async.php`:

```php
'server' => [
    'stats'     => (bool) env('ASYNC_STATS', true),      // aggregate readable
    'telemetry' => (bool) env('ASYNC_TELEMETRY', false), // request timings + W3C trace context
],
```

The per-request increments always run; `stats` decides whether the aggregate can be read.
With it off the server allocates no counter slab, every counter read throws a
`RuntimeException`, and `isAvailable()` answers false without the exception.

`telemetry` is what makes `latency()` return anything but zeros: the timing stamps it
reports are collected only when a consumer asks for them.

An application that published `config/async.php` before this release has neither key:
`mergeConfigFrom` merges the top level only, so nothing under `server` is filled in from
the package. The defaults still apply (`stats` on, `telemetry` off), but `ASYNC_STATS`
will not be read until the two keys are added to the published file.

## Reading them

```php
use Spawn\Laravel\Server\ServerMetrics;

$metrics = app(ServerMetrics::class);   // or the trueasync_metrics() helper

$metrics->isAvailable();   // bool — a TrueAsync server is serving and stats are on
$metrics->totals();        // array<string,int> — counters summed across workers
$metrics->workers();       // array<int,array<string,int>> — the same counters per worker
$metrics->latency();       // array<string,float|int> — timings of the answering worker
$metrics->toArray();       // the three above in one array
$metrics->toPrometheus();  // string — Prometheus text exposition format
```

`toArray()` is one call for a JSON endpoint; the counter readings inside it are taken one
after the other, so under traffic the per-worker numbers and the totals are moments apart.

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

Summed and per-worker readings go into separate metric families:

```
# TYPE spawn_requests_total counter
spawn_requests_total 1204
# TYPE spawn_worker_requests_total counter
spawn_worker_requests_total{worker="0"} 603
spawn_worker_requests_total{worker="1"} 598
# TYPE spawn_conns_active_h2 gauge
spawn_conns_active_h2 18
# TYPE spawn_worker_conns_active_h2 gauge
spawn_worker_conns_active_h2{worker="0"} 9
spawn_worker_conns_active_h2{worker="1"} 9
# TYPE spawn_workers gauge
spawn_workers 2
```

One name carrying both the sum and the parts would make `sum(spawn_requests_total)` count
every request twice, which is why `spawn_worker_*` is a family of its own. Alert on
`spawn_requests_total`; read `spawn_worker_*` to see one worker stop while the others keep
serving. `toPrometheus('laravel')` changes the prefix, and `total_requests` is renamed to
`requests_total` because Prometheus names a counter with that suffix.

Nothing in the package guards the route. A metrics endpoint exposes request volume and
error rates, so put it behind middleware, or on a listener only the monitoring network can
reach.

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

A name ending in `_total` only grows, and `totals()` sums it across workers. The rest are
readings of the moment: gauges are summed over live workers, while `h2_ping_rtt_ns` is the
largest reading of any worker rather than a sum.

## Timings

```php
trueasync_metrics()->latency();
// ['sojourn_samples' => 5, 'sojourn_avg_ms' => 0.013, 'sojourn_max_ms' => 0.042, 'service_avg_ms' => 7.5]
```

`sojourn` is the wait between the request arriving and the handler starting, `service` is
the handler's own time. Both are averages over the answering worker's whole life, and
`sojourn_max_ms` never falls, so a slow minute stays visible in them for hours.

They belong to that one worker, because the extension keeps timings per thread: under a
pool, consecutive scrapes read different workers
([true-async/server#169](https://github.com/true-async/server/issues/169)). That is why
`toPrometheus()` leaves them out — a series built from a different worker each time
measures nothing.

Percentiles are not available. The extension keeps a sum, a maximum and a sample count,
not buckets ([true-async/server#5](https://github.com/true-async/server/issues/5), stage
A6).

## Limits

`workers()` lists the workers alive at that moment, and a worker mid-retire is skipped, so
a reading can lag one worker behind. The per-worker numbers add up to `totals()` only when
neither of two things has happened: a hot reload, after which the totals still carry the
monotonic counts of workers that have exited (their gauges are gone), and a request served
end to end by a reactor thread — HTTP/3 packets and some static files — whose counts reach
the totals without belonging to any worker.
