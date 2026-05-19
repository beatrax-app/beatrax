# Architecture Research — diederik v2.0 Public Release

**Domain:** Local-first desktop personal-finance application (Laravel 13 + Livewire 4 + SQLite + NativePHP/Electron shell)
**Researched:** 2026-05-19
**Confidence:** HIGH on integration with existing v1.0 invariants (read directly from the codebase); MEDIUM-HIGH on NativePHP behaviour (verified against official v2 docs).

This document answers: *how do the eight v2.0 architectural pieces — desktop shell, user-data dir migration, queue/Horizon-in-bundle, multi-user, Dev Mode UI, CI/CD, auto-update, public-release boundary — integrate with the 11-module, arch-test-enforced v1.0 architecture without breaking any of the 34+ `BoundaryArchTest` invariants?*

The v1.0 architecture has already done most of the load-bearing work for v2.0:

- `Modules\Core\Public\Contracts\CurrentUser` already exists as a DI-friendly seam (interface, resolved through `Illuminate\Contracts\Auth\Factory`, NOT through facades).
- `Modules\Core\Public\Concerns\BelongsToUser` + `Modules\Core\Public\Scopes\UserScope` already apply the per-user global scope to every domain model.
- Every domain table already carries a nullable `user_id`, enforced by `UserIdColumnArchTest`.
- `Modules\EmailScan\Public\Services\OAuthSecretsRepository` already isolates OAuth tokens behind a single repository class (path-injected, chmod-600 enforced, atomic-rename writes).
- `Modules\Core\Internal\Console\InstallCommand --launchd` already proves the "ship plist templates with placeholders, materialise on install" pattern.

v2.0's job is to **flip the dormant pieces on, repackage the runtime, and add three new bounded modules** — not to redesign the spine.

## Standard Architecture

### System Overview — v2.0 Desktop Runtime

```
┌────────────────────────────────────────────────────────────────────────┐
│                          Electron Renderer Process                      │
│                       (Chromium window — diederik UI)                   │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ Livewire 4 SFCs + Volt + Flux UI + Tailwind 4                    │  │
│  │ ApexCharts (via livewire-charts wrapper)                          │  │
│  └─────────────────────────────────┬────────────────────────────────┘  │
└────────────────────────────────────┼────────────────────────────────────┘
                                     │  HTTP(S) on 127.0.0.1:RANDOM_PORT
                                     │  (Electron browser → bundled PHP)
┌────────────────────────────────────┼────────────────────────────────────┐
│                  Electron Main Process (Node, NativePHP/electron)      │
│  ┌─────────────────────────┐  ┌────────────────────────────────────┐   │
│  │ App lifecycle           │  │ NativePHP IPC bridge                │   │
│  │ Window/Menu/Tray/Notif  │  │ - Window, Menu, Notification, Tray │   │
│  │ electron-updater        │  │ - File-open handlers (.eml/.csv)   │   │
│  │ Code-signing wrap       │  │ - Global hotkeys, clipboard         │   │
│  └─────────────────────────┘  └─────────────────────┬──────────────┘   │
└─────────────────────────────────────────────────────┼──────────────────┘
                                                       │  PHP <-> JS over
                                                       │  STDIO + named pipes
┌─────────────────────────────────────────────────────┴──────────────────┐
│            Bundled php-fpm-like long-running PHP runtime                │
│  ┌────────────────────────────────────────────────────────────────┐    │
│  │ Laravel 13 — routing, container, queue, scheduler               │    │
│  │  ┌─────────────────────────────────────────────────────────┐    │    │
│  │  │  Modules/  (existing 11 + 3 new — see "Module Layout")  │    │    │
│  │  └─────────────────────────────────────────────────────────┘    │    │
│  │  Workers: 1× scheduler, 2× queue-worker (NativePHP-managed,    │    │
│  │           NOT Horizon; see "Queue Decision")                    │    │
│  └────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────┬──────────────────┘
                                                       │  All paths flow
                                                       │  through OS appdata
┌─────────────────────────────────────────────────────┴──────────────────┐
│                       Per-OS user data directory                        │
│  macOS:   ~/Library/Application Support/diederik/                       │
│  Windows: %APPDATA%\diederik\                                           │
│  Linux:   ~/.config/diederik/                                           │
│                                                                          │
│   storage/                                                               │
│   ├── database/data.sqlite        (WAL + synchronous=NORMAL)            │
│   ├── app/                                                               │
│   │   ├── backups/                (VACUUM INTO targets)                 │
│   │   ├── secrets/email-oauth.json  (chmod 600)                         │
│   │   └── inbox/                  (.eml drop-folder)                    │
│   ├── logs/                                                              │
│   └── framework/cache,sessions,views                                     │
└──────────────────────────────────────────────────────────────────────────┘
```

Key shape changes vs v1.0:

| Layer | v1.0 | v2.0 |
|-------|------|------|
| Hosting | Laravel Herd (`https://diederik.test`) | Electron shell + bundled static PHP runtime |
| SQLite path | `database/database.sqlite` inside repo | `storagePath('database/data.sqlite')` in OS user-data dir |
| Backups | `storage/app/backups/` inside repo | Same relative path, rooted at OS user-data dir |
| OAuth secrets | `storage/app/secrets/email-oauth.json` inside repo | Same relative path, rooted at OS user-data dir |
| Queue driver | `redis` (loopback Docker container) | `database` for shipped desktop; `redis` only for dev mode |
| Background workers | `launchd` plists supervising `php artisan horizon` + scheduler | NativePHP-managed child processes (`queue_workers` + `schedule:work`) |
| Multi-user | Single user, schema multi-user-ready | Real Fortify auth, profile selector, **one shared SQLite per machine** |
| Auto-update | None | `electron-updater` polling GitHub Releases |

### Component Responsibilities — v2.0 Additions

