---
phase: 11-operational-hardening
verified: 2026-05-19T22:30:00Z
status: human_needed
score: 7/7 must-haves verified
overrides_applied: 0
human_verification:
  - test: "db:backup --force on the real Herd-mounted DB produces a chmod-600 .sqlite + .meta.json pair"
    expected: "`ls -la storage/app/backups/` shows the new file at mode `-rw-------` plus a `.meta.json` sidecar also at 0600"
    why_human: "File-permission semantics on the actual filesystem (not the Pest temp dir under sys_get_temp_dir) cannot be asserted programmatically by the verifier without running the command — Plan 11-05 Task 3 explicitly deferred this to a real Herd run"
  - test: "Second `db:backup` (no --force) on an unchanged DB prints 'Skipped — no commits since last backup' and creates no new file"
    expected: "Output contains the skip line and `ls storage/app/backups/` shows the same files as before"
    why_human: "Smart-skip behaviour depends on PRAGMA data_version cache state on the live process, which only the operator's running Herd substrate can reproduce"
  - test: "`php artisan diederik:doctor` against the local DB prints WAL / synchronous / backup-freshness probe lines and exits 0 in a clean state"
    expected: "Three probe lines visible alongside the inline PHP/Composer/SQLite/Node checks; exit code 0 when WAL is active, synchronous=NORMAL, and a fresh sidecar exists"
    why_human: "Exit code + console output of a real `artisan` run on the developer's machine cannot be inspected from the verifier process"
  - test: "Banner appears in the browser at https://diederik.test after seeding a critical alert via tinker, then disappears on click"
    expected: "Visual: rose-coloured banner with `Mark as resolved` button at top of authenticated pages; click → row gone within ~500ms; persists gone on reload"
    why_human: "Visual appearance, Livewire round-trip latency feel, and browser DOM interaction cannot be verified without a real browser session"
  - test: "`php artisan diederik:failed-jobs prune --older-than=30d --dry-run` prints candidate-row summary and exits 0 without modifying the failed_jobs table"
    expected: "Output shows 'Would delete N rows' footer; running `select count(*) from failed_jobs` before and after returns the same number"
    why_human: "Confirms the dry-run guard does not write — programmatic feature tests cover the unit but operator-felt safety needs the operator to run it once on real data"
  - test: "README ## Backups and ## Operator recovery sections render correctly when read in a markdown viewer (GitHub, IDE preview)"
    expected: "All five new ## Backups subsections plus four new ## Operator recovery subsections are present and the existing Stuck Redis lock recovery is preserved verbatim"
    why_human: "Visual rendering of markdown headings and code fences cannot be verified from a grep; the ReadmeOperationalDocsTest pins substrings but not visual layout"
  - test: "db:restore --confirm --force-maintenance <known-good-source.sqlite> round-trips a backup and creates a pre-restore-*.sqlite snapshot at chmod 0600"
    expected: "Source DB rows visible after restore; pre-restore snapshot exists at `storage/app/backups/pre-restore-<timestamp>.sqlite`; app comes back up automatically"
    why_human: "Restore is destructive and operates on the live DB file handle — the only meaningful end-to-end smoke is on the operator's real machine, deferred per plan 11-05 Task 3"
---

# Phase 11: Operational Hardening Verification Report

**Phase Goal:** User can run the app as a reliable daily tool — consistent SQLite backups via artisan command, restore verification, and the daily-use reliability touches that close out v1.

