<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\ViewServiceProvider;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;

/**
 * Two pages compiled and rendered at once come out whole and separate.
 *
 * This goes through Blade rather than through the factory's methods, because the two are
 * not the same object path: a compiled template writes through `$__env`, which the
 * factory shares into every view's data. A factory that is correct when called directly
 * and wrong as `$__env` renders every page against the wrong state, and only a real
 * render says which one is in use.
 *
 * The suspension is a view composer, which is where a real render suspends first: a
 * composer that queries the database hands the coroutine over in the middle of a section.
 */
class BladeRenderE2ETest extends AsyncTestCase
{
    private string $views;

    private string $compiled;

    protected function setUp(): void
    {
        parent::setUp();

        $this->views    = sys_get_temp_dir() . '/spawn-views-' . getmypid();
        $this->compiled = $this->views . '/compiled';

        @mkdir($this->views . '/partials', 0777, true);
        @mkdir($this->compiled, 0777, true);

        file_put_contents(
            $this->views . '/layout.blade.php',
            "<title>@yield('title')</title>@stack('scripts')<body>@yield('content')</body>",
        );
        file_put_contents(
            $this->views . '/page.blade.php',
            "@extends('layout')\n"
            . "@section('title', \$title)\n"
            . "@push('scripts')<script src=\"{{ \$script }}\"></script>@endpush\n"
            . "@section('content')@include('partials.sidebar')<p>{{ \$title }}</p>@endsection\n",
        );
        file_put_contents(
            $this->views . '/partials/sidebar.blade.php',
            "<aside>{{ \$title }}</aside>",
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->compiled . '/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->views . '/partials/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->views . '/*.blade.php') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->compiled);
        @rmdir($this->views . '/partials');
        @rmdir($this->views);

        Facade::clearResolvedInstances();

        parent::tearDown();
    }

    private function bootedApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('files', new Filesystem());
        $app->instance('config', new Repository([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'view'  => ['paths' => [$this->views], 'compiled' => $this->compiled],
        ]));

        $app->register(EventServiceProvider::class);
        $app->register(ViewServiceProvider::class);
        $app->register(AsyncServiceProvider::class);
        $app->boot();

        $factory = $app->make('view');

        /* Where a real render hands the coroutine over: a composer that talks to the world. */
        $factory->composer('partials.sidebar', fn () => \Async\suspend());

        $factory->bootCompleted();
        $app->enableAsyncMode();

        return $app;
    }

    public function test_two_pages_rendered_at_once_do_not_mix(): void
    {
        $app = $this->bootedApp();

        $render = fn (string $title, string $script) => $app->make('view')
            ->make('page', ['title' => $title, 'script' => $script])
            ->render();

        $html = $this->runParallel([
            'first'  => fn () => $render('First', 'first.js'),
            'second' => fn () => $render('Second', 'second.js'),
        ]);

        $this->assertStringContainsString('<title>First</title>', $html['first']);
        $this->assertStringContainsString('<aside>First</aside>', $html['first']);
        $this->assertStringContainsString('first.js', $html['first']);
        $this->assertStringNotContainsString('Second', $html['first']);
        $this->assertStringNotContainsString('second.js', $html['first']);

        $this->assertStringContainsString('<title>Second</title>', $html['second']);
        $this->assertStringContainsString('<aside>Second</aside>', $html['second']);
        $this->assertStringContainsString('second.js', $html['second']);
        $this->assertStringNotContainsString('First', $html['second']);
    }

    /**
     * An `@include` inside a `@section` finishes its own render while the outer one is
     * still open. With a shared nesting counter the inner render reaches zero, the
     * factory flushes the section stack, and `@endsection` has nothing to close.
     */
    public function test_a_section_wrapping_an_include_closes(): void
    {
        $app = $this->bootedApp();

        $html = $this->runParallel([
            'only' => fn () => $app->make('view')
                ->make('page', ['title' => 'Alone', 'script' => 'alone.js'])
                ->render(),
        ]);

        $this->assertStringContainsString('<aside>Alone</aside>', $html['only']);
        $this->assertStringContainsString('<p>Alone</p>', $html['only']);
    }
}
