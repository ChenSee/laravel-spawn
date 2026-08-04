<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Foundation\AsyncApplication;

/**
 * The two reports the container makes about a worker that will silently lose its
 * boot-time configuration.
 *
 * Both go through error_log(), which is observable: pointed at a file by the
 * error_log ini setting, that is where the message lands. Nothing else about them is
 * — they carry no return value and throw nothing — so the log is the contract.
 */
class AsyncDiagnosticsTest extends TestCase
{
    /**
     * Everything error_log() emits while the callback runs.
     */
    private function logged(callable $act): string
    {
        $file     = tempnam(sys_get_temp_dir(), 'async-diagnostics');
        $previous = ini_get('error_log');

        ini_set('error_log', $file);

        try {
            $act();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $written = (string) file_get_contents($file);
        unlink($file);

        return $written;
    }

    /**
     * @param  string[]  $scoped
     */
    private function container(array $scoped, bool $diagnostics): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new Repository([
            'async' => ['scoped_services' => $scoped, 'diagnostics' => $diagnostics],
        ]));

        return $app;
    }

    public function test_a_scoped_service_configured_at_boot_with_no_seeder_is_reported(): void
    {
        $app = $this->container(['widgets'], diagnostics: true);
        $app->singleton('widgets', fn () => new \stdClass());

        /* A provider resolved it and configured the object — that is what will be lost. */
        $app->make('widgets');

        $written = $this->logged($app->enableAsyncMode(...));

        $this->assertStringContainsString("scoped service 'widgets'", $written);
        $this->assertStringContainsString('scopedSeeder()', $written);
    }

    public function test_a_scoped_service_with_a_seeder_is_not_reported(): void
    {
        $app = $this->container(['widgets'], diagnostics: true);
        $app->singleton('widgets', fn () => new \stdClass());
        $app->scopedSeeder('widgets', function (): void {
        });
        $app->make('widgets');

        $this->assertSame('', $this->logged($app->enableAsyncMode(...)), 'a seeded service carries its configuration');
    }

    public function test_a_scoped_service_never_resolved_at_boot_is_not_reported(): void
    {
        $app = $this->container(['widgets'], diagnostics: true);
        $app->singleton('widgets', fn () => new \stdClass());

        $this->assertSame(
            '',
            $this->logged($app->enableAsyncMode(...)),
            'nothing was configured, so nothing can be lost',
        );
    }

    public function test_the_services_the_package_scopes_itself_are_not_reported(): void
    {
        $app = $this->container([], diagnostics: true);
        $app->singleton('session', fn () => new \stdClass());
        $app->make('session');

        $this->assertSame(
            '',
            $this->logged($app->enableAsyncMode(...)),
            'the package builds its own scoped services in full; warning about them is crying wolf',
        );
    }

    public function test_nothing_is_reported_while_diagnostics_are_off(): void
    {
        $app = $this->container(['widgets'], diagnostics: false);
        $app->singleton('widgets', fn () => new \stdClass());
        $app->make('widgets');

        $this->assertSame('', $this->logged($app->enableAsyncMode(...)), 'the report is opt-in');
    }

    /**
     * The unconditional half: async mode switched on before the providers booted.
     *
     * Everything a provider registers from that point on lands on an object no coroutine
     * will ever see, and nothing else says so.
     */
    public function test_async_mode_enabled_before_the_application_booted_is_reported(): void
    {
        $app = $this->container([], diagnostics: false);
        $app->bind(HttpKernel::class, fn () => null);

        $written = $this->logged($app->enableAsyncMode(...));

        $this->assertStringContainsString('async mode was enabled before the application booted', $written);
    }

    public function test_a_booted_application_is_not_reported(): void
    {
        $app = $this->container([], diagnostics: false);
        $app->bind(HttpKernel::class, fn () => null);
        $app->boot();

        $this->assertSame('', $this->logged($app->enableAsyncMode(...)));
    }

    public function test_a_container_with_no_http_kernel_is_not_reported(): void
    {
        $app = $this->container([], diagnostics: false);

        $this->assertSame(
            '',
            $this->logged($app->enableAsyncMode(...)),
            'a container assembled by a test has no providers of its own to lose',
        );
    }
}
