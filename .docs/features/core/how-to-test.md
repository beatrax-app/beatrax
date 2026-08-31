# `Core` — how to test

Practical recipes for exercising the `Core` module in isolation.

## Unit tests

- **Location:** `Modules/Core/tests/Unit/` (when present).
- **What they test:** small helpers such as `DurationParser`,
  `BackupRetentionPolicy`, `SafeTrace`. The richer surface is in
  feature tests because most of `Core` only makes sense against a
  bootable app.
- **`SafeTrace` is tested with `zend.exception_ignore_args` forced
  Off.** The bundled ini sets it On, so a test that took the ambient
  value would pass against `getTraceAsString()` too and prove nothing.
  The file's `beforeEach` sets it to `0` and the `afterEach` restores
  it — the directive is PHP_INI_ALL, and it is read when the exception
  is thrown.

## Feature tests

- **Location:** `Modules/Core/tests/Feature/`
- **What they test:**
  - The `/health` endpoint's deterministic shape and version
    resolution (`HealthEndpointTest`).
  - The first-launch APP_KEY regeneration + sentinel
    (`Bootstrap/AppKeyRegenerationTest`).
  - The boot-probe report (`AppBootHealthCheckTest`).
  - The auto-update channel: Ed25519 manifest verification, SHA-512
    binary verification, the stale-banner threshold, the skip-version
    persistence (`AutoUpdate/Ed25519ManifestVerificationTest`,
    `AutoUpdate/Sha512BinaryVerificationTest`,
    `AutoUpdate/StaleVersionBannerTest`,
    `AutoUpdate/SkipVersionTest`).
  - The backup + restore commands' happy + corruption paths
    (`BackupDatabaseCommandTest`, `BackupCorruptionPathTest`,
    `BackupScheduleTest`, `RestoreDatabaseCommandTest`,
    `RestoreSuccessPathTest`).
  - The doctor command + failed-jobs command
    (`DoctorCommandTest`, `FailedJobsCommandTest`).
  - The install command happy + re-run paths (`InstallCommandTest`).
  - The settings page (`SettingsPageTest`,
    `SettingsPageDevModeToggleTest`, `ThemePreferenceTest`,
    `SettingsRecurringFieldsTest`).
  - The dashboard render (`DashboardOriginalModeRenderTest`).
  - The help-data-locations page (`HelpDataLocationsTest`).
  - The system-alerts banner including the OAuth-reconsent kind
    (`SystemAlertsBannerTest`, `SystemAlertsBannerOAuthReconsentTest`).
  - The sidebar render + dev-mode block (`AppSidebarRenderTest`,
    `AppSidebarDevBlockLiveDataTest`).
  - The path resolution under both local-dev and packaged build env
    (`UserDataPathResolutionTest`).
  - The `users.community_settings` JSON column shape
    (`UserCommunitySettingsColumnTest`).
  - The Phase 11 acceptance walk-through (`Phase11AcceptanceTest`).
  - The operator recovery runbook end-to-end
    (`OperatorRecoveryRunbookTest`).
  - The brand SVG render (`BrandSvgRenderTest`).
- **Setup:** every test uses `RefreshDatabase`. Tests that exercise
  the path service set / unset `NATIVEPHP_STORAGE_PATH` via
  `putenv()` + a `try / finally` reset to keep other tests
  unaffected.

## Contract / arch invariants

Several invariants in `tests/Contracts/` are anchored by `Core`:

- `noRawPathHelpersOutsidePathService` — only
  `UserDataPathService` may call `base_path()` /
  `database_path()` / `storage_path()`.
- `everyUserScopedModelUsesBelongsToUser` — every Eloquent model
  whose table has a `user_id` column must use the trait.
- `noSecretsInLivewireSnapshot` — backed by `SecretsColumnRegistry`.
  Adding a new secret column without registering it leaves the
  invariant blind.
- `noUnsanctionedSystemAlertAcknowledgements` — only
  `AcknowledgeSystemAlert` may write `system_alerts.acknowledged_at`.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Core/tests

# Just the auto-update sub-suite
vendor/bin/pest Modules/Core/tests/Feature/AutoUpdate

# Just the health endpoint
vendor/bin/pest Modules/Core/tests/Feature/HealthEndpointTest.php

# Stop on first failure for a focused debug session
vendor/bin/pest Modules/Core/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **`/health` returns `app_version: dev` in a packaged build** —
  `NATIVEPHP_APP_VERSION` is unset in the NativePHP runtime config.
  Confirm the release pipeline populates the env var via the
  NativePHP `info.plist` / Windows-equivalent stanza; `dev` is the
  documented fallback only for local dev runs.
