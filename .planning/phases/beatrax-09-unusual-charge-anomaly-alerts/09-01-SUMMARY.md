---
phase: 09-unusual-charge-anomaly-alerts
plan: 01
subsystem: database
tags: [laravel-modules, anomaly, state-machine, sqlite-triggers, pest, arch-tests, eloquent]

# Dependency graph
requires:
  - phase: 05-recurring-drift-alerts (DriftAlerts module)
    provides: "DriftAlerts module shape — sole-mutator state machine + audit-transition table + SQLite state-trigger pair + BoundaryArchTest sole-mutator invariant — cloned verbatim for Anomaly"
  - phase: ledger
    provides: "transactions table (per-transaction FK target for anomaly_alerts) + Transaction model"
  - phase: counterparties
    provides: "counterparties table (suppression-rule merchant FK)"
provides:
  - "Modules/Anomaly module skeleton: composer.json, module.json, AnomalyServiceProvider, registered in bootstrap/providers.php"
  - "anomaly_alerts table keyed per-transaction with UNIQUE(transaction_id) idempotency seam + BEFORE INSERT/UPDATE state-trigger pair"
  - "anomaly_alert_transitions append-only audit table"
  - "anomaly_suppression_rules table (merchant + amount band + detector + direction), per-user scoped"
  - "users.anomaly_sensitivity_percent (50), anomaly_min_amount_minor (1000), anomaly_backfilled_at (nullable)"
  - "AnomalyAlert / AnomalyAlertTransition / AnomalySuppressionRule Eloquent models + factories"
  - "AnomalyAlertStateMachine — sole mutator with the diverging dismissed -> open undo edge (D-18)"
  - "Nine-case anomaly fixture corpus (shared input for Plan 02's evaluator)"
  - "Three BoundaryArchTest invariants: Internal isolation, noOtherAnomalyAlertStateMutator, noTransactionWritesFromAnomaly"
affects: [09-02-evaluator, 09-03-read-write-surface, 09-04-jobs, 09-05-ui]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Per-transaction alert table (UNIQUE(transaction_id)) vs DriftAlerts' per-series UNIQUE — same idempotency-seam pattern, different key"
    - "Sole-mutator state machine with a diverging undo edge (dismissed -> open) layered over the same three-tier enforcement (arch test + runtime map + SQLite trigger)"
    - "reasons JSON array column for multi-reason single-alert semantics (D-16)"
    - "Table-driven fixture corpus consumed by a downstream-plan evaluator, guarded by a shape smoke test"

key-files:
  created:
    - Modules/Anomaly/composer.json
    - Modules/Anomaly/module.json
    - Modules/Anomaly/Providers/AnomalyServiceProvider.php
    - Modules/Anomaly/Database/Migrations/2026_06_13_010001_create_anomaly_alerts_table.php
    - Modules/Anomaly/Database/Migrations/2026_06_13_010002_create_anomaly_alert_transitions_table.php
    - Modules/Anomaly/Database/Migrations/2026_06_13_010003_create_anomaly_suppression_rules_table.php
    - Modules/Anomaly/Database/Migrations/2026_06_13_010004_add_anomaly_settings_to_users.php
    - Modules/Anomaly/Models/AnomalyAlert.php
    - Modules/Anomaly/Models/AnomalyAlertTransition.php
    - Modules/Anomaly/Models/AnomalySuppressionRule.php
    - Modules/Anomaly/Internal/StateMachines/AnomalyAlertStateMachine.php
    - Modules/Anomaly/Internal/StateMachines/InvalidStateTransitionException.php
    - Modules/Anomaly/Database/Factories/AnomalyAlertFactory.php
    - Modules/Anomaly/Database/Factories/AnomalyAlertTransitionFactory.php
    - Modules/Anomaly/Database/Factories/AnomalySuppressionRuleFactory.php
    - Modules/Anomaly/tests/Pest.php
    - Modules/Anomaly/tests/TestCase.php
    - Modules/Anomaly/tests/Unit/AnomalyAlertsMigrationTest.php
    - Modules/Anomaly/tests/Unit/AnomalyAlertTransitionsMigrationTest.php
    - Modules/Anomaly/tests/Unit/AnomalySettingsMigrationTest.php
    - Modules/Anomaly/tests/Unit/AnomalyAlertStateMachineTest.php
    - Modules/Anomaly/tests/Unit/AnomalyFixtureCorpusTest.php
    - "Modules/Anomaly/tests/fixtures/anomaly-corpus/*.php (nine fixtures)"
  modified:
    - bootstrap/providers.php
    - composer.json
    - tests/Pest.php
    - Modules/Core/Models/User.php
    - tests/Contracts/BoundaryArchTest.php

