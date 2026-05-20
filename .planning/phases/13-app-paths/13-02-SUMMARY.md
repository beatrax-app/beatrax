---
phase: 13-app-paths
plan: 02
subsystem: Core / path abstraction
tags: [nativephp-readiness, path-resolution, di-refactor, call-site-migration]
requires:
  - UserDataPathService static + instance path-resolution surface (Plan 01)
  - UserDataPathService container singleton binding (Plan 01)
provides:
  - every production call site resolves filesystem paths through UserDataPathService
  - UserDataPathService::projectPath() project-rooted accessor
  - core.backups_directory binding retired (D-04)
  - noStoragePathHardCodedOutsideUserDataPathService arch test GREEN
  - composer check:paths grep gate GREEN
affects:
  - Plan 03 (proves simulated-NativePHP-env behaviour, fills the feature test, locks the gate)
tech-stack:
  added: []
  patterns:
    - NATIVEPHP_STORAGE_PATH-driven temp-dir redirection in tests (replaces container string-binding override)
key-files:
  created: []
  modified:
    - config/database.php
    - config/session.php
    - config/modules.php
    - Modules/Core/Public/Services/UserDataPathService.php
    - Modules/Core/Providers/CoreServiceProvider.php
    - Modules/Core/Internal/Console/BackupDatabaseCommand.php
    - Modules/Core/Internal/Console/RestoreDatabaseCommand.php
    - Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php
    - Modules/EmailScan/Public/Services/EmlBlobStore.php
    - Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php
    - Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php
    - Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php