- **A migration ran in dev but not in the packaged build** — the
  packaged build's storage root is `NATIVEPHP_STORAGE_PATH`, not the
  project's `storage/`. Run the packaged `/health` to confirm
  `sqlite_version` is non-empty; then read
  `UserDataPathService::databaseFile()` from `tinker` against the
  packaged env to find the live DB file.
- **`UserScope` returning zero rows in a queued job** — the job
  did not bind the guard before issuing the query. Call
  `Auth::onceUsingId($userId)` (or the project's DI equivalent)
  at job entry, or read the model with explicit
  `where('user_id', $userId)` and `withoutGlobalScope(UserScope::class)`.
- **A new system-alert kind not rendering in the banner** — the
  `SystemAlertsBanner` Livewire SFC renders by `kind`. The view
  needs a partial for the new kind; without it the row is fetched
  but invisible.
- **The Ed25519 verification rejects a legitimate manifest** —
  the publisher key in `config/auto_update.php` does not match the
  release pipeline's private key. Rotate via the runbook in
  `.docs/runbooks/verify-release.md`.
- **A test exercising the SQLite optimisations sees `journal_mode =
  delete`** — the connection was created before the
  `SqliteOptimizationsProvider` listener registered. Force a fresh
  connection by calling `DB::purge()` then `DB::connection()` again,
  or use `RefreshDatabase` which re-creates the connection per test.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

- **`BelongsToUser` is the only sanctioned way for a domain model to
  scope to the user.** The `UserScope` global scope is the single
  read-side guard; every authenticated query inherits it. The arch
  invariant `everyUserScopedModelUsesBelongsToUser` enforces the trait
  composition. (See [ADR 0008](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0008-multi-user-belongstouser.md).)
- **`CurrentUser::user()` never returns `null`.** If no guard user is
  bound, the service throws `NotAuthenticatedException`; downstream
  code can assume a real `User` instance after a successful resolve.
- **`UserDataPathService` is the SOLE sanctioned caller of
  `base_path()` in production code.** The arch invariant
  `noRawPathHelpersOutsidePathService` blocks every other call site,
  so a packaged build (where `NATIVEPHP_STORAGE_PATH` redirects the
  storage root) cannot land a stray path that escapes the redirect.
  (`tests/Feature/UserDataPathResolutionTest.php`)
- **`EnsureAppKey::run()` runs `key:generate --force` exactly once per
  install.** The sentinel file at
  `appPath('first-launch.app-key-generated')` is the idempotency
  signal; subsequent invocations short-circuit.
  (`tests/Feature/Bootstrap/AppKeyRegenerationTest.php`)
- **`/health` returns a deterministic four-key JSON object — `status`,
  `app_version`, `php_version`, `sqlite_version` — with no
  timestamp.** An external probe can equality-check the entire body
  without normalising volatile fields.
  (`tests/Feature/HealthEndpointTest.php`)
- **`/health` is auth-free.** A non-loopback request still hits
  `LoopbackOnly` and gets 404; an unauthenticated loopback request
  passes through.
- **Every non-loopback request raises 404.** `LoopbackOnly` middleware
  inspects `SERVER_ADDR`; if a non-loopback IP is set, it throws
  `NotFoundHttpException`. A request carrying no `SERVER_ADDR` is
  decided by the SAPI: the console context passes, `embed` (the mobile
  shell, which has no listening socket) passes unconditionally,
  `cli-server` passes only for a loopback `REMOTE_ADDR`, and every
  other SAPI fails closed. The middleware takes the SAPI as a
  constructor argument so all four branches are drivable from a test
  (`Modules/Core/tests/Unit/LoopbackOnlySapiTest.php`). The detection
  covers IPv4 127.0.0.0/8, IPv6 `::1`, and the IPv4-mapped-IPv6
  `::ffff:127.x.x.x` form on binary-form (`inet_pton`) comparison.
- **Every authenticated response carries `Cache-Control: no-store`.**
  `NoStoreFinancialData` is pushed onto the `auth` middleware group
  so the browser never caches a transaction list.
- **`ElectronUpdateChannel` verifies every manifest before raising any
  `system_alerts` row.** Ed25519 signature verification against the
  publisher public key is the gate; a manifest failing verification
  is logged at `warning` and no banner appears.
  (`tests/Feature/AutoUpdate/Ed25519ManifestVerificationTest.php`)
- **`ElectronUpdateChannel` verifies every downloaded binary before
  install handoff.** SHA-512 hash of the binary must equal the
  manifest's declared hash. Mismatch aborts the install path.
  (`tests/Feature/AutoUpdate/Sha512BinaryVerificationTest.php`)
- **A manifest more than 30 days stale raises an `update.stale`
  banner.** The banner does NOT auto-install — the user has to
  investigate first. (`tests/Feature/AutoUpdate/StaleVersionBannerTest.php`)
- **Skipping a version persists per-user.** The user's
  `user_preferences.skipped_update_versions` JSON column records the
  list; the banner does not re-surface those versions.
  (`tests/Feature/AutoUpdate/SkipVersionTest.php`)
- **`AcknowledgeSystemAlert` is the sole sanctioned writer of
  `system_alerts.acknowledged_at`.** Direct writes via Eloquent /
  query-builder are forbidden by the arch invariant
  `noUnsanctionedSystemAlertAcknowledgements`.
- **The SQLite WAL + busy_timeout + synchronous=NORMAL PRAGMAs apply
  to every connection.** `SqliteOptimizationsProvider` registers the
  listener; a connection that bypasses the listener (test harness
  shortcut) is detectable via `PRAGMA journal_mode`. (See
  [ADR 0005](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0005-sqlite-wal.md).)
- **The `UserInstalled` event must be dispatched in two distinct
  contexts: signup ceremony and `beatrax:install` re-run.** Listeners
  MUST be idempotent — the install command's "re-seed reference data"
  guarantee depends on it.
- **Every column in `SecretsColumnRegistry::columns()` is enforced by
  the `noSecretsInLivewireSnapshot` arch invariant.** Adding a new
  secret column without registering it is an unguarded leak.

## Edge cases

- **Empty database** — the install command runs migrations first, then
  dispatches `UserInstalled`. The user lands on a working install.
- **Re-running `beatrax:install` on an existing install** — the
  command confirms the existing user, dispatches `UserInstalled` again
  (re-seeding reference data idempotently), prints the existing
  recovery codes only if requested explicitly.
- **`CurrentUser` called from a queued job** — the job arrives with a
  serialised user payload; the listener calls `Auth::onceUsingId($userId)`
  or otherwise binds the guard before resolving `CurrentUser`.
- **`UserScope` query inside an artisan command that has no
  authenticated user** — the scope detects the unbound auth and
  silently no-ops the filter (returns all rows). This is the
  intentional CLI escape hatch for the operator-only commands.
- **Backup file missing on restore** — `RestoreDatabaseCommand` aborts
  before touching the live DB; the operator gets a clear error.
- **Boot probe finds Node missing** — the probe returns level=warn
  (not fail) because the runtime path runs without Node; only the
  release pipeline needs it.
- **A manifest fetch succeeds but the host is down at binary
  download** — the channel logs a `warning`, the in-flight
  `update.available` banner stays visible without flipping to
  installed; the user retries.
- **`EnsureAppKey` sentinel exists but the `.env` `APP_KEY` is
  empty** — the action still short-circuits (sentinel is the
  authoritative signal). The operator restores from backup or runs
  `key:generate --force` manually; this is a corrupted-install path.

## Cross-module collaborators

- **Depends on** nothing inside `Modules/`. This is by design — Core
  is the floor of the dependency graph. The only external dependencies
  are framework primitives (Eloquent, Auth, HTTP), `Carbon`, the
  `sodium_*` extension for signature verification, and the NativePHP
  `PublisherManifestFetcher` contract (the binding lives in
  [`Desktop`](../desktop/how-to-test.md)).
- **Depended on by** every other module. The cross-module surface this
  module provides:
  - `User` model — every domain model with a `user_id` FK.
  - `BelongsToUser` trait — every user-scoped Eloquent model.
  - `Clock` contract — every service that needs a timestamp.
  - `CurrentUser` contract — every Livewire page + every action that
    runs on the authenticated user.
  - `UserDataPathService` — every place a path is read.
  - `LockStore::forUniqueJobs()` — `Chains`, `EmailScan`,
    `DriftAlerts`, any other module using `ShouldBeUniqueUntilProcessing`.
  - `UserInstalled` event — `Auth` (raises), `Categorization`,
    `Community`, `Onboarding` (listen).
  - `system_alerts` infrastructure — every module that needs to
    raise a banner reads `SystemAlertQuery` and writes via
    `AcknowledgeSystemAlert`.

## Configuration + feature flags

- `NATIVEPHP_STORAGE_PATH` (env) — redirects the storage root for the
  packaged build. Absent → project-rooted `storage/`.
- `NATIVEPHP_APP_VERSION` (env) — populates `/health`'s `app_version`;
  absent → `'dev'`.
- `config('auto_update.publisher_public_key_hex')` — the Ed25519
  public key the channel verifies manifests against.
- `users.theme` / `users.close_behavior` / `users.period_start_day` /
  `users.default_currency_view` — per-user preference columns.
- `user_preferences.skipped_update_versions` (JSON) — per-user
  skipped-update-version list.
- `BEATRAX_RUNTIME=local` (env) — Dev-mode runtime distinguisher
  several modules respect (`DevMode` for surface gates, `Queue` for
  driver). See [ADR 0007](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0007-database-queue-driver.md).
