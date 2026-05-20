# Phase 14: Queue Rewire + Horizon Carve-out - Pattern Map

**Mapped:** 2026-05-20
**Files analyzed:** 19 (create/modify)
**Analogs found:** 16 / 19

## Orientation

Phase 14 is a config + dependency + test phase. It has almost no "new feature
code" — it relocates existing patterns. The single genuinely new code file is
the shared lock-store helper (~10 lines). Every other file either copies an
existing arch-test invariant, a published-config shape, or a Pest feature-test
skeleton already present in the repo.

The two patterns the planner must lean on hardest:
1. **`BoundaryArchTest` carve-out + recursive-grep `it()` invariant** — the
   single most reused pattern. The Horizon arch invariant copies the existing
   `noFacadeCallsFromCoreConsoleCommands` grep shape; the facade carve-out
   *replacement* edits the existing `->ignoring([...])` list in place.
2. **The `uniqueVia()` body** — byte-identical across all 10 jobs
   (`return Cache::driver('redis');`). The migration collapses all 10 to one
   shared call.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `config/queue.php` (modify) | config | request-response | self (1-line flip + comment rewrite) | exact (self) |
| `config/cache.php` (create) | config | request-response | `config/queue.php` (published-config + custom-key shape) | role-match |
| `config/app.php` (modify) | config | request-response | self (`maintenance` block, `(bool)` cast on `debug`) | exact (self) |
| `app/Providers/HorizonServiceProvider.php` (modify) | provider | event-driven | self (`boot()` already exists) | exact (self) |
| `bootstrap/providers.php` (modify) | config/bootstrap | request-response | self (plain provider array) | exact (self) |
| Shared lock-store helper (create) | utility | transform | `Modules/Core/Public/Services/UserDataPathService.php` (Core/Public single-responsibility support class) | role-match |
| `ResolveChainLinksJob.php` (modify `uniqueVia()`) | job | event-driven | self | exact (self) |
| `BusChainResolutionDispatcher.php` | service | event-driven | — **no `uniqueVia()` of its own** | n/a — see "No Analog" |
| `ScanInboxDropFolderJob.php` (modify) | job | event-driven | `ResolveChainLinksJob` `uniqueVia()` | exact |
| `ProcessFetchedInboxMessagesJob.php` (modify) | job | event-driven | `ResolveChainLinksJob` `uniqueVia()` | exact |
| `BackfillInboxJob.php` (modify) | job | event-driven | `ResolveChainLinksJob` `uniqueVia()` | exact |
| `IncrementalScanJob.php` (modify) | job | event-driven | `ResolveChainLinksJob` `uniqueVia()` | exact |
| `DiscoveryScanJob.php` (modify) | job | event-driven | `ResolveChainLinksJob` `uniqueVia()` | exact |
| `DetectDriftAlertsJob.php` (modify) | job | event-driven | `ResolveChainLinksJob` `uniqueVia()` | exact |
| `ProjectForecastJob.php` (modify) | job | event-driven | `ResolveChainLinksJob` `uniqueVia()` | exact |
| `DetectRecurringSeriesJob.php` (modify) | job | event-driven | `ResolveChainLinksJob` `uniqueVia()` | exact |
| `tests/Contracts/BoundaryArchTest.php` (modify) | test (arch) | batch | self (`noFacadeCallsFromCoreConsoleCommands`, carve-out list) | exact (self) |
| `composer.json` (modify) | config | — | self (`require` / `require-dev` sections) | exact (self) |
| 3 framework migrations: `create_jobs_table`, `create_job_batches_table`, `create_cache_table` (create) | migration | — | framework `queue:table`/`make:cache-table` stubs (NOT hand-written) | framework stub |
| SC2 concurrency Pest test (create) | test (feature) | event-driven | `ResolveChainLinksJobTest.php` + `ChainResolutionIdempotencyTest.php` | exact (role + data flow) |
| SC1/SC3/SC4 test coverage (create) | test (feature/arch) | batch | `BoundaryArchTest` `it()` + `ResolveChainLinksJobTest` | exact |

## Pattern Assignments

### `config/queue.php` (modify — flip default + rewrite stale comment)

**Analog:** self.

**Current line 20 (the flip):**
```php
'default' => env('QUEUE_CONNECTION', 'redis'),
// → becomes:
'default' => env('QUEUE_CONNECTION', 'database'),
```

