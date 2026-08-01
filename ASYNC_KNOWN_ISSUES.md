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

Ordered by damage per unit of work, not by difficulty. Each item is a separate PR: they
touch different subsystems and want different reviewers.

**First — the audit, not a fix.** List every singleton whose factory or constructor
resolves a scoped alias. That list decides how much of #30 and the rest is really one bug.
Two were found by accident (`StartSession`, `Redirector`); finding the third by accident
is not a plan. A PHPStan rule in the shape of the existing `MutableStaticPropertyRule`
could make this permanent.

**Second — one mechanism for facades (#30, part of #32).** The proxy cannot cover
services that are passed to typed parameters, which is why `cookie` was excluded. A
context-delegating subclass — the `AsyncConfig` pattern — serves both the type and the
facade, and would retire `FACADE_PROXIED_MAP` rather than extend it. Doing this once for
`CookieJar`, `Request` and `UrlGenerator` closes #30 and #32 together.

**Third — one mechanism for per-request reset (#34, #35, the rest of #33).** Octane
solves this with listeners that flush known objects between requests. The three servers
here need the same hook: terminating callbacks, `Vite::flush()`, anything else that
accumulates. One hook, a list, and a place for packages to add to it.

**Fourth — Blade render state (#31).** The largest, and the only one where an
application sees corrupt output rather than wrong data. `AsyncViewFactory` already
isolates shared data; the render stack needs the same treatment, and the work is
mechanical once the pattern is set.

**Fifth — the container contract (§3).** Worth doing before the package is depended on
widely, because every gap is a surprise for somebody writing tests against it.

**Not on this list:** the design limitations in §2. They are pinned by tests and
documented; removing them needs a different design, not a fix.
