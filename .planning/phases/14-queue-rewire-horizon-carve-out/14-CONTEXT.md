# Phase 14: Queue Rewire + Horizon Carve-out - Context

**Gathered:** 2026-05-20
**Status:** Ready for planning

<domain>
## Phase Boundary

Swap the shipped desktop bundle's queue + cache-lock infrastructure from
Redis to SQLite-backed `database` drivers, gate Laravel Horizon so it only
boots on the developer's Herd box, and prove chain resolution still produces
no duplicate `chain_resolution_runs` rows under concurrent dispatch on the
new driver.

Phase 14 ships:

- `config/queue.php` default flipped so `QUEUE_CONNECTION=database` is the
  shipped default (today it is `env('QUEUE_CONNECTION', 'redis')`).
- A published `config/cache.php` with an explicit `locks_store` key.
- A new `config('app.dev_mode')` key, derived from a standalone
  `DIEDERIK_DEV_MODE` env var.
- A shared trait/helper that centralizes `config('cache.locks_store')`
  resolution; every `ShouldBeUniqueUntilProcessing` job's `uniqueVia()`
  migrated off the hard-coded `Cache::driver('redis')` to use it.
- `HorizonServiceProvider::boot()` early-exits when `app.dev_mode !== true`,
  and the provider is conditionally registered (it imports a `require-dev`
  package — see D-04).
- `laravel/horizon` and `predis/predis` both moved from `require` to
  `require-dev`.
- `BoundaryArchTest::noHorizonImportsInShippedBuildCode` invariant.
- An in-process Pest test proving chain resolution's unique-lock holds
  under simulated concurrent dispatch against the `database` lock store.

Phase 14 does NOT ship:

- **The shipped-build worker daemon.** Phase 14 is config + dependency +
  test only. Actually spawning a `queue:work` daemon in the packaged build
  (NativePHP child process / launchd plist) belongs to Phase 15's desktop
  shell (see D-07 / Deferred Ideas).
- The bespoke queue inspector UI (DEVUI-05) and the embedded Horizon
  iframe (DEVUI-08) — both are Phase 16.
- NativePHP integration itself (Phase 15).

</domain>

<decisions>
## Implementation Decisions

### Dev-Mode Signal

- **D-01: A standalone `DIEDERIK_DEV_MODE` env var drives
  `config('app.dev_mode')`.** It is independent of any path/runtime signal.
  `config('app.dev_mode')` is the single key that gates every dev-only
  feature — Horizon's boot (this phase) and Phase 16's dev console.
- **D-02: `DIEDERIK_RUNTIME` is retired.** Roadmap and Phase 13 referenced
  `DIEDERIK_RUNTIME=herd` as the dev-feature gate; that role is now owned
  entirely by `DIEDERIK_DEV_MODE`. Any roadmap/REQUIREMENTS reference to
  `DIEDERIK_RUNTIME=herd` is reinterpreted as `DIEDERIK_DEV_MODE=true`.
  Note: this does NOT touch `NATIVEPHP_STORAGE_PATH`, which remains the
  authoritative path-resolution signal per Phase 13 D-01 — that env var is
  a separate, still-live axis.

### Horizon + Redis Dependency Placement

- **D-03: Both `laravel/horizon` and `predis/predis` move to
  `require-dev`.** The shipped `composer install --no-dev` produces a
  dependency tree with zero Horizon/Redis packages. (Roadmap SC4 only named
  `predis`; this decision extends it so Horizon's dashboard code never
  ships either.)
- **D-04: `app/Providers/HorizonServiceProvider.php` stays where it is and
  is registered conditionally.** Because it extends
  `HorizonApplicationServiceProvider` (a `require-dev` class absent from
  shipped builds), it is registered behind a `class_exists()` guard in
  `bootstrap/providers.php`. The new
  `BoundaryArchTest::noHorizonImportsInShippedBuildCode` invariant
  allow-lists this one file — the same carve-out pattern the existing
  `BoundaryArchTest` uses for the permitted `Cache` facade in `uniqueVia()`.

### Cache Configuration

- **D-05: Publish a project `config/cache.php`.** No such file exists today
  — the project rides Laravel framework defaults. Publishing it makes the
  cache config greppable and reviewable, with an explicit
  `locks_store => env('CACHE_LOCK_STORE', 'database')`. The shipped build
  leaves the env unset (`database`); the dev box sets `CACHE_LOCK_STORE=redis`.

### uniqueVia Migration

- **D-06: A shared trait/helper centralizes the lock-store resolution.**
  Every `ShouldBeUniqueUntilProcessing` job's `uniqueVia()` is migrated off
  the hard-coded `Cache::driver('redis')` to a single shared mechanism that
  returns `Cache::driver(config('cache.locks_store'))`. The `config()` /
  `Cache` facade use stays confined to that one new file; the
  `BoundaryArchTest` facade carve-out is updated to cover it (replacing the
  per-job exemptions). Affected jobs span Chains, Receipts, EmailScan,
  DriftAlerts, Forecasting, Recurring — see `<code_context>`.

### Dev vs Shipped Queue Connection

- **D-07: The developer's Herd box keeps `QUEUE_CONNECTION=redis` with
  Horizon-managed workers; only the shipped build uses `database`.** This
  matches roadmap SC1's "`'redis'` in dev mode" wording. The `database`
  queue + lock drivers are still exercised continuously by CI and by the
  Phase 14 concurrency test, so the shipped path does not go untested
  despite not being the dev default.

### Concurrency Test Fidelity