| Component | Responsibility | Typical Implementation |
|-----------|---------------|------------------------|
| `Modules/Desktop/` (NEW) | OS-shell concerns: tray, menu, notifications, file-open registration, `nativephp.config.php` lifecycle | Provider wires NativePHP listeners; thin classes delegate to existing module Public services |
| `Modules/DevMode/` (NEW) | In-app developer console: whitelisted/destructive artisan runner, log tailer, queue inspector, `system_alerts` viewer, embedded Horizon proxy (dev only) | Livewire SFCs gated by `User::is_developer` flag + middleware |
| `Modules/Auth/` (NEW, **or extend `Modules/Core/`** — see decision below) | Login / signup / profile selector / logout / session lifecycle / `is_developer` flag management | Fortify actions + Livewire SFCs; binds the `Auth::id()` seam |
| `Modules/Core/Public/Contracts/UserDataPath` (NEW contract) | Single DI seam for "where is the user-data root on this machine?" | `UserDataPathService` reads `Application::storagePath()` (NativePHP-rewritten) |
| `Modules/Core/Public/Contracts/UpdateChannel` (NEW contract) | "Is an update available? Which version? Apply." | `ElectronUpdateChannel` IPC-calls into the NativePHP main process |

## Recommended Project Structure

```
diederik/
├── Modules/
│   ├── Core/                        # (existing) auth contracts, doctor, backups
│   │   ├── Public/Contracts/
│   │   │   ├── CurrentUser.php      # EXISTING
│   │   │   ├── UserDataPath.php     # NEW (v2.0 user-data root seam)
│   │   │   ├── UpdateChannel.php    # NEW (auto-update seam)
│   │   │   └── DeveloperMode.php    # NEW (Dev Mode capability flag)
│   │   ├── Public/Services/
│   │   │   ├── CurrentUserService.php  # EXISTING — no changes needed
│   │   │   └── UserDataPathService.php # NEW
│   │   ├── Public/Concerns/
│   │   │   └── BelongsToUser.php    # EXISTING — no changes needed
│   │   ├── Public/Scopes/
│   │   │   └── UserScope.php        # EXISTING — already enforces per-user
│   │   └── Internal/Console/
│   │       ├── InstallCommand.php   # EXISTING — gains --desktop / --first-run modes
│   │       └── MigrateUserDataCommand.php  # NEW — v1.0 → v2.0 in-place migration
│   │
│   ├── Auth/                        # NEW — owns the auth UI + actions
│   │   ├── Public/Contracts/
│   │   │   └── SignupGate.php       # "is signup currently allowed?"
│   │   ├── Public/Actions/
│   │   │   ├── CreateUser.php       # invoked by Fortify CreateNewUser action
│   │   │   └── SwitchUser.php       # profile-selector hand-off
│   │   ├── Internal/Http/Livewire/
│   │   │   ├── LoginPage.php
│   │   │   ├── SignupPage.php
│   │   │   ├── ProfileSelectorPage.php
│   │   │   └── LogoutControl.php
│   │   └── Database/Migrations/
│   │       └── *_add_is_developer_to_users.php
│   │
│   ├── Desktop/                     # NEW — owns OS-shell concerns
│   │   ├── Public/Contracts/
│   │   │   ├── SystemTrayService.php
│   │   │   └── DesktopNotificationService.php
│   │   ├── Public/Events/
│   │   │   └── FileOpenedFromOs.php # .eml / .csv dropped onto app icon
│   │   ├── Internal/
│   │   │   ├── NativePhpEventListener.php
│   │   │   ├── TrayMenuBuilder.php
│   │   │   ├── AppMenuBuilder.php
│   │   │   └── FileOpenRouter.php   # routes to Ingestion or Receipts public services
│   │   └── Providers/
│   │       └── DesktopServiceProvider.php
│   │
│   ├── DevMode/                     # NEW — in-app developer console
│   │   ├── Public/Contracts/
│   │   │   └── AllowedArtisanCommand.php  # whitelist contract
│   │   ├── Public/Services/
│   │   │   └── ArtisanCommandRegistry.php # SAFE / DESTRUCTIVE / FORBIDDEN tiers
│   │   ├── Internal/
│   │   │   ├── Http/Livewire/
│   │   │   │   ├── DevConsolePage.php
│   │   │   │   ├── ArtisanRunner.php
│   │   │   │   ├── LogTailer.php
│   │   │   │   ├── QueueInspector.php   # reads jobs table directly (no Horizon dep)
│   │   │   │   └── DoctorPanel.php
│   │   │   ├── Http/Middleware/
│   │   │   │   └── EnsureDeveloperMode.php  # gate on User::is_developer
│   │   │   └── Services/
│   │   │       ├── ArtisanRunner.php        # uses Illuminate\Contracts\Console\Kernel
│   │   │       └── DestructiveCommandGuard.php  # double-confirm + audit-log
│   │   └── Providers/
│   │       └── DevModeServiceProvider.php
│   │
│   └── (existing 11 modules — unchanged structure)
│
├── app/Providers/
│   ├── HorizonServiceProvider.php           # EXISTING — gated to dev mode only in v2
│   ├── NativePhpServiceProvider.php         # NEW (or auto-discovered from nativephp/desktop)
│   └── ElectronUpdateServiceProvider.php    # NEW — binds UpdateChannel contract
│
├── nativephp.config.php             # NEW — NativePHP main config
├── config/
│   ├── database.php                 # MODIFIED — database = storage_path('database/data.sqlite')
│   ├── queue.php                    # MODIFIED — default = 'database' for desktop builds
│   ├── horizon.php                  # MODIFIED — gated to dev mode (config('app.dev_mode'))
│   └── nativephp.php                # NEW — queue_workers, scheduler, electron app meta
│
├── deploy/
│   ├── launchd/                     # EXISTING — dev-mode only
│   └── electron-builder.yml         # NEW — installer/sign/update-feed config
│
├── .github/workflows/
│   ├── ci.yml                       # NEW — PR gate: pint + larastan + pest
│   └── release.yml                  # NEW — tag-triggered: build/sign/publish installers
│
└── resources/
    └── brand/
        ├── logo.svg                 # canonical brand asset
        └── icons/
            ├── macos.icns
            ├── windows.ico
            └── linux.png            # generated at build time
```

### Structure Rationale