key-decisions:
  - "anomaly_alerts is a NEW dedicated module keyed per-transaction (UNIQUE(transaction_id)), not an extension of drift_alerts (D-01/D-16)"
  - "AnomalyAlertStateMachine diverges from DriftAlerts by allowing dismissed -> open (the undoable-suppression edge, D-18); acknowledged stays terminal"
  - "noTransactionWritesFromAnomaly narrowed vs the Recurring analog to permit Transaction::query() reads (the Anomaly evaluator needs transaction history for baselines), forbidding only writes"
  - "baseline_amount_minor / latest_amount_minor / currency made nullable since first-time-merchant flags carry no per-merchant amount baseline"

patterns-established:
  - "Per-transaction alert with reasons JSON + multi-reason single-alert dedup (one charge = one alert)"
  - "Suppression-rule storage scoped per-user with merchant + amount band + detector + direction keying"
  - "Anomaly settings live on users (sensitivity 50, floor €10.00, backfilled_at first-activation guard)"

requirements-completed: [ANOM-01, ANOM-02]

# Metrics
duration: ~35 min
completed: 2026-06-13
---

# Phase 9 Plan 01: Anomaly Module Scaffold Summary

**New per-transaction Anomaly module cloning the DriftAlerts shape — four migrations (alerts with UNIQUE(transaction_id) + state-trigger pair, append-only audit, suppression rules, users settings), three models, the sole-mutator state machine with the diverging dismissed→open undo edge, a nine-case fixture corpus, and three passing boundary invariants.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-06-13
- **Completed:** 2026-06-13
- **Tasks:** 3
- **Files modified:** 36 (31 created, 5 modified)

## Accomplishments
- Migrate-able, arch-clean `Modules/Anomaly` skeleton registered via `bootstrap/providers.php` and the root Pest per-module map.
- `anomaly_alerts` table carries the `UNIQUE(transaction_id)` idempotency seam (D-16) and the BEFORE INSERT / BEFORE UPDATE OF state trigger pair raising ABORT on any state outside `open/acknowledged/snoozed/dismissed`; append-only `anomaly_alert_transitions` audit table; per-user `anomaly_suppression_rules`; three new users columns with the locked defaults (sensitivity 50, floor 1000, backfilled_at nullable).
- `AnomalyAlertStateMachine` is the sole mutator: one audit row per transition, busy-timeout + lockForUpdate fence, and the diverging `dismissed -> open` undo edge (D-18) on top of the otherwise drift-parity lifecycle.
- Nine table-driven fixtures (large-above/below, first-time-large, duplicate-in-window, duplicate-recurring-excluded, sub-floor-ignored, thin-history-category-fallback, suppressed-skip, mixed-currency) staged as Plan 02's evaluator input, guarded by a shape smoke test.
- Three BoundaryArchTest invariants pass: Anomaly Internal isolation, `noOtherAnomalyAlertStateMutator`, `noTransactionWritesFromAnomaly`.

## Task Commits

1. **Task 1: Module skeleton + four migrations** - `61e32cc` (feat) — 18 migration tests green
2. **Task 2: Models + state machine + factories** - `501c240` (feat) — 22 state-machine tests green
3. **Task 3: Fixture corpus + arch invariants** - `edfb4d1` (test) — full BoundaryArchTest + Anomaly suite green (115 tests)

**Plan metadata:** _(this commit)_ (docs: complete plan)

## Files Created/Modified
See `key-files` frontmatter. Highlights:
- `Modules/Anomaly/Database/Migrations/...create_anomaly_alerts_table.php` — UNIQUE(transaction_id) + state-trigger pair.
- `Modules/Anomaly/Internal/StateMachines/AnomalyAlertStateMachine.php` — sole mutator, dismissed→open undo edge.
- `Modules/Anomaly/Models/AnomalyAlert.php` — reasons JSON array cast, transaction() belongsTo, transitions() HasMany.
- `tests/Contracts/BoundaryArchTest.php` — three new Anomaly invariants.
- `Modules/Core/Models/User.php` — anomaly_sensitivity_percent / anomaly_min_amount_minor / anomaly_backfilled_at fillable + casts + defaults.