**Current header comment (lines 5-16) is now FALSE and must be rewritten.** It
asserts "The redis driver is the project default ... The database driver
remains configured for tests and fallback environments only." After this phase
`database` is the *shipped* default; `redis` is the dev-box override (D-07).
Rewrite the comment to state that, and that the `failed_jobs` table is joined
this phase by `jobs` / `job_batches` / `cache_locks`.

**`after_commit` decision (planner call — RESEARCH Open Question 1):** line 34
of the `database` connection is `'after_commit' => false`. The existing
post-commit-dispatch discipline (enforced by `ResolveChainLinksJobTest` — see
its "dispatch happens AFTER the transaction closure" test, lines 219-237)
already mitigates the in-transaction-dispatch hazard. Keep `false` unless the
planner wants defense-in-depth; either way document the choice.

---

### `config/cache.php` (create — publish framework default + custom `locks_store` key)

**Analog:** `config/queue.php` — the shape of a published Laravel config file
with a `declare(strict_types=1);` header, a doc-comment block, and an `env()`
default per key. No `config/cache.php` exists today (project rides framework
defaults).

**Critical correctness note (RESEARCH Pitfall 4):** `locks_store` is **NOT a
framework key**. The framework will not auto-route locks based on it. It is a
project convention this phase introduces; the D-06 helper explicitly reads it
and passes the value to `Cache::store()`. The config-file comment MUST say this
so a future reader does not hunt for non-existent framework docs.

**Custom key to add (sibling of `default`):**
```php
'default' => env('CACHE_STORE', 'database'),

// Project-defined key — NOT a Laravel framework key. The shared
// lock-store helper reads this and passes the value to Cache::store()
// so the shipped build runs database-backed ShouldBeUnique* locks while
// the dev box overrides to redis via CACHE_LOCK_STORE.
'locks_store' => env('CACHE_LOCK_STORE', 'database'),
```

Both `'database'` and `'redis'` must exist in `config('cache.stores')` — the
published framework default already defines both. Publish via
`php artisan config:publish cache` (or copy the vendor stub), then add the
single custom key + comment.

---

### `config/app.php` (modify — add `dev_mode` key)

**Analog:** self — lines 9 (`'debug' => (bool) env('APP_DEBUG', false)`) and
25-28 (`maintenance` block). Both show the established `(bool) env(...)` cast
and the `env()`-default pattern.

