---
phase: 09-unusual-charge-anomaly-alerts
plan: 02
subsystem: api
tags: [anomaly, detection, robust-statistics, mad, median, percentile, evaluator, suppression, idempotency, laravel-modules, pest]

# Dependency graph
requires:
  - phase: 09-01 (Anomaly module scaffold)
    provides: "anomaly_alerts (UNIQUE(transaction_id) + reasons JSON + direction enum), anomaly_suppression_rules, users anomaly settings (sensitivity 50 / floor 1000), AnomalyAlert model, nine-case fixture corpus, noTransactionWritesFromAnomaly arch invariant"
  - phase: 05-recurring-drift-alerts (DriftAlerts module)
    provides: "DriftEvaluator skeleton (idempotent insert + QueryException no-op + event dispatch + cross-module read discipline) cloned for AnomalyEvaluator"
  - phase: recurring
    provides: "RecurringSeriesQuery Public surface (extended here) + recurring_series_occurrences membership signal for duplicate exclusion"
  - phase: ledger
    provides: "transactions table (settled_amount_minor/settled_currency/type/counterparty_id/category_id/posted_at) read directly for baselines"
provides:
  - "AnomalyEvaluator.evaluate(int transactionId, User) — the single entry point shared by Plan 04's reactive job / backfill / safety-net sweep"
  - "RobustStatistics helper (median + MAD + MAD-floored robust-z + p95 + sensitivity->k clamp curve) with every tunable as a named constant"
  - "Three detectors: LargeVsTypicalDetector, FirstTimeMerchantDetector, DuplicateChargeDetector"
  - "RecurringSeriesQuery::seriesMembershipForTransactionIds() new Public method (txn_id => bool, user-scoped, batched)"
  - "AnomalyAlertOpened Public event (reasons[] + baseline trio)"
  - "Evaluator + three detectors registered as singletons in AnomalyServiceProvider"
  - "AnomalyCorpusSeeder test helper materialising fixtures into real rows"
affects: [09-03-read-write-surface, 09-04-jobs, 09-05-ui]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Robust dispersion (median + k×MAD) over mean+σ for sparse per-merchant baselines, with per-category p95 fallback for thin history"
    - "Single user-tunable sensitivity knob mapped to a k multiplier via a named, clamped curve (50% -> k=3.0)"
    - "One alert per transaction carrying a canonical-ordered reasons[] (D-16), with suppression-rule filtering BEFORE insert (D-17)"
    - "Settled-currency-only comparison so multi-currency merchants never spuriously flag (Pitfall 5)"
    - "Detector classes consumed by an orchestrating evaluator that owns only the idempotent insert + dispatch seam"

key-files:
  created:
    - Modules/Anomaly/Internal/Support/RobustStatistics.php
    - Modules/Anomaly/Internal/Detectors/LargeVsTypicalDetector.php
    - Modules/Anomaly/Internal/Detectors/FirstTimeMerchantDetector.php
    - Modules/Anomaly/Internal/Detectors/DuplicateChargeDetector.php
    - Modules/Anomaly/Internal/AnomalyEvaluator.php
    - Modules/Anomaly/Public/Events/AnomalyAlertOpened.php
    - Modules/Anomaly/tests/Support/AnomalyCorpusSeeder.php
    - Modules/Anomaly/tests/Unit/RobustStatisticsTest.php
    - Modules/Anomaly/tests/Unit/AnomalyEvaluatorTest.php
    - Modules/Anomaly/tests/Unit/AnomalyFxInvariantTest.php
    - Modules/Anomaly/tests/Unit/AnomalyEvaluatorFirstTimeTest.php
    - Modules/Anomaly/tests/Unit/AnomalyEvaluatorDuplicateTest.php
    - Modules/Anomaly/tests/Unit/AnomalyMinFloorTest.php
    - Modules/Anomaly/tests/Unit/AnomalyDedupTest.php
    - Modules/Anomaly/tests/Feature/AnomalySuppressionSkipTest.php
  modified:
    - Modules/Recurring/Public/Services/RecurringSeriesQuery.php
    - Modules/Anomaly/Providers/AnomalyServiceProvider.php
    - Modules/Recurring/tests/Feature/RecurringCounterpartyLinkTest.php

key-decisions:
  - "first-time-large carries BOTH `first_time` and `large` reasons — a brand-new merchant has no per-merchant baseline for LargeVsTypical to judge, so the first-time detector's large-vs-overall finding IS the large evidence (the evaluator adds `large` when first-time fires)"
  - "FirstTimeMerchantDetector judges large-vs-overall with a lower OVERALL_HISTORY_MIN (3) than the per-merchant THIN_HISTORY_CUTOFF (5): the overall distribution is the user's whole spend, so a handful of points already establishes a typical band"
  - "Suppression matching at evaluation time tolerates a NULL counterparty_id rule (normalized-name fallback merchants) via the where(counterparty_id = X OR counterparty_id IS NULL) branch"
  - "Reasons are canonically ordered (large, first_time, duplicate) before persistence so the stored JSON is deterministic regardless of detection order"

