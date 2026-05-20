# Phase 14: Queue Rewire + Horizon Carve-out - Research

**Researched:** 2026-05-20
**Domain:** Laravel 13 queue/cache infrastructure, dependency placement, Pest arch + concurrency testing
**Confidence:** HIGH

## Summary

Phase 14 is a config + dependency + test phase. It flips the shipped desktop bundle off Redis: `QUEUE_CONNECTION` defaults to `database`, the `ShouldBeUnique*` lock store moves off a hard-coded `Cache::driver('redis')` onto a project-configurable store, Horizon and Predis become `require-dev`, and Horizon's dashboard is gated so it never registers in a shipped build. All of this is straightforward, well-supported Laravel 13 surface — the verification work (the SC2 deterministic concurrency test and the new arch invariant) is where the planning attention belongs.

The single most important factual correction this research surfaces: **`locks_store` is NOT a Laravel framework config key.** The framework's `config/cache.php` has no top-level `locks_store`. Per-store lock configuration lives inside each store as `lock_connection` / `lock_table` (for `database`) — those control *where the database store's own locks live*, not *which store to use for locks*. CONTEXT.md's D-05/D-06 `config('cache.locks_store')` is a **project-defined custom key** the phase adds to `config/cache.php`. The D-06 helper reads that custom key and passes the value to `Cache::store()` (`Cache::driver()` is an alias of `Cache::store()` — both resolve a *named store*, not a *driver type*). This is a correct and clean design — it just must be documented as a project convention, not mistaken for a framework feature, or the planner/implementer will hunt for framework docs that do not exist.

**Primary recommendation:** Add a custom `'locks_store' => env('CACHE_LOCK_STORE', 'database')` key to a published `config/cache.php`; centralize `Cache::store(config('cache.locks_store'))` in one new shared helper; migrate all 10 jobs' identical `uniqueVia()` bodies to call it; add a `jobs` + `job_batches` + `cache`/`cache_locks` migration (only `failed_jobs` exists today); gate Horizon with an `app.dev_mode` early-exit + a `class_exists()`-guarded provider registration; build the SC2 test as an in-process race simulation against the `database` store using the existing `Modules/Chains/tests/fixtures/scenario-1/` fixture set.

## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** A standalone `DIEDERIK_DEV_MODE` env var drives `config('app.dev_mode')`. Independent of any path/runtime signal. `config('app.dev_mode')` is the single key gating every dev-only feature (Horizon boot now, Phase 16 dev console later).
- **D-02:** `DIEDERIK_RUNTIME` is retired. Its dev-feature-gating role transfers entirely to `DIEDERIK_DEV_MODE`. Any roadmap/REQUIREMENTS reference to `DIEDERIK_RUNTIME=herd` is reinterpreted as `DIEDERIK_DEV_MODE=true`. `NATIVEPHP_STORAGE_PATH` is untouched — separate, still-live path-resolution axis (Phase 13 D-01).
- **D-03:** Both `laravel/horizon` and `predis/predis` move from `require` to `require-dev`. Shipped `composer install --no-dev` produces a tree with zero Horizon/Redis packages.
- **D-04:** `app/Providers/HorizonServiceProvider.php` stays in place, registered behind a `class_exists()` guard in `bootstrap/providers.php`. The new `BoundaryArchTest::noHorizonImportsInShippedBuildCode` invariant allow-lists this one file.
- **D-05:** Publish a project `config/cache.php` with explicit `locks_store => env('CACHE_LOCK_STORE', 'database')`. Shipped build leaves env unset (`database`); dev box sets `CACHE_LOCK_STORE=redis`.
- **D-06:** A shared trait/helper centralizes lock-store resolution. Every `ShouldBeUnique*` job's `uniqueVia()` migrates off hard-coded `Cache::driver('redis')` to a single shared mechanism returning `Cache::driver(config('cache.locks_store'))`. `config()`/`Cache` facade use stays confined to that one new file; the `BoundaryArchTest` facade carve-out is updated to cover it (replacing per-job exemptions).
- **D-07:** The developer's Herd box keeps `QUEUE_CONNECTION=redis` + Horizon-managed workers; only the shipped build uses `database`. The `database` queue + lock drivers are still exercised continuously by CI and by the Phase 14 concurrency test.
- **D-08:** SC2 is satisfied by an in-process deterministic race simulation — NOT real parallel OS worker processes. The test dispatches overlapping chain-resolution jobs and asserts the `database` lock store (via `uniqueVia()`) rejects the duplicate before `handle()` runs. Imports a multi-month ASN CAMT.053 + ICS + PayPal fixture set.

### Claude's Discretion

- Exact shape of the shared lock-store trait/helper (trait vs static helper vs tiny service) — given how much the ~10 `uniqueVia()` bodies vary (they do NOT vary — see Finding 1).
- Internal structure of the `noHorizonImportsInShippedBuildCode` arch test.
- Exact `config/cache.php` contents beyond the explicit `locks_store` key (publish framework default, adjust only what SC1 requires).
- How the conditional `class_exists()` guard is expressed in `bootstrap/providers.php`.

### Deferred Ideas (OUT OF SCOPE)

