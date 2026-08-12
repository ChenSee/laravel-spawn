<?php

/**
 * In-process render-load runner (TrueAsyncServer only).
 *
 * Sixty requests at once, each carrying its own token through a page that suspends in
 * the middle of the render: a view composer on `load.partials.aside` yields inside an
 * `@include`, so the sections, the `@push` stack, the `@once` ledger and the component
 * slots of sixty renders are open at the same time. A stand whose only suspension comes
 * before the render proves nothing here, because the renders then run atomically and
 * shared state is indistinguishable from isolated.
 *
 * Checked in the response: the title, the pushed script, the aside behind the
 * suspension, the component slot, the `@once` block, the URL and the queued cookie —
 * each has to carry this request's token and no other. Checked afterwards: the log the
 * terminating callbacks wrote, which runs after the body is built and reads the log
 * context, so nothing in the body would show it.
 *
 * Exits 0 if all scenarios pass, 1 otherwise. Run directly, or via RenderLoadE2ETest.
 */

use Async\Scope;
use Spawn\Laravel\Server\TrueAsyncServer;

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;
use function Async\delay;
use function Async\timeout;

require __DIR__ . '/../../vendor/autoload.php';

$autoload  = __DIR__ . '/../../vendor/autoload.php';
$bootstrap = __DIR__ . '/../bench/bootstrap/app.php';
$log       = __DIR__ . '/../bench/storage/render-load.log';
$host = '127.0.0.1';
$port = 8397;

/** How many requests are in flight at once; the first argument overrides it. */
$concurrency = max(1, (int) ($argv[1] ?? 60));

$exitCode = 1;

@unlink($log);

$main = spawn(static function () use ($autoload, $bootstrap, $log, $host, $port, $concurrency, &$exitCode) {
    $ready = new Async\ThreadChannel(1);

    spawn_thread(static function () use ($ready, $autoload, $bootstrap, $host, $port) {
        try {
            // A fresh engine per worker thread: nothing loaded in the parent is visible.
            require $autoload;

            $app = require $bootstrap;
            $app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

            $server = new TrueAsyncServer($autoload, $bootstrap, [
                'listeners' => [['host' => $host, 'port' => $port, 'tls' => false, 'protocol' => 'auto']],
                'workers'   => 1,
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

    /** @return array{0:int,1:string,2:string} status, headers and body */
    $get = static function (string $path) use ($host, $port): array {
        $fp = false;
        for ($i = 0; $i < 40 && !$fp; $i++) {
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 1);
            if (!$fp) { delay(50); }
        }
        if (!$fp) { return [0, '', 'CONNECT-FAIL']; }
        fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $raw = '';
        while (!feof($fp)) { $c = fread($fp, 8192); if ($c === false || $c === '') break; $raw .= $c; }
        fclose($fp);
        $status = preg_match('#^HTTP/\d\.\d (\d+)#', $raw, $m) ? (int) $m[1] : 0;
        $pos = strpos($raw, "\r\n\r\n");
        return $pos === false
            ? [$status, $raw, '']
            : [$status, substr($raw, 0, $pos), substr($raw, $pos + 4)];
    };

    $tokens    = [];
    $responses = [];

    for ($i = 1; $i <= $concurrency; $i++) {
        $tokens[] = sprintf('t%04d', $i);
    }

    // One warm-up request: the first render of a page compiles it, and a compile inside
    // sixty concurrent renders would measure the compiler rather than the state.
    $get('/render-load?token=warmup');

    $scope = new Scope();

    foreach ($tokens as $token) {
        $scope->spawn(static function () use ($token, $get, &$responses): void {
            $responses[$token] = $get('/render-load?token=' . $token);
        });
    }

    $scope->awaitCompletion(timeout(30_000));

    $answered = 0;
    $ownTokenOnly = [];
    $missing = [];

    foreach ($tokens as $token) {
        [$status, $headers, $body] = $responses[$token] ?? [0, '', ''];

        if ($status === 200) {
            $answered++;
        }

        // Every token-shaped string in the page has to be this request's own.
        preg_match_all('/t\d{4}/', $body, $found);
        $others = array_values(array_unique(array_diff($found[0], [$token])));

        if ($others !== []) {
            $ownTokenOnly[$token] = $others;
        }

        $wanted = [
            "<title>{$token}</title>",
            "<script>{$token}</script>",
            "<aside>{$token}</aside>",
            "<panel>slot:{$token}</panel>",
            "<once>{$token}</once>",
            "token={$token}</url>",
        ];

        foreach ($wanted as $fragment) {
            if (!str_contains($body, $fragment)) {
                $missing[$token][] = $fragment;
            }
        }

        // @once emits from the first of the two includes and from neither of the others.
        if (substr_count($body, '<once>') !== 1) {
            $missing[$token][] = '@once emitted ' . substr_count($body, '<once>') . ' times';
        }

        if (!str_contains($headers, "probe={$token}")) {
            $missing[$token][] = "Set-Cookie probe={$token}";
        }
    }

    $check("render-load: all {$concurrency} requests answered ({$answered})", $answered === $concurrency);

    $check(
        'render-load: no page carries another request\'s token'
            . ($ownTokenOnly === [] ? '' : ' — ' . json_encode(array_slice($ownTokenOnly, 0, 3))),
        $ownTokenOnly === []
    );

    $check(
        'render-load: every page carries its own token in every place'
            . ($missing === [] ? '' : ' — ' . json_encode(array_slice($missing, 0, 3))),
        $missing === []
    );

    // terminate() runs after the response is written, so the log lags the last read.
    delay(500);

    $lines = array_filter(array_map('trim', explode("\n", (string) @file_get_contents($log))));
    $mixed = [];
    $seen  = [];

    foreach ($lines as $line) {
        [$token, $inCallback] = array_pad(explode(':', $line, 2), 2, '');
        $seen[$token] = true;

        if ($token !== $inCallback) {
            $mixed[] = $line;
        }
    }

    $check(
        'render-load: every terminating callback ran with its own log context'
            . ($mixed === [] ? '' : ' — ' . json_encode(array_slice($mixed, 0, 3))),
        $mixed === []
    );

    $ran = count(array_intersect_key($seen, array_flip($tokens)));

    $check("render-load: every request ran its terminating callback once ({$ran})", $ran === $concurrency);

    echo "\nE2E: {$pass} passed, {$fail} failed\n";
    $exitCode = $fail === 0 ? 0 : 1;

    posix_kill(posix_getpid(), SIGTERM);
});

await($main);
exit($exitCode);
