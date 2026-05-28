# `Core` — architecture

The `Core` module owns the shared primitives every other module
consumes: the `User` model, the `BelongsToUser` trait that scopes every
domain row to its owner, the `CurrentUser` contract that gives the
authenticated identity to any service that asks, the `Clock` abstraction
that keeps tests deterministic, the system-alerts banner the layout
renders, the `/health` endpoint, the install + doctor + backup +
restore + failed-jobs CLI commands, and the helpers that resolve every
filesystem path the bundle reads or writes.

## What this module is for

A modular codebase needs a place for the genuinely-shared primitives
that have no natural home in any one bounded module: the user identity,
the clock, the loopback guard, the storage-path resolver, the
auto-update channel. Putting them anywhere else either creates a
circular dependency (every module needs `User`) or arbitrary placement
(why does the clock live in `Forecasting`?). `Core` is the answer.

The trade-off is sharp: `Core` becomes the floor of the dependency
graph. Every other module imports from it; it imports from nothing.
That asymmetry is enforced by the
[module-boundaries architecture topic](../../architecture/module-boundaries.md)
and the matching arch invariant suite.

What the module explicitly does NOT do:

- It never imports a domain module. The `BelongsToUser` trait does not
  know about transactions or chains; it knows about
  `users.id` and the active auth guard.
- It never owns business logic. The system-alerts banner shows alerts;
  it does not decide what counts as an alert. The clock returns time;
  it does not decide how often a job runs. The CLI commands run
  procedures; they do not decide policy.
- It never reaches a remote network on its own. The single outbound
  exception is `ElectronUpdateChannel`, which fetches the publisher
  update manifest from a fixed URL configured by the bundle (see
  [ADR 0004](../../adr/0004-local-only-hosting.md)). Even that fetch
  is gated by Ed25519 signature verification before any side effect
  fires.

## Module boundary

`Public/` is the foundation surface every other module depends on:

