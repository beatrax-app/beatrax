---
phase: 9
slug: subscription-drift-detection-alerts
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-17
---

# Phase 9 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (built on PHPUnit 11) |
| **Config file** | `phpunit.xml` (+ `Modules/DriftAlerts/tests/Pest.php` when module Pest entry is added) |
| **Quick run command** | `vendor/bin/pest --filter=DriftAlerts` |
| **Full suite command** | `vendor/bin/pest` |
| **Estimated runtime** | ~30 seconds quick / ~90 seconds full |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/pest --filter=DriftAlerts`
- **After every plan wave:** Run `vendor/bin/pest && vendor/bin/phpstan analyse --memory-limit=2G && vendor/bin/pint --test`
- **Before `/gsd:verify-work`:** Full suite green + Larastan level 10 strict green + Pint --test green
- **Max feedback latency:** ~30 seconds

---

## Per-Task Verification Map

> Populated by the planner during step 8. Each task in PLAN.md MUST have an `<automated>` verify command OR a Wave 0 fixture dependency the planner records here.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 9-01-01 | 01 | 0 | REC-06 | T-9-01 / — | DriftAlerts module structure + ServiceProvider DI graph wires from container | unit | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` | ❌ W0 | ⬜ pending |
| 9-01-02 | 01 | 0 | REC-06 | T-9-02 / — | All 4 (or 5) new BoundaryArchTest invariants assert correctly on synthetic violations | unit | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter=DriftAlerts` | ❌ W0 | ⬜ pending |
| 9-01-03 | 01 | 0 | REC-06, REC-07, REC-08 | — | Wave 0 fixture corpus (24 scenarios per RESEARCH.md) is loadable and asserts shape | unit | `vendor/bin/pest Modules/DriftAlerts/tests/Unit/FixtureCorpusTest.php` | ❌ W0 | ⬜ pending |
| 9-02-01 | 02 | 1 | REC-06 | — | `drift_alerts` migration creates table with all required columns + indexes | unit | `vendor/bin/pest --filter=DriftAlertsMigrationTest` | ❌ W0 | ⬜ pending |
| 9-02-02 | 02 | 1 | REC-08 | — | `drift_alert_transitions` migration mirrors Phase 8 D-815 shape | unit | `vendor/bin/pest --filter=DriftAlertTransitionsMigrationTest` | ❌ W0 | ⬜ pending |
| 9-02-03 | 02 | 1 | REC-06 | — | `users.drift_alert_threshold_percent` + `recurring_series.drift_threshold_percent` migrations apply idempotently | unit | `vendor/bin/pest --filter=DriftThresholdMigrationsTest` | ❌ W0 | ⬜ pending |
| 9-02-04 | 02 | 1 | REC-06 | — | DriftAlert + DriftAlertTransition Eloquent models + DTOs round-trip | unit | `vendor/bin/pest Modules/DriftAlerts/tests/Unit/DriftAlertDtoTest.php` | ❌ W0 | ⬜ pending |
| 9-02-05 | 02 | 1 | REC-08 | T-9-03 | DriftAlertStateMachine transitions write audit row + reject illegal transitions | unit | `vendor/bin/pest Modules/DriftAlerts/tests/Unit/DriftAlertStateMachineTest.php` | ❌ W0 | ⬜ pending |
| 9-03-01 | 03 | 2 | REC-06 | — | DriftEvaluator computes signed delta + annualized impact correctly across cadences (monthly/quarterly/yearly/weekly × 52) | unit | `vendor/bin/pest Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php` | ❌ W0 | ⬜ pending |
| 9-03-02 | 03 | 2 | REC-06 | — | DriftEvaluator skips prior_amount = 0 / NULL without divide-by-zero | unit | `vendor/bin/pest --filter=DriftEvaluatorEdgeCases` | ❌ W0 | ⬜ pending |
| 9-03-03 | 03 | 2 | REC-06 | T-9-04 | DriftEvaluator compares ORIGINAL currency only; FX-only EUR swings never fire | unit | `vendor/bin/pest --filter=DriftEvaluatorFxInvariant` | ❌ W0 | ⬜ pending |
| 9-03-04 | 03 | 2 | REC-06 | — | DriftEvaluator fires on BOTH approved AND cadence_changed series | unit | `vendor/bin/pest --filter=DriftEvaluatorCadenceChangedTest` | ❌ W0 | ⬜ pending |
| 9-03-05 | 03 | 2 | REC-06 | — | `DetectDriftAlertsJob` is `ShouldBeUniqueUntilProcessing` keyed `(user_id, recurring_series_id)` | unit | `vendor/bin/pest --filter=DetectDriftAlertsJobUniqueTest` | ❌ W0 | ⬜ pending |
| 9-03-06 | 03 | 2 | REC-06 | — | Listener wires `RecurringSeriesMetricsRefreshed` → `DetectDriftAlertsJob` dispatch | unit | `vendor/bin/pest --filter=EvaluateDriftListenerTest` | ❌ W0 | ⬜ pending |
| 9-03-07 | 03 | 2 | REC-06, REC-07, REC-08 | — | End-to-end against 24-scenario fixture corpus produces expected alert rows | contract | `vendor/bin/pest tests/Contracts/DriftDetectionContractTest.php` | ❌ W0 | ⬜ pending |
| 9-04-01 | 04 | 3 | REC-07 | T-9-05 | `/drift` page renders open alerts sorted by `detected_at DESC` grouped by series | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/DriftPageTest.php` | ❌ W0 | ⬜ pending |
| 9-04-02 | 04 | 3 | REC-08 | T-9-06 | AcknowledgeDriftAlert action flips state + writes transition + emits event | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/AcknowledgeDriftAlertTest.php` | ❌ W0 | ⬜ pending |
| 9-04-03 | 04 | 3 | REC-08 | T-9-07 | SnoozeDriftAlert action writes snoozed_until + reuses Phase 8 date picker | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/SnoozeDriftAlertTest.php` | ❌ W0 | ⬜ pending |
| 9-04-04 | 04 | 3 | REC-08 | T-9-08 | DismissDriftAlertAsCancelled action flips state + writes transition + emits event | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/DismissDriftAlertTest.php` | ❌ W0 | ⬜ pending |
| 9-04-05 | 04 | 3 | REC-07 | T-9-09 | Cross-user 404 invariant: user A cannot act on user B's drift alerts | feature | `vendor/bin/pest --filter=DriftAlertsCrossUser404Test` | ❌ W0 | ⬜ pending |
| 9-04-06 | 04 | 3 | REC-07 | — | Dashboard drift count badge composer renders count for current user only | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/DashboardDriftBadgeTest.php` | ❌ W0 | ⬜ pending |
| 9-04-07 | 04 | 3 | REC-07 | — | Top-nav drift indicator renders correctly (per UI-SPEC D-927 decision) | feature | `vendor/bin/pest --filter=TopNavDriftIndicatorTest` | ❌ W0 | ⬜ pending |
| 9-05-01 | 05 | 4 | REC-06 | — | CancellationImpactQuery returns `{annual_savings_minor, monthly_savings_minor, currency}` in `latest_currency` | unit | `vendor/bin/pest Modules/DriftAlerts/tests/Unit/CancellationImpactQueryTest.php` | ❌ W0 | ⬜ pending |
| 9-05-02 | 05 | 4 | REC-06 | — | Inline "save €X/yr" displays original-currency primary + EUR shadow when distinct | feature | `vendor/bin/pest --filter=CancellationImpactDisplayTest` | ❌ W0 | ⬜ pending |
| 9-05-03 | 05 | 4 | REC-06 | — | Per-series threshold override editor persists to `recurring_series.drift_threshold_percent` | feature | `vendor/bin/pest --filter=DriftThresholdOverrideEditorTest` | ❌ W0 | ⬜ pending |
| 9-05-04 | 05 | 4 | REC-06 | — | `/settings` global threshold field persists to `users.drift_alert_threshold_percent` | feature | `vendor/bin/pest --filter=GlobalDriftThresholdSettingTest` | ❌ W0 | ⬜ pending |
| 9-05-05 | 05 | 4 | REC-07 | — | Snoozed-alert revival (hybrid: hourly sweep + query-time conditional per RESEARCH D-925) restores alerts to `open` after `snoozed_until` | feature | `vendor/bin/pest --filter=SnoozedAlertRevivalTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

> Planner refines task IDs + binds exact REQ-IDs/T-9-XX threat refs in each PLAN.md. Wave 0 (`❌ W0`) entries become `✅` once the corresponding test file is created.

---

## Wave 0 Requirements

- [ ] `Modules/DriftAlerts/tests/Pest.php` — module Pest entry + PSR-4 autoload-dev + phpunit.xml testsuite (3-step pattern carry-forward)
- [ ] `Modules/DriftAlerts/tests/fixtures/` — 24-scenario synthesised drift corpus per RESEARCH.md:
  - stable (no alert)
  - small drift below threshold (no alert)
  - large drift expense up (alert)
  - large drift expense down (alert)
  - income raise (alert green-leaning)
  - income cut (alert red-leaning)
  - FX-only EUR swing (NO alert — D-909)
  - cadence-changed series (alert — D-910)
  - multi-drift series (multiple open alerts — D-904)
  - per-series threshold override (uses series-level threshold — D-915)
  - prior_amount = NULL (no alert — divide-by-zero guard)
  - prior_amount = 0 (no alert — divide-by-zero guard)
  - volatile series with default threshold (alert avalanche scenario)
  - volatile series with widened per-series override (no alerts)
  - weekly cadence (alert with × 52 multiplier)
  - quarterly cadence (alert with × 4 multiplier)
  - yearly cadence (alert with × 1 multiplier)
  - mixed-currency stable USD $11.99 → $11.99 (no alert despite EUR drift)
  - mixed-currency drift USD $11.99 → $14.99 (alert)
  - pending series (excluded)
  - rejected series (excluded)
  - series-level snoozed series (excluded)
  - irregular series (excluded)
  - snooze-expiry revival (snoozed alert returns to open after `snoozed_until`)
- [ ] `tests/Contracts/DriftDetectionContractTest.php` — end-to-end Pest contract test over the 24 scenarios
- [ ] `Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php` — Pest dataset `(prior, latest, threshold, cadence, currency) → expected_alert`
- [ ] BoundaryArchTest extension: 4 invariants from D-902 + recommended 5th (`noOtherDriftAlertStateMutator`) per RESEARCH.md
- [ ] `Modules/DriftAlerts/Database/Factories/` — DriftAlertFactory + DriftAlertTransitionFactory mirroring Phase 8 factories

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| `/drift` visual layout (calm aesthetic, grouped-by-series collapsible) | REC-07 | Visual / Tailwind / Flux primitive choice (D-930) — automated arch tests can't assert calm aesthetic | After Wave 3 ship, open `https://diederik.test/drift`, verify: (a) count badge shows on dashboard, (b) grouped-by-series collapse works, (c) direction-aware copy renders expense vs income, (d) original-currency primary + EUR shadow when distinct |
| Snooze date picker reuse from Phase 8 | REC-08 | UI component reuse — automated test asserts hook into `SnoozeDriftAlert` action, not visual reuse | After Wave 3 ship, click "Snooze" on a drift alert, verify Phase 8's snooze date-picker component appears (no second snooze UI) |
| Top-nav drift indicator placement | REC-07 | UI-SPEC owns final decision on D-927 (own slot vs secondary on Recurring) | After Wave 3 ship, verify top-nav drift indicator matches UI-SPEC.md decision |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (24-scenario fixture corpus + arch invariants + factories)
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