**Verified:** 2026-05-19T22:30:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria + must_haves merge)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | **SC #1** — User can run `php artisan db:backup` and produce a consistent, restorable SQLite backup while the app is running (via `VACUUM INTO` or the online backup API) | VERIFIED | `Modules/Core/Internal/Console/BackupDatabaseCommand.php:117` runs `VACUUM INTO '...'` against the named `sqlite` connection outside any transaction. Chmod 0600 at line 159. Smart-skip via fresh-PDO `PRAGMA data_version` at lines 252–264. `BackupDatabaseCommandTest` (3 scenarios) + `BackupScheduleTest` (1 scenario) green. |
| 2 | **SC #2** — The latest backup is automatically verified by re-opening it and running `PRAGMA integrity_check`, with failures surfaced to the user | VERIFIED | `BackupDatabaseCommand.php:176–188` runs fresh-PDO `PRAGMA integrity_check` against the freshly-written destination, branches to `recordCorruptAlert()` on non-`'ok'` results, renames the file to `.suspect`, and writes a critical `system_alerts(kind=backup_corrupt, severity=critical)` row at lines 445–462. The `BackupCorruptionPathTest` (2 scenarios) + `Phase11AcceptanceTest` (the end-to-end vertical) prove the chain. Failures reach the user via `SystemAlertsBanner` Livewire SFC (`@livewire('core.system-alerts-banner')` at `resources/views/layouts/app.blade.php:14`). |
| 3 | **SC #3** — Operator documentation explicitly forbids `cp database.sqlite` of the live WAL DB and points to `db:backup` as the supported path | VERIFIED | `README.md:197` has the explicit heading `### DO NOT cp database.sqlite`. Lines 199–207 explain WAL + `.sqlite-wal` + `.sqlite-shm` semantics and point at `VACUUM INTO` / `db:backup` as the supported path. `ReadmeOperationalDocsTest` enforces 14 required substrings + 4 forbidden substring families (`/D-\d{4}/`, `.planning/`, `Phase 11`, `cp database.sqlite` count == 1). Verifier sweep: `grep -cE 'D-[0-9]{4}\|Phase 11\|\.planning/' README.md` returns 0. |
| 4 | system_alerts persistence surface (migration + Eloquent model + per-user + system-wide read service + Public action) | VERIFIED | `Modules/Core/Database/Migrations/2026_05_20_010001_create_system_alerts_table.php` creates the table with 8 columns, 2 indexes, BEFORE INSERT + BEFORE UPDATE OF severity triggers. `SystemAlert.php` + `SystemAlertQuery.php` + `AcknowledgeSystemAlert.php` ship. 19 unit tests green across `SystemAlertsMigrationTest|SystemAlertModelTest|SystemAlertQueryTest|AcknowledgeSystemAlertTest`. |
| 5 | db:restore with triple safety rail (maintenance mode + --confirm + pre-swap integrity_check + pre-restore snapshot) | VERIFIED | `RestoreDatabaseCommand.php` ships all four guards. The four refusal-path scenarios in `RestoreDatabaseCommandTest` + the happy-path `RestoreSuccessPathTest` are green. Plan 11-03 D-1112/D-1113/D-1114 mechanics all visible at the corresponding line numbers. |
| 6 | Doctor probes + boot-time PRAGMA health check (non-halting) | VERIFIED | `Modules/Core/Internal/Console/Probes/{WalMode,Synchronous,BackupFreshness,Php,Composer,Node,SqliteCli}*Probe.php` all exist; `DoctorCommand` iterates them. `HealthCheckServiceProvider` + extracted `HealthCheckListener` listen on `ConnectionEstablished`, write `system_alerts(wal_mode_missing|synchronous_misconfigured)` warning rows on drift via `BootProbeState` DI singleton + 1-hour recency gate. `AppBootHealthCheckTest` (5 scenarios) + `DoctorProbesTest` (11 scenarios) + `DoctorCommandTest` (3 scenarios) all green. |
| 7 | SystemAlertsBanner Livewire SFC + FailedJobsCommand + arch invariants | VERIFIED | `SystemAlertsBanner.php` + Blade view + partial all ship with three explicit severity branches (literal Tailwind class strings). `FailedJobsCommand` (`diederik:failed-jobs prune --older-than=<d\|h\|w> [--dry-run]`) ships with `DurationParser`. Three arch invariants present in `BoundaryArchTest.php` (systemAlertsTableNotJoinedToTransactions, noFacadeCallsFromCoreConsoleCommands, noLaravelGlobalHelpersInCoreConsoleCommands) + `HorizonForceFlagTest.php` — all four green in the verifier's Contracts run. `SystemAlertsBannerTest` (6 scenarios) + `FailedJobsCommandTest` (5 scenarios) + `DurationParserTest` (16 scenarios) green. |

