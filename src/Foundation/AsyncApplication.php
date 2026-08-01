<?php

namespace Spawn\Laravel\Foundation;

use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

use function Async\current_context;

class AsyncApplication extends Application
{
    /**
     * Scoped services that are safe to proxy via offsetGet (used by Facades).
     * Services that get passed to typed PHP parameters must NOT be here,
     * because ScopedServiceProxy does not extend/implement their types.
     *
     * 'cookie' is excluded: AuthManager passes $app['cookie'] to setCookieJar(QueueingFactory).
     * 'auth.driver' is excluded: guards are passed to typed parameters in some middleware.
     */
    private const FACADE_PROXIED_MAP = [
        'auth'    => true,
        'session' => true,
    ];

    /**
     * True while the async HTTP server is running.
     */
    private bool $asyncMode = false;

    /**
     * User-registered scoped factories: abstract => Closure.
     */
    private array $scopedBindings = [];

    /**
     * Registration transfer callbacks: abstract => Closure(object $fresh, object $bootInstance).
     */
    private array $scopedSeeders = [];

    /**
     * What the container held for each scoped alias when async mode started:
     * abstract => object. A missing entry means the service was never resolved
     * during bootstrap, so it carries no configuration worth transferring.
     */
    private array $scopedPrototypes = [];

    /**
     * Cached config('async.scoped_services') as alias => true hash map.
     * Populated once in enableAsyncMode() to avoid per-resolve config lookups.
     */
    private array $scopedServiceCache = [];

    public function isAsyncModeEnabled(): bool
    {
        return $this->asyncMode;
    }

    /**
     * While serving HTTP the worker is a web app, not a console command — even
     * though it was launched from the CLI. Once async serving is active, let the
     * framework and packages (Debugbar, etc.) detect web context. runningInConsole()
     * is Laravel's own gate for this; overriding it here is honest about the runtime
     * mode and avoids relying on the PHP_SAPI fallback.
     */
    public function runningInConsole(): bool
    {
        if ($this->asyncMode) {
            return false;
        }

        return parent::runningInConsole();
    }

    /**
     * Switch the container over to per-coroutine resolution of scoped services.
     *
     * Call once per worker, after the framework has finished bootstrapping: what
     * the providers configured during boot is captured here, and everything that
     * cached a boot-time instance is invalidated. Calling it earlier means the
     * providers configure objects nobody will ever see again.
     */
    public function enableAsyncMode(): void
    {
        $this->asyncMode = true;

        $config = $this->resolved('config') ? $this->make('config') : null;

        if ($config !== null) {
            $this->scopedServiceCache = array_flip($config->get('async.scoped_services', []));
        }

        $this->captureScopedPrototypes();
        $this->reportPrematureAsyncMode();

        // A facade resolved during bootstrap keeps the boot-time instance in a static
        // array of its own and would go on handing it to every coroutine, never once
        // asking offsetGet() for the scoped proxy. Only the proxied aliases are dropped:
        // for the rest, offsetGet() still answers with a single per-coroutine object,
        // and re-resolving would pin whichever coroutine asked first.
        foreach (array_keys(self::FACADE_PROXIED_MAP) as $proxied) {
            Facade::clearResolvedInstance($proxied);
        }

        if ($config !== null && $config->get('async.diagnostics', false)) {
            $this->reportScopedServiceRisks();
        }
    }

    public function scopedSingleton(string $abstract, Closure $factory): void
    {
        $this->scopedBindings[$abstract] = $factory;
    }

    /**
     * Record the extender even when the service has already been resolved.
     *
     * The container skips that for a resolved service, and for a shared one it is
     * right: decorating the single instance is the whole job, there will be no second
     * resolve. A scoped service is resolved again in every coroutine, so the extender
     * has to be kept — otherwise the decoration reaches the boot-time object and
     * nobody else, silently.
     */
    public function extend($abstract, Closure $closure)
    {
        $alias = $this->getAlias($abstract);

        if (! isset($this->instances[$alias]) || ! $this->isScopedAlias($alias)) {
            parent::extend($abstract, $closure);

            return;
        }

        $this->extenders[$alias][] = $closure;
        $this->instances[$alias]   = $closure($this->instances[$alias], $this);

        $this->rebound($alias);
    }

