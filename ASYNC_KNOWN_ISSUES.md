# Known issues and limitations under async serving

Findings from the review of #29 (issue #24, auth registrations). Everything here was
verified by reading the vendored framework source or by running the suite; where a
claim is reasoning rather than an observed failure, it says so.

Nothing in this file is fixed by #29. What #29 does fix is in `CHANGELOG.md`.

---

## 1. Filed as issues

| # | What | Worst outcome |
|---|---|---|
| [#30](https://github.com/YanGusik/laravel-spawn/issues/30) | Facades of scoped services pin the first coroutine's instance (`Cookie`, `Socialite`, `Request`) | Login as another user through Socialite; queued cookies silently lost |
| [#31](https://github.com/YanGusik/laravel-spawn/issues/31) | Blade render state (`@section`, `@push`, components) is shared | Two responses' HTML mixed |
| [#32](https://github.com/YanGusik/laravel-spawn/issues/32) | `UrlGenerator` is shared and overwritten per request | `url()->current()`, `redirect()->back()`, `asset()` answer for another request |
| [#33](https://github.com/YanGusik/laravel-spawn/issues/33) | Laravel's own `scoped()` singletons were never flushed | *Container half fixed in #29*; `defer()` path still unverified |
| [#34](https://github.com/YanGusik/laravel-spawn/issues/34) | Terminating callbacks accumulate and re-run | Side effect repeats N times on the Nth request; unbounded growth |
| [#35](https://github.com/YanGusik/laravel-spawn/issues/35) | `Vite` holds CSP nonce and preloaded assets on a shared singleton | Blank page under CSP; `Link: rel=preload` grows forever |

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
5. **Facades of scoped aliases outside `FACADE_PROXIED_MAP`** are pinned. `enableAsyncMode()
   clears only the proxied ones, deliberately: clearing the rest would trade a pinned
   boot-time instance for a pinned instance of the first coroutine, which is worse. See #30.

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

**3. Make "a singleton captured a per-request object" impossible to miss.** At worker
start, walk the resolved singletons and report any holding a reference to a per-request
object; add a PHPStan rule, beside the existing `MutableStaticPropertyRule`, for a
constructor parameter typed as one. This is the only measure that covers third-party
code nobody has read.

**4. Facades: stop caching rather than proxy.** `FACADE_PROXIED_MAP` cannot be completed:
a service passed to a typed parameter cannot be a proxy, which is why `cookie` and
`redirect` are excluded — `RoutingServiceProvider` hands `$app['redirect']` to
`ResponseFactory::__construct(Redirector $redirector)`. Flushing the facade cache between
requests, as Octane does, needs no list of names at all. Delegating subclasses stay only
where a real type is required.

**5. One per-request reset hook (#33, #34, #35).** Terminating callbacks, `Vite`,
`Context`, deferred callbacks — one shape, one list the servers flush between requests,
and a place for packages to add to it.

**6. Blade render state (#31).** The only item where an application sees corrupt output
rather than wrong data. The factory stays shared; the render stack moves into the
context, which is the existing adapter pattern taken to its end.

Order: 3 next — it buys the most insight per line. Then 4, 5, 6. The container contract
gaps in §3 stay until the shape in 1 is proven on a concurrency harness.

**Not on this list:** the design limitations in §2. They are pinned by tests and
documented; removing them needs a different design, not a fix.
