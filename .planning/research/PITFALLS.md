# v2.0 Pitfalls Research — Desktop Packaging + Multi-User + Developer Mode + CI Release

**Domain:** Wrapping an existing shipped Laravel 13 + Livewire 4 + SQLite + Horizon-on-Redis personal-finance app as a code-signed desktop installer (NativePHP/Electron), activating the dormant multi-user schema, adding an in-app Developer Console, and publishing under Hippocratic License 3.0 via GitHub Actions.
**Researched:** 2026-05-19
**Confidence:**
- HIGH for: NativePHP storage-path conventions, PHP-JIT hardened-runtime entitlements, electron-updater signing, OSI status of Hippocratic 3.0, Fortify password-reset hookpoints (all verified against official docs).
- MEDIUM for: the *interaction* between these mechanisms and diederik's specific invariants (Horizon-Redis loopback, DI-only rule, 34+ arch-test invariants, state-machine triggers). Project-specific empirical validation required during each v2.0 phase.

**How this file relates to v1.0 PITFALLS.md:** That file covered the 19 pitfalls of *building* the v1.0 stack. This file does **not** repeat them. It covers only the pitfalls introduced by **integrating** the 8 new v2.0 capabilities (PKG / MULTI / DEVUI / CI / REL / RESET / UPDATE / LICENSE) on top of the v1.0 stack. Where a v1.0 pitfall becomes more dangerous in a v2.0 context (e.g. floats in money math now ship to a partner's machine), it is cross-referenced, not re-explained.

---

## Critical Pitfalls

### Pitfall 1: Hard-coded `database/database.sqlite` path breaks the moment NativePHP boots

**What goes wrong:**
v1.0 puts the SQLite file at `database/database.sqlite` (project root). Anything that constructs that path — `db:backup`, `db:restore`, `diederik:doctor`, `HealthCheckServiceProvider`, the launchd plist templates, any test that uses an explicit fixture path, any module's `DatabaseBackupRepository` — assumes the project root is the writable working directory.

Inside an Electron bundle the project root is `Resources/app/` (macOS) or `resources\app\` (Windows), shipped as **read-only**. NativePHP transparently relocates the SQLite file to `Application::storagePath()` (per-OS appdata), but anything that doesn't read the active connection's `database` PDO attribute and instead reads `base_path('database/database.sqlite')` or the literal `DB_DATABASE` env value will keep pointing at the read-only bundled location. The first write attempt throws a `SQLSTATE[HY000]: General error: 8 attempt to write a readonly database`. The app boots, the dashboard loads, then any user action fails.

**Why it happens:**
NativePHP automatically rewrites the database connection at boot — but only the *active* connection. Code paths that hand-construct paths (`base_path()`, `database_path()`, string concat of `DB_DATABASE`) bypass this. The DI-only rule actually makes this *worse*: a service that takes a `DatabaseManager` and asks it for the current connection path is correct; a service that takes a `Repository $config` and reads `database.connections.sqlite.database` is wrong but passes Larastan.

**How to avoid:**
- **Introduce `Modules\Core\Filesystem\AppPaths` from day one of the packaging phase** — a single injectable that exposes `databasePath()`, `backupsPath()`, `oauthSecretsPath()`, `logsPath()`, `inboxPath()`, `attachmentsPath()`. In Herd-dev mode it returns the existing v1.0 paths. In NativePHP mode it returns paths under `Application::storagePath()`. Every consumer (`DatabaseBackupRepository`, `OAuthSecretsRepository`, `EmailScan`'s `.eml` drop-in path, `diederik:doctor`) takes `AppPaths` as a constructor dependency.
- **Arch-test invariant** (`PKG-01`): forbid `base_path()`, `database_path()`, `storage_path()`, and string literals containing `database.sqlite` / `storage/app/` outside `Modules\Core\Filesystem`. Only `AppPaths` may produce filesystem locations.
- **Grep gate** in CI: `git grep -nE "(base_path|database_path|storage_path)\(" Modules/ app/ -- ':!Modules/Core/Filesystem'` returns empty.
- **Pest test** that boots the app under a simulated NativePHP env (env var `NATIVEPHP_STORAGE_PATH` set to a temp dir), runs `php artisan db:backup`, and asserts the backup landed in the temp dir, not in the project root.

**Warning signs:**
- Any string `'database/database.sqlite'` anywhere in `Modules/` (Larastan won't catch it).
- A test that uses `realpath(database_path('database.sqlite'))` for fixture assertions.
- Launchd plist references absolute paths under `/Users/wesselverheij/Development/diederik/` (it does — those plists work for the developer's box, not for an installed app).
- The `DB_DATABASE` value being read anywhere outside `config/database.php`.

**Phase to address:**
**PKG-01 (first packaging phase)**, *before* the NativePHP integration spike, so the AppPaths abstraction lands in the same PR as the first read of a writable path. Retrofitting after NativePHP is integrated means hunting silent bugs across all 11 modules.

**Test/check that proves it didn't happen:** `BoundaryArchTest::test_filesystem_paths_are_injectable_only()` (forbids the four helpers + the literal database filename) + the simulated-NativePHP backup test.

---

### Pitfall 2: First-run data migration silently corrupts the developer's real v1.0 data

**What goes wrong:**
The developer (= the user) already has 5+ years of v1.0 transactions in `database/database.sqlite` at the project root. The v2.0 desktop build runs for the first time and:
- (A) Migrates a fresh empty schema into `Application::storagePath()/database/database.sqlite`, so the desktop app starts with **zero transactions**, and the developer thinks "is everything broken?" — then panics, runs `db:restore`, and overwrites the empty desktop DB with the live v1.0 DB without realizing the original v1.0 was still being read by Herd's `php artisan schedule:work`, producing a torn copy.
- (B) Or worse, the first-run migration helpfully copies the v1.0 file into the new location, but the v1.0 file is in WAL mode with uncommitted WAL pages, and the copy is a plain `cp` (see v1.0 Pitfall 10) producing a subtly corrupt SQLite on the partner's machine.
- (C) Or the migration runs idempotently every launch and on the second launch overwrites the desktop's already-edited DB with the now-stale v1.0 copy.
- (D) Or the OAuth secrets at `storage/app/secrets/oauth/*.json` (chmod 600) get migrated as world-readable inside `%APPDATA%` because Electron's copy doesn't preserve POSIX modes (and Windows has a different ACL model anyway).

**Why it happens:**
A one-time, idempotent, atomic, mode-preserving, WAL-safe, "what if v1.0 is still running in Herd" migration is *easy to talk about and hard to write*. The developer-as-test-subject is the worst case: real data, dual-running v1.0 + v2.0 during the transition, and the same finder window showing both DB files.

**How to avoid:**
- **Migration is explicit, not automatic.** The desktop app on first run shows a "Welcome — Import existing data?" wizard. Three buttons: "Start fresh", "Import from existing Diederik install (pick folder)", "Quit and decide later". Never auto-detects + auto-copies.
- **The import path uses `VACUUM INTO`, not file copy.** Same primitive `db:backup` uses in v1.0 (Phase 11). Source DB must be opened in read-only mode (`mode=ro` URI flag) so the act of copying cannot corrupt the live v1.0. WAL pages are merged by SQLite during the VACUUM.
- **A migration sentinel file** (`Application::storagePath().'/.migrated-from-v1'`) records the source path + sha256 of the source DB at import time. Subsequent launches see the sentinel and never re-run. Re-import requires explicit "Reset and re-import" in the wizard.
- **OAuth secrets are NOT copied directly.** The wizard prompts: "Re-connect Gmail / Microsoft / Other? (You'll need to re-authorize.)" Existing OAuth tokens stay on the v1.0 side; the v2.0 install gets fresh tokens with fresh chmod 600 (POSIX) / fresh ACL (Windows: SYSTEM + current user only, no Everyone, no Authenticated Users). Reason: forwarding OAuth tokens across an OS-level copy is a leak vector and the tokens are cheap to recreate.
- **Backups directory** migrates via the same wizard, but as an *optional* second step — the user picks "import previous backups too" or skips. Backups are large and most users won't need history beyond what's in the live DB.
- **Pre-migration safety dump.** Before the wizard's "Import" runs, it makes a `VACUUM INTO` snapshot of *both* the source and the (empty) destination into `Application::storagePath().'/migration-rollback/'`. Rollback is "delete the new DB, restore the snapshot." Documented in the SECURITY.md.
- **The v1.0 launchd plists must be uninstalled before the desktop app takes over** — otherwise the v1.0 scheduler keeps writing to the source DB while the desktop is reading it. Provide `php artisan diederik:install --launchd --uninstall` (already exists per v1.0 Phase 11) and have the wizard run it as a confirmation step.

**Warning signs:**
- A migration that runs on every boot instead of once.
- A code path that calls `copy()` / `File::copy()` on a `.sqlite` file.
- No sentinel file or no sha256 of source for audit.
- The wizard's "Import" path doesn't pause to uninstall the v1.0 launchd plists first.
- OAuth secret files appear in the new install with `0644` (or world-readable equivalent on Windows).

**Phase to address:**
**PKG-02 (data-migration wizard phase)**, separate phase from the NativePHP packaging spike. The wizard is the riskiest single UX in v2.0 — give it its own phase + UAT scenario with real data.

**Test/check that proves it didn't happen:** Pest feature test that creates a fake "v1.0 install" with a WAL DB + open write transaction + OAuth secret file, runs the import wizard, and asserts: (a) destination DB integrity check passes, (b) sentinel file written with correct sha256, (c) OAuth secret was NOT auto-copied, (d) second launch does not re-run the migration. Plus a manual UAT scenario with the developer's real data on a sacrificial copy.

---

### Pitfall 3: Horizon + Redis don't ship in the Electron bundle — chain resolver silently dies

**What goes wrong:**
v1.0 Phase 5 made an explicit stack carve-out: `laravel/horizon` + a loopback-bound Docker Redis (`127.0.0.1:6379`) for chain resolution because `ShouldBeUniqueUntilProcessing` per-user locking needs real Redis semantics, and the chain-resolver dashboard is operationally useful. That decision is correct for a developer box that already runs Docker. It is **fatal** for an end-user install on macOS / Windows / Linux where:
- The partner has never heard of Docker.
- Asking a non-technical user to install Docker Desktop is a 20-step setup with a 1 GB download and a license agreement that breaks the privacy promise (Docker Desktop has telemetry).
- Electron bundles do not ship Redis. There is no clean way to.
- The chain-resolver job dispatches, queues to Redis (which doesn't exist), fails silently, and the user sees forecasts that ignore chain resolution. The forecast looks plausibly correct (because deterministic links from v1.0 Phase 5 still work) but fuzzy chain resolution and ICS-bulk decomposition are dead.
- Horizon's dashboard route loads, can't connect to Redis, throws, and the SystemAlertsBanner lights up with a cryptic error.

**Why it happens:**
The Phase 5 stack-override was a *development-machine* override. The "Redis is a hard requirement for chain resolution" rationale was honest at the time but didn't anticipate the shipping target. Now Horizon is woven into chain resolution (`Modules\Chains` dispatches via Horizon-tagged jobs), and `ShouldBeUniqueUntilProcessing` *requires* a cache driver that supports atomic locks (`redis`, `database`, `memcached`, `dynamodb` per Laravel 13 docs — `database` works in SQLite if the cache driver is properly configured).

**How to avoid:**
Two real options, pick one and commit:

**Option A (recommended): Drop Redis for the desktop build, replace with `database` cache + queue driver.**
- Per-user uniqueness lock moves from Redis-backed to the database cache lock provider. `ShouldBeUniqueUntilProcessing` works against the cache contract — `Cache::lock()` against the database driver is officially supported in Laravel 11+.
- Queue driver becomes `database` (same default as v1.0 pre-Phase-5). Job rate is one-human-per-machine; SQLite-WAL+`synchronous=NORMAL` handles it fine, validated by v1.0's Phase 11 doctor.
- Replace Horizon dashboard with a Livewire-built failed-jobs viewer + queue-depth viewer inside the in-app Dev Console (the DEVUI work is happening anyway — fold the dashboard into it).
- Configuration: the desktop build's bundled `.env` (see Pitfall 6) sets `QUEUE_CONNECTION=database` + `CACHE_STORE=database`. The Herd dev environment keeps Redis (developer convenience). The arch test from Pitfall 6 ensures the bundled config is what ships.
- Cost: lose Horizon's per-job retry visualization. Gain: a portable install with zero external services.

**Option B: Bundle a userland Redis via [`predis/predis`](https://github.com/predis/predis)-only-with-no-server, ship without Horizon, accept that some Horizon-only features go dark.**
- Not actually a path — Predis is a client library, not a server. There is no "embeddable Redis" in PHP. Drop this option.

**Option B' (only if Option A is unacceptable): Ship a tiny statically-linked Redis binary in the bundle, launch it as an Electron child process bound to `127.0.0.1` on a random free port, persist nothing (or persist to `Application::storagePath()/redis.rdb`).**
- macOS: `redis-server` from Homebrew is a 2.5 MB binary; Apple's hardened runtime + notarization can accept it with the right entitlements (it's a normal Mach-O); Windows: tporadowski/redis-windows fork (5 MB).
- Risk: notarization complexity, antivirus false-positives on Windows for "unknown server binary", port-collision if user runs their own Redis.
- Reject this option unless Option A's loss of Horizon proves operationally painful in beta — which is unlikely on a single-machine, one-or-two-user install.

**Decision: Pick Option A.** Update Phase 5's "Horizon required" decision to "Horizon developer-only; bundled build uses database driver with cache-lock for per-user uniqueness." Document in the PROJECT.md "Key Decisions" table with an `⚠️ Revisit` outcome.

**Warning signs:**
- The bundled `.env` contains `QUEUE_CONNECTION=redis` or `CACHE_STORE=redis`.
- `Modules\Chains\Jobs\ResolveChainJob` references `Redis::` (facade — also a DI violation) or a specific Redis cache prefix.
- `composer.json` keeps `laravel/horizon` in `require` (not `require-dev`) for the bundled build.
- The first launch on a fresh user box shows the SystemAlertsBanner with a Redis connection error.

**Phase to address:**
**PKG-03 (queue & cache portability)** — must be its own phase, must land *before* the multi-user beta, must include a Pest test that proves chain resolution works end-to-end against the `database` driver with `Cache::lock()` honoring `ShouldBeUniqueUntilProcessing` semantics.

**Test/check that proves it didn't happen:** Existing `Modules\Chains` job-uniqueness Pest tests are reconfigured to run against `CACHE_STORE=database` + `QUEUE_CONNECTION=database`, and a new test fires 5 concurrent dispatches for the same `(user_id, fingerprint)` pair and asserts exactly one job ran (the others were dropped by the lock).

---

### Pitfall 4: `Auth::user()` smuggled into 50 places, silently killing the no-facade-rule

**What goes wrong:**
The dormant multi-user schema activates. Every Livewire component, every controller, every job, every domain service that *was* hardcoded to `user_id = 1` now needs the current authenticated user. The path of least resistance is `Auth::user()` (facade) or `auth()->user()` (helper) — both forbidden by the DI-only rule baked into v1.0 (PROJECT.md: "Forbidden: helper functions (`auth()`, `request()`, ...) and facade static calls (`Auth::user()`, ...)").

The first PR that activates multi-user touches `Modules\Ledger`, `Modules\Categorization`, `Modules\Recurring`, `Modules\DriftAlerts`, `Modules\Forecasting`, `Modules\Chains`, `Modules\Transfers`, `Modules\EmailScan`, `Modules\Receipts`, `Modules\Ingestion`, and `Modules\Core` — that is *every* module. Under deadline pressure to "just ship multi-user", the temptation to `use Illuminate\Support\Facades\Auth;` in every constructor-less Livewire mount() method is enormous. Some of these places — Livewire components in particular — historically resolve services via DI in `boot()`/`mount()` but the *current user* feels like global state, not a dependency. **`Auth::user()` will appear in 50+ places before the end of the multi-user phase.** The arch test catches it, but only after 50 places have been written, and the "fix" is to plumb a `CurrentUserProvider` through 50 constructors at once — which makes the multi-user PR a 3000-line diff that nobody can review.

**Why it happens:**
- `Auth::user()` is mechanically the cheapest way to get the user.
- Constructor-DI for the current user requires deciding *who provides it* — a singleton bound to the request lifecycle is unusual in Laravel because the framework's own auth API is facade-shaped.
- Livewire components' constructors are managed; they don't naturally take dependencies like Laravel controllers do (well, they can via `boot()` or method DI, but it's less idiomatic).
- The "I'll fix it later" mental model is fatal for an invariant — the arch test ratchets, and "later" means a 50-file refactor.

**How to avoid:**
- **Land `CurrentUserProvider` interface in `Modules\Core\Auth\` BEFORE the multi-user activation PR opens.** Signature: `interface CurrentUserProvider { public function user(): ?User; public function id(): ?int; public function require(): User; }`. Concrete implementation `LaravelAuthCurrentUserProvider` wraps the framework's auth (it's allowed to use facades *inside* — facades are forbidden in `Modules/`, not in `Modules/Core/Auth/Internal/`). The arch test allows facade use inside this single class via an explicit allowlist.
- **Bind in `Modules\Core\Providers\CoreServiceProvider` as scoped (per-request) singleton.** Livewire request lifecycle is the right boundary.
- **Arch test `MULTI-01` enforces:** no `use Auth;` / no `auth()` / no `Auth::` outside `Modules/Core/Auth/Internal/`. Existing v1.0 arch tests forbid these in all of `Modules/`; the multi-user phase extends them to `app/Http/`, `app/Livewire/`, all Volt SFC files, and seeders. The arch test ratchets: 0 violations is the only acceptable state.
- **Inject `CurrentUserProvider` into every Livewire component via `boot()` (Livewire 4 supports DI in `boot()`).** Volt SFCs use `inject()` in the inline class. Service classes take it as a constructor argument.
- **Per-module domain services that need "the owner of this entity" take `User $owner` as an explicit parameter, never reach for the current user.** This separates "this is the user acting" from "this is the user who owns the row" — they are not the same concept and conflating them is the root of cross-tenant leaks.
- **Land the `CurrentUserProvider` PR as Phase 1 of the MULTI milestone.** Zero auth activation. Just the contract + the binding + the arch test extension + a no-op implementation that returns the seeded user. This PR is small (~200 lines) and reviewable. Subsequent PRs (login UI, signup UI, partner activation) only add features against an already-established contract.

**Warning signs:**
- A Livewire mount() method that calls `Auth::user()` or `auth()->id()`.
- A migration seeder that does `Auth::loginUsingId(1)` to set up test data.
- The arch test `MULTI-01` has an allowlist longer than one class.
- A PR that touches 20+ files and adds `use Illuminate\Support\Facades\Auth;` to all of them — reject in code review, request `CurrentUserProvider` plumbing instead.
- `$request->user()` inside a controller — also a facade-shaped access; should be `CurrentUserProvider->user()` injected.

**Phase to address:**
**MULTI-01 (auth contract)** — the smallest, first multi-user phase. Lands the `CurrentUserProvider` + arch test. Subsequent multi-user phases (login, signup, profile selector, password reset) build on top.

**Test/check that proves it didn't happen:** `MULTI-01` arch test: `expect('Modules')->not->toUse(['Illuminate\Support\Facades\Auth', 'auth'])` with the single allowlist exception for `Modules\Core\Auth\Internal\LaravelAuthCurrentUserProvider`. Plus a runtime assertion in the failing-test reporter: any test that calls `actingAs()` without going through `CurrentUserProvider` fakes is flagged.

---

### Pitfall 5: A query forgets `->where('user_id', ...)` and the partner sees the user's data

**What goes wrong:**
v1.0 `UserIdColumnArchTest` enforces that every domain table has a `user_id` column + the `BelongsToUser` trait on the model. That's the *schema* invariant. It doesn't enforce *that queries actually filter by user_id*. In a single-user world this never mattered. In a multi-user world, `Transaction::where('posted_at', '>', $cutoff)->get()` returns the partner's transactions too — the schema is correct, the query is correct under Eloquent, the user sees data that isn't theirs.

Specific failure modes:
- A v1.0 service like `MonthAtAGlanceService::compute()` was written before multi-user activation; it calls `$this->transactions->newQuery()->where(...)` with no user scoping. Multi-user activates. Partner logs in. Sees the user's grocery spending.
- A Livewire `wire:click` handler accepts an integer transaction ID from the request and calls `Transaction::find($id)` (or `findOrFail`). No 404. Partner crafts a URL with another user's ID, sees the row.
- A queue job (e.g., `ResolveChainJob`) was serialized in v1.0 with a `transaction_id` only. Re-running it post-multi-user activation might match the wrong user's chain because it never had user context.
- A search query goes through `spatie/laravel-query-builder` and the AllowedFilters list forgot to scope to user.
- An export endpoint streams CSV of "all my transactions" but actually streams *all* transactions.

**Why it happens:**
- `BelongsToUser` trait exists but doesn't add a global query scope by default (v1.0 left it intentionally inert — the schema was multi-user-ready, the runtime was not).
- Eloquent has no compiler that says "you read a `BelongsToUser` model without filtering by user_id."
- Larastan level 10 doesn't know about user scoping.
- Tests written when there was one user always pass; they never had a second user to leak to.
- The "I forgot one query" is *exactly* the kind of failure that 1644 tests do not catch if those tests only ever have one user in the seed.

**How to avoid:**
- **`BelongsToUser` trait gains a `bootBelongsToUser()` method that registers a global scope** — `static::addGlobalScope('user', fn($q) => $q->where('user_id', app(CurrentUserProvider::class)->id()))`. The scope is *always on* for all reads. Writes pre-fill `user_id` from the provider in `creating` event.
- **Three escape hatches, each requiring an explicit, named, audited helper:**
  - `Transaction::withoutUserScope()` — used by background jobs that legitimately cross users (e.g., a "refresh exchange rates" job that touches FX-rate rows, which have `user_id = null` because they're global). Must be called with an explanatory comment; arch-test counts occurrences and flags PRs that add to the count.
  - `Transaction::forUser($userId)` — used by admin operations (e.g., "import data for partner during onboarding wizard"). Logs to a dedicated `cross_user_access` table for audit.
  - `Transaction::systemQuery()` — used by `diederik:doctor`, `db:backup`, and the cross-user data integrity probes. Whitelisted to a fixed set of caller classes via stack-frame check.
- **Arch test `MULTI-02`:** any Eloquent model that uses `BelongsToUser` must register the global scope; the scope must be present in every test boot; the three escape hatches are the only ways to read across users.
- **Cross-user 404 Pest test** — for every Livewire route that takes an ID parameter (transactions, recurring series, drift alerts, scenarios, chain candidates, OAuth connections, ingestion sources, etc.), a test that: creates two users, creates an entity owned by user A, hits the route as user B with user A's ID, asserts 404. **This is the single most important set of tests in the multi-user milestone.** The v1.0 `UserIdColumnArchTest` proves the column exists; this test set proves the *runtime* honors it.
- **Queue serialization includes `user_id`.** Every job class that operates on user-scoped data declares `public int $userId;` and the first line of `handle()` calls `$this->currentUserProvider->actingAs($this->userId)` to activate the scope for the job's duration. **No job dispatches without a user_id**, enforced by arch test `MULTI-03` (every job in `Modules/` that touches a `BelongsToUser` model has a `$userId` property).
- **`spatie/laravel-query-builder` configurations are reviewed once-and-for-all** — every `AllowedFilter::custom()` callback gets a default user scope; every `Search` falls back to scoped query.

**Warning signs:**
- A `find($id)` or `findOrFail($id)` on a `BelongsToUser` model in a controller / Livewire / job without a preceding user check.
- A test that creates one user and one transaction, never a second user.
- An export endpoint that streams transactions without an `auth->id()` filter (and remember: `auth()->id()` is forbidden — should be via CurrentUserProvider, see Pitfall 4).
- The "cross-user 404" test list is shorter than the "Livewire route with an ID parameter" list — any gap is a hole.
- Queue jobs deserialized after a user logout still complete successfully (a smoking gun that user scope is from request-time facade, not job-time property).

**Phase to address:**
**MULTI-02 (data isolation)** — second phase of multi-user, lands `BelongsToUser` global scope + the three escape hatches + the cross-user 404 test set. Must precede MULTI-03 (login UI) so login simply activates an already-enforced isolation, doesn't introduce it.

**Test/check that proves it didn't happen:** The cross-user 404 test set (one test per route) + a meta-test that asserts the test set's coverage matches the route list (`expect(routes_with_id_param())->toEqual(cross_user_404_tests())`).

---

### Pitfall 6: `.env` ships inside the bundle with the developer's secrets

**What goes wrong:**
The developer runs the NativePHP build command. Their local `.env` contains the real `APP_KEY`, the real Gmail OAuth client secret, the real Microsoft Graph client secret, the real Sentry DSN if any, and possibly the IMAP password from the early ddeboer days that never got cleaned up. Electron's default packaging copies the working directory verbatim. **The released `.dmg` / `.exe` contains the developer's secrets, downloadable by anyone with the GitHub Releases link.** Compounded: the bundled APP_KEY means every installer signs the same session cookies, so a malicious user could craft a session cookie that's trusted by another user's install.

**Why it happens:**
- Electron packaging is "ship the directory" by default. NativePHP improves on this but the developer must explicitly tell it which paths to exclude / replace.
- The developer's working `.env` is the most-recently-edited file; "build now, fix the env story later" is the natural development order.
- The fact that v1.0 chmod-600'd `.env` doesn't help — chmod 600 is about local-filesystem access, not about bundle inclusion.
- The DI-only rule helped here (no helper reads `env()` at runtime — Laravel best practice anyway, only `config()` reads), but `.env` is still read at boot for `APP_KEY` and connection strings.

**How to avoid:**
- **Bundle-time `.env` is a generated artifact, not a copy.** A build step (`php artisan diederik:build --target=desktop`) writes a fresh `.env.bundled` to `dist/` with:
  - `APP_KEY` is *not* set — the desktop boot script generates a fresh one per-install on first run and writes it to `Application::storagePath()/.env.local`, which is read on subsequent boots. Each user install has a unique APP_KEY.
  - `OAUTH_GMAIL_CLIENT_ID` and `OAUTH_GMAIL_CLIENT_SECRET` — for Gmail OAuth to work *on the user's machine*, the OAuth client credentials *do* need to ship in the bundle (they identify the *app*, not the user's account). This is acceptable per Google's "installed app" OAuth flow, which is explicitly designed for desktop apps where the client secret isn't truly secret. Document this in SECURITY.md: "the OAuth client secret in the bundle is the app's identity, not a per-user credential." Use a dedicated "Diederik Desktop" Google Cloud project + Microsoft Entra app registration with restricted scopes.
  - `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`.
  - `DB_CONNECTION=sqlite` with no `DB_DATABASE` set (NativePHP resolves it — see Pitfall 1).
  - No Sentry DSN, no Mailtrap credentials, no anything from the developer's local `.env`.
- **Build script reads from `build/env.bundled.template`** (committed) instead of from `.env`. The template has placeholders that the build script fills with OAuth client IDs from GitHub Actions secrets.
- **GitHub Actions: OAuth client secrets stored as repository secrets**, never echoed in workflow logs (`echo '::add-mask::$OAUTH_GMAIL_CLIENT_SECRET'`). Build step writes them into the `.env.bundled` before packaging.
- **Build step verifies absence of secrets in the build output.** `gitleaks scan` or equivalent against `dist/` before signing. Fail the build if any high-entropy string matches a known secret pattern that isn't a permitted OAuth client ID.
- **Reject the bundle if `.env` (vs `.env.bundled`) is present.** Final check before signing: assert no file in the bundle is exactly named `.env` or contains the developer's IMAP credentials by accident.
- **Per-install APP_KEY generation must be atomic.** First-run script generates the key, writes it, then loads Laravel. Two simultaneous first launches on the same machine race; use a file lock during generation. (Rare, but the partner installing from a USB stick while the developer is testing is a real scenario.)

**Warning signs:**
- The build pipeline references `.env` directly (instead of `.env.bundled`).
- The bundled `.env` contains a hardcoded `APP_KEY=base64:...`.
- The OAuth client secret was inherited from the v1.0 setup instead of being a new "Diederik Desktop" credential set.
- `gitleaks scan dist/` finds matches and the build doesn't fail.
- Two installs of the desktop app on the same machine share session cookies (APP_KEY isn't per-install).
- The OAuth consent screen on Google says "diederik-dev" (the developer's personal project) instead of "Diederik Desktop".

**Phase to address:**
**PKG-04 (build & env hardening)** — the phase that wires up the GitHub Actions release workflow. Must land before the first signed build hits a GitHub Release.

**Test/check that proves it didn't happen:** GitHub Actions step that runs `gitleaks scan dist/` + a custom artisan command (`diederik:audit-bundle dist/Diederik.dmg`) that opens the bundle, lists files, and asserts no `.env` / no high-entropy strings outside the permitted OAuth-client-ID allowlist.

---

### Pitfall 7: Password reset wizard works locally with Mailtrap, ships dead because no SMTP

**What goes wrong:**
Multi-user activation includes "forgot password." Laravel Fortify scaffolds the standard email-based reset flow — generate a token, send a reset link email, user clicks link, resets password. The developer wires it up against Mailtrap or local mail catcher (Herd Pro has one), it works perfectly. Ship.

On the partner's machine, there is **no SMTP**. The privacy promise forbids "phone home" reset via a Diederik-operated SMTP relay. Sending mail through the user's own Gmail/Microsoft via their OAuth scopes is technically possible but requires `gmail.send` scope (more invasive than current `gmail.readonly`) and a complete UX for "we'll send a reset email to yourself" that the partner will find baffling.

Result: partner forgets password, clicks "Reset", sees "We've sent you an email" message, no email arrives (the queued job logs `MailFailed`), partner is locked out of their financial data, calls the developer.

**Why it happens:**
- The reset-via-email pattern is the Laravel default and "looks done" instantly.
- The user-to-user threat model is "partner sharing the same machine", which doesn't need SMTP. But the Fortify defaults assume internet auth.
- "I'll add a different reset path later" is fine in principle and forgotten in practice.

**How to avoid:**
- **No SMTP-based password reset in the desktop build, period.** Three alternative paths, all of which work offline:
  - **(A) Recovery code at signup.** When a user creates their account, the signup wizard shows a 12-word recovery phrase (BIP-39 wordlist or similar), demands the user confirm they've written it down or saved it to a password manager, and stores a hash of it in the DB. "Forgot password" prompts for the recovery phrase, validates against the hash, lets the user set a new password. Standard pattern in offline-first apps.
  - **(B) Owner-resets-partner.** The first user (the developer) is the "admin" account; they can reset any other user's password from a settings page. Documented as "ask your partner to reset it for you." Works for the partner-sharing scenario.
  - **(C) Recovery via local-file proof.** "Forgot password" prompts for the recovery code (printed to a file at `Application::storagePath()/.recovery-code` at signup, chmod 600, owner-only). The file is left visible to the user with the message "save this somewhere safe." Cheaper than BIP-39, less robust.
- **Recommend (A) + (B) together.** Recovery code is the primary path (BIP-39 phrase shown once at signup, hash stored); (B) is the fallback for when the partner can't find their recovery code but the developer can log in. (C) is rejected — a file on disk is a phishing target.
- **Fortify configuration disables the email reset path entirely.** `Fortify::resetUserPasswordsUsing()` is not bound; the reset routes are removed from the route file via `Fortify::ignoreRoutes()`; custom routes installed that route to the recovery-code UI.
- **The "we'll send you an email" UX never appears.** The signup wizard explicitly says "There is no password recovery via email — write down your recovery phrase." The "forgot password" page explicitly says "Enter your recovery phrase. We cannot email you a reset link — this app runs entirely on your computer."
- **Document in onboarding wizard + SECURITY.md.** Partner's first-launch flow includes "what happens if I forget my password" as a step.

**Warning signs:**
- `config/mail.php` has any real driver set (`smtp`, `mailgun`, `ses`) in the bundled config.
- `Fortify::resetUserPasswordsUsing()` is bound.
- Route file has `password.email` / `password.reset` routes.
- Onboarding wizard doesn't show a recovery phrase.
- The signup happy-path test doesn't assert the recovery phrase is presented.

**Phase to address:**
**MULTI-04 (login/signup UI)** — the phase that wires up Fortify. Must explicitly remove the email-reset routes and add the recovery-code UI in the same PR.

**Test/check that proves it didn't happen:** Pest test that hits `POST /password/email` (the email-reset route) and asserts 404. Pest test for the recovery-code happy path: signup → log out → forgot password → enter phrase → set new password → log in with new password.

---

### Pitfall 8: PHP JIT + Hardened Runtime fight, app crashes on first launch after notarization

**What goes wrong:**
The macOS build is signed, notarized, stapled, the user downloads the `.dmg`, drags to Applications, double-clicks. Gatekeeper green-lights the binary. The app launches. The Electron splash screen appears for 200ms. Then the app crashes. The crash log says `EXC_BAD_ACCESS` from `phpsetjit` or `pcre2_jit_compile`.

Cause: Apple's Hardened Runtime is mandatory for notarization. It forbids creating writable + executable memory pages (the W^X rule). PHP's PCRE JIT and Opcache JIT both allocate W+X pages — that's how JITs work. The bundled PHP binary inside the NativePHP app crashes the moment it hits any regex-heavy code (which is every Laravel boot — service container resolution touches regex constantly).

**Why it happens:**
- Hardened Runtime is required for notarization (no opt-out).
- W^X is a Hardened Runtime default; you must explicitly grant an entitlement to relax it.
- The exact entitlements you need:
  - `com.apple.security.cs.allow-jit` — for PCRE JIT and Opcache JIT.
  - `com.apple.security.cs.allow-unsigned-executable-memory` — for any executable memory pages PHP allocates without code-signing them.
  - `com.apple.security.cs.disable-library-validation` — required because PHP's bundled extensions (`pdo_sqlite`, `mbstring`, `openssl`, `curl`) are dynamically loaded `.so`/`.dylib` files that aren't signed by Apple (they're built by NativePHP/Herd). Without this, dyld refuses to load them.
- Each of these entitlements is a security relaxation; document why each is needed.

**How to avoid:**
- **`build/entitlements.mac.plist`** committed to the repo, referenced from the electron-builder / NativePHP build config. Contents (the three entitlements above, all `true`).
- **First-launch smoke test in CI** — every signed-and-notarized macOS build runs in a clean macOS GitHub Actions runner via `open -W Diederik.app && grep -q 'started' Diederik.app/Contents/Resources/storage/logs/laravel.log`. Fail the build if the app crashes within 10 seconds of launch. This catches the JIT issue *in CI*, not on the partner's machine.
- **`opcache.jit=disable` and `pcre.jit=0` in `php.ini.bundled`** as a defensive layer. If a future entitlement gets revoked or notarization rules tighten, the app still boots (slower, but boots). The 5-10% perf hit from disabling Opcache JIT is irrelevant for a single-user dashboard. PCRE JIT off is a similar small hit. **Recommended:** ship with JITs disabled, even though entitlements would allow them. Less attack surface, fewer failure modes, easier to reason about. Re-enable only if a real perf bottleneck is measured.
- **Windows equivalent: DEP (Data Execution Prevention).** Less commonly an issue but document. The default Windows ACL on the install path must allow the bundled PHP binary to execute — installer should run as user, not admin, to keep the install path under `%LOCALAPPDATA%\Programs\Diederik\` (writable by the user).
- **Linux: AppImage / .deb don't have an equivalent runtime restriction.** No-op.

**Warning signs:**
- The macOS build is signed but the app crashes on launch.
- The crash log mentions `__pthread_jit_write_protect_np` or `mprotect` or PCRE/JIT.
- The build pipeline doesn't include a "launch the app and check it boots" step.
- `opcache.jit` is unset or `1255` in the bundled `php.ini`.

**Phase to address:**
**PKG-05 (macOS signing & entitlements)** — the phase that adds Apple Developer ID + notarization. Must land entitlements file + the disable-JIT defaults + the first-launch smoke test.

**Test/check that proves it didn't happen:** GitHub Actions macOS job — after notarization + stapling, opens the `.app` on the runner with `open -W`, asserts non-zero exit if crash, asserts log file contains the Laravel "started" line. Similar for Windows: `Start-Process Diederik.exe -Wait` + check log file.

---

### Pitfall 9: Auto-update flow trusts the downloaded `.dmg` without verifying the signature

**What goes wrong:**
electron-updater downloads the new `.dmg` from GitHub Releases over HTTPS, swaps the old app for the new one, restarts. By default electron-updater *does* verify the code signature on macOS — but only against the publisher identity stored in the *currently-running* app's signing info. If the running app was tampered with (its `Info.plist` swapped to point at a different update feed, or its `latest-mac.yml` URL hijacked), the verification can be trivially bypassed.

Specific failure modes:
- A malicious actor compromises the developer's GitHub account, uploads a malicious `.dmg` to a *new* release tagged `v2.0.1`, signs it with a *different* Apple Developer ID. Default electron-updater on the partner's machine sees the signature is valid (Apple-issued cert chain) but doesn't check it's the *expected* publisher. Update installs. Game over.
- The update server's `latest-mac.yml` says `sha512: ...` — but electron-updater historically had bugs where the SHA512 check could be skipped if the signature check "passed."
- The user runs `Diederik.app` from a path that has been replaced by a sideloaded malicious copy; the updater happily updates the malicious copy.

**Why it happens:**
electron-updater's defaults are "safe enough for most apps" but not "safe for an app with full read access to the user's bank statements." The threat model for diederik is closer to a password manager than to a generic dev tool.

**How to avoid:**
- **Pin the publisher identity check.** electron-updater's `publisherName` config in `package.json` lists the *exact* Apple Developer ID + Windows publisher name. Any update signed by a different cert fails verification, regardless of cert validity. Documented at electron.build/auto-update under `publisherName`.
- **Add Ed25519 signature verification on top of code signing.** Generate an Ed25519 keypair offline, embed the public key in the bundled app at build time, sign each release `.dmg` / `.exe` with the private key (stored in a hardware token, never in CI). electron-updater can be extended via `verifyUpdateCodeSignature` to require the Ed25519 signature too. This is the [Doyensec SafeUpdater](https://github.com/doyensec/ElectronSafeUpdater) pattern; recommended for high-trust apps.
- **`latest-mac.yml` + `latest.yml` URLs** point at GitHub Releases (CDN-distributed) but the URL is hardcoded into the bundled config — *not* derived from a runtime field that could be overwritten.
- **Auto-update is opt-in for the first version.** The first install asks "automatically install updates? (recommended yes)" and stores the preference in `Application::storagePath()/.update-preference`. Partner sees this; can say no.
- **"Skip this version" UX** — when an update is available, three buttons: "Install now", "Install on next launch", "Skip this version" (records the version in `.skipped-versions`, never prompts for that version again). Mandatory because a bad update should be rejectable without uninstalling the auto-updater.
- **Partial-download corruption guard.** electron-updater handles this via SHA512 in `latest.yml`, but explicitly verify the SHA matches *before* swapping the app, and *after* download but *before* execution. Two checks because the download could be tampered with in motion (rare with HTTPS) but the SHA in `latest.yml` could be wrong (your build pipeline generated it incorrectly).
- **Documentation in SECURITY.md:** "If you cannot update via the in-app updater, do not download a `.dmg` from anywhere except `github.com/diederik/diederik/releases`. Verify the SHA256 by running `shasum -a 256 ~/Downloads/Diederik-2.0.1.dmg` and comparing against the SHA published in the release notes." Belt-and-braces for the worst-case.

**Warning signs:**
- electron-updater config has no `publisherName` pinned.
- No Ed25519 signing in the release workflow.
- The "skip this version" button is absent.
- Updates auto-install without user confirmation on first install.
- SECURITY.md doesn't mention how to verify an installer manually.

**Phase to address:**
**REL-02 (auto-update)** — the phase that wires up electron-updater. Must include publisherName pin + Ed25519 signing + skip-version UX in the same PR. Signing key generation is its own ceremony (offline, hardware token, recovery copy in a safe deposit box) — document the key custody process in SECURITY.md.

**Test/check that proves it didn't happen:** End-to-end auto-update test on a sacrificial GitHub Releases repo: build `v2.0.0`, install on a runner, publish `v2.0.1` signed correctly → assert update installs. Publish `v2.0.2` signed with a wrong publisher → assert update rejects + alerts user. Publish `v2.0.3` with a tampered SHA512 → assert update rejects.

---

### Pitfall 10: Dev Console exposes destructive artisan to anyone with a session

**What goes wrong:**
The DEVUI milestone adds an in-app "Developer Console" — list artisan commands, run them, show output. The developer wants to ship `db:backup`, `diederik:doctor`, `diederik:failed-jobs prune`, the chain-resolver inspection commands, etc. They also accidentally allow `db:restore`, `migrate:fresh`, `migrate:fresh --seed`, `cache:clear`, `tinker` (interactive), `down` (maintenance mode).

Now the partner — who is not a developer — opens "Settings → Developer Mode" out of curiosity (or because the UI hint says "tap 7 times here for hidden options"), clicks `db:restore`, picks a backup file from yesterday, and silently destroys today's transactions. Or worse, clicks `migrate:fresh --seed` and erases everything, replacing it with seed data. Or pastes a recovery code into `tinker` to "see if it works" and the recovery code now sits in their shell history.

Specific failure modes:
- Dev Mode is on by default in the bundled config (because dev/test had it on).
- The "destructive command" whitelist is the *block*list (default-allow), not the allow-list (default-deny).
- The UI for running an artisan command interpolates the arguments into a shell string and passes through `shell_exec()` — classic command injection (the partner pastes `; rm -rf ~` and the app dutifully executes it; the partner thought they were typing a comment).
- The Dev Mode toggle is per-session (not persisted), so a UI bug that flips it on doesn't get caught by "I would have noticed yesterday."

**Why it happens:**
- "It's a Dev Console, of course it's powerful" is the developer's mental model. The partner's mental model is "this looks like an advanced settings page."
- The shell-escape vulnerability is the default if you reach for `shell_exec`. `Symfony\Process` with array-syntax is safe; string-syntax through a shell is not.
- A "Dev Mode" flag that defaults to true (because dev needs it) is the most common mistake; defaulting to false everywhere is correct.

**How to avoid:**
- **Two artisan classes: read-only and destructive. Defaults differ.**
  - **Read-only Dev Console** — *Always* available (no toggle, no flag). Exposes a fixed allowlist of commands: `diederik:doctor`, `db:backup`, `diederik:failed-jobs list`, `queue:work --once --queue=...` (manual job retry), `route:list`, `config:show`, `about` (Laravel's `about` command). Each command's *output is captured*, *arguments are pre-defined* (form fields with hardcoded values; no free-text shell input), and the user role required is "owner" (the developer, not the partner).
  - **Destructive Dev Console** — Behind a "Developer Mode" toggle that is:
    - Off by default in the bundled `.env.bundled`.
    - Persisted to `users.developer_mode_enabled` (boolean column, per-user, default false). Activated only via a settings page that requires re-entering the password.
    - Off for any non-owner user, regardless of toggle state. The partner cannot enable it; only the user marked `is_owner = true` (set at signup for the first user) can.
    - When enabled, displays a persistent red banner: "Developer Mode is on — destructive commands are accessible. [Disable]"
    - Exposes a small allowlist (`db:restore`, `migrate:rollback`, `cache:clear`, `route:clear`, `diederik:failed-jobs prune`). Still allowlist, not blocklist. Explicitly excludes: `migrate:fresh`, `migrate:fresh --seed`, `tinker`, `db:wipe`, `down`, `up`, anything that runs raw SQL.
  - **No "free-text command runner".** The "run any artisan command" wish is fulfilled by editing the allowlist in code + redeploying. Never an in-app text field.
- **`Symfony\Process` with array-style arguments only.** Arch test `DEVUI-01`: forbid `shell_exec`, `exec`, `passthru`, `proc_open`, `system`, `popen` in `Modules/`. Process construction uses `new Process(['php', 'artisan', $allowlistedCommand, ...$predefinedArgs])`.
- **`Modules\Core\DevConsole\AllowedCommands` is the single source of truth** — an enum / value object listing commands + their per-command argument schemas (typed, validated) + their required user role. The UI iterates this list; the runner re-validates against this list before executing. Never trust the request.
- **Audit log.** Every Dev Console execution (read-only or destructive) writes to a `dev_console_executions` table: user_id, command, args, exit_code, started_at, output_hash. Visible in a "Recent activity" panel. If the partner ever does run a destructive command (perhaps the owner left Dev Mode on), the developer can see it.
- **The SQLite read-only query runner is read-only by *PRAGMA*, not by promise.** The Dev Console's "run SQL" feature (if it exists at all) opens a separate connection with `?mode=ro` URI parameter, so `INSERT` / `UPDATE` / `DELETE` / `DROP` are rejected by SQLite itself. Bonus: row limit enforced (`LIMIT 10000` appended if missing); query timeout enforced via `PRAGMA busy_timeout=5000`.

**Warning signs:**
- A controller / Livewire handler that accepts a string and passes it to `Process` (or worse, `shell_exec`).
- The `developer_mode_enabled` column doesn't exist (so it's a global flag, not per-user).
- The bundled `.env` has `APP_DEBUG=true` or any dev mode default-on.
- The Dev Console UI is reachable by the partner with default config.
- The audit log table doesn't exist.
- A test for "partner cannot enable developer mode" doesn't exist.

**Phase to address:**
**DEVUI-01 (developer console scaffolding)** — first DEVUI phase. Lands the allowlist + the audit log + the owner-only restriction + the arch test. Subsequent DEVUI phases (log viewer, queue inspector) build on top.

**Test/check that proves it didn't happen:** Pest test: log in as a non-owner user, attempt to GET `/developer/console` → 403. Log in as the owner with Dev Mode off, attempt to POST `/developer/run` with `db:restore` → 403 + banner-not-shown. Log in as the owner, enable Dev Mode (re-enter password), POST `/developer/run` with `db:restore` → 200, command executes, audit log row written. Plus the shell-escape test: POST with `; rm -rf` in any argument field → 422 validation error, no Process spawned.

---

### Pitfall 11: Log tailing exposes Bearer tokens in stack traces to the partner

**What goes wrong:**
The Dev Console adds a "tail logs" feature that streams `storage/logs/laravel.log` to the UI. Useful for debugging "why didn't my Gmail scan run?" Except: when the OAuth refresh fails, the exception stack trace contains the full `Authorization: Bearer ya29.a0AfH6SMB...` header in the request data dumped by Laravel's exception handler. When Microsoft Graph throws a 401, the response body containing the user's email address + tenant ID gets logged. When IMAP-idle was still running pre-v2.0, the connection string might have included credentials.

The partner, debugging their Gmail connection, sees a log line containing the Bearer token. They screenshot it to send to the developer for help. Now the token is in iMessage / WhatsApp / email — all places that scan and index content. A Bearer token leaked is an account compromised, even if the user can revoke it.

**Why it happens:**
- Laravel's default exception handler dumps `request->all()` and `request->headers->all()` into the log entry for HTTP exceptions.
- OAuth libraries (`google/apiclient`, `microsoft/microsoft-graph`) include the access token in the request they construct; an exception from the HTTP layer can serialize the request.
- A "tail the log file" feature has zero context about which lines are sensitive.
- The partner is not a developer; they don't know "Bearer tokens shouldn't be shared."

**How to avoid:**
- **Log redaction at write time, not at read time.** Custom Monolog processor in `Modules\Core\Logging\SensitiveDataRedactor` that scrubs:
  - Authorization headers (`Authorization: Bearer .*` → `Authorization: Bearer [REDACTED]`).
  - OAuth response bodies (`access_token`, `refresh_token`, `id_token` → `[REDACTED]`).
  - IMAP credentials (any string after `password=` in connection strings).
  - The user's IBAN (regex matches `[A-Z]{2}\d{2}[A-Z0-9]{0,30}` — risk: false positives on country codes; accept).
  - Email addresses other than the configured user emails.
- **Bound the log lines exposed to the Dev Console.** Last 1000 lines, never the full file. Older lines viewable only by opening `storage/logs/laravel.log` in a text editor outside the app — the redaction is at *write* time so even the file has redacted versions.
- **`Application::storagePath()/logs/` permissions chmod 600 owner-only.** The partner's user account on the same machine cannot read the file via Finder.
- **The "Copy log to clipboard" / "Share log" feature** (which will be a feature, because debugging) runs a *second* pass of redaction, doubly scrubbing. Show the user a preview before copying — "About to share 47 lines, sensitive data has been redacted, please verify." Make the preview the last line of defense.
- **Pest test corpus:** craft synthetic log lines containing fake Bearer tokens, fake refresh tokens, fake IBANs; pass through the redactor; assert all are scrubbed. Run this in CI.

**Warning signs:**
- The log file (any of `storage/logs/laravel.log` in dev, `Application::storagePath()/logs/laravel.log` in prod) contains a Bearer token without `[REDACTED]`.
- The Dev Console UI shows raw log lines (no redaction filter).
- An exception handler dumps `request->all()` without filtering.
- The "Share log" feature exists without a preview-before-send step.

**Phase to address:**
**DEVUI-02 (in-app log viewer)** — separate phase from the console runner. Lands the Monolog processor + redaction tests + the secure share flow.

**Test/check that proves it didn't happen:** Pest test that triggers a real OAuth 401 (against a mock) and asserts the resulting log entry contains no Bearer token / refresh token. Plus a CI grep on the actual `storage/logs/laravel.log` after the test suite runs: zero matches for `Bearer [a-zA-Z0-9._-]{30,}`.

---

### Pitfall 12: Pest --parallel + SQLite + GitHub Actions = flaky CI

**What goes wrong:**
The v1.0 test suite is 1644 Pest tests with `pest --parallel`. On the developer's M-series Mac, parallel execution distributes tests across cores and the SQLite files are isolated per worker — works fine. On GitHub Actions' shared runners, the filesystem is slower, `pest --parallel` spawns 2-4 workers depending on the runner spec, and the SQLite `BUSY` errors start appearing intermittently. Some tests fail with `database is locked` even though the code is correct.

Compounded: a test that uses `RefreshDatabase` against a *file* SQLite (instead of `:memory:`) plus a test in a different worker that runs `db:backup` against the same file = race. A test that depends on `now()` and runs at 23:59:59 UTC while another test runs at 00:00:00 the next day = day-boundary flaky.

**Why it happens:**
- SQLite is a single-writer DB; WAL helps but `pest --parallel` workers each open their own connection and contention is real on slow disks.
- GitHub-hosted runners are not in Europe/Amsterdam (the project's canonical timezone) — they're in `UTC`. The project's many Carbon-dependent tests (forecast horizon, recurring cadence detection, day-of-month drift) implicitly assume Amsterdam-time, which means: tests that pass at 14:00 CET fail at 23:00 UTC (which is 01:00 CET, a different day).
- Pest's parallel-by-default doesn't make this discoverable in dev; the developer's Mac is in Amsterdam, the runners are in UTC, and the discrepancy only shows up when CI fails for the first time at the wrong hour.

**How to avoid:**
- **Set `APP_TIMEZONE=Europe/Amsterdam` in the test bootstrap** (`tests/Pest.php` or `tests/CreatesApplication.php`). This forces all Carbon `now()` calls to Amsterdam regardless of runner timezone. Document why in a comment.
- **CI runners explicitly set `TZ=Europe/Amsterdam` env var.** GitHub Actions step at the start of the job. Belt-and-braces for any code that reads the OS timezone directly.
- **Tests that depend on "today" use `Carbon::setTestNow()` with a fixed date.** Never let real-clock-time leak in. Arch test optional: forbid `now()` (helper) in tests (it's already forbidden in production code — the test directory should match). Allow `CarbonImmutable::now()` only when explicitly fixed via `setTestNow`.
- **`pest --parallel` workers each get their own SQLite file**, not a shared one. Pest 4 supports `--processes` (defaults to CPU count); each process can have a unique `DB_DATABASE` via the `LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES` flag (Laravel 13 supports this). Verify it's on. If a flaky `database is locked` still surfaces, drop to `--processes=2` on CI (slower but stable). Don't try to "fix" SQLite parallelism — it can't be fixed at the SQLite level.
- **`:memory:` for unit tests that don't need file persistence.** Per-module unit tests that only test pure logic (DTOs, value objects, calculators) use `:memory:`. Feature tests that exercise migrations + queries use file-backed parallel-isolated DBs.
- **CI build matrix is narrow on PR, wide on tag.** PR: single OS (`ubuntu-latest`) + single PHP (8.5) + single Node (whatever NativePHP needs). Full matrix (Ubuntu / macOS / Windows × PHP / Node combos) runs *only* on tag push (= release builds). Cuts CI time per PR from ~25 min to ~6 min and prevents the partner-merge anxiety of "CI is taking forever."

**Warning signs:**
- `phpunit.xml` doesn't set `APP_TIMEZONE`.
- GitHub Actions workflow has no `TZ:` env var.
- A test uses `Carbon::now()->format(...)` without `setTestNow`.
- Tests pass locally + fail on CI with `database is locked`.
- Tests pass locally + fail on CI with off-by-one-day assertions.
- The PR build matrix has 6 jobs and takes 25+ minutes.

**Phase to address:**
**CI-01 (PR gates)** — the first CI phase, lands the narrow PR matrix + the timezone bootstrap + the parallel-DB-isolation flags.

**Test/check that proves it didn't happen:** Run the test suite 3 times consecutively in CI; require all 3 green. The first run that goes red on a transient `database is locked` is the canary; the workflow must be `concurrency: cancel-in-progress: false` so the 3-times check actually catches flakes.

---

### Pitfall 13: Code-signing secrets exposed to forked PRs

**What goes wrong:**
The project goes public on GitHub. A contributor opens a PR from a fork (the default for external contributions). The PR workflow runs and (because the developer set things up quickly) it has access to the Apple Developer ID certificate, the notarization API key, the Windows EV signing cert — because those secrets were added at the repo level, not at the environment level, and the workflow doesn't distinguish PRs-from-forks from PRs-from-branches.

GitHub Actions' default behavior is actually **safer than this** — `secrets` are not exposed to workflows triggered by `pull_request` from a fork. But: if the workflow uses `pull_request_target` (which runs in the context of the base branch and *does* expose secrets), or `workflow_run` (which can chain to fork builds), the protections are bypassed. A malicious PR that modifies the workflow file to `cat $APPLE_CERTIFICATE_BASE64 > poisoned-artifact.txt` and uploads it could exfiltrate the cert.

**Why it happens:**
- `pull_request_target` is sometimes recommended for "auto-label PRs" workflows; it's tempting to use everywhere.
- The signing secrets are big base64 strings; their exposure is total compromise of the signing identity.
- Apple Developer ID revocation is a multi-day process; re-issuance interrupts releases.

**How to avoid:**
- **PR workflows only run quality gates** (Larastan, Pint, Pest). They never have access to signing secrets. Use `pull_request:` trigger, not `pull_request_target:`.
- **Release workflow runs only on tag push** (`on: push: tags: ['v*']`). Has access to signing secrets via a GitHub Environment (`environments: signing`) with required reviewers — the developer must manually approve each release run.
- **Signing secrets stored in GitHub Environment `signing`**, not repo-level. The environment is configured: "only the `release.yml` workflow on tag pushes can access this environment." Branch protection rules require manual approval for the environment.
- **Workflow files in `.github/workflows/` are protected** via CODEOWNERS — only the developer can modify them. PRs that modify workflow files trigger a CI step that comments "this PR modifies a workflow file; review carefully."
- **Signing secrets rotate annually** — Apple Developer ID certs are 5-year, but rotate the export password annually. Windows EV cert is hardware-backed (USB token or HSM); the token is physically with the developer, never in CI. Use a [GitHub-hosted signing service like SignPath / Azure Key Vault](https://signpath.io/) for Windows EV instead of base64'ing the cert into a secret. Apple notarization via the App Store Connect API key is fine in a secret (it's revocable in seconds via Apple's website).
- **Cert exposure on CI logs:** every signing step starts with `echo '::add-mask::$APPLE_CERTIFICATE_BASE64'` to mask the value. The base64 is decoded inside a single Bash step into a `security`-imported keychain, then the decoded file is shredded. Nothing persists.

**Warning signs:**
- Any workflow file has `on: pull_request_target:`.
- The signing secret is at the repo level, not environment level.
- The release workflow runs on push to `main` (not on tag push), so every merge could attempt to sign.
- A PR from a fork has access to the signing secret (test by submitting a PR that prints `${{ secrets.APPLE_CERTIFICATE_BASE64 }}` — should print empty).
- CODEOWNERS doesn't protect `.github/workflows/`.
- The Windows EV signing happens in-CI with the cert in a secret (instead of via a hardware token signing service).

**Phase to address:**
**CI-02 (release pipeline & secrets)** — the phase that wires up the tag-triggered release workflow. Must land Environment-scoped secrets + CODEOWNERS + the no-`pull_request_target` rule.

**Test/check that proves it didn't happen:** Submit a sacrificial PR from a fork that tries to `echo ${{ secrets.APPLE_CERTIFICATE_BASE64 }}` to a workflow log. Verify the secret is empty in the output (or that the workflow step is skipped entirely because the secret isn't available). Then verify the actual release workflow, on a tag, *does* see the secret.

---

### Pitfall 14: Hippocratic License 3.0 confuses every license-detection tool

**What goes wrong:**
The repo is published under Hippocratic License 3.0. The user expectations are reasonable — "anyone except entities that violate human rights can use this." The mechanical reality is messier:
- **GitHub's license picker doesn't list Hippocratic 3.0** as a standard option. The repo's `LICENSE` file is detected as "Other"; the license badge says "View license" without a name; contributors can't tell what they're agreeing to without opening the file.
- **OSI has not approved Hippocratic 3.0.** The repo cannot say "Open Source" in marketing copy without a footnote. Some companies' open-source policy scans (Tidelift, FOSSA, Snyk Open Source) flag Hippocratic as "Restricted — not OSI-approved" and refuse to vendor the dependency.
- **SPDX has Hippocratic 3.0 listed but as a non-OSI license** (`Hippocratic-3.0` identifier). Tools that consume SPDX (most license scanners) may surface warnings.
- **Contributors don't always read the LICENSE.** A drive-by contribution from someone whose employer has a "no copyleft, no ethical clauses" policy creates a CLA conflict the contributor didn't know existed.
- **The ethical clauses themselves** (no human-rights violations, anti-ICE clauses) are legally untested. There's no case law on whether they'd hold up in court. The clauses are real values; the *legal* enforceability is uncertain. Document this honestly.

**Why it happens:**
- Hippocratic 3.0 was designed to fail the OSD by intent (it discriminates against fields of endeavor — the OSD forbids this). The author of Hippocratic intentionally chose ethical clauses over OSI approval.
- The tooling ecosystem (GitHub, SPDX, OSI) is older and bigger than ethical-source. It doesn't auto-render unknown licenses gracefully.
- Diederik is a personal app; the "drive-by contributor" concern is low. But the project's stated goal is "any technical user can run, debug, and contribute end-to-end" — contribution friction matters.

**How to avoid:**
- **`LICENSE` file is the verbatim Hippocratic 3.0 text** (downloaded from firstdonoharm.dev). No modifications.
- **`NOTICE` file alongside LICENSE explains the trade-off:**
  - "This project is licensed under Hippocratic License 3.0."
  - "Hippocratic 3.0 is source-available and ethical-use-restricted; it is *not* OSI-approved."
  - "You may use, modify, and redistribute Diederik under the terms of Hippocratic 3.0. You may not use Diederik to violate human rights as defined in the United Nations Universal Declaration of Human Rights."
  - "If you are unsure whether your use complies, open an issue or contact the maintainer before deploying."
  - "Contributors: by submitting a PR, you agree to license your contribution under Hippocratic 3.0. No CLA is required."
- **README has a "License" section** that quotes the NOTICE summary + links to firstdonoharm.dev/learn.
- **`CONTRIBUTING.md` explicitly states** the licensing model + the implicit DCO-style agreement (your contribution will be Hippocratic 3.0).
- **GitHub repo settings:** Use the SPDX identifier `Hippocratic-3.0` in `composer.json` (`"license": "Hippocratic-3.0"`) so SPDX scanners pick it up. Add a topic `hippocratic-license` to the repo for discoverability.
- **Don't claim "Open Source" in the README.** Use "source-available" or "ethical source." Honest, accurate, avoids OSI-pedantry arguments.
- **Don't depend on Hippocratic-flagging tools for downstream users.** If a downstream user's company can't accept Hippocratic, that's a feature, not a bug — the license is doing its job. Document this in the README.

**Warning signs:**
- `composer.json` `"license": "proprietary"` (current v1.0 value!) — must update before public release.
- README says "Open Source" without qualification.
- No `NOTICE` file alongside `LICENSE`.
- `CONTRIBUTING.md` doesn't mention licensing.
- The LICENSE file is paraphrased or modified instead of verbatim Hippocratic text.

**Phase to address:**
**REL-01 (license + README + community files)** — the phase that handles public-release readiness. Lands LICENSE + NOTICE + CONTRIBUTING + CODE_OF_CONDUCT + SECURITY + the README rewrite with the SVG hero.

**Test/check that proves it didn't happen:** A simple CI script that asserts (a) `LICENSE` SHA256 matches the official Hippocratic 3.0 SHA, (b) `composer.json` license is `Hippocratic-3.0`, (c) `NOTICE` exists, (d) `README.md` references the NOTICE. Run on every PR.

---

## Moderate Pitfalls

### Pitfall 15: Session storage inside Electron defaults to a cookie domain that breaks `file://`

**What goes wrong:**
NativePHP serves the Laravel app via an embedded HTTP server bound to a random localhost port (typically `http://127.0.0.1:PORT`). Sessions work fine. But: if any internal route attempts to `redirect()->away('file://...')` (e.g., "open the backup folder in Finder"), or if a Livewire upload uses a `file://` URL, the session cookie's `domain` attribute (not set by default = current host) may not apply, and the user appears logged out.

Compounded: Laravel's session cookie defaults assume HTTPS in production (`secure=true` when `APP_ENV=production`). Inside Electron the app is on `http://127.0.0.1` — the secure flag would prevent the cookie from being set, silently logging out the user.

**Why it happens:**
- Cookie semantics on `localhost` + `127.0.0.1` differ between browsers; Electron's Chromium follows the strict path.
- `APP_ENV=production` is what you want for the bundled build (no debug, no dev tooling) but it activates `secure=true` cookie defaults.
- "It works in Herd" (which uses `https://diederik.test`) doesn't predict "it works in Electron" (which uses `http://127.0.0.1:RANDOM`).

**How to avoid:**
- **`session.secure_cookie = false` in the bundled `.env.bundled`**, explicit override. Document the reason in a comment: "Electron loopback HTTP server; SameSite=Strict + same-origin already prevents the XSS surface that secure=true defends against."
- **`session.same_site = 'strict'`** in the bundled config — defense in depth.
- **`session.domain = '127.0.0.1'`** — explicit; prevents the cookie from being bound to a wildcard that Electron's Chromium might reject.
- **File-system-open actions go through `Native::openWithFinder($path)`** (NativePHP's IPC bridge to the shell), not through `redirect()->away('file://...')`. The latter is broken anyway in modern Chromium; the former is a desktop-native operation.
- **CSRF stays enabled.** Some Electron tutorials suggest disabling CSRF because "it's all local." Don't. The Dev Console + multi-user + a malicious browser extension that hits `http://127.0.0.1:PORT` makes CSRF a real concern.

**Warning signs:**
- The user logs in, navigates to a page that triggers a redirect, and finds themselves logged out.
- The bundled `.env` has `SESSION_SECURE_COOKIE` unset (so it inherits the framework default).
- Any route returns `redirect()->away('file://...')`.

**Phase to address:**
**MULTI-04 (login/signup UI)** + **PKG-04 (env hardening)**.

**Test/check that proves it didn't happen:** Pest feature test: log in, hit 10 different routes including ones that redirect, assert session persists across all of them. Run inside a NativePHP-simulated env (env var set, base URL `http://127.0.0.1:PORT`).

---

### Pitfall 16: Windows installer doesn't request the right permissions, ends up writing to Program Files

**What goes wrong:**
The Windows installer (built via electron-builder's nsis target) defaults to a per-machine install under `C:\Program Files\Diederik\`. Standard Windows UAC kicks in: install requires admin. After install, the bundled SQLite + app data go to `%PROGRAMDATA%\Diederik\` — readable by every user on the box, including the kids who happen to share the laptop. Privacy fail.

Alternatively, the installer is configured per-machine but the bundled PHP tries to write to `Program Files` (not allowed for non-admin) and Laravel logs become unwritable.

**Why it happens:**
- electron-builder's NSIS target defaults to per-machine, which is wrong for a single-user privacy app.
- `%APPDATA%` is per-user roaming (sync'd to the user's Microsoft account if they're signed into OneDrive — privacy concern); `%LOCALAPPDATA%` is per-user local (better); `%PROGRAMDATA%` is all-users (worst for privacy).
- Default `electron-builder` config doesn't think about which it lands in.

**How to avoid:**
- **`electron-builder.yml`:** `nsis.perMachine: false` (per-user install, no admin needed). `nsis.oneClick: false` (user picks install path, defaults to `%LOCALAPPDATA%\Programs\Diederik\`).
- **NativePHP storage path = `%LOCALAPPDATA%\Diederik\` on Windows.** Verify via the AppPaths abstraction (Pitfall 1) that Application::storagePath() resolves there on Windows, not to `%APPDATA%` (roaming) or `%PROGRAMDATA%` (shared).
- **NTFS ACL on the storage folder:** at install time, the NSIS script sets the storage folder's ACL to "Current User: Full Control; SYSTEM: Read; Everyone: Deny." Belt-and-braces; the default `%LOCALAPPDATA%` ACL is already user-only, but explicit is safer.
- **Don't sync to OneDrive accidentally.** `%LOCALAPPDATA%` is *not* sync'd by OneDrive by default; `%APPDATA%` (roaming) *can be* depending on user settings. Pick `%LOCALAPPDATA%`.

**Warning signs:**
- The installer prompts for admin on install.
- Files appear in `C:\Program Files\` or `C:\ProgramData\`.
- The DB file shows up in OneDrive's "Recent" panel.
- Multiple Windows accounts can read each other's `database.sqlite`.

**Phase to address:**
**PKG-06 (Windows installer)** — paired with the macOS PKG-05.

**Test/check that proves it didn't happen:** Manual UAT on a fresh Windows VM: install Diederik, verify install path is `%LOCALAPPDATA%\Programs\Diederik\`, verify data path is `%LOCALAPPDATA%\Diederik\`, verify a second Windows user account cannot read the first user's `database.sqlite`.

---

### Pitfall 17: Dead code accumulated through 11 phases never gets removed because arch tests don't fail on it

**What goes wrong:**
The pre-release deep Modules code review is a manual sweep. The reviewer (developer + Claude) looks for:
- Cross-module leakage (already caught by `BoundaryArchTest`).
- DI violations (already caught).
- Float money (already caught by `NoFloatMoneyArchTest`).
- State-mutation bypass (already caught).

What's *not* caught:
- `Modules\Chains\OldFuzzyMatcher` from Phase 5 v1 that was replaced by `ConfidenceWeightedMatcher` in Phase 5 v2 but never deleted. Tested independently. No call sites in production code. Adds to the test count without adding to the value.
- A `try/catch` block in `Modules\Ingestion\AsnCamtParser` that handles a CAMT subversion (001.04) that ASN never emitted — the catch block was written defensively in Phase 2 and never triggered. Dead code is *worse* than no code because it adds maintenance burden and confuses future readers.
- A `wire:click` handler in a Livewire component that's no longer in the template (template was rewritten in Phase 8).
- A migration column added in Phase 3 (`transactions.deprecated_chain_hint`) that was used briefly, replaced by a richer table in Phase 5, and never dropped via a follow-up migration.

The arch tests passed. Larastan passed. Pest is 1644 green. The code is *correct*. It just has dead weight.

**Why it happens:**
- Deletion is harder than addition — fear of breaking something.
- 1644 tests include tests for the dead code, so deletion looks like "removing tests" which feels regressive.
- The deep Modules code review is mentioned in the v2.0 PROJECT.md but as a *qualitative* sweep; there's no mechanical check.

**How to avoid:**
- **Run a dead-code finder.** [`tomasvotruba/unused-public`](https://github.com/TomasVotruba/unused-public) (PHPStan extension) reports public methods that are never called outside their declaring class. Run as a one-off, review every match.
- **`composer require --dev rector/rector` + the dead-code set.** Rector's `DEAD_CODE` set detects unreachable methods, unused properties, always-true conditions, dead return statements. Run in `--dry-run` mode + review.
- **Database schema cleanup.** SELECT every column whose name contains `deprecated`, `legacy`, `old_`, `_v1`, etc. Cross-reference against production code references via grep. Drop via migration what's dead.
- **Pest coverage gate.** Run Pest with `--coverage` once; identify files with 0% coverage; cross-reference with non-test public APIs. Either: write a test (if it's live code), or delete (if it's dead).
- **Cross-module reference graph.** `composer require --dev shipmonk/composer-dependency-analyser`. Reports modules that declare a dependency on another module they no longer use. Cleans up `nwidart/laravel-modules` cross-deps.

**Warning signs:**
- A class hasn't been touched in the git log since the phase that introduced it, and no production reference exists.
- A test file `tests/Feature/OldFuzzyMatcherTest.php` exists but the matcher isn't dispatched anywhere.
- Schema columns with `deprecated` / `legacy` / `_old` in the name.

**Phase to address:**
**REL-04 (deep Modules review)** — pre-public-release sweep. Mechanically run all four checks above + manual review of every match.

**Test/check that proves it didn't happen:** CI step that runs `unused-public` + `rector --dry-run --set=DEAD_CODE` + `shipmonk/composer-dependency-analyser` after the review. Tolerate a *small* allowlist of intentional dead code (e.g., factory methods used by Pest dataset providers may be flagged); the allowlist must be reviewed each release.

---

### Pitfall 18: Beta cycle reveals the partner can't complete OAuth on Windows

**What goes wrong:**
The partner's first task on day 1 is connecting their Gmail. The OAuth flow:
1. App opens a browser to `https://accounts.google.com/o/oauth2/v2/auth?...redirect_uri=http://127.0.0.1:PORT/oauth/callback/google`.
2. Partner approves.
3. Google redirects to `http://127.0.0.1:PORT/oauth/callback/google?code=...`.
4. The app's embedded HTTP server receives the callback, exchanges the code, stores the token.

In Herd-dev land, this works because the app's HTTP server is bound to a known port (8000 or whatever the developer configured). In Electron land, the port is *random* (NativePHP picks a free port each launch). The OAuth redirect URI registered with Google is `http://127.0.0.1:8000/oauth/callback/google` — when the partner's app is on port 54321, Google rejects the callback ("redirect_uri_mismatch").

Workaround attempts that fail:
- "Register all ports 1024-65535 as valid redirect URIs" — Google rejects this; OAuth client config has a max URI count.
- "Use a wildcard URL" — Google rejects wildcards for OAuth.
- "Use `urn:ietf:wg:oauth:2.0:oob`" (out-of-band, copy-paste code) — Google deprecated this in 2022.

The Google-recommended pattern for installed apps is the RFC 8252 loopback IP scheme — but it requires *any* port on `127.0.0.1` to be acceptable, which Google's "Desktop app" OAuth client type explicitly supports.

**Why it happens:**
- The v1.0 OAuth setup used the "Web application" Google OAuth client type (because Herd is a web server). That client type requires fixed redirect URIs.
- The desktop build needs the "Desktop application" Google OAuth client type. That type accepts *any* loopback port.
- The v1.0 OAuth client ID and the desktop OAuth client ID **must be different**. Reusing the v1.0 web-app client ID is wrong because the redirect-URI semantics differ.

**How to avoid:**
- **Create a separate "Diederik Desktop" OAuth client** in Google Cloud Console with type "Desktop application". Same for Microsoft Entra (different "Public client" flow).
- **The bundled `.env.bundled`** has the desktop client ID; the developer's Herd `.env` has the web-app client ID. Two separate sets of OAuth secrets, two separate consent screens (which is fine — Google's "Diederik Desktop" and "Diederik Web" can both exist).
- **The OAuth callback handler** (`Modules\EmailScan\Http\OAuthCallbackController`) handles both `http://127.0.0.1:8000/...` (web-app variant) and `http://127.0.0.1:PORT/...` (desktop variant) — they share the same code path because the callback is the same regardless of which port answered.
- **The OAuth consent screen** for "Diederik Desktop" must be verified by Google for the requested scopes (`gmail.readonly`). Verification can take days. Start the verification request *before* the beta phase.
- **First-launch wizard tests OAuth on macOS *and* Windows** with the partner during beta UAT. Document each failure.

**Warning signs:**
- The bundled OAuth client ID is the same as the v1.0 dev OAuth client ID.
- Google Cloud Console shows the OAuth client type as "Web application" (not "Desktop application").
- The partner sees a "redirect_uri_mismatch" error on first OAuth attempt.
- The OAuth consent screen says "diederik-dev" (the developer's personal Cloud project).

**Phase to address:**
**MULTI-05 (per-user OAuth isolation)** + **PKG-04 (env hardening)** — the OAuth client provisioning is a manual GCP / Entra config step, documented in the phase plans.

**Test/check that proves it didn't happen:** Manual UAT — partner connects Gmail on macOS, then on Windows, then on a third machine. All three succeed without intervention.

---

### Pitfall 19: Queue worker doesn't survive the desktop app being closed and reopened

**What goes wrong:**
v1.0 runs `queue:work` via launchd, so the worker survives app close. Desktop v2.0: when the user closes the Electron window, what happens to in-flight jobs?
- (A) The worker dies mid-job. `ResolveChainJob` was halfway through writing the chain candidate; the row is partially updated; the next launch finds inconsistent state.
- (B) NativePHP's bundled queue worker keeps running in a hidden process even when the window is closed (because Electron's `BrowserWindow` close ≠ app quit by default). The user thinks they closed the app; CPU is still spinning; battery drains.
- (C) The user kills the app via Activity Monitor / Task Manager because they don't realize there's a queue worker. Forgot-about jobs accumulate.
- (D) Re-opening the app spawns a *second* queue worker, both attached to the same DB queue table; jobs are picked up twice (the database queue driver does row-level locking via `FOR UPDATE` equivalents in SQLite — actually SQLite doesn't support that; uniqueness depends on the `reserved_at` column update being atomic, which is generally fine but worth testing).

**Why it happens:**
- The Electron lifecycle and the Laravel-queue lifecycle are not naturally aligned. NativePHP supervises the PHP processes it spawns, but the user's mental model of "closing the app" doesn't predict what happens.
- "Quit on window close" is platform-different (macOS apps traditionally stay alive when the window closes; Windows apps quit).

**How to avoid:**
- **Quit on window close on Windows + Linux** (`win.on('closed', () => app.quit())`). Matches platform conventions. The launching of any background work is bounded by the app's lifetime.
- **macOS: quit-on-close is configurable.** First-launch wizard asks "Run in background when window is closed? (recommended for receipt scanning to keep working)". Stored in user preferences. The receipt-scanner uses this preference to decide whether to keep running. Default: quit on close (less surprising for a non-technical user).
- **Long-running jobs are interruptible + resumable.** `ResolveChainJob` is already `ShouldBeUniqueUntilProcessing`; ensure it's also idempotent (re-running yields the same result; partial state on disk is fine). v1.0 mostly already does this via the state machines, but verify per-job in a checklist.
- **On app launch, before the queue worker starts**, scan for "reserved" jobs that haven't been touched in >10 minutes — those are jobs from a previous run that died. Release them (`reserved_at = NULL`) so the new worker picks them up. Standard `queue:restart` behavior, but explicit.
- **Show queue status in the UI.** The dashboard footer says "2 jobs running" / "all jobs complete." The Dev Console (Pitfall 10) lets the user manually retry / cancel. Visibility = trust.
- **Don't run the queue worker as a separate launchd daemon** in the desktop build. The launchd plists from v1.0 are *uninstalled* by the data-migration wizard (Pitfall 2). The queue runs in-process via NativePHP's child-process supervisor instead.

**Warning signs:**
- Closing the app and reopening it leaves jobs in `reserved` state forever.
- The user reports "the app uses CPU even when I closed it" (queue worker still running, app quit didn't propagate).
- Two app instances accidentally process the same job (test by launching the app twice from `Applications/` while ignoring the "already running" warning).
- The v1.0 launchd plists are still installed after the migration wizard ran.

**Phase to address:**
**PKG-03 (queue & cache portability)** + **PKG-07 (lifecycle UX)** — a small phase dedicated to platform-correct quit/background behavior.

**Test/check that proves it didn't happen:** UAT script: dispatch 5 long-running jobs, close the window mid-batch, reopen, assert all 5 jobs complete (not stuck reserved, not duplicated).

---

### Pitfall 20: First-launch macOS permission prompts confuse the partner

**What goes wrong:**
On macOS Sonoma+, the first time an app:
- Reads a file from `~/Downloads/` (the partner is likely to drop a CSV there for the import wizard): prompts "Diederik wants to access files in your Downloads folder."
- Opens the system browser for OAuth: silent.
- Writes to `Application::storagePath()` (which is under `~/Library/Application Support/Diederik/`): silent (sandboxed apps would need entitlements, but NativePHP apps run unsandboxed).
- Accesses the keychain (if we add macOS Keychain support per the v1.0 STACK note): "Diederik wants to use 'Diederik IMAP password' from your keychain."
- Sends Apple Events (e.g., to open Finder pointing at the backups folder): "Diederik wants control of Finder."

The partner sees 3-5 system prompts in the first 60 seconds of using the app. They click "Don't Allow" on at least one because they don't recognize "Diederik." Now the CSV import is silently blocked because file access was denied. The partner reports "the import doesn't work."

**Why it happens:**
- macOS TCC (Transparency, Consent, and Control) prompts are aggressive and well-known to non-technical users for being noisy.
- The partner has been trained by years of "click Don't Allow" on apps they don't trust.
- "Diederik" is an unfamiliar app name; first impressions matter.

**How to avoid:**
- **Avoid prompts in the first 60 seconds.** Specifically:
  - Don't access `~/Downloads/` on launch. The CSV import flow opens a `NSOpenPanel` (NativePHP's file picker) — Apple-issued, doesn't trigger a TCC prompt because the user explicitly chose the file.
  - Don't access keychain unless the user opts in to keychain-based secrets storage (per v1.0 STACK: chmod-600 file is the v1 path; keychain is deferred). Keep deferred.
  - Don't send Apple Events. "Open backup folder in Finder" can be done via NativePHP's `Native::openWithFinder($path)` which uses `NSWorkspace.openURL`, not Apple Events.
- **The first-launch wizard primes the user.** "Diederik will ask for permission to open files you choose. This is normal. Always select Allow." A screenshot of the expected prompt makes the partner less startled when it appears.
- **`Info.plist` privacy strings** are clear and friendly. `NSDocumentsFolderUsageDescription = "Diederik needs to read your bank statement CSV when you import it."` Not "Diederik needs to read your Documents folder" (sounds invasive).
- **Test on a fresh macOS user account** during beta. The developer's account has long since accepted Diederik's prompts; their test isn't representative of the partner's first launch.

**Warning signs:**
- The first 60 seconds shows multiple system prompts.
- The partner reports "the app doesn't work" after clicking "Don't Allow."
- `Info.plist` privacy strings are vague or absent.
- The developer can't reproduce the partner's first-launch issues because their TCC database has cached prior approvals.

**Phase to address:**
**BETA-01 (partner onboarding)** — first beta phase. Lands the first-launch wizard + Info.plist strings + the test-on-fresh-account UAT step.

**Test/check that proves it didn't happen:** Manual UAT on a fresh macOS user account (create one, log in, install Diederik from a downloaded .dmg, run for 10 minutes, no permission denials).

---

### Pitfall 21: "Where is my data?" is unanswerable when the partner asks

**What goes wrong:**
The partner uses Diederik for 2 weeks. On day 15 they ask "if I want to back this up to an external drive, what file do I copy?" The developer doesn't know off the top of their head because `Application::storagePath()` is opaque on different OSes. Partner abandons the backup. The "your data is yours" privacy promise is undermined by the user not being able to *find* their data.

**Why it happens:**
- NativePHP's storage path is OS-conventional but not user-discoverable.
- macOS hides `~/Library` by default in Finder.
- Windows hides `%LOCALAPPDATA%` (it's `C:\Users\<user>\AppData\Local\Diederik\`, but AppData is hidden by default).
- Linux: `~/.local/share/Diederik/`, less hidden but still not obvious.

**How to avoid:**
- **In-app "Where is my data?" page.** Settings → Privacy → "Your data lives at `/Users/wesselverheij/Library/Application Support/Diederik/`." Two buttons: "Open in Finder/Explorer", "Copy path to clipboard."
- **The same page lists what's stored where:**
  - Transactions database: `.../database/database.sqlite`
  - Backups: `.../backups/`
  - OAuth tokens: `.../secrets/oauth/*.json` (chmod 600)
  - Logs: `.../logs/`
  - Recovery code hash: `.../.recovery-code-hash`
- **Export-everything button.** "Export all my data to a zip file" — bundles the DB + backups + OAuth secrets (warning the user that the secrets shouldn't be shared) into a single `.zip` placed on Desktop. The export is encrypted with a user-chosen passphrase. One-click "I want my data."
- **README and SECURITY.md document the storage path** per OS.

**Warning signs:**
- The partner asks "where's my data" and the developer has to look it up.
- The in-app settings has no "Privacy" section explaining storage.
- There's no export-everything affordance.

**Phase to address:**
**REL-03 (privacy + data export UX)** — pre-release, lands the Settings → Privacy page + export feature.

**Test/check that proves it didn't happen:** Partner UAT: "find your database file." Should take <30 seconds via the in-app affordance. Should be possible without the developer's help.

---

## Technical Debt Patterns (v2.0-specific, building on v1.0)

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|---|---|---|---|
| Smuggle `Auth::user()` into one Livewire component because the constructor-injection felt awkward | Save 10 min of plumbing | Arch test fails; PR review demands refactor; the fix is now spread across 5 commits | **Never** — land CurrentUserProvider first, write Auth::user() never |
| Skip the AppPaths abstraction, just hardcode `base_path('database/database.sqlite')` "since NativePHP rewrites the connection anyway" | Faster first build | The first artisan command that takes a path argument breaks silently; debugging takes a day | Never — AppPaths is a 50-line class, write it first |
| Ship the bundled `.env` as the developer's `.env` "we'll fix this before public release" | First build works | Secret in the .dmg goes to GitHub Releases first time the developer accidentally pushes a tag | **Never** — bundled `.env` is a generated artifact from day 1 of PKG |
| Keep Horizon + Redis "because the chain resolver is well-tested with it" | Avoids re-testing chain resolution | Partner cannot install the app; or the desktop install requires Docker (privacy fail) | Never beyond a Herd-dev experiment |
| Skip Ed25519 signing on top of code signing "because Apple/Windows code signing is good enough" | Saves one weekend of key custody ceremony | Single point of failure: cert revocation or compromise = forced re-issuance + partner panic | OK during beta on a private repo; mandatory before public release |
| Enable Dev Mode globally instead of per-user-with-owner-only-toggle | Faster Dev Console feature dev | Partner accidentally runs `db:restore`; data lost | Never beyond a "prototype to show the developer" — bake in per-user from day 1 |
| Reuse v1.0 Google OAuth client ID for the desktop OAuth | Avoids creating a new GCP project | "redirect_uri_mismatch" on the partner's first launch; OAuth verification has to be re-requested | Never — separate Desktop client is a 5-minute config |
| `pull_request_target` for "convenient" PR auto-labeling | Cleaner PR triage | Forked PR can exfiltrate signing secrets | Never on a repo with code-signing secrets |
| Skip log redaction "logs are local-only anyway" | Saves writing the Monolog processor | Partner shares a log screenshot containing a Bearer token | Never once OAuth tokens exist |
| Use Fortify's email-based password reset "because it's the default" | Avoids designing the recovery-code flow | Partner forgets password, no SMTP, locked out, data inaccessible | Never on a local-only app |

---

## Integration Gotchas (v2.0)

| Integration | Common Mistake | Correct Approach |
|---|---|---|
| NativePHP + existing `database/database.sqlite` | Assume NativePHP "just works" with the v1.0 setup; ship without testing first launch | AppPaths abstraction (Pitfall 1) + migration wizard (Pitfall 2) + simulated-NativePHP Pest test |
| Bundled PHP + macOS hardened runtime | Use NativePHP's defaults; forget the entitlements file | Ship with `entitlements.mac.plist` (allow-jit + allow-unsigned-executable-memory + disable-library-validation) + `opcache.jit=disable` + `pcre.jit=0` defensively |
| Horizon-Redis + Electron bundle | Try to bundle Redis; or pretend Horizon is optional | Drop Horizon for the desktop build (Pitfall 3); use `database` cache + queue; fold dashboard into Dev Console |
| Laravel Auth + DI-only rule | Use `Auth::user()` in 50 places | `CurrentUserProvider` interface in `Modules\Core\Auth`, injected everywhere, arch-test-enforced (Pitfall 4) |
| Multi-user + `BelongsToUser` trait | Trust schema enforcement; forget runtime query scoping | Global query scope via the trait; three named escape hatches; cross-user 404 tests per route (Pitfall 5) |
| Fortify + offline-first | Wire up the default email password reset | Disable email reset routes; ship recovery-phrase + owner-resets-partner UX (Pitfall 7) |
| Electron auto-update + GitHub Releases | Trust electron-updater's default signature check | Pin `publisherName` + Ed25519 signing + skip-version UX + SECURITY.md manual-verify docs (Pitfall 9) |
| GitHub Actions + signing secrets | Use `pull_request_target` or repo-level secrets | Tag-triggered release workflow + Environment-scoped secrets + CODEOWNERS on workflow files (Pitfall 13) |
| Hippocratic 3.0 + GitHub | Expect GitHub to detect it cleanly | LICENSE verbatim + NOTICE explainer + composer.json SPDX + README "source-available" framing (Pitfall 14) |
| Electron quit + queue worker | Assume launchd handles it as in v1.0 | Quit-on-close on Windows/Linux; macOS opt-in background; uninstall v1.0 launchd plists during migration |
| OAuth client type for desktop | Reuse Web Application client type | Create separate Desktop Application client; trigger verification early |

---

## Performance Traps (v2.0-specific)

| Trap | Symptoms | Prevention | When It Breaks |
|---|---|---|---|
| Electron + bundled PHP cold-start latency | First launch takes 8-15 seconds on the partner's mid-range laptop | Pre-warm config cache at build time (`php artisan config:cache` in the build step, ship the cached file in the bundle); pre-warm route cache; pre-warm view cache; lazy-load module service providers | First launch after install or after update |
| Database global scope on every query | 5-10% query overhead from the user-id filter | Acceptable for a single-machine app; the alternative (per-controller scoping) leaks. Index `(user_id, ...)` on every domain table — already done in v1.0 schema | At 100k+ transactions per user |
| Dev Console log tailing reads the whole file | 50MB log file takes 2s to load and re-renders on every new line | Stream from the tail (`tac -10000`); paginate; use server-sent events or Livewire `wire:poll.5s` not real-time | Once logs grow past a few MB |
| Audit log table grows unbounded | `dev_console_executions` reaches 100k rows after a year of dev use | Retain last 1000 rows + last 90 days; truncate on a daily schedule | After a year of dev use |
| Recovery code re-hash on every login | bcrypt cost 12 → 100ms per attempt; OK for password, painful for recovery code if also hashed | Recovery code hashed with the same cost is fine — it's used once at recovery time, not on every request | Never (used once) |
| Cross-user 404 tests double the test suite | 1644 tests → 3000+ tests; CI runs 2x longer | Use Pest's `dataset()` to share setup across routes; mock the model layer to avoid per-test DB rebuild; parallelize with `--processes` | After the MULTI-02 phase |

---

## Security Mistakes (v2.0-specific, building on v1.0)

| Mistake | Risk | Prevention |
|---|---|---|
| `.env` ships in the bundle with developer secrets | Secrets in `.dmg` on GitHub Releases | Build-step writes `.env.bundled` from a template; gitleaks scan dist/ (Pitfall 6) |
| `APP_KEY` is the same across all installs | Session cookies forgeable across users | Per-install `APP_KEY` generation on first launch; never in the bundled `.env` |
| Dev Mode enabled by default | Partner runs destructive command by accident | Off by default; per-user `developer_mode_enabled` column; owner-only; password re-entry to enable (Pitfall 10) |
| Bearer tokens in logs | Partner shares screenshot → token leaks | Monolog redaction processor at write time (Pitfall 11) |
| Shell injection in Dev Console runner | `; rm -rf ~` in an argument field | `Symfony\Process` array-syntax only; arch-test forbid `shell_exec`/`exec`/`passthru` (Pitfall 10) |
| Auto-update accepts any Apple-signed `.dmg` | Different publisher cert → still passes; partner installs compromised version | Pin `publisherName`; Ed25519 over the top (Pitfall 9) |
| Signing secrets exposed to forked PRs | Cert exfiltration | Tag-triggered release workflow + Environment-scoped secrets + no `pull_request_target` (Pitfall 13) |
| OneDrive/iCloud Drive picks up the storage folder | Financial data sync'd to vendor cloud | Use `%LOCALAPPDATA%` (Windows) / `~/Library/Application Support/` (macOS); document "don't move the folder to a sync'd path" |
| Partner can read user's `database.sqlite` on shared macOS | Multiple-user macOS: data leaks | chmod 600 on the file; `~/Library/Application Support/` is per-user — but verify with the `ls -la` test on a multi-user box |
| Cross-user query missing `user_id` filter | Partner sees user's transactions | `BelongsToUser` global scope + cross-user 404 test set + queue jobs carry `user_id` (Pitfall 5) |
| OAuth refresh tokens copied across installs | A leak from the dev box ends up on the partner's box | Migration wizard does NOT copy OAuth secrets; partner re-authorizes (Pitfall 2) |
| `Auth::user()` smuggled in past the DI rule | The "no facade" arch-test invariant rots | CurrentUserProvider + extended `MULTI-01` arch test (Pitfall 4) |

---

## UX Pitfalls (v2.0-specific)

| Pitfall | User Impact | Better Approach |
|---|---|---|
| No "what happens if I forget my password" guidance | Partner panics on first password input | First-launch wizard shows recovery phrase; "forgot password" UX explicitly says "no email reset, use your phrase" (Pitfall 7) |
| Permission prompts in first 60 seconds | Partner clicks "Don't Allow" out of habit, app doesn't work | Prime user before prompts appear; defer keychain/AppleEvents until needed (Pitfall 20) |
| "Where is my data?" unanswerable | Partner can't make their own backup; trust erosion | Settings → Privacy page + "Open folder" button + "Export everything" affordance (Pitfall 21) |
| Update appears, no "skip this version" option | Partner can't reject a bad update | Three buttons: "Now", "On next launch", "Skip this version" (Pitfall 9) |
| Closing app silently leaves background worker running | Partner sees CPU spike, kills via Task Manager, jobs lost | macOS opt-in to background; Windows/Linux quit on close (Pitfall 19) |
| Migration wizard auto-detects v1.0 install + auto-imports | Wrong install detected, wrong data imported | Wizard is explicit: "Import from existing install? Pick folder." Never auto (Pitfall 2) |
| Dev Console accessible by partner | Curiosity → destructive action | Owner-only flag, off by default, requires password to enable (Pitfall 10) |
| Bundled OAuth consent screen says "diederik-dev (testing)" | Partner sees confusing OAuth pop-up | Separate "Diederik Desktop" OAuth client + Google verification before public release (Pitfall 18) |
| Log share button copies raw log to clipboard | Partner shares with developer via WhatsApp, OAuth token leaks | "Preview before share" step; double-pass redaction (Pitfall 11) |
| No visibility of "is the queue working?" | Receipts don't appear, partner doesn't know why | Dashboard footer queue status + Dev Console queue inspector |

---

## "Looks Done But Isn't" Checklist (v2.0)

- [ ] **Packaging:** Often missing **first-launch smoke test in CI** — verify a freshly-built `.dmg` / `.exe` actually boots without crashing.
- [ ] **Packaging:** Often missing **path-correctness test** — verify `db:backup` writes to `Application::storagePath()`, not to `database/`.
- [ ] **Packaging:** Often missing **`opcache.jit=disable` + `pcre.jit=0`** defensive defaults — verify in bundled `php.ini`.
- [ ] **Migration wizard:** Often missing **WAL-safe copy** — verify the import path uses `VACUUM INTO` against a `?mode=ro` source, not `copy()`.
- [ ] **Migration wizard:** Often missing **OAuth secrets not auto-copied** — verify the wizard prompts for re-auth, never bulk-copies tokens.
- [ ] **Migration wizard:** Often missing **launchd uninstall step** — verify v1.0 launchd plists are removed before desktop takes over.
- [ ] **Multi-user:** Often missing **`CurrentUserProvider` arch test** — verify `Auth::` and `auth()` are forbidden in `Modules/` (extended from v1.0).
- [ ] **Multi-user:** Often missing **cross-user 404 test set** — verify every Livewire route with an ID parameter has a test for "User B can't access User A's row."
- [ ] **Multi-user:** Often missing **queue job `$userId` property** — verify every job in `Modules/` that touches a `BelongsToUser` model carries the user_id.
- [ ] **Multi-user:** Often missing **`BelongsToUser` global scope test** — verify reads from outside a `CurrentUserProvider` context throw.
- [ ] **Password reset:** Often missing **recovery phrase shown at signup** — verify signup wizard demands user confirms phrase before continuing.
- [ ] **Password reset:** Often missing **email reset routes removed** — verify `POST /password/email` returns 404.
- [ ] **Dev Console:** Often missing **owner-only check** — verify non-owner users get 403 on `/developer/*`.
- [ ] **Dev Console:** Often missing **Dev Mode off by default** — verify a fresh install has `developer_mode_enabled = false`.
- [ ] **Dev Console:** Often missing **`Symfony\Process` array-syntax** — verify no `shell_exec`/`exec`/`passthru` in `Modules/` (arch test).
- [ ] **Dev Console:** Often missing **audit log** — verify every artisan execution writes to `dev_console_executions`.
- [ ] **Log redaction:** Often missing **Bearer token scrub** — verify a synthetic OAuth 401 produces a log line with `Bearer [REDACTED]`.
- [ ] **Code signing:** Often missing **Apple notarization + stapling** — verify `xcrun stapler validate Diederik.app` passes.
- [ ] **Code signing:** Often missing **entitlements file** — verify `entitlements.mac.plist` is referenced from the build config.
- [ ] **Auto-update:** Often missing **publisherName pin** — verify the bundled config has `publisherName: "Apple Developer ID: ..."`.
- [ ] **Auto-update:** Often missing **Ed25519 signature verification** — verify update flow rejects an update without the Ed25519 sig.
- [ ] **Auto-update:** Often missing **skip-this-version UX** — verify the update prompt has three buttons (Now / Next Launch / Skip).
- [ ] **CI:** Often missing **TZ=Europe/Amsterdam in workflow** — verify Carbon-dependent tests pass at any wall-clock hour.
- [ ] **CI:** Often missing **narrow PR matrix** — verify PR builds run only on `ubuntu-latest`, full matrix on tag only.
- [ ] **CI:** Often missing **secrets-not-exposed-to-fork test** — submit a sacrificial fork PR that prints a secret; assert empty.
- [ ] **License:** Often missing **NOTICE file** — verify LICENSE + NOTICE both exist; composer.json has `Hippocratic-3.0`.
- [ ] **Dead code:** Often missing **`unused-public` clean run** — verify the dead-code finder reports zero unexplained matches.
- [ ] **Bundled `.env`:** Often missing **per-install APP_KEY** — verify two installs on the same machine have different `APP_KEY` values.
- [ ] **OAuth:** Often missing **separate Desktop OAuth client** — verify the bundled `.env` references a different client ID from v1.0 dev.
- [ ] **Beta UAT:** Often missing **fresh macOS account test** — verify first-launch UX on a never-used account on a clean VM.

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---|---|---|
| `.env` with developer secrets shipped in `.dmg` | HIGH | Rotate every secret in the leaked `.env`; revoke OAuth client; issue new release; notify any partners who installed the bad build; pull the release from GitHub |
| Auto-update installs malicious version (publisherName not pinned) | CRITICAL | Revoke the misused Apple Developer ID / Windows EV cert; force-reissue both certs (multi-day); push an emergency update via a side channel (email partners directly with a verified link); add Ed25519 in the recovery release |
| Bearer token leaked via log screenshot | MEDIUM | Revoke the OAuth refresh token (Google/Microsoft both support per-app revocation); partner re-authorizes; ship the redaction processor in the next release |
| Partner runs destructive command in Dev Console, data lost | MEDIUM | Restore from the latest `.../backups/` directory (v1.0 Phase 11 backup retention applies); if no backup, restore from the migration sentinel snapshot (Pitfall 2); document the lesson in dev console audit log + add the command to a tighter allowlist |
| Cross-user data leak (a query missed `user_id`) | HIGH | Identify the query via grep + git-bisect; ship a patch; audit the dev_console_executions and HTTP logs for what the partner saw; assume the partner saw the data and disclose; cross-user 404 tests catch the regression going forward |
| `Auth::user()` smuggled into N places | LOW (if caught early) | The arch test catches it; the fix is the same as Pitfall 4's prevention. Cost is review time, not data loss. The longer it's ignored, the more places to refactor at once |
| `entitlements.mac.plist` missing → app crashes on partner launch | LOW | Add the entitlements file, re-sign, re-notarize, re-release; partner downloads new `.dmg` (no data loss because the app never booted) |
| Recovery phrase lost by partner | MEDIUM | Owner-resets-partner path (Pitfall 7); if owner is also locked out, restore from a backup of `users.password` table from before the partner forgot their phrase (requires backup discipline); if no backup, the partner's account is gone — their data remains in DB but they can't log in. Document this in SECURITY.md explicitly |
| Hippocratic-flagged downstream rejection | LOW | Acknowledge; document that this is by design; provide a "you can fork under the same license" path; no action |

---

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---|---|---|
| 1. Hard-coded filesystem paths | PKG-01 | Arch test forbids `base_path()`/`storage_path()`/`database_path()` outside `Modules\Core\Filesystem`; simulated-NativePHP backup test |
| 2. Data-migration wizard corrupts v1.0 data | PKG-02 | Pest feature test with WAL-mode source + sentinel-file assertion + OAuth-not-copied assertion |
| 3. Horizon-Redis doesn't ship | PKG-03 | Chain-resolver tests run against `database` queue + `Cache::lock()`; bundled `.env` has `QUEUE_CONNECTION=database` |
| 4. `Auth::user()` everywhere | MULTI-01 | Arch test `MULTI-01` extends v1.0 forbid-list; allowlist has exactly one class |
| 5. Forgotten `where('user_id', ...)` | MULTI-02 | Cross-user 404 test set (one per route); `BelongsToUser` global scope test; queue job `$userId` arch test |
| 6. `.env` ships with developer secrets | PKG-04 | `gitleaks scan dist/`; `diederik:audit-bundle` artisan command; per-install APP_KEY test |
| 7. Password reset via SMTP | MULTI-04 | `POST /password/email` returns 404; signup happy-path asserts recovery phrase |
| 8. JIT + hardened runtime crash | PKG-05 | CI first-launch smoke test (`open -W Diederik.app`); `php.ini.bundled` has JITs disabled |
| 9. Auto-update trusts wrong signature | REL-02 | End-to-end update test with right/wrong publisherName + tampered SHA |
| 10. Dev Console exposes destructive artisan | DEVUI-01 | Non-owner 403 test; off-by-default test; shell-injection 422 test |
| 11. Logs leak Bearer tokens | DEVUI-02 | Synthetic 401 → log line has `[REDACTED]`; CI grep for unmasked tokens |
| 12. Pest parallel + SQLite + UTC runner | CI-01 | 3-times-green CI run; `TZ` env var set; `APP_TIMEZONE` bootstrapped |
| 13. Signing secrets exposed to forks | CI-02 | Sacrificial fork PR test; Environment-scoped secrets; CODEOWNERS on workflows |
| 14. Hippocratic 3.0 ecosystem friction | REL-01 | LICENSE sha256 match; composer.json `Hippocratic-3.0`; NOTICE exists |
| 15. Session cookies break on `file://` | MULTI-04 + PKG-04 | Log-in-then-navigate test in NativePHP-simulated env |
| 16. Windows installer asks for admin / writes to ProgramFiles | PKG-06 | Manual UAT on Windows VM: install path under `%LOCALAPPDATA%` |
| 17. Dead code accumulates | REL-04 | `unused-public` + `rector --dry-run --set=DEAD_CODE` clean |
| 18. OAuth redirect URI mismatch on partner machine | MULTI-05 + PKG-04 | Manual UAT: partner connects Gmail on macOS + Windows |
| 19. Queue worker survives close-and-reopen | PKG-03 + PKG-07 | Dispatch 5 jobs + close + reopen + assert all complete |
| 20. macOS permission prompts confuse partner | BETA-01 | Fresh-macOS-account UAT |
| 21. "Where is my data?" unanswerable | REL-03 | Partner-finds-data UAT in <30s |

---

## Sources

- [NativePHP Databases — official docs](https://nativephp.com/docs/desktop/1/digging-deeper/databases) — HIGH. Confirms automatic SQLite relocation to `Application::storagePath()` and the migrate-on-version-bump behavior.
- [NativePHP Files — official docs](https://nativephp.com/docs/desktop/1/digging-deeper/files) — HIGH. Confirms `Application::storagePath()` semantics.
- [NativePHP Building (signing) — official docs](https://nativephp.com/docs/1/publishing/building) — HIGH. Confirms NativePHP performs the signing + notarization step.
- [Apple — Allow Unsigned Executable Memory entitlement](https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.security.cs.allow-unsigned-executable-memory) — HIGH. Confirms the W^X relaxation entitlement for JIT.
- [Apple — Hardened Runtime configuration](https://developer.apple.com/documentation/xcode/configuring-the-hardened-runtime) — HIGH.
- [Code Signing and Security Considerations for NativePHP Apps (thecodingdev.com, Apr 2025)](https://www.thecodingdev.com/2025/04/code-signing-and-security.html) — MEDIUM. NativePHP-specific.
- [electron-builder Auto-Update](https://www.electron.build/auto-update.html) — HIGH (official). Confirms `publisherName` pinning + GitHub Releases provider + `latest.yml` semantics.
- [Doyensec ElectronSafeUpdater (Ed25519 verification)](https://github.com/doyensec/ElectronSafeUpdater) — MEDIUM. Reference implementation of hardened update verification.
- [Doyensec blog — Building a Secure Electron Auto-Updater (Feb 2026)](https://blog.doyensec.com/2026/02/16/electron-safe-updater.html) — MEDIUM.
- [Hippocratic License 3.0 — firstdonoharm.dev](https://firstdonoharm.dev/learn/) — HIGH (official).
- [Hippocratic License FAQ on GitHub](https://github.com/EthicalSource/hippocratic-license/blob/eef3a5a4ecb731f34f45c01145e8ca4ba74c0714/content/faq.md) — HIGH. Explicit on OSI/OSD non-compliance by design.
- [SPDX license-list-XML issue 1393 — Hippocratic 3.0](https://github.com/spdx/license-list-XML/issues/1393) — MEDIUM. Confirms SPDX listing as non-OSI.
- [The Great Open Source Divide: Hippocratic License (itsfoss.com)](https://itsfoss.com/hippocratic-license/) — MEDIUM. Community discussion of trade-offs.
- [Laravel Fortify customization — Laracasts discussion](https://laracasts.com/discuss/channels/laravel/overriding-password-reset-functionality-in-fortify) — MEDIUM. Confirms `ResetPassword::toMailUsing` is the override hook.
- [Laravel Fortify docs (11.x)](https://laravel.com/docs/11.x/fortify) — HIGH (official). Confirms route-removal via `Fortify::ignoreRoutes()`.
- [Laravel 12 Queues docs](https://laravel.com/docs/12.x/queues) — HIGH (official). Confirms `Cache::lock()` works against the database driver for `ShouldBeUniqueUntilProcessing`.
- [GitHub Actions security — pull_request_target vs pull_request (official docs)](https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions) — HIGH. Confirms secrets exposure rules.
- [v1.0 PITFALLS.md — diederik milestones/v1.0-research](file:///Users/wesselverheij/Development/diederik/.planning/milestones/v1.0-research/PITFALLS.md) — HIGH (project canonical). Foundation for the carry-over pitfalls; this file deliberately does not re-explain them.

---

*v2.0 pitfalls research for: diederik — desktop packaging + multi-user + Developer Mode + CI release pipeline on top of a shipped v1.0 Laravel personal-finance dashboard.*
*Researched: 2026-05-19*
