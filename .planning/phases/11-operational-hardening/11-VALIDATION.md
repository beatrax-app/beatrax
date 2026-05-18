---
phase: 11
slug: operational-hardening
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-19
---

# Phase 11 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (built on PHPUnit 11) |
| **Config file** | `phpunit.xml` + `tests/Pest.php` |
| **Quick run command** | `./vendor/bin/pest --filter='<TestName>' --parallel` |
| **Full suite command** | `./vendor/bin/pest --parallel` |
| **Estimated runtime** | ~30–45 seconds full suite |

Additional gates (must all pass on every plan):
- `./vendor/bin/phpstan analyse --memory-limit=2G` (Larastan level 10, strict)
- `./vendor/bin/pint --test` (Laravel Pint formatting)
- `./vendor/bin/pest --testsuite=Contracts` (BoundaryArchTest invariants)

---

## Sampling Rate

- **After every task commit:** Run targeted Pest filter for the touched module + `pint --test` on modified files
- **After every plan wave:** Run full `pest --parallel` + `phpstan analyse` + `pint --test`
- **Before `/gsd:verify-work`:** Full suite + arch tests + static analysis must be green
- **Max feedback latency:** 60 seconds (parallel Pest run)

---

## Per-Task Verification Map

*The planner will populate this map from the generated PLAN.md files. Stubs below cover the five canonical waves outlined in RESEARCH.md / CONTEXT.md.*

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 11-01-01 | 01 | 0 | FND-05 | T-11-01 | `system_alerts` migration creates table with correct columns + indexes | feature | `pest --filter=SystemAlertsMigrationTest` | ❌ W0 | ⬜ pending |
| 11-01-02 | 01 | 0 | FND-05 | T-11-01 | `Modules\Core\Models\SystemAlert` model casts metadata→array, scopes active/byKind | unit | `pest --filter=SystemAlertModelTest` | ❌ W0 | ⬜ pending |
| 11-01-03 | 01 | 0 | FND-05 | — | `SystemAlertQuery::active(?User)` returns per-user + system rows ordered by severity DESC | unit | `pest --filter=SystemAlertQueryTest` | ❌ W0 | ⬜ pending |
| 11-01-04 | 01 | 0 | FND-05 | T-11-04 | `AcknowledgeSystemAlert` stamps `acknowledged_at = now()`, idempotent, transactional | unit | `pest --filter=AcknowledgeSystemAlertTest` | ❌ W0 | ⬜ pending |
| 11-02-01 | 02 | 1 | FND-05 | T-11-02 | `BackupRetentionPolicy::prune()` returns expected 7-daily + 4-Sunday-weekly subset | unit | `pest --filter=BackupRetentionPolicyTest` | ❌ W0 | ⬜ pending |
| 11-02-02 | 02 | 1 | FND-05 | T-11-02 | `BackupDatabaseCommand` writes verified sidecar + chmod 600 + smart-skip on unchanged data_version | feature | `pest --filter=BackupDatabaseCommandTest` | ❌ W0 | ⬜ pending |
| 11-02-03 | 02 | 1 | FND-05 | T-11-03 | Corrupt VACUUM INTO output writes `.suspect` + `system_alerts` (kind=backup_corrupt, severity=critical) + exits non-zero | feature | `pest --filter=BackupCorruptionPathTest` | ❌ W0 | ⬜ pending |
| 11-02-04 | 02 | 1 | FND-05 | — | `Schedule::command('db:backup')->name(...)->dailyAt('03:00')->withoutOverlapping()` registered in routes/console.php | feature | `pest --filter=BackupScheduleTest` | ❌ W0 | ⬜ pending |
| 11-03-01 | 03 | 2 | FND-05 | T-11-04 | `RestoreDatabaseCommand` refuses without `--confirm` + non-maintenance-mode + corrupt source | feature | `pest --filter=RestoreDatabaseCommandTest` | ❌ W0 | ⬜ pending |
| 11-03-02 | 03 | 2 | FND-05 | T-11-04 | Restore takes pre-restore snapshot, swaps file, re-checks integrity post-swap | feature | `pest --filter=RestoreSuccessPathTest` | ❌ W0 | ⬜ pending |
| 11-03-03 | 03 | 2 | FND-05 | — | `WalModeProbe`, `SynchronousModeProbe`, `BackupFreshnessProbe` each implement `Probe` contract + correct severities | unit | `pest --filter=DoctorProbesTest` | ❌ W0 | ⬜ pending |
| 11-03-04 | 03 | 2 | FND-05 | — | `diederik:doctor` prints summary table, exits non-zero on warning/critical | feature | `pest --filter=DoctorCommandTest` | ❌ W0 | ⬜ pending |
| 11-03-05 | 03 | 2 | FND-05 | — | `HealthCheckServiceProvider` writes `system_alerts(kind=wal_mode_missing/synchronous_misconfigured)` on boot drift without halting | feature | `pest --filter=AppBootHealthCheckTest` | ❌ W0 | ⬜ pending |
| 11-04-01 | 04 | 3 | FND-05 | T-11-05 | `FailedJobsCommand prune --older-than=30d` deletes correctly + `--dry-run` is non-destructive | feature | `pest --filter=FailedJobsCommandTest` | ❌ W0 | ⬜ pending |
| 11-04-02 | 04 | 3 | FND-05 | — | `SystemAlertsBanner` Livewire SFC renders active alerts, dismisses via Acknowledge action, refreshes on `alert-acknowledged` dispatch | feature | `pest --filter=SystemAlertsBannerTest` | ❌ W0 | ⬜ pending |
| 11-04-03 | 04 | 3 | FND-05 | — | `BoundaryArchTest::noFacadeCallsFromCoreConsoleCommands` + `systemAlertsTableNotJoinedToTransactions` both pass | unit | `pest --testsuite=Contracts` | ❌ W0 | ⬜ pending |
| 11-04-04 | 04 | 3 | FND-05 | — | README `## Backups` + `## Operator recovery` sections rewrite verified by content assertion | feature | `pest --filter=ReadmeOperationalDocsTest` | ❌ W0 | ⬜ pending |
| 11-04-05 | 04 | 3 | FND-05 | T-11-04 | Pest arch test asserting no Horizon supervisor uses `force: true` (A2 invariant from RESEARCH) | unit | `pest --filter=HorizonForceFlagTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Core/SystemAlertsMigrationTest.php` — table schema + indexes
- [ ] `tests/Unit/Core/SystemAlertModelTest.php` — scopes + casts
- [ ] `tests/Unit/Core/SystemAlertQueryTest.php` — per-user + system-wide read
- [ ] `tests/Unit/Core/AcknowledgeSystemAlertTest.php` — action behavior
- [ ] `tests/Unit/Core/BackupRetentionPolicyTest.php` — 7-daily + 4-Sunday-weekly dataset
- [ ] `tests/Feature/Backup/BackupDatabaseCommandTest.php` — VACUUM INTO + sidecar + smart-skip
- [ ] `tests/Feature/Backup/BackupCorruptionPathTest.php` — `.suspect` + `system_alerts` row + non-zero exit
- [ ] `tests/Feature/Backup/BackupScheduleTest.php` — schedule registration assertion
- [ ] `tests/Feature/Backup/RestoreDatabaseCommandTest.php` — three safety rails
- [ ] `tests/Feature/Backup/RestoreSuccessPathTest.php` — pre-restore snapshot + post-swap integrity
- [ ] `tests/Unit/Core/DoctorProbesTest.php` — each probe in isolation
- [ ] `tests/Feature/Core/DoctorCommandTest.php` — command-level integration
- [ ] `tests/Feature/Core/AppBootHealthCheckTest.php` — boot probe writes alert, no halt
- [ ] `tests/Feature/Core/FailedJobsCommandTest.php` — prune + dry-run
- [ ] `tests/Feature/Core/SystemAlertsBannerTest.php` — Livewire SFC lifecycle
- [ ] `tests/Feature/Core/ReadmeOperationalDocsTest.php` — README content assertion
- [ ] `tests/Contracts/HorizonForceFlagTest.php` — A2 invariant
- [ ] `tests/Contracts/BoundaryArchTest.php` — extend with two new `it(...)` blocks (existing file)
- [ ] Pest test helper for real-on-disk SQLite fixtures (verify Phase 5/9 precedent first; create only if missing)

