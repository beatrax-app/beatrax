# `Core` — how to test

Practical recipes for exercising the `Core` module in isolation.

## Unit tests

- **Location:** `Modules/Core/tests/Unit/` (when present).
- **What they test:** small helpers such as `DurationParser`,
  `BackupRetentionPolicy`, `SafeTrace`. The richer surface is in
  feature tests because most of `Core` only makes sense against a
  bootable app.

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
