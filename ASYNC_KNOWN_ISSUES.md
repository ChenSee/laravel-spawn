# Known issues and limitations under async serving

Findings from the review of #29 (issue #24, auth registrations). Everything here was
verified by reading the vendored framework source or by running the suite; where a
claim is reasoning rather than an observed failure, it says so.

What #29 fixes is in `CHANGELOG.md`. What it does not fix is below.

---

## 0. Where things stand

- Work is on `fix/24-scoped-boot-registrations`, which carries this change on top of
  `master`; the branch's earlier work went in as PR #29 and the branch was rebuilt after
  that merge. 199 tests, green, end-to-end included.
- **Issues #30 to #35 are fixed** (table in §1), each with a test that fails without the
  fix.
- Of the strategy in §6: moves 4 and 6 are done, move 3 is half done and its two
  unfinished halves are the next steps below, move 5 was rejected in the shape the plan
  gave it and each of its three items fixed separately, and move 1 stays rejected.

### Next, in order

1. **`async:audit` command** (§6.3). The runtime walk reports what the moment it runs can
   see, and at worker start that is almost nothing: Laravel resolves `url`, the response
   factory and every singleton middleware lazily, during the first request. A command
   that boots as a worker does, drives every parameterless GET route and prints the union
   — with the route that provoked each finding — is what turns the walk into coverage.
   Non-zero exit when anything is found, so CI can hold a baseline.
2. **Point the PHPStan rule at code nobody has read.** `phpstan.neon` analyses `src`, so
   `SingletonCapturesPerRequestRule` currently checks only this package. The third-party
   coverage §6.3 promised needs a second config aimed at `vendor/laravel` and at the
   application.
3. The container contract gaps (§3) — only after the shape in §6.1 has a concurrency
   harness behind it.

### Working notes, so a cold start does not repeat today

- Suite: `php vendor/bin/phpunit --colors=never`, with `PHP_INI_SCAN_DIR` pointing at an
  ini directory that loads `true_async_server.so` — without it the three end-to-end tests
  fail on a missing `TrueAsync\HttpServerConfig`.
- Benchmark: `php tests/bench/bench_resolve.php 200000`
- **The local `/usr/local/bin/php` runs the whole suite.** It is PHP 8.6.0-dev ZTS DEBUG
  with dom, xml, libxml and Phar, so PHPUnit and PHPStan both work; the older note saying
  the build was `--disable-all` is wrong. Docker is not available in WSL here.
- **`true_async_server.so` under the extension directory is stale** and dies with
  `undefined symbol: _php_stream_cast` the moment it is used. Rebuild from
  `/home/edmond/true-async-server`: `make clean && phpize && ./configure
  --with-php-config=/usr/local/bin/php-config && make -j$(nproc)`, then point
  `extension_dir` at `modules/`. Installing over the stale copy needs root.
- **If `tests/StreamingE2ETest.php` fails with `Call to undefined function trueasync_response()`,
  the autoloader is stale, not the extension.** That function is defined in this package's own
  `src/helpers.php` and reaches PHP through composer's `autoload.files`. Regenerate:
  `composer dump-autoload` (CI installs from scratch, which is why CI never saw it).
- PHPStan on `src` reports 34 errors without `true_async_server` loaded and 9 with it: 4
  `class.notFound` for `FrankenPHP\*`, 4 `classConstant.notFound` for `PDO::ATTR_POOL_*`,
  and one Telescope `staticMethod.notFound`. They predate this work; CI does not run
  PHPStan. Neither custom rule reports anything on `src`.
- `Async\request_context()` is always `null` under PHPUnit — the server extension sets it.
  Anything that depends on it can only be checked end to end.
- A proxy must never be returned from `offsetGet()`: `RoutingServiceProvider` passes
  `$app['redirect']` to `ResponseFactory::__construct(Redirector $redirector)` and
  `AuthManager` passes `$app['cookie']` to `setCookieJar(QueueingFactory)`. The proxies
  live in the facade cache instead, which nothing type-hints.