*Existing Pest 4 infrastructure (`phpunit.xml`, `tests/Pest.php`) covers framework setup; no install step required.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real `php artisan db:backup` run against Herd-mounted live SQLite produces a verified file | FND-05 SC #1 | Automated tests use a fixture DB; the smoke test against the real Herd DB confirms storage permissions + scheduler timing on the user's actual machine | 1. `php artisan db:backup` 2. Verify file in `storage/app/backups/` 3. Verify `chmod 600` 4. Open via `sqlite3` CLI, run `PRAGMA integrity_check` |
| Persistent banner appears + dismisses for a real `backup_corrupt` alert | FND-05 SC #2 | Visual / interaction validation in Herd browser (Livewire `wire:click` + Tailwind tokens render correctly) | 1. Insert a `system_alerts(kind=backup_corrupt, severity=critical)` row 2. Reload any page in browser at `https://diederik.test` 3. Banner visible at top 4. Click "Mark as resolved" 5. Banner disappears, `acknowledged_at` stamped |
| Restore command rolls back cleanly when source is corrupted | FND-05 SC #3 | Filesystem swap under maintenance mode is hard to fully simulate in automated tests | 1. `php artisan down` 2. `php artisan db:restore --confirm path/to/corrupt.sqlite` 3. Confirm pre-restore snapshot exists 4. Confirm live DB unchanged 5. Confirm `php artisan up` returned the app to serving |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
