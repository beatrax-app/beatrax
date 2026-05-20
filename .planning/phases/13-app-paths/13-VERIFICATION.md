---
phase: 13-app-paths
verified: 2026-05-20T14:00:00Z
status: passed
score: 2/2
overrides_applied: 0
---

# Phase 13: AppPaths Verification Report

**Phase Goal:** Every filesystem path the app reads or writes flows through a single injectable `UserDataPath` contract whose implementation defers to NativePHP's `Application::storagePath()` in shipped builds and the existing project-rooted paths in Herd dev mode; an arch test plus a CI grep gate guarantee no raw `database_path()` / `storage_path()` / `base_path()` call — or equivalent hard-coded string literal — survives outside that service.
**Verified:** 2026-05-20T14:00:00Z
**Status:** PASSED
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `BoundaryArchTest::noStoragePathHardCodedOutsideUserDataPathService` is green — no `database_path()` / `storage_path()` / `base_path()` call appears anywhere outside `UserDataPathService`; CI grep gate enforces the same rule against string literals | VERIFIED | Test ran: 1 passed (1 assertion). `composer check:paths` prints `OK: no raw path helpers or storage literals outside UserDataPathService.` |
| 2 | Running `php artisan migrate:fresh` under `NATIVEPHP_STORAGE_PATH=<tmp>` creates the SQLite file under the temp dir; `db:backup` writes to the simulated backups dir; OAuth secrets path resolves under the simulated root; all proven by Pest feature test | VERIFIED | `UserDataPathResolutionTest` ran: 4 passed (19 assertions). All three artisan behaviors verified on disk. |