- `AsyncTestCase::runParallel()` returns results in the order the coroutines were given,
  not the order they finished. A test comparing whole arrays would otherwise flake.
- `Async\suspend()` is what makes an interleaving test deterministic. Two coroutines that
  never suspend run one after the other, and a test written without it passes on shared
  state as happily as on isolated state.

---

## 1. Filed as issues, and how each was closed

| # | What | Fix | Test |
|---|---|---|---|
| [#30](https://github.com/YanGusik/laravel-spawn/issues/30) | Facades of scoped services pin the first coroutine's instance (`Cookie`, `Socialite`, `Request`) | Every per-request alias gets a `ScopedServiceProxy` written straight into the facade cache, so no list of facade names is needed and no proxy reaches a typed parameter | `CookieIsolationTest` |
| [#31](https://github.com/YanGusik/laravel-spawn/issues/31) | Blade render state (`@section`, `@push`, components) is shared | The factory stays one object and its sixteen render properties move into the request's context, so unmodified framework code writes per request | `BladeRenderE2ETest`, `BladeRenderIsolationTest`, `ViewRenderStateTest` |
| [#32](https://github.com/YanGusik/laravel-spawn/issues/32) | `UrlGenerator` is shared and overwritten per request | `url` and the response factory are per-request; `rebinding()` inside a per-request factory is dropped instead of accumulating | `UrlIsolationTest` |
| [#33](https://github.com/YanGusik/laravel-spawn/issues/33) | Laravel's own `scoped()` singletons were never flushed | Container half in #29; a seeder now carries boot-time log context into each request | `RequestLifecycleIsolationTest` |
| [#34](https://github.com/YanGusik/laravel-spawn/issues/34) | Terminating callbacks accumulate and re-run | The list belongs to the request, in its context; the container keeps only what bootstrap registered | `RequestLifecycleIsolationTest` |
| [#35](https://github.com/YanGusik/laravel-spawn/issues/35) | `Vite` holds CSP nonce and preloaded assets on a shared singleton | Per-request clone of the boot-time object, render state emptied | `RequestLifecycleIsolationTest` |

### The one pattern behind half of them

A **shared singleton that captures a scoped service in its constructor**. `StartSession`
was one — fixed in #29 after two concurrent logins were found leaving with the same
session cookie. `Redirector` was another. The same shape, unaudited:

- `Illuminate\Cookie\Middleware\EncryptCookies` and `AddQueuedCookiesToResponse` take the
  `CookieJar` in their constructors;
- `VerifyCsrfToken` takes the encrypter and the app;
- any application middleware registered as a singleton.

Middleware resolved per request is safe; middleware registered as a singleton is not.
**An audit of every singleton whose constructor resolves a scoped alias is worth more
than any single fix here.**

---

## 2. Limitations inside the design, pinned by tests

These are consequences of how scoping works, not oversights. Each has a test asserting
the current behaviour, so a future change that removes the limitation fails visibly.

1. **`use`-captured state in an adopted registration.** Re-binding fixes `$this` and
   nothing else. A creator written as `Auth::extend('x', function () use ($manager) {…})`
   keeps resolving against the manager it captured, in every coroutine.
   `tests/AuthLimitationsTest.php` asserts the broken behaviour and says so.
   Registrations made through `Auth::resolved()` — the form real Sanctum uses — are
   correctly isolated, because `afterResolving` runs after the seeder.
2. **A registration made after serving begins reaches one coroutine.** Prototypes are
   captured once, at `enableAsyncMode()`. A deferred provider loaded inside a request is
   the usual way to hit this: it is marked loaded and never registers again.
3. **`scopedSingleton()` services have no prototype**, so a seeder for one never runs —
   there is no boot-time instance by definition.
4. **A seeder only runs for an alias the container treats as scoped.** On any other
   alias it is silently never called.
5. **A facade of an alias that becomes per-request after start-up** is proxied from the
   moment the container learns of it, not from the moment the facade first resolved. An
   instance the facade cached before that — a deferred provider calling `scoped()` on an
   alias a facade had already touched — stays until something overwrites it.
6. **A facade root of a per-request alias answers `__call`, `__get`, `__set` and
   `__isset`, and nothing else.** `ScopedServiceProxy` is not callable, not stringable,
   not countable and not traversable, so `(Vite::getFacadeRoot())(…)` or `count(Foo::…)`
   through such a facade fails. Nothing in the per-request set is used that way — the
   `@vite` directive resolves through the container, not the facade — and the methods can
   be added when something needs them.
7. **`Facade::clearResolvedInstances()` while serving removes the proxies.** Nothing in
   the package calls it after start-up. The singular `clearResolvedInstance('request')`,
   which `Illuminate\Foundation\Http\Kernel` calls on every request, is put back by
   `RestorePerRequestFacades`, the first middleware in the pipeline — including when a
   coroutine got in first and left its own instance in the slot, which is why the check
   is for the proxy rather than for the slot being occupied. A test harness that clears
   the whole array has to enable async mode again, or its facades go back to pinning the
   first coroutine's instance.

---

## 3. Container contract gaps in `tryResolveScoped()`

The scoped path bypasses `Container::resolve()`, so parts of the container contract do
not apply to scoped services. None of these is silent-and-severe like the above, but
each will surprise somebody:

- contextual bindings (`when()->needs()->give()`) are ignored, and `$this->buildStack` is
  not populated, so contextual bindings *inside* a scoped factory do not fire either;
- `beforeResolving` callbacks never fire;
- `$this->resolved[$alias]` is never set, so `$app->resolved('session')` answers `false`
  after any number of resolves;
- `$app->extend()` **after** the alias was resolved and while not scoped follows the
  container's own rule and mutates only the instance.

`makeWith()` with parameters and `instance()` are handled: parameters bypass the context,
and an explicit `instance()` outranks scoping.

---

## 4. What the test harness cannot reach

- **`request_context()`** is set by the server extension's C code. Under PHPUnit it is
  always `null`, so the suite only exercises the `?? current_context()` fallback. The
  behaviour it guards — a `Scope::inherit()` inside a handler sharing one auth manager
  and one session with its parent — was verified by hand against a real `TrueAsyncServer`
  and needs an e2e runner to be verified automatically.
- **Pool mode (`workers > 1`)** cannot be stopped from the parent (upstream
  true-async/server#117), so a test would leave a process behind. The failure mode it
  would catch is static state, which the two-applications-in-one-process test covers.
- **Object lifetime** — "a guard does not outlive its request" — is unobservable:
  `runParallel()` never disposes its scopes, so a `WeakReference` stays alive on any
  branch. Accumulation is asserted through the container's own counters instead.

---

## 5. Environment

`tests/StreamingE2ETest.php` fails locally against `trueasync/php-true-async:0.8.4-php8.6-alpine`:
the image loads `true_async_server` but has no `trueasync_response()`, which
`src/Sse/Sse.php:19` calls. CI uses `:latest`, where it exists. Not a code defect, and it
fails identically on `master`.

---

## 6. Strategy

### The one cause

Laravel's container knows two lifetimes: transient, and singleton for the life of the
process. An async worker needs a third — for the life of a request. Every item above is
a place where state belonging to one request ended up in an object living for the life
of the process, or the reverse.

That is why fixing them one alias at a time does not converge. `StartSession` and
`Redirector` were both found by accident; the list of services nobody has looked at yet
is not shorter for having found those two.

### The moves, in order

**1. Do not relocate storage into `$this->instances` — rejected, with reasons.** The
obvious refactor is to stop reproducing `Container::resolve()` and instead seed
`$this->instances[$alias]` from the context, delegate, and move the result back. Two
things kill it. The container publishes the instance and *then* fires the resolving
callbacks, which may suspend — and `$this->instances` is shared by every coroutine, so
one request can read or erase another's object in that window. And the publication order
inverts: today the instance reaches the context *before* the callbacks run, which is what
stops a callback resolving its own service from recursing for ever. Relocation cannot
express that order at all.

If the reproduction is to be reduced, the shape worth trying is different: make
`isShared()` answer false for per-request aliases so the container never touches the
shared slot, delegate, and publish the *returned* value under the existing
first-one-wins rule. That still has to solve publication-before-callbacks, and it needs a
concurrency harness — two coroutines in one scope, a factory that suspends — before any
of it is worth writing.

**2. One registry of lifetime, not four — done.** The `ScopedService` enum, the config
list, `scopedBindings` and Laravel's own `scopedInstances` now answer through a single
map of alias to context key, kept current rather than snapshotted. Measured on the
benchmark below: a per-request resolve went from 212 ns to 117 ns, because the check
cost more than the work it guarded.

**3. Make "a singleton captured a per-request object" impossible to miss — half done.**
`PerRequestCaptureAudit` walks the resolved singletons and reports any holding a
per-request object; `SingletonCapturesPerRequestRule` flags the same shape in a
`singleton()` registration before the code runs. Both work, and both are pointed at the
wrong place. The walk runs at worker start, where nothing per-request has been resolved
yet — driven against the example application it found `url` and the response factory only
after a request had gone through, which is why §0 puts an `async:audit` command next. The
rule analyses `src`, so the third-party coverage it was justified by does not exist yet.

The walk sees properties and array elements. Statics, closures and anything behind a
resource are outside it by construction, so an empty result means clean as far as it
looks, not safe.

**4. Facades: a proxy in the cache, not a shorter list — done.** `FACADE_PROXIED_MAP` was
deleted. Every per-request alias gets a `ScopedServiceProxy` written into
`Facade::$resolvedInstance` at start-up and whenever the container learns of a new one, so
the completeness problem disappears: the list is the container's own map of per-request
aliases. `offsetGet()` returns real objects again, which is what makes it safe for
`redirect` and `cookie` — both are passed to typed constructor parameters, and neither
was proxyable under the old shape.

**5. One per-request reset hook (#33, #34, #35) — rejected, and why.** A hook that resets
process state between requests is correct only where requests do not overlap. Here they
do: clearing the terminating callbacks would drop the callbacks of a request still in
flight, `Vite::flush()` would empty the preloaded assets another coroutine is collecting,
and `forgetScopedInstances()` would destroy another request's log context. Octane can
reset because it serves one request at a time per worker. Each of the three was made
per-request instead: the callbacks live in the request's context, `Vite` is a clone of the
boot-time object, and Laravel's `scoped()` already answers per request, with a seeder now
carrying the boot-time log context in.

**6. Blade render state (#31) — done, and the factory stayed shared after all.** The first
attempt made `view` per-request by cloning the boot-time factory. It was wrong twice over.
`Factory::__construct` does `share('__env', $this)`, so every compiled template renders
against whatever object was constructed — the prototype — while the clone was only reached
by direct calls on `$app->make('view')`, which is all the first tests did. And a
per-request factory creates the very pattern this package exists to remove:
`Component::$factory` is a process static filled on first use, `MailManager` and
`Markdown` take the factory in their constructors, so the first request to render a
component or send a mail pins its copy for the life of the worker.

What ships is the plan's original shape, with an implementation that does not cost fifty
method overrides. The sixteen render properties are `unset()` from the object in the
constructor, so every read and write the inherited traits make falls through `&__get()`
and `__set()` into a `BladeRenderState` held in the request's context. `&__get()` returns
by reference, which is what lets unmodified code run `array_pop($this->sectionStack)`.
An upgrade adding a *method* is handled automatically; an upgrade adding a *property* is
caught by `ViewRenderStateTest`, which fails on any property of `Illuminate\View\Factory`
that is in neither the moved list nor the configuration list.

**The rule this leaves behind.** Clone-per-request is correct for a service nothing
long-lived captures — `Vite` is resolved through the container at each use, so it is a
clone. A service the ecosystem captures by reference must stay one object with its state
moved into the request. That is why `Vite` and `view` are treated differently.

Next: the two halves of 3 that are not yet pointed at anything (§0). The container
contract gaps in §3 stay until the shape in 1 is proven on a concurrency harness.

**Not on this list:** the design limitations in §2. They are pinned by tests and
documented; removing them needs a different design, not a fix.
