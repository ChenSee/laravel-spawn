<?php

/**
 * In-process server-metrics runner (TrueAsyncServer only).
 *
 * Drives a real server, then reads its counters back through the route the fixture
 * declares — the package publishes no endpoint of its own, so this is also the check
 * that an application can declare one. What the numbers have to satisfy: the request
 * total counts the requests this runner made, every live worker appears with its own
 * series, and the Prometheus rendering types a `_total` as a counter.
 *
 * `php run_metrics.php off` starts the same server with `stats` off, where the counters
 * are unavailable and asking for one throws instead of answering zero. `timing` starts it
 * with `telemetry` on, which is what makes the latency numbers move, and `pool` starts two
 * workers, where the counters of both have to add up to the totals of one scrape.
 *
 * Exits 0 if all scenarios pass, 1 otherwise. Run directly, or via ServerMetricsE2ETest.
 */

use Spawn\Laravel\Server\TrueAsyncServer;

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;
use function Async\delay;

require __DIR__ . '/../../vendor/autoload.php';

$autoload  = __DIR__ . '/../../vendor/autoload.php';
$bootstrap = __DIR__ . '/../bench/bootstrap/app.php';
$host = '127.0.0.1';

/*
 * Four modes, a port each: `on` is the shipped default (counters, no timing stamps),
 * `timing` adds `telemetry`, `pool` runs two workers, `off` runs with the counters
 * disabled.
 */
$mode         = $argv[1] ?? 'on';
$statsEnabled = $mode !== 'off';
$telemetry    = $mode === 'timing';
$workers      = $mode === 'pool' ? 2 : 1;
$port         = ['on' => 8398, 'off' => 8399, 'timing' => 8400, 'pool' => 8401][$mode] ?? 8398;

$exitCode = 1;

