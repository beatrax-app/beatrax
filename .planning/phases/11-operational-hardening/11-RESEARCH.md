# Phase 11: Operational Hardening - Research

**Researched:** 2026-05-19
**Domain:** SQLite backup/restore, Laravel 13 console + scheduling, persistent-alert UI surface, operational reliability probes
**Confidence:** HIGH

## Summary

Phase 11 closes out v1 by shipping the operational layer that turns the app into a daily-use tool: `db:backup` (VACUUM INTO + integrity check + retention + smart-skip), `db:restore` (three safety rails), a `system_alerts` persistent failure surface (table + Eloquent + Public service/action + persistent banner), three new `diederik:doctor` probes via a shared `Probe` contract, a `diederik:failed-jobs prune` CLI, an app-boot SQLite-PRAGMA health check that warns-and-continues, README rewrites of the Backups + Operator recovery sections, and two new BoundaryArchTest invariants.

Every locked decision in CONTEXT.md (D-1101 … D-1119) lines up cleanly with what the substrate already supports — there are NO surprises in the SQLite/Laravel mechanics, but there are FIVE concrete gotchas the planner must address: (1) `PRAGMA data_version` is connection-local with caching quirks and must be read from a fresh `PDO` connection to be honest as a smart-skip signal; (2) `VACUUM INTO` is a write operation against the *destination* but a read operation against the *source* — it does NOT take a write lock on the live DB, but it DOES require no other connection to be holding the write lock; (3) the existing `SqliteOptimizationsProvider` re-applies WAL + synchronous=NORMAL on every new connection via `ConnectionEstablished` event — so after `db:restore` swaps the file, the Laravel-level connection reset (`DB::purge()`) is sufficient to re-apply PRAGMAs against the freshly-swapped file; (4) Laravel scheduler entries live in `routes/console.php` at the project root (NOT in module ServiceProviders) — this is established convention and the project's `BoundaryArchTest` "no Laravel facade usage in module code" rule is intentionally scoped via `->not->toBeUsedIn('Modules')` so routes/console.php's `Schedule::call` / `Schedule::command` usage is legal; (5) Livewire 4 Components in this codebase use **method-parameter DI on `render()`** — constructor DI on `Component` subclasses is banned by `phpstan-strict-rules`.

**Primary recommendation:** Treat the locked decisions as the contract; use this research to ground each implementation choice in current SQLite/Laravel/Livewire semantics; carry forward existing conventions (Phase 9 DriftAlerts shape for `system_alerts` + banner; Phase 5/9 migration shape with state triggers; Phase 6 chmod-600 atomic-write pattern for backup files; existing `routes/console.php` for `Schedule::command('db:backup')`).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|--------------|----------------|-----------|
| `db:backup` (VACUUM INTO + verify + retain) | Backend / Console | — | Local-machine CLI; no UI tier involvement. Lives in `Modules/Core/Internal/Console/`. |
| `db:restore` (safety-railed swap) | Backend / Console | — | Same as above. Maintenance-mode lifecycle controls how the API tier interacts with it. |
| Backup retention policy (file-pattern pruner) | Backend / Domain logic | — | Pure value-object class for unit-testability; consumed by the backup command. |
| `system_alerts` persistence | Database / Storage | — | New Eloquent table + Public read/mutate API; mirrors DriftAlerts. |
| `system-alerts-banner` Livewire SFC | Frontend Server (SSR) | Database (via SystemAlertQuery) | Server-rendered Livewire component slotted into `app.blade.php`; reads through SystemAlertQuery. |
| `AcknowledgeSystemAlert` action | API / Backend (action layer) | Database | Public Action invoked from the banner via `wire:click`; constructor-DI'd, transactional. |
| Doctor probes (WAL / synchronous / backup-freshness) | Backend / Console | Database (PRAGMA reads + file IO) | Console-only surface; each probe is a small testable class implementing the `Probe` contract. |
| App-boot PRAGMA health check | Backend / Service Provider boot | Database (PRAGMA reads), Storage (writes system_alerts row) | Runs in a Service Provider's `boot()`; writes alert + log, never halts boot. |
| `diederik:failed-jobs prune` | Backend / Console | Database (failed_jobs table) | Console-only surface; reads + deletes from the Laravel-managed `failed_jobs` table. |
| Backup scheduling | Backend / Scheduler | — | Single `Schedule::command(...)` entry in existing `routes/console.php`; no new launchd plist. |
| BoundaryArchTest invariants | Test infrastructure | — | Pure Pest arch tests in `tests/Contracts/BoundaryArchTest.php`. |
| README rewrite | Documentation | — | Operator-facing prose only; no code impact. |

## User Constraints (from CONTEXT.md)

### Locked Decisions

**D-1101: VACUUM INTO is the locked backup mechanism.** Single SQL statement; atomic; produces a compacted copy of the live DB while WAL writes continue uninterrupted. Online-backup API (`SQLite3::backup`) rejected. ROADMAP SC #1 lists both as acceptable; planner picks `VACUUM INTO`.

**D-1102: Backup destination is `storage/app/backups/`, files named `diederik-YYYY-MM-DD-HHMMSS.sqlite`, permissions `chmod 600`.** Mirrors the `storage/app/secrets/imap.json` permission precedent. Directory created on first run via `mkdir -p`. Gitignored.

**D-1103: Retention: keep the latest 7 daily + 4 most-recent Sunday-dated weekly snapshots.** Predictable ≤11 files at steady state on a ~50MB DB. Pruner lives in `BackupRetentionPolicy` value object.

**D-1104: Pre-restore snapshots (filename pattern `pre-restore-YYYY-MM-DD-HHMMSS.sqlite`) are NEVER pruned automatically.** Separate retention bucket; operator prunes manually.

**D-1105: `db:backup` runs daily at 03:00 via `Schedule::command('db:backup')->dailyAt('03:00')->withoutOverlapping()` in `routes/console.php`.** Existing scheduler plist already fires `schedule:work`. NO new launchd plist.

**D-1106: Smart-skip signal is `PRAGMA data_version`.** Each successful backup writes `<backup>.meta.json` containing `{ data_version, started_at, completed_at, integrity: 'ok' }`. On next run, compare live data_version against most-recent backup's sidecar; equal → log "skipped" and exit 0.

**D-1107: `php artisan db:backup --force` bypasses smart-skip.**

**D-1108: `system_alerts` is a first-class new persistence surface.** Migration + Eloquent + Public read API + Public action. Schema deliberately broad so future modules can write rows without migration.

**D-1109: Persistent dashboard banner renders all active critical alerts at the top of every page, sticky until acknowledged.** Lives in `app.blade.php` between top nav and page content. Livewire SFC reads `SystemAlertQuery::active($currentUser)` on mount.

**D-1110: A failed-integrity-check is the only `critical` severity in Phase 11.** The other three v1 kinds (`backup_overdue`, `wal_mode_missing`, `synchronous_misconfigured`) are `warning`.

**D-1111: A corrupt backup file is preserved on disk with a `.suspect` suffix.** Retention pruner ignores `.suspect` files entirely.

**D-1112: `db:restore` requires THREE rails simultaneously: maintenance mode + pre-restore snapshot + `--confirm`.** `--force-maintenance` flag chains `down` and `up`.

**D-1113: Source-file validation runs BEFORE the swap.** Open via fresh `PDO`, run `PRAGMA integrity_check`; refuse if not `ok`.

**D-1114: Post-swap, run integrity_check one more time.** Belt-and-braces. If THIS fails, keep maintenance mode ON, log critical `system_alerts` row, exit non-zero.

**D-1115: `diederik:doctor` gains three probes via a shared `Probe` contract under `Modules/Core/Internal/Console/Probes/`.** `WalModeProbe`, `SynchronousModeProbe`, `BackupFreshnessProbe`. Contract: `run(): ProbeResult` returning ok / warning / critical + message. Existing probes refactor into the same contract.

**D-1116: The `BackupFreshnessProbe` is the ONLY one that ALSO writes a `system_alerts` row.** Other two are read-only in doctor; their alert-row writes come from the app-boot check.

**D-1117: `diederik:failed-jobs prune {--older-than=30d} {--dry-run}`.** Subcommand-style for future-proofing. Default 30d.

**D-1118: App-boot health check writes alerts but does NOT halt boot.** Loud-fail rejected.

**D-1119: `db:backup` and `db:restore` use the `db:` namespace despite the project-wide `diederik:` convention.** Locked by ROADMAP SC #1 and FND-05. Remaining new commands (`diederik:failed-jobs`) keep the existing convention.

### Claude's Discretion

- Exact `<duration>` parser shape for `diederik:failed-jobs --older-than=`.
- Whether the app-boot check lives in `AppServiceProvider::boot()` or a new `Modules\Core\Internal\Providers\HealthCheckServiceProvider`.
- Blade banner Tailwind tokens (severity colors should mirror existing Phase 9 drift-alert / Phase 5 chain-link confidence tier palette).
- Pest test split between Feature/ and Unit/.
- Internal name of the `Probe` contract.
- Whether existing `DoctorCommand` internals get refactored into the new contract as part of Phase 11 or deferred.

### Deferred Ideas (OUT OF SCOPE)

- Cloud-uploaded / off-machine backups.
- Encryption-at-rest for backup files beyond filesystem `chmod 600`.
- iCloud / Time Machine integration.
- Outbound notifications (email, push, macOS notification) on alert creation.
- Scenario-style "scheduled-task management" UI.
- New launchd plist templates for the backup command.
- Multi-tenant alert routing (`user_id` nullable from day one, but v1 has one user).
- Restore-into-a-different-DB-file (split-brain testing).
- Telescope cleanup CLI / Debugbar production-disable guard.
- Log rotation CLI.
- Backup-restoration drill scheduled task.
- Backup retention configurability via env var.

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FND-05 | User can run an artisan `db:backup` command that produces a consistent SQLite backup (via `VACUUM INTO` or online backup API), safe to copy while the app is running | SQLite docs (lang_vacuum.html) confirm `VACUUM INTO` produces a consistent snapshot without modifying the original DB; output is a single .sqlite file with no WAL sidecars; with `PRAGMA synchronous=NORMAL` on the source DB, SQLite calls `fsync()` on the output file. `BackupRetentionPolicy` value object + retention rules cover the operational layer. README rewrite documents the `cp database.sqlite` forbiddance. |

