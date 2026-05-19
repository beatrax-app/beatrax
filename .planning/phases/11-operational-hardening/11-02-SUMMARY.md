---
phase: 11-operational-hardening
plan: 02
subsystem: infra
tags: [sqlite, vacuum-into, backup, pdo, integrity-check, schedule, pest, larastan]

# Dependency graph
requires:
  - phase: 11-operational-hardening
    provides: system_alerts persistence surface + RealSqliteFixture helper from plan 11-01
  - phase: 01-foundation
    provides: Modules\Core\Public\Contracts\Clock, Modules\Core\Internal\Providers\SqliteOptimizationsProvider
provides:
  - db:backup artisan command (VACUUM INTO + chmod 600 + integrity_check + smart-skip + retention)
  - BackupRetentionPolicy pure value object (7 newest dailies + 4 most-recent Sundays keep rule)
  - core.backups_directory container binding for test-overridable storage/app/backups path
  - db.backup-daily Schedule entry at `0 3 * * *` with 60-minute withoutOverlapping mutex
  - system_alerts(backup_corrupt, critical) failure surface emitted on integrity-check trip and on VACUUM INTO PDOException
affects: [11-03-restore-command, 11-04-doctor-probes-failed-jobs, 11-05-banner-readme]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Fresh PDO('sqlite:<path>') for PRAGMA data_version + PRAGMA integrity_check reads — bypasses Laravel connection pool's per-connection cache (RESEARCH §Pitfall 1)"
    - "Named DatabaseManager::connection('sqlite') for VACUUM INTO so tests can keep RefreshDatabase on sqlite_testing :memory: while the command targets the on-disk fixture"
    - "Container-bound string for runtime-configurable filesystem paths: app->singleton('core.backups_directory') + contextual when()->needs('$backupsPath')->give() in CoreServiceProvider"
    - "PDOException bridge: VACUUM INTO try/catch converges into the same system_alerts(backup_corrupt) failure surface the integrity-check branch produces"
    - "Retention policy returns keepers (positive list) so the consuming command issues delete() calls — keeps the policy I/O-free and unit-testable"

key-files:
  created:
    - Modules/Core/Internal/Console/Support/BackupRetentionPolicy.php
    - Modules/Core/Internal/Console/BackupDatabaseCommand.php
    - Modules/Core/tests/Unit/BackupRetentionPolicyTest.php
    - Modules/Core/tests/Feature/BackupDatabaseCommandTest.php
    - Modules/Core/tests/Feature/BackupCorruptionPathTest.php
    - Modules/Core/tests/Feature/BackupScheduleTest.php
  modified:
    - Modules/Core/Providers/CoreServiceProvider.php
    - routes/console.php

key-decisions:
  - "BackupDatabaseCommand uses DatabaseManager::connection('sqlite') explicitly rather than the framework default ($this->db->connection()). The test environment defaults to sqlite_testing (:memory:) for RefreshDatabase speed; pinning the command at the named on-disk connection lets the existing SystemAlert::create() writes continue running through sqlite_testing while VACUUM INTO targets the real path. Tests rebind the sqlite-connection database to a RealSqliteFixture path; production substrate is unaffected."
  - "Smart-skip data_version is read via a FRESH PDO BEFORE the --force gate. The corrupt-source exception bridge from PDOException at PRAGMA data_version flows into the same system_alerts(backup_corrupt) row the integrity-check branch produces, so an unreadable source DB is never silent."
  - "core.backups_directory is bound as an app singleton resolving to base_path('storage/app/backups') in production; contextual binding wires it into BackupDatabaseCommand's string $backupsPath constructor argument. Tests override via $this->app->instance('core.backups_directory', <temp>) so the production storage path stays untouched."
  - "Filesystem::chmod() returns mixed (bool on write-mode, octal-string on read-mode) so the failure branch tests `=== false` explicitly rather than negating an unknown-typed value. Avoids larastan strict-rules booleanNot.exprNotBoolean."
  - "BackupRetentionPolicy is an instance-method class (not static like AmountStringParser) so constructor DI into BackupDatabaseCommand stays uniform with the rest of the project's DI-only posture. Verb: keepers(list<string>, CarbonImmutable): list<string>."
  - "Retention prune removes the matching .meta.json sidecar alongside its parent .sqlite when the daily is pruned, keeping the directory consistent. Non-matching filenames (.suspect, pre-restore-*, .meta.json without a matching daily) are always preserved through the retention pass."

