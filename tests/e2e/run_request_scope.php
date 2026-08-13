<?php

/**
 * In-process request-scope end-to-end runner (TrueAsyncServer only).
 *
 * A request scope is assigned by the server extension's C code and by nothing else:
 * `Async\request_context()` is null under DevServer, under artisan and inside PHPUnit,
 * so the container's choice of context is only observable through a genuine
 * TrueAsync\HttpServer. This boots one in a worker thread — the same fixture and the
 * same shape as run_streaming.php — and asks the /request-scope route whether the
 * coroutines of one request share their per-request services.
 *
 * Exits 0 if all scenarios pass, 1 otherwise. Run directly, or via RequestScopeE2ETest.
 */

use Async\ThreadChannel;
use Spawn\Laravel\Server\TrueAsyncServer;

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;
use function Async\delay;

require __DIR__ . '/../../vendor/autoload.php';

$autoload  = __DIR__ . '/../../vendor/autoload.php';
$bootstrap = __DIR__ . '/../bench/bootstrap/app.php';
$host = '127.0.0.1';
$port = 8399;

$exitCode = 1;

$main = spawn(static function () use ($autoload, $bootstrap, $host, $port, &$exitCode) {
    $ready = new ThreadChannel(1);

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

    [$status, $body] = $get('/request-scope');
    $first = json_decode($body, true);

    $check('request-scope: the route answered (' . $status . ': ' . trim($body) . ')', $status === 200 && is_array($first));

    // Without this the rest proves nothing: the container would be falling back to
    // current_context() and the probe would pass on a broken build.
    $check('request-scope: the server assigned a request scope', ($first['request_scope'] ?? false) === true);

    $check(
        'request-scope: a coroutine spawned inside the request shares its services',
        ($first['shared'] ?? false) === true
    );

    // The adapters answer through the same context as the container, so a config
    // overlay and a locale written by the nested coroutine reach the response.
    $check(
        'request-scope: the config overlay written inside the coroutine reaches the handler',
        ($first['config_shared'] ?? false) === true
    );

    $check(
        'request-scope: so does the locale',
        ($first['locale_shared'] ?? false) === true
    );

    [, $body] = $get('/request-scope');
    $second = json_decode($body, true);

    $check(
        'request-scope: and the next request gets services of its own',
        is_array($second) && ($second['shared'] ?? false) === true && $second['id'] !== ($first['id'] ?? null)
    );

    echo "\nE2E: {$pass} passed, {$fail} failed\n";
    $exitCode = $fail === 0 ? 0 : 1;

    posix_kill(posix_getpid(), SIGTERM);
});

await($main);
exit($exitCode);