- **`Modules/Auth/` as a NEW dedicated module, NOT a `Modules/Core/` sub-namespace.** Auth is its own bounded context: login/signup/profile-selector pages, Fortify actions, the `is_developer` flag toggle. Keeping it inside `Modules/Core/` would force `Modules/Core/Internal/Http/Livewire/LoginPage.php` to live alongside `SystemAlertsBanner` and `Dashboard`, blurring "operational infrastructure" with "identity." A separate module preserves the BoundaryArchTest pattern (`Modules\Auth\Internal` only used inside `Modules\Auth`).
- **`Modules/Desktop/` as a NEW module.** All NativePHP IPC concerns live here. The existing modules never import `Native\Laravel\*` namespaces — they only receive events (e.g. `FileOpenedFromOs`) and method calls on injected `SystemTrayService` / `DesktopNotificationService` contracts. This keeps the eleven existing modules buildable in a non-desktop test environment (Pest runs Laravel without NativePHP loaded).
- **`Modules/DevMode/` as a NEW module.** The Dev Mode UI consumes other modules' public surfaces (e.g. `Modules\Core\Public\Services\SystemAlertQuery`, `DoctorProbe` results) but exposes none of its own to other domain modules. Quarantining it in its own module makes the `is_developer`-gated middleware enforcement straightforward and prevents leakage into normal user pages.
- **No `App\Models\User` migration.** v1.0 already class-aliases `App\Models\User` to `Modules\Core\Models\User` inside `CoreServiceProvider::register()`. v2.0 keeps that alias; the User model stays in `Modules/Core/Models/`.

## Architectural Patterns

### Pattern 1: NativePHP-Rewritten storage_path() as the Single Source of Truth

**What:** NativePHP rewrites `Application::storagePath()` (and therefore `app()->storagePath()` and `storage_path()`) to the OS's per-user `appData` directory at boot. All paths the application reads or writes derive from `Application::storagePath(...)`, never from `database_path()` or hard-coded `__DIR__` relative paths.

**When to use:** Every file the app reads/writes that must survive an app update or uninstall-reinstall cycle. Backups, the SQLite file itself, OAuth secrets, the inbox drop-folder, logs, queue payloads (when using `database` driver), failed_jobs.

**Trade-offs:** `database_path()` continues to point at the *bundled* (read-only) path inside the app bundle — useful for migration files and seeders that ship with the binary, useless for the live data. Code must consistently choose the right helper. The new `Modules\Core\Public\Contracts\UserDataPath` contract makes this explicit and arch-testable.

**Example:**
```php
// Modules/Core/Public/Services/UserDataPathService.php
final class UserDataPathService implements UserDataPath
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function databaseFile(): string
    {
        // NativePHP-rewritten storage_path()
        return $this->app->storagePath('database/data.sqlite');
    }

    public function backupsDirectory(): string
    {
        return $this->app->storagePath('app/backups');
    }

    public function oauthSecretsFile(): string
    {
        return $this->app->storagePath('app/secrets/email-oauth.json');
    }

    public function inboxDropFolder(): string
    {
        return $this->app->storagePath('app/inbox');
    }

    public function logsDirectory(): string
    {
        return $this->app->storagePath('logs');
    }
}
```

`config/database.php` then resolves to `storage_path('database/data.sqlite')` at boot (NativePHP runs the rewrite before `config:cache`). `OAuthSecretsRepository`, `BackupDatabaseCommand`, `RestoreDatabaseCommand`, `BackupFreshnessProbe`, and the inbox drop-folder scanner all get re-wired to consume `UserDataPath` instead of base-path-rooted strings. The existing `core.backups_directory` container binding (in `CoreServiceProvider`) becomes a closure that delegates to `UserDataPath::backupsDirectory()`.

### Pattern 2: One SQLite Per Machine, NOT Per User (recommended)

**What:** A single `data.sqlite` lives in the OS user-data directory, shared by every diederik user (the developer, the partner, future invitees). The existing `user_id`-scoped `UserScope` global scope is the per-user data wall.

**When to use:** Multi-user with strong partner-sharing intent (the user has explicitly said they want to share with a partner). Single-machine. Privacy is on the **operating-system user** boundary (one macOS user account = one diederik dataset), not on the **app user** boundary.

**Trade-offs:**

| | Per-machine (RECOMMENDED) | Per-app-user |
|---|---|---|
| Partner sharing | Trivial — both users log into the same app on the same machine, see only their own data via `UserScope` | Requires explicit shared-account model or a sync layer (out of scope) |
| Backups | One `db:backup` covers everyone | One backup per user; coordination headache |
| OAuth secrets | One JSON file, partitioned per `user_id` inside | One file per user; multiply chmod surface |
| Cross-user reporting | Possible later if user opts in (e.g. household total) | Impossible without merging databases |
| OS-user isolation | Preserved (macOS already isolates `~/Library/Application Support/` per OS user) | Preserved (same dir) |
| Schema complexity | Already done in v1.0 (every domain table has `user_id`) | Same complexity, no benefit |

**Decision: per-machine, one SQLite, multi-user via existing schema.** This is what v1.0's schema was designed for — see `UserIdColumnArchTest`. v2.0 just turns on real authentication, the global scope already works.

**Implication for OAuth secrets:** the existing `email-oauth.json` file gets a `users.{user_id}.providers` nesting:

```json
{
  "providers": {
    "gmail":    { "client_id": "...", "client_secret": "..." }
  },
  "users": {
    "1": { "inboxes": [ { "id": 7, "refresh_token": "...", "rotated_at": "..." } ] },
    "2": { "inboxes": [ { "id": 12, "refresh_token": "..." } ] }
  }
}
```

`OAuthSecretsRepository` extends with `forUser(int $userId)` accessor methods; the chmod-600 single-file invariant + atomic-rename writes stay intact. Existing v1.0 users get migrated in-place by `MigrateUserDataCommand` (their inboxes move under `users.1`).

**Example — multi-user-aware query (NO code change required in domain modules):**
```php
// Modules/Ledger/Public/Services/TransactionQuery.php — UNCHANGED from v1.0
final class TransactionQuery
{
    public function recent(int $limit = 20): Collection
    {
        // UserScope (global scope, applied via BelongsToUser trait) already
        // filters by $this->currentUser->id() — nothing else to do.
        return Transaction::query()
            ->orderByDesc('posted_at')
            ->limit($limit)
            ->get();
    }
}
```

This is the load-bearing payoff of the v1.0 design: 11 modules of domain logic continue to work unmodified.

### Pattern 3: Drop Horizon for the Shipped Desktop Bundle; Keep It Only for Dev Mode

