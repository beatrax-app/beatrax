# Phase 11: Operational Hardening - Context

**Gathered:** 2026-05-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 11 closes out v1 by hardening the app for daily, unattended use on the user's local machine. It ships the **`db:backup` artisan command** (consistent, restorable SQLite snapshots while the app is running), the **`db:restore` companion command** (safe swap-in with auto pre-restore snapshot + maintenance-mode + `--confirm` rail), **auto-verification of every backup via `PRAGMA integrity_check`** with failures surfaced through a new persistent **`system_alerts` table** (so a corrupt backup cannot silently disappear), **automatic daily scheduling with smart-skip** keyed on SQLite `PRAGMA data_version` (the canonical commit counter), and four small reliability touches: a **`db:restore`** twin, **`diederik:doctor` probes** (WAL active / synchronous=NORMAL / backup freshness), a **failed-jobs maintenance CLI** (`diederik:failed-jobs prune --older-than=<duration>`), and an **app-boot SQLite-PRAGMA health check** (warns and writes a `system_alerts` row but does NOT halt boot). README's existing placeholder `## Backups` and `## Operator recovery` sections are extended in-place — `cp database.sqlite` of the live WAL DB is explicitly forbidden and `db:backup` is documented as the supported path.

**What Phase 11 delivers (vertical):**