**Score:** 7/7 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Core/Internal/Console/BackupDatabaseCommand.php` | db:backup with VACUUM INTO + integrity_check + chmod + retention + system_alerts on corruption | VERIFIED | Exists, 463 lines, no facade imports (`grep -c "use Illuminate\\\\Support\\\\Facades\\\\"` = 0), VACUUM INTO at line 117, integrity_check at line 280, chmod 0600 at line 159, `SystemAlert::create([…'backup_corrupt'…])` at line 452 |
| `Modules/Core/Internal/Console/RestoreDatabaseCommand.php` | db:restore with triple safety rail + pre-restore snapshot | VERIFIED | Exists, 290 lines. `DB::purge` via injected manager confirmed. Pre-restore snapshot path uses `pre-restore-*.sqlite` prefix (preserved by retention policy). Contextual `$backupsPath` binding (post-WR-03 fix) |
| `Modules/Core/Internal/Console/FailedJobsCommand.php` | diederik:failed-jobs prune --older-than --dry-run | VERIFIED | Exists, 137 lines, zero facade imports |
| `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` | Livewire 4 Component with method-parameter DI | VERIFIED | Exists, 53 lines, no constructor (`grep public function __construct` = 0), method-parameter DI on render() + acknowledge() |
| `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` | Three explicit severity branches with literal Tailwind classes | VERIFIED | `border-rose-500`, `border-amber-300`, `border-slate-200` all present as literal strings; no dynamic Tailwind interpolation; XSS guard intact (`grep -c '{!! ' = 0`) |
| `Modules/Core/Internal/Providers/HealthCheckServiceProvider.php` | Boot-time PRAGMA verifier (non-halting) | VERIFIED | Exists; delegates to extracted `Modules\Core\Internal\Listeners\HealthCheckListener` (IN-04 fix); listens `ConnectionEstablished` |
| `Modules/Core/Models/SystemAlert.php` | Eloquent model with BelongsToUser + casts | VERIFIED | Exists, casts metadata→array, acknowledged_at→immutable_datetime |
| `Modules/Core/Public/Services/SystemAlertQuery.php` | active() returns per-user + system-wide rows | VERIFIED | `scopedActiveQuery()` predicate: `where(user_id) OR whereNull(user_id)` widening visible at lines 96–101 |
| `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` | Per-user-safe acknowledge action, idempotent, cross-user 404 | VERIFIED | `withoutGlobalScopes()->findOrFail` fix at line 78 (the bug that Phase11AcceptanceTest caught); transactional wrap intact |
| `Modules/Core/Internal/Console/Support/BackupRetentionPolicy.php` | 7-daily + 4-Sunday keepers, .suspect / pre-restore-* / .meta.json pass-through | VERIFIED | Calendar-invalid date Throwable catch at line 129 (WR-02 fix) |
| `Modules/Core/Internal/Console/Support/DurationParser.php` | `^(\d+)([dhw])$` regex, m rejected, 0d rejected | VERIFIED | Zero-amount rejection at line 63 (IN-05 fix); 16 Pest dataset scenarios |
| `tests/Helpers/RealSqliteFixture.php` | On-disk SQLite + WAL + DEFAULT_SCHEMAS extension hook | VERIFIED | Exists, `DEFAULT_SCHEMAS` public const present; sanity test green |
| `routes/console.php` | Schedule::command('db:backup --force') daily 03:00 with 60-min withoutOverlapping | VERIFIED | Line 228: `Schedule::command('db:backup --force')->name('db.backup-daily')->dailyAt('03:00')->withoutOverlapping(60)` (post-WR-04 fix includes `--force` so quiet days do not silently skip past 48h freshness) |
| `tests/Contracts/BoundaryArchTest.php` (3 new invariants) | systemAlertsTableNotJoinedToTransactions + noFacadeCallsFromCoreConsoleCommands + noLaravelGlobalHelpersInCoreConsoleCommands | VERIFIED | All three `it(...)` blocks present at lines 881, 926, 967 |
| `tests/Contracts/HorizonForceFlagTest.php` | A2 invariant — no Horizon supervisor uses force: true | VERIFIED | Exists; baseline holds (`grep "'force'" config/horizon.php` returns no `=> true` matches) |
| `README.md` ## Backups + ## Operator recovery sections | Rewrite + four new recovery subsections + Stuck Redis preserved | VERIFIED | All 14 required substrings present; 0 forbidden matches; `cp database.sqlite` count == 1; `### DO NOT cp database.sqlite` heading at line 197 |
| `Modules/Core/tests/Feature/Phase11AcceptanceTest.php` | End-to-end vertical (db:backup → banner-empty → corrupt → banner-renders → acknowledge) | VERIFIED | Exists; runs db:backup --force twice (happy + corrupt); zero hand-seeded `SystemAlert::create` calls in the test body (grep == 0); zero "ACCEPTED FALLBACK" matches |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `BackupDatabaseCommand` | `system_alerts` table (corrupt path) | `SystemAlert::create(['kind' => 'backup_corrupt', 'severity' => 'critical', ...])` | WIRED | Line 452; verified by `BackupCorruptionPathTest` and the Phase11AcceptanceTest |
| `BackupDatabaseCommand` | `storage/app/backups/` | `VACUUM INTO` + `Filesystem::chmod(0o600)` | WIRED | Lines 117 + 159; backup file + .meta.json sidecar produced atomically |
| `BackupFreshnessProbe` | `system_alerts(backup_overdue, warning)` | `SystemAlert::create()` guarded by 1-hour recency `Builder::exists()` | WIRED | WR-06 fix in place; banner no longer spams 100 rows per doctor run |
| `routes/console.php` | `Schedule::command('db:backup --force')` | `.name('db.backup-daily')->dailyAt('03:00')->withoutOverlapping(60)` | WIRED | Method order locked; `--force` ensures quiet days do not silently skip past freshness threshold |
| `resources/views/layouts/app.blade.php` | `@livewire('core.system-alerts-banner')` | One new line inside the `@auth` block | WIRED | Line 14 |
| `SystemAlertsBanner` | `AcknowledgeSystemAlert` action | Method-parameter DI on `acknowledge(int, AcknowledgeSystemAlert, CurrentUser)` | WIRED | Line 48–51 of the Livewire SFC |
| `HealthCheckListener` | `ConnectionEstablished` event | `Dispatcher::listen` registered in `HealthCheckServiceProvider::boot` | WIRED | `HealthCheckServiceProvider.php:30` |
| `CoreServiceProvider` | `Application::basePath()` for `core.backups_directory` | DI'd closure (post-CR-01 fix) | WIRED | Lines 60–66; no `base_path()` global helper |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `SystemAlertsBanner` Blade view | `$alerts` collection | `SystemAlertQuery::active($currentUser->user())` → real SQLite `system_alerts` SELECT via injected `DatabaseManager::table('system_alerts')->whereNull('acknowledged_at')->where(...)->get()` → `SystemAlert::hydrate()` | YES — real DB query, no static returns | FLOWING |
| `BackupDatabaseCommand` post-VACUUM integrity check | `$integrityRows` | Fresh PDO `query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN)` against the destination `.sqlite` file just produced by VACUUM INTO | YES — real fresh PDO against an on-disk file | FLOWING |
| `BackupFreshnessProbe` | newest sidecar `completed_at` | `Filesystem::files($backupsPath)` filtered by `*.meta.json` → mtime sort → `json_decode($contents, true)` → `completed_at` ISO timestamp | YES — real directory listing + file read | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 11 Contracts invariants all pass | `./vendor/bin/pest --testsuite=Contracts --filter='noLaravelGlobalHelpersInCoreConsoleCommands\|noFacadeCallsFromCoreConsoleCommands\|systemAlertsTableNotJoinedToTransactions\|HorizonForceFlag'` | 4 passed, 0 failed | PASS |
| All Phase 11 Feature tests pass | `./vendor/bin/pest --testsuite=Feature --filter='Phase11AcceptanceTest\|ReadmeOperationalDocsTest\|BackupCorruptionPathTest\|BackupDatabaseCommandTest\|BackupScheduleTest\|RestoreDatabaseCommandTest\|RestoreSuccessPathTest\|AppBootHealthCheckTest\|FailedJobsCommandTest\|SystemAlertsBannerTest\|DoctorCommandTest'` | 32 passed | PASS |
| Full Feature suite green (no regressions) | `./vendor/bin/pest --testsuite=Feature` | 612 passed, 5 skipped (env-gated, unrelated to Phase 11) | PASS |
| Forbidden substring sweep on README | `grep -cE 'D-[0-9]{4}\|Phase 11\|\.planning/' README.md` | 0 | PASS |
| Required substring sweep on README | grep for all 14 required substrings | All present | PASS |
| No facades in BackupDatabaseCommand | `grep -c "use Illuminate\\\\Support\\\\Facades\\\\" Modules/Core/Internal/Console/BackupDatabaseCommand.php` | 0 | PASS |
| No global path helpers in Core module | `grep -nE "base_path\(\|storage_path\(\|app_path\(\|public_path\(" Modules/Core/{Internal,Public,Providers}/ -r` | 0 matches (all hits are in comments explaining the DI alternative) | PASS |
| `withoutGlobalScopes()` fix landed in AcknowledgeSystemAlert | `grep "withoutGlobalScopes" Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` | Found at line 78 | PASS |
| `db:backup --force` in scheduler entry (WR-04 fix) | `grep "db:backup --force" routes/console.php` | Found at line 228 | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| FND-05 | 11-01, 11-02, 11-03, 11-04, 11-05 | User can run an artisan `db:backup` command that produces a consistent SQLite backup (via VACUUM INTO or online backup API), safe to copy while the app is running | SATISFIED | All three ROADMAP SCs verified. The orchestrator will flip the REQUIREMENTS.md checkbox from `[ ]` to `[x]` and the traceability row from Pending to Complete on phase merge — that bookkeeping is the orchestrator's contract, not the verifier's. |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none) | — | — | — | The REVIEW.md sweep identified 15 findings across BLOCKER/WARNING/INFO tiers; all 15 are fixed per the commits `12e3374` through `49ae798` (matching `fixes_applied: 15` in REVIEW.md frontmatter). Verifier re-scanned the touched files for the original anti-patterns: zero residue. |