patterns-established:
  - "Atomic chmod-600 sidecar write: umask(0o077) → file_put_contents(.tmp) → @chmod 0o600 → @rename(tmp, final) → @chmod final → restore umask in finally. Mirrors OAuthSecretsRepository::writeAtomic for any future single-file metadata sidecar in storage/app/."
  - "Schedule entry method order: .name() BEFORE .dailyAt()->withoutOverlapping(N). CallbackEvent::withoutOverlapping reads $event->description to derive its mutex key and throws LogicException when description is not set yet. Locked across all 8 entries in routes/console.php."
  - "60-minute lock TTL for short-running scheduled jobs: the default 24h withoutOverlapping TTL is too long for a 5-second backup. 60 minutes is the documented operational pattern."
  - "Container contextual binding for command-time-resolved paths: $this->app->when(Command::class)->needs('$paramName')->give(fn () => $this->app->make('alias.binding')). Replaces a hand-rolled base_path() call inside the command body and gives tests an injection seam."

requirements-completed: [FND-05]

# Metrics
duration: ~45min
completed: 2026-05-19
---

# Phase 11 Plan 02: db:backup Command + Retention + Daily Schedule Summary

**A working `php artisan db:backup` artisan command that runs `VACUUM INTO` against the live SQLite DB, writes a chmod-600 backup + JSON sidecar to `storage/app/backups/`, verifies via fresh-PDO `PRAGMA integrity_check`, prunes the directory to 7 newest dailies + 4 most-recent Sundays, smart-skips on unchanged `PRAGMA data_version`, surfaces every corruption path through a critical `system_alerts(backup_corrupt)` row, and is wired into the daily 03:00 scheduler.**

## Performance

- **Duration:** ~45 minutes
- **Started:** 2026-05-19 (Wave 1 of Phase 11, after 11-01 system_alerts foundation merged)
- **Completed:** 2026-05-19
- **Tasks:** 3 (all autonomous, all TDD: RED → GREEN per task)
- **Files created:** 6
- **Files modified:** 2

## Accomplishments

- Shipped `BackupRetentionPolicy` as a pure-logic value object under `Modules/Core/Internal/Console/Support/` — instance method `keepers(list<string>, CarbonImmutable): list<string>` parses `diederik-YYYY-MM-DD-HHMMSS.sqlite` basenames, keeps the 7 most-recent dailies + the 4 most-recent Sundays, and passes through every non-matching filename verbatim (so `.suspect`, `pre-restore-*`, and `.meta.json` files survive every prune). 6 Pest dataset scenarios cover empty input, under-cap, 14 consecutive days, 8 weeks of Sundays plus weekday dailies, same-day timestamps, and the pass-through cases.
- Shipped `BackupDatabaseCommand` at `db:backup` with full DI surface (`DatabaseManager`, `Filesystem`, `Clock`, `Repository`, `BackupRetentionPolicy`, `$backupsPath`) and zero Laravel facade imports. The command runs VACUUM INTO outside any transaction against the named `sqlite` connection, immediately `chmod`s the output to 0600, runs the post-VACUUM integrity check through a SECOND fresh PDO, writes the `.meta.json` sidecar atomically (umask + tmp + rename + chmod), and applies the retention prune.
- Smart-skip implemented via PRAGMA data_version through a fresh PDO against the live DB (Pitfall 1 mitigation) — compared against the lexicographically-newest `.meta.json` sidecar's stored data_version. A `--force` flag bypasses the gate.
- Corrupt-path surface: integrity-check trip renames the output to `.suspect` and writes `SystemAlert::create(['kind' => 'backup_corrupt', 'severity' => 'critical', ...])` with the integrity_check rows in `metadata.integrity_check`. PDOException at VACUUM INTO or at PRAGMA data_version bridges into the same alert path via a try/catch, so a malformed source DB is never silent.
- Registered `Schedule::command('db:backup')->name('db.backup-daily')->dailyAt('03:00')->withoutOverlapping(60)` in `routes/console.php`. Method order (`.name()` before `.dailyAt()->withoutOverlapping()`) matches the convention locked across the other 7 existing entries. BackupScheduleTest resolves the Schedule instance from the container and asserts the (description, expression, command-string, mutex-name) tuple.
- Wired `BackupDatabaseCommand` into `CoreServiceProvider::boot()`'s `commands([…])` array and bound `'core.backups_directory'` as a singleton in `register()` resolving to `base_path('storage/app/backups')`. The contextual `when(BackupDatabaseCommand::class)->needs('$backupsPath')->give(...)` call gives tests a clean override seam without touching the developer's real storage path.
- Manual smoke verified: `php artisan db:backup --force` against the worktree-local DB produces a chmod-600 `.sqlite` + chmod-600 `.meta.json` pair; second invocation prints `Skipped — no commits since last backup (data_version=2).` and produces no new file; `php artisan schedule:list` shows the new entry at `0 3 * * *`.