patterns-established:
  - "RobustStatistics: all statistical tunables (12-month window, <5 thin cutoff, 1.4826, p95, k-curve coefficients, MAD floor) are named class constants — one-line re-tunable after D-14 backfill triage"
  - "Detectors are final readonly, inject DatabaseManager/Clock(/RecurringSeriesQuery), read transactions directly with explicit user_id scoping, never write transactions"
  - "Evaluator clones the DriftEvaluator idempotency seam re-keyed to UNIQUE(transaction_id)"

requirements-completed: [ANOM-01]

# Metrics
duration: ~14 min
completed: 2026-06-13
---

# Phase 9 Plan 02: Anomaly Evaluator + Three Detectors Summary

**AnomalyEvaluator orchestrating three net-new detectors — large-vs-typical (median + k×MAD per-counterparty with per-category p95 fallback), first-time-merchant (large-AND-first-time, D-09), and duplicate-charge (7-day window with recurring-series exclusion, D-10) — aggregating one canonical multi-reason alert per transaction, filtering suppression rules before insert (D-17), and staying idempotent on UNIQUE(transaction_id) (D-16).**

## Performance

- **Duration:** ~14 min
- **Started:** 2026-06-13T17:37:23Z
- **Completed:** 2026-06-13
- **Tasks:** 3 (all TDD: RED -> GREEN -> verify)
- **Files modified:** 18 (15 created, 3 modified)

## Accomplishments
- `RobustStatistics` pure-math helper: `median`, `mad`, MAD-floored `robustZ`, `percentile`, and `kForSensitivity` (clamp curve, 50% -> 3.0) with every design tunable as a named constant (`WINDOW_MONTHS=12`, `THIN_HISTORY_CUTOFF=5`, `MAD_CONSISTENCY=1.4826`, `CATEGORY_PERCENTILE=95.0`, the k-curve coefficients, `MAD_FLOOR_MINOR`).
- `LargeVsTypicalDetector`: per-counterparty robust z-score (≥5 samples) with per-category p95 fallback for thin history; settled-currency-only comparison (Pitfall 5 / T-09-07); min-floor gate (D-11); explicit `where('user_id', ...)` on every query (T-09-05).
- `FirstTimeMerchantDetector`: fires ONLY when first-time AND large-vs-overall (D-09); `DuplicateChargeDetector`: same counterparty + exact settled amount/currency/direction within 7 days, excluding pairs where BOTH sides are approved-recurring-series members (D-10) via the new Public membership method.
- `RecurringSeriesQuery::seriesMembershipForTransactionIds()` — batched, user-scoped txn_id => bool map; the only cross-module crossing the duplicate detector needs, through Recurring's Public surface.
- `AnomalyEvaluator`: reasons aggregation into one alert (D-16), suppression filtering before insert (D-17), the cloned DriftEvaluator idempotency seam re-keyed to UNIQUE(transaction_id), and `AnomalyAlertOpened` dispatch on success — all reading `transactions` directly, never writing it (arch invariant stays green).
- All three quality gates green: 150 Pest tests (full Anomaly suite + BoundaryArchTest + Recurring membership tests), Pint clean, PHPStan L10 strict clean on all eight touched source files.

## Task Commits

Each task committed atomically (TDD):

1. **Task 1: Robust statistics + LargeVsTypicalDetector** - `0e6d336` (feat)
2. **Task 2: FirstTime + Duplicate detectors + RecurringSeriesQuery membership** - `00f46f0` (feat)
3. **Task 3: AnomalyEvaluator orchestration + suppression + dispatch** - `4f798c8` (feat)

**Plan metadata:** _(this commit)_ (docs: complete plan)

## Files Created/Modified
See `key-files` frontmatter. Highlights:
- `Modules/Anomaly/Internal/Support/RobustStatistics.php` — the named-constant statistical kernel.
- `Modules/Anomaly/Internal/Detectors/*` — the three final-readonly detectors.
- `Modules/Anomaly/Internal/AnomalyEvaluator.php` — reasons aggregation + suppression filter + idempotent insert + dispatch.
- `Modules/Recurring/Public/Services/RecurringSeriesQuery.php` — new `seriesMembershipForTransactionIds()`.
- `Modules/Anomaly/tests/Support/AnomalyCorpusSeeder.php` — materialises the nine fixtures into real transactions/counterparties/categories/series/suppression rows.

