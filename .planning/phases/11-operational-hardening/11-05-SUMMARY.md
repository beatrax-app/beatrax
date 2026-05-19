---
phase: 11-operational-hardening
plan: 05
subsystem: infra
tags: [docs, readme, content-arch-test, sqlite, vacuum-into, system-alerts, livewire, pest, larastan, acceptance-test]

# Dependency graph
requires:
  - phase: 11-operational-hardening
    provides: BackupDatabaseCommand + corrupt-path system_alerts surface (plan 11-02); RestoreDatabaseCommand + Doctor probes + boot health check (plan 11-03); SystemAlertsBanner Livewire SFC + FailedJobsCommand + arch invariants (plan 11-04); SystemAlertQuery + AcknowledgeSystemAlert + RealSqliteFixture (plan 11-01)
provides:
  - "README ## Backups + ## Operator recovery sections rewritten in-place: VACUUM INTO mechanics, 03:00 schedule entry, 7-daily + 4-Sunday retention, ad-hoc PRAGMA integrity_check recipe, DO NOT cp database.sqlite warning, three new recovery recipes (Restoring from a backup, Corrupt-backup alert, Failed-jobs maintenance, Stuck withoutOverlapping lock)"
  - "ReadmeOperationalDocsTest — content arch test pinning 14 required substrings + 4 forbidden substring families (`.planning/`, `Phase 11`, `/D-\\d{4}/` regex, `cp database.sqlite` count == 1)"
  - "Phase11AcceptanceTest — end-to-end vertical scenario walking db:backup happy → calm banner → corrupt source → critical banner → acknowledge in a single Pest run against the real production command pipelines"
  - "AcknowledgeSystemAlert bug-fix: `withoutGlobalScopes()` on the Eloquent hand-off so system-wide alerts (`user_id IS NULL`) are dismissable by any authenticated user"
affects: [end-of-phase-11]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Content-driven arch test for documentation files: file_get_contents → strip markdown HTML comments → required-substring loop + forbidden-substring loop, with a regex assertion for the decision-ID family pattern that catches future digit rolls without a per-ID update"
    - "End-to-end Phase acceptance test that wires the real RealSqliteFixture + sqlite-connection rebind + 'core.backups_directory' override + actingAs Livewire authentication into one Pest scenario so cross-plan regressions surface immediately"
    - "Forbidden-substring arch tests must phrase comments without the forbidden token literal — comments are not language-aware (`grep` sees them as code) so the gate has to be honest about the surface it scans"

key-files:
  created:
    - Modules/Core/tests/Feature/ReadmeOperationalDocsTest.php
    - Modules/Core/tests/Feature/Phase11AcceptanceTest.php
  modified:
    - README.md
    - Modules/Core/Public/Actions/AcknowledgeSystemAlert.php

key-decisions:
  - "ReadmeOperationalDocsTest uses `preg_match('/D-\\d{4}/', $contents)` for the decision-ID forbidden assertion. The regex matches D-1101 through D-9999 in a single line, so future decision IDs that roll past D-1119 do not require a test edit. The plan's alternative — a literal `'D-110'` substring — would miss D-1110+ and trip the moment the decision counter rolled."
  - "The `cp database.sqlite` substring is restricted to exactly ONE occurrence in README.md via `substr_count($contents, 'cp database.sqlite') === 1`. The one allowed occurrence is the `### DO NOT cp database.sqlite` heading itself; the body paragraph of that subsection was reworded to 'A plain `cp` of the live `database.sqlite` file' so the substring did not double-up."
  - "Phase11AcceptanceTest queries the corrupt-path alert through `SystemAlert::withoutGlobalScopes()` rather than `SystemAlert::query()` because the corrupt-path row is system-wide (`user_id IS NULL`). The BelongsToUser global UserScope filters by `where('user_id', $currentUser->id())`, which never matches NULL — the trait's standing carve-out for system-wide visibility lives on the SystemAlertQuery service (`orWhereNull('user_id')`), not on the model itself."
  - "AcknowledgeSystemAlert::__invoke also got `withoutGlobalScopes()->findOrFail($alertId)` on the Eloquent hand-off step. Without it, an authenticated user could not dismiss a system-wide alert (the raw `table()` lookup above the hand-off would find the row, the Eloquent re-hydration would not, ModelNotFoundException bubbled). Phase 11-04's SystemAlertsBannerTest 'removes a row after acknowledging it' scenario only used user-owned alerts; the regression slipped past the dedicated banner test and was caught by the cross-plan acceptance test in this plan. Rule 1 — Bug."
  - "Phase11AcceptanceTest accepts BOTH corrupt-path branches (the `.suspect` rename branch when VACUUM INTO produces output that then fails integrity_check, AND the exception-bridge branch when PRAGMA data_version throws on the malformed source before VACUUM INTO ever runs). The user-visible failure shape — a critical system_alerts(backup_corrupt) row + non-zero exit code — converges either way. Truncating the source to exactly 100 bytes (the SQLite header length) reliably trips the PRAGMA-data_version bridge against the current SQLite version, but the test does not lock that branch in case a future SQLite or PHP version flips the order."

