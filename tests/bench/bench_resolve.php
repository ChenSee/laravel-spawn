<?php

/**
 * What it costs to resolve a per-request service, four ways.
 *
 * The container is asked for a service on every helper call, every facade call and
 * every type-hinted parameter, so how the per-request lifetime is implemented is a
 * hot-path decision, not a design preference. This measures the candidates:
 *
 *   A   what AsyncApplication does today: resolve() is intercepted and the service
 *       built here, which means re-implementing what Container::resolve() does.
 *   A'  the same logic behind a cheap alias check, to price the check separately.
 *   B   delegate everything to Container::resolve(), swapping only where the
 *       instance is stored — correct by construction, nothing re-implemented.
 *   C   B, with a context hit answered before the container is involved.
 *
 * Two paths are measured, and they pull in opposite directions: a hit happens many
 * times per request, a miss once per service per request.
 *
 * Run: php tests/bench/bench_resolve.php [iterations]
 */

use Async\Scope;
use Illuminate\Config\Repository;
use Spawn\Laravel\Foundation\AsyncApplication;

use function Async\current_context;

require __DIR__ . '/../../vendor/autoload.php';

const ALIAS = 'widgets';

class BenchWidget
{
}

/**
 * A': today's logic behind the same cheap gate B and C use, so that the comparison
 * measures the design and not the alias check.
 */
class CurrentLogicApplication extends AsyncApplication
{
    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        $alias = $this->getAlias($abstract);

        if (! $this->isAsyncModeEnabled() || $alias !== ALIAS || $parameters !== []) {
            return parent::resolve($abstract, $parameters, $raiseEvents);
        }

        $ctx      = current_context();
        $instance = $ctx->find($alias);

        if ($instance !== null) {
            return $instance;
        }

        $concrete = $this->getBindings()[$alias]['concrete'];
        $instance = $concrete($this, []);

        foreach ($this->getExtenders($alias) as $extender) {
            $instance = $extender($instance, $this);
        }

        $ctx->set($alias, $instance, true);
        $this->fireResolvingCallbacks($alias, $instance);

        return $instance;
    }
}

/** B: the container does all of its work; only where the instance lives changes. */
class DelegatingApplication extends AsyncApplication
{
    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        $alias = $this->getAlias($abstract);

        if (! $this->isAsyncModeEnabled() || $alias !== ALIAS) {
            return parent::resolve($abstract, $parameters, $raiseEvents);
        }

        $ctx  = current_context();
        $mine = $ctx->find($alias);

        if ($mine !== null) {
            $this->instances[$alias] = $mine;
        } else {
            unset($this->instances[$alias]);
        }

        try {
            return parent::resolve($abstract, $parameters, $raiseEvents);
        } finally {
            if (isset($this->instances[$alias])) {
                $ctx->set($alias, $this->instances[$alias], true);
                unset($this->instances[$alias]);
            }
        }
    }
}

/** C: B, with the hit answered before the container is involved. */
class HybridApplication extends AsyncApplication
{
    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        $alias = $this->getAlias($abstract);

        if (! $this->isAsyncModeEnabled() || $alias !== ALIAS) {
            return parent::resolve($abstract, $parameters, $raiseEvents);
        }

        $ctx = current_context();

        if ($parameters === []) {
            $mine = $ctx->find($alias);

            if ($mine !== null) {
                return $mine;
            }
        }

        unset($this->instances[$alias]);

        try {
            return parent::resolve($abstract, $parameters, $raiseEvents);
        } finally {
            if (isset($this->instances[$alias])) {
                $ctx->set($alias, $this->instances[$alias], true);
                unset($this->instances[$alias]);
            }
        }
    }
}

function benchApplication(string $class): AsyncApplication
{
    $app = new $class(sys_get_temp_dir());
    $app->instance('config', new Repository(['async' => ['scoped_services' => [ALIAS]]]));
    $app->singleton(ALIAS, fn () => new BenchWidget());
    $app->singleton('shared.widget', fn () => new BenchWidget());
    $app->enableAsyncMode();

    return $app;
}

/**
 * Milliseconds for $iterations resolves inside one coroutine.
 *
 * With $rebuild the context entry is dropped before each resolve, so every one of
 * them takes the build path instead of answering from the context.
 */
function timeResolves(AsyncApplication $app, string $alias, int $iterations, bool $rebuild = false): float
{
    $ms = 0.0;
    $scope = new Scope();

    $scope->spawn(function () use ($app, $alias, $iterations, $rebuild, &$ms) {
        $app->make($alias);            // warm: the first one always builds

        $ctx   = current_context();
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            if ($rebuild) {
                $ctx->unset($alias);
            }

            $app->make($alias);
        }

        $ms = (hrtime(true) - $start) / 1e6;
    });

    $scope->awaitCompletion(\Async\timeout(120000));

    return $ms;
}

/** @param array{0: string, 1: string} $case */
function report(string $label, array $case, int $iterations, int $runs, bool $rebuild): void
{
    [$class, $alias] = $case;
    $samples = [];

    for ($run = 0; $run < $runs; $run++) {
        $samples[] = timeResolves(benchApplication($class), $alias, $iterations, $rebuild);
    }

    sort($samples);
    $median = $samples[intdiv($runs, 2)];

    printf(
        "%-30s  %8.1f ms   %6.0f ns/call   [%.1f .. %.1f]\n",
        $label,
        $median,
        $median * 1e6 / $iterations,
        $samples[0],
        $samples[$runs - 1],
    );
}

$iterations = (int) ($argv[1] ?? 200000);
$runs = 5;

$cases = [
    'A  intercept resolve()'       => [AsyncApplication::class, ALIAS],
    "A' same, cheap alias check"   => [CurrentLogicApplication::class, ALIAS],
    'B  delegate always'           => [DelegatingApplication::class, ALIAS],
    'C  delegate on a miss only'   => [HybridApplication::class, ALIAS],
];

echo "answered from the context, {$iterations} iterations, median of {$runs} runs\n\n";

foreach ($cases as $label => $case) {
    report($label, $case, $iterations, $runs, rebuild: false);
}

report('   plain singleton (ref)', [AsyncApplication::class, 'shared.widget'], $iterations, $runs, rebuild: false);

$missIterations = intdiv($iterations, 4);

echo "\nbuilt every time, {$missIterations} iterations, median of {$runs} runs\n\n";

foreach ($cases as $label => $case) {
    report($label, $case, $missIterations, $runs, rebuild: true);
}