## Task Commits

Each task was committed atomically (TDD: test → feat per task):

1. **Task 1 — RED:** `57b5b76` (test: add failing tests for BackupRetentionPolicy)
2. **Task 1 — GREEN:** `3c68a6b` (feat: ship BackupRetentionPolicy pure-logic value object)
3. **Task 2 — RED:** `b5f0aba` (test: add failing tests for db:backup command + corruption path)
4. **Task 2 — GREEN:** `8c4cbd6` (feat: ship db:backup artisan command)
5. **Task 3 — RED:** `85eb65d` (test: add failing schedule-registration test for db:backup)
6. **Task 3 — GREEN:** `a5d85b1` (feat: register db.backup-daily 03:00 schedule entry)

## Files Created/Modified

### Created

- `Modules/Core/Internal/Console/Support/BackupRetentionPolicy.php` — Pure-logic instance-method class; parses backup basenames; returns the keeper subset for the 7-daily + 4-Sunday rule; non-matching filenames pass through unchanged.
- `Modules/Core/Internal/Console/BackupDatabaseCommand.php` — `final class` extending `Command` with signature `db:backup {--force}`. Constructor DI of `Repository`, `DatabaseManager`, `Filesystem`, `Clock`, `BackupRetentionPolicy`, `string $backupsPath`. Public surface: `handle(): int`. Private helpers: `livePath()`, `backupsDir()`, `readDataVersion()`, `readIntegrityCheck()`, `isSkippable()`, `writeSidecar()`, `pruneRetention()`, `recordCorruptAlert()`.
- `Modules/Core/tests/Unit/BackupRetentionPolicyTest.php` — 6 Pest dataset scenarios.
- `Modules/Core/tests/Feature/BackupDatabaseCommandTest.php` — 3 scenarios: --force happy path (chmod 600 + sidecar shape + integrity 'ok'), smart-skip on second invocation, retention prune across an 8-file pre-seeded window.
- `Modules/Core/tests/Feature/BackupCorruptionPathTest.php` — 2 scenarios: corrupt source produces a critical system_alerts row + exit non-zero; .suspect-file branch (integrity_check trip) or exception-bridge branch (VACUUM INTO PDOException) both satisfy the same user-visible failure shape.
- `Modules/Core/tests/Feature/BackupScheduleTest.php` — 1 scenario asserting the Schedule entry tuple.

### Modified

- `Modules/Core/Providers/CoreServiceProvider.php` — Added `BackupDatabaseCommand` import + registration in `commands([…])` (boot); added `'core.backups_directory'` singleton + `when()->needs('$backupsPath')->give(...)` contextual binding (register).
- `routes/console.php` — Appended the `Schedule::command('db:backup')->name('db.backup-daily')->dailyAt('03:00')->withoutOverlapping(60)` block with an explanatory comment block (lock-TTL rationale, method-order rationale, scheduler-namespace rationale).

## Decisions Made

See frontmatter `key-decisions`. The principal substantive decisions are:

1. **Named `sqlite` connection over default for VACUUM INTO** — the test default is `sqlite_testing` (`:memory:`) which cannot back a VACUUM INTO path. Pinning the command at the named connection lets RefreshDatabase keep using `:memory:` for speed while the command targets the on-disk fixture; production substrate (where `DB_CONNECTION=sqlite`) sees no difference.

2. **Container-bound backups directory** — `'core.backups_directory'` lets tests inject a `sys_get_temp_dir()`-rooted path without touching the developer machine's real `storage/app/backups`. Production resolves to `base_path('storage/app/backups')` automatically.

3. **PDOException bridge for VACUUM INTO + PRAGMA data_version** — a corrupt source DB throws BEFORE the post-VACUUM integrity_check ever runs. The two try/catch arms route into the same `recordCorruptAlert()` helper that the integrity-check branch uses, so the user-visible failure shape is identical regardless of where the corruption surfaced.

4. **Retention deletes sidecars alongside their parent** — when a daily `.sqlite` is pruned, its `.meta.json` sidecar is removed in the same pass. The retention policy itself stays I/O-free (returns keepers); the command handles the sidecar cleanup because the policy can't peek at which sidecars belong to which dailies via filename pattern alone.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Switched `$this->db->connection()` to `$this->db->connection('sqlite')` in BackupDatabaseCommand**