- **Shipped-build worker daemon.** Spawning a `queue:work` process in the packaged desktop bundle (NativePHP child-process supervision / launchd plist) is Phase 15.
- **Real OS-level concurrency / WAL-contention testing.** True parallel worker processes hammering shared SQLite under WAL is Phase 21's beta-cohort concern.
- **`laravel/pulse` (TELE-03).** Requires Redis cache reconfig; v2.1 candidate.

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PKG-03 | Queue rewire — `database` queue driver + `cache.locks_store=database` + `ShouldBeUniqueUntilProcessing` jobs' `uniqueVia()` lock store migrated; Horizon gated on dev-mode only; chain-resolution end-to-end Pest test against `database` driver under concurrent load | Standard Stack (queue/cache config), Architecture Patterns (shared helper, provider gating), Validation Architecture (SC2 race test), Common Pitfalls (missing `jobs`/`cache` tables, `Cache::driver` vs `store`, queue testing env). Note: PKG-03's "Horizon gated on `DIEDERIK_RUNTIME=herd`" wording is superseded by D-01/D-02 — gate on `config('app.dev_mode')` derived from `DIEDERIK_DEV_MODE`. |

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Queue connection default flip | Config (`config/queue.php`) | — | Single `'default'` line; framework reads `QUEUE_CONNECTION` env at boot |
| Cache lock-store selection | Config (`config/cache.php`) + a new shared helper | Jobs (`uniqueVia()`) | Custom `locks_store` key resolved once by the helper; jobs consume the helper |
| Horizon dashboard gating | App provider (`HorizonServiceProvider`) + `bootstrap/providers.php` | Config (`app.dev_mode`) | Provider `boot()` is where `/horizon` routes register; `class_exists()` guard prevents fatal in `--no-dev` builds |
| Dependency placement | `composer.json` `require` → `require-dev` | `composer.lock` | Build-time concern; verified by inspecting the `--no-dev` tree |
| Concurrency correctness | Pest test (`Modules/Chains/tests/`) | `database` cache store + `chain_resolution_runs` table | In-process race simulation; correctness asserted via lock + audit-row count |
| Boundary enforcement | Pest arch test (`tests/Contracts/BoundaryArchTest.php`) | — | New invariant + updated facade carve-out |

## Standard Stack

This phase adds **no new packages**. It moves two existing packages and reconfigures framework-provided drivers. Everything below is already in `composer.json` / the framework.

### Core (already present — verified against composer.lock)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | 13.8.0 [VERIFIED: composer.lock] | `database` queue driver, `database` cache store + `DatabaseLock`, `Cache` manager | Framework-native; `database` queue + lock is the canonical Redis-free path for single-machine apps |
| `laravel/horizon` | ^5.46 [VERIFIED: composer.json] → moves to `require-dev` | Redis queue dashboard (dev only) | Dev-box monitoring; never ships |
| `predis/predis` | ^3.4 [VERIFIED: composer.json] → moves to `require-dev` | Pure-PHP Redis client | Dev-box queue/cache transport; never ships |
| `pestphp/pest` | v4.7.0 [VERIFIED: composer.lock] | Test runner | Project standard |
| `pestphp/pest-plugin-arch` | ^4.0 [VERIFIED: composer.json] | `arch()` invariants | Powers `BoundaryArchTest` |

### Supporting (framework-provided, no install)

| Component | Purpose | When to Use |
|-----------|---------|-------------|
| `Illuminate\Cache\DatabaseStore` + `DatabaseLock` | SQLite-backed atomic locks for `ShouldBeUnique*` | Shipped build's lock store |
| `queue:table` / `queue:batches-table` / `make:cache-table` artisan commands | Generate the `jobs` / `job_batches` / `cache`+`cache_locks` migrations | Run once to scaffold the missing migrations (see Pitfall 1) |
| `Illuminate\Support\Facades\Queue::fake()` | Assert dispatch without running jobs | Existing `ResolveChainLinksJobTest` pattern |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Custom `locks_store` config key + shared helper | Per-job `env()` reads | Rejected by D-06 — scatters config reads, defeats the single-carve-out goal |
| In-process race simulation (D-08) | Real parallel `queue:work` processes | D-08 locks the in-process choice for determinism; real-OS parallelism is Phase 21 |
| `pest-plugin-arch` `arch()` rule for the Horizon invariant | Hand-rolled recursive-grep `it()` test | The existing `BoundaryArchTest` uses BOTH styles; a grep-based `it()` is the better fit here (see Finding 6) |

**Installation:** No `composer require`. Two moves in `composer.json` (`require` → `require-dev`), then `composer update --lock` to refresh `composer.lock`.

**Version verification:**
```bash
composer show laravel/framework   # confirmed 13.8.0
composer show laravel/horizon     # confirm ^5.46 resolves
composer show predis/predis       # confirm ^3.4 resolves
```

## Package Legitimacy Audit

> No external packages are installed in this phase. `laravel/horizon` and `predis/predis` are already in the dependency tree and merely change section. No registry lookup or slopcheck required — this is a dependency *relocation*, not an *install*.

| Package | Registry | Disposition |
|---------|----------|-------------|
| `laravel/horizon` | Packagist (already in lockfile) | Move `require` → `require-dev` — no install |
| `predis/predis` | Packagist (already in lockfile) | Move `require` → `require-dev` — no install |

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
                       config/cache.php  (PUBLISHED this phase)
                       ├── 'default'      => env('CACHE_STORE','database')
                       └── 'locks_store'  => env('CACHE_LOCK_STORE','database')  ← custom key
                                  │
                                  ▼
   Job dispatched ──► PendingDispatch::shouldDispatch()
   (ConfirmImport,    │   Laravel calls $job->uniqueVia() BEFORE the queue push
    EmailScan, etc.)  ▼
                  uniqueVia()  ──calls──►  [NEW] shared lock-store helper
                                              │  Cache::store(config('cache.locks_store'))
                                              ▼
                                  ┌───────────────────────────┐
                                  │  shipped build: 'database' │ → DatabaseLock on SQLite cache_locks
                                  │  dev box (env set): 'redis'│ → Redis lock
                                  └───────────────────────────┘
                                              │ lock acquired?
                                  ┌───────────┴────────────┐
                                yes (first)            no (duplicate)
                                  │                        │
                          push to queue            silently dropped
                          (QUEUE_CONNECTION             (NO second
                           = database → jobs table)      chain_resolution_runs row)
                                  │
                                  ▼
                          queue:work (Phase 15) → handle() → writes chain_resolution_runs


   bootstrap/providers.php
   └── class_exists(HorizonApplicationServiceProvider) ? register HorizonServiceProvider : skip
                                  │
                                  ▼
              HorizonServiceProvider::boot()
              └── if config('app.dev_mode') !== true  → return early (no /horizon route)
                  else → parent::boot()  (registers /horizon dashboard routes)