**Add one line (mirror line 9's `(bool)` cast):**
```php
'dev_mode' => (bool) env('DIEDERIK_DEV_MODE', false),
```

**Why the `(bool)` cast is load-bearing, not stylistic:** the Horizon gate uses
a strict `!== true` check. `env()` returns the string `"true"` / `"false"` /
`null`. Casting at the config layer lets every consumer use `=== true`. And
because the shipped build runs `php artisan config:cache`, `env()` outside
config files returns `null` — the read MUST stay confined to `config/app.php`.

---

### `app/Providers/HorizonServiceProvider.php` (modify — `app.dev_mode` early-exit)

**Analog:** self. The current file (read in full, 40 lines):

**Current `boot()` (lines 22-27):**
```php
public function boot(): void
{
    parent::boot();

    Horizon::auth(static fn (Request $request): bool => $request->user() !== null);
}
```

**Pattern to apply — early-exit BEFORE `parent::boot()`:**
```php
public function boot(): void
{
    if (config('app.dev_mode') !== true) {
        return; // shipped-build safe: no /horizon route, no Horizon assets
    }

    parent::boot();

    Horizon::auth(static fn (Request $request): bool => $request->user() !== null);
}
```

`parent::boot()` (`HorizonApplicationServiceProvider::boot()`) is what registers
the `/horizon` dashboard routes + resources — the early-exit before it is what
makes the gate effective. The `gate()` method (lines 35-39) and the existing
class docblock about queue payloads / loopback / Fortify stay unchanged.

**Note:** this provider is the *single allow-listed file* for the new
`noHorizonImportsInShippedBuildCode` invariant — it imports `Laravel\Horizon\*`
(lines 9-10) and is `class_exists()`-guarded in `bootstrap/providers.php`.

---

### `bootstrap/providers.php` (modify — `class_exists()`-guarded registration)

**Analog:** self. The current file is a plain 36-line PHP array. `HorizonServiceProvider`
is `use`d on line 5 and listed unconditionally as the first array entry (line 21).

**Pitfall (RESEARCH Pitfall 2):** in a `composer install --no-dev` tree
`Laravel\Horizon\HorizonApplicationServiceProvider` does not exist;
`HorizonServiceProvider` `extends` it, so the class file fatals at *autoload*
time — before `boot()` ever runs. The `boot()` early-exit alone is insufficient.

**Pattern to apply (RESEARCH Pattern 3 — exact expression is planner discretion):**
```php
return array_values(array_filter([
    class_exists(\Laravel\Horizon\HorizonApplicationServiceProvider::class)
        ? \App\Providers\HorizonServiceProvider::class
        : null,
    \Modules\Core\Providers\CoreServiceProvider::class,
    // ... the remaining 12 module providers unchanged, in current order ...
]));
```
The `use App\Providers\HorizonServiceProvider;` import on line 5 can stay
(string-class reference) or be inlined as an FQCN — planner call. The other 12
module-provider entries (lines 22-34) are untouched and keep their order.

---

### Shared lock-store helper (create)

**Analog:** `Modules/Core/Public/Services/UserDataPathService.php` — the closest
existing precedent for a Core/Public single-responsibility support class that
centralizes one cross-cutting concern (it is itself the sole allow-listed file
for the `noStoragePathHardCodedOutsideUserDataPathService` arch invariant — the
*exact same "one file owns the carve-out" shape* this helper needs).

**Recommended location:** `Modules/Core/Public/Support/LockStore.php` (RESEARCH
Pattern 2; final name/location is Claude's discretion per D-06). `Modules/Core/Public/`
already has `Services/`, `Concerns/`, `Contracts/`, `Dto/` — adding `Support/`
is consistent.

**Hard constraint (the reason this is a static helper, not an injected service):**
Laravel calls `ShouldBeUnique*::uniqueVia()` at queue-push time inside
`PendingDispatch::shouldDispatch()`, *before* constructor DI completes — see the
`BusChainResolutionDispatcher` docblock (lines 19-25, read in full). A
constructor-injected `Repository` is impossible here. This is the exact
pre-existing carve-out reason; the helper inherits it.

**Recommended shape (RESEARCH Pattern 2 — trait vs static helper is D-06
discretion; researcher recommends static helper):**
```php
final class LockStore
{
    /**
     * The queue-uniqueness lock store, resolved from config('cache.locks_store')
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
Use `Cache::store()` (documented public name), not `Cache::driver()` (legacy
alias — both resolve a *named store*).

This file is the **single facade carve-out** going forward — the
`BoundaryArchTest` `->ignoring([...])` list (10 per-job FQNs) is replaced by
this one file's FQN. See Shared Patterns below.

---

### All 10 `ShouldBeUnique*` jobs (modify `uniqueVia()`)

**Analog:** `ResolveChainLinksJob.php` `uniqueVia()` (lines 89-96).

**VERIFIED — the `uniqueVia()` body is byte-identical across all 10 jobs.**
Confirmed by direct read: `ResolveChainLinksJob` (line 95), `BackfillInboxJob`
(line 158), `DetectDriftAlertsJob` (line 70) all return `Cache::driver('redis')`;
grep confirms `ProcessFetchedInboxMessagesJob`, `ScanInboxDropFolderJob`,
`IncrementalScanJob`, `DiscoveryScanJob`, `ProjectForecastJob`,
`DetectRecurringSeriesJob` all carry the same `uniqueVia(): Repository` method.
They do **not vary** (CONTEXT's "given how much the bodies vary" is corrected by
RESEARCH Finding 1 — they are identical).

**Current pattern (every job):**
```php
public function uniqueVia(): Repository
{
    // The Cache facade is the single permitted facade use in
    // module code (BoundaryArchTest carve-out). Laravel calls
    // uniqueVia() before constructor DI completes — there is
    // no path to inject a Repository at this point.
    return Cache::driver('redis');
}
```

**Migration pattern — collapse to the shared helper:**
```php
public function uniqueVia(): Repository
{
    return LockStore::forUniqueJobs();
}
```

Per-job mechanical changes when adopting the helper:
- Remove `use Illuminate\Support\Facades\Cache;` from each job's import block
  (the facade now lives only in `LockStore`).
- Add `use Modules\Core\Public\Support\LockStore;` (or the chosen FQN).
- Keep `use Illuminate\Contracts\Cache\Repository;` — still the return type.
- **Update each job's class docblock.** Multiple jobs have a multi-line
  "Single permitted facade exception: the `Cache::driver('redis')` call inside
  `uniqueVia()`..." paragraph (e.g. `ResolveChainLinksJob` lines 45-49,
  `BackfillInboxJob` lines 114-119). Per MEMORY `feedback_docs_describe_current_state`,
  these docblocks must describe the *new* state — the job now delegates to the
  shared `LockStore`, the facade no longer lives in the job. Do not leave a
  "was Cache::driver, now LockStore" history note — just describe what the
  code does now.

**Exact files (10):**
- `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php`
- `Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php`
- `Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php`
- `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php`
- `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php`
- `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php`
- `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php`
- `Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php`
- `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php`
- `BusChainResolutionDispatcher` — **NOT a job, no `uniqueVia()`** (see No Analog).

---

### `tests/Contracts/BoundaryArchTest.php` (modify — new invariant + carve-out swap)

**Analog:** self — this file already contains both arch-test styles the phase needs.

**Part A — replace the facade carve-out (D-06).** The existing
`arch('no Laravel facade usage in module code')` block (lines 52-105) carries an
`->ignoring([...])` list of 10 per-job FQNs (lines 62-104), each with a
multi-line rationale comment. After the migration the facade lives only in
`LockStore` — the planner replaces the 10 entries with the single new helper's
FQN (`Modules\Core\Public\Support\LockStore` or chosen name), and rewrites the
carve-out comment to describe the one-file mechanism.

**Part B — add `noHorizonImportsInShippedBuildCode`.** Copy the recursive-grep
`it()` shape from `noFacadeCallsFromCoreConsoleCommands` (lines 926-965) — the
closest existing analog: same `RecursiveIteratorIterator` walk, same
`preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents)` comment-strip, same
`preg_match('/Illuminate\\\\Support\\\\Facades\\\\/', ...)` namespace match,
same `expect($hits)->toBe([], "...")` assertion. `noStoragePathHardCodedOutsideUserDataPathService`
(lines 1119-1187) is the analog for the *allow-list* leg — it shows the
`$allowList = [...]`, `str_replace(base_path().'/', '', $path)` relative-path
normalization, and `in_array($relative, $allowList, true)` skip.

**Concrete invariant (RESEARCH Code Examples — adapt to match local style):**
```php
it('does not allow Horizon imports outside the allow-listed provider (noHorizonImportsInShippedBuildCode)', function (): void {
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

---

### `composer.json` (modify — Horizon + Predis to `require-dev`)

**Analog:** self. VERIFIED current placement: `laravel/horizon: "^5.46"` at
line 14 and `predis/predis: "^3.4"` at line 22 — both in `require`. Move both
to `require-dev`. Then `composer update --lock` to refresh `composer.lock`.
Run `composer why laravel/horizon` first (RESEARCH Assumption A4) to confirm no
other `require` package depends on it — it is a leaf dashboard package, so this
is expected to be clean.

---

### 3 framework migrations (create — `jobs`, `job_batches`, `cache`/`cache_locks`)

**Analog:** framework stubs — do NOT hand-write. RESEARCH Pitfall 1: today
`database/migrations/` holds ONLY `2026_05_16_174022_create_failed_jobs_table.php`.
The `database` queue driver fatals "no such table: jobs" and `DatabaseLock`
fatals "no such table: cache_locks" without these.

**Generate, do not author:**
```bash
php artisan queue:table          # create_jobs_table migration
php artisan queue:batches-table  # create_job_batches_table migration
php artisan make:cache-table     # create_cache_table — creates BOTH cache + cache_locks
php artisan migrate
```
These land in `database/migrations/` alongside `create_failed_jobs_table` and
run against the default connection — which Phase 13's `UserDataPathService`
already roots at the NativePHP-aware SQLite location (RESEARCH Assumption A3).

---

### SC2 concurrency Pest test (create)

**Analog:** `Modules/Chains/tests/Feature/ResolveChainLinksJobTest.php` (read in
full) for the fixture-seeding + `handle()`-invocation shape, and
`Modules/Chains/tests/Contracts/ChainResolutionIdempotencyTest.php` for the
"no duplicate row" assertion shape. Place the new test under
`Modules/Chains/tests/Feature/`.

**Reusable from `ResolveChainLinksJobTest`:**
- `seedJobUserAndFixtures()` helper (lines 38-63) — seeds a `User` + ICS
  `Account` + `ImportRun`. The SC2 test can reuse this or the multi-month
  `scenario-1/` fixtures (`asn-camt053.xml`, `ics-statement.pdf`,
  `paypal-activity.csv` — VERIFIED present in `Modules/Chains/tests/fixtures/scenario-1/`).
- `handle()` invocation via `$this->app->make(...)` for each DI argument
  (lines 178-184) — `DatabaseManager`, `Clock`, `IcsSettlementResolver`,
  `PaypalFundingResolver`.
- `ChainResolutionRun::query()->where('user_id', ...)->...` audit-row assertion
  (lines 186-193).

**Load-bearing deviations from the existing test (RESEARCH Pitfalls 5 & 6):**
- The existing test asserts `uniqueVia()` returns *a* `CacheRepository` (line
  88-92) — that proves nothing about the `database` store. The SC2 test must
  `config(['cache.locks_store' => 'database'])` to override the `phpunit.xml`
  `CACHE_STORE=array` default, and the `cache_locks` + `jobs` tables must be
  migrated into the test DB.
- Do NOT rely solely on `Queue::fake()` (existing test uses it lines 94-115) —
  `Queue::fake()` masks whether the real SQLite lock did the rejecting. Drive
  the lock directly per D-08: acquire via the configured store, attempt a
  second acquire, assert it returns `false`, then run `handle()` once and assert
  exactly one `chain_resolution_runs` row.

**Skeleton (RESEARCH Code Examples — concrete shape is planner's call):**
```php
it('database lock store rejects a concurrent duplicate chain-resolution dispatch (SC2)', function (): void {
    config(['cache.locks_store' => 'database']);
    // ... seed user + import the scenario-1 payload ...
    $store = \Illuminate\Support\Facades\Cache::store(config('cache.locks_store'));
    $key   = 'laravel_unique_job:'.ResolveChainLinksJob::class.':'.$user->id; // A1 — derive from UniqueLock at impl time
    expect($store->lock($key, 600)->get())->toBeTrue();   // first wins
    expect($store->lock($key, 600)->get())->toBeFalse();  // duplicate rejected
    // ... run handle() once ...
    expect(ChainResolutionRun::query()->where('user_id', $user->id)->count())->toBe(1);
});
```
Derive the literal lock-key from `Illuminate\Bus\UniqueLock` at implementation
time rather than hard-coding it (RESEARCH Assumption A1).

---

### SC1 / SC3 / SC4 test coverage (create)

**Analog:** `BoundaryArchTest` `it()` blocks for SC4 (grep `composer.json`
sections); `ResolveChainLinksJobTest` for SC1 (`uniqueVia()` resolution
assertion). All Wave 0 — none exist today.

- **SC1** — assert `uniqueVia()` resolves the *configured* store, not just
  "a Repository". Override `cache.locks_store` to `database` AND to `redis`,
  assert resolution against each. Replaces the weak existing assertion
  (`ResolveChainLinksJobTest` lines 88-92).
- **SC3** — feature test: with `config(['app.dev_mode' => false])`, assert the
  `/horizon` route is absent (e.g. route collection has no `horizon` entry).
- **SC4** — a Pest `it()` that greps `composer.json`'s `require` /
  `require-dev` sections (mirror the `file_get_contents` + assertion shape used
  in `ResolveChainLinksJobTest` lines 208-217's "sanity-grep the source"
  pattern), or a CI shell gate:
  `composer install --no-dev --dry-run 2>&1 | grep -iE 'horizon|predis'`
  expecting empty output.

## Shared Patterns

### Single facade carve-out (the central D-06 pattern)

**Source:** `tests/Contracts/BoundaryArchTest.php` lines 52-105 (the existing
`->ignoring([...])` carve-out) + `Modules/Core/Public/Services/UserDataPathService.php`
(the "one file owns the carve-out" precedent — it is the sole allow-listed file
for `noStoragePathHardCodedOutsideUserDataPathService`).
**Apply to:** the new `LockStore` helper + all 10 job files + `BoundaryArchTest`.
**Mechanism:** the facade (`Cache`) + `config()` use is confined to ONE new
file (`LockStore`); the arch-test `->ignoring([...])` shrinks from 10 per-job
FQNs to that single FQN. This is the same shape Phase 12/13 used — one
sanctioned file, one allow-list entry.

### DI-only rule (CLAUDE.md `feedback_laravel_di_only`)

**Apply to:** every file in scope. Constructor DI only; no facades / global
helpers in module code. The `uniqueVia()` `Cache` use is the *one* sanctioned
exception (Laravel calls it before constructor DI completes). The new `LockStore`
helper inherits exactly this exception and nothing wider — it is the only place
`config()` / `Cache` may be called in `Modules/`.
**Exception surface for config files:** `config/*.php` legitimately call `env()`
and static accessors (the `noLaravelGlobalHelpersInCoreConsoleCommands` invariant
scopes the helper ban to `Modules/Core/Internal/Console/`, not config). Reading
`env('DIEDERIK_DEV_MODE')` is permitted **only** in `config/app.php`.

### Recursive-grep arch invariant

**Source:** `tests/Contracts/BoundaryArchTest.php` `noFacadeCallsFromCoreConsoleCommands`
(lines 926-965) — canonical shape; `noStoragePathHardCodedOutsideUserDataPathService`
(lines 1119-1187) for the allow-list leg.
**Apply to:** `noHorizonImportsInShippedBuildCode`.
**Invariant elements:** `RecursiveIteratorIterator` + `RecursiveDirectoryIterator`
walk; `.php` filter; `/tests/` skip; comment-strip via
`preg_replace('#/\*.*?\*/|//[^\n]*#s', ...)`; namespace `preg_match`; per-file
allow-list with `str_replace(base_path().'/', '', $path)` relative normalization;
`expect($hits)->toBe([], "...message...")`.

### Codebase stays GSD-agnostic (MEMORY `feedback_codebase_gsd_agnostic`)

**Apply to:** every file written. No `.planning/` / `PLAN.md` / `RESEARCH.md` /
phase-number references in code, PHPDocs, or comments. Job docblocks that
currently say e.g. "(D-103)" or "RESEARCH Pitfall 3" already exist in the repo —
when the planner touches those docblocks for the `uniqueVia()` migration, this
is a chance to keep them current-state and decision-ID-free where practical, but
removing pre-existing references is not in this phase's scope unless the docblock
is being rewritten anyway.

### Docs describe current state (MEMORY `feedback_docs_describe_current_state`)

**Apply to:** all 10 job docblocks, the `config/queue.php` header comment, the
`config/cache.php` `locks_store` comment. After the migration the docblocks must
describe what the code does *now* (delegates to `LockStore`) — never "was
`Cache::driver`, changed to `LockStore`". The stale `config/queue.php` header
("redis is the project default ... database for tests only") is a current
example of a doc that drifted from reality — rewrite it to the new truth.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `Modules/Chains/Internal/Services/BusChainResolutionDispatcher.php` | service | event-driven | VERIFIED (full read): this dispatcher has **no `uniqueVia()` of its own** — it is a 39-line wrapper that calls `ResolveChainLinksJob::dispatch($userId)`. CONTEXT.md lists it among the `uniqueVia()` migration targets, but RESEARCH Finding (line 549) and direct read confirm it delegates; the `uniqueVia()` lives on `ResolveChainLinksJob`. **No change needed in this file** — its docblock (lines 19-25) correctly documents that the lock is acquired via the job's `uniqueVia()` at `PendingDispatch::shouldDispatch()` time. Planner should drop it from the file list; if its docblock references `Redis` specifically as the lock store, that one word may be softened to "the configured lock store", but no `uniqueVia()` migration applies. |

(All other in-scope files have a strong analog — most are self-modifications or
framework stubs.)

## Metadata

**Analog search scope:** `tests/Contracts/`, `app/Providers/`, `bootstrap/`,
`config/`, `Modules/Chains/Internal/Jobs/`, `Modules/Chains/Internal/Services/`,
`Modules/EmailScan/Internal/Jobs/`, `Modules/DriftAlerts/Internal/Jobs/`,
`Modules/Receipts/Internal/Jobs/` (grep), `Modules/Forecasting/Internal/Jobs/`
(grep), `Modules/Recurring/Internal/Jobs/` (grep), `Modules/Chains/tests/`,
`Modules/Core/Public/`, `database/migrations/`, `composer.json`, `.env.example`.
**Files scanned:** ~20 (8 read in full, ~12 confirmed via targeted grep).
**Pattern extraction date:** 2026-05-20