- **Found during:** Task 2 GREEN (running the Pest feature suite against the test harness)
- **Issue:** The plan's `<behavior>` block on Task 2 specifies the command should use the framework default connection via `$this->db->connection()`. In the project's test environment, however, `DB_CONNECTION=sqlite_testing` (set by `phpunit.xml`) makes the default a `:memory:` connection — VACUUM INTO cannot back an in-memory connection to an on-disk path, so all five Feature scenarios failed at `assertSuccessful()` while the corruption tests failed for the same reason at the source-read path.
- **Fix:** Pinned every `$this->db->connection(...)` call inside the command to the named `sqlite` connection. The named connection is the project's locked on-disk SQLite (config/database.php line 30) and reads `database.connections.sqlite.database` for the path — exactly the value the smart-skip + VACUUM INTO mechanics need. Production behaviour is unchanged because `DB_CONNECTION=sqlite` makes the named connection IS the default.
- **Files modified:** Modules/Core/Internal/Console/BackupDatabaseCommand.php
- **Verification:** All 5 Feature tests + 6 Unit tests + 1 Schedule test green; phpstan max + pint clean; manual smoke against the worktree-local DB succeeds.
- **Committed in:** 8c4cbd6 (Task 2 GREEN commit)

**2. [Rule 2 — Missing Critical] Added PDOException catch around `readDataVersion()` to bridge corrupt-source path**

- **Found during:** Task 2 GREEN (BackupCorruptionPathTest's "writes a critical system_alerts row" scenario)
- **Issue:** The plan's `<action>` block on Task 2 describes wrapping VACUUM INTO in a try/catch(\PDOException) to bridge the corrupt path to the system_alerts row. But the smart-skip `PRAGMA data_version` read happens BEFORE VACUUM INTO — and against a truncated source DB, the PRAGMA read itself throws `SQLSTATE[HY000]: database disk image is malformed` first. Without a catch arm there, the corrupt-source test exited with an uncaught PDOException rather than the documented critical alert row + non-zero exit code.
- **Fix:** Added an explicit try/catch(PDOException) around the `readDataVersion()` call in `handle()`. The catch arm computes a `destination` for alert metadata (so the system_alerts row records what the next backup WOULD have been named), routes through the existing `recordCorruptAlert()` helper with `'phase' => 'pragma_data_version'` + `'pdo_exception' => $e->getMessage()`, prints the same `Backup corrupt — see system_alerts.` line, and returns `FAILURE`. The user-visible failure shape converges with the VACUUM-INTO-catch and integrity-check-trip branches.
- **Files modified:** Modules/Core/Internal/Console/BackupDatabaseCommand.php
- **Verification:** BackupCorruptionPathTest's "writes a critical system_alerts(backup_corrupt) row and exits non-zero on a corrupt source" scenario asserts the alert exists and the exit code is non-zero — both green after this fix.
- **Committed in:** 8c4cbd6 (Task 2 GREEN commit)

**3. [Rule 1 — Bug] Switched `! $this->files->chmod(...)` to `$this->files->chmod(...) === false`**

- **Found during:** Task 2 GREEN (phpstan analyse after the GREEN code landed)
- **Issue:** larastan strict-rules profile reported `booleanNot.exprNotBoolean` on line 151 — `Filesystem::chmod()` returns `mixed` (the Laravel implementation either calls native `chmod()` returning bool or `substr(sprintf('%o', fileperms($path)), -4)` returning a string), so negating its return with `!` violates the strict-boolean rule.
- **Fix:** Replaced `! $this->files->chmod($destination, 0o600)` with `$this->files->chmod($destination, 0o600) === false`. The semantics are unchanged because the native `chmod()` underneath returns bool; the explicit `=== false` survives the strict analyser.
- **Files modified:** Modules/Core/Internal/Console/BackupDatabaseCommand.php
- **Verification:** phpstan analyse Modules/Core exits 0 (No errors).
- **Committed in:** 8c4cbd6 (Task 2 GREEN commit)

---

**Total deviations:** 3 auto-fixed (2 Rule 1 — Bug, 1 Rule 2 — Missing Critical)
**Impact on plan:** All three deviations are driven by the test-environment SQLite topology (sqlite_testing = `:memory:`) and the project's larastan-strict-rules profile. They preserve the plan's intent verbatim (named connection for the on-disk DB, unified corrupt-path failure surface, chmod-failure surfacing) while satisfying the static-analysis bar and the corruption-test acceptance criteria. No scope creep; no architectural changes; no new packages.

## Issues Encountered

**Worktree CWD vs. PHPUnit testsuite-path discovery (carry-forward from 11-01).** Pest's `BootFiles` bootstrapper loads `tests/Pest.php` from the rootPath derived as `dirname($autoloadPath, 2)` where `$autoloadPath = __DIR__ . '/vendor/autoload.php'`. Because the worktree's `vendor/` is a symlink into the main repo, `__DIR__` inside `vendor/bin/pest` resolves to the main repo's vendor (PHP's `__DIR__` follows symlinks), and `dirname($vendor, 2)` lands at the main repo root — not the worktree. Worktree-local test files are never bound to the per-module TestCase because the wrong `tests/Pest.php` runs.