- A new `Modules/Core/Internal/Console/BackupDatabaseCommand.php` registering signature `db:backup` (NOT the project-wide `diederik:` prefix — the `db:` namespace is the literal name locked by ROADMAP SC #1 and REQUIREMENTS.md FND-05; both Laravel-shipped `db:show`/`db:wipe`/`db:seed` peers and the project's first-party backup command live under this namespace as a deliberate carve-out documented in this CONTEXT).
  - Mechanism: `VACUUM INTO '<path>'` — single SQL statement, atomic, produces a compacted copy of the live DB while WAL writes continue. Online-backup API rejected as overkill for a single-user local DB; planner confirms during research.
  - Output path: `storage/app/backups/diederik-YYYY-MM-DD-HHMMSS.sqlite`. Directory created on first run with `mkdir -p` and the project's storage permission set; file permissions `chmod 600` mirroring `storage/app/secrets/imap.json` convention.
  - **Immediate post-backup verification:** open the just-written file via a fresh `PDO('sqlite:<path>')` connection (NOT the main app connection), run `PRAGMA integrity_check`, expect `ok`. Any other result writes a `system_alerts` row (kind=`backup_corrupt`, severity=`critical`) AND emits a structured log line AND exits non-zero. The corrupt file is left on disk under a `.suspect` suffix so the user can inspect it; it does NOT count toward retention.
  - **Smart-skip:** before running VACUUM INTO, the command reads `PRAGMA data_version` from the live DB. If it equals the `data_version` stamped on the most recent successful backup's sidecar metadata file (`<backup>.meta.json`: `{ data_version, started_at, completed_at, integrity: 'ok' }`), the command logs "skipped — no commits since last backup" and exits 0. Otherwise it proceeds.
  - **Retention:** after a successful verified backup, the command prunes `storage/app/backups/` to keep the latest 7 daily files + the 4 most-recent Sunday-dated weekly files (Sunday is the project's week anchor — already used implicitly by the dashboard "this period at a glance" tile). Files matching neither rule are deleted. The pruner is a separate `Modules/Core/Internal/Console/Support/BackupRetentionPolicy.php` value object so the planner can unit-test it independently of `VACUUM INTO`.
  - **Scheduling:** `Schedule::command('db:backup')->dailyAt('03:00')->withoutOverlapping();` registered via `Modules\Core\Internal\Providers\CoreServiceProvider::registerScheduledTasks()` (mirrors the existing Phase 8/9/10 scheduler-extension pattern). The existing `deploy/launchd/com.diederik.scheduler.plist` already runs `schedule:work` — nothing in launchd changes.

- A new `Modules/Core/Internal/Console/RestoreDatabaseCommand.php` registering signature `db:restore {path}` with three load-bearing safety rails:
  1. Refuses to run unless the app is in maintenance mode (`php artisan down`) OR `--force-maintenance` is passed (which calls `Artisan::call('down')` internally and re-`up`s on completion). The Phase 5 horizon worker stack is paused by `php artisan down` because requests stop entering — this is the right primitive.
  2. Takes a pre-restore snapshot of the CURRENT live DB via the same `VACUUM INTO` path, written to `storage/app/backups/pre-restore-YYYY-MM-DD-HHMMSS.sqlite`, BEFORE touching anything. Filename pattern is distinct so the retention pruner never deletes a pre-restore snapshot (treat `pre-restore-*` as a separate retention bucket with `keep-forever-until-acknowledged` policy — user manually prunes).
  3. Validates the source file via `PRAGMA integrity_check` BEFORE swap. Mismatch refuses the restore.
  4. Requires `--confirm` flag OR (in TTY) prompts `Restore <source> over current DB? Pre-restore snapshot will be saved at <path>. [y/N]`. Non-TTY without `--confirm` exits non-zero.
  - Swap mechanism: `php artisan down` → close all DB connections (`DB::purge()`) → copy source over `database/database.sqlite` (or whatever `database.connections.sqlite.database` resolves to) → reopen → run `PRAGMA integrity_check` on the swapped-in DB → `php artisan up` → log line.

- A new `system_alerts` migration (additive, no destructive changes): columns `id`, `user_id` (nullable BIGINT FK to users — NULL = system-wide), `kind` (string, indexed — first values: `backup_corrupt`, `backup_overdue`, `wal_mode_missing`, `synchronous_misconfigured`), `severity` (enum: `info`, `warning`, `critical`), `message` (text), `metadata` (JSON, nullable), `created_at`, `acknowledged_at` (nullable timestamp). Indexes: `(user_id, acknowledged_at)`, `(kind, acknowledged_at)`. Eloquent model `Modules\Core\Models\SystemAlert` with scopes `active()` (= `whereNull('acknowledged_at')`), `byKind()`. Acknowledged_at is NEVER deleted — alerts archive in place for audit trail.

- A new `Modules\Core\Public\Services\SystemAlertQuery` read API: `active(?User $user): Collection<SystemAlert>` and `count(?User $user): int`. The `?User $user` parameter scopes per-user alerts AND includes system-wide (user_id NULL) alerts. Mirrors Phase 9's `DriftAlertQuery` shape.

- A new `Modules\Core\Public\Actions\AcknowledgeSystemAlert` action: stamps `acknowledged_at = now()` on the row. Constructor-DI'd, transactional, returns the updated model. Mirrors Phase 9's `DismissDriftAlert`.

- A persistent dashboard banner via a new `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` Livewire SFC + Public component slot in `app.blade.php` between the top nav and the page content. Banner reads `SystemAlertQuery::active($currentUser)` on mount; renders ONE banner per active critical alert with a dismiss button wired to `AcknowledgeSystemAlert`; warning/info severities render below as a stack. Banner is sticky until the user clicks "Mark as resolved" — they cannot lose visibility by navigating. Banner copy for `backup_corrupt`: "⚠ The backup written at {timestamp} failed integrity check. Inspect {.suspect file path}. Resolve before relying on backups."

- An extension to the existing `Modules/Core/Internal/Console/DoctorCommand.php` (`diederik:doctor`) adding three probes alongside any existing ones:
  - **WAL mode active:** `PRAGMA journal_mode` returns `wal`; flag if otherwise.
  - **synchronous=NORMAL set:** `PRAGMA synchronous` returns `1` (NORMAL); flag if otherwise.
  - **Backup freshness:** the most-recent verified backup in `storage/app/backups/` has `completed_at` within the last 48 hours; if older OR none exist, the probe FAILS and writes a `system_alerts` row (kind=`backup_overdue`, severity=`warning`) — so manual `diederik:doctor` runs and the next page-load banner are mutually reinforcing.
  - Each probe is a separate small class implementing a shared `Probe` contract under `Modules/Core/Internal/Console/Probes/` so the planner can add a fourth/fifth probe in v2 without rewriting the command. Existing probes (if any in DoctorCommand) are refactored into the same contract during this phase as a stylistic carry-cost.

- A new `Modules/Core/Internal/Console/FailedJobsCommand.php` registering signature `diederik:failed-jobs prune {--older-than=30d} {--dry-run}`. Reads `failed_jobs` table, deletes rows with `failed_at < now() - <duration>`, prints a summary (`Removed N rows; M remaining`). `--dry-run` prints what WOULD be deleted without writing. The `<duration>` parser accepts Laravel-Carbon `sub*` style (`30d`, `7d`, `90d`); planner picks the parser shape.

- An app-boot health check in `app/Providers/AppServiceProvider.php`'s `boot()` (or a new `Modules\Core\Internal\Providers\HealthCheckServiceProvider` — planner picks the right home). On boot in non-`local`-only mode it reads `PRAGMA journal_mode` and `PRAGMA synchronous` on the default connection; if either is wrong, it writes a `system_alerts` row (kind=`wal_mode_missing` / `synchronous_misconfigured`, severity=`warning`), logs a structured warning, AND continues — the app still boots. The dashboard banner makes the user aware on the very next page load. Loud-fail rejected: a borked Herd PHP version swap should not lock the user out of the app entirely.

- A README rewrite of the existing `## Backups` and `## Operator recovery` sections:
  - `## Backups`: explicitly forbids `cp database.sqlite` of the live WAL DB (with the `.sqlite-wal` and `.sqlite-shm` sidecar explanation already present), points to `php artisan db:backup` (documents what it does + retention policy + scheduler entry), points to `php artisan db:restore <path>` with safety rails.
  - `## Operator recovery`: keeps the existing "Stuck Redis unique-lock keys" subsection AND adds three new ones: "Restoring from a backup" (step-by-step including the pre-restore snapshot story), "Corrupt-backup alert" (how to interpret a `system_alerts.backup_corrupt` row), "Failed-jobs maintenance" (the `diederik:failed-jobs prune` recipe).

- Module tests:
  - `tests/Feature/Backup/BackupDatabaseCommandTest.php` — end-to-end on a fresh SQLite fixture: command produces a verified file, retention prunes correctly across a 14-day rolling window, smart-skip exits 0 with the right log line, corrupt source produces a `.suspect` file + `system_alerts` row.
  - `tests/Feature/Backup/RestoreDatabaseCommandTest.php` — refuses without `--confirm`, refuses without maintenance mode, pre-restore snapshot is written, corrupt source is rejected, successful restore swaps cleanly + brings the app back up.
  - `tests/Unit/Core/BackupRetentionPolicyTest.php` — Pest dataset covering the 7-daily + 4-Sunday-weekly rule across edge cases (consecutive same-day backups, missing weekdays, retention window narrower than file count).
  - `tests/Feature/Core/DoctorProbesTest.php` — each probe runs in isolation; the WAL probe fails after `PRAGMA journal_mode = DELETE` is forced and writes the expected `system_alerts` row.
  - `tests/Feature/Core/SystemAlertsBannerTest.php` — banner renders for active critical alerts, dismisses on action, persists across page loads until acknowledged, scopes per-user.
  - `tests/Feature/Core/FailedJobsCommandTest.php` — prunes correctly, `--dry-run` does not write.
  - `tests/Feature/Core/AppBootHealthCheckTest.php` — boot probe writes the alert row on misconfigured PRAGMAs; app continues to serve requests.

- BoundaryArchTest extensions (one new test, mirrors Phase 9 D-902 carry-forward):
  - `noFacadeCallsFromCoreConsoleCommands` — feedback memory locks DI-only; every new command MUST receive `DatabaseManager`, `Filesystem`, `Schedule`, etc. via constructor injection.
  - `systemAlertsTableNotJoinedToTransactions` — `system_alerts` is a purely operational surface and MUST NOT be queried alongside `transactions` / domain reads (mirrors Phase 10's `noScenarioMutationsJoinedToTransactionQueries` shape).

**What Phase 11 does NOT deliver:**

- Cloud-uploaded or off-machine backups — explicitly out of scope per PROJECT.md "Hosting: Local only".
- Encryption-at-rest for backup files beyond filesystem `chmod 600` — the live DB itself isn't encrypted; backup-only encryption is asymmetric protection.
- iCloud / Time Machine integration — operator's choice on the OS, not the app's responsibility.
- Outbound notifications (email, push, macOS notification) on alert creation — the in-app banner is the v1 surface. macOS notification deferred (rejected during discuss in favor of the persistent banner which is harder to miss).
- A scenario-style "scheduled-task management" UI — the scheduler runs via launchd; surface is the CLI + Telescope (already installed locally).
- New launchd plist templates — `deploy/launchd/com.diederik.scheduler.plist` already runs `schedule:work` which fires `db:backup` daily via the new schedule entry. Phase 11 does NOT add a separate plist for backups.
- Multi-tenant alert routing — `system_alerts.user_id` is nullable from day one (FND-03 carry-forward) but v1 has one user; multi-user partner rollout is post-v1.
- Restore-into-a-different-DB-file (split-brain testing) — `db:restore` swaps the configured DB; a multi-DB local sandbox is out of scope.

**Architectural anchor:**

Phase 11 is the operational layer that sits below every other module — it does not extend any domain module's behavior, it provides infrastructure those modules can lean on. The `system_alerts` table is deliberately scoped narrow in v1 (4 known `kind` values) but its schema is designed so future modules (Phase 6 IMAP backfill failures, Phase 8 detection-job failures, Phase 9 drift-alert dispatch failures) can write rows without a migration. This is the "calm tool" promise: failures don't disappear, they accumulate where the user will see them on next page load, and they archive in place once resolved.

The phase consumes nothing that doesn't already exist on the substrate — WAL is already on (config/database.php), launchd already runs `schedule:work`, queue + horizon + Redis are already wired (Phase 5 Wave 0). The only new persistence surface is `system_alerts` itself; everything else is artisan commands + console probes + a banner.

</domain>

<decisions>
## Implementation Decisions

### Backup Mechanism, Storage, Retention

- **D-1101:** **`VACUUM INTO` is the locked backup mechanism.** Single SQL statement; atomic; produces a compacted copy of the live DB while WAL writes continue uninterrupted. Online-backup API (`SQLite3::backup`) was rejected — overkill for a single-user local app with a sub-100MB DB, and adds a streaming-incremental code path with no operational payoff. ROADMAP SC #1 explicitly lists both as acceptable; planner picks `VACUUM INTO`.
- **D-1102:** **Backup destination is `storage/app/backups/`, files named `diederik-YYYY-MM-DD-HHMMSS.sqlite`, permissions `chmod 600`.** Mirrors the `storage/app/secrets/imap.json` permission precedent (sensitive-data convention from Phase 1). The directory is created on first run via `mkdir -p`. Gitignored (already covered by the project-wide `storage/app/*` ignore; the planner verifies the existing `.gitignore` covers `backups/` and adds an explicit entry if not).
- **D-1103:** **Retention: keep the latest 7 daily backups + the 4 most-recent Sunday-dated weekly snapshots; everything else gets deleted after each successful run.** Predictable disk usage (≤11 files at steady state on a ~50MB DB = ~550MB upper bound). Project policy "Full history retained forever" applies to transaction rows INSIDE the DB, NOT to backup snapshots — those are point-in-time recovery artifacts, not the system of record. Pruner lives in `BackupRetentionPolicy` value object for unit-testability.
- **D-1104:** **Pre-restore snapshots (filename pattern `pre-restore-YYYY-MM-DD-HHMMSS.sqlite`) are NEVER pruned automatically.** They sit in `storage/app/backups/` alongside scheduled backups but the retention rule's name-pattern filter exempts them. The user prunes manually after they're confident the restore took. Operator docs explain this.

### Scheduling + Smart-Skip

- **D-1105:** **`db:backup` runs automatically at 03:00 daily via `Schedule::command(...)->dailyAt('03:00')->withoutOverlapping()`.** Registered in `Modules\Core\Internal\Providers\CoreServiceProvider::registerScheduledTasks()`. The existing `deploy/launchd/com.diederik.scheduler.plist` already fires `php artisan schedule:work` — Phase 11 adds NO new launchd plist. `withoutOverlapping()` prevents two same-day runs from racing if the previous run is somehow still active (defence-in-depth even though scheduled spacing makes overlap improbable).
- **D-1106:** **Smart-skip signal is `PRAGMA data_version`.** The canonical SQLite commit counter — increments on every write to the DB, immune to mtime quirks (WAL checkpoint timing) and cheap to read (one PRAGMA call). Each successful backup writes a sidecar `<backup>.meta.json` containing `{ data_version, started_at, completed_at, integrity: 'ok' }`. On run, command reads live `PRAGMA data_version`; if it matches the most-recent backup's sidecar, log `"skipped — no commits since last backup"` and exit 0. Otherwise proceed. Mtime-comparison + row-count rejected (D-1106 deliberation in discussion log).
- **D-1107:** **Manual invocation always works regardless of smart-skip.** `php artisan db:backup --force` bypasses the data_version check and runs unconditionally. Useful before a risky manual operation. Default invocation (without `--force`) is what the scheduler uses.

### Failure Surface — `system_alerts` Table + Persistent Banner

- **D-1108:** **`system_alerts` is a first-class new persistence surface.** Migration adds the table (columns + indexes per `<domain>`); Eloquent model + scopes; Public read API (`SystemAlertQuery`) + Public action (`AcknowledgeSystemAlert`). The schema is deliberately broad enough that future modules can write `kind`s beyond Phase 11's four — but Phase 11 itself only writes the four. JSON file / config dotfile rejected as alternative persistence: would create two persistence stories and bypass Livewire's natural Eloquent integration.
- **D-1109:** **Persistent dashboard banner renders all active critical alerts at the top of every page, sticky until the user clicks "Mark as resolved".** Lives in `app.blade.php` between top nav and page content, Livewire SFC reads `SystemAlertQuery::active($currentUser)` on mount. Warning/info severities render below the critical stack with a more subdued treatment (calm tool — severity gradient is honest). User cannot lose visibility by navigating; only acknowledgement clears the banner.
- **D-1110:** **A failed-integrity-check is the only `critical` severity in Phase 11.** `backup_overdue`, `wal_mode_missing`, `synchronous_misconfigured` are all `warning`. The corruption case justifies blocking visual real-estate; the configuration drifts are inconvenient but not data-losing.
- **D-1111:** **A corrupt backup file is preserved on disk with a `.suspect` suffix (`diederik-YYYY-MM-DD-HHMMSS.sqlite.suspect`) so the user can inspect it.** It does NOT count toward retention (retention pruner ignores `.suspect` files entirely). The user inspects + deletes manually.

### `db:restore` Safety Rails

- **D-1112:** **`db:restore` requires THREE rails simultaneously: maintenance mode + pre-restore snapshot + `--confirm`.** This is rare-and-irreversible territory; ceremony is cheap and "I fat-fingered the source path" is recoverable because the pre-restore snapshot exists. The flag `--force-maintenance` is provided so a script can chain `db:restore --confirm --force-maintenance <path>` without a separate `php artisan down` invocation; the command brings the app back `up` on completion.
- **D-1113:** **Source-file validation runs BEFORE the swap, not after.** Open the source via fresh `PDO`, run `PRAGMA integrity_check`; if not `ok`, refuse to proceed AND leave the current DB untouched. Catching corruption pre-swap is the load-bearing safety property — post-swap detection of a corrupt source means the user is in a half-broken state.
- **D-1114:** **Post-swap, run integrity_check one more time on the now-live DB.** Belt-and-braces. If THIS check fails (filesystem-level corruption during copy), the command keeps maintenance mode ON, logs a `critical` `system_alerts` row, and exits non-zero. The pre-restore snapshot is still on disk for manual recovery.

### Doctor Probes + Failed-Jobs CLI + App-Boot Check

- **D-1115:** **`diederik:doctor` gains three probes via a shared `Probe` contract under `Modules/Core/Internal/Console/Probes/`.** Each probe is a small testable class: `WalModeProbe`, `SynchronousModeProbe`, `BackupFreshnessProbe`. The contract is `run(): ProbeResult` returning ok / warning / critical + a message. The command iterates probes, prints a summary table, exits non-zero on any non-ok result. Any existing probes in the current `DoctorCommand` get refactored into the same contract during this phase — stylistic carry-cost worth paying once.
- **D-1116:** **The `BackupFreshnessProbe` is the ONLY one that ALSO writes a `system_alerts` row.** If the latest verified backup is > 48 hours old (or none exist), the probe writes `system_alerts(kind=backup_overdue, severity=warning)` AND fails. The other two probes (WAL / synchronous) are read-only against the doctor command — the app-boot check is what writes their `system_alerts` rows. Rationale: backup freshness is a slow-decay signal that should accumulate across doctor runs and page loads; PRAGMA drift is a startup-time signal owned by the boot check.
- **D-1117:** **`diederik:failed-jobs` is the failed-jobs maintenance surface.** Subcommand-style signature `diederik:failed-jobs prune {--older-than=30d} {--dry-run}` for v1. Future verbs (`view`, `retry-all`, `clear`) can extend without renaming. Default `30d` retention is conservative; the user can pass `--older-than=7d` if they want tighter pruning. `--dry-run` prints the rows that WOULD be deleted without writing.
- **D-1118:** **App-boot health check writes alerts but does NOT halt boot.** Lives in a new `HealthCheckServiceProvider`'s `boot()` (planner confirms placement — `AppServiceProvider` is the obvious alternative; the dedicated provider gives the boot probe a clear module home and matches `Modules/Core/Internal/Providers/` convention). Checks WAL + synchronous; writes `system_alerts` row + structured log on drift; app continues to serve. Loud-fail rejected — locking the user out of the app for a PRAGMA drift is worse than a banner.

### Command Naming Carve-Out

- **D-1119:** **`db:backup` and `db:restore` use the `db:` namespace despite the project-wide `diederik:` convention.** Locked by ROADMAP SC #1 and REQUIREMENTS.md FND-05 — both quote `php artisan db:backup` verbatim. Laravel ships `db:show` / `db:wipe` / `db:seed` under the same namespace; a first-party `db:backup` / `db:restore` peer is consistent with that. The remaining new commands (`diederik:failed-jobs`) keep the existing convention. CONTEXT records the carve-out so future contributors don't try to rename.

### Claude's Discretion

The planner has flexibility on:
- The exact `<duration>` parser shape for `diederik:failed-jobs --older-than=` (Carbon `sub*` style, or a small custom parser — either is fine).
- Whether the app-boot check lives in `AppServiceProvider::boot()` or a new `Modules/Core/Internal/Providers/HealthCheckServiceProvider`.
- The Blade banner's exact Tailwind tokens (severity colors should mirror existing Phase 9 drift-alert / Phase 5 chain-link confidence tier palette for visual coherence; planner picks).
- The Pest test split between Feature/ and Unit/ within the Phase 11 test list.
- The internal name of the `Probe` contract and whether existing DoctorCommand internals get refactored as part of this phase or deferred.

### Folded Todos
None — discussion captured no pending-todo matches (`gsd-sdk query todo.match-phase 11` returned 0).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 11 Scope + Requirements
- `.planning/ROADMAP.md` — Phase 11 entry (Goal, Mode, Depends-on, Requirements, Success Criteria)
- `.planning/REQUIREMENTS.md` — FND-05 entry (the `db:backup` requirement that this phase satisfies and flips Complete)
- `.planning/PROJECT.md` — Constraints section (Local-only hosting; SQLite WAL invariant; "calm tool" ethos)

### Operational Baseline Already on the Substrate
- `config/database.php` — SQLite WAL + `synchronous=NORMAL` configuration. The boot health check verifies the PRAGMAs match this config at runtime.
- `deploy/launchd/com.diederik.scheduler.plist` — runs `php artisan schedule:work`. Phase 11 adds a `Schedule::command('db:backup')->dailyAt('03:00')` entry but ships NO new plist.
- `deploy/launchd/com.diederik.horizon.plist`, `deploy/launchd/com.diederik.redis.plist` — informational; Phase 11 does not modify these.
- `Modules/Core/Internal/Console/DoctorCommand.php` — extension point for the three new probes (signature `diederik:doctor`).
- `Modules/Core/Internal/Console/InstallCommand.php` — convention reference for module command shape (constructor DI, signature, `handle()` return-code).
- `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php` — second convention reference, locks the project-wide `diederik:*` naming carve-out (`db:backup`/`db:restore` is the deliberate exception).
- `README.md` — `## Backups` and `## Operator recovery` sections already exist as placeholders; Phase 11 rewrites both in-place.

### Convention Carry-Forward From Earlier Phases
- `tests/Contracts/BoundaryArchTest.php` — Phase 9 D-902 + Phase 10 D-1024 carry-forward for arch invariants. The new `noFacadeCallsFromCoreConsoleCommands` and `systemAlertsTableNotJoinedToTransactions` invariants append to this file.
- `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` — shape reference for `SystemAlertQuery`.
- `Modules/DriftAlerts/Public/Actions/DismissDriftAlert.php` — shape reference for `AcknowledgeSystemAlert`.
- `Modules/Forecasting/Database/Migrations/*` (Phase 10) — most recent migration shape on the substrate; reference for `system_alerts` migration style.
- `storage/app/secrets/imap.json` (chmod 600 precedent from Phase 1 PLT-05) — file-permission convention applied to `storage/app/backups/*.sqlite`.

### External / Library Documentation (research will pull as needed)
- SQLite `VACUUM INTO` reference (https://www.sqlite.org/lang_vacuum.html#vacuuminto)
- SQLite `PRAGMA integrity_check` reference (https://www.sqlite.org/pragma.html#pragma_integrity_check)
- SQLite `PRAGMA data_version` reference (https://www.sqlite.org/pragma.html#pragma_data_version)
- Laravel 13 task scheduling (`->withoutOverlapping()`, `->dailyAt()` semantics)
- Laravel maintenance mode (`php artisan down` / `up`; `--render`; secret-based bypass — not used here)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`Modules/Core/Internal/Console/DoctorCommand.php`** — already registers signature `diederik:doctor`. Phase 11 extends it with three probes via a shared `Probe` contract. Existing probes (if any) refactor into the same contract.
- **`Modules/Core/Internal/Console/InstallCommand.php`** — convention reference for command shape: final class, constructor DI, `protected $signature`, `handle(): int` returning self::SUCCESS / self::FAILURE.
- **`Modules/DriftAlerts/Public/Services/DriftAlertQuery.php`** + `DismissDriftAlert` action — exact shape to mirror for `SystemAlertQuery` + `AcknowledgeSystemAlert`. Same per-user scope pattern.
- **`config/database.php`** — WAL + synchronous=NORMAL configuration already locked. The boot health check verifies the LIVE pragmas match this CONFIG.
- **`deploy/launchd/com.diederik.scheduler.plist`** — already running `schedule:work`. Phase 11 piggybacks via the new `Schedule::command()` entry; no plist edits.
- **`storage/app/secrets/imap.json`** (Phase 1 precedent) — file permissions convention: 600. Applies to backup `.sqlite` files.
- **`Modules/Forecasting/Database/Migrations/2026_05_19_010005_add_forecast_columns_to_accounts.php`** (Phase 10) — most-recent migration shape; reference for `create_system_alerts_table` styling.
- **`tests/Contracts/BoundaryArchTest.php`** — extension point for the two new arch invariants.

### Established Patterns

- **DI-only (feedback memory + Phase 5 D-101):** every new command takes its dependencies via constructor. No `DB::`, `Schedule::`, `Storage::`, `Artisan::` facade calls in production code — receive `DatabaseManager`, `Schedule`, `Filesystem`, `Kernel` instances instead. (Eloquent model statics are allowed per the same feedback memory.)
- **Codebase agnostic from GSD (feedback memory):** no `.planning/` references, no `D-1101` codes, no `Phase 11` annotations in production code or PHPDocs. Rationale lives in plain technical language inside class docblocks.
- **Docs describe current state (feedback memory):** the README rewrite explains what `db:backup` does TODAY, not "we added this in Phase 11".
- **Module Public/Internal split (Phase 1 architecture lock):** new Public surface (`Modules/Core/Public/Services/SystemAlertQuery.php`, `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php`); new Internal surface (the four commands + probes + boot provider + retention-policy value object).
- **Persistent-banner Livewire SFC pattern (Phase 5 chain-drawer + Phase 9 drift-alert dashboard):** Blade SFC + `app.blade.php` slot + Livewire `dispatch()` from action back to component for live update on acknowledge.
- **BoundaryArchTest invariant pattern (Phase 9 D-902, Phase 10 `noScenarioMutationsJoinedToTransactionQueries`):** one new `it(...)` block per invariant, walks `Modules/` tree, regex-based detection.
- **Pest test housing (Phase 9 + Phase 10 precedent):** Feature/ for command + Livewire + boot-check tests; Unit/ for the retention-policy value object.
- **Larastan level 10 + Pint + Pest must pass (constraint carry-forward):** every new file lands clean. No relaxations.

### Integration Points

- **`Schedule::command('db:backup')->dailyAt('03:00')->withoutOverlapping()`** registered in `Modules\Core\Internal\Providers\CoreServiceProvider::registerScheduledTasks()` (or wherever Core's scheduler-extension hook already lives — planner confirms).
- **`app.blade.php`** — Livewire slot for `<livewire:system-alerts-banner />` between top nav and page content. Existing layout file already hosts the Phase 5 top-nav badge + Phase 10 forecast-highlights pieces; the banner sits above the main content area.
- **`SystemAlertQuery::active(?User $user)`** — read by the banner SFC on mount AND by the dashboard's existing health-tile composition path (if any).
- **`AcknowledgeSystemAlert`** — invoked from the banner's "Mark as resolved" button via Livewire `wire:click="acknowledge({id})"`.
- **`storage/app/backups/`** — new directory created on first `db:backup` run. The retention pruner is the only writer that ever DELETES from it.
- **`system_alerts` table** — new persistence surface; written by `db:backup` (corrupt + overdue paths), `BackupFreshnessProbe`, app-boot health check; read by `SystemAlertQuery`; mutated by `AcknowledgeSystemAlert`.

</code_context>

<specifics>
## Specific Ideas

- "Calm tool" ethos applies to every surface here: a banner that demands acknowledgment is the loudest thing the app does in v1. macOS notifications were rejected for this reason — the persistent banner is harder to miss AND less invasive than a system-wide popup.
- Backup retention "7 daily + 4 weekly" mirrors common ops practice (Atlassian / GitHub backup posture) and gives ~5 weeks of recovery window with a bounded file count. The user explicitly chose this over "keep forever" — disk discipline matters more than infinite redundancy.
- Pre-restore snapshots NEVER auto-prune because the moment of restore is exactly when the user is most likely to need to undo it. Let those files accumulate; user prunes manually when confident.
- The `db:` namespace carve-out is deliberate and documented — not an oversight. Future contributors will see `diederik:doctor` + `diederik:failed-jobs` next to `db:backup` + `db:restore` and might be tempted to rename. CONTEXT.md + the README backup section both make the choice explicit.
- Smart-skip via `PRAGMA data_version` is the canonical "did SQLite change?" signal — the user specifically chose this over mtime and row-count because they trust SQLite's own counter more than filesystem heuristics.

</specifics>

<deferred>
## Deferred Ideas

- **Cloud-uploaded backups** — Local-only hosting is a project constraint (PROJECT.md). Future phase or out-of-scope entirely.
- **Backup-file encryption-at-rest** — Asymmetric protection vs the unencrypted live DB. Out of scope for v1; revisit if/when partner-sharing requires it.
- **macOS notifications on alert creation** — Considered during discuss; the persistent banner won. Could be added later as opt-in.
- **Multi-DB / split-brain restore (restore into a sandbox copy)** — Out of scope; the single-user single-DB model is enough.
- **Backup retention configurability via env var** — Hard-coded 7-daily + 4-weekly for v1. If the user later wants `BACKUP_KEEP_DAILY=14`, add a config entry.
- **Telescope cleanup CLI / Debugbar production-disable guard** — Considered during scope discussion; not selected for Phase 11. Telescope is local-only and the project never deploys, so the production-disable concern doesn't apply yet.
- **Log rotation CLI** — Laravel's daily-log channel handles rotation natively (`LOG_CHANNEL=daily`); no app-side CLI needed in v1.
- **Backup-restoration drill scheduled task** — Auto-restoring a backup into a sandbox to prove it boots. Considered overkill for a single-user local tool.

### Reviewed Todos (not folded)
None — `gsd-sdk query todo.match-phase 11` returned no candidates.

</deferred>

---

*Phase: 11-operational-hardening*
*Context gathered: 2026-05-19*
