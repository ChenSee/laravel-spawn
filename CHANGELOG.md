# Changelog

## [Unreleased]

### Added
- Redis connection pool (#23) — `RedisManager` shares one connection with every coroutine, so concurrent commands used to interleave on a single socket (protocol errors, `unserialize()` failures under load)
  - `AsyncPhpRedisConnector` — builds pooled clients: connection settings move to the `Redis` constructor (pool mode rejects `connect()`), the rest is applied to the template as before
  - `RedisPool::configure()` — installs the connector into `RedisManager` and purges anything resolved during bootstrap; called by all three servers
  - `config/async.php` — new `redis_pool` section: `enabled`, `min`, `max`, `mux`
  - Requires the TrueAsync build of phpredis; Redis Cluster is not pooled
- Laravel Debugbar compatibility (#14) — Debugbar now renders under async serving, with per-coroutine data isolation
  - `AsyncDebugbar` — one instance per worker; request state (collected snapshot, `responseIsModified`) kept per-coroutine via `current_context()`; persistent storage disabled (its inline I/O in `collect()` would break render atomicity under concurrency)
  - Context-backed collectors (`messages`, `time`, `exceptions`, `query`) via `DelegatesToContext` — one shared instance, per-coroutine data, so concurrent requests never mix debug data (`events`/`models` are a follow-up)
  - `AsyncApplication::runningInConsole()` returns `false` while serving, so Debugbar (and other web-only packages) detect web context instead of the CLI SAPI
  - `ResetDebugbar` — per-request reset + boot, mirroring Laravel Octane's `ResetDebugbar`
- `AsyncApplication` — extends Laravel's `Application` with per-coroutine service isolation
  - `enableAsyncMode()` — must be called before the HTTP server starts; artisan commands run as normal Laravel
  - `LARAVEL_SCOPED` — services that get a fresh instance per coroutine: `session`, `auth`, `auth.driver`, `cookie`
  - `FACADE_PROXIED` — subset of scoped services returned as `ScopedServiceProxy` via `offsetGet()` so Laravel Facades always resolve the correct coroutine-local instance
  - `scopedSingleton()` — register custom per-coroutine services programmatically
  - `scopedSeeder()` — carry boot-time registrations (`extend()`, `viaRequest()`, setters on the resolved object) onto per-coroutine instances, which a factory alone cannot reproduce
  - `diagnostics` config key — report at worker startup any scoped service that bootstrap configured and no seeder covers
  - `scoped_services` config key — register scoped services via `config/async.php`
- `ScopedServiceProxy` — lightweight proxy cached by Facades; delegates every call to `coroutine_context()` so concurrent requests never share state
- `config/async.php` — publishable config with `scoped_services` list
- `AsyncServiceProvider` — merges config, registers `serve` command, publishes config via `vendor:publish`
- `DevServer` — minimal TCP server for local development only (`async:serve`), analogous to `php artisan serve`
- `FrankenPhpServer` — production adapter for TrueAsync FrankenPHP (`async:franken`); uses `FrankenPHP\HttpServer::onRequest()`, generates Caddyfile + worker file in `storage/app/trueasync/` and starts the `frankenphp` binary as a subprocess
- `ServerInterface` — contract for all server adapters (`prepareApp()`, `start()`)
- `ManagesDatabasePool` trait — shared PDO Pool logic extracted from servers; used by both `DevServer` and `FrankenPhpServer`

- PDO Pool integration for async-safe database access
  - `ManagesDatabasePool::configureDatabasePool()` — injects `PDO::ATTR_POOL_ENABLED` and related options into all database connection configs when `async.db_pool.enabled = true`
  - `ManagesDatabasePool::warmUpDatabasePool()` — forces the `DatabaseManager` to establish its connection inside the server coroutine before the accept loop starts, so the pool is created in the correct coroutine scope and shared across all request coroutines
  - `config/async.php` — extended with `db_pool` section: `enabled`, `min`, `max`, `healthcheck_interval`
- PHPUnit test suite under `tests/` running inside `trueasync/php-true-async:latest` Docker image
  - `CoroutineContextIsolationTest` — verifies `coroutine_context()` isolation
  - `ScopedServiceIsolationTest` — verifies scoped vs singleton behavior, session/auth isolation
  - `RequestIsolationTest` — verifies `app('request')` isolation per coroutine
  - `DatabaseIsolationTest` — documents that `db` is intentionally a singleton; PDO Pool handles physical connection isolation at the C level
  - `HttpE2ETest` + `tests/e2e/run.php` — in-process HTTP end-to-end suite: boots the bench fixture in a real worker thread (`spawn_thread`) and drives it over a loopback socket with concurrent coroutine clients (routing, per-request isolation across an I/O yield, error resilience) — no external server process, teardown via the DevServer's own SIGTERM handler

### Fixed
- **With `--workers=1` none of the async adaptation ran (#22).** `TrueAsyncServer` did all of its per-worker setup — async mode, the PDO pool, every `bootCompleted()` hook — inside the server's bootloader closure, and the server only consults that closure in pool mode (`workers > 1`). A single-worker run therefore served requests from a plain Laravel app: one shared PDO for all coroutines (`SQLSTATE[HY000] 2014`), one shared Route object, no coroutine-scoped services. The setup moved to `TrueAsyncServer::initializeApp()`, which the bootloader calls in pool mode and `start()` calls in this process otherwise.
- **Route parameters crossed between concurrent requests (#24).** `RouteCollection` keeps one `Route` object per definition and `match()` binds the request's parameters onto it, so two coroutines on the same route overwrote each other: `$request->route('id')` returned another request's value or null. `AsyncRouter` now clones the matched route per coroutine and resolves `Route::class` from the context instead of binding it into the shared container.
- **Auth drivers registered during boot vanished under the async server (#24).** A scoped service is rebuilt per coroutine from its container factory, so everything a provider had done to the boot-time object was left behind — `Auth::viaRequest()` and `Auth::extend()` most visibly, giving `Auth driver [x] for guard [y] is not defined` on every request. Registrations are now carried onto per-coroutine instances (`AsyncApplication::scopedSeeder()`, `AsyncAuthManager::seedInto()`), while resolved guards stay behind, since a guard holds the user of the request that built it.
- **Facades kept serving the boot-time instance of a scoped service.** A facade resolved during bootstrap caches it in a static array of its own, so `Auth::user()` bypassed the scoped proxy entirely and read another coroutine's state; `enableAsyncMode()` now drops those caches.
- **`extend()` registrations were ignored for scoped services.** `tryResolveScoped()` built from the binding concrete and never applied the container's extenders, so `$app->extend('auth', ...)` silently did nothing under async serving.
- **Two concurrent requests could be handed the same session.** `StartSession` is registered as a singleton and takes the session manager in its constructor, so the first coroutine through the pipeline decided whose session every later request read, wrote and left with — one visitor's data served to another, and a session cookie shared between them. The middleware is resolved per request now.
- **The redirector flashed into one coroutine's session.** `redirect` is built once and takes `session.store` at construction (`RoutingServiceProvider::registerRedirector`), so `redirect()->with()`, `->withErrors()` and `->withInput()` all went to whichever session was current when the first redirect of the worker's life was made. Now scoped.
- **The session store was shared by every coroutine.** `session.store` is bound separately from `session` and was not scoped, so the first coroutine to resolve it pinned its `Store` in the container for all of them — and the stock `web` guard reads the authenticated user out of exactly that object. One user was served as another. `flash()`/`redirect()->with()` landed in someone else's session for the same reason.
- **A driver registered at boot on a scoped `Manager` was unsupported under async serving.** `Session::extend('mongo', ...)` and `Socialite::extend('gitlab', ...)` write into the manager, which each coroutine rebuilt, giving `Driver [mongo] not supported` on the first request. `ManagerRegistrations` seeds both, and serves any `Illuminate\Support\Manager` an application makes scoped.
- **Guards subscribed to the shared container, one subscription per request.** Every stock guard registers itself through `refresh('request')` (session, token, `viaRequest`, Sanctum, Passport). Under async serving that subscription outlives its coroutine: the list grew by one on every request, each entry keeping a dead guard alive, and each later request called `setRequest()` on guards belonging to other coroutines — one request could authenticate from another's headers. `refresh()` is refused in async mode, where nothing outlives the request it was built for. `rebinding()` is untouched: the framework uses it for genuinely shared objects that do have to follow the request, the URL generator among them.
- **Two coroutines resolving the same scoped service at once could crash one of them.** When a factory yields, both got past the context lookup and the second one's write threw `Context key already exists`. The loser now takes the instance the winner published.
- **A resolving callback that re-resolved its own service recursed until the stack ran out.** Scoped instances were published to the context only after the callbacks had run, so `afterResolving('auth', fn ($a, $app) => $app->make('auth'))` built a new instance for every nested call.
- Per-alias `afterResolving()` callbacks fired twice for every scoped resolve — a manual loop and `fireResolvingCallbacks()` both ran
- `TrueAsyncServer` bootloader returned early when `async.db_pool.enabled` was false, skipping every `bootCompleted()` call after it (view, permission, inertia, translator, config, events, router)
- `DevServer` request exception handler had a 1-arg `Throwable` signature but the scope invokes it with `(Scope, Coroutine, Throwable)` — every request error was swallowed by a `TypeError`; signature corrected
- `RequestParser` did not set `REMOTE_ADDR`, so `Request::getClientIp()` returned `null` — `DevServer` now passes the socket peer address (fixes Debugbar and anything else reading the client IP)

### Verified
- `cache`, `mail`, `queue` managers do not hold per-request state — no scoping needed, confirmed via parallel isolation tests

### Notes
- `flock(LOCK_EX)` was blocking the entire event loop on concurrent requests — reported to TrueAsync team, fixed in TrueAsync v0.6.2 (thanks @EdmondDantes)
- `cookie` and `auth.driver` are scoped but not proxied via `offsetGet`: `AuthManager` passes `$app['cookie']` directly to `setCookieJar(QueueingFactory $cookie)`, so returning a proxy there causes a `TypeError`
- `db` cannot be scoped: `DatabaseServiceProvider::boot()` stores the `DatabaseManager` in `Model::$resolver` (static). A scoped instance would be GC'd after its coroutine finishes, leaving the static pointing to a destroyed object → segfault. Physical connection isolation is handled by PDO Pool instead.
- `Connection::$transactions` counter is shared across coroutines (known limitation). In practice this only matters if two coroutines call `DB::beginTransaction()` simultaneously on the same connection name — PDO Pool ensures physical DB transactions are isolated, but Laravel may create a `SAVEPOINT` instead of `BEGIN` if the counter is non-zero.
- `PDO::ATTR_POOL_ENABLED` caused a segfault when `PDOStatement::execute()` was called from inside class methods — reported to TrueAsync team, fixed (thanks @EdmondDantes)
- PDO Pool must be initialized in the server coroutine scope (not lazily inside a request coroutine) — hence `warmUpDatabasePool()` runs before the accept loop
- `proc_close()` double `waitpid` bug caused `Async\AsyncException: Failed to monitor process` with multi-worker FrankenPHP — reported to TrueAsync team, fixed (thanks @EdmondDantes)
- TrueAsync dynamic fiber pool added (+10% on synthetic benchmarks)
- TrueAsync PDO Pool connection spawning fix (+20% throughput)
- Hot reload (`--watch`) for `async:serve` is not feasible: PHP cannot reload already-loaded class definitions within the same process, and restarting TrueAsync scheduler in-process causes `zend_mm_heap corrupted`. FrankenPHP hot reload pending watcher support in the Docker image.