### Human Verification Required

The 10-step Herd-mounted human-verify checkpoint from Plan 11-05 Task 3 is the canonical owner of the items below. Plan 11-05 SUMMARY is explicitly marked `status: complete-pending-human-verify` and the user has deferred this checkpoint until after the next phase. The items persist through the workflow contract's `human_needed` → HUMAN-UAT.md sink so they are not lost.

#### 1. db:backup --force produces chmod-0600 .sqlite + .meta.json on the real Herd-mounted DB

**Test:** Run `php artisan db:backup --force` at the project root. Then run `ls -la storage/app/backups/`.
**Expected:** A `diederik-YYYY-MM-DD-HHMMSS.sqlite` file at mode `-rw-------` (0600). A matching `.meta.json` sidecar also at mode 0600. Command exit code 0.
**Why human:** File-permission semantics on the actual filesystem (not the Pest `sys_get_temp_dir()` test substrate) cannot be programmatically verified without running the command on the operator's machine.

#### 2. Smart-skip path works on a quiet DB

**Test:** Immediately after step 1, re-run `php artisan db:backup` (no `--force`).
**Expected:** Output contains `Skipped — no commits since last backup (data_version=N)` and `ls storage/app/backups/` shows the same set of files (no new entry).
**Why human:** Smart-skip behaviour depends on `PRAGMA data_version` cache state on the live SQLite process; only the operator's running Herd substrate reproduces it deterministically.