patterns-established:
  - "Content arch test for README contracts: load via `$this->app->make(Filesystem::class)->get($readmePath)`, strip markdown HTML comments with `preg_replace('#<!--.*?-->#s', '', $raw)`, run required-substring loop with `str_contains`, run forbidden-substring loop including a regex variant for family patterns (`/D-\\d{4}/` here)."
  - "Comments inside Pest test files MUST avoid literal forbidden tokens — `grep -c '$forbidden' file.php` counts comments and code identically. Reword comments to describe the rule without echoing the forbidden grammar."
  - "Cross-plan acceptance tests double as bug-traps: Phase11AcceptanceTest caught the AcknowledgeSystemAlert system-wide-alert bug that the per-plan SystemAlertsBannerTest missed, by exercising the cross-plan combination (system-wide alert produced by db:backup → dismissed via the banner) that the per-plan tests never combined."

requirements-completed: [FND-05]

# Metrics
duration: ~75min
completed: 2026-05-19
status: awaiting-human-verify
---

# Phase 11 Plan 05: README Rewrite + Acceptance Test + Human-Verify Summary

**Operator-facing documentation rewrite (README's ## Backups + ## Operator recovery sections), the content arch test that pins it against future regressions, and the end-to-end acceptance test that exercises the full Phase 11 vertical in a single Pest scenario. With the human-verify checkpoint pending, Phase 11 is one approval away from done.**

## Performance

- **Duration:** ~75 minutes
- **Started:** 2026-05-19 (Wave 4 of Phase 11, after 11-01 / 11-02 / 11-03 / 11-04 merged)
- **Completed (Tasks 1-2):** 2026-05-19
- **Tasks executed:** 2 of 3 (Task 3 = human-verify checkpoint, awaiting user)
- **Files created:** 2 (`ReadmeOperationalDocsTest.php`, `Phase11AcceptanceTest.php`)
- **Files modified:** 2 (`README.md`, `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php`)

## Accomplishments

- Rewrote the README's `## Backups` section in-place. Documents what `db:backup` actually does (VACUUM INTO + chmod 600 + integrity check + retention pruning + .meta.json sidecar), the `db.backup-daily` 03:00 schedule entry with the 60-minute `withoutOverlapping(60)` lock, smart-skip vs `--force` manual modes, the 7-daily + 4-Sunday-weekly retention rule (with `.suspect` + `pre-restore-*` carve-outs), the ad-hoc `sqlite3 ... "PRAGMA integrity_check"` recipe, and the explicit `### DO NOT cp database.sqlite` warning (WAL + sidecar files rationale + the "stop the app first" fallback).
- Rewrote `## Operator recovery` in-place: PRESERVED the existing "Stuck Redis unique-lock keys" subsection verbatim (one of the rewrite's hard requirements) and added four new sibling subsections:
  - `### Restoring from a backup` — step-by-step db:restore recipe with the pre-restore-*.sqlite snapshot recovery path, the `--force-maintenance` script-friendly variant, and the post-swap doctor pass.
  - `### Corrupt-backup alert` — what triggers a `system_alerts(backup_corrupt)` row, where the `.suspect` file lives, how to inspect it with `sqlite3 ... "PRAGMA integrity_check"`, how to dismiss the banner, when to delete the `.suspect` file.
  - `### Failed-jobs maintenance` — the `diederik:failed-jobs prune` recipe with `--dry-run`, the supported duration tokens (d|h|w), the explicit `m` rejection rationale, the default 30d retention, and the doctor cross-link.
  - `### Stuck withoutOverlapping lock` — manual recovery for a SIGKILL'd `db:backup` process via the `cache` table key + `php artisan cache:forget` (or wait the 60-minute TTL out).
- Every path in the rewrite is generic (`storage/app/backups/...`) — no machine-specific paths leak (threat T-11-05 mitigation). No `.planning/` references, no `Phase 11` labels, no four-digit decision IDs (D-####). `cp database.sqlite` appears exactly once, in the warning subsection's heading.
- Shipped `ReadmeOperationalDocsTest.php` at `Modules/Core/tests/Feature/`. The test reads README.md once via the injected `Filesystem`, strips markdown HTML comments, then runs:
  - The 14 required-substring assertions (section headings, code references, retention numbers, the preservation of `Stuck Redis unique-lock keys`).
  - The forbidden-substring assertions including a `preg_match('/D-\\d{4}/', $contents) === 0` regex check that catches the entire D-#### family (D-1101 through D-9999) in a single line — future-proof against decision-ID rolls.
  - The `cp database.sqlite` count assertion (`substr_count() === 1`) plus the `### DO NOT cp database.sqlite` heading-presence check, locking the warning's placement and rejecting any duplicate occurrence.
- Shipped `Phase11AcceptanceTest.php` at `Modules/Core/tests/Feature/`. One `it(...)` block walks the full Phase 11 vertical:
  1. Build a real on-disk SQLite source via `RealSqliteFixture::create('phase11-acceptance')`.
  2. Rebind `database.connections.sqlite.database` at the fixture and override `core.backups_directory` to a `sys_get_temp_dir()` subtree (mirrors Plan 11-02's W6-locked test convention).
  3. Run `db:backup --force` — assert the .sqlite + .meta.json pair land in the backups dir.
  4. Render the `SystemAlertsBanner` as the authenticated user — assert the calm state (no `Mark as resolved` button, only the empty `aria-label="System alerts"` wrapper).
  5. Truncate the source DB to 100 bytes — the verbatim recipe from `BackupCorruptionPathTest` (strips the sqlite_master page after the SQLite header).
  6. Re-run `db:backup --force` — assert non-zero exit code AND a critical `system_alerts(backup_corrupt)` row was written organically through the command's `recordCorruptAlert()` helper. No hand-seeded Eloquent insert; no `ACCEPTED FALLBACK` shortcut; the test accepts both the `.suspect` branch and the exception-bridge branch (per `BackupCorruptionPathTest`'s documented dual-branch behaviour).
  7. Re-render the banner — assert the critical row visible with the locked `failed integrity check` copy from `system-alert-message.blade.php`.
  8. Click `acknowledge` via Livewire — assert next render does NOT see the row; persisted row carries `acknowledged_at`.
- Caught + fixed a system-wide-alert acknowledge bug in `AcknowledgeSystemAlert::__invoke`: the Eloquent hand-off `findOrFail($alertId)` ran under the BelongsToUser global UserScope, which filters by `where('user_id', $currentUser->id())` — system-wide rows (`user_id IS NULL`) never matched, so the action raised `NotFoundHttpException` on legitimate dismissals. Added `withoutGlobalScopes()` to the Eloquent re-hydration step. The raw `table()` predicate above the hand-off still enforces the "owned-by-user OR system-wide" access rule, so the fix is a query-builder concern, not a security carve-out.

## Task Commits

Each task was committed atomically (TDD: test → feat per task, with the human-verify checkpoint at task 3):

1. **Task 1 — RED:** `f2e40ac` (test: add failing ReadmeOperationalDocsTest content arch test)
2. **Task 1 — GREEN:** `6d9e0d6` (docs: rewrite README ## Backups + ## Operator recovery sections)
3. **Task 2 — RED:** `a2cf06a` (test: add Phase11AcceptanceTest end-to-end vertical scenario)
4. **Task 2 — GREEN:** `6538ae9` (feat: Phase11AcceptanceTest passes against fixed acknowledge)
5. **Task 2 — Polish:** `88ba2fb` (test: drop SystemAlert::create literal from acceptance comment)

Task 3 (`checkpoint:human-verify`) is the closing gate; the worktree pauses here.

## Files Created/Modified

### Created

- `Modules/Core/tests/Feature/ReadmeOperationalDocsTest.php` — Content arch test pinning the README's operator-facing contract. Reads via injected `Filesystem`, strips markdown HTML comments, runs 14 required-substring assertions + 4 forbidden-substring assertions (including the `/D-\\d{4}/` regex catching the full D-#### family) + the `cp database.sqlite === 1` count gate.
- `Modules/Core/tests/Feature/Phase11AcceptanceTest.php` — End-to-end vertical scenario walking db:backup happy + corrupt-via-real-command + banner render + acknowledge in a single Pest run. 15 assertions; uses `RealSqliteFixture`, `SystemAlertsBanner`, `AcknowledgeSystemAlert`, raw `db:backup --force` invocations on both happy and corrupted sources.

### Modified

- `README.md` — In-place rewrite of `## Backups` and `## Operator recovery` sections. Five new subsections under `## Backups` (`Daily schedule`, `Manual run`, `Retention`, `Verifying a backup`, `DO NOT cp database.sqlite`) plus the four new subsections under `## Operator recovery` documented in Accomplishments. The existing "Stuck Redis unique-lock keys" subsection is preserved verbatim.
- `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` — One-line behavioural fix: `SystemAlert::query()->findOrFail($alertId)` → `SystemAlert::withoutGlobalScopes()->findOrFail($alertId)` on the Eloquent re-hydration step so system-wide rows (`user_id IS NULL`) are reachable for the mutation. Inline documentation explains why the scope bypass is safe (the raw table() predicate above already enforced the access rule).

## Decisions Made

See frontmatter `key-decisions`. The substantive decisions:

1. **Future-proof decision-ID regex** — the plan suggested either a literal `'D-110'` substring or a regex; the regex `'/D-\\d{4}/'` matches the entire D-#### family in one assertion. Future decisions D-1110, D-1120, D-9999 all fail the gate without a per-ID test edit.

2. **`cp database.sqlite` count gate** — the plan required this substring to appear exactly once and only inside the `### DO NOT cp database.sqlite` warning subsection. Implementation: `substr_count() === 1` + heading-presence check. The warning subsection's body paragraph was reworded ("A plain `cp` of the live `database.sqlite` file") so the substring did not double-up.

3. **Acceptance test bypasses the global UserScope** — `SystemAlert::withoutGlobalScopes()` is the cleanest way to read system-wide rows from a test. The production read path goes through `SystemAlertQuery::active($user)` which widens the predicate with `orWhereNull('user_id')`, but the test asserts the row exists at all (a read concern), not how the banner widens its query.

4. **AcknowledgeSystemAlert bug-fix scope** — the fix is one line (`->query()` → `->withoutGlobalScopes()`) on the Eloquent hand-off. It satisfies the Phase 11 acceptance test's step 9 (acknowledge) and preserves the access semantics from the raw `table()` lookup above the hand-off. The existing 6 SystemAlertsBannerTest scenarios + 6 AcknowledgeSystemAlertTest scenarios stay green because they only test user-owned alerts — the system-wide-alert path was undocumented but the SystemAlertsBannerTest's "removes a row after acknowledging it" scenario would have caught this if it had used a system-wide row (it didn't, by happenstance, so the regression slipped past).

5. **Acceptance test accepts both corrupt-path branches** — the truncate-to-100-bytes recipe trips the PRAGMA data_version exception bridge on the current SQLite version, but a future SQLite or PHP version may change the ordering (e.g., PRAGMA succeeds but VACUUM INTO trips). The test's branch-A vs branch-B accept logic matches the dual-branch shape `BackupCorruptionPathTest` documents — the load-bearing user-visible signal is the `system_alerts(backup_corrupt, critical)` row + non-zero exit, regardless of which arm wrote the row.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] `AcknowledgeSystemAlert` was unable to dismiss system-wide alerts**

- **Found during:** Task 2 GREEN verification (Phase11AcceptanceTest step 9 — acknowledge the corrupt-path alert).
- **Issue:** The action's Eloquent re-hydration step `SystemAlert::query()->findOrFail($alertId)` runs under the BelongsToUser global UserScope, which filters `where('user_id', $currentUser->id())`. System-wide rows (`user_id IS NULL`) never match the scope, so `findOrFail` raises `ModelNotFoundException` even though the raw `table()` lookup above the hand-off found the row through the `orWhereNull('user_id')` widening. Result: an authenticated user could not dismiss a system-wide alert (`backup_corrupt`, `backup_overdue`, `wal_mode_missing`, `synchronous_misconfigured`) through the banner. The bug was structurally present from Plan 11-01 but slipped past the per-plan tests because every scenario in SystemAlertsBannerTest's "remove after acknowledge" + AcknowledgeSystemAlertTest used user-owned alerts (`user_id = userA.id`).
- **Fix:** One-line change — `SystemAlert::query()->findOrFail($alertId)` → `SystemAlert::withoutGlobalScopes()->findOrFail($alertId)`. Documented inline at the call site (the comment explains why the scope bypass is safe: the raw `table()` predicate above already enforced the access rule).
- **Files modified:** Modules/Core/Public/Actions/AcknowledgeSystemAlert.php
- **Verification:** All 31 Phase 11 Feature tests + 68 Unit tests + 104 Contracts tests pass; phpstan max + pint clean. Full project suite: 611 Feature + 634 Unit + 104 Contracts = 1349 tests green.
- **Committed in:** 6538ae9 (Task 2 GREEN)

**2. [Rule 1 — Bug] Two stylistic README rewordings to satisfy the content arch test**

- **Found during:** Task 1 GREEN verification (re-running the test against the rewritten README).
- **Issue 2a:** The first rewrite of the retention subsection said "the 7 newest **daily** files" — bolded "daily" broke the test's required substring `'7 daily'`. The bold markdown wrapper `**` interrupted the literal sequence.
- **Issue 2b:** The first rewrite of the `### DO NOT cp database.sqlite` subsection's opening sentence said "`cp database.sqlite some-backup.sqlite` against the live database is unsafe." That produced TWO occurrences of `cp database.sqlite` in README.md (the heading + the body sentence), tripping the `substr_count === 1` gate.
- **Fix:** Reworded both — "the 7 newest **daily**" → "7 daily `diederik-*.sqlite` files (the 7 most-recent dated files)" so the unbolded literal `'7 daily'` survives, and "`cp database.sqlite some-backup.sqlite` against the live database is unsafe" → "A plain `cp` of the live `database.sqlite` file to a backup path is unsafe" so the body no longer contains the literal `cp database.sqlite`.
- **Files modified:** README.md
- **Verification:** ReadmeOperationalDocsTest green with all 20 assertions; the original operator-facing intent of both subsections is preserved.
- **Committed in:** 6d9e0d6 (Task 1 GREEN)

**3. [Rule 1 — Bug] Acceptance test comment dropped the `SystemAlert::create` literal token**

- **Found during:** Task 2 GREEN acceptance-criteria audit (`grep -c "SystemAlert::create" Modules/Core/tests/Feature/Phase11AcceptanceTest.php returns 0`).
- **Issue:** The test's documentation block on step 4 of the acceptance scenario originally said "No hand-seeded SystemAlert::create call — the test exercises the production code path." The literal `SystemAlert::create` in the comment tripped the grep gate (comments are not language-aware to grep).
- **Fix:** Rephrased the comment to "No hand-seeded Eloquent insert — the test exercises the production code path end-to-end." The rule intent is preserved without echoing the forbidden token.
- **Files modified:** Modules/Core/tests/Feature/Phase11AcceptanceTest.php
- **Verification:** `grep -c "SystemAlert::create" file` returns 0; the test still passes (the comment change is documentation-only).
- **Committed in:** 88ba2fb (Task 2 polish)

---

**Total deviations:** 3 auto-fixed (3 Rule 1 — Bug; the AcknowledgeSystemAlert fix is the only behavioural change, the other two are documentation-grammar fixes against the gate).

**Impact on plan:** All three deviations preserve the plan's verbatim intent (operator-facing README contract, end-to-end vertical acceptance test, gate-driven forbidden-token enforcement) while satisfying the project's CI-enforced larastan-strict-rules + Pint profile and the acceptance-criteria greps. No scope creep; no architectural changes; no new packages. The AcknowledgeSystemAlert bug-fix is a 1-line query change that the Phase 11 vertical surfaced through cross-plan coverage — exactly the regression-detection property the acceptance test was designed for.

## Authentication Gates Encountered

None — Phase 11-05 ships local-only documentation + tests. No OAuth or third-party credential flow.

## Issues Encountered

**Worktree CWD vs. PHPUnit testsuite-path discovery (carry-forward from 11-01 / 11-02 / 11-03 / 11-04).** Pest's `BootFiles` bootstrapper loads `tests/Pest.php` from the rootPath derived from the realpath of `vendor/autoload.php`. The worktree at `.claude/worktrees/agent-aadbb33f8eab180c0/` has no `vendor/` directory of its own; every verification round in this plan rsynced the modified files into the matching main-repo paths, ran Pest + Larastan + Pint from the main repo, then `git checkout --` reverted the tracked files and `rm -f` removed the untracked test files before the next commit. The main repo's working tree returned to its `?? .claude/` + `?? storage/app/inbox/` baseline after every cycle. All commits in this plan live in the worktree branch only.

**Pest cross-suite test-file double-loading warnings (carry-forward from 11-04).** Running `./vendor/bin/pest` without a `--testsuite` filter triggers `WARN Cannot add file ... to test suite "DriftAlerts" as it was already added to test suite "Feature"` because the per-Module `phpunit.xml` testsuites overlap. This plan ran filter-narrow Pest invocations and per-suite full runs (`--testsuite=Feature` etc.) to avoid the warning cascade. Out of scope for Phase 11 to fix.

**The PendingCommand RAII vs. file-write timing red herring.** The first three rounds of debugging Phase11AcceptanceTest chased a phantom: scandir showed the happy-run backup files existed; glob returned empty against the same path. The actual issue was the BelongsToUser global UserScope filtering out the system-wide alert in the test's `SystemAlert::query()` count — `glob` had nothing to do with it. Once an inline `file_put_contents('/tmp/dbg_backup.log', ...)` instrumented `BackupDatabaseCommand::handle()` (later reverted), the trail led to `SystemAlert::query()->count() === 0` while a raw `table('system_alerts')->count() === 1` returned the row — classic global-scope footprint.

## User Setup Required

None — no new dependencies, no new environment variables, no external service configuration. The README rewrite is in-place; Pest tests run against the existing substrate; the AcknowledgeSystemAlert fix is a query-builder concern.

## Next Phase Readiness

- **End of Phase 11:** After the human-verify checkpoint approval, Phase 11 is feature-complete. ROADMAP SC #1 (backup), SC #2 (auto-verification + surfacing), SC #3 (operator documentation) are all satisfied across plans 11-01..11-05. The FND-05 requirement in REQUIREMENTS.md is flippable from Pending to Complete (the orchestrator owns the STATE.md / ROADMAP.md / REQUIREMENTS.md writes after the wave merges).
- **Phase 12+:** The `system_alerts` surface is the canonical operational-failure pipeline. Any future module that writes a known `kind` value gets a banner row for free; new kinds fall through to the row's own `message` column. The Probe contract under `Modules/Core/Internal/Console/Probes/` is the extension point for new doctor probes. The README's operator-recovery cookbook is the discovery surface for daily operator workflows.
- **Carry-forward to a future polish phase:** Phase 11's "carry-forward issues" list (Pest cross-suite double-loading, worktree CWD / vendor discovery, the inline tool-version checks in DoctorCommand still inline) accumulates as candidate work items for a "Phase 11.5: operational-hardening polish" plan if/when the user wants to close those backlog items.

## Known Stubs

None — every shipped surface is wired end-to-end. The acceptance test exercises ALL FIVE Phase 11 artifacts in one Pest run (migration → command → public action → Livewire SFC → user-visible banner) and lands the result on the `system_alerts` table the operator banner reads from.

## Self-Check: PASSED

- File `Modules/Core/tests/Feature/ReadmeOperationalDocsTest.php` exists in worktree.
- File `Modules/Core/tests/Feature/Phase11AcceptanceTest.php` exists in worktree.
- Modified `README.md` contains the rewritten `## Backups` and `## Operator recovery` sections.
- Modified `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` uses `withoutGlobalScopes()->findOrFail($alertId)` on the Eloquent hand-off.
- Commits `f2e40ac`, `6d9e0d6`, `a2cf06a`, `6538ae9`, `88ba2fb` all reachable from worktree HEAD.
- `pest --testsuite=Feature --filter='Phase11AcceptanceTest|ReadmeOperationalDocsTest'` → 2 passed (35 assertions).
- `pest --testsuite=Feature --filter='SystemAlertsBannerTest|AcknowledgeSystemAlertTest|BackupCorruptionPathTest|BackupDatabaseCommandTest|BackupScheduleTest|DoctorCommandTest|RestoreDatabaseCommandTest|RestoreSuccessPathTest|AppBootHealthCheckTest|FailedJobsCommandTest|Phase11AcceptanceTest|ReadmeOperationalDocsTest'` → 31 passed (111 assertions; no regression after the AcknowledgeSystemAlert fix).
- `pest --testsuite=Feature` (full Feature suite) → 611 passed + 5 skipped, no regression.
- `pest --testsuite=Unit` (full Unit suite) → 634 passed, no regression.
- `pest --testsuite=Contracts` (full Contracts suite) → 104 passed (591 assertions, no regression on any pre-existing invariant).
- `phpstan analyse --memory-limit=2G` (full tree) → No errors.
- `pint --test` (full project) → passed.
- `grep -c "## Backups" README.md` returns 1.
- `grep -c "## Operator recovery" README.md` returns 1.
- `grep -c "Stuck Redis unique-lock keys" README.md` returns 1 (preservation).
- `grep -c ".planning/" README.md` returns 0 (no GSD-internal references).
- `grep -c "Phase 11" README.md` returns 0 (no phase labels).
- `grep -cE 'D-[0-9]{4}' README.md` returns 0 (no four-digit decision IDs).
- `grep -c "cp database.sqlite" README.md` returns 1 (only inside the DO NOT subsection).
- `grep -c "artisan.*db:backup" Modules/Core/tests/Feature/Phase11AcceptanceTest.php` returns 2 (happy + corrupt runs).
- `grep -c "SystemAlert::create" Modules/Core/tests/Feature/Phase11AcceptanceTest.php` returns 0 (no hand-seeded inserts).
- `grep -c "ACCEPTED FALLBACK" Modules/Core/tests/Feature/Phase11AcceptanceTest.php` returns 0 (no fallback shortcut).
- `grep -c 'D-\\d{4}' Modules/Core/tests/Feature/ReadmeOperationalDocsTest.php` returns 2 (the regex itself in the test + the surrounding comment).
- Human-verify checkpoint: **PENDING** — Task 3 of the plan is `checkpoint:human-verify`. The orchestrator owns the resume signal.

---
*Phase: 11-operational-hardening*
*Tasks 1 & 2: 2026-05-19 (automated, all gates green)*
*Task 3: 2026-05-19 (awaiting human verification on the real Herd-mounted DB)*