**Score:** 2/2 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Core/Public/Services/UserDataPathService.php` | `final class` with full static + instance surface | VERIFIED | Exists, substantive (187 lines), contains `final class UserDataPathService`, `getenv('NATIVEPHP_STORAGE_PATH')`, exactly one `base_path()` call inside `projectRoot()`, all required accessors present |
| `Modules/Core/tests/Unit/UserDataPathServiceTest.php` | Both-branch unit coverage, Herd-parity guard | VERIFIED | 10 tests / 28 assertions — env-set branch, env-unset branch, singleton identity, A2 parity guard all present |
| `tests/Contracts/BoundaryArchTest.php` | `noStoragePathHardCodedOutsideUserDataPathService` it() block, allow-list of one | VERIFIED | Block present at line 1119; allow-list is exactly `['Modules/Core/Public/Services/UserDataPathService.php']`; regex covers `database_path|storage_path|base_path` and NOT `public_path` |
| `Modules/Core/tests/Feature/UserDataPathResolutionTest.php` | Real assertions — no `->todo()` | VERIFIED | All four `it()` blocks filled with real assertions; no `->todo()` marker; `putenv(`, `migrate:fresh`, `db:backup`, `file_exists`, Herd-parity guard, `configurationIsCached` guard all present |
| `bin/check-paths.sh` | Executable CI grep gate | VERIFIED | Exists, executable, greps `database_path|storage_path|base_path`, excludes `public_path`, single `ALLOW` entry |
| `config/database.php` | `UserDataPathService::databaseFile()` replaces `database_path(...)` | VERIFIED | Contains `use Modules\Core\Public\Services\UserDataPathService;` and `UserDataPathService::databaseFile()` |
| `config/session.php` | `UserDataPathService::frameworkPath('sessions')` | VERIFIED | Contains `UserDataPathService::frameworkPath('sessions')` at line 48 |
| `config/modules.php` | All 5 raw helpers replaced via `UserDataPathService` | VERIFIED | Contains `modulesPath()`, `publicPath('modules')`, `migrationsPath()`, `projectPath('vendor/*/*')`, `projectPath('modules_statuses.json')` |
| `Modules/Core/Internal/Console/BackupDatabaseCommand.php` | Injects `UserDataPathService $paths` | VERIFIED | `private readonly UserDataPathService $paths` at constructor; no `string $backupsPath` param |
| `Modules/Core/Internal/Console/RestoreDatabaseCommand.php` | Injects `UserDataPathService $paths`; no `basePath('storage/framework/down')` | VERIFIED | `UserDataPathService $paths` present; `basePath(` removed |
| `Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php` | Injects `UserDataPathService $paths` | VERIFIED | `private readonly UserDataPathService $paths` at constructor |
| `Modules/Core/Providers/CoreServiceProvider.php` | No `core.backups_directory`; singleton binding | VERIFIED | `singleton(UserDataPathService::class)` at line 59; no `core.backups_directory` or `needs('$backupsPath')` |
| `Modules/EmailScan/Public/Services/EmlBlobStore.php` | Both `Filesystem $files` and `UserDataPathService $paths`; `appRelative(` | VERIFIED | Both parameters present; `appRelative(` used; no `storage_path(` |
| `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php` | Injects `UserDataPathService $paths`; no `storage_path(` or `app/secrets/` literal | VERIFIED | `UserDataPathService $paths` at line 40; `BACKUP_FILENAME` constant holds only the filename; `secrets().DIRECTORY_SEPARATOR.BACKUP_FILENAME` |
| `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php` | No `storage/app/secrets/` literal in error copy | VERIFIED | Grep returns no match for `storage/app/secrets` |
| `Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php` | `paths(): UserDataPathService` helper; no `storage_path(` | VERIFIED | `private function paths(): UserDataPathService` at line 66; `Container::getInstance()->make(UserDataPathService::class)` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `config/database.php` | `UserDataPathService::databaseFile` | static call | WIRED | `UserDataPathService::databaseFile()` as `env()` default |
| `config/session.php` | `UserDataPathService::frameworkPath('sessions')` | static call | WIRED | Direct static call at session files path |
| `config/modules.php` | `UserDataPathService` accessors | static calls | WIRED | 5 call sites, all static accessors |
| `BackupDatabaseCommand` | `UserDataPathService::backups()` | constructor DI | WIRED | `$this->paths->backups()` in `backupsDir()` |
| `RestoreDatabaseCommand` | `UserDataPathService::framework('down')` | constructor DI | WIRED | `$this->paths->framework('down')` at line 94 |
| `EmlBlobStore` | `UserDataPathService::appRelative()` | constructor DI | WIRED | `$this->paths->appRelative(...)` for blob paths |
| `EmitOAuthReauthRequiredAlert` | `UserDataPathService::secrets()` | constructor DI | WIRED | `$this->paths->secrets()` for backup path |
| `CoreServiceProvider` | `UserDataPathService` | singleton binding | WIRED | `$this->app->singleton(UserDataPathService::class)` at line 59 |
| `UserDataPathResolutionTest` | `artisan migrate:fresh` / `db:backup` | putenv-simulated env | WIRED | `putenv('NATIVEPHP_STORAGE_PATH=...')` in `beforeEach`, assertions on disk artifacts |
| `BoundaryArchTest` | `check:paths` | duplicate invariant | WIRED | Both grep `database_path|storage_path|base_path`; arch test is authoritative |

### Data-Flow Trace (Level 4)

Not applicable — phase produces a service + test coverage, not a rendering component. The data flow is path strings, not DB → UI. The feature test performs end-to-end verification by running `migrate:fresh` and `db:backup` and asserting `file_exists` on disk, which constitutes a stronger-than-Level-4 check for this type of work.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Arch invariant green | `./vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter=noStoragePathHardCodedOutsideUserDataPathService` | 1 passed (1 assertion) | PASS |
| CI grep gate exits 0 | `bash bin/check-paths.sh` | `OK: no raw path helpers or storage literals outside UserDataPathService.` | PASS |
| Feature test: `migrate:fresh` + `db:backup` + secrets path + Herd parity | `php artisan config:clear && ./vendor/bin/pest Modules/Core/tests/Feature/UserDataPathResolutionTest.php` | 4 passed (19 assertions) | PASS |
| Unit test: both env branches + singleton + A2 parity | `./vendor/bin/pest Modules/Core/tests/Unit/UserDataPathServiceTest.php` | 10 passed (28 assertions) | PASS |
| No raw helpers in config files | `grep -REn '(database_path\|storage_path\|base_path\|public_path)[[:space:]]*\(' config/*.php` | no matches | PASS |
| No raw helpers in Modules production code | `grep -REn '(storage_path\|base_path)[[:space:]]*\(' Modules --include='*.php' \| grep -v /tests/ \| grep -v UserDataPathService.php \| grep -v /Database/Migrations/` | no matches | PASS |

### Probe Execution

No probe scripts declared or applicable for this phase.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| PKG-01 | 13-01, 13-02, 13-03 | AppPaths / UserDataPath abstraction service replacing every raw path helper, with arch-test forbidding use outside the service | SATISFIED | `UserDataPathService` exists and is the single call site for `base_path()`; arch test + `check:paths` enforcing the constraint; feature test proving simulated-env behavior |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `Modules/Core/tests/Feature/UserDataPathResolutionTest.php` | 133 | Stale comment says `<tmp>/storage/app/backups` but implementation resolves to `<tmp>/app/backups` | INFO | Comment only — assertion on line 128 is correct (`<tmp>/app/backups`); no behavioral impact |

No TBD / FIXME / XXX markers found in any phase-modified file.

### Human Verification Required

None. All success criteria are mechanically verifiable and confirmed by automated test runs.

---

## Gaps Summary

No gaps. Both ROADMAP success criteria are observably met by direct test execution in this verification session.

### CR-01 Assessment (from 13-REVIEW.md)

The code review identified CR-01: `bootstrap/app.php` does not call `->useStoragePath(UserDataPathService::storageBase())`, meaning Laravel's framework-level `storage_path()` helper (used by `FileBasedMaintenanceMode`, session/cache/view internals) is not reconfigured to agree with `UserDataPathService::storageRoot()`. This means under a packaged `NATIVEPHP_STORAGE_PATH` build, the `RestoreDatabaseCommand` maintenance-mode check would read the wrong path, and sessions/views could land in a divergent tree.

Assessment against the stated success criteria:

**Success Criterion 1** (arch test + CI grep gate green) — CR-01 does NOT affect SC-1. The arch test verifies that no production file outside `UserDataPathService` calls the raw helpers. CR-01 describes framework-internal calls — `FileBasedMaintenanceMode::path()` is in `vendor/`, not in the codebase roots scanned by the gate. SC-1 is satisfied.

**Success Criterion 2** (feature test proves `migrate:fresh` / `db:backup` / OAuth-secrets under `NATIVEPHP_STORAGE_PATH`) — CR-01 does NOT affect SC-2. The feature test runs in a test harness (Herd dev environment) where the env var is set via `putenv()` and `UserDataPathService::databaseFile()` / `backupsPath()` / `secretsPath()` all resolve under `<tmp>`. The test does not exercise `FileBasedMaintenanceMode`. All 4 assertions in `UserDataPathResolutionTest` pass on disk. SC-2 is satisfied.

CR-01 is a real structural gap that will matter in Phase 15 when an actual NativePHP build is constructed — it is a Phase 15 pre-condition, not a Phase 13 failure. The Phase 15 hand-off notes in `13-03-SUMMARY.md` already document that "Phase 15 must decide whether NativePHP's native rewrite or `NATIVEPHP_STORAGE_PATH` is the source of truth." CR-01 is correctly deferred there.

---

_Verified: 2026-05-20T14:00:00Z_
_Verifier: Claude (gsd-verifier)_