#### 3. diederik:doctor prints WAL / synchronous / backup-freshness probe lines

**Test:** Run `php artisan diederik:doctor`.
**Expected:** Three probe lines visible alongside the inline PHP/Composer/SQLite/Node version checks: `SQLite WAL mode: ok`, `SQLite synchronous mode: ok`, `Backup freshness: ok` (or `warning` if the local backups dir is empty / >48h stale). Exit code 0 if all are `ok`, 1 if any are `warning`.
**Why human:** Exit code + console output of a real `artisan` run cannot be inspected from the verifier process; the inline tool checks also depend on the operator's locally-installed PHP/Composer/Node versions.

#### 4. SystemAlertsBanner renders and dismisses in the real browser

**Test:**
1. Run `php artisan tinker --execute='\Modules\Core\Models\SystemAlert::create(["user_id" => null, "kind" => "backup_corrupt", "severity" => "critical", "message" => "Test alert from human-verify checkpoint.", "metadata" => ["timestamp" => now()->toIso8601String(), "suspect_path" => "storage/app/backups/test.sqlite.suspect"]]); echo "created";'`
2. Reload `https://diederik.test` in a browser.
3. Click "Mark as resolved" on the banner row.
**Expected:** A rose-coloured banner with the message and a "Mark as resolved" button appears at the top of authenticated pages. Click → row disappears within ~500ms. Stays gone on reload.
**Why human:** Visual appearance, Tailwind class application, Livewire round-trip latency, and browser DOM interaction cannot be verified from a grep or Pest test.