## Decisions Made
- **first-time-large carries both `first_time` and `large`:** the fixture corpus (and D-09) require this. A brand-new merchant has no per-merchant baseline for `LargeVsTypicalDetector`, so the first-time detector's large-vs-overall comparison IS the large evidence; the evaluator records `large` when first-time fires (de-duplicated).
- **`OVERALL_HISTORY_MIN = 3` for large-vs-overall:** lower than the per-merchant thin cutoff (5) because the overall distribution is the user's entire spend — a few points already establish a typical band, and the first-time-large fixture (4 overall priors) must fire.
- **Suppression tolerates NULL-counterparty rules:** matching uses `counterparty_id = X OR counterparty_id IS NULL` so a normalized-name-fallback rule (unresolved merchant) still suppresses (RESEARCH §Suppression-Rule Model).
- **Canonical reason ordering:** reasons persist in `(large, first_time, duplicate)` order so the stored JSON is deterministic.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Shared corpus-seed test helper instead of per-file free functions**
- **Found during:** Task 2 (first cross-file reuse of the corpus seeder)
- **Issue:** Task 1's `AnomalyEvaluatorTest` defined `anomalyCorpusUser()` / `anomalyTxnRow()` as Pest free functions. Pest scopes free functions per test file at include time, so Task 2's new test files (`AnomalyMinFloorTest` etc.) called undefined functions when run in isolation.
- **Fix:** Promoted both helpers to static methods on `AnomalyCorpusSeeder` (`makeUser()`, `transactionRow()`) and pointed every test file at them. No production code affected.
- **Files modified:** Modules/Anomaly/tests/Support/AnomalyCorpusSeeder.php + the five corpus-driven test files.
- **Verification:** All detector/evaluator suites green when run individually and together.
- **Committed in:** `00f46f0` (Task 2 commit)

**2. [Rule 1 - Bug] `large` reason missing from first-time-large alerts**
- **Found during:** Task 3 (multi-reason aggregation test)
- **Issue:** The plan's evaluator sketch only added `large` from `LargeVsTypicalDetector`. For a baseline-less new merchant that detector returns null (no per-counterparty or category history), so first-time-large produced only `['first_time']` — contradicting the fixture's `['large','first_time']` and D-09's intent ("first-time fires only when also large").
- **Fix:** When the first-time detector fires (which by definition means large-vs-overall), the evaluator also records the `large` reason (de-duplicated), then canonically orders reasons.
- **Files modified:** Modules/Anomaly/Internal/AnomalyEvaluator.php
- **Verification:** `AnomalySuppressionSkipTest` multi-reason case asserts the alert contains both `large` and `first_time`.
- **Committed in:** `4f798c8` (Task 3 commit)

**3. [Rule 3 - Blocking] PHPStan L10 strict fixes on touched files**
- **Found during:** Tasks 1–3 (per-task quality gate)
- **Issue:** L10 strict rejected `cast.int` on mixed transaction-row values, a dynamic call to the static `CarbonImmutable::parse()`, untyped `when()` closure params (`method.nonObject`), and a redundant `array_values()` on an already-list.
- **Fix:** Added `toInt`/`toIntOrNull` coercion helpers, switched to `CarbonImmutable::parse()` directly, type-hinted the `when()` closures with `Illuminate\Database\Query\Builder`, and dropped the redundant `array_values()`.
- **Files modified:** LargeVsTypicalDetector.php, DuplicateChargeDetector.php, AnomalyEvaluator.php
- **Verification:** PHPStan clean on all eight touched source files.
- **Committed in:** `0e6d336` / `00f46f0` / `4f798c8` (respective task commits)

---

**Total deviations:** 3 auto-fixed (2 blocking test-harness/static-analysis, 1 correctness bug)
**Impact on plan:** All necessary for correctness and the quality gates. Deviation 2 is the one semantic clarification — it makes the evaluator match both the fixture contract and D-09. No scope creep.

## Issues Encountered
- PHPStan was scoped to the touched paths with `php -d memory_limit=2-3G ./vendor/bin/phpstan analyse <files>` per the project memory note about host fd/memory limits on whole-repo runs. Clean on every touched file.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- The evaluator's single `evaluate(int $transactionId, User $user)` entry point is ready for Plan 04 to hang the reactive `DetectAnomaliesJob` (on `TransactionImported`), the full-history backfill, and the safety-net sweep on — one code path, no duplicate detection logic.
- The statistical constants (k-curve, window, thin cutoff, duplicate window, MAD floor) are all one-line tunable should D-14 backfill triage reveal a noisy/quiet default.
- Plan 03 (read/write surface) can build `AnomalyAlertQuery` + Public Actions on top of the alerts the evaluator now produces; `AnomalyAlertOpened` is available for badge/forecasting listeners.

## Known Stubs
None — the evaluator and all three detectors are fully wired against real `transactions` reads and the suppression table; no placeholder data paths remain. (The service provider retains explicit `TODO(Plan 03)` notes for the not-yet-built Public query/actions, which is the planned wave boundary, not a stub in this plan's deliverables.)

## Self-Check: PASSED
- All six created source files + the SUMMARY verified present on disk.
- Task commits `0e6d336`, `00f46f0`, `4f798c8` present in `git log`.
- Plan verification re-run: `./vendor/bin/pest Modules/Anomaly tests/Contracts/BoundaryArchTest.php Modules/Recurring/tests/Feature/RecurringCounterpartyLinkTest.php` -> 150 passed (490 assertions). Pint clean (--test), PHPStan L10 strict clean on all eight touched source files.

---
*Phase: 09-unusual-charge-anomaly-alerts*
*Completed: 2026-06-13*