```

### Recommended Project Structure

```
config/
├── app.php            # + 'dev_mode' => (bool) env('DIEDERIK_DEV_MODE', false)
├── queue.php          # 'default' => env('QUEUE_CONNECTION', 'database')   ← flip
└── cache.php          # NEW — published framework default + custom 'locks_store' key

database/migrations/
├── 2026_05_16_174022_create_failed_jobs_table.php   # EXISTS
├── XXXX_create_jobs_table.php                       # NEW (database queue driver needs it)
├── XXXX_create_job_batches_table.php                # NEW (Bus::batch / job_batches)
└── XXXX_create_cache_table.php                      # NEW (cache + cache_locks tables)

app/Providers/
└── HorizonServiceProvider.php   # + app.dev_mode early-exit in boot()

bootstrap/providers.php          # HorizonServiceProvider behind class_exists() guard

Modules/Core/Public/   (recommended home for the shared helper — see Pattern 2)
└── Concerns/ or Support/  ResolvesLockStore (trait) OR LockStore (static helper)

tests/Contracts/BoundaryArchTest.php   # + noHorizonImportsInShippedBuildCode; carve-out updated
Modules/Chains/tests/Feature/          # + the SC2 concurrency test
```

### Pattern 1: Custom `locks_store` config key

`locks_store` is **not a framework key** (see Pitfall 4). It is a project convention introduced here. `config/cache.php` gets it as a sibling of `default`:

```php
// config/cache.php  — published framework default, plus:
'default' => env('CACHE_STORE', 'database'),