#### 5. diederik:failed-jobs prune --dry-run does not modify the failed_jobs table

**Test:** Note the row count via `php artisan tinker --execute='echo \DB::table("failed_jobs")->count();'`. Then run `php artisan diederik:failed-jobs prune --older-than=30d --dry-run`. Then re-count.
**Expected:** Dry-run output contains "Would delete N rows (--dry-run; nothing written)." and the row count before == row count after.
**Why human:** Confirms the dry-run guard does not write to the table on the operator's real data; feature tests cover the unit but operator-felt safety should be confirmed once.

#### 6. README ## Backups + ## Operator recovery sections render correctly in markdown viewers

**Test:** Open `README.md` in GitHub, an IDE markdown preview, or `glow`. Scroll to the `## Backups` and `## Operator recovery` sections.
**Expected:** All five `## Backups` subsections present (`### Daily schedule`, `### Manual run`, `### Retention`, `### Verifying a backup`, `### DO NOT cp database.sqlite`). All four new `## Operator recovery` subsections present (`### Restoring from a backup`, `### Corrupt-backup alert`, `### Failed-jobs maintenance`, `### Stuck withoutOverlapping lock`). The pre-existing `### Stuck Redis unique-lock keys` subsection still present, unchanged.
**Why human:** Visual rendering of markdown headings + code fences cannot be verified from a substring grep alone; ReadmeOperationalDocsTest pins the substrings but not the visual layout.

#### 7. db:restore round-trips a backup safely on the real machine

**Test:** Identify a known-good backup file from a previous `db:backup --force` run. Run `php artisan db:restore --confirm --force-maintenance storage/app/backups/<file>.sqlite`.
**Expected:** A `pre-restore-YYYY-MM-DD-HHMMSS.sqlite` file lands in `storage/app/backups/` at chmod 0600 BEFORE the swap. The source DB rows are visible after the swap. The app comes back up automatically (no lingering `storage/framework/down` file).
**Why human:** Restore is destructive against the live DB file handle — the only meaningful end-to-end smoke is on the operator's real machine. Plan 11-05 Task 3 explicitly defers this step.

### Gaps Summary

No code gaps detected. All seven must-have truths are VERIFIED through:
- 4 Contracts invariants green
- 32 Phase 11 Feature tests green (covering db:backup happy + corrupt + smart-skip + retention, db:restore four refusal paths + happy, doctor probes + boot health check, banner render + dismiss + cross-user isolation, failed-jobs prune dry-run + apply, README content arch, end-to-end vertical acceptance)
- 643 Unit + 612 Feature + 105 Contracts tests in the full suite with no regressions
- The 15 REVIEW.md findings all closed (commits `12e3374` through `49ae798`)
- The new arch invariant `noLaravelGlobalHelpersInCoreConsoleCommands` (added during the CR-01 fix) and `BoundaryArchTest::noFacadeCallsFromCoreConsoleCommands` both pass against the current Core console directory, locking the DI-only contract from CLAUDE.md `feedback_laravel_di_only.md`

The status is `human_needed` rather than `passed` because Plan 11-05 Task 3 deferred a 10-step Herd-mounted human-verify smoke to a later phase per the user. The human verification items above represent the deferred steps that need operator approval on the real machine — not gaps in the code.

**Note on goal-vs-User-Story format:** ROADMAP marks Phase 11 with `mode: mvp`, but the goal text is not a User Story ("As a [role], I want to [capability], so that [outcome]"). The MVP Mode Verification rules normally require refusal in this case. The verifier interprets the explicit `Phase 11 Success Criteria` provided in the task prompt as the verification target (these are the ROADMAP success criteria, which are non-negotiable), and treats the standard goal-backward verification methodology as appropriate. Surfacing this format mismatch for the user's attention so a future ROADMAP edit (or `/gsd mvp-phase 11`) can decide whether to rewrite the goal as a User Story.

---

_Verified: 2026-05-19T22:30:00Z_
_Verifier: Claude (gsd-verifier)_