    /**
     * Teach the container how to carry boot-time configuration onto per-coroutine
     * instances of a scoped service.
     *
     * The seeder receives the freshly built instance and the boot-time one, and is
     * expected to copy across registrations only — never per-request state, which is
     * the very thing scoping exists to keep apart.
     *
     * Without a seeder, a scoped service is whatever its factory returns: anything a
     * provider did to the object after construction (extend(), viaRequest(), setters)
     * stays behind on the boot-time instance.
     */
    public function scopedSeeder(string $abstract, Closure $seeder): void
    {
        $this->scopedSeeders[$abstract] = $seeder;
    }

    /**
     * 'request' is always resolvable: from context, instances, or fallback.
     *
     * This keeps code that checks bound('request') during bootstrap from crashing,
     * before any HTTP request exists — AuthServiceProvider::registerRequestRebindHandler()
     * among it. It also makes rebinding('request', ...) return make('request') rather
     * than null, which UrlGenerator::__construct requires when the exception handler
     * renders an error before the first request is served.
     */
    public function bound($abstract): bool
    {
        if ($this->getAlias($abstract) === 'request') {
            return true;
        }

        return parent::bound($abstract);
    }

    public function offsetGet($key): mixed
    {
        $alias = $this->getAlias($key);

        if ($this->asyncMode && isset(self::FACADE_PROXIED_MAP[$alias])) {
            return new ScopedServiceProxy(fn() => $this->tryResolveScoped($alias));
        }

        // 'request' is always safe to resolve — even during bootstrap when no
        // HTTP request exists yet. Without this, any code that touches $app['request']
        // before the first onRequest() call crashes with "Class request does not exist".
        //
        // Resolution priority:
        //   1. context  — per-coroutine request (async mode, during request handling)
        //   2. instances — set by Kernel::sendRequestThroughRouter()
        //   3. fallback — empty Request so bootstrap/error handler can proceed
        if ($alias === 'request') {
            return $this->resolveRequest();
        }

        return parent::offsetGet($key);
    }

    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        $alias = $this->getAlias($abstract);

        // 'request' must never fall through to build('request') — it's a string
        // alias, not a class name, so ReflectionClass would throw.
        if ($alias === 'request') {
            return $this->resolveRequest();
        }

        if ($this->asyncMode) {
            $instance = $this->tryResolveScoped($alias);

            if ($instance !== null) {
                return $instance;
            }
        }