- **Concerns/**
  - `BelongsToUser` trait — adds a `user_id` column to `$fillable`,
    registers the `UserScope` global scope, and exposes a
    `user()` belongsTo relation. The arch invariant
    [`BelongsToUser` everywhere](../../adr/0008-multi-user-belongstouser.md)
    requires every user-scoped Eloquent model to use this trait.
- **Contracts/**
  - `Clock` — returns the current time as `CarbonImmutable`. Bound to
    `SystemClock`; tests bind a `FrozenClock`.
  - `CurrentUser` — exposes `id()`, `user()`, `periodStartDay()`,
    `isAuthenticated()`. Bound to `CurrentUserService`.
  - `PublisherManifestFetcher` — the contract the auto-update path
    uses to fetch the publisher manifest; concrete impl lives in
    [`Desktop`](../desktop/architecture.md).
- **Scopes/**
  - `UserScope` — the global query scope `BelongsToUser` registers.
- **Services/**
  - `SystemClock` (impl. `Clock`).
  - `CurrentUserService` (impl. `CurrentUser`).
  - `UserDataPathService` — single source of truth for every path the
    app reads or writes; the SOLE sanctioned caller of `base_path()`
    in production code.
  - `ElectronUpdateChannel` — the manifest-fetch + signature-verify +
    binary-hash-verify pipeline behind the `update.available` /
    `update.stale` system-alerts banner kinds.
  - `SecretsColumnRegistry` — enumerates the columns the
    `noSecretsInLivewireSnapshot` arch invariant treats as secret.
  - `SystemAlertQuery` — read-side query for the banner.
- **Actions/**
  - `AcknowledgeSystemAlert` — single sanctioned write path for
    `system_alerts.acknowledged_at`.
- **Bootstrap/**
  - `EnsureAppKey` — first-launch APP_KEY regeneration with
    sentinel-driven idempotency. Bound into the NativePHP first-launch
    chain by [`Desktop`](../desktop/architecture.md).
- **Controllers/**
  - `HealthController` — the auth-free `/health` endpoint. Returns
    `{status, app_version, php_version, sqlite_version}` — a flat
    JSON object with deterministic key order, no timestamp.
- **Support/**
  - `LockStore` — wraps the Cache facade behind a constructor-
    injectable surface so queued jobs can take `Cache::lock()` without
    the rest of the codebase importing the facade.
  - `SafeTrace` — utility for redacting potentially-sensitive
    arguments out of stack traces written to the developer log.
- **Events/**
  - `UserInstalled` — dispatched by `Modules\Auth\Public\Actions\SignupAction`
    after a successful install AND by `beatrax:install` on every re-run.
    Listeners (default-category-tree seeder, community-corpus seeder,
    wizard first-step priming) MUST be idempotent.
- **Exceptions/**
  - `NotAuthenticatedException` — thrown by `CurrentUserService::resolveUser()`
    when no guard is bound. Maps to a 401 / redirect at the request
    boundary.
- **Dto/**
  - `UpdateManifestDto` — typed shape of the verified update manifest.

`Internal/` houses the implementation owners: the SQLite optimisations
provider (WAL + busy_timeout + synchronous=NORMAL), the health-check
service provider that wires the boot probes, the install / doctor /
backup / restore / failed-jobs CLI commands and their probes, the
loopback-only middleware that hides every non-loopback request behind
a 404, the no-store middleware that prevents browser caching of
financial data, the system-alerts-banner Livewire SFC, the dashboard
Livewire SFC, the help-data-locations Livewire SFC, the app-sidebar
Livewire SFC, the settings page Livewire SFC, and the health-check
listener that watches the boot probes during the install ceremony.

## Key services + events

- `BelongsToUser` — the trait every user-scoped model uses. It
  resolves the `UserScope` through `Container::getInstance()->make()`
  — the SOLE sanctioned container touch-point in the codebase (Eloquent
  boot hooks run static and cannot accept constructor DI).
- `CurrentUser::user()` — the read-everywhere identity. Returns a real
  `User` or throws `NotAuthenticatedException`; never null.
- `Clock::now()` — the read-everywhere wall clock. Tests substitute a
  `FrozenClock`; production substitutes `SystemClock`.
- `UserDataPathService` — every read of `database_path()`,
  `storage_path()`, `base_path()` outside this class is forbidden by
  the arch invariant `noRawPathHelpersOutsidePathService`. The
  `NATIVEPHP_STORAGE_PATH` env var redirects the storage root for the
  packaged build.
- `EnsureAppKey::run()` — first-launch APP_KEY mint, guarded by a
  0-byte sentinel under the user-data app directory. Subsequent
  invocations short-circuit, so the action is safe to wire into the
  chained-bootstrap path that runs on every launch.
- `ElectronUpdateChannel` — fetches the publisher manifest, verifies
  the Ed25519 signature, hashes the downloaded binary against the
  manifest's SHA-512, and dispatches a `system_alerts` row only after
  every verification succeeds. The 30-day staleness threshold raises
  an `update.stale` banner without offering an install (the user has
  to investigate manually before the install fires).
- `UserInstalled` event — the cross-module "a user just appeared"
  signal. Subscribed to by `Categorization`, `Community`, `Onboarding`.

## Data flow

The auth + global-scope chain that every domain model rides:

```
HTTP request
  → Authenticate middleware (Auth module pushes it onto the auth group)
  → CurrentUserService picks up auth()->guard()->user()
  → any Eloquent query via a BelongsToUser-using model
       → UserScope::apply
            → query->where('user_id', CurrentUser::id())
  → cross-user row never visible
```

The first-launch boot chain:

```
NativePHP boots
  → FirstLaunchBootstrap (Modules/Desktop)
      → EnsureAppKey::run                (Core)
           → if sentinel absent:
                php artisan key:generate --force
                write sentinel
      → … other first-launch actions
  → request routing begins
```

The `/health` endpoint:

```
GET /health
  → HealthController::__invoke
      → resolve app_version from NATIVEPHP_APP_VERSION env (fallback 'dev')
      → SELECT sqlite_version() via injected DatabaseManager
      → return {status: ok, app_version, php_version, sqlite_version}
```

The system-alerts banner:

```
Layout renders
  → SystemAlertsBanner Livewire SFC
      → SystemAlertQuery::activeFor($currentUser)
      → render kind-specific message + acknowledge button
      → on click → AcknowledgeSystemAlert($alertId, $user)
            → write acknowledged_at (sole sanctioned writer)
```