**What:** Ship the desktop installer with `QUEUE_CONNECTION=database`. The `jobs`, `failed_jobs`, and `job_batches` tables already exist (Laravel 13 defaults). NativePHP's built-in `queue_workers` config supervises 2 long-running `php artisan queue:work` child processes inside the Electron app. Horizon stays installed in `composer.json` but the `/horizon` route, Horizon's Redis-backed dashboard, and the `redis` queue connection are activated **only when** `config('app.dev_mode') === true` AND a Redis instance is reachable on `127.0.0.1:6379`.

**When to use:** Local-only, single-machine, partner-sharing desktop app. Job throughput is bounded by the human's inbox size (hundreds of inboxes/day at the absolute worst) — well within SQLite's capacity in WAL mode.

**Trade-offs vs shipping Redis-in-bundle:**

| | Database queue (RECOMMENDED) | Redis-in-bundle |
|---|---|---|
| Installer size | ~80 MB (Electron + PHP runtime) | ~140 MB (add `redis-server` binary per OS) |
| Code-signing surface | Just Electron + PHP | Electron + PHP + 3 Redis binaries to sign per OS |
| Cross-platform | Works on macOS / Windows / Linux without per-OS Redis builds | Needs 3 Redis binaries; Windows Redis is unmaintained |
| `ShouldBeUniqueUntilProcessing` per-user locking | Works against any cache store; switch the lock store from `redis` to `file` or `database` | Works natively |
| Horizon dashboard for end users | Not available in shipped build (Dev Mode only — see Pattern 4) | Available |
| Failed-jobs UI for end users | Built into Dev Mode UI (`Modules/DevMode/Internal/Http/Livewire/QueueInspector.php`) | Comes free via Horizon |
| Latency to job execution | ~3-5s (worker sleep interval) | ~100ms (Redis blpop) |
| Resource cost on idle laptop | ~30 MB resident (queue workers) | ~60 MB resident (Redis daemon + workers) |

**The single nuanced concern: `ShouldBeUniqueUntilProcessing`.** v1.0 uses Redis as the lock store (via `Cache::driver('redis')` carve-outs in `BoundaryArchTest`). For the shipped desktop bundle, the lock store needs to be `file` or `database`:

```php
// In every ShouldBeUniqueUntilProcessing job's uniqueVia():
public function uniqueVia(): Repository
{
    // v1.0:  return Cache::driver('redis');
    // v2.0:  return Cache::driver(config('cache.locks_store', 'database'));
    return Cache::driver(config('cache.locks_store'));
}
```

A new `config/cache.php` key `locks_store` defaults to `'database'` in the shipped build and `'redis'` in dev mode. The existing `BoundaryArchTest` carve-outs stay intact (the facade call shape is unchanged; only the resolved store rotates).

**Implication for `app/Providers/HorizonServiceProvider.php`:** it stays registered, but `boot()` early-exits when `! config('app.dev_mode')` so the `/horizon` route never registers in shipped builds.

**Example — `nativephp.config.php` queue worker config:**
```php
return [
    'queue_workers' => [
        'default' => [
            'queues' => ['default'],
            'memory_limit' => 256,
            'timeout' => 90,
            'sleep' => 3,
            'max_jobs' => 1000,
            'max_time' => 3600,
        ],
        'chains' => [
            // Dedicated worker for ResolveChainLinksJob so a slow chain
            // resolve never blocks the EmailScan pipeline.
            'queues' => ['chains'],
            'memory_limit' => 256,
            'timeout' => 300,
        ],
    ],
    'scheduler' => true,  // NativePHP launches `php artisan schedule:work`
];
```

### Pattern 4: Developer Mode Gating via `User::is_developer` Boolean + Middleware

**What:** A new `is_developer` boolean column on the `users` table. The Dev Mode UI is unreachable unless the authenticated user has the flag set. The `is_developer` flag is set by:
1. The first user to sign up (automatically, so the developer always has access on their own install).
2. Any existing developer can grant or revoke the flag on another user via the profile-selector UI.

**When to use:** Anywhere in `Modules/DevMode/`. The middleware lives at `Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php` and is registered against every route in `Modules/DevMode/Routes/web.php`.

**Trade-offs:** Forces every dev-only feature behind a single gate. The trade-off is consistency: instead of sprinkling `if (! $currentUser->isDeveloper()) abort(403);` across 20 surfaces, the middleware handles all of them, and the arch-test `EnsureDeveloperModeAppliedToAllDevModeRoutes` makes the rule mechanically enforceable.

**Example:**
```php
// Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php
final class EnsureDeveloperMode
{
    public function __construct(
        private readonly CurrentUser $currentUser,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->currentUser->isAuthenticated()) {
            return redirect()->route('login');
        }
        if (! $this->currentUser->user()->is_developer) {
            abort(404);  // 404, not 403: pretend the route doesn't exist
        }
        return $next($request);
    }
}
```

The `CurrentUser::user()->is_developer` access is the only new field; the contract gains a `public function isDeveloper(): bool` convenience method.

### Pattern 5: Artisan Command Whitelist (SAFE / DESTRUCTIVE / FORBIDDEN tiers)

**What:** The Dev Mode UI exposes an artisan runner backed by a strict whitelist. Each command falls into one of three tiers:

- **SAFE** (run with one click, no confirmation): `diederik:doctor`, `db:backup`, `diederik:failed-jobs list`, `queue:retry`, `cache:clear`, `migrate:status`.
- **DESTRUCTIVE** (require confirmation modal + reason text + a 5-second hold-to-confirm): `db:restore`, `migrate:rollback`, `migrate:fresh`, `diederik:failed-jobs prune`, `cache:forget`.
- **FORBIDDEN** (never exposed): anything outside the whitelist. The user can copy-paste commands into a terminal if they really need them; the in-app runner refuses.

The whitelist lives in `Modules/DevMode/Public/Services/ArtisanCommandRegistry.php`. New commands added to existing modules are added explicitly to the registry — they do not auto-appear.

**When to use:** Inside `Modules/DevMode/Internal/Http/Livewire/ArtisanRunner.php`. Every command execution writes an audit row to a new `dev_mode_audit` table: `user_id`, `command`, `arguments`, `started_at`, `finished_at`, `exit_code`, `stdout_preview`.

**Trade-offs:** Three tiers add code complexity vs "run any artisan command." The payoff is auditable, safer, and explicit — a partner accidentally clicking through Dev Mode can't run `migrate:fresh` without explicitly confirming what they're doing.