        return parent::resolve($abstract, $parameters, $raiseEvents);
    }

    /**
     * Resolve the current request from context, instances, or fallback.
     */
    private function resolveRequest(): \Illuminate\Http\Request
    {
        if ($this->asyncMode) {
            $fromContext = current_context()->find(ScopedService::REQUEST);
            if ($fromContext !== null) {
                return $fromContext;
            }
        }

        return $this->instances['request']
            ?? \Illuminate\Http\Request::createFromGlobals();
    }

    /**
     * Resolve a scoped service from the current context, or return null
     * if the alias is not a scoped service.
     */
    private function tryResolveScoped(string $alias): mixed
    {
        $key = ScopedService::tryFrom($alias);

        if ($key === null && !isset($this->scopedBindings[$alias]) && !isset($this->scopedServiceCache[$alias])) {
            return null;
        }

        $ctx = current_context();
        $ctxKey = $key ?? $alias;

        $instance = $ctx->find($ctxKey);

        if ($instance !== null) {
            return $instance;
        }

        if (isset($this->scopedBindings[$alias])) {
            $instance = ($this->scopedBindings[$alias])($this);
        } else {
            $bindings = $this->getBindings();
            if (isset($bindings[$alias])) {
                $concrete = $bindings[$alias]['concrete'];
                $instance = $concrete instanceof \Closure ? $concrete($this) : $this->build($concrete);
            } else {
                // No factory registered (e.g. 'request' is stored in instances[], not bindings[]).
                // Fall through to parent::resolve which handles instances[] correctly.
                return null;
            }
        }

        // extend() registrations live outside the factory, so building from the
        // concrete alone would silently drop them for scoped services only. They run
        // before seeding, because a decorating extender replaces the object and the
        // registrations have to land on whatever the coroutine will actually use.
        foreach ($this->getExtenders($alias) as $extender) {
            $instance = $extender($instance, $this);
        }

        $this->seedScopedInstance($alias, $instance);

        // A sibling coroutine of the same scope may have got here first while this one
        // was suspended inside the factory. Its instance is already the one the context
        // hands out, so this one is dropped rather than fought over.
        $winner = $ctx->find($ctxKey);

        if ($winner !== null) {
            return $winner;
        }

        // The instance goes into the context before the callbacks run, the way the
        // container publishes to $instances before firing them: a callback is free to
        // resolve the same alias again, and it has to reach this object rather than
        // build a second one and collide on the context key.
        $ctx->set($ctxKey, $instance);

        // Adapters registered via afterResolving() (e.g. registerSessionAdapter) have to
        // fire here too: parent::resolve() is bypassed for scoped services, and it is the
        // only other place that fires them.
        $this->fireResolvingCallbacks($alias, $instance);

        return $instance;
    }

    /**
     * In async mode an object is never kept in step with the current request.
     *
     * refresh() exists for an object that outlives the request it was built for; a
     * coroutine has none. What it leaves behind is real damage: the subscription goes
     * into the shared container, so it survives its coroutine, is invoked on every
     * later request, and holds its target — a guard, and the user inside it — alive
     * forever. Upstream registers one for every guard it builds.
     *
     * Only refresh() is refused. rebinding() still works, because the framework uses
     * it for objects that genuinely are shared and do have to follow the request,
     * the URL generator among them.
     */
    public function refresh($abstract, $target, $method)
    {
        if ($this->asyncMode) {
            return $this->make($abstract);
        }

        return parent::refresh($abstract, $target, $method);
    }

    /**
     * Hand the boot-time configuration of a scoped service to the instance this
     * coroutine has just built, if the service registered a way to transfer it.
     */
    private function seedScopedInstance(string $alias, object $instance): void
    {
        $seeder    = $this->scopedSeeders[$alias] ?? null;
        $prototype = $this->scopedPrototypes[$alias] ?? null;

        if ($seeder === null || $prototype === null) {
            return;
        }

        $seeder($instance, $prototype);
    }

    /**
     * Remember what bootstrap left in the container for every scoped alias.
     *
     * Only instances that are already resolved are taken. Resolving one here would
     * happen with async mode switched on, which stores it in the root context — where
     * find() reaches it from every coroutine and the sharing we are undoing comes back.
     */
    private function captureScopedPrototypes(): void
    {
        foreach ($this->scopedAliases() as $alias) {
            if (isset($this->instances[$alias])) {
                $this->scopedPrototypes[$alias] = $this->instances[$alias];
            }
        }
    }

    /**
     * Report async mode being switched on with providers registered but not booted.
     *
     * Unconditional, unlike the diagnostics below: every boot-time registration made
     * from that point on is lost, the loss is silent, and by the time anything shows
     * it looks like the registration was never written. The HTTP kernel stands in for
     * "this is a real application": a bare container assembled by a test has no
     * providers of its own to lose.
     */
    private function reportPrematureAsyncMode(): void
    {
        if ($this->isBooted() || ! $this->bound(HttpKernel::class)) {
            return;
        }

        error_log(
            '[async] async mode was enabled before the application booted: providers configure '
            .'objects no coroutine inherits, so their boot-time registrations are lost'
        );
    }

    /**
     * Warn about configuration that bootstrap made and no coroutine will inherit.
     *
     * The failure is invisible at runtime: the service resolves, behaves like a freshly
     * constructed one, and fails much later wherever the lost registration was supposed
     * to be used.
     */
    private function reportScopedServiceRisks(): void
    {
        foreach (array_keys($this->scopedPrototypes) as $alias) {
            if (isset($this->scopedSeeders[$alias]) || isset($this->scopedBindings[$alias])) {
                continue;
            }

            error_log(
                "[async] scoped service '{$alias}' was configured during bootstrap but has no "
                .'scopedSeeder()/scopedSingleton(); coroutines will get an unconfigured instance'
            );
        }
    }

    /**
     * Whether this alias gets a fresh instance per coroutine.
     *
     * Fills the config-declared half of the answer on first use, because extend() can
     * run long before async mode is switched on and the cache is built.
     */
    private function isScopedAlias(string $alias): bool
    {
        if (ScopedService::tryFrom($alias) !== null || isset($this->scopedBindings[$alias])) {
            return true;
        }

        if ($this->scopedServiceCache === [] && $this->resolved('config')) {
            $this->scopedServiceCache = array_flip($this->make('config')->get('async.scoped_services', []));
        }

        return isset($this->scopedServiceCache[$alias]);
    }

    /**
     * @return string[] every alias this container treats as scoped
     */
    private function scopedAliases(): array
    {
        return array_values(array_unique(array_merge(
            array_column(ScopedService::cases(), 'value'),
            array_keys($this->scopedServiceCache),
            array_keys($this->scopedBindings),
        )));
    }
}
