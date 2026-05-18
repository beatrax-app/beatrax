# Phase 11: Operational Hardening - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-19
**Phase:** 11-operational-hardening
**Areas discussed:** Backup storage + retention, Scheduling cadence, Failure-surface visibility, Reliability scope (multi-select), Smart-skip signal, Banner persistence, Restore-command safety rails, Boot-check failure mode

---

## Backup storage + retention

| Option | Description | Selected |
|--------|-------------|----------|
| storage/app/backups/, keep last 7 daily + 4 weekly | Repo-local storage, 7-day rolling + 4 weekly snapshots, ~11 files max, predictable disk usage. | ✓ |
| storage/app/backups/, keep forever | Every backup retained until manual prune. Honest about 'never lose data' but unbounded disk growth (~18 GB/year on 50 MB DB). | |
| User-configured external path via env var | `BACKUP_PATH=` points to e.g. ~/Diederik-Backups or external drive. Portable but adds config step. | |

**User's choice:** `storage/app/backups/`, keep last 7 daily + 4 weekly
**Notes:** "Full history retained forever" applies to transaction rows inside the DB, not to backup snapshots — those are point-in-time recovery artifacts. Pre-restore snapshots get a separate "keep forever" retention bucket inside the same directory.

---

## Scheduling cadence

| Option | Description | Selected |
|--------|-------------|----------|
| Automatic daily via Laravel scheduler | `Schedule::command('db:backup')->dailyAt('03:00')` runs via existing launchd `schedule:work` daemon. Manual invocation still works. | |
| On-demand only | User runs `php artisan db:backup` manually. Simpler, no scheduler entanglement. | |
| Automatic + smart skip | Daily, but skip if no data changes since last backup. Saves disk + cycles. | ✓ |

**User's choice:** Automatic + smart skip
**Notes:** Triggered a follow-up question on which signal to use for "no changes" detection. `PRAGMA data_version` selected (see "Smart-skip signal" area below).

---

## Failure-surface visibility

| Option | Description | Selected |
|--------|-------------|----------|
| Non-zero exit code + structured log + persistent dashboard banner until acknowledged | Exit code for scripts, log for forensics, in-app banner that can't be silently ignored. | ✓ |
| Exit code + log only | Standard CLI behavior; corrupt-backup could sit unnoticed until user manually checks logs. | |
| Exit code + log + macOS notification | osascript-driven native notification. More noticeable but macOS-specific code path. | |

**User's choice:** Non-zero exit code + structured log + persistent dashboard banner until acknowledged
**Notes:** Persistent banner required because a corrupt backup is data-safety-load-bearing — silent failure here defeats the entire phase. macOS notifications rejected because they're easier to dismiss than a banner, and the user might not always be at the machine when the scheduler fires.

---

## Reliability scope (multi-select)

| Option | Description | Selected |
|--------|-------------|----------|
| db:restore command (companion to db:backup) | `php artisan db:restore path/to/backup.sqlite` swaps live DB after warning + integrity-check. | ✓ |
| Extend diederik:doctor with WAL/synchronous/backup-freshness probes | Adds three probes to existing doctor command, surfaces drift in one place. | ✓ |
| Failed-jobs maintenance CLI | `php artisan diederik:failed-jobs prune --older-than=30d` + summary. Keeps failed_jobs table bounded. | ✓ |
| App-boot health check (asserts WAL + synchronous on startup) | `AppServiceProvider::boot()` verifies PRAGMAs at runtime. Loud-fail on misconfig. | ✓ |

**User's choice:** ALL FOUR
**Notes:** User chose every reliability touch — Phase 11 ends up meatier than the minimum SC#1–3 scope, but every additional item is well-bounded and closes a specific gap in the daily-use story. App-boot check changed from "loud-fail" to "warn + continue" after the follow-up question (see "Boot-check failure mode" area below).

---

## Smart-skip signal

| Option | Description | Selected |
|--------|-------------|----------|
| Compare SQLite `PRAGMA data_version` | Built-in counter, increments on every commit. Cheap, reliable, immune to mtime quirks. | ✓ |
| Compare DB file mtime vs last backup mtime | Cheap and obvious, but WAL-mode mtime lags commits until checkpoint. Could miss backups or run unnecessary ones. | |
| Compare aggregate row counts across key tables | Robust against mtime weirdness but slow on growing transactions table; no-op updates (recategorize) don't change row count. Worst of both worlds. | |

