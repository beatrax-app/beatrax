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
│   ├── Enums/                (Country, Duration, InboxMessageStatus,
│   │                          JobRunStatus, Locale, MobilePlatform,
│   │                          SnoozeWindow, SystemAlertSeverity, Theme,
│   │                          TransitionActor, UpdateAlertKind)
│   ├── Events/
│   │   └── UserInstalled.php
│   ├── Http/
│   │   └── Livewire/
│   │       └── Concerns/
│   │           ├── DispatchesToast.php
│   │           ├── HoldsFlashMessage.php
│   │           └── ReportsFieldRejections.php
│   ├── Exceptions/
│   │   └── NotAuthenticatedException.php
│   ├── Scopes/
│   │   └── UserScope.php
│   ├── Services/
│   │   ├── CurrentUserService.php
│   │   ├── DevConsoleBuildGate.php
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
│   │       ├── BackupSidecar.php
│   │       └── DurationParser.php
│   ├── Http/
│   │   ├── Middleware/
│   │   │   ├── LoopbackOnly.php
│   │   │   ├── PhpSapi.php
│   │   │   ├── TrustedHostGuard.php
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
│   └── components/           (the cross-module presentational components
│                              — x-core::app-mark, progress-bar, spinner,
│                              form-field and the rest of the x-core:: set)
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
- **Enums/**
  - `SnoozeWindow` — `OneWeek = '1w'`, `OneMonth = '1m'`,
    `ThreeMonths = '3m'`. `targetFrom($now)` returns the ISO8601 target
    measured from the moment it is handed (never from a clock of its
    own, so `setTestNow()` stays authoritative); `targetsFrom($now)`
    builds the whole `value => target` map a review page hands its
    blade; `labelKey($group)` appends `.snooze_<value>` to a caller's
    lang group, so each queue keeps its own three labels. Read by
    `DriftAlerts`, `Recurring` and `Anomaly`.
- **Scopes/**
  - `UserScope::apply($builder, $model)` — `where('user_id',
    CurrentUser::id())`. Skipped when the auth factory is unbound, as
    it is under the CLI and in tests outside an HTTP context.
- **Services/**
  - `SystemClock` — production `Clock`.
  - `CurrentUserService` — production `CurrentUser`. Throws
    `NotAuthenticatedException` from `resolveUser()` when no guard
    user exists.
  - `UserDataPathService::databaseFile()`, `appPath($relative)`,
    `storageBase()`, `backupsPath()`, `secretsPath()`,
    `frameworkPath($sub)`, `logsFile()`, `publicPath($relative)`,
    `projectPath($relative)`. The single sanctioned `base_path()`
    caller; the `NATIVEPHP_STORAGE_PATH` env var redirects every
    accessor.
  - `DevConsoleBuildGate::permits()` — whether this build carries the
    Dev Console at all. `local` and `testing` are the development
    environments, held as an allow-list so no other spelling of a
    shipped build reads as a checkout. It lives here rather than in
    `DevMode` because `Shell` and `Desktop` both have to ask, and
    `DevMode` already reads `Shell`; see
    [the console on a shipped build](../dev-mode/the-console-on-a-shipped-build.md).
  - `ElectronUpdateChannel::poll()`, `verifyManifest($manifest)`,
    `verifyBinary($binaryPath, $expectedSha512)`.
  - `SecretsColumnRegistry::columns()` (static), `all()` (instance) —
    enumerates `oauth_secrets.access_token`,
    `oauth_secrets.refresh_token`, `oauth_secrets.client_secret`,
    `users.password`, `users.remember_token`,
    `user_recovery_codes.code_hash`.
  - `SystemAlertQuery::active(?User $user)`, `visibleTo($alertId,
    $user)`, `count(?User $user)`. The nullable user is deliberate and
    is not "any user": a user reads their own rows plus the
    system-wide ones, while a NULL narrows the read to system-wide
    rows alone — the shape a background probe needs when it has no
    auth context.
- **Actions/**
  - `AcknowledgeSystemAlert::__invoke($alertId, $user)` — single
    sanctioned writer of `system_alerts.acknowledged_at`.
- **Bootstrap/**
  - `EnsureAppKey::run()` — checks sentinel, runs
    `key:generate --force`, writes sentinel. Idempotent on re-run.
- **Controllers/**
  - `HealthController::__invoke()` — `/health` endpoint; the probe behind it
    is `Internal/Support/RuntimeHealthSnapshot`.
- **Http/Livewire/Concerns/**
  - `HoldsFlashMessage` — the `$flashMessage` property every Livewire page
    renders with `@if ($flashMessage !== '')`.
  - `ReportsFieldRejections::reportRejection($exception, $fallbackKey)` —
    places a `ValidationException`'s messages on the using component's own
    `FIELD_KEYS`, and falls back to the flash line for a rejection that
    names no box. Used by `Auth`'s `SignupPage` and `Mobile`'s
    `MobileImportBootstrap`.
- **Support/**
  - `LockStore::forUniqueJobs()` — wraps the Cache facade.
  - `SafeTrace::cap($throwable, $basePath, $maxLines)` — a trace built
    from the frames, so no argument of any frame is rendered.

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
- `Internal/Console/DoctorCommand` — `beatrax:doctor`. Runs every
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
  `SERVER_ADDR` is neither a loopback address (127.x.x.x, `::1`,
  IPv4-mapped-IPv6) nor an interface the install recorded itself as
  serving. Throws `NotFoundHttpException` so the app never
  acknowledges its existence to a caller it does not serve.
- `Internal/Support/NetworkBoundary` — the one object both address
  gates read, so they cannot allow different things. Owns the served
  interfaces (`BEATRAX_SERVED_INTERFACES`), the `APP_URL` host, and
  what each may not be talked into meaning.
- `Internal/Support/NetworkAddress` — `inet_pton` comparison of two
  addresses, collapsing the IPv4-mapped-IPv6 spelling onto the IPv4
  one, plus the loopback and wildcard tests both gates ask.
- `Internal/Console/Probes/NetworkBoundaryProbe` — the operator's
  read of the boundary: interfaces taken, entries refused, and whether
  `APP_URL` agrees with the widening.
- `Internal/Http/Middleware/NoStoreFinancialData` — sets
  `Cache-Control: no-store` on every authenticated response so the
  browser never caches a transaction list.
- `Internal/Http/Livewire/Dashboard` — the `/` landing page.
- `Internal/Http/Livewire/SettingsPage` — the `/settings` surface
  (theme, currency view, close-behavior, period-start-day, dev-mode
  toggle, community-list toggles).
- `Public/Http/Livewire/SystemAlertsBanner` — the layout banner
  consuming `SystemAlertQuery` + `AcknowledgeSystemAlert`. Its view
  renders one row shape for every severity:
  `Resources/views/livewire/partials/system-alert-body.blade.php` holds
  the message / timestamp / actions layout, and severity only chooses
  the wrapping `x-core::alert`'s tone plus its live-region semantics —
  `role="alert"` for critical, `aria-live="polite"` for the other two.
  The three severities each carried a byte-identical copy of that body
  before, so a layout fix had to be applied three times.
- `Internal/Http/Livewire/AppSidebar` — the navigation sidebar.
- `Resources/views/components/app-mark.blade.php` — `x-core::app-mark`,
  the only site that names `resources/brand/logo.svg`. Nine screens drew
  the mark by hand before it existed. `:size` (px, or `false` for a mark
  sized by class), `alt`, `:decorative` (aria-hidden) and `class` are the
  four ways the call sites differ; the defaults are the four lock and
  setup screens that agreed on all of them.
- `Resources/views/components/password-requirements.blade.php` —
  `x-core::password-requirements`, the live checklist under a new-password
  pair. The desktop signup screen and the mobile import screen each carried
  a byte-identical copy that differed only in lang namespace, so the five
  strings are props; the id it emits is the `aria-describedby` target the
  password field names. The reading of the two boxes is
  `Alpine.data('passwordStrength')` in `resources/js/app.js`, taking the
  minimum length and the two `$wire` property names as arguments. That
  minimum is [`Auth`](../auth/code.md)'s `PasswordPolicy::MINIMUM_LENGTH`,
  the same constant every server-side gate measures against.
- `Resources/views/components/locale-select.blade.php` —
  `x-core::locale-select`, the language `<select>` with its 26 options and
  the `LocaleNegotiator::SYSTEM` sentinel. It draws all three language
  pickers. `x-core::locale-switcher` wraps it in one of two shells: a plain
  POST form for the pre-auth screens that must work before the JS bundle
  boots, or a `wire:model` div for a screen the reader is part way through
  filling in; the Settings card wraps it a third way, with its own label,
  width and `wire:change`. Each carried a byte-identical copy of the option
  list before.

  `selected` — which option opens chosen — is a **prop**, not something the
  component works out. It has to be, because the surfaces know different
  things: the pre-auth shells have only `session('locale')`, while Settings
  holds the stored preference, which `LocaleNegotiator::resolve()` ranks
  ABOVE the session. A component that read the session for itself would show
  System to a signed-in reader whose stored language the app was rendering.
  `fieldId` is a prop for the same reason — the label above names the id, and
  the Settings control keeps `settings-locale-select`.
- `Resources/views/components/country-options.blade.php` —
  `x-core::country-options`, the empty option plus the country list, for
  signup, Settings and the onboarding step. The options rather than the whole
  control: signup's `<select>` belongs to `x-core::form-field`, and the three
  bind three different ways (`wire:model`, `wire:change`, `wire:model.live`),
  which is exactly the modifier `form-field` refuses to reproduce from a prop.
  `placeholderDisabled` is a behaviour, not a look: it says whether the
  surface can go back to "no country". Settings cannot — `setCountry()`
  refuses the empty value — so its empty option is disabled; signup and the
  wizard can, so theirs stays choosable. Before this the empty option was
  written out three times under two lang keys, and the German copy had
  already drifted apart.
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
- Registers this module's Livewire components under the `core.*`
  namespace.
- Registers its console commands when running in console.