### Pattern 6: NativePHP-Rooted Module for OS-Shell Concerns

**What:** A new `Modules/Desktop/` module owns all NativePHP imports. Other modules consume `Modules\Desktop\Public\Contracts\*` interfaces. The Desktop module's `NativePhpEventListener` translates `Native\Laravel\Events\App\FileOpened` → `Modules\Desktop\Public\Events\FileOpenedFromOs` (a thin in-process event), which the Ingestion and Receipts modules subscribe to.

**When to use:** Whenever an existing module needs an OS capability. Example — when `Modules/Ingestion/Internal/Adapters/Asn/CsvAdapter` finishes parsing a CSV dropped via OS file-open, it dispatches a Livewire toast through `DesktopNotificationService` (NOT through a hard-coded NativePHP `Notification::send()` call).

**Trade-offs:** One extra hop (Native event → Desktop event → consumer). The payoff is that all 11 existing modules continue to work in Pest tests without NativePHP loaded (the contract is bound to a no-op stub in the test container).

**Example:**
```php
// Modules/Desktop/Internal/NativePhpEventListener.php
final class NativePhpEventListener
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function subscribe(): void
    {
        // Native\Laravel\Events\App\FileOpened is fired by NativePHP when
        // the user double-clicks a .eml or .csv file on the OS.
        $this->events->listen(NativeFileOpened::class, function (NativeFileOpened $event): void {
            $this->events->dispatch(new FileOpenedFromOs(
                absolutePath: $event->filePath,
                openedAt: $event->openedAt,
            ));
        });
    }
}

// Modules/Ingestion/Internal/Listeners/HandleFileOpenedFromOs.php
final class HandleFileOpenedFromOs
{
    public function __construct(
        private readonly ImportFileRouter $router,
    ) {}

    public function handle(FileOpenedFromOs $event): void
    {
        $this->router->route($event->absolutePath);
    }
}
```

## Data Flow

### Request Flow — Multi-User Aware

```
1.  Electron renderer (Chromium) → HTTP GET / on 127.0.0.1:PORT
2.  Laravel Router →  EnsureAuthenticatedMiddleware
                       │
                       ├─ NOT authenticated → redirect /login
                       │                       (Modules/Auth/Internal/Http/Livewire/LoginPage)
                       └─ authenticated → continues
3.  Livewire SFC constructor → DI resolves CurrentUser
4.  CurrentUser::user() → returns Modules\Core\Models\User#42
5.  Page calls TransactionQuery::recent() → Eloquent
6.  UserScope::apply() reads CurrentUser::id() === 42
7.  SQL: SELECT * FROM transactions WHERE user_id = 42 ORDER BY posted_at DESC LIMIT 20
8.  Rendered HTML → Livewire diff → Electron renderer
```

**The critical property:** steps 5–7 are **identical** to v1.0 — the existing 11 modules already query through `UserScope`. The only new piece is step 4 actually returning a meaningful `User#42` instead of the single-row `User#1` that v1.0 hard-codes via the seeder.

### User-Data Migration Flow (v1.0 → v2.0 first launch)

```
1.  v2.0 first launch  →  Electron main process starts
2.  NativePHP rewrites storage_path() to ~/Library/Application Support/diederik/
3.  Bundled PHP boots Laravel
4.  Modules\Core\Internal\Console\InstallCommand (auto-run if first-launch marker missing)
    │
    ├─ Checks: does ~/Library/Application Support/diederik/storage/database/data.sqlite exist?
    │  │
    │  ├─ YES → already-installed path → skip to schedule:work
    │  │
    │  └─ NO  → MigrateUserDataCommand
    │           │
    │           ├─ Looks for old v1.0 install at $HOME/code/diederik/database/database.sqlite
    │           │  (heuristic: check Herd's pinned site list + DEV_INSTALL_PATH env var)
    │           │
    │           ├─ If found AND user opts in via first-run dialog:
    │           │  ├─ Copy database.sqlite → storage/database/data.sqlite
    │           │  ├─ Copy storage/app/secrets/email-oauth.json → storage/app/secrets/
    │           │  ├─ Copy storage/app/backups/ → storage/app/backups/
    │           │  ├─ Copy storage/app/inbox/ → storage/app/inbox/
    │           │  ├─ Re-derive paths in OAuth secrets if any are absolute
    │           │  └─ Set existing User#1.is_developer = true
    │           │
    │           └─ If not found OR user opts out:
    │              ├─ php artisan migrate
    │              ├─ Show signup page on next request
    │              └─ First user to sign up gets is_developer = true
    │
    └─ Boot complete → renderer loads /
```

### Auto-Update Flow

```
1.  App startup → ElectronUpdateChannel::checkForUpdates() (debounced, once per launch + every 4h)
2.  electron-updater fetches https://github.com/<owner>/diederik/releases/latest/latest.yml
3.  Compares version against nativephp.config.php's `version`
4.  If update available:
    │
    ├─ Dispatches UpdateAvailable event (Modules\Core\Public\Events)
    ├─ SystemAlertsBanner shows a non-dismissable banner with "Restart to update"
    └─ Background download starts; on completion, dispatches UpdateDownloaded
5.  User clicks "Restart" → electron-updater.quitAndInstall()
    │
    ├─ Renderer is told to close (saves any in-flight Livewire form state)
    ├─ Bundled PHP terminates via NativePHP graceful-shutdown hook
    ├─ Electron quits, installer runs (signed; OS validates code signature)
    ├─ New binary launches → first-launch check sees database/data.sqlite already present
    │   → runs `php artisan migrate` (NativePHP's built-in update-time migration runner)
    │   → resumes
    └─ Renderer reopens to last route
```

## Module Boundaries — New v2.0 Invariants for `BoundaryArchTest`

These rules extend the existing 34+ `BoundaryArchTest` invariants. Each is concrete and arch-testable (matching the v1.0 style — file-walking + comment-stripping + regex when needed).