decisions:
  - added UserDataPathService::projectPath() rather than re-introducing base_path()
    in config/modules.php — config/ is not a sanctioned helper home, the service is
  - de-hardcoded the BackupFreshnessProbe overdue-alert message string (the
    storage/app/backups/ literal would have tripped the gate's literal regex)
  - tests redirect storage via NATIVEPHP_STORAGE_PATH instead of binding
    core.backups_directory — the binding is retired, putenv drives the same temp dir
  - RestoreDatabaseCommandTest drives maintenance state by writing the down marker
    at framework('down') directly, because php artisan down targets the
    framework's own un-redirected storage path
metrics:
  duration: ~50m
  completed: 2026-05-20
  tasks: 3
  files: 21
---

# Phase 13 Plan 02: Production Call-Site Migration Summary

Every production call site is now off the raw `database_path()` / `storage_path()`
/ `base_path()` / `public_path()` helpers (and the equivalent hard-coded
`storage/app/` string literals) and onto `UserDataPathService`. The
`noStoragePathHardCodedOutsideUserDataPathService` arch test and
`composer check:paths` — both stood up RED in Plan 01 — are now GREEN because
there is genuinely nothing left to flag.

## What Was Built

**Task 1 — config files.** `config/database.php`, `config/session.php`, and
`config/modules.php` each `use Modules\Core\Public\Services\UserDataPathService;`
and call the static accessors (`databaseFile()`, `frameworkPath('sessions')`,
`modulesPath()`, `publicPath('modules')`, `migrationsPath()`). The static methods
work pre-container because PSR-4 autoload is registered before config load. The
`env('DB_DATABASE', ...)` wrapper is preserved so an explicit override still
works. Plan 01's surface had no arbitrary project-root join, so
`UserDataPathService::projectPath(string $relative)` was added to the service
(its sanctioned `base_path()` home) and used for the `vendor/*/*` scan glob and
the `modules_statuses.json` activator file.

**Task 2 — D-04 backup/restore consumers.** `CoreServiceProvider::register()`
no longer has the `core.backups_directory` singleton (incl. its
`storage/app/backups` string literal) nor the three
`->when()->needs('$backupsPath')` contextual bindings. `BackupDatabaseCommand`,
`RestoreDatabaseCommand`, and `BackupFreshnessProbe` drop their
`private readonly string $backupsPath` parameter and inject
`UserDataPathService $paths` through plain constructor DI, calling `->backups()`.
`RestoreDatabaseCommand` also migrates `$this->app->basePath('storage/framework/down')`
to `$this->paths->framework('down')` and drops its `Application` dependency. The
`BackupFreshnessProbe` overdue-alert message string lost its
`storage/app/backups/` literal. Seven Core test files were migrated off the
retired binding to `NATIVEPHP_STORAGE_PATH`-driven temp-dir redirection.

**Task 3 — EmailScan + Auth.** `EmlBlobStore` gains a second promoted
constructor parameter `UserDataPathService $paths` (the existing `Filesystem $files`
is preserved); blob paths resolve via `appRelative('inbox/...')` and the chmod-walk
root via `appRelative('inbox')`. The `MESSAGE_ID_PATTERN` validation is preserved
unchanged (threat T-13-04). `EmitOAuthReauthRequiredAlert` injects the service as a
4th parameter; `BACKUP_RELATIVE` was rewritten to a bare `BACKUP_FILENAME` joined
onto `secrets()`. `OAuthClientWizardModal`'s disk-write error string was reworded
to drop the `storage/app/secrets/` path (threat T-13-05). The Auth
`rename_legacy_email_oauth_json` migration gained a `paths(): UserDataPathService`
container-resolution helper mirroring its existing `files()` helper.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree missing execution infrastructure**
- **Found during:** Task 1 verification (`php artisan` could not boot)
- **Issue:** The parallel-executor git worktree had no `vendor/`, no `.env`, no
  `database/database.sqlite`, and (discovered later) no `public/build/` Vite
  manifest — so `php artisan` / `pest` could not boot and full-page render tests
  failed with "Vite manifest not found".
- **Fix:** Copied the main repo's `vendor/`, `.env`, `database/database.sqlite`,
  and `public/build/` into the worktree (real copies). Ran `composer dump-autoload`
  so the worktree-local classmap discovers the migrated files. All four are
  git-ignored — none staged or committed; they are local execution
  infrastructure only.
- **Files modified:** none tracked (all in `.gitignore`)
- **Commit:** n/a

**2. [Rule 1 - Bug] BackupFreshnessProbe overdue-alert message named a hard-coded path**
- **Found during:** Task 2
- **Issue:** `BackupFreshnessProbe::recordOverdueAlert()` had an alert message
  `'No verified backups found under storage/app/backups/.'` — a real PHP string
  literal that would trip the gate's `storage/app/` literal regex even though the
  plan's Task 2 `<read_first>` did not call it out.
- **Fix:** Reworded the message to "No verified backups found under the backups
  directory." and routed the `metadata.backups_path` value through `$this->paths->backups()`.
- **Files modified:** Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php
- **Commit:** bf7dfe8

**3. [Rule 1 - Bug] RestoreDatabaseCommandTest maintenance-mode tests broke after the framework('down') migration**
- **Found during:** Task 2 verification
- **Issue:** Migrating `basePath('storage/framework/down')` → `paths->framework('down')`
  meant the command reads a `NATIVEPHP_STORAGE_PATH`-redirected framework path,
  while `php artisan down` (used by the tests) always writes the framework's own
  un-redirected `storage/framework/down`. The marker the command checked never
  appeared, so the refusal-path assertions failed.
- **Fix:** `RestoreDatabaseCommandTest` now drives maintenance state by writing /
  removing the down marker directly at `UserDataPathService::framework('down')`
  via `markDown` / `markUp` closures, keeping the test in lock-step with the
  command's actual path. In a real NativePHP build the framework's own
  `storage_path()` is redirected too, so production behaviour is unaffected.
- **Files modified:** Modules/Core/tests/Feature/RestoreDatabaseCommandTest.php
- **Commit:** bf7dfe8

**4. [Rule 1 - Bug] OAuthLegacyMigrationTest used app->useStoragePath(), which UserDataPathService does not honour**
- **Found during:** Task 3 verification
- **Issue:** The test redirected paths with `$this->app->useStoragePath()`, but
  the migration + listener now resolve via `UserDataPathService::secrets()`,
  which reads `NATIVEPHP_STORAGE_PATH` / `base_path()` — not Laravel's storage
  path. Three tests failed.
- **Fix:** Switched the test to `putenv('NATIVEPHP_STORAGE_PATH', ...)` in
  `beforeEach` and clear in `afterEach`.
- **Files modified:** Modules/EmailScan/tests/Feature/OAuthLegacyMigrationTest.php
- **Commit:** 5f5401f

**5. [Rule 1 - Bug] OAuthClientWizardSecretsWriteFailedTest asserted the old error string**
- **Found during:** Task 3 verification
- **Issue:** The test `assertSet('errorMessage', ...)` pinned the old copy that
  named `storage/app/secrets/`.
- **Fix:** Updated the assertion to the reworded user-facing copy.
- **Files modified:** Modules/EmailScan/tests/Feature/OAuthClientWizardSecretsWriteFailedTest.php
- **Commit:** 5f5401f

## Verification Results

- `grep -REn '(database_path|storage_path|base_path|public_path)\(' config/*.php`
  — no matches
- `grep -REn '(storage_path|base_path)\(' Modules --include='*.php'` (excl.
  tests / `UserDataPathService.php` / migrations) — no matches
- `noStoragePathHardCodedOutsideUserDataPathService` arch test — GREEN
- `composer check:paths` — `OK: no raw path helpers or storage literals
  outside UserDataPathService.`
- Comment-stripped `storage/app/` literal check on `CoreServiceProvider.php` —
  no literal
- `php artisan config:clear && php artisan about` — runs cleanly
- Core `Backup|Restore` filtered tests — 29 passed
- EmailScan `EmlBlob|OAuthClientWizard|OAuthReauth` filtered tests — 26 passed
- `OAuthLegacyMigrationTest` — 6 passed
- Full Core suite — 147 passed, 1 todo (the Plan 03 scaffold)
- Full EmailScan suite — 246 passed
- Full Auth suite — 126 passed
- `tests/Contracts/BoundaryArchTest.php` — 38 passed
- `pint --test` on all 21 changed files — passed
- Larastan L10 strict on all 8 changed production files — no errors

## Threat Surface

No new security surface beyond the plan's `<threat_model>`.
- T-13-04 (path traversal via blob-path construction) — mitigated: `EmlBlobStore`'s
  `MESSAGE_ID_PATTERN` validation is preserved unchanged; `appRelative()` rejects
  `..` segments (Plan 01 control).
- T-13-05 (path disclosure in `OAuthClientWizardModal` error copy) — mitigated:
  the user-facing string no longer names a hard-coded filesystem path.
- T-13-06 (OAuth secrets file path) — accepted: this plan changed the path
  resolution, not the chmod-0600/0700 posture.

## Commits

- `db85bd9` refactor(13-02): route config path resolution through UserDataPathService
- `bf7dfe8` refactor(13-02): inject UserDataPathService into Core backup/restore consumers
- `5f5401f` refactor(13-02): route EmailScan + Auth path resolution through UserDataPathService

## Self-Check: PASSED

- config/database.php contains `UserDataPathService::databaseFile` — FOUND
- config/session.php contains `UserDataPathService::frameworkPath('sessions')` — FOUND
- config/modules.php has 0 raw path helpers — VERIFIED
- UserDataPathService::projectPath() — FOUND
- CoreServiceProvider no longer contains `core.backups_directory` / `needs('$backupsPath')` — VERIFIED
- BackupDatabaseCommand / RestoreDatabaseCommand / BackupFreshnessProbe contain `UserDataPathService $paths` — FOUND
- EmlBlobStore contains `appRelative(` and no `storage_path(` — VERIFIED
- Commits db85bd9, bf7dfe8, 5f5401f — all FOUND in git log