// Project-defined key (NOT a framework key). The shared lock-store helper
// (D-06) reads this and passes it to Cache::store(). Lets the shipped build
// run database-backed locks while the dev box overrides to redis.
'locks_store' => env('CACHE_LOCK_STORE', 'database'),
```

Both `'database'` and `'redis'` must exist as entries in `config('cache.stores')` (the published framework default already defines both).

### Pattern 2: The shared lock-store resolver (D-06)

**What:** One file that owns the only sanctioned `config()` + `Cache` facade use in module code.
**Constraint:** It MUST work when called from `uniqueVia()` — Laravel invokes `uniqueVia()` at queue-push time, inside `PendingDispatch::shouldDispatch()`, *before* the job's constructor DI completes. A constructor-injected `Repository` is therefore impossible. This is the exact pre-existing carve-out reason; the helper inherits it. [CITED: BusChainResolutionDispatcher.php docblock, lines 19-25]

**Recommended shape — a static helper or a trait, NOT a service:**

```php
// e.g. Modules/Core/Public/Support/LockStore.php  (Claude's discretion on location/name)
final class LockStore
{
    /**
     * The queue-uniqueness lock store. Resolved from config('cache.locks_store')
     * — 'database' in shipped builds, 'redis' on the dev box. This is the single
     * sanctioned config()/Cache facade use in module code: Laravel calls
     * ShouldBeUnique*::uniqueVia() at queue-push time before constructor DI
     * completes, so an injected Repository is not an option.
     */
    public static function forUniqueJobs(): \Illuminate\Contracts\Cache\Repository
    {
        return \Illuminate\Support\Facades\Cache::store(config('cache.locks_store'));
    }
}
```

All 10 jobs' `uniqueVia()` collapse to one identical line:

```php
public function uniqueVia(): Repository
{
    return LockStore::forUniqueJobs();
}
```

**Trait vs static helper:** The 10 `uniqueVia()` bodies are *byte-identical* today (all `return Cache::driver('redis');` — see Finding 1). A trait that supplies the whole `uniqueVia()` method is viable and removes the method from each job entirely. A static helper keeps a one-line `uniqueVia()` in each job (slightly more explicit, easier to grep). Either satisfies D-06. Recommendation: **static helper** — it keeps `uniqueVia()` visible on each job class (good for the concurrency contract being self-documenting) while still confining the facade to one file. If the planner prefers zero duplication, a trait providing `uniqueVia()` is equally correct. Avoid a "tiny service" — it cannot be DI-injected at `uniqueVia()` time, so it would still be accessed statically/via container, gaining nothing over a static helper.

`Cache::driver()` and `Cache::store()` are aliases — both resolve a *named store* from `config('cache.stores')`. Use `Cache::store()` for clarity (it is the documented public name; `driver()` is the legacy alias). [VERIFIED: vendor CacheManager.php — `driver()` at L78 delegates to `store()` at L65]

### Pattern 3: Horizon provider gating (D-04)

Two independent guards, both required:

1. **`bootstrap/providers.php` — `class_exists()` guard.** In a shipped `composer install --no-dev` tree, `Laravel\Horizon\HorizonApplicationServiceProvider` does not exist. `app/Providers/HorizonServiceProvider.php` `extends` it — so the class file itself fatals at autoload time if registered unconditionally. The fix: conditionally include the entry.

```php
// bootstrap/providers.php  (Claude's discretion on exact expression)
return array_values(array_filter([
    class_exists(\Laravel\Horizon\HorizonApplicationServiceProvider::class)
        ? \App\Providers\HorizonServiceProvider::class
        : null,
    \Modules\Core\Providers\CoreServiceProvider::class,
    // ... remaining providers unchanged ...
]));
```

2. **`HorizonServiceProvider::boot()` — `app.dev_mode` early-exit.** Even on the dev box where the class exists, `/horizon` should only register when dev-mode is on. `parent::boot()` (`HorizonApplicationServiceProvider::boot()`) is what calls `$this->registerRoutes()` / `$this->registerResources()` and registers the dashboard. Early-exit *before* `parent::boot()`:

```php
public function boot(): void
{
    if (config('app.dev_mode') !== true) {
        return; // no /horizon route, no Horizon assets — shipped-build safe
    }

    parent::boot();
    Horizon::auth(static fn (Request $request): bool => $request->user() !== null);
}
```

[CITED: app/Providers/HorizonServiceProvider.php — current `boot()` calls `parent::boot()` then `Horizon::auth(...)`]

### Pattern 4: `config('app.dev_mode')` wiring (D-01)

Add one line to `config/app.php`:

```php
// config/app.php
'dev_mode' => (bool) env('DIEDERIK_DEV_MODE', false),
```

Cast to `bool` at the config layer so every consumer can use a strict `=== true` / `!== true` check (the Horizon gate uses `!== true`). `env()` returns the string `"true"`/`"false"` or `null` — never call `env('DIEDERIK_DEV_MODE')` directly outside `config/`. **`config:cache` implication:** once `php artisan config:cache` runs, `env()` calls *outside config files* return `null`. Confining the read to `config/app.php` is therefore mandatory, not stylistic — the shipped build will run cached config. [CITED: Laravel 13 docs — configuration caching]

### Anti-Patterns to Avoid

- **Reading `env('DIEDERIK_DEV_MODE')` anywhere but `config/app.php`.** Breaks under `config:cache`. Always read `config('app.dev_mode')`.
- **Assuming `locks_store` is a framework key.** It is not (Pitfall 4). The helper must pass its value to `Cache::store()`; the framework will not auto-route locks based on it.
- **Registering `HorizonServiceProvider` unconditionally.** Fatals a `--no-dev` build at autoload (Pitfall 2).
- **Dispatching the SC2 test job inside a DB transaction.** The existing `ResolveChainLinksJobTest` already asserts dispatch happens *after* the transaction closure (D-103 / Pitfall 3). The new test must preserve that.
- **Leaving the SC2 test on `QUEUE_CONNECTION=sync` / `CACHE_STORE=array`.** phpunit.xml sets exactly those (Pitfall 5). The test must explicitly exercise the `database` store or it proves nothing about the shipped path.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| `jobs` / `job_batches` / `cache_locks` table schemas | Hand-written migrations | `php artisan queue:table`, `queue:batches-table`, `make:cache-table` | Framework ships the exact stubs (verified in `vendor/.../Console/stubs/`); hand-rolling risks column-name drift from what the driver expects |
| SQLite atomic lock for uniqueness | Custom `SELECT ... FOR UPDATE` lock table | `Illuminate\Cache\DatabaseLock` (the `database` cache store) | The framework's `DatabaseLock` already handles owner tokens, expiry, atomic acquire — `ShouldBeUnique*` consumes it transparently |
| Concurrency simulation harness | Spawning OS processes / threads | In-process: call `uniqueVia()`-backed lock acquire twice (D-08) | D-08 mandates the deterministic in-process pattern; OS parallelism is flaky and is Phase 21 scope |
| Conditional provider registration | A custom "feature flag" provider loader | `class_exists()` in `bootstrap/providers.php` | Laravel 11+ `bootstrap/providers.php` is a plain PHP array — a `class_exists()` ternary is the idiomatic conditional |

**Key insight:** Everything this phase needs is framework-native. The only *new code* is: one config key, one published config file, three generated migrations, one ~10-line helper, one provider early-exit, one `composer.json` edit, one arch invariant, one concurrency test. No custom infrastructure.

## Runtime State Inventory

> This phase changes config defaults and dependency placement. It is migration-adjacent (queue/cache tables). The five categories:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `jobs` / `job_batches` / `cache` / `cache_locks` tables do **not exist** in `database/migrations/` — only `failed_jobs` (2026_05_16_174022) does. The `database` queue driver and `database` cache store will fatal at runtime without them. | Generate 3 new migrations (`queue:table`, `queue:batches-table`, `make:cache-table`). These create *new* tables — no data migration, no existing rows to move. |
| Live service config | The developer's local `.env` already has `QUEUE_CONNECTION=database` + `CACHE_STORE=database`. `.env.example` has `QUEUE_CONNECTION=redis`. No shipped deployment exists (Phase 13 carry-over: "no real deployment, no v1.0 user data"). | Update `.env.example` to reflect new defaults; add `DIEDERIK_DEV_MODE` + `CACHE_LOCK_STORE` example entries. Retire `DIEDERIK_RUNTIME` from `.env.example` if present. |
| OS-registered state | None. No launchd plist / queue daemon ships in Phase 14 (deferred to Phase 15). | None — verified: Phase 14 scope explicitly excludes the worker daemon. |
| Secrets/env vars | `DIEDERIK_RUNTIME` retired (D-02) — code rename only; `DIEDERIK_DEV_MODE` is new; `CACHE_LOCK_STORE` is new. `NATIVEPHP_STORAGE_PATH` untouched. `REDIS_*` keys stay in `.env.example` (dev box still uses Redis). | Grep the codebase for any `DIEDERIK_RUNTIME` reference and replace with `app.dev_mode`. Roadmap/REQUIREMENTS text references are doc-only. |
| Build artifacts | `composer.lock` will change when Horizon/Predis move to `require-dev`. A shipped `--no-dev` install produces a different (smaller) tree. | Run `composer update --lock`; verify `composer install --no-dev` tree has zero `laravel/horizon` + `predis/predis` (see Code Examples). |

**The canonical question — after every file is updated, what runtime systems still have the old string cached/stored/registered?** Answer: only `php artisan config:cache` output, if the dev box has run it. `php artisan config:clear` covers it. No DB-stored, OS-registered, or external-service state embeds `DIEDERIK_RUNTIME` or the old queue driver name.

## Common Pitfalls

### Pitfall 1: The `jobs`, `job_batches`, and `cache_locks` tables do not exist yet
**What goes wrong:** Flip `QUEUE_CONNECTION=database` and the first dispatch throws "no such table: jobs". The `database` cache store's `DatabaseLock` throws "no such table: cache_locks". Today the project only has `failed_jobs` (it has ridden Redis for the queue and `array`/Redis for cache, so it never needed them).
**Why it happens:** Laravel 11+ ships these as opt-in migrations, generated on demand — they are not in `database/migrations/` until you run the generator commands.
**How to avoid:** First task of the phase: `php artisan queue:table`, `php artisan queue:batches-table`, `php artisan make:cache-table`. The `make:cache-table` migration creates **both** `cache` and `cache_locks` in one file (verified in the framework stub). Confirm the migrations land under the Phase-13 `UserDataPath`-rooted SQLite location (they will — they are plain framework migrations against the default connection).
**Warning signs:** `QueueException` / `QueryException` "no such table" on first `database`-driver dispatch or first `Cache::lock()` call.

### Pitfall 2: Unconditional `HorizonServiceProvider` fatals the shipped build
**What goes wrong:** `composer install --no-dev` drops `laravel/horizon`. `app/Providers/HorizonServiceProvider.php` `extends Laravel\Horizon\HorizonApplicationServiceProvider` — a class that no longer exists. Laravel autoloads every provider in `bootstrap/providers.php` at boot → fatal `Class "Laravel\Horizon\HorizonApplicationServiceProvider" not found`.
**Why it happens:** `bootstrap/providers.php` currently lists `HorizonServiceProvider::class` unconditionally (line 21).
**How to avoid:** `class_exists()` guard around the array entry (Pattern 3). The `app.dev_mode` `boot()` early-exit alone is insufficient — the class fatals at *autoload*, before `boot()` ever runs.
**Warning signs:** Fatal error on `php artisan` / first request in any environment without Horizon installed.

### Pitfall 3: Dispatching inside a DB transaction (carried from Phase 5 / D-103)
**What goes wrong:** With the `database` queue driver the `jobs` row is written in the *same SQLite connection* as the import transaction. Dispatch inside the transaction closure and a worker could read the row before commit (or, with `after_commit=false`, see stale state).
**Why it happens:** `config/queue.php`'s `database` connection has `'after_commit' => false`.
**How to avoid:** The existing `ResolveChainLinksJobTest` already enforces "dispatch after the transaction closure" via static line-number assertions. Preserve this; the SC2 test must dispatch post-commit. Consider setting `'after_commit' => true` on the `database` connection — but that is a behavior change; flag it for the planner rather than assuming.
**Warning signs:** Intermittent "job ran but saw no data" failures.

### Pitfall 4: `locks_store` is not a Laravel framework config key
**What goes wrong:** Implementer assumes the framework reads `config('cache.locks_store')` to auto-route locks. It does not. The framework's `database` cache store has `lock_connection` / `lock_table` (where *that store's* locks are stored) — there is no top-level "which store handles locks" key.
**Why it happens:** Plausible-sounding key name; no framework docs to contradict it because the key does not exist.
**How to avoid:** Treat `locks_store` as a **project convention**. The D-06 helper explicitly reads it and passes the value to `Cache::store()`. Document this in the `config/cache.php` comment so future readers do not search for non-existent framework docs.
**Warning signs:** Implementer searching Laravel docs for "cache locks_store" and finding nothing.

### Pitfall 5: The test suite runs `sync` queue + `array` cache — the SC2 test proves nothing by default
**What goes wrong:** `phpunit.xml` sets `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `DB_CONNECTION=sqlite_testing`. A test that "dispatches a job" under these defaults runs it synchronously against an in-memory array lock — it never exercises the SQLite `database` lock the shipped build uses.
**Why it happens:** Fast-test defaults; correct for 99% of the suite, wrong for the one test whose entire purpose is the `database` store.
**How to avoid:** The SC2 test must explicitly override the lock store to `database` (e.g. `config(['cache.locks_store' => 'database'])` in the test, or a dedicated test that points at a SQLite-backed store) and ensure the `cache_locks` + `jobs` tables are migrated into the test DB. The arch/contract test for `uniqueVia()` should also assert resolution against `database`, not just "returns a Repository" (the current `ResolveChainLinksJobTest` only asserts `toBeInstanceOf(CacheRepository::class)`).
**Warning signs:** SC2 test passes even when the lock logic is broken.