| # | Invariant name | Rule |
|---|----------------|------|
| 1 | `Modules\Auth\Internal is only used inside Modules\Auth` | Pest `arch()` rule, same shape as existing `Modules\Ledger\Internal` rule |
| 2 | `Modules\Desktop\Internal is only used inside Modules\Desktop` | Same shape |
| 3 | `Modules\DevMode\Internal is only used inside Modules\DevMode` | Same shape |
| 4 | `noNativePhpImportsOutsideDesktopModule` | `Native\Laravel\*` namespace may only be imported from `Modules\Desktop\` (excludes `tests/`) |
| 5 | `noAuthFacadeOrHelper` | Already covered by `noFacadeCallsFromCoreConsoleCommands` pattern — extend to **all** modules: `auth()`, `Auth::user()`, `Auth::id()`, `Auth::guard()` are forbidden anywhere outside `Modules\Core\Public\Services\CurrentUserService` |
| 6 | `noStoragePathHardCodedOutsideUserDataPathService` | Only `Modules\Core\Public\Services\UserDataPathService` may call `$app->storagePath(...)` (or `storage_path()` if Larastan misses it). All other module code consumes `UserDataPath` contract |
| 7 | `noDatabasePathInDomainCode` | `database_path(...)` calls are forbidden in `Modules/*/Internal/*` and `Modules/*/Public/*`. Permitted in migrations only |
| 8 | `noArtisanRunFromRequestLifecycle` | `Illuminate\Contracts\Console\Kernel::call()` may only be imported from `Modules\DevMode\Internal\Services\ArtisanRunner` — prevents accidental "run a command on user request" leaks |
| 9 | `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` | Walks `Modules/DevMode/Routes/web.php` and asserts every route is inside a group with `middleware([EnsureDeveloperMode::class, ...])` |
| 10 | `noDevModeImportsFromRegularModuleHttp` | `Modules\Categorization\Internal\Http`, `Modules\Ledger\Internal\Http`, etc. — none of them import `Modules\DevMode\*` |
| 11 | `userIsDeveloperGateOnDestructiveCommands` | `ArtisanCommandRegistry::tier(string $command): Tier` covers every command in the `DESTRUCTIVE` array — enforced via a snapshot test rather than arch, but lives alongside arch tests |
| 12 | `noOAuthSecretsTableInDatabase` | Already exists (`noOAuthTokensInEmailScanSchema`) — extend to all modules: no migration may declare `refresh_token` / `client_secret` / `access_token` columns |
| 13 | `noLaravelGlobalHelpersAnywhereInModules` | The `noLaravelGlobalHelpersInCoreConsoleCommands` invariant — scope expanded from `Modules/Core/Internal/Console/` to all of `Modules/*/Internal/` and `Modules/*/Public/` (excludes migrations) |
| 14 | `noHorizonImportsInShippedBuildCode` | `Laravel\Horizon\*` namespace may only be imported from `app/Providers/HorizonServiceProvider.php` and `Modules/DevMode/Internal/Services/*` |
| 15 | `noElectronAutoUpdaterImportsOutsideElectronUpdateChannel` | `electron-updater` and IPC bridges are wrapped by `Modules\Core\Public\Services\ElectronUpdateChannel` — no other PHP class may issue IPC calls directly |
| 16 | `noUserIdScopeBypass` | Forbid `Model::withoutGlobalScope(UserScope::class)` outside `Modules/Core/Internal/Console/` (where backup + doctor + InstallCommand legitimately need a cross-user view) |
| 17 | `noCurrentUserResolutionInJobConstructor` | Queued job constructors may not type-hint `CurrentUser` — the user-id must be passed as a `int` parameter, because the request-scoped guard is gone by the time the worker dequeues. Arch-tests `Modules/*/Internal/Jobs/*.php` and grep for the `CurrentUser` import |
| 18 | `noDevModeUiInRendererJsonResponses` | Any controller / Livewire endpoint that returns JSON must not include `dev_mode_audit`, `failed_jobs`, or `system_alerts` row payloads in the response — arch-tested via a regex against route closures' return types, plus a per-Livewire-component snapshot test |
| 19 | `everyDomainModelUsesBelongsToUser` | Already covered by `UserIdColumnArchTest` — extend to assert the model class declares `use BelongsToUser;`, not just the column |
| 20 | `noSecretsTableRowsInRendererJson` | Snapshot-tested: any Livewire component that ever serialises `inboxes` or OAuth data filters secret columns server-side |

## Scalability Considerations

| Concern | At 1 user (the developer) | At 2 users (partner) | At 5 users (extended beta) |
|---------|--------------------------|----------------------|---------------------------|
| SQLite write concurrency | Fine (single writer; WAL allows concurrent readers) | Fine — one human types at a time | Fine; only one user is typically interactive |
| Background job throughput | ~10 jobs/min during inbox backfill | ~20 jobs/min (parallel inboxes) | ~50 jobs/min — still inside `database` queue capacity |
| Queue worker count | 1 default + 1 chains worker | Same — workers are per-machine, not per-user | Same |
| Disk size (10 years of data) | ~200 MB | ~400 MB | ~1 GB — well within macOS Application Support free space |
| OAuth secrets file size | ~5 KB | ~10 KB | ~25 KB — single chmod-600 file remains fine |
| Auto-update bandwidth | 80 MB / release / user | Same | Same |

Outside the partner-sharing scope, diederik is **never** designed for 100+ users on one machine. If that ever becomes a need (it won't — it's a personal-finance app), the migration path is SQLite → PostgreSQL 16 (Laravel makes this a one-config swap + dump/load).

## Recommended Build Order

Strict dependency-driven ordering across the v2.0 phases:

```
PHASE A — Multi-user activation (depends on: nothing new)
    ├─ Fortify already installed (composer.json line 11)
    ├─ User model already exists with hashed-password cast
    ├─ Add Modules/Auth/ — Login / Signup / ProfileSelector / Logout Livewire SFCs
    ├─ Add is_developer column migration
    ├─ Update CurrentUserService — already DI-friendly, no changes
    ├─ Activate UserScope across all queries (already wired via BelongsToUser; just remove
    │   any v1.0 hard-coded user_id = 1 references — grep them)
    ├─ Add new arch tests #5, #16, #17, #19
    └─ Validates: Sign in as User A → see only User A data; sign in as User B → see only B
       (Per-user UAT: a partner-shared install on the developer's machine works
       end-to-end before any packaging work begins.)

PHASE B — User-data dir abstraction (depends on: nothing — can land in parallel with A,
          but A's commit hardens the multi-user path that B then carries into the desktop bundle)
    ├─ Add Modules/Core/Public/Contracts/UserDataPath + UserDataPathService
    ├─ Modify config/database.php — database = storage_path('database/data.sqlite')
    ├─ Rewire OAuthSecretsRepository, BackupDatabaseCommand, RestoreDatabaseCommand,
    │   BackupFreshnessProbe, the inbox drop-folder scanner — all consume UserDataPath
    ├─ Add MigrateUserDataCommand (v1.0 in-place → v2.0 paths)
    ├─ Add new arch tests #6, #7
    └─ Validates: php artisan migrate fresh creates DB at storage/database/data.sqlite;
       db:backup writes to storage/app/backups; OAuth secrets file at expected path

PHASE C — Queue rewire (depends on: B — UserDataPath ensures `database` queue tables
          live in the right place; depends on: A — multi-user lock-keys partition cleanly)
    ├─ Modify config/queue.php — default = 'database' for shipped, 'redis' for dev mode
    ├─ Modify config/cache.php — locks_store = 'database' for shipped, 'redis' for dev mode
    ├─ Audit each ShouldBeUniqueUntilProcessing job — uniqueVia() reads config('cache.locks_store')
    ├─ HorizonServiceProvider.boot() guards on config('app.dev_mode')
    ├─ Add new arch test #14
    └─ Validates: with QUEUE_CONNECTION=database, chain resolution + inbox backfill
       + drift detection + recurring sweep + forecast all succeed; uniqueness is honoured

PHASE D — Desktop shell (depends on: B + C — both must be in place before NativePHP
          rewrites storage_path() and supervises the queue workers)
    ├─ composer require nativephp/desktop
    ├─ Add nativephp.config.php — queue_workers, scheduler, app meta
    ├─ Add Modules/Desktop/ — SystemTrayService, AppMenuBuilder, FileOpenRouter,
    │   NativePhpEventListener, DesktopNotificationService
    ├─ Add new arch tests #2, #4, #15
    └─ Validates: php artisan native:run launches a window; the dashboard loads;
       chain resolution runs in a NativePHP-supervised worker; .eml drag-drop on the app icon
       opens the receipt review screen

PHASE E — Developer mode UI (depends on: A — is_developer flag; depends on: D — Dev Mode
          UI is exposed only in the desktop shell, so the desktop bundle has to exist first)
    ├─ Add Modules/DevMode/ — DevConsolePage, ArtisanRunner, LogTailer, QueueInspector,
    │   DoctorPanel, EnsureDeveloperMode middleware, ArtisanCommandRegistry
    ├─ Add dev_mode_audit migration
    ├─ Add new arch tests #3, #8, #9, #10, #11, #18, #20
    └─ Validates: log in as a developer → /dev visible; non-developer gets 404;
       db:backup runs from the UI; db:restore needs the hold-to-confirm

PHASE F — CI/CD pipeline (depends on: A through E — the gates need real code to gate)
    ├─ Add .github/workflows/ci.yml — pint --test + larastan + pest, all 3 must pass
    ├─ Add .github/workflows/release.yml — tag-triggered build matrix:
    │   ├─ macos-13   → .dmg     (Apple Developer ID signed + notarised)
    │   ├─ windows-2022 → .msi/.exe (EV code-signing certificate)
    │   └─ ubuntu-22.04 → .AppImage + .deb
    ├─ Wire electron-builder.yml — publish: github
    ├─ GitHub Encrypted Secrets: APPLE_ID, APPLE_PASSWORD, APPLE_TEAM_ID, CSC_LINK (Win),
    │   CSC_KEY_PASSWORD, GH_TOKEN (publish to GitHub Releases)
    └─ Validates: PR fails on pint regression; tag push produces signed installers
       on all 3 OSes; downloaded .dmg installs and runs

PHASE G — Auto-update plumbing (depends on: F — installer pipeline must exist to publish
          the update artefacts)
    ├─ Wire electron-updater inside Electron main process (NativePHP exposes a hook)
    ├─ Add Modules/Core/Public/Contracts/UpdateChannel + ElectronUpdateChannel service
    ├─ Add SystemAlertsBanner integration — "Restart to update" alert
    ├─ Test path: tag v2.0.1 → release pipeline publishes → existing v2.0.0 install
    │   detects + downloads + prompts → restart applies
    └─ Validates: bump version, push tag, wait 10 minutes, open v2.0.0 client, see prompt

PHASE H — Public-release boundary review (depends on: A through G — final hardening)
    ├─ Deep Modules code review — cross-module hygiene, DI compliance, dead-code scan
    ├─ GSD-leakage redaction sweep — `.planning/` references in code, PHPDocs, comments
    ├─ Hippocratic License 3.0 + SECURITY.md + CONTRIBUTING.md + CODE_OF_CONDUCT.md
    ├─ README rewrite with logo.svg as hero
    ├─ Renderer-JSON audit — add arch test #18, #20
    └─ Validates: clone the public repo on a fresh machine → install → first run works
```

### Why this order

- **A before everything else** — multi-user must be solid on the developer's existing Herd setup before any desktop packaging work. If multi-user is wrong, the desktop bundle ships a broken auth experience.
- **B before C** — the `database` queue tables need to live at `storage_path('database/data.sqlite')`, which means `UserDataPath` must be wired first. Otherwise switching `QUEUE_CONNECTION=database` writes to the bundled (read-only) database path inside the app bundle.
- **C before D** — NativePHP's `queue_workers` config supervises `php artisan queue:work`. If the queue driver isn't on `database` first, the desktop bundle would need to ship Redis (rejected).
- **D before E** — Dev Mode UI lives inside the desktop shell and renders alongside the rest of the Livewire app. It can be developed against a Herd dev environment, but its acceptance test ("open the menu bar tray, click 'Open Dev Console', see the running queue workers") needs the NativePHP shell live.
- **F after A-E** — the CI pipeline needs the real codebase + arch tests to gate against. Building the pipeline before the code exists is theatre.
- **G after F** — electron-updater needs a release pipeline to fetch updates from. No release pipeline → nothing to update from.
- **H last** — the public-release boundary review is the load-bearing exit gate. It can't pass until everything else passes.

## Anti-Patterns to Avoid

### Anti-Pattern: Shipping Redis Inside the Electron Bundle

**Why:** Three OS-specific Redis binaries to code-sign per release; Windows Redis is unmaintained (Microsoft's port stopped in 2020); installer size bloats by ~60 MB; the supervisor footprint adds 60 MB resident on an idle laptop. The single justification (Horizon dashboard for end users) is satisfied better by the in-app `QueueInspector` Livewire component that reads the `jobs` and `failed_jobs` tables directly. **Use the `database` queue driver for shipped builds; keep Redis as a dev-mode-only convenience.**

### Anti-Pattern: Using `app/Models/User` in v2.0

**Why:** v1.0 already establishes `Modules\Core\Models\User` as canonical, with `App\Models\User` aliased via `class_alias()` in `CoreServiceProvider::register()` for framework compatibility (Fortify, notification routing, `config/auth.php`). Reaching for `app/Models/User.php` in v2.0 would shatter the alias. **Stay with the module-namespaced User.**

### Anti-Pattern: Per-User SQLite Files

**Why:** Multiplies the backup surface (5 databases means 5 backup jobs, 5 schedules, 5 retention policies), defeats partner-sharing (each user gets a private silo, no cross-user reporting ever possible), and bypasses the `UserIdColumnArchTest` work v1.0 already did. **One SQLite per machine, scoped by `user_id`. The OS user-data directory is the per-OS-user isolation boundary.**

### Anti-Pattern: Allowing `Auth::user()` Anywhere in `Modules/`

**Why:** v1.0 already built and validated the DI-friendly `CurrentUser` contract. Letting `Auth::user()` creep back in (especially in multi-user UAT phases) would lock the codebase to the request-scoped Laravel guard and break testability. **The new arch test #5 forbids `Auth::*` facade and `auth()` helper across all of `Modules/`.**

### Anti-Pattern: Calling `Application::storagePath()` Directly From Domain Code

**Why:** NativePHP's `storage_path()` rewrite is implicit, fragile, and easy to forget in tests. The UserDataPath contract makes the dependency explicit + mockable. **Arch test #6 prevents direct `storagePath()` calls outside `UserDataPathService`.**

### Anti-Pattern: Letting the Dev Mode UI Expose Arbitrary Artisan Commands

**Why:** The first contributor who needs `migrate:fresh` will type it into the artisan runner, the partner using the install will accidentally click it, and the database is gone. The 3-tier whitelist (`SAFE` / `DESTRUCTIVE` / `FORBIDDEN`) is the safety belt. **Treat the artisan runner as a UI for a curated subset, not a passthrough.**

### Anti-Pattern: Auto-Update Without Signature Verification

**Why:** electron-updater verifies code signatures by default on macOS and Windows — but only if the build pipeline actually signed the installer. A skipped signing step means the auto-updater silently accepts any payload that reaches the `latest.yml` feed URL, which is a remote-code-execution primitive against every running install. **Phase F must complete signing on all three OSes before Phase G ships auto-update.**

## Sources

- [NativePHP Desktop v2 — Files documentation](https://nativephp.com/docs/desktop/2/digging-deeper/files) — HIGH (official). Confirmed `Application::storagePath()` rewrite, per-OS appdata location mapping (macOS `~/Library/Application Support/`, Windows `%APPDATA%`, Linux `~/.config/`), the `local` filesystem disk landing at `appdata/storage/app`.
- [NativePHP Desktop v2 — Databases documentation](https://nativephp.com/docs/desktop/2/digging-deeper/databases) — HIGH (official). Confirmed SQLite is the default; production builds detect version changes and auto-run migrations; data persists across updates in the appdata folder.
- [NativePHP Desktop v2 — Queues documentation](https://nativephp.com/docs/desktop/2/digging-deeper/queues) — HIGH (official). Confirmed NativePHP boots its own queue worker(s) via `config/nativephp.php` → `queue_workers` array. **No Horizon support documented; no Redis dependency required.** Jobs persist in the SQLite database.
- [NativePHP Desktop v2 — Installation](https://nativephp.com/docs/desktop/2/getting-started/installation) — HIGH (official). PHP 8.3+ / Laravel 11+ / Node 22+ / macOS 12+/Win 10+/Linux. Released as v2.x current.
- [NativePHP Desktop v2 — Menu documentation](https://nativephp.com/docs/desktop/2/digging-deeper/menu) — HIGH (official). System tray, menus, notifications, file management, global hotkeys all available as injectable Laravel-style services.
- [electron-builder — Auto-update](https://www.electron.build/auto-update.html) — HIGH (official). Confirmed GitHub Releases is a first-class publish target; `latest.yml` is the manifest; signature verification is automatic when builds were signed.
- [Electron — Code Signing](https://www.electronjs.org/docs/latest/tutorial/code-signing) — HIGH (official). Confirmed `CSC_LINK` + `CSC_KEY_PASSWORD` env vars on Windows; `APPLE_ID` + `APPLE_PASSWORD` + `APPLE_TEAM_ID` for macOS notarisation.
- [Distributing NativePHP Apps with Auto-Update Support](https://www.thecodingdev.com/2025/04/distributing-nativephp-apps-with-auto.html) — MEDIUM (third-party). End-to-end pipeline with electron-updater + GitHub Actions. Useful template; verify against current NativePHP docs.
- [NativePHP/electron on Packagist](https://packagist.org/packages/nativephp/electron) — HIGH (official). Active maintenance; v2 currently published.
- diederik v1.0 codebase — read directly:
  - `Modules/Core/Public/Contracts/CurrentUser.php` — confirms DI-friendly auth seam already exists.
  - `Modules/Core/Public/Services/CurrentUserService.php` — uses `Illuminate\Contracts\Auth\Factory`, NOT a facade.
  - `Modules/Core/Public/Concerns/BelongsToUser.php` + `Modules/Core/Public/Scopes/UserScope.php` — already apply per-user scope.
  - `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` — chmod-600 atomic-rename pattern proven.
  - `Modules/Core/Internal/Console/InstallCommand.php` — `--launchd` flag proves "ship templates with placeholders" approach.
  - `tests/Contracts/BoundaryArchTest.php` — 34+ arch invariants; v2.0 additions follow the same style.
  - `composer.json` — Fortify already installed; Horizon already installed; NativePHP NOT yet installed.