- **D-08: SC2 is satisfied by an in-process, deterministic race
  simulation** — not real parallel OS worker processes. The test dispatches
  overlapping chain-resolution jobs and asserts the `database` lock store
  (via `uniqueVia()`) rejects the duplicate before `handle()` runs, proving
  no duplicate `chain_resolution_runs` row is created. It imports a
  multi-month ASN CAMT.053 + ICS + PayPal fixture set as the end-to-end
  payload. Chosen for determinism and stability over true OS parallelism;
  real WAL-contention testing under a multi-user cohort is a Phase 21
  concern.

### Claude's Discretion

- Exact shape of the shared lock-store trait/helper (trait vs static helper
  vs tiny service) — planner decision, given how much the ~10 `uniqueVia()`
  bodies actually vary.
- Internal structure of the `noHorizonImportsInShippedBuildCode` arch test.
- Exact `config/cache.php` contents beyond the explicit `locks_store` key
  (publish framework default, adjust only what SC1 requires).
- How the conditional `class_exists()` guard is expressed in
  `bootstrap/providers.php`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements
- `.planning/ROADMAP.md` § "Phase 14: Queue Rewire + Horizon Carve-out" —
  goal + 4 success criteria.
- `.planning/REQUIREMENTS.md` — PKG-03 (the only requirement in scope).
  Note DEVUI-05 / DEVUI-08 there are Phase 16, not Phase 14.

### Project conventions
- `CLAUDE.md` — DI-only rule (constructor DI; no facades / global helpers;
  Eloquent models direct OK); modular-boundary rule; queue/scheduler stack
  notes (`database` driver, no Horizon in shipped build).
- `.planning/phases/12-multi-user-activation/12-CONTEXT.md` — establishes
  the `BoundaryArchTest` pattern that `noHorizonImportsInShippedBuildCode`
  extends, and the per-user lock-key partitioning via `CurrentUser`.
- `.planning/phases/13-app-paths/13-CONTEXT.md` — D-01 there: env-var
  signals; `NATIVEPHP_STORAGE_PATH` is path resolution, `DIEDERIK_RUNTIME`
  was dev-feature gating (now retired and replaced by `DIEDERIK_DEV_MODE`,
  see D-02). The `jobs`/`failed_jobs`/`job_batches` tables live at the
  `UserDataPathService`-rooted SQLite location.

### Architecture
- `ARCHITECTURE.md` § ~L446 — the chain-resolution unique-lock invariant
  (referenced by `BusChainResolutionDispatcher`'s docblock) that the SC2
  concurrency test must not regress.

No external ADRs — requirements fully captured in the decisions above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `tests/Contracts/BoundaryArchTest.php` — existing arch-test class
  (Phase 12 `noAuthFacadeOrHelper`, Phase 13
  `noStoragePathHardCodedOutsideUserDataPathService`). The new
  `noHorizonImportsInShippedBuildCode` invariant extends it; its existing
  `Cache`-facade allow-list is the carve-out pattern to reuse for D-04/D-06.
- `app/Providers/HorizonServiceProvider.php` — already boot-guards the
  `/horizon` dashboard with a Fortify auth gate; Phase 14 adds the
  `app.dev_mode` early-exit and conditional registration.

### Established Patterns
- DI-only: constructor injection everywhere; no facade/global-helper calls
  in module code. The `uniqueVia()` `Cache` facade use is the single
  pre-existing carve-out — Laravel invokes `uniqueVia()` at queue-push time
  before constructor DI completes, so a `Repository` cannot be injected.
  The D-06 shared helper inherits this exact constraint.
- `config/queue.php` today: `'default' => env('QUEUE_CONNECTION', 'redis')`
  — the literal line that flips to `'database'`.
- `config/session.php` driver is already `database` — no change needed.

### Integration Points
- `ShouldBeUniqueUntilProcessing` jobs whose `uniqueVia()` must migrate
  (hard-code `Cache::driver('redis')` or equivalent today):
  `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php`,
  `Modules/Chains/Internal/Services/BusChainResolutionDispatcher.php`,
  `Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php`,
  `Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php`,
  `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php`,
  `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php`,
  `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php`,
  `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php`,
  `Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php`,
  `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php`.
  (Researcher: confirm the exact `uniqueVia()` bodies — some live in
  service-provider bindings / dispatchers rather than the job class.)
- `composer.json` — `laravel/horizon ^5.46` and `predis/predis ^3.4` are
  in `require`; both move to `require-dev`.
- `bootstrap/providers.php` — where `HorizonServiceProvider` is registered;
  becomes a `class_exists()`-guarded conditional registration.
- `config/cache.php` — does not exist yet; must be published.

</code_context>

<specifics>
## Specific Ideas

No specific UI/reference requirements — this is a backend infrastructure
phase. Guiding constraint carried from Phase 13: diederik has no real
deployment and no v1.0 user data, so backwards-breaking config/env changes
(retiring `DIEDERIK_RUNTIME`, flipping queue defaults) are free.

</specifics>

<deferred>
## Deferred Ideas

- **Shipped-build worker daemon.** Spawning a `queue:work` process in the
  packaged desktop bundle (NativePHP child process supervision or a
  launchd plist) is Phase 15's desktop-shell scope, not Phase 14 (D-07).
- **Real OS-level concurrency / WAL-contention testing.** True parallel
  worker processes hammering the shared SQLite file under WAL belongs to
  the Phase 21 beta cohort's multi-user concurrency validation, not the
  Phase 14 unit-of-correctness test (D-08).
- **`laravel/pulse` (TELE-03).** Requires Redis cache reconfig and is
  redundant with the Phase 16 bespoke queue inspector — v2.1 candidate.

</deferred>

---

*Phase: 14-Queue Rewire + Horizon Carve-out*
*Context gathered: 2026-05-20*
