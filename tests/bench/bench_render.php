<?php

/**
 * What the per-request render state costs a page.
 *
 * The sixteen render properties are removed from the view factory, so every read and
 * write the framework's own traits make goes through `__get()`/`__set()` and asks the
 * request's context for a {@see Spawn\Laravel\View\BladeRenderState}. A `@foreach` touches
 * those properties on every iteration, which is why the price per access matters rather
 * than the price per render.
 *
 * Three cases, and the difference between the last two is the whole question:
 *
 *   stock     Illuminate\View\Factory — real properties, no magic access.
 *   process   AsyncViewFactory before bootCompleted(): the magic access happens, the
 *             state is a property, and no context is consulted.
 *   request   AsyncViewFactory while serving: the same, with the context asked for the
 *             state on every access.
 *
 * stock -> process prices the magic access; process -> request prices the context lookup,
 * which is what a per-coroutine memo could remove.
 *
 * Run: php tests/bench/bench_render.php [rows] [renders]
 */

use Async\Scope;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Factory;
use Illuminate\View\ViewServiceProvider;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;

require __DIR__ . '/../../vendor/autoload.php';

$views    = sys_get_temp_dir() . '/spawn-bench-views-' . getmypid();
$compiled = $views . '/compiled';

@mkdir($compiled, 0777, true);

file_put_contents(
    $views . '/layout.blade.php',
    "<title>@yield('title')</title><body>@yield('content')</body>",
);
file_put_contents(
    $views . '/loop.blade.php',
    "@extends('layout')\n"
    . "@section('title', \$title)\n"
    . "@section('content')@foreach (\$rows as \$row)<li>{{ \$loop->index }}:{{ \$row }}</li>@endforeach@endsection\n",
);

register_shutdown_function(static function () use ($views, $compiled): void {
    foreach (array_merge(glob($compiled . '/*') ?: [], glob($views . '/*.blade.php') ?: []) as $file) {
        unlink($file);
    }

    @rmdir($compiled);
    @rmdir($views);
});

function benchApplication(string $views, string $compiled): AsyncApplication
{
    $app = new AsyncApplication(sys_get_temp_dir());

    $app->instance('files', new Filesystem());
    $app->instance('config', new Repository([
        'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
        'view'  => ['paths' => [$views], 'compiled' => $compiled],
    ]));

    $app->register(EventServiceProvider::class);
    $app->register(ViewServiceProvider::class);
    $app->register(AsyncServiceProvider::class);
    $app->boot();

    return $app;
}

/**
 * Milliseconds for $renders renders inside one coroutine, the first one not counted:
 * it compiles the template.
 */
function timeRenders(Factory $factory, int $rows, int $renders): float
{
    $ms   = 0.0;
    $data = ['title' => 'bench', 'rows' => range(1, $rows)];

    $scope = new Scope();

    $scope->spawn(function () use ($factory, $data, $renders, &$ms) {
        $factory->make('loop', $data)->render();

        $start = hrtime(true);

        for ($i = 0; $i < $renders; $i++) {
            $factory->make('loop', $data)->render();
        }

        $ms = (hrtime(true) - $start) / 1e6;
    });

    $scope->awaitCompletion(\Async\timeout(300000));

    return $ms;
}

function report(string $label, callable $factory, int $rows, int $renders, int $runs, float $reference): float
{
    $samples = [];

    for ($run = 0; $run < $runs; $run++) {
        $samples[] = timeRenders($factory(), $rows, $renders);
    }

    sort($samples);
    $median = $samples[intdiv($runs, 2)];

    printf(
        "%-34s  %8.2f ms   %7.1f us/render   %s   [%.2f .. %.2f]\n",
        $label,
        $median,
        $median * 1000 / $renders,
        $reference > 0.0 ? sprintf('%+6.1f%%', ($median / $reference - 1) * 100) : '  base ',
        $samples[0],
        $samples[$runs - 1],
    );

    return $median;
}

$rows    = (int) ($argv[1] ?? 500);
$renders = (int) ($argv[2] ?? 200);
$runs    = (int) ($argv[3] ?? 5);

echo "{$rows} rows per page, {$renders} renders, median of {$runs} runs\n\n";

$stock = report('stock Illuminate\\View\\Factory', static function () use ($views, $compiled) {
    $app = benchApplication($views, $compiled);

    return new Factory($app->make('view.engine.resolver'), $app->make('view.finder'), $app->make('events'));
}, $rows, $renders, $runs, 0.0);

report('async factory, process state', static function () use ($views, $compiled) {
    return benchApplication($views, $compiled)->make('view');
}, $rows, $renders, $runs, $stock);

report('async factory, request state', static function () use ($views, $compiled) {
    $app     = benchApplication($views, $compiled);
    $factory = $app->make('view');

    $factory->bootCompleted();
    $app->enableAsyncMode();

    return $factory;
}, $rows, $renders, $runs, $stock);