**User's choice:** Compare SQLite `PRAGMA data_version`
**Notes:** Canonical SQLite signal — one PRAGMA call per scheduled run. Each backup writes a sidecar `<backup>.meta.json` storing the data_version it captured; the next run compares live data_version against that sidecar.

---

## Banner persistence

| Option | Description | Selected |
|--------|-------------|----------|
| New `system_alerts` DB table | Reusable beyond Phase 11; composes with Eloquent + Livewire; mirrors `forecast_shortfall_windows` shape. | ✓ |
| JSON file under `storage/app/system-alerts.json` | No migration but requires hand-rolled concurrency-safe read/write. Creates two persistence stories. | |
| `config/system-alerts` dotfile read on boot | Even simpler but conflates config and runtime state. Surprises future maintainers. | |

**User's choice:** New `system_alerts` DB table
**Notes:** Schema deliberately broad so Phase 6 (IMAP failures), Phase 8 (detection-job failures), and Phase 9 (drift-alert dispatch failures) can write rows in v2 without a migration. v1 writes four `kind`s: `backup_corrupt`, `backup_overdue`, `wal_mode_missing`, `synchronous_misconfigured`.

---

## Restore-command safety rails

| Option | Description | Selected |
|--------|-------------|----------|
| Auto pre-restore snapshot + `--confirm` flag + maintenance-mode required | Three rails simultaneously. Restoring is rare; ceremony is cheap. Pre-restore snapshot makes "wrong source path" recoverable. | ✓ |
| `--confirm` flag only, no auto pre-restore | Lower ceremony but user must remember to back up current DB before restoring. | |
| Maintenance-mode required, no other rails | Less ceremony, single safety primitive. | |

**User's choice:** Auto pre-restore snapshot + `--confirm` flag + maintenance-mode required
**Notes:** `--force-maintenance` flag added so scripts can chain `db:restore --confirm --force-maintenance <path>` without manual `php artisan down`. Pre-restore snapshots use a separate filename pattern (`pre-restore-*`) so the retention pruner never touches them.

---

## Boot-check failure mode

| Option | Description | Selected |
|--------|-------------|----------|
| Warn + log + write a `system_alerts` row, app continues | Treats drift as warning, surfaces via banner on next page load. Doctor command surfaces the same drift on demand. | ✓ |
| Fail loud (throw exception, halt boot) | Treats WAL/synchronous invariants as load-bearing for money safety; refuse to start otherwise. Safer in theory but locks out the user entirely on a borked Herd PHP switch. | |

**User's choice:** Warn + log + write a `system_alerts` row, app continues
**Notes:** A borked PHP version switch on Herd is a realistic-enough failure mode that locking the user out of the app entirely is a worse outcome than a banner. The doctor command provides the same visibility on demand, and the boot check accumulates alerts in `system_alerts` so they don't disappear.

---

## Claude's Discretion

- Exact `<duration>` parser shape for `diederik:failed-jobs --older-than=` — planner picks Carbon `sub*` style or a small custom parser.
- Whether the app-boot check lives in `AppServiceProvider::boot()` or a dedicated `HealthCheckServiceProvider` under `Modules/Core/Internal/Providers/`.
- Banner Tailwind palette — severity colors should mirror existing Phase 9 drift-alert / Phase 5 chain-link confidence tier tokens.
- Pest test split between `tests/Feature/` and `tests/Unit/` within the Phase 11 test list.
- Whether existing probes in `DoctorCommand` get refactored into the new `Probe` contract as part of Phase 11 or deferred.
- The internal name of the `Probe` contract interface.

## Deferred Ideas

- Cloud-uploaded backups (PROJECT.md "Local only" constraint).
- Backup-file encryption-at-rest (asymmetric vs unencrypted live DB; revisit if partner-sharing materializes).
- macOS notifications on alert creation (banner won; could be added later as opt-in).
- Multi-DB / split-brain restore into a sandbox copy (out of scope for single-user single-DB model).
- Backup retention configurability via env var (`BACKUP_KEEP_DAILY=14` etc.) — hardcoded for v1.
- Telescope cleanup CLI / Debugbar production-disable guard (local-only project, no production target yet).
- Log rotation CLI (Laravel's daily-log channel handles natively).
- Backup-restoration drill scheduled task (auto-restore into sandbox to prove it boots) — overkill for single-user local tool.