$main = spawn(static function () use (
    $autoload,
    $bootstrap,
    $host,
    $port,
    $statsEnabled,
    $telemetry,
    $workers,
    &$exitCode
) {
    $ready = new Async\ThreadChannel(1);

    spawn_thread(static function () use (
        $ready,
        $autoload,
        $bootstrap,
        $host,
        $port,
        $statsEnabled,
        $telemetry,
        $workers
    ) {
        try {
            // A fresh engine per worker thread: nothing loaded in the parent is visible.
            require $autoload;

            $app = require $bootstrap;
            $app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

            $server = new TrueAsyncServer($autoload, $bootstrap, [
                'listeners' => [['host' => $host, 'port' => $port, 'tls' => false, 'protocol' => 'auto']],
                'workers'   => $workers,
                'stats'     => $statsEnabled,
                'telemetry' => $telemetry,
            ]);
            $ready->send('ok');
            $server->start();
        } catch (\Throwable $e) {
            $ready->send('ERR ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    });

    $signal = $ready->recv();
    if ($signal !== 'ok') {
        fwrite(STDERR, "server boot failed: {$signal}\n");
        return;
    }

    $pass = 0; $fail = 0;
    $check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
        echo ($ok ? 'PASS' : 'FAIL') . " — {$name}\n";
        $ok ? $pass++ : $fail++;
    };

    /** @return array{0:int,1:string} status and body */
    $get = static function (string $path) use ($host, $port): array {
        $fp = false;
        for ($i = 0; $i < 40 && !$fp; $i++) {
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 1);
            if (!$fp) { delay(50); }
        }
        if (!$fp) { return [0, 'CONNECT-FAIL']; }
        fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $raw = '';
        while (!feof($fp)) { $c = fread($fp, 8192); if ($c === false || $c === '') break; $raw .= $c; }
        fclose($fp);
        $status = preg_match('#^HTTP/\d\.\d (\d+)#', $raw, $m) ? (int) $m[1] : 0;
        $pos = strpos($raw, "\r\n\r\n");
        return [$status, $pos === false ? '' : substr($raw, $pos + 4)];
    };

    if (!$statsEnabled) {
        [$status, $body] = $get('/metrics-availability');
        $report = json_decode($body, true) ?: [];

        $check('stats off: the probe route answers', $status === 200);
        $check('stats off: metrics report themselves unavailable', ($report['available'] ?? null) === false);
        $check(
            'stats off: reading a counter throws in the package\'s own terms — '
                . var_export($report['thrown'] ?? null, true),
            ($report['thrown'] ?? null) === 'RuntimeException',
        );
        // The timing stamps are gated by `telemetry`, not by `stats`, so these answer.
        $check(
            'stats off: the timings answer with zeros rather than throwing — '
                . json_encode($report['latency'] ?? null),
            ($report['latency']['sojourn_samples'] ?? null) === 0,
        );

        echo "\nE2E: {$pass} passed, {$fail} failed\n";
        $exitCode = $fail === 0 ? 0 : 1;

        posix_kill(posix_getpid(), SIGTERM);
        return;
    }

    /** Requests made before the first scrape; the request total has to have counted them. */
    $warmup = 5;

    for ($i = 0; $i < $warmup; $i++) {
        $get('/ping');
    }

    [$status, $body] = $get('/metrics.json');
    $report = json_decode($body, true) ?: [];

    $check('json: the metrics route answers', $status === 200);
    $check('json: metrics report themselves available', ($report['available'] ?? null) === true);

    $totals   = $report['totals'] ?? [];
    $reported = $report['workers'] ?? [];

    $check(
        'json: the request total counts the requests made (' . ($totals['total_requests'] ?? 'missing') . ')',
        ($totals['total_requests'] ?? 0) >= $warmup,
    );
    $check(
        'json: 2xx responses are counted (' . ($totals['responses_2xx_total'] ?? 'missing') . ')',
        ($totals['responses_2xx_total'] ?? 0) >= $warmup,
    );
    $check(
        'json: every live worker reports (' . count($reported) . ' of ' . $workers . ')',
        count($reported) === $workers,
    );

    /* The point of the pool mode: one scrape has to answer for every worker. No reload
     * happened and an H1 listener is served by workers alone, so the per-worker requests
     * add up to the totals exactly. */
    $summed = array_sum(array_column($reported, 'total_requests'));

    $check(
        "json: the workers' own requests add up to the totals ({$summed} of "
            . ($totals['total_requests'] ?? 'missing') . ')',
        $summed === ($totals['total_requests'] ?? -1),
    );

    $latency = $report['latency'] ?? [];

    $wanted = ['sojourn_samples', 'sojourn_avg_ms', 'sojourn_max_ms', 'service_avg_ms'];

    // A set, not a list: the order comes from the extension's own telemetry array.
    $check(
        'json: latency carries the four timing keys — ' . json_encode($latency),
        array_diff($wanted, array_keys($latency)) === [] && count($latency) === count($wanted),
    );

    if ($telemetry) {
        $check(
            'timing: the handler time was sampled (' . ($latency['sojourn_samples'] ?? 'missing') . ' samples)',
            ($latency['sojourn_samples'] ?? 0) >= $warmup,
        );
        $check(
            'timing: the handler time is above zero (' . ($latency['service_avg_ms'] ?? 'missing') . ' ms)',
            ($latency['service_avg_ms'] ?? 0) > 0,
        );
    } else {
        // The stamps these come from are collected only when something asks for them,
        // and counters alone do not.
        $check(
            'default: timings read zero while telemetry is off',
            ($latency['sojourn_samples'] ?? null) === 0,
        );
    }

    [$status, $text] = $get('/metrics');

    $check('prometheus: the metrics route answers', $status === 200);
    $check(
        'prometheus: the request total is renamed and typed as a counter',
        str_contains($text, "# TYPE spawn_requests_total counter\n"),
    );
    $check(
        'prometheus: a gauge is typed as a gauge',
        str_contains($text, "# TYPE spawn_active_requests gauge\n"),
    );
    $check(
        'prometheus: each counter also appears per worker under a name of its own',
        (bool) preg_match('/^spawn_worker_requests_total\{worker="\d+"\} \d+$/m', $text),
    );
    // One name carrying both the sum and the parts would double every sum() over it.
    $check(
        'prometheus: the summed reading shares no name with the per-worker series',
        !preg_match('/^spawn_requests_total\{/m', $text),
    );
    $check(
        'prometheus: the worker count is reported',
        (bool) preg_match('/^spawn_workers \d+$/m', $text),
    );

    // The value the exposition carries is the one the array carried a moment earlier.
    preg_match('/^spawn_requests_total (\d+)$/m', $text, $m);

    $check(
        'prometheus: the request total matches the array reading (' . ($m[1] ?? 'missing') . ')',
        isset($m[1]) && (int) $m[1] >= ($totals['total_requests'] ?? 0),
    );

    echo "\nE2E: {$pass} passed, {$fail} failed\n";
    $exitCode = $fail === 0 ? 0 : 1;

    posix_kill(posix_getpid(), SIGTERM);
});

await($main);
exit($exitCode);
