# `Core` — code

The file-level map for the module.

## Directory layout

```
Modules/Core/
├── Public/
│   ├── Actions/
│   │   └── AcknowledgeSystemAlert.php
│   ├── Bootstrap/
│   │   └── EnsureAppKey.php
│   ├── Concerns/
│   │   └── BelongsToUser.php
│   ├── Contracts/
│   │   ├── Clock.php
│   │   ├── CurrentUser.php
│   │   └── PublisherManifestFetcher.php
│   ├── Controllers/
│   │   └── HealthController.php
│   ├── Dto/
│   │   └── UpdateManifestDto.php
│   ├── Events/
│   │   └── UserInstalled.php
│   ├── Exceptions/
│   │   └── NotAuthenticatedException.php
│   ├── Scopes/
│   │   └── UserScope.php
│   ├── Services/
│   │   ├── CurrentUserService.php
│   │   ├── ElectronUpdateChannel.php
│   │   ├── SecretsColumnRegistry.php
│   │   ├── SystemAlertQuery.php
│   │   ├── SystemClock.php
│   │   └── UserDataPathService.php
│   └── Support/
│       ├── LockStore.php
│       └── SafeTrace.php
├── Internal/
│   ├── Providers/
│   │   ├── HealthCheckServiceProvider.php
│   │   └── SqliteOptimizationsProvider.php
│   ├── Console/
│   │   ├── InstallCommand.php
│   │   ├── DoctorCommand.php
│   │   ├── BackupDatabaseCommand.php
│   │   ├── RestoreDatabaseCommand.php
│   │   ├── FailedJobsCommand.php
│   │   ├── Probes/
│   │   │   ├── Probe.php
│   │   │   ├── ProbeResult.php
│   │   │   ├── BootProbeState.php
│   │   │   ├── PhpVersionProbe.php
│   │   │   ├── ComposerVersionProbe.php
│   │   │   ├── NodeVersionProbe.php
│   │   │   ├── SqliteCliVersionProbe.php
│   │   │   ├── ExternalToolVersionProbe.php
│   │   │   ├── BackupFreshnessProbe.php
│   │   │   ├── WalModeProbe.php
│   │   │   └── SynchronousModeProbe.php
│   │   └── Support/
│   │       ├── BackupRetentionPolicy.php
│   │       └── DurationParser.php
│   ├── Http/
│   │   ├── Middleware/
│   │   │   ├── LoopbackOnly.php
│   │   │   └── NoStoreFinancialData.php
│   │   └── Livewire/
│   │       ├── Dashboard.php
│   │       ├── SettingsPage.php
│   │       ├── SystemAlertsBanner.php
│   │       ├── AppSidebar.php
│   │       └── HelpDataLocations.php
│   └── Listeners/
│       └── HealthCheckListener.php
├── Models/
│   ├── User.php
│   ├── SystemAlert.php
│   └── UserPreference.php
├── Database/Migrations/
├── Routes/
│   ├── web.php
│   └── console.php
├── Resources/views/
├── Providers/
│   └── CoreServiceProvider.php
└── tests/
    └── Feature/
```

## Public API