Workaround: for each verification round, rsync the worktree's modified files into the matching main-repo paths, run `composer dump-autoload` + Pest from the main repo, then `git checkout --` the modified files and remove the untracked copies before the next commit. The main repo's working tree returned to its pre-verification state after each cycle. All commits live in the worktree branch only. This is the same workaround 11-01 used; if it stays this annoying past Wave 1, a follow-up "make tests/Pest.php worktree-aware" plan would be worth scoping.

## User Setup Required

None — no new dependencies, no new environment variables, no external service configuration. The worktree-local manual smoke (`php artisan db:backup --force` after `touch database/database.sqlite`) works out of the box. Production scheduler activation requires the existing `~/Library/LaunchAgents/com.diederik.scheduler.plist` to be running `php artisan schedule:work` — which it already is on the developer machine; no new plist needed (D-1105).

## Next Phase Readiness

- **Wave 1 (Plan 11-03 RestoreDatabaseCommand):** Ready to consume the same `recordCorruptAlert()` shape (SystemAlert::create with kind=backup_corrupt severity=critical) for the post-swap integrity-check critical-failure branch (D-1114), and the same RealSqliteFixture + sqlite-connection-rebind test pattern.
- **Wave 2 (Plan 11-04 doctor probes):** Ready to consume `SystemAlertQuery` for the BackupFreshnessProbe's "was the most recent backup written within 48 hours?" read against the `.meta.json` sidecar `completed_at` field. The sidecar shape `{ data_version, started_at, completed_at, integrity }` is now locked.
- **Wave 2 (Plan 11-05 banner + README):** Ready to surface `system_alerts(backup_corrupt)` rows on the dashboard banner; the README "Backups" section will document the `db:backup` mechanics + the `.suspect` recovery recipe.

## Self-Check: PASSED

- File `Modules/Core/Internal/Console/Support/BackupRetentionPolicy.php` exists in worktree.
- File `Modules/Core/Internal/Console/BackupDatabaseCommand.php` exists in worktree.
- File `Modules/Core/tests/Unit/BackupRetentionPolicyTest.php` exists in worktree.
- File `Modules/Core/tests/Feature/BackupDatabaseCommandTest.php` exists in worktree.
- File `Modules/Core/tests/Feature/BackupCorruptionPathTest.php` exists in worktree.
- File `Modules/Core/tests/Feature/BackupScheduleTest.php` exists in worktree.
- Modified `Modules/Core/Providers/CoreServiceProvider.php` contains `BackupDatabaseCommand` reference.
- Modified `routes/console.php` contains the `db.backup-daily` entry.
- Commits `57b5b76`, `3c68a6b`, `b5f0aba`, `8c4cbd6`, `85eb65d`, `a5d85b1` all reachable from worktree HEAD.
- `pest --testsuite=Unit --filter='BackupRetentionPolicyTest'` → 6 passed (6 assertions).
- `pest --testsuite=Feature --filter='BackupDatabaseCommandTest|BackupCorruptionPathTest|BackupScheduleTest'` → 6 passed (30 assertions).
- `pest --testsuite=Unit` (full Unit suite from main repo verification cycle) → 607 passed, no regression.
- `pest --testsuite=Feature` (full Feature suite from main repo verification cycle) → 587 passed + 5 skipped, no regression.
- `pest --testsuite=Contracts` (arch invariants) → 102 passed.
- `phpstan analyse --memory-limit=2G` (full tree as CI runs) → No errors.
- `pint --test` (full project) → passed.
- Manual smoke `php artisan db:backup --force` in worktree → exits 0, produces chmod-0600 .sqlite + chmod-0600 .meta.json.
- Manual smoke `php artisan db:backup` (no --force) → exits 0, prints `Skipped — no commits since last backup (data_version=2).`, produces no new file.
- Manual smoke `php artisan schedule:list` (with `CACHE_STORE=array` to avoid the unrelated missing `cache_locks` table) → shows `0 3 * * *  php artisan db:backup` at the bottom of the registered entries.

---
*Phase: 11-operational-hardening*
*Completed: 2026-05-19*