### Pitfall 6: `Queue::fake()` short-circuits `uniqueVia()`
**What goes wrong:** `Queue::fake()` (used in the existing `ResolveChainLinksJobTest` "dispatching twice enqueues once" test) intercepts at the `Queue` layer. The unique-lock check in `PendingDispatch::shouldDispatch()` still runs and still calls `uniqueVia()` — but a faked queue can mask whether the *real* `database` lock store is doing the rejecting.
**Why it happens:** `Queue::fake()` is the right tool for "was it dispatched"; it is the wrong tool for "did the SQLite lock atomically reject the second push".
**How to avoid:** The SC2 test should NOT rely solely on `Queue::fake()`. Either (a) use the real `database` queue connection and assert the `jobs` table has one row, or (b) directly drive the lock: acquire via the `uniqueVia()` store, attempt a second acquire, assert it fails — then assert exactly one `chain_resolution_runs` row after running `handle()`. D-08's "assert the lock store rejects the duplicate before `handle()` runs" points at the direct-lock approach.
**Warning signs:** Test still green when the lock store is swapped to a no-op.

## Code Examples

### Generating the missing queue/cache migrations
```bash
# Source: Laravel 13 framework — verified stubs in
# vendor/laravel/framework/src/Illuminate/{Queue,Cache}/Console/stubs/
php artisan queue:table          # creates jobs table migration
php artisan queue:batches-table  # creates job_batches table migration
php artisan make:cache-table     # creates cache + cache_locks tables (one migration)
php artisan migrate
```