- **Concerns/**
  - `BelongsToUser` trait — every user-scoped Eloquent model uses this.
    Boot hook resolves `UserScope` via `Container::getInstance()->make()`
    (single sanctioned container touch-point — Eloquent boot is static).
- **Contracts/**
  - `Clock::now()` — returns `CarbonImmutable`.
  - `CurrentUser::id()`, `user()`, `periodStartDay()`,
    `isAuthenticated()`.
  - `PublisherManifestFetcher::fetch()` — `Desktop` provides the
    concrete impl.
- **Scopes/**
  - `UserScope::apply($builder, $model)` — `where('user_id',
    CurrentUser::id())`. Skipped when the auth factory is unbound (CLI
    - tests not in HTTP context).
- **Services/**
  - `SystemClock` — production `Clock`.
  - `CurrentUserService` — production `CurrentUser`. Throws
    `NotAuthenticatedException` from `resolveUser()` when no guard
    user exists.
  - `UserDataPathService::databaseFile()`, `appPath()`,
    `appRelative($name)`, `storagePath($subdir)`, `backupsPath()`.
    The single sanctioned `base_path()` caller; the
    `NATIVEPHP_STORAGE_PATH` env var redirects every accessor.
  - `ElectronUpdateChannel::poll()`, `verifyManifest($manifest)`,
    `verifyBinary($binaryPath, $expectedSha512)`.
  - `SecretsColumnRegistry::columns()` (static), `all()` (instance) —
    enumerates `oauth_secrets.access_token`,
    `oauth_secrets.refresh_token`, `oauth_secrets.client_secret`,
    `users.password`, `users.remember_token`,
    `user_recovery_codes.code_hash`.
  - `SystemAlertQuery::activeFor($user)`.
- **Actions/**
  - `AcknowledgeSystemAlert::__invoke($alertId, $user)` — single
    sanctioned writer of `system_alerts.acknowledged_at`.
- **Bootstrap/**
  - `EnsureAppKey::run()` — checks sentinel, runs
    `key:generate --force`, writes sentinel. Idempotent on re-run.
- **Controllers/**
  - `HealthController::__invoke()` — `/health` endpoint.
- **Support/**
  - `LockStore::forUniqueJobs()` — wraps the Cache facade.
  - `SafeTrace` — redacts sensitive args from stack traces.

## Internal services

- `Internal/Providers/SqliteOptimizationsProvider` — registers a
  connection-resolved listener that runs the WAL + busy_timeout +
  synchronous=NORMAL PRAGMAs on every SQLite connection
  (see [ADR 0005](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0005-sqlite-wal.md)).
- `Internal/Providers/HealthCheckServiceProvider` — wires the boot
  probes used by `DoctorCommand` and `HealthCheckListener`.
- `Internal/Console/InstallCommand` — `beatrax:install`. Creates the
  user (or confirms an existing one), dispatches `UserInstalled` so
  listeners re-seed reference data, prints the recovery codes once.
- `Internal/Console/DoctorCommand` — `diederik:doctor`. Runs every
  probe (PHP / Composer / Node / SQLite CLI / external tools / backup
  freshness / WAL / synchronous mode) and prints a coloured pass /
  warn / fail report.
- `Internal/Console/BackupDatabaseCommand` — `db:backup`. Copies the
  SQLite file under the backups directory; honours the retention
  policy.
- `Internal/Console/RestoreDatabaseCommand` — `db:restore`. Replaces
  the live DB with a backup file after operator confirmation.
- `Internal/Console/FailedJobsCommand` — operator inspection of the
  `failed_jobs` table without leaving the CLI.
- `Internal/Console/Probes/Probe` — interface. Each probe returns a
  `ProbeResult` carrying `(level, summary, detail)`.
- `Internal/Http/Middleware/LoopbackOnly` — refuses any request whose
  `SERVER_ADDR` is not a loopback address (127.x.x.x, `::1`,
  IPv4-mapped-IPv6). Throws `NotFoundHttpException` so the app never
  acknowledges its existence to a non-loopback caller.
- `Internal/Http/Middleware/NoStoreFinancialData` — sets
  `Cache-Control: no-store` on every authenticated response so the
  browser never caches a transaction list.
- `Internal/Http/Livewire/Dashboard` — the `/` landing page.
- `Internal/Http/Livewire/SettingsPage` — the `/settings` surface
  (theme, currency view, close-behavior, period-start-day, dev-mode
  toggle, community-list toggles).
- `Internal/Http/Livewire/SystemAlertsBanner` — the layout banner
  consuming `SystemAlertQuery` + `AcknowledgeSystemAlert`.
- `Internal/Http/Livewire/AppSidebar` — the navigation sidebar.
- `Internal/Http/Livewire/HelpDataLocations` — `/help/data-locations`.
  Renders the export-everything action surfaced by [`DevMode`](../dev-mode/architecture.md).
- `Internal/Listeners/HealthCheckListener` — runs probes during the
  install ceremony so a fresh user sees pass / warn / fail before any
  data is imported.

## Models + migrations

- `Models/User` — extends `Illuminate\Foundation\Auth\User`. Aliased
  to `App\Models\User` by the provider so framework consumers
  (`config/auth.php`, `Tests\TestCase`, notification routing) resolve
  the same class. Fields: `username`, `password`, `is_developer`,
  `force_password_change_at_next_login`, `period_start_day`,
  `default_currency_view`, `theme`, `close_behavior`, `community_settings`
  (JSON), `receipt_conflict_resolution`, `auto_import_drop_folder`.
- `Models/SystemAlert` — maps to `system_alerts`. Uses
  `BelongsToUser`. Per-row kind drives the banner's render.
- `Models/UserPreference` — maps to `user_preferences`. Per-user
  key/value store for preferences that did not justify a column on
  `users` (e.g. counterparty-index view, skipped-update-version list).

Migrations:

- `2026_05_12_000001_create_users_table.php` — Laravel's seeded
  users + email; later reshaped by `Modules/Auth` migrations that
  swap `email` for `username` and add `is_developer` + the
  forced-change flag.
- `2026_05_12_000002_create_password_reset_tokens_table.php` — kept
  for framework compatibility; never written to (no SMTP-driven
  password reset; see [ADR 0010](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0010-recovery-codes-no-smtp.md)).
- `2026_05_12_000003_create_sessions_table.php` — `database`-driver
  sessions co-resident with the app schema (see
  [ADR 0007](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0007-database-queue-driver.md)).
- `2026_05_13_010001_add_default_currency_view_to_users.php` — per-user
  currency-view preference column.
- `2026_05_17_010007_add_auto_import_drop_folder_to_users.php` —
  per-user toggle for the auto-watch drop folder.
- `2026_05_20_010001_create_system_alerts_table.php` — the banner's
  source of truth.
- `2026_05_22_000001_add_theme_to_users.php` — light / dark / system
  theme preference.
- `2026_05_22_000002_add_close_behavior_to_users.php` —
  minimize-to-tray vs quit-on-close (consumed by [`Desktop`](../desktop/code.md)).
- `2026_05_26_000005_add_community_settings_to_users.php` —
  per-user community-list opt-in JSON.
- `2026_05_27_000003_create_user_preferences_table.php` — generic
  per-user key/value preference store.
- `2026_05_28_000001_add_counterparty_index_view_to_user_preferences.php`
  — preference key for the `/counterparties` index view mode.
- `2026_05_28_000001_add_skipped_update_versions_to_user_preferences.php`
  — preference key for the auto-update "skip this version" list.

## Provider wiring

`CoreServiceProvider::register()`:

- Registers `SqliteOptimizationsProvider` and
  `HealthCheckServiceProvider` (nested providers).
- Singletons `BootProbeState`, `SystemAlertQuery`,
  `AcknowledgeSystemAlert`, `UserDataPathService`.
- Binds `Clock` → `SystemClock`.
- Binds `CurrentUser` → `CurrentUserService`.
- Aliases `App\Models\User` to `Modules\Core\Models\User` if the
  default class is not already loaded.

`CoreServiceProvider::boot()`:

- Loads migrations, web/console routes, views.
- Registers five Livewire components under the `core.*` namespace.
- Registers the five CLI commands when running in console.