## Decisions Made
- **New module, per-transaction key (D-01/D-16):** `anomaly_alerts` is keyed `UNIQUE(transaction_id)` in a dedicated module rather than overloading `drift_alerts` (whose `recurring_series_id` is NOT NULL and series-coupled).
- **Diverging undo edge (D-18):** the state machine adds `dismissed => [open]` so a user-dismissed "as expected" anomaly can be re-opened — the only divergence from the drift transition map.
- **Nullable amount baselines:** `baseline_amount_minor` / `latest_amount_minor` / `currency` are nullable because a first-time-merchant flag has no prior per-merchant amount.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Narrowed `noTransactionWritesFromAnomaly` to allow `Transaction::query()` reads**
- **Found during:** Task 3 (arch invariants)
- **Issue:** The Recurring analog (`noTransactionWritesFromRecurring`) greps `Transaction::query|Transaction::where|Transaction::create` and forbids all three, because Recurring never reads transactions via the model. The Anomaly module, by contrast, MUST read transaction history for baselines (D-06/D-07) and the full-history backfill (D-13) — the plan text for this invariant explicitly states "reads stay allowed." Cloning the Recurring grep verbatim would forbid the Anomaly evaluator's legitimate reads in Plans 02/04.
- **Fix:** The Anomaly invariant greps only the write surfaces: `Transaction::create(` (model write) and `->table('transactions')->(update|insert|delete)(` (query-builder write). `Transaction::query()` / `Transaction::where()` reads pass.
- **Files modified:** tests/Contracts/BoundaryArchTest.php
- **Verification:** Invariant passes on the current skeleton (no transaction writes exist); narrowing is documented inline. Full BoundaryArchTest green.
- **Committed in:** edfb4d1 (Task 3 commit)

**2. [Rule 3 - Blocking] Unique IBAN / sha256 / fingerprint in migration-test transaction seeds**
- **Found during:** Task 1 (migration tests)
- **Issue:** The drift-analog seed helper hardcodes `iban => 'NL00ASNB'`, `sha256 => str_repeat('a',64)`, and a static fingerprint. The anomaly enum-value test seeds multiple transactions per test, tripping `UNIQUE(accounts.user_id, accounts.iban)` and `UNIQUE(import_runs.user_id, import_runs.sha256)`.
- **Fix:** Salted the IBAN, sha256, and fingerprint with `bin2hex(random_bytes(...))` / `hash('sha256', ...)` so repeated seeds stay unique.
- **Files modified:** Modules/Anomaly/tests/Unit/AnomalyAlertsMigrationTest.php
- **Verification:** All 18 migration tests green.
- **Committed in:** 61e32cc (Task 1 commit)

---

**Total deviations:** 2 auto-fixed (1 bug/correctness, 1 blocking test-harness)
**Impact on plan:** Both necessary for correctness. Deviation 1 preserves the module's required read access while still forbidding writes — it strengthens rather than weakens the boundary intent. No scope creep.

## Issues Encountered
- PHPStan exhausted memory when run across the whole repo at the default limit (known host fd/memory constraint per project memory). Resolved by scoping analysis to the touched paths with `php -d memory_limit=2G ./vendor/bin/phpstan analyse Modules/Anomaly ...` — clean (no errors) on all touched files.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Storage, lifecycle contract, and the fixture corpus are settled. Plan 02 (evaluator) can build the three detectors against the nine fixtures and the `reasons` JSON column without re-exploring schema.
- The state machine, suppression-rule table, and users settings are ready for Plans 03 (read/write surface), 04 (jobs + TransactionImported hook), and 05 (UI). The provider leaves the listener / badge / Livewire wiring as explicit TODO stubs for those plans.

## Self-Check: PASSED
- All 31 created files verified present on disk.
- Task commits `61e32cc`, `501c240`, `edfb4d1` present in `git log`.
- Plan verification re-run: `./vendor/bin/pest Modules/Anomaly tests/Contracts/BoundaryArchTest.php` → 115 passed (428 assertions). Pint clean, PHPStan clean on touched files. `migrate:fresh` applies all four anomaly migrations.

---
*Phase: 09-unusual-charge-anomaly-alerts*
*Completed: 2026-06-13*