### `config/queue.php` — the one-line flip (SC1)
```php
// BEFORE (current — config/queue.php line 20):
'default' => env('QUEUE_CONNECTION', 'redis'),
// AFTER:
'default' => env('QUEUE_CONNECTION', 'database'),
```
The dev box's `.env` keeps `QUEUE_CONNECTION=redis` (D-07); the shipped build leaves it unset → `database`.

### Verifying the shipped `--no-dev` tree has zero Horizon/Redis (SC4 verification)
```bash
# Source: composer docs — --no-dev install
composer install --no-dev --dry-run 2>&1 | grep -iE 'horizon|predis' \
  && echo 'FAIL: Horizon/Redis present in --no-dev tree' \
  || echo 'PASS: no Horizon/Redis in shipped dependency tree'
# Or, against an actual install:
composer install --no-dev && composer show 2>/dev/null | grep -iE 'horizon|predis'
```

### The `noHorizonImportsInShippedBuildCode` arch invariant (grep-style, matching existing BoundaryArchTest patterns)
```php
// Source: pattern mirrors existing tests/Contracts/BoundaryArchTest.php
// recursive-grep it() tests (e.g. noFacadeCallsFromCoreConsoleCommands)
it('does not allow Horizon imports outside the allow-listed provider (noHorizonImportsInShippedBuildCode)', function (): void {
    // Shipped-build code must never reference Laravel\Horizon\* — that
    // namespace is require-dev only and absent from a --no-dev install.
    // The single sanctioned exception is app/Providers/HorizonServiceProvider.php,
    // which is itself class_exists()-guarded in bootstrap/providers.php.
    $allowList = ['app/Providers/HorizonServiceProvider.php'];
    $hits = [];
    foreach (['app', 'Modules', 'bootstrap', 'routes'] as $root) {
        $abs = base_path($root);
        if (! is_dir($abs)) { continue; }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.php')) { continue; }
            $path = $file->getPathname();
            if (str_contains($path, '/tests/')) { continue; }
            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, $allowList, true)) { continue; }
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
            if (preg_match('/Laravel\\\\Horizon\\\\/', $stripped) === 1) {
                $hits[] = $relative;
            }
        }
    }
    expect($hits)->toBe([], "Laravel\\Horizon\\* may only be imported by the allow-listed provider. Offenders:\n  ".implode("\n  ", $hits));
});
```