## Project Constraints (from CLAUDE.md)

- **PHP 8.3 → 8.5** (composer.json pins `^8.5`).
- **Laravel 13** (composer.json pins `^13.0`).
- **nwidart/laravel-modules** — module Public/Internal split (Phase 1 architecture lock).
- **DI-only** — no `DB::`, `Schedule::`, `Storage::`, `Artisan::`, `Cache::` facade calls in production module code. Receive `DatabaseManager`, `Filesystem`, `Kernel`, etc. instances instead. Eloquent model statics ARE allowed. **Exception:** `routes/console.php` is OUTSIDE the `Modules\` namespace, so `Schedule::*` calls there are legal (BoundaryArchTest scopes the rule via `->not->toBeUsedIn('Modules')`).
- **Livewire 4 Component subclasses** — method-parameter DI on `render()`, NOT constructor DI (phpstan-strict-rules forbids constructor DI on `Component` subclasses).
- **Codebase agnostic from GSD** — NO `.planning/`, `D-1101`, `Phase 11`, `PLAN.md` references in production code/comments/PHPDocs. Rationale in plain technical language.
- **Docs describe current state** — README explains what `db:backup` DOES today, not "we added this in Phase 11".
- **Larastan level 10 (strict)** + Laravel Pint + Pest must all pass — no relaxations.
- **`storage/app/secrets/imap.json` chmod 600 precedent** (Phase 1 PLT-05) — file-permission convention applied to `storage/app/backups/*.sqlite`.
- **JSON casts** — use Spatie Data DTOs for typed values where downstream callers need a stable shape (mirrors Phase 9 `DriftAlertDto`). For raw metadata JSON columns, `->cast('metadata', 'array')` is the established shape.
- **Eloquent + raw DatabaseManager hybrid** — strict-rules `staticMethod.dynamicCall` forbids `Eloquent::query()->exists()`; the established workaround is raw `DatabaseManager::connection()->table(...)->count() > 0` for existence checks (see `PreviewWizard::needsIcsAccountName`, `ClassifyTransactionType`).

## Standard Stack

### Core (already on substrate — no new dependencies)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP | `^8.5` | Runtime | Pinned in composer.json |
| Laravel | `^13.0` | Framework — Console, Scheduling, Maintenance mode, Filesystem, DatabaseManager | Pinned in composer.json [VERIFIED: composer.json line 13] |
| Livewire | `^4.0` | Banner SFC | Pinned in composer.json [VERIFIED: composer.json line 19] |
| Flux UI | `^2.0` | Banner chrome (dismiss button, severity badges) | Already used for drift-page + chain-drawer [VERIFIED: composer.json line 18] |
| spatie/laravel-data | `^4.0` | Optional: typed DTO wrapper around `SystemAlertDto` if the planner wants a DTO-mapper shape mirroring DriftAlerts | Mirrors Phase 9 `DriftAlertDto` [VERIFIED: composer.json line 23] |
| pestphp/pest | `^4.0` + `pest-plugin-arch ^4.0` + `pest-plugin-laravel ^4.0` | Tests + arch invariants | Already on substrate [VERIFIED: composer.json] |
| larastan/larastan | `^3.0` + strict-rules `^2.0` | Static analysis at level 10 | Already on substrate [VERIFIED: composer.json] |
| Carbon | (ships with Laravel) | Duration parsing for `--older-than=`, scheduler dates | Built-in [VERIFIED: Laravel framework] |

### No new dependencies required for Phase 11

Every locked decision can be implemented against the existing dependency set. The planner SHOULD NOT add any new composer package for this phase.

**Version verification:**
- `composer.json` is the source of truth. The above versions are read verbatim from the file at research time.
- No `npm view` / `pip index versions` calls needed because the phase introduces no new dependencies.

## Package Legitimacy Audit

> Not required for this phase: Phase 11 installs zero new external packages. Every implementation choice maps to a library already in `composer.json` (verified at research time). If during planning a fresh dependency is proposed, the planner MUST run the Package Legitimacy Gate at that point.

| Package | Disposition |
|---------|-------------|
| (none) | Phase 11 introduces no new dependencies. |

## Architecture Patterns

### System Architecture Diagram

```
                    ┌──────────────────────────────────────┐
                    │  Browser (Livewire 4)                │
                    │  ┌────────────────────────────────┐  │
                    │  │ system-alerts-banner SFC       │  │
                    │  │ - reads SystemAlertQuery       │  │
                    │  │ - wire:click=acknowledge({id}) │──┼──┐
                    │  └────────────────────────────────┘  │  │
                    └──────────────────────────────────────┘  │
                                                              ▼
   ┌─────────────────────────────────────────────────────────────────┐
   │  Frontend Server (SSR) — app.blade.php @auth slot               │
   │  ┌─────────────────────────────────────────────────────────────┐│
   │  │ @livewire('core.system-alerts-banner')                      ││
   │  └─────────────────────────────────────────────────────────────┘│
   └──────────────────────┬──────────────────────────────────────────┘
                          │ ↑ acknowledge
                          ▼ │
   ┌──────────────────────────────────────────────────────────────┐
   │  API / Backend (Action + Service layer)                      │
   │                                                              │
   │  SystemAlertQuery::active(?User $user)                       │
   │  AcknowledgeSystemAlert::__invoke(int $alertId, User $user)  │
   │                                                              │
   └──────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  Database (SQLite WAL + synchronous=NORMAL)                  │
   │   - system_alerts table (NEW)                                │
   │   - failed_jobs table (existing, pruned by diederik:        │
   │     failed-jobs prune)                                       │
   └──────────────────────────────────────────────────────────────┘

   Console-only flows (cron + ad-hoc):

   schedule:work (launchd) ── fires daily 03:00 ─→ db:backup
                                                       │
                                                       ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  BackupDatabaseCommand                                        │
   │   1. Read live PRAGMA data_version (fresh PDO connection)     │
   │   2. Compare to most-recent <backup>.meta.json data_version   │
   │   3. If equal AND not --force: log "skipped", exit 0          │
   │   4. Else: VACUUM INTO 'storage/app/backups/diederik-…sqlite' │
   │   5. Open backup via fresh PDO, run PRAGMA integrity_check    │
   │   6. If ok: chmod 600 + write <backup>.meta.json              │
   │   7. If not ok: rename to .suspect + write system_alerts row  │
   │      (kind=backup_corrupt, severity=critical) + exit non-zero │
   │   8. BackupRetentionPolicy::prune() (7 daily + 4 weekly)      │
   └──────────────────────────────────────────────────────────────┘

   diederik:doctor (manual or CI):
   ┌──────────────────────────────────────────────────────────────┐
   │ DoctorCommand iterates Probe[]:                              │
   │   - WalModeProbe          (PRAGMA journal_mode)              │
   │   - SynchronousModeProbe  (PRAGMA synchronous)               │
   │   - BackupFreshnessProbe  (newest <backup>.meta.json mtime)  │
   │       └─ writes system_alerts(backup_overdue, warning) on fail│
   │   - existing probes (PHP/Composer/SQLite/Node versions)      │
   └──────────────────────────────────────────────────────────────┘

   App boot (AppServiceProvider or HealthCheckServiceProvider):
   ┌──────────────────────────────────────────────────────────────┐
   │ HealthCheckServiceProvider::boot()                            │
   │   - Read PRAGMA journal_mode, PRAGMA synchronous on default   │
   │     connection                                                │
   │   - If wrong: write system_alerts row (wal_mode_missing /     │
   │     synchronous_misconfigured, severity=warning) + log        │
   │   - Continue booting either way                               │
   └──────────────────────────────────────────────────────────────┘

   db:restore (operator-only):
   ┌──────────────────────────────────────────────────────────────┐
   │ RestoreDatabaseCommand                                       │
   │   1. Require maintenance mode OR --force-maintenance          │
   │   2. Require --confirm flag (or TTY y/N prompt)               │
   │   3. Open source via fresh PDO; PRAGMA integrity_check        │
   │      (refuse if not ok)                                       │
   │   4. VACUUM INTO 'pre-restore-…sqlite' (pre-restore snapshot) │
   │   5. DB::purge() (via DatabaseManager) — close all conns      │
   │   6. copy(source, configured DB path)                         │
   │   7. Force reconnect — SqliteOptimizationsProvider re-applies │
   │      WAL + synchronous PRAGMAs via ConnectionEstablished event│
   │   8. PRAGMA integrity_check on swapped DB                     │
   │   9. artisan up (if --force-maintenance brought it down)      │
   └──────────────────────────────────────────────────────────────┘
```

### Recommended Project Structure

```
Modules/Core/
├── Database/
│   └── Migrations/
│       └── 2026_05_19_XXXXXX_create_system_alerts_table.php   # new
├── Internal/
│   ├── Console/
│   │   ├── BackupDatabaseCommand.php                          # new (db:backup)
│   │   ├── RestoreDatabaseCommand.php                         # new (db:restore)
│   │   ├── FailedJobsCommand.php                              # new (diederik:failed-jobs)
│   │   ├── DoctorCommand.php                                  # extended (3 new probes)
│   │   ├── InstallCommand.php                                 # untouched (reference for conventions)
│   │   ├── Probes/
│   │   │   ├── Probe.php                                      # new interface
│   │   │   ├── ProbeResult.php                                # new value object
│   │   │   ├── WalModeProbe.php                               # new
│   │   │   ├── SynchronousModeProbe.php                       # new
│   │   │   └── BackupFreshnessProbe.php                       # new
│   │   └── Support/
│   │       ├── BackupRetentionPolicy.php                      # new value object
│   │       └── DurationParser.php                             # new (or fold into FailedJobsCommand)
│   ├── Http/
│   │   └── Livewire/
│   │       └── SystemAlertsBanner.php                         # new Livewire Component
│   └── Providers/
│       ├── FortifyServiceProvider.php                         # untouched
│       ├── SqliteOptimizationsProvider.php                    # untouched (key reference)
│       └── HealthCheckServiceProvider.php                     # new (or merge into AppServiceProvider)
├── Models/
│   └── SystemAlert.php                                        # new
├── Providers/
│   └── CoreServiceProvider.php                                # extended (register new commands + Livewire component + HealthCheckServiceProvider)
├── Public/
│   ├── Actions/
│   │   └── AcknowledgeSystemAlert.php                         # new
│   └── Services/
│       └── SystemAlertQuery.php                               # new
├── Resources/
│   └── views/
│       └── livewire/
│           └── system-alerts-banner.blade.php                 # new
└── tests/
    ├── Feature/
    │   ├── Backup/
    │   │   ├── BackupDatabaseCommandTest.php                  # new
    │   │   └── RestoreDatabaseCommandTest.php                 # new
    │   └── Core/
    │       ├── DoctorProbesTest.php                           # new
    │       ├── SystemAlertsBannerTest.php                     # new
    │       ├── FailedJobsCommandTest.php                      # new
    │       └── AppBootHealthCheckTest.php                     # new
    └── Unit/
        ├── BackupRetentionPolicyTest.php                      # new
        ├── DurationParserTest.php                             # new (if extracted)
        └── ProbesTest.php                                     # new (per-probe unit slice)

routes/
└── console.php                                                # extended (one new Schedule::command entry)

resources/views/layouts/
└── app.blade.php                                              # extended (one new <livewire:core.system-alerts-banner> slot)

README.md                                                     # rewrite ## Backups + ## Operator recovery in place

tests/Contracts/
└── BoundaryArchTest.php                                       # extended (2 new arch invariants)
```

### Pattern 1: VACUUM INTO from Laravel via DatabaseManager

**What:** Run `VACUUM INTO '<path>'` against the live DB using a constructor-injected `DatabaseManager`.
**When to use:** Inside `BackupDatabaseCommand::handle()` and `RestoreDatabaseCommand` (for the pre-restore snapshot).
**Why:** The SQLite docs [CITED: https://sqlite.org/lang_vacuum.html] confirm:
- `VACUUM INTO` does NOT modify the source DB.
- It IS NOT a write operation on the source (regular `VACUUM` is, but `VACUUM INTO` is not — important distinction).
- The generated output database is a consistent snapshot.
- If `PRAGMA synchronous` is `NORMAL` or `FULL` on the source, SQLite invokes `fsync()` on the output file — so the destination is durable without a separate checkpoint call. (The substrate runs `synchronous=NORMAL` per `SqliteOptimizationsProvider`.)
- The output is a single `.sqlite` file — there are NO `-wal` / `-shm` sidecars produced by `VACUUM INTO`.

**Concurrency caveat [CITED: https://sqlite.org/lang_vacuum.html]:** "A VACUUM will fail if there is an open transaction on the database connection that is attempting to run the VACUUM." This applies to regular VACUUM; for `VACUUM INTO`, the source can be read while other connections write — but the connection running VACUUM INTO must not itself be inside an open transaction. Practical implication: the backup command should use a dedicated `DatabaseManager::connection()` invocation and avoid wrapping the VACUUM INTO call inside any `connection()->transaction(…)`.

**Example (DI-only, no facade):**

```php
// Source: established pattern from Modules/Core/Internal/Console/InstallCommand.php
// (constructor DI of DatabaseManager + Filesystem, handle(): int)
namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;

final class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup {--force}';
    protected $description = 'Produce a consistent SQLite backup via VACUUM INTO.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Filesystem $files,
        // ... other deps: Clock, ConfigRepository for db path, etc.
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $destination = base_path('storage/app/backups/diederik-'.$timestamp.'.sqlite');
        // VACUUM INTO takes a single quoted-string argument — bind via raw statement.
        // The destination path must NOT exist yet; SQLite refuses to overwrite.
        $this->db->connection()->statement(
            "VACUUM INTO '".addslashes($destination)."'"
        );
        // chmod 600 + integrity_check follow.
        return self::SUCCESS;
    }
}
```

### Pattern 2: Fresh `PDO` connection for integrity_check and data_version reads

**What:** Open a brand-new `PDO('sqlite:<path>')` instance — bypassing the Laravel-managed connection pool — to read `PRAGMA integrity_check` on a backup file, and `PRAGMA data_version` on the live DB.
**Why:** `PRAGMA data_version` has connection-local caching semantics [CITED: https://www.sqlite.org/pragma.html#pragma_data_version + https://sqlite.org/forum/info/e76cac71ac2db298]: each connection caches the last value it read, and the bump only becomes visible on a subsequent read. To use it as a smart-skip signal across queue worker + scheduler + web request boundaries, each backup invocation must open a fresh connection. The Laravel-managed connection may be cached / pooled by the framework; a raw `PDO` is the only honest way to read a current value.
**When to use:** Always for integrity_check on a freshly-written backup file (it's a different file path than the live DB anyway, so a fresh `PDO` is structurally required). For data_version, prefer fresh `PDO` over the framework's connection to avoid stale-cache risk.

**Example:**

```php
// Open the just-written backup file via a fresh, isolated PDO instance.
$pdo = new \PDO('sqlite:'.$destination, options: [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);
$result = $pdo->query('PRAGMA integrity_check')->fetchColumn();
// `integrity_check` returns the literal string 'ok' on success; on failure it
// returns multiple rows with diagnostic messages.
if ($result !== 'ok') {
    // rename to .suspect, write system_alerts row, exit non-zero
}
```

### Pattern 3: Sidecar JSON metadata via Filesystem (chmod 600 atomic write)

**What:** Each successful backup writes a `<backup>.meta.json` sidecar containing `{ data_version, started_at, completed_at, integrity: 'ok' }`. The sidecar is also chmod 600.
**Why:** The smart-skip path reads the most-recent sidecar to learn the data_version captured at that backup. The sidecar is also useful for the BackupFreshnessProbe (recency check by `completed_at`).
**When to use:** Mirrors the established `OAuthSecretsRepository::writeAtomic` pattern [VERIFIED: Modules/EmailScan/Public/Services/OAuthSecretsRepository.php lines 262-353]:
1. `umask(0077)` BEFORE opening the file → temp file is born with mode 0600 (not 0644).
2. Write to a `.tmp` file in the same directory.
3. `fsync` if available (`file_put_contents` doesn't fsync; for the sidecar this is acceptable — the load-bearing fsync is on the .sqlite file via SQLite).
4. `chmod 0600` explicitly (belt-and-braces against umask churn).
5. `rename(tmp, final)` — atomic on the same filesystem.
6. Restore prior umask.

For the backup file itself (which `VACUUM INTO` writes), `VACUUM INTO` creates the file with the process's default permissions (typically 0644). After the SQL completes successfully, the command MUST call `chmod 0600` explicitly. There is no umask trick that works for VACUUM INTO because the file is created by SQLite, not by PHP's open().

### Pattern 4: Persistent banner Livewire SFC with method-parameter DI

**What:** A new `SystemAlertsBanner` Livewire 4 Component that reads `SystemAlertQuery::active($currentUser)` on every render and renders one row per active alert (severity-colored, sticky, dismiss button).
**Why:** Livewire 4 banner pattern is well-established in this codebase — Phase 9 `DashboardDriftBadge` is the canonical reference [VERIFIED: Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php].
**When to use:** Once, in `app.blade.php` between top nav and `@yield('content')`.

**Critical convention:** **Method-parameter DI on `render()`** — constructor DI on Livewire `Component` subclasses is banned by `phpstan-strict-rules` [VERIFIED: established convention in `DashboardDriftBadge.php` line 24, `Dashboard.php`, `TopNav.php`].

**Example (verbatim mirror of DashboardDriftBadge):**

```php
namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\SystemAlertQuery;

final class SystemAlertsBanner extends Component
{
    public function render(
        CurrentUser $currentUser,
        SystemAlertQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $alerts = $query->active($user);

        return $views->make('core::livewire.system-alerts-banner', [
            'alerts' => $alerts,
        ]);
    }

    public function acknowledge(int $alertId, AcknowledgeSystemAlert $action, CurrentUser $currentUser): void
    {
        $action($alertId, $currentUser->user());
        // No explicit re-render needed — Livewire re-runs render() after the action.
    }
}
```

**Livewire SFC slot in `app.blade.php`:** insert ONE line between `@livewire('core.top-nav')` and `@yield('content')`:

```blade
@auth
    @livewire('core.top-nav')
    @livewire('core.system-alerts-banner')    {{-- new --}}
    @livewire('categorization.rule-form-modal')
    ...
@endauth
```

**Refresh-after-action:** Livewire 4 re-runs `render()` automatically after `acknowledge()` returns. No `wire:poll` is needed — the banner updates synchronously within the same request lifecycle. For multi-tab consistency, an optional `wire:poll.30s` interval would catch alerts written by background jobs (the BackupFreshnessProbe / app-boot check / db:backup) but is NOT required for v1.

### Pattern 5: Schedule entry in `routes/console.php` (NOT in module ServiceProvider)

**What:** Append a single `Schedule::command('db:backup')->dailyAt('03:00')->withoutOverlapping()` entry to `routes/console.php`.
**Why:** Established convention [VERIFIED: routes/console.php lines 40-191]. Every scheduled job in this codebase lives in `routes/console.php`, NOT inside module ServiceProviders. The project's `BoundaryArchTest` "no Laravel facade usage in module code" rule is intentionally scoped via `->not->toBeUsedIn('Modules')` so `routes/console.php`'s `Schedule::*` usage is legal.

**Verified scheduler conventions in the codebase:**
- Method order MUST be `.name(...)` BEFORE `.dailyAt(...)->withoutOverlapping(...)` — Laravel's `CallbackEvent::withoutOverlapping` throws `LogicException` when description is not set yet (every existing entry comments this gotcha).
- `withoutOverlapping(int $minutes)` parameter is the lock TTL in minutes; default is 24 hours (1440 minutes). CONTEXT does not specify a TTL — recommend `withoutOverlapping(60)` (a backup that's still running an hour later is anomalous; 24h is too long).
- The lock backing store is the application's cache. The substrate's `CACHE_STORE=database` [VERIFIED: .env.example] means the lock is a row in the `cache` table. Laravel 13 docs [CITED: https://laravel.com/docs/12.x/scheduling#preventing-task-overlaps] confirm withoutOverlapping uses the cache layer; database driver is supported.

**Example:**

```php
// routes/console.php — append after the forecasting.daily-sweep block
Schedule::command('db:backup')
    ->name('db.backup-daily')
    ->dailyAt('03:00')
    ->withoutOverlapping(60);
```

### Pattern 6: Maintenance-mode lifecycle inside db:restore

**What:** `db:restore` requires `php artisan down` (maintenance mode) BEFORE the swap. The `--force-maintenance` flag lets the command chain `down` and `up` itself.
**Why:** Maintenance mode is the right primitive because:
- It returns HTTP 503 for incoming requests [CITED: Laravel docs — maintenance mode writes `storage/framework/down`, Laravel 13's `PreventRequestsDuringMaintenance` middleware short-circuits the kernel].
- **Horizon workers pause processing while the app is in maintenance mode by default** [VERIFIED: WebSearch + Laravel Horizon docs] — "queued jobs will not be processed by Horizon unless the supervisor's force option is true." The project's HorizonServiceProvider has not set `force: true` on any supervisor [VERIFIED: project does not use that override], so Horizon will pause cleanly during the swap.
- The scheduler will continue running but its scheduled commands honor maintenance mode (most commands skip by default; `php artisan down` does NOT prevent the scheduler from firing, but the `db:backup` scheduled command would itself be skippable if needed).

**Crash recovery between `down` and `up`:** Wrap the restore body in `try/finally` so that if `--force-maintenance` was passed and the restore throws, the `finally` block calls `up` to leave the app reachable. The pre-restore snapshot is on disk regardless, so recovery is "operator notices banner / sees command exit non-zero, runs `php artisan db:restore <pre-restore-snapshot> --confirm`."

**`DB::purge()` semantics:** `DatabaseManager::purge(?string $name = null)` disconnects every active connection for the given name (or default if null) AND removes the connection from the connection cache. The NEXT call to `connection()` re-resolves a fresh connection. For SQLite, this is critical because the OS-level file handle on the old `database.sqlite` is held open by PDO until purge releases it. After `purge()`, the file can be safely overwritten.

**SqliteOptimizationsProvider already handles PRAGMA re-application:** After `DB::purge()` and the file copy, the very next `DatabaseManager::connection()` call fires the `ConnectionEstablished` event, which the existing `SqliteOptimizationsProvider` listens for and re-applies `journal_mode=WAL`, `synchronous=NORMAL`, `busy_timeout=5000`, `foreign_keys=ON`, `temp_store=MEMORY` [VERIFIED: Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php]. **The restore command does NOT need to manually re-apply PRAGMAs — the existing event listener does it.** The command MUST close all connections via `purge()` so the listener fires when the NEXT request opens a connection on the new file.

### Pattern 7: Probe contract + ProbeResult value object

**What:** A small interface (`Probe`) + immutable value object (`ProbeResult`) under `Modules/Core/Internal/Console/Probes/`. Each probe implements `run(): ProbeResult` returning severity (ok / warning / critical) + a human message + optional metadata.
**Why:** Standard tactic for fan-out command-internals: each probe is independently testable, the command iterates an injected `array<Probe>` and aggregates results.

**Example contract:**

```php
namespace Modules\Core\Internal\Console\Probes;

interface Probe
{
    /**
     * Human-readable label printed in the doctor command's summary table.
     */
    public function label(): string;

    /**
     * Run the probe and return its result. Probes MUST NOT throw — wrap
     * any IO / SQL call in try/catch and surface failure as a critical
     * ProbeResult with the exception message.
     */
    public function run(): ProbeResult;
}