### SC2 — in-process deterministic race simulation (skeleton, D-08)
```php
// Source: D-08 pattern + framework Cache lock API. Concrete shape is the
// planner's call; this shows the load-bearing assertions.
it('database lock store rejects a concurrent duplicate chain-resolution dispatch (SC2)', function (): void {
    config(['cache.locks_store' => 'database']);   // override the sync/array test default (Pitfall 5)
    // ... import the Modules/Chains/tests/fixtures/scenario-1/ payload for $user ...

    $store = \Illuminate\Support\Facades\Cache::store(config('cache.locks_store'));
    $key = 'laravel_unique_job:'.\Modules\Chains\Internal\Jobs\ResolveChainLinksJob::class.':'.$user->id;

    // Simulate overlapping dispatch: first acquire wins, second is rejected
    // BEFORE handle() runs — proving no second chain_resolution_runs row.
    $first  = $store->lock($key, 600)->get();
    $second = $store->lock($key, 600)->get();
    expect($first)->toBeTrue();
    expect($second)->toBeFalse();

    // Run handle() once (the winner); assert exactly one audit row, no dup.
    // ... dispatch/handle the job for $user ...
    expect(\Modules\Chains\Models\ChainResolutionRun::query()
        ->where('user_id', $user->id)->count())->toBe(1);
});
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `QUEUE_CONNECTION=redis` default in `config/queue.php` | `database` default | This phase | Shipped build needs no Redis daemon |
| Hard-coded `Cache::driver('redis')` in 10 `uniqueVia()` bodies + 10 per-file arch carve-outs | One shared helper + one carve-out for that helper | This phase | DI-rule surface shrinks from 10 exemptions to 1 |
| `DIEDERIK_RUNTIME=herd` dev-feature gate | `DIEDERIK_DEV_MODE=true` → `config('app.dev_mode')` | This phase (D-02) | Single, path-independent dev-mode signal |
| Horizon/Predis in `require` | `require-dev` | This phase (D-03) | `--no-dev` tree is smaller and Redis-free |
| `database` queue driver "configured for tests/fallback only" (per current `config/queue.php` comment) | `database` is the shipped production driver | This phase | The stale comment in `config/queue.php` lines 9-16 must be rewritten |

**Deprecated/outdated:**
- `config/queue.php`'s header comment ("The redis driver is the project default... database driver remains configured for tests and fallback environments only") — becomes false this phase and must be updated, or it will mislead future readers.
- `DIEDERIK_RUNTIME` — retired (D-02). Purge from `.env.example` and any code.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The unique-lock cache key format is `laravel_unique_job:{JobClass}:{uniqueId}` | Code Examples (SC2 skeleton) | LOW — the test should derive the key from the framework's actual `UniqueLock`/`shouldDispatch()` rather than hard-coding it; verify against `Illuminate\Foundation\Bus\PendingDispatch` + `Illuminate\Bus\UniqueLock` at implementation time. The *correctness* of the test does not depend on the literal — it depends on going through `uniqueVia()`. |
| A2 | `'after_commit' => false` on the `database` queue connection is acceptable, or the planner will decide whether to flip it to `true` | Pitfall 3 | MEDIUM — leaving it `false` keeps current behavior; the existing post-commit-dispatch discipline already mitigates it. Flagged for an explicit planner decision, not assumed. |
| A3 | The 3 generated migrations (`jobs`, `job_batches`, `cache`) work unmodified against the Phase-13 `UserDataPath`-rooted SQLite connection | Pitfall 1 | LOW — they are plain framework migrations on the default connection; Phase 13 already proved `migrate:fresh` works under the simulated NativePHP env. |
| A4 | Moving Horizon to `require-dev` does not strand any other `require` package that depends on it | Don't Hand-Roll / composer move | LOW — Horizon is a leaf dashboard package; nothing else in `require` depends on it. Verify with `composer why laravel/horizon` before the move. |

## Open Questions

1. **`after_commit` for the `database` queue connection.**
   - What we know: Current `database` connection config sets `'after_commit' => false`. The Redis path historically did not share the SQLite transaction frame; the `database` driver does.
   - What's unclear: Whether to flip it to `true` as part of this phase.
   - Recommendation: Planner decision. The existing "dispatch after the transaction closure" discipline (enforced by `ResolveChainLinksJobTest`) already covers the chain-resolution path. Flipping to `true` would be defense-in-depth for any future in-transaction dispatch. Keep `false` unless the planner wants the extra guard — either way, document it.

2. **Whether the SC2 test runs against the real `database` *queue* connection or only the `database` *lock* store.**
   - What we know: D-08 says "assert the lock store rejects the duplicate before `handle()` runs" — that is a *lock-store* assertion. SC2 (roadmap) says "against the `database` queue driver".
   - What's unclear: The two are separable — you can test the lock store without a real queue connection.
   - Recommendation: Do both. Drive the lock directly (D-08's deterministic assertion) AND run the end-to-end import → dispatch → `handle()` flow with `QUEUE_CONNECTION=database` so the `jobs` table is genuinely exercised. The fixture set (`Modules/Chains/tests/fixtures/scenario-1/`) supports the end-to-end leg.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `laravel/framework` | All config/driver work | ✓ | 13.8.0 | — |
| `pestphp/pest` + `pest-plugin-arch` | Arch test + concurrency test | ✓ | 4.7.0 / ^4.0 | — |
| SQLite (queue + cache + lock backend) | `database` driver | ✓ | Herd-bundled 3.45+ | — |
| `composer` (dependency move + `--no-dev` verify) | D-03 | ✓ | — | — |
| Redis (dev-box only, D-07) | Dev queue/Horizon | ✓ on dev box | — | Not needed for shipped path or CI of the `database` path |

**Missing dependencies with no fallback:** none — every dependency is already present.
**Missing dependencies with fallback:** none.

## Validation Architecture

> Phase 14 has a hard concurrency-correctness success criterion (SC2). Nyquist validation applies.

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.7.0 (PHPUnit 11 engine) + `pest-plugin-arch` ^4.0 |
| Config file | `phpunit.xml` (sets `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `DB_CONNECTION=sqlite_testing`) |
| Quick run command | `vendor/bin/pest --filter=BoundaryArchTest` (arch invariant) |
| Full suite command | `composer test` (`pest --parallel`) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PKG-03 / SC1 | `QUEUE_CONNECTION=database` is the shipped default; `uniqueVia()` resolves the configured lock store; uniqueness honored under `database` AND `redis` | unit/feature | `vendor/bin/pest --filter='lock store'` | ❌ Wave 0 — new test; existing `ResolveChainLinksJobTest` only asserts `uniqueVia()` returns *a* Repository, not the configured store |
| PKG-03 / SC2 | Concurrent dispatch produces no duplicate `chain_resolution_runs` row against the `database` driver, using a multi-month CAMT.053 + ICS PDF + PayPal CSV payload | feature (in-process race sim) | `vendor/bin/pest Modules/Chains/tests/Feature/<new>Test.php` | ❌ Wave 0 — new test; reuses `Modules/Chains/tests/fixtures/scenario-1/{asn-camt053.xml,ics-statement.pdf,paypal-activity.csv}` |
| PKG-03 / SC3 | `HorizonServiceProvider::boot()` early-exits when `app.dev_mode !== true`; `/horizon` route absent in shipped builds; `noHorizonImportsInShippedBuildCode` green | feature + arch | `vendor/bin/pest --filter=Horizon` | ❌ Wave 0 — new tests |
| PKG-03 / SC4 | `laravel/horizon` + `predis/predis` in `require-dev`; `--no-dev` tree has zero Horizon/Redis | shell/integration | `composer install --no-dev --dry-run \| grep -iE 'horizon\|predis'` (expect empty) | ❌ Wave 0 — can be a CI-gate shell check or a Pest test that greps `composer.json` sections |

### Sampling Rate
- **Per task commit:** `vendor/bin/pest --filter=BoundaryArchTest` (fast — arch invariant must stay green as carve-outs change)
- **Per wave merge:** `composer test` (full suite) + `composer analyse` (Larastan L10 strict) + `composer format:check`
- **Phase gate:** Full suite green + the SC2 concurrency test green + `composer install --no-dev` verified Horizon/Redis-free, before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] 3 generated migrations: `create_jobs_table`, `create_job_batches_table`, `create_cache_table` — prerequisite for ANY `database`-driver test (Pitfall 1)
- [ ] `Modules/Chains/tests/Feature/<SC2 concurrency>Test.php` — covers PKG-03 SC2; reuses `scenario-1` fixtures
- [ ] New `uniqueVia()` lock-store assertion test — covers SC1; must override `cache.locks_store` to `database` (Pitfall 5) and assert resolution against the *configured* store, not just "a Repository"
- [ ] `BoundaryArchTest::noHorizonImportsInShippedBuildCode` invariant + updated facade carve-out (replace the 10 per-job exemptions with the single shared-helper file)
- [ ] Horizon `boot()` early-exit feature test (`app.dev_mode` off → no `/horizon` route)
- [ ] SC4 verification — a Pest test grepping `composer.json` `require`/`require-dev` sections, or a CI shell gate

*The existing test suite covers chain-resolution `handle()` behavior (`ResolveChainLinksJobTest`, `ChainResolutionRunsLifecycleTest`) and `Queue::fake()` uniqueness — but NOT the `database` lock store, NOT Horizon gating, NOT the dependency tree. All four SC's need new Wave 0 tests.*

## Security Domain

> `security_enforcement` config status not located; this phase is infrastructure config with limited attack surface. ASVS treatment below covers the relevant categories.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | Phase changes no auth surface |
| V3 Session Management | no | `config/session.php` driver already `database`; untouched |
| V4 Access Control | yes | The `/horizon` dashboard exposes serialized queue payloads (may contain transaction data). The `app.dev_mode` early-exit + `class_exists()` guard ensure `/horizon` does not even register in shipped builds — defense beyond the existing Fortify auth gate + loopback binding. |
| V5 Input Validation | no | No new user input surface |
| V6 Cryptography | no | No crypto changes |
| V14 Configuration | yes | `DIEDERIK_DEV_MODE` defaults to `false` (fail-closed): a shipped build with the env unset gets dev-mode OFF and Horizon OFF. `CACHE_LOCK_STORE` defaults to `database` (no Redis dependency leaks into shipped). |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Horizon dashboard reachable in a shipped build (queue payloads = transaction data) | Information Disclosure | `app.dev_mode` `boot()` early-exit + `class_exists()`-guarded registration + Horizon/Predis as `require-dev` (route literally cannot exist) |
| `DIEDERIK_DEV_MODE` accidentally truthy in a shipped `.env` | Elevation of Privilege | `(bool)` cast in `config/app.php`; `=== true` strict checks; ship `.env.bundled` with the key absent/`false` (Phase 17 CI-06 concern, flag for that phase) |
| Duplicate chain-resolution under concurrent dispatch corrupting `chain_resolution_runs` | Tampering (data integrity) | `ShouldBeUniqueUntilProcessing` + `database` `DatabaseLock`; SC2 test proves no duplicate row |

## Sources

### Primary (HIGH confidence)
- `vendor/laravel/framework` (13.8.0) — verified `config/cache.php` has NO `locks_store` key; `database` store uses `lock_connection`/`lock_table`; `CacheManager::driver()` aliases `store()`; queue/cache table stubs in `Console/stubs/`. [VERIFIED: direct vendor inspection]
- `composer.lock` — `laravel/framework` 13.8.0, `pestphp/pest` v4.7.0. [VERIFIED]
- `composer.json` — `laravel/horizon ^5.46`, `predis/predis ^3.4` in `require`. [VERIFIED]
- `config/queue.php` line 20 — `'default' => env('QUEUE_CONNECTION', 'redis')`. [VERIFIED]
- `app/Providers/HorizonServiceProvider.php` — `boot()` calls `parent::boot()`; `bootstrap/providers.php` line 21 lists it unconditionally. [VERIFIED]
- All 10 job files — every `uniqueVia(): Repository` body is `return Cache::driver('redis');` (byte-identical); the dispatcher `BusChainResolutionDispatcher` has no `uniqueVia()` of its own — it delegates to `ResolveChainLinksJob::dispatch()`. [VERIFIED: grep + direct read]
- `database/migrations/` — only `failed_jobs` exists; no `jobs`/`job_batches`/`cache`/`cache_locks`. [VERIFIED: directory listing]
- `phpunit.xml` — test env: `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `DB_CONNECTION=sqlite_testing`. [VERIFIED]
- `Modules/Chains/tests/fixtures/scenario-1/` — `asn-camt053.xml`, `ics-statement.pdf`, `paypal-activity.csv` exist. [VERIFIED: find]
- `tests/Contracts/BoundaryArchTest.php` — facade carve-out lists all 10 job FQNs; uses both `arch()` and recursive-grep `it()` styles. [VERIFIED: full read]

### Secondary (MEDIUM confidence)
- Laravel 13 documentation conventions for `config:cache` / `env()` behavior — consistent with framework behavior since Laravel 5; no version-specific surprises expected.

### Tertiary (LOW confidence)
- A1: the literal unique-lock cache-key format — derive from `Illuminate\Bus\UniqueLock` at implementation time rather than trusting the assumed string.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new packages; all versions verified against composer.lock and vendor.
- Architecture: HIGH — provider gating, config keys, and the shared-helper shape verified against framework source and the existing codebase.
- Pitfalls: HIGH — every pitfall is grounded in a verified codebase fact (missing tables, unconditional provider registration, test env defaults, `locks_store` non-existence).

**Research date:** 2026-05-20
**Valid until:** 2026-06-20 (stable — framework config surface; re-verify if Laravel minor bumps before planning)