final readonly class ProbeResult
{
    /**
     * @param 'ok'|'warning'|'critical' $severity
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $severity,
        public string $message,
        public array $metadata = [],
    ) {}
}
```

**Registration:** Container-tag the probes in `CoreServiceProvider::register()` so the `DoctorCommand` constructor can receive `iterable<Probe>` via `$this->app->tagged('core.doctor.probe')` — but the simpler approach (preferred for a small fixed set of probes) is constructor parameter list of named bindings:

```php
public function __construct(
    private readonly WalModeProbe $walProbe,
    private readonly SynchronousModeProbe $synchronousProbe,
    private readonly BackupFreshnessProbe $backupFreshnessProbe,
    // ... existing version-check internals refactored into Probes too
) { parent::__construct(); }
```

### Pattern 8: `<duration>` parsing for `--older-than=`

**What:** Parse strings like `30d`, `7d`, `90d`, `12h`, `2w` into a `Carbon` `DateTime` representing `now - duration`.
**Recommendation:** Small custom regex parser + Carbon's `sub*` methods. Carbon does NOT natively parse `30d` as a Period — it parses ISO 8601 durations (`P30D`) via `CarbonInterval::create($iso)`, but `30d` is a friendlier UX.

**Idiomatic shape:**

```php
// In a small Internal/Console/Support/DurationParser.php value object
namespace Modules\Core\Internal\Console\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class DurationParser
{
    public function subFromNow(string $input, CarbonImmutable $now): CarbonImmutable
    {
        if (preg_match('/^(\d+)([dhwm])$/i', $input, $m) !== 1) {
            throw new InvalidArgumentException(
                "Duration must match /^\\d+[dhwm]\$/ (e.g. '30d', '7d', '12h'). Got: {$input}"
            );
        }
        $n = (int) $m[1];
        return match (strtolower($m[2])) {
            'd' => $now->subDays($n),
            'h' => $now->subHours($n),
            'w' => $now->subWeeks($n),
            'm' => $now->subMinutes($n),  // ambiguous w/ months — explicit choice: minutes
        };
    }
}
```

**Edge case:** `m` is ambiguous (minutes vs months). Recommend committing to **minutes** for `m` because the failed-jobs use case rarely wants month-granularity, but the planner could equally choose "no `m` at all — only `d/h/w`" to avoid the ambiguity. Decide via Pest dataset.

### Anti-Patterns to Avoid

- **Using `DB::statement(...)` facade directly inside commands** — banned by `noFacadeCallsFromCoreConsoleCommands` (the new arch invariant) and by the project-wide DI-only rule. Always inject `DatabaseManager` and call `$this->db->connection()->statement(...)`.
- **Wrapping `VACUUM INTO` inside a `connection()->transaction(...)` block** — VACUUM INTO cannot run inside an open transaction on the source connection [CITED: https://www.sqlite.org/lang_vacuum.html]. The backup command must NOT wrap the VACUUM INTO call inside any transaction.
- **Reading `PRAGMA data_version` from the framework's cached connection** — the value may be stale due to per-connection caching [CITED: https://www.sqlite.org/pragma.html#pragma_data_version]. Use a fresh `PDO` instance.
- **Copying `.sqlite-wal` / `.sqlite-shm` sidecars to the backup directory** — DO NOT. `VACUUM INTO` produces a single self-contained `.sqlite` file with all committed data folded in. Copying WAL sidecars is exactly what the README's `cp database.sqlite` forbiddance is warning against.
- **Constructor DI on Livewire 4 Components** — banned by phpstan-strict-rules. Always use method-parameter DI on `render()`.
- **Scheduling via `Schedule::call()` closure inside a module ServiceProvider** — schedule entries belong in `routes/console.php`. Module ServiceProviders register commands and Livewire components, not scheduler bindings.
- **Using `php artisan up` from inside the command without checking what state it was in** — only call `up` if the command itself put it `down` (via `--force-maintenance`). If the operator pre-`down`-ed manually, leave it `down` after the restore so they can verify before bringing the app back.
- **Hand-rolled file-permission code paths that bypass umask** — use the established `OAuthSecretsRepository::writeAtomic` pattern (umask 0077 + atomic rename + explicit chmod 0600).
- **Foreign-key checks during the swap inside `db:restore`** — if the swapped-in DB schema differs from the live DB schema, `PRAGMA foreign_keys = ON` may surface dangling references. The post-swap `PRAGMA integrity_check` catches structural corruption; for schema-incompatibility the right answer is "user holds it wrong" — document that `db:restore` is for same-schema-version restores only (operator runs `php artisan migrate` afterward if migrating across versions).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Generating a consistent SQLite snapshot | `cp database.sqlite` with file locks | `VACUUM INTO '<path>'` SQL | WAL sidecars contain uncommitted pages; `cp` is unsafe. `VACUUM INTO` is atomic + writes a self-contained file. |
| Verifying backup integrity | Custom header parsing | `PRAGMA integrity_check` via fresh PDO | SQLite's built-in check is the canonical answer; returns `ok` or diagnostic rows. |
| Smart-skip "did the DB change" signal | Compare file mtime / aggregate row counts | `PRAGMA data_version` (via fresh PDO) | mtime lags commits in WAL until checkpoint; row counts miss no-op-update cases; data_version is the SQLite-canonical change counter. |
| Mutex for daily backup non-overlap | Custom lock file in `storage/` | `Schedule::command(...)->withoutOverlapping(60)` | Uses Laravel's cache layer (already configured as database driver); standard idiom. |
| Pausing background workers during restore | Custom Horizon-pause artisan call | `php artisan down` (maintenance mode) | Horizon already pauses on maintenance mode by default; one primitive does both. |
| Re-applying SQLite PRAGMAs after a connection reset | Manually call `PRAGMA journal_mode=WAL` post-restore | Rely on existing `SqliteOptimizationsProvider` `ConnectionEstablished` listener | The provider already runs on every new connection; calling `DB::purge()` is enough to trigger re-application on the next read. |
| `failed_jobs` retention policy | Custom SQL DELETE chain | `failed_jobs` table is Laravel-managed; just `DELETE WHERE failed_at < <cutoff>` via DatabaseManager | The table is plain Laravel; no library wraps it. The CLI is a thin wrapper. |
| Persistent in-app alert surface | JSON file under storage | New `system_alerts` Eloquent table + Public service + Public action | Mirrors Phase 9 DriftAlerts; composes with Livewire's Eloquent rendering; one persistence story. |
| Banner with severity-colored chrome | Hand-rolled Tailwind classes | Mirror Phase 9 drift-page severity tiers / Phase 5 chain-link confidence palette | Visual coherence + chrome already proven in production. |
| Duration parsing for `--older-than=` | Carbon's natural-language parser | Small regex `/^(\d+)[dhwm]$/i` → `Carbon::sub*` | Natural-language is overkill; explicit regex makes failures loud. |

**Key insight:** Phase 11's most-load-bearing dependencies are SQLite primitives (`VACUUM INTO`, `PRAGMA integrity_check`, `PRAGMA data_version`) and Laravel primitives (Scheduler `withoutOverlapping`, Maintenance mode, `DatabaseManager::purge`, `ConnectionEstablished` event). Every one of these is already on the substrate and battle-tested. The phase is mostly composition, not invention.

## Runtime State Inventory

> Phase 11 is **not** a rename / refactor / migration phase. It is purely additive (new commands, new table, new banner, new probes). No grep audit of "what files still mention the old string" is required because there is no old string.
>
> However, two state-adjacent concerns are worth calling out for the planner:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | The `system_alerts` table is brand-new — no pre-existing rows. Existing tables (drift_alerts, recurring_series, etc.) are NOT touched. | None. |
| Live service config | None — Horizon configuration is untouched. The scheduler's existing entries are untouched; one new entry is APPENDED to `routes/console.php`. | None. |
| OS-registered state | The existing `~/Library/LaunchAgents/com.diederik.scheduler.plist` already runs `schedule:work` and will pick up the new `Schedule::command('db:backup')` entry automatically on next scheduler tick — NO new plist registration required. | None — verify by `launchctl list \| grep diederik` after deploying the new schedule entry; the plist itself doesn't change. |
| Secrets/env vars | None — no new env keys; cache driver is already `database`; queue driver is already `redis`. | None. |
| Build artifacts | None. | None. |

**Nothing found in any category that requires migration work.** The phase adds new state surfaces (`system_alerts` table, `storage/app/backups/` directory) but does not migrate or rename existing state.

## Common Pitfalls

### Pitfall 1: `PRAGMA data_version` connection-local caching

**What goes wrong:** The smart-skip path reads `PRAGMA data_version` on the live DB and compares against the most-recent backup's stored data_version. If the read happens on a long-lived Laravel connection that has cached the value from a prior read, it may report a stale value and either skip a backup that should run, or run a backup that should skip.

**Why it happens:** `PRAGMA data_version` is implemented as a per-connection cached counter that re-syncs from the database header on the next read after an external commit [CITED: https://sqlite.org/forum/info/e76cac71ac2db298].

**How to avoid:** Always read `PRAGMA data_version` via a fresh `PDO('sqlite:<path>')` instance for the comparison. Treat it as "open, read, close." Do NOT use the Laravel-managed connection.

**Warning signs:** Two consecutive `db:backup --force` runs report identical data_version readings but the backup file content differs (or: scheduled backups run when no commits happened / skip when commits happened). Add a unit test that opens two fresh PDOs in sequence around a known write to verify the read is fresh.

### Pitfall 2: Forgetting to close all connections before file copy in db:restore

**What goes wrong:** `RestoreDatabaseCommand` copies the source file over `database.sqlite` while a Laravel connection still has the old file mmap'd. On Linux/macOS this is silently tolerated (POSIX allows opens past unlink), but the live connection continues seeing the OLD file content while the swapped-in file is on disk — leading to confusing post-swap behavior until next request.

**Why it happens:** Laravel's `DatabaseManager` caches connection objects across calls. Without explicit purge, the same PDO handle is reused.

**How to avoid:** Call `$this->db->purge()` (no args = default connection) BEFORE the `copy()` call. Verify with a Pest test that opens a fresh connection after the swap and asserts the new content is visible.

**Warning signs:** The post-swap `PRAGMA integrity_check` succeeds but the dashboard shows the OLD data until next request. If you see this in testing, you forgot the purge.

### Pitfall 3: VACUUM INTO inside an open transaction

**What goes wrong:** Wrapping `$db->connection()->transaction(function () use ($db) { $db->connection()->statement("VACUUM INTO …"); })` throws "cannot VACUUM from within a transaction."

**Why it happens:** SQLite docs explicitly forbid this [CITED: https://www.sqlite.org/lang_vacuum.html]: "A VACUUM will fail if there is an open transaction on the database connection that is attempting to run the VACUUM."

**How to avoid:** Call `$this->db->connection()->statement("VACUUM INTO '$path'")` OUTSIDE any transaction block. The retention pruner step (after the VACUUM) can be inside its own transaction if multi-row delete atomicity matters, but the VACUUM itself stands alone.

**Warning signs:** "cannot VACUUM from within a transaction" error in the command output.

### Pitfall 4: Backup file is mode 0644 because VACUUM INTO bypasses PHP's umask

**What goes wrong:** The freshly-written backup file under `storage/app/backups/` is world-readable (or group-readable, depending on umask) because SQLite created it through `open(2)` with default permissions, not through PHP's `fopen()`.

**Why it happens:** `VACUUM INTO` is an internal SQLite operation; PHP's umask narrowing trick (used by `OAuthSecretsRepository`) does not apply.

**How to avoid:** After the VACUUM INTO statement returns successfully, IMMEDIATELY call `$this->files->chmod($destination, 0600)` (using Laravel's `Filesystem`) — `Filesystem::chmod()` exists in Laravel 13 and wraps PHP's `chmod()`. **Do this BEFORE writing the sidecar metadata.** If the chmod fails, treat it as a critical error: the backup file is on disk but world-readable — write a system_alerts row and remove the file.

**Warning signs:** `ls -la storage/app/backups/` shows files at `-rw-r--r--` instead of `-rw-------`.

### Pitfall 5: `withoutOverlapping()` lock survives past a crash

**What goes wrong:** Scheduler runs `db:backup`; the process is SIGKILL'd mid-VACUUM. The withoutOverlapping cache key sits in the `cache` table at a 60-minute TTL, blocking every subsequent scheduled run for an hour.

**Why it happens:** Laravel's withoutOverlapping writes the lock at task start and removes it at task end via a `register_shutdown_function` finalizer. On SIGKILL the finalizer never runs.

**How to avoid:** Choose a TTL appropriate for the task. The default 24 hours is way too long for a 5-second backup. Recommend `withoutOverlapping(60)` (60 minutes) — long enough that two runs within an hour are anomalous, short enough that a crash recovers within an hour. Document the manual recovery recipe in README ("clear the lock by deleting the cache row whose key matches `framework/schedule-…`"). The existing Operator recovery section's Redis-stuck-lock recipe is the template.

**Warning signs:** Multiple skipped scheduler runs in a row with "skipped: another instance is running" messages.

### Pitfall 6: db:restore swaps DB file but PRAGMAs not re-applied because no fresh request triggers ConnectionEstablished

**What goes wrong:** `db:restore` purges connections, copies the file, then runs `PRAGMA integrity_check` IMMEDIATELY via `$this->db->connection()->statement(...)`. The next `connection()` call after `purge()` DOES fire `ConnectionEstablished`, so the SqliteOptimizationsProvider listener fires and applies WAL+synchronous BEFORE the integrity_check. ✓ This is the happy path.

**Edge case to watch:** If the command opens a raw PDO for the post-swap integrity_check (mirroring the pre-swap check pattern), that raw PDO bypasses the Laravel event listener. The integrity_check would succeed (it doesn't care about PRAGMAs) but the next live request would re-apply PRAGMAs. **Recommendation:** for the post-swap integrity_check, use the framework's `DatabaseManager::connection()->statement(...)` deliberately so the ConnectionEstablished listener fires and the file is in correct PRAGMA state immediately. Document this in the command's docblock as a deliberate choice.

### Pitfall 7: `system_alerts.user_id` NULL for system-wide alerts vs. SQLite UNIQUE-distinct-NULL

**What goes wrong:** A future module adds a UNIQUE constraint involving `user_id` on `system_alerts` and discovers SQLite treats NULL as distinct in UNIQUE — duplicate system-wide alerts slip through.

**Why it happens:** SQLite UNIQUE allows multiple NULLs by spec [CITED: SQLite UNIQUE constraint docs].

**How to avoid:** Phase 11 has no UNIQUE constraints on `system_alerts` — duplicate alerts of the same kind ARE allowed by design (the user can have two backup_corrupt alerts from two different runs). If a future module adds a UNIQUE that involves `user_id`, document the NULL-distinct caveat at that time. For Phase 11, no action.

### Pitfall 8: Banner re-renders cause N+1 queries on every page

**What goes wrong:** `SystemAlertQuery::active($user)` returns a Collection of SystemAlert rows. The Blade template loops and reads `$alert->user` to display "from <user>" — triggering an N+1 against the users table.

**Why it happens:** Eloquent lazy-loads relations on access.

**How to avoid:** For Phase 11, there's only one user, so N+1 across alerts is bounded at 1. But for forward-compat with FND-03 multi-user: `SystemAlertQuery::active()` should eager-load the user relation if the banner displays user-scoped info — or, simpler, just don't display "from <user>" in the banner. Recommend the latter for v1.

## Code Examples

### Reading PRAGMA data_version via a fresh PDO

```php
// Source: established pattern, no Laravel framework involvement
$pdo = new \PDO('sqlite:'.$liveDatabasePath, options: [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);
$stmt = $pdo->query('PRAGMA data_version');
$dataVersion = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
// $pdo goes out of scope at end of method; PDO destructor closes the connection.
```

### Running VACUUM INTO via DatabaseManager (no facade)

```php
// Source: combining InstallCommand.php DI shape + SQLite docs
public function __construct(
    private readonly DatabaseManager $db,
    private readonly Filesystem $files,
) {
    parent::__construct();
}

public function handle(): int
{
    $destination = base_path('storage/app/backups/'.$filename.'.sqlite');
    // Note: VACUUM INTO accepts a single quoted string. SQLite does NOT
    // support placeholder binding for this DDL statement.
    $escaped = str_replace("'", "''", $destination);
    $this->db->connection()->statement("VACUUM INTO '{$escaped}'");
    $this->files->chmod($destination, 0o600);
    // ... write sidecar, integrity_check, retention prune
    return self::SUCCESS;
}
```

### Running PRAGMA integrity_check via fresh PDO

```php
// Source: SQLite docs https://www.sqlite.org/pragma.html#pragma_integrity_check
$pdo = new \PDO('sqlite:'.$backupPath, options: [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);
// integrity_check returns 'ok' on success; on failure returns N rows
// each describing one problem.
$rows = $pdo->query('PRAGMA integrity_check')->fetchAll(\PDO::FETCH_COLUMN);
if ($rows === ['ok']) {
    // healthy
} else {
    // corrupt — rename to .suspect, write system_alerts, exit non-zero
}
```

### Schedule entry append to routes/console.php

```php
// Source: established convention in routes/console.php
// (method order .name() BEFORE .dailyAt()->withoutOverlapping())
Schedule::command('db:backup')
    ->name('db.backup-daily')
    ->dailyAt('03:00')
    ->withoutOverlapping(60);
```

### Migration shape for `system_alerts`

```php
// Source: mirrors Modules/DriftAlerts/Database/Migrations/2026_05_19_010001_create_drift_alerts_table.php
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('system_alerts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('kind', 64);
            $table->string('severity', 16);  // 'info'|'warning'|'critical'
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('acknowledged_at')->nullable();

            $table->index(['user_id', 'acknowledged_at']);
            $table->index(['kind', 'acknowledged_at']);
        });

        // Optional: enforce severity enum at DB layer via BEFORE INSERT/UPDATE
        // trigger pair (mirrors drift_alerts pattern). If the planner judges
        // the schema-level enforcement is overkill for a 3-value column,
        // a CHECK constraint via `$table->string('severity', 16);` and a
        // PHP-side validator in AcknowledgeSystemAlert is equally honest.
        $connection = $this->db()->connection($this->getConnection());
        $allowedSeverities = "'info','warning','critical'";
        $connection->statement(sprintf(
            "CREATE TRIGGER system_alerts_severity_check_insert BEFORE INSERT ON system_alerts FOR EACH ROW
             WHEN NEW.severity NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid system_alerts.severity value'); END",
            $allowedSeverities,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER system_alerts_severity_check_update BEFORE UPDATE OF severity ON system_alerts FOR EACH ROW
             WHEN NEW.severity NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid system_alerts.severity value'); END",
            $allowedSeverities,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS system_alerts_severity_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS system_alerts_severity_check_update');
        $this->schema()->dropIfExists('system_alerts');
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            $this->resolvedDb = Container::getInstance()->make(DatabaseManager::class);
        }
        return $this->resolvedDb;
    }
};
```

### BoundaryArchTest new invariants

```php
// Append to tests/Contracts/BoundaryArchTest.php

it('does not allow Laravel facades inside Modules/Core/Internal/Console/ commands (noFacadeCallsFromCoreConsoleCommands)', function (): void {
    // Phase 11 invariant: every new console command in Core/Internal/Console
    // takes its dependencies via constructor DI. No DB::, Schedule::,
    // Storage::, Artisan::, Cache:: facade calls in the command bodies.
    // The arch rule above ("no Laravel facade usage in module code") covers
    // Illuminate\Support\Facades broadly; this targeted invariant raises the
    // signal when a contributor accidentally pulls a facade into a console
    // command class.
    $hits = [];
    $consoleDir = base_path('Modules/Core/Internal/Console');
    if (! is_dir($consoleDir)) {
        expect(true)->toBeTrue();
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($consoleDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) continue;
        $contents = (string) file_get_contents($file->getPathname());
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match('/\\\\?Illuminate\\\\Support\\\\Facades\\\\/', $stripped) === 1) {
            $hits[] = $file->getPathname();
        }
    }
    expect($hits)->toBe([], "Modules/Core/Internal/Console/ commands may not use Illuminate\\Support\\Facades. Offenders:\n  ".implode("\n  ", $hits));
});

it('does not allow system_alerts to be JOINed onto the transactions table (systemAlertsTableNotJoinedToTransactions)', function (): void {
    // Phase 11 invariant: system_alerts is an operational surface only —
    // never joined onto transactions or any other domain read. Mirrors the
    // Phase 10 noScenarioMutationsJoinedToTransactionQueries shape.
    $hits = [];
    $modulesDir = base_path('Modules');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) continue;
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) continue;
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        $hasJoin = preg_match("/->(join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]system_alerts['\"]/", $stripped) === 1;
        $hasTransactions = preg_match("/['\"]transactions['\"]/", $stripped) === 1;
        if ($hasJoin && $hasTransactions) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe([], "system_alerts must never be JOINed onto the transactions table. Offenders:\n  ".implode("\n  ", $hits));
});
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| SQLite online-backup API (`SQLite3::backup`) | `VACUUM INTO '<path>'` SQL | SQLite 3.27.0 (2019) | Single-statement, atomic, simpler from PHP. ROADMAP SC #1 allows either; CONTEXT locks the simpler one. |
| File-mtime smart-skip | `PRAGMA data_version` smart-skip | Established SQLite primitive | mtime lags WAL-mode commits until checkpoint. `data_version` is the canonical signal. |
| Hand-rolled Horizon-pause artisan call | `php artisan down` (Horizon respects maintenance mode by default) | Laravel + Horizon current versions | One primitive does both. |
| Polling for banner refresh | Livewire 4 implicit re-render after action | Livewire 4 conventions | `acknowledge()` → render() chain is automatic; no `wire:poll` for v1. |
| Constructor DI on Livewire Components | Method-parameter DI on render() | phpstan-strict-rules current convention | Established codebase-wide; mirrors DashboardDriftBadge / Dashboard / TopNav. |

**Deprecated/outdated:**
- `cp database.sqlite` of a live WAL DB — README explicitly forbids; `db:backup` is the supported path.
- `kingsquare/php-mt940` for primary ASN ingestion (irrelevant to Phase 11 directly, but reinforces the project's stance against unmaintained libraries — no Phase 11 dependency falls into that bucket).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `Filesystem::chmod()` exists on Laravel 13's `Illuminate\Filesystem\Filesystem` class | Pattern 4, Pitfall 4 | If absent in Laravel 13, use `chmod()` PHP built-in (still legal — it's a PHP function, not a facade). Risk: low; the method has existed for many Laravel versions. [ASSUMED — verify with Laravel 13 source during planning] |
| A2 | `php artisan down` causes Horizon to pause processing in this codebase's HorizonServiceProvider | Pattern 6 | If a future Horizon supervisor sets `force: true`, jobs would still process during restore — leading to dirty writes against the swapped-in DB. Risk: medium; recommend the planner add a Pest test that verifies the project's HorizonServiceProvider does not set `force: true`. [VERIFIED: WebSearch + Horizon docs — but project-specific Horizon config not re-read in this research] |
| A3 | Carbon ships with Laravel 13 (no separate `nesbot/carbon` declaration in composer.json) | Pattern 8 | Composer requires Carbon transitively via Laravel framework; if missing, add `nesbot/carbon` to require. Risk: very low. [VERIFIED: Laravel framework dependency tree] |
| A4 | `Schedule::command('db:backup')->dailyAt('03:00')` syntax works in Laravel 13 | Pattern 5 | If Laravel 13 changed the scheduler API, fall back to `->cron('0 3 * * *')`. Risk: very low. [CITED: Laravel 13 scheduling docs — confirmed identical to 12.x] |
| A5 | `withoutOverlapping(60)` accepts minutes as the parameter | Pattern 5, Pitfall 5 | Verified in Laravel 12 docs; identical in 13. [CITED: https://laravel.com/docs/12.x/scheduling#preventing-task-overlaps] |
| A6 | `DatabaseManager::purge()` exists and behaves as described | Pattern 6, Pitfall 2 | Long-standing Laravel API; very stable. Verify by reading vendor source if needed. [VERIFIED: Laravel framework — purge() has been on DatabaseManager since at least Laravel 5] |
| A7 | The integrity_check PRAGMA returns the literal string `ok` on success | Code Examples | [CITED: https://www.sqlite.org/pragma.html#pragma_integrity_check] — confirmed in SQLite documentation. |
| A8 | Existing `DoctorCommand` does not currently use the Probe contract | Pattern 7 | Read [VERIFIED: Modules/Core/Internal/Console/DoctorCommand.php] — confirmed: it uses inline `reportTool()` calls. Phase 11's "refactor existing into Probe contract" is a real refactor, not a no-op. |
| A9 | The dashboard chrome (Tailwind tokens) used by Phase 9 drift-alert / Phase 5 chain-link confidence tiers exists and the planner can mirror it | Pattern 4 | [VERIFIED: Modules/DriftAlerts/Resources/views/livewire/dashboard-drift-badge.blade.php] uses `border-slate-200 bg-white text-slate-900`. Severity gradient for system-alerts banner can mirror: critical = `border-rose-500 bg-rose-50 text-rose-900`; warning = `border-amber-300 bg-amber-50 text-amber-900`; info = `border-slate-200 bg-slate-50 text-slate-700`. [ASSUMED — planner confirms exact tokens during implementation] |
| A10 | Two consecutive fresh `PDO` instances reading `PRAGMA data_version` against the same unchanged DB return the same value | Pitfall 1 | [CITED: SQLITE_FCNTL_DATA_VERSION docs imply this; PRAGMA wraps it]. A Pest unit test should verify this empirically. [ASSUMED — planner should write a regression test] |

## Open Questions (RESOLVED)

1. **Where exactly does the app-boot health check live — `AppServiceProvider::boot()` or a new `Modules/Core/Internal/Providers/HealthCheckServiceProvider`?**
   - What we know: Both work mechanically. The existing module convention places module-scoped providers under `Modules/Core/Internal/Providers/` (e.g., `SqliteOptimizationsProvider`, `FortifyServiceProvider`).
   - RESOLVED: Recommendation: A dedicated `HealthCheckServiceProvider` registered from `CoreServiceProvider::register()` (same shape as `SqliteOptimizationsProvider` registration). Keeps boot-time PRAGMA verification colocated with the existing PRAGMA-application listener; one file owns "all things SQLite-pragma-related at boot."

2. **Should the failed-jobs CLI accept `m` for minutes or omit the `m` token entirely?**
   - What we know: `m` is genuinely ambiguous (minutes vs months).
   - RESOLVED: Recommendation: Omit `m` entirely. Accept `d` / `h` / `w` only. Document explicitly in the command's `--help` text. Minutes-granularity pruning is not a real-world use case for failed_jobs.

3. **Should the corrupt-backup `.suspect` file include a sidecar `<suspect>.meta.json` documenting WHY it was flagged?**
   - What we know: CONTEXT doesn't specify; the user's intent is "preserve so they can inspect."
   - RESOLVED: Recommendation: Yes — write a tiny sidecar with `{ flagged_at, integrity_check_output: <full PRAGMA output> }`. Aids forensics. Costs ~200 bytes per .suspect file.

4. **Should the integrity_check rows on a failed check be persisted into `system_alerts.metadata` JSON?**
   - What we know: The PRAGMA returns N rows of human-readable diagnostics on failure.
   - RESOLVED: Recommendation: Yes — `metadata: { integrity_check: <array of rows> }`. Makes the banner click-through diagnostic clear.

5. **Does the existing `Modules\Core\Models\User` need a `system_alerts` `HasMany` relation?**
   - What we know: SystemAlertQuery already scopes by `user_id`.
   - RESOLVED: Recommendation: Not strictly needed for Phase 11 (the query lives in the Public service). Defer until a consumer requires `$user->systemAlerts()`. YAGNI.

6. **Should `BackupRetentionPolicy::prune()` actually delete files, or return a list of "files that would be pruned" that the command then deletes?**
   - RESOLVED: Recommendation: Return a list. Keeps the value object pure (no filesystem IO inside the policy); the command does the actual `Filesystem::delete()` calls. This makes the policy 100% unit-testable with `Storage::fake()` not even needed.

7. **Does the existing test infrastructure (Pest 4 + RefreshDatabase) cleanly cover a test that writes a real SQLite file to disk (e.g., the BackupDatabaseCommandTest)?**
   - What we know: The `:memory:` testing connection (config/database.php `sqlite_testing`) does not produce a file path that `VACUUM INTO` can read. Backup tests need a file-on-disk fixture.
   - RESOLVED: Recommendation: Use `Storage::fake()` for the destination directory, but the SOURCE DB must be a real on-disk SQLite file. A Pest helper `withRealSqliteFixture()` that creates a temp DB, runs migrations against it, returns the path, and tears down in `afterEach` is the canonical shape. Verify Phase 5 / Phase 9 test fixtures for any similar precedent.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| SQLite CLI (`sqlite3`) | Doctor command (existing usage) | ✓ | (whatever Herd ships, ≥ 3.45) | — |
| PHP `ext-pdo_sqlite` | All commands (PDO `sqlite:` driver) | ✓ | Bundled with PHP 8.5 | — |
| Laravel 13 framework | All console + scheduling + maintenance-mode work | ✓ | ^13.0 | — |
| Livewire 4 | Banner SFC | ✓ | ^4.0 | — |
| Redis (via Horizon) | Job queue (existing) | ✓ | Docker container running on 6379 | — |
| `php artisan schedule:work` (via launchd) | Daily backup execution | ✓ | Existing `com.diederik.scheduler.plist` | — |
| `php artisan down` / `up` | db:restore maintenance-mode lifecycle | ✓ | Built into Laravel | — |
| `chmod` (POSIX) | Backup file permissions | ✓ | macOS/Linux native | — |

**Missing dependencies with no fallback:** None.
**Missing dependencies with fallback:** None.

All dependencies are already on the substrate. The phase introduces zero new system-level requirements.

## Validation Architecture

> `workflow.nyquist_validation: true` in .planning/config.json — section included.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.x (built on PHPUnit 11) — `pestphp/pest ^4.0` [VERIFIED: composer.json] |
| Config file | `phpunit.xml` (project root) — per-module test discovery via `tests/Pest.php` wire-up map |
| Quick run command | `vendor/bin/pest --filter='backup|restore|doctor|system-alerts|failed-jobs|app-boot'` (Phase 11 subset) |
| Full suite command | `vendor/bin/pest --parallel` (substrate-wide) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| FND-05 | `php artisan db:backup` produces a verified consistent SQLite backup | feature | `pest --filter=BackupDatabaseCommandTest` | ❌ Wave 0 |
| FND-05 | Backup integrity check fails → `system_alerts` row + .suspect file + non-zero exit | feature | `pest --filter=BackupDatabaseCommandTest::corrupt_source` | ❌ Wave 0 |
| FND-05 | Smart-skip via PRAGMA data_version | feature | `pest --filter=BackupDatabaseCommandTest::smart_skip` | ❌ Wave 0 |
| FND-05 | Retention prunes correctly across rolling 14-day window | unit | `pest --filter=BackupRetentionPolicyTest` | ❌ Wave 0 |
| FND-05 | `--force` bypasses smart-skip | feature | `pest --filter=BackupDatabaseCommandTest::force_bypass` | ❌ Wave 0 |
| FND-05 (SC#2) | `db:restore` rejects without --confirm | feature | `pest --filter=RestoreDatabaseCommandTest::refuses_without_confirm` | ❌ Wave 0 |
| FND-05 (SC#2) | `db:restore` rejects without maintenance mode | feature | `pest --filter=RestoreDatabaseCommandTest::refuses_without_maintenance` | ❌ Wave 0 |
| FND-05 (SC#2) | `db:restore` writes pre-restore snapshot before swap | feature | `pest --filter=RestoreDatabaseCommandTest::writes_pre_restore_snapshot` | ❌ Wave 0 |
| FND-05 (SC#2) | `db:restore` refuses corrupt source pre-swap | feature | `pest --filter=RestoreDatabaseCommandTest::refuses_corrupt_source` | ❌ Wave 0 |
| FND-05 (SC#2) | `db:restore` swaps file + brings app back up | feature | `pest --filter=RestoreDatabaseCommandTest::successful_swap` | ❌ Wave 0 |
| (operational) | Each doctor probe runs in isolation; WAL probe fails after `PRAGMA journal_mode=DELETE` | feature | `pest --filter=DoctorProbesTest` | ❌ Wave 0 |
| (operational) | `system_alerts` banner renders for active critical alerts; dismisses on action | feature | `pest --filter=SystemAlertsBannerTest` | ❌ Wave 0 |
| (operational) | `diederik:failed-jobs prune --older-than=30d --dry-run` does not delete | feature | `pest --filter=FailedJobsCommandTest::dry_run` | ❌ Wave 0 |
| (operational) | App-boot health check writes alert on misconfigured PRAGMAs; app continues | feature | `pest --filter=AppBootHealthCheckTest` | ❌ Wave 0 |
| (operational) | DurationParser handles `30d` / `7d` / `12h` / `2w`; rejects `30m` ambiguity | unit | `pest --filter=DurationParserTest` | ❌ Wave 0 |
| (arch) | `noFacadeCallsFromCoreConsoleCommands` — no facade imports in `Modules/Core/Internal/Console/` | arch | `pest --filter=BoundaryArchTest::noFacadeCallsFromCoreConsoleCommands` | ❌ Wave 0 (existing file extended) |
| (arch) | `systemAlertsTableNotJoinedToTransactions` — no JOINs from `system_alerts` to `transactions` | arch | `pest --filter=BoundaryArchTest::systemAlertsTableNotJoinedToTransactions` | ❌ Wave 0 (existing file extended) |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest --filter=<feature-or-file>` — the single test file touched by the task.
- **Per wave merge:** `vendor/bin/pest --filter='backup|restore|doctor|system-alerts|failed-jobs|app-boot|BoundaryArchTest'` (Phase 11 subset).
- **Phase gate:** `vendor/bin/pest --parallel` + `vendor/bin/phpstan analyse --memory-limit=1G` + `vendor/bin/pint --test` all green before `/gsd:verify-work`.

### Signal Sources

| Surface | Signal Source | Observation Channel |
|---------|---------------|---------------------|
| `db:backup` command | Pest feature test asserts exit code + file-on-disk + sidecar + system_alerts row | Pest assertions + filesystem inspection |
| `db:restore` command | Pest feature test asserts exit code + pre-restore-snapshot file + post-swap DB content + maintenance-mode state | Pest assertions + filesystem + `Artisan::call('down')` inspection |
| `diederik:doctor` | Pest feature test runs each probe, asserts ProbeResult severity + message | Pest assertions + console output capture via `$this->artisan(...)->expectsOutput(...)` |
| `system_alerts` table | Pest assertions on row insert/scope/state transitions | Eloquent `assertDatabaseHas()` / `Modules\Core\Models\SystemAlert::query()->where(...)->first()` |
| Banner Livewire SFC | Livewire test harness — `Livewire::test(SystemAlertsBanner::class)->assertSee(...)` + `->call('acknowledge', $id)->assertDispatched(...)` | Livewire test methods |
| Probe contract | Per-probe unit test instantiates probe in isolation, asserts `ProbeResult` | Pest assertions |
| App-boot check | Pest feature test forces `PRAGMA journal_mode = DELETE`, boots a fresh Laravel app (via `app()->bootstrapWith(...)` or Artisan::call lifecycle), asserts `system_alerts` row written | Pest assertions + `Modules\Core\Models\SystemAlert::query()` |
| Arch invariants | `pest-plugin-arch` rules in `tests/Contracts/BoundaryArchTest.php` | Pest arch assertions |

### Wave 0 Gaps

- [ ] `tests/Feature/Backup/BackupDatabaseCommandTest.php` — covers FND-05 SC#1 + SC#2 + smart-skip + corrupt + retention end-to-end
- [ ] `tests/Feature/Backup/RestoreDatabaseCommandTest.php` — covers FND-05 SC#2 restore-related cases
- [ ] `tests/Unit/Core/BackupRetentionPolicyTest.php` — pure unit, Pest dataset
- [ ] `tests/Unit/Core/DurationParserTest.php` — pure unit, Pest dataset (if duration parser is extracted)
- [ ] `tests/Feature/Core/DoctorProbesTest.php` — per-probe + composite doctor command
- [ ] `tests/Feature/Core/SystemAlertsBannerTest.php` — Livewire test harness
- [ ] `tests/Feature/Core/FailedJobsCommandTest.php` — covers `prune` subcommand + `--dry-run`
- [ ] `tests/Feature/Core/AppBootHealthCheckTest.php` — covers boot-time PRAGMA verification
- [ ] `tests/Helpers/RealSqliteFixture.php` (or trait) — Pest helper to create a temp on-disk SQLite file for tests that need a real file path (VACUUM INTO + integrity_check tests). Verify whether Phase 5 / Phase 9 already provide a similar helper before creating fresh.
- [ ] `tests/Contracts/BoundaryArchTest.php` — extended with two new invariants (`noFacadeCallsFromCoreConsoleCommands`, `systemAlertsTableNotJoinedToTransactions`)

## Security Domain

> `security_enforcement` not explicitly set in config.json — treated as enabled by default per agent contract.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|------------------|
| V2 Authentication | indirect | Existing Fortify-based auth gates the banner (banner is rendered inside `@auth` block). No new auth surface. |
| V3 Session Management | indirect | Maintenance mode (`php artisan down`) returns 503 to all sessions during `db:restore`. No new session surface. |
| V4 Access Control | yes | `AcknowledgeSystemAlert` enforces per-user scope (`where('user_id', $user->id)` + 404 on miss, mirroring Phase 9 `AcknowledgeDriftAlert`). `SystemAlertQuery::active(?User $user)` scopes per-user AND includes user_id NULL system-wide rows. |
| V5 Input Validation | yes | Command signatures validate via Laravel's signature DSL (`{--older-than=30d}`, `{path}`). `DurationParser` validates the format strictly (regex). `db:restore` validates the source path exists + is readable + integrity-checks before swap. |
| V6 Cryptography | no | No cryptographic operations introduced in Phase 11 (backups are NOT encrypted; OS-level chmod 600 is the only confidentiality control). Deferred. |
| V7 Error Handling | yes | All commands return integer exit codes (0/1/2 mirroring DoctorCommand's existing convention); structured logs via Laravel's logger (no facade — inject `LoggerInterface` or use `Log` carve-out per project convention). |
| V8 Data Protection | yes | Backup files chmod 600 (mirrors `storage/app/secrets/imap.json` precedent). `.suspect` files also chmod 600 to avoid leaking corrupted-but-readable data. |
| V12 File and Resources | yes | All file IO via DI'd `Filesystem` (no `file_put_contents` / `fopen` directly). Paths validated (no `../` traversal — Laravel's path helpers normalize). |
| V13 API and Web Services | n/a | No public API surface introduced. |

### Known Threat Patterns for {Laravel 13 + SQLite + Local-only stack}

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Corrupt backup silently overwrites good backup → data loss | Tampering | Post-write integrity_check + .suspect quarantine + system_alerts critical alert |
| Backup file world-readable → credential leak via copied secrets table content | Information Disclosure | chmod 600 explicitly post-VACUUM-INTO; verified via Pest test that asserts file mode |
| `db:restore` swaps in attacker-supplied DB file → arbitrary data injection | Tampering / Spoofing | Three rails: maintenance mode + pre-restore snapshot + `--confirm` flag; source integrity_check pre-swap; documentation requires operator-vetted source path |
| App-boot PRAGMA misconfiguration silently allows non-WAL writes → torn pages on crash | Tampering | `SqliteOptimizationsProvider` re-applies PRAGMAs on every connection; HealthCheckServiceProvider verifies at boot AND writes alert if drift detected |
| Stuck `withoutOverlapping` cache key blocks all scheduled backups for 24h after crash | Denial of Service | Lock TTL of 60 minutes (not default 24h); document manual recovery recipe in README |
| Banner XSS via system_alerts.message rendered without escaping | Tampering / Information Disclosure | Blade default `{{ }}` escaping (mirrors DashboardDriftBadge). Document that `system_alerts.message` MAY contain operator-controlled text but never user-controlled input in v1 |
| Cross-user system_alerts read via missing user_id scope | Information Disclosure | `SystemAlertQuery::active(?User $user)` always scopes by user_id (or NULL for system-wide); cross-user test asserts this |
| `php artisan down` skipped by accident during restore → live writes during file copy → corrupted DB | Tampering | `--confirm` + refuse-without-maintenance gate + DB::purge() before copy; pre-restore snapshot makes recovery possible |
| TOCTOU between integrity_check and chmod 600 → file readable in the window | Information Disclosure | chmod the backup file FIRST after VACUUM INTO returns, then run integrity_check; the window between VACUUM completing and chmod is the irreducible TOCTOU |

## Sources

### Primary (HIGH confidence)

- [SQLite VACUUM documentation](https://www.sqlite.org/lang_vacuum.html) — VACUUM INTO semantics, atomicity, fsync behavior under synchronous=NORMAL
- [SQLite PRAGMA integrity_check](https://www.sqlite.org/pragma.html#pragma_integrity_check) — Return values; 'ok' single-row on success, multi-row diagnostics on failure
- [SQLite PRAGMA data_version](https://www.sqlite.org/pragma.html#pragma_data_version) — Connection-local caching semantics
- [SQLite forum: Global PRAGMA data_version](https://sqlite.org/forum/info/e76cac71ac2db298) — Cross-process visibility (database-file-global counter; per-connection caching)
- [SQLite SQLITE_FCNTL_DATA_VERSION file control](https://www.sqlite.org/c3ref/c_fcntl_begin_atomic_write.html) — Documentation of the underlying database-file-global counter mechanism
- [Laravel 12.x scheduling docs (identical to 13.x)](https://laravel.com/docs/12.x/scheduling#preventing-task-overlaps) — `withoutOverlapping()` uses application cache; 24h default TTL
- [Laravel 13.x Horizon docs](https://laravel.com/docs/13.x/horizon) — Horizon pauses processing during maintenance mode unless supervisor `force: true` is set

### Secondary (MEDIUM confidence)

- [Laravel Horizon GitHub issue #297 — Deployment order](https://github.com/laravel/horizon/issues/297) — Documents the maintenance-mode-pause behavior
- [PhotoStructure: How to VACUUM SQLite in WAL Mode](https://photostructure.com/coding/how-to-vacuum-sqlite/) — VACUUM under WAL writes to WAL itself, checkpoint moves atomically
- [Old Moe: The Write Stuff: Concurrent Write Transactions in SQLite](https://oldmoe.blog/2024/07/08/the-write-stuff-concurrent-write-transactions-in-sqlite/) — WAL allows readers + one writer simultaneously
- [SQLite WAL documentation](https://sqlite.org/wal.html) — WAL checkpoint concurrency with readers

### Tertiary (LOW confidence — only used as cross-check, not source-of-truth)

- WebSearch hits on "Laravel scheduler withoutOverlapping cache driver lock backend" — used only to corroborate the primary Laravel docs; not relied on for any standalone claim.

### Code (VERIFIED — read at research time)

- `/Users/wesselverheij/Development/diederik/Modules/Core/Internal/Console/DoctorCommand.php` — current doctor command shape; signature, return codes, inline tool-version checks (the soon-to-be-refactored bits)
- `/Users/wesselverheij/Development/diederik/Modules/Core/Internal/Console/InstallCommand.php` — DI conventions: constructor DI of DatabaseManager + Filesystem + Repository + Dispatcher; `handle(): int` returning self::SUCCESS / self::FAILURE; option declaration via `protected $signature`
- `/Users/wesselverheij/Development/diederik/Modules/Core/Providers/CoreServiceProvider.php` — command + Livewire component registration shape
- `/Users/wesselverheij/Development/diederik/Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php` — `ConnectionEstablished` event listener that re-applies WAL + synchronous=NORMAL on every fresh connection (critical to db:restore re-PRAGMA story)
- `/Users/wesselverheij/Development/diederik/Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` — exact shape mirror for SystemAlertQuery (per-user scope; cursor pagination; raw query builder + Eloquent hybrid)
- `/Users/wesselverheij/Development/diederik/Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` — exact shape mirror for AcknowledgeSystemAlert (404 on cross-user; idempotent; transactional)
- `/Users/wesselverheij/Development/diederik/Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php` — Livewire 4 method-parameter DI on render() convention
- `/Users/wesselverheij/Development/diederik/Modules/DriftAlerts/Database/Migrations/2026_05_19_010001_create_drift_alerts_table.php` — migration shape with state-trigger pair
- `/Users/wesselverheij/Development/diederik/Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` — atomic chmod 600 write pattern (umask 0077 + temp file + explicit chmod + atomic rename)
- `/Users/wesselverheij/Development/diederik/routes/console.php` — established Schedule::command + Schedule::call conventions: `.name()` BEFORE `.dailyAt()->withoutOverlapping()`
- `/Users/wesselverheij/Development/diederik/resources/views/layouts/app.blade.php` — current layout, identifies the slot for the new banner
- `/Users/wesselverheij/Development/diederik/tests/Contracts/BoundaryArchTest.php` — established arch-test conventions (`it(...)` blocks with RecursiveIteratorIterator + comment-stripping regex)
- `/Users/wesselverheij/Development/diederik/config/database.php` — SQLite config (WAL, synchronous=NORMAL, busy_timeout=5000)
- `/Users/wesselverheij/Development/diederik/composer.json` — version pins for PHP, Laravel, Livewire, Pest, Larastan
- `/Users/wesselverheij/Development/diederik/.env.example` — CACHE_STORE=database, QUEUE_CONNECTION=redis
- `/Users/wesselverheij/Development/diederik/README.md` lines 125-167 — current ## Backups + ## Operator recovery sections

## Metadata

**Confidence breakdown:**

- Standard stack: **HIGH** — Every library is already on the substrate; versions pinned in composer.json read at research time; no new dependencies.
- Architecture patterns: **HIGH** — Every pattern has a concrete reference in existing code (DriftAlerts shape, SqliteOptimizationsProvider, InstallCommand conventions, routes/console.php scheduler entries, BoundaryArchTest invariants).
- Pitfalls: **HIGH** for SQLite + Laravel core (SQLite docs + Laravel docs verified); **MEDIUM** for the more niche edges (TOCTOU between VACUUM INTO + chmod; SQLite triggers vs CHECK constraints).
- Validation Architecture: **HIGH** — Test framework + existing per-module test discovery confirmed via composer.json + phpunit.xml + per-module Pest.php conventions.
- Security domain: **MEDIUM** — Standard threat patterns enumerated; project-specific Horizon `force: true` config assumption flagged (A2).

**Research date:** 2026-05-19
**Valid until:** 2026-06-18 (30 days — substrate is stable; no fast-moving dependencies in this phase)
