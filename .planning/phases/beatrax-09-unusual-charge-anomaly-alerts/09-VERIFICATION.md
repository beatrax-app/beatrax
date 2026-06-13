---
phase: 09-unusual-charge-anomaly-alerts
verified: 2026-06-13T00:00:00Z
status: passed
score: 30/30 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: null
gaps: []
---

# Phase 9: Unusual-charge / anomaly alerts Verification Report

**Phase Goal:** The system proactively flags charges that deviate from the user's baseline, surfaced through the existing alerts plumbing.
**Verified:** 2026-06-13
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Success Criteria (ROADMAP contract)

| # | Criterion | Status | Evidence |
| - | --------- | ------ | -------- |
| SC-1 | System flags charges unusual vs baseline (large-vs-typical, first-time merchant) | ✓ VERIFIED | `AnomalyEvaluator` (279 lines) orchestrates 3 detectors; `AnomalyEvaluatorTest`, `...FirstTimeTest`, `...DuplicateTest` all pass — large/first-time/duplicate reasons fire exactly per corpus |
| SC-2 | Anomaly flags surface through the existing alerts surface and are dismissible/acknowledgeable | ✓ VERIFIED | `/drift` carries `#[Url(as:'type')]` switch consuming `AnomalyAlertQuery`; 4 Public Actions (ack/snooze/dismiss/dismiss-as-expected) wired through sole-mutator state machine; UI feature tests (AnomalyAlertsHome/DashboardBadge/TopNavBadge/SettingsSection) pass; browser-MCP human UAT PASSED |

### Observable Truths

| # | Truth | Status | Evidence |
| - | ----- | ------ | -------- |
| 1 | Dedicated Anomaly module mirroring DriftAlerts (Query/Actions/Job/StateMachine/DTO), not an extension of drift_alerts | ✓ VERIFIED | Full `Modules/Anomaly/` tree present with Public/Internal split; arch invariant `Internal isolation` passes |
| 2 | anomaly_alerts table with UNIQUE(transaction_id) + state trigger pair | ✓ VERIFIED | Migration 010001 passes artifact check (`anomaly_alerts_uniq`); idempotent-insert no-op proven by AnomalyDedupTest |
| 3 | append-only anomaly_alert_transitions audit table | ✓ VERIFIED | Migration 010002 + `transitions()` HasMany link verified |
| 4 | anomaly_suppression_rules per-user (merchant + band + detector + direction) | ✓ VERIFIED | Migration 010003 + AnomalySuppression tests pass |
| 5 | users carries anomaly_sensitivity_percent / anomaly_min_amount_minor / anomaly_backfilled_at | ✓ VERIFIED | Migration 010004 + AnomalySettingsMigrationTest pass |
| 6 | AnomalyAlertStateMachine sole legal mutator, allows dismissed->open | ✓ VERIFIED | `ALLOWED_TRANSITIONS` has `'dismissed' => ['open']`; arch invariant `noOtherAnomalyAlertStateMutator` passes |
| 7 | 3 arch invariants pass (Internal isolation, sole-mutator, no transaction writes) | ✓ VERIFIED | 3 passed in BoundaryArchTest (`--filter=nomaly`) |
| 8 | Exactly 3 detectors evaluating expenses + income | ✓ VERIFIED | LargeVsTypical / FirstTimeMerchant / DuplicateCharge detectors present + tested |
| 9 | large-vs-typical fires on robust-z over 12mo per-counterparty with per-category fallback, k(sensitivity) tunable | ✓ VERIFIED | RobustStatistics (`1.4826` MAD constant) + sensitivity->k=3.0 mapping test passes |
| 10 | first-time fires ONLY when also large vs overall spend (D-09) | ✓ VERIFIED | FirstTimeTest: fires for large-new-merchant, NOT for small/typical new merchant |
| 11 | duplicate fires same counterparty + exact amount within 7d, excluding same-recurring-series | ✓ VERIFIED | DuplicateTest: fires in 7d window, NOT when both on approved recurring series, fires on later sibling only |
| 12 | min-amount floor gates all three detectors (D-11) | ✓ VERIFIED | AnomalyMinFloorTest passes |
| 13 | one alert per transaction with all tripped reasons; UNIQUE collision silent no-op (D-16) | ✓ VERIFIED | AnomalyDedupTest passes; `insertGetId|QueryException` key-link verified |
| 14 | comparison uses settled/base currency consistently (no spurious FX flags) | ✓ VERIFIED | AnomalyFxInvariantTest passes |
| 15 | matching suppression rule prevents insert (checked BEFORE insert, D-17) | ✓ VERIFIED | AnomalySuppressionSkipTest passes; `anomaly_suppression_rules` key-link verified |
| 16 | AnomalyAlertQuery open/history/dismissed + openCount per-user + snooze-revival conditional | ✓ VERIFIED | `applyOpenStateFilter` present; AnomalyAlertsHomeTest passes |
| 17 | full drift-parity lifecycle via sole-mutator + audit (ack=History, dismissed=Dismissed, snooze=defer) | ✓ VERIFIED | State machine `acknowledged => []` terminal; transition writes audit row |
| 18 | Public Actions transition via state machine + throw NotFoundHttpException cross-user | ✓ VERIFIED | AnomalyAlertCrossUser404Test: ack/snooze/dismiss all 404 cross-user, leave state unchanged |
| 19 | DismissAnomalyAlertAsExpected dismisses AND inserts ±15% band (D-17) | ✓ VERIFIED | `0.85`/`1.15` multipliers + `source_anomaly_alert_id` provenance; CR-01 fix adds settled-charge fallback for null latest_amount |
| 20 | RemoveAnomalySuppressionRule deletes rule; undo additionally re-opens via dismissed->open (D-18) | ✓ VERIFIED | AnomalySuppressionUndoTest: undo deletes+reopens, settings Remove deletes only, both 404 cross-user |
| 21 | AnomalySuppressionRuleQuery lists user rules for settings | ✓ VERIFIED | Service present, AnomalySettingsSectionTest passes |
| 22 | TransactionImported queues unique DetectAnomaliesJob, never inline (D-12) | ✓ VERIFIED | Provider listens `TransactionImported` -> EvaluateAnomaliesOnTransactionImport; `uniqueId` on job |
| 23 | BackfillAnomaliesJob lazyById full history, lands Open, idempotent+resumable, no-op once backfilled_at set | ✓ VERIFIED | `lazyById` present; BackfillAnomaliesJobTest passes; WR-01 concurrent-double-walk fixed |
| 24 | ReviveExpiredAnomalySnoozesJob flips expired snoozes to open + audit row, hourly | ✓ VERIFIED | AnomalySnoozeRevivalTest passes; `anomaly.revive-snoozes` scheduled hourly |
| 25 | SafetyNetAnomalySweepJob re-evaluates recently-imported-unalerted | ✓ VERIFIED | SafetyNetAnomalySweepTest passes; `anomaly.safety-net-sweep` scheduled hourly |
| 26 | console.php registers both jobs with .name()->hourly()->withoutOverlapping() | ✓ VERIFIED | Both entries confirmed with correct ordering at lines 210/227 |
| 27 | /drift type switch (drift default, ?type=anomaly renders anomaly rows under tabs) (D-02) | ✓ VERIFIED | `#[Url(as:'type', except:'drift')]`; anomaly branch in render(); browser UAT confirmed |
| 28 | Anomaly rows show reason chips + baseline->actual + 4 action chips wired to Public Actions | ✓ VERIFIED | anomaly-alert-row.blade.php present; UAT confirmed each action fires + decrements reactive badge |
| 29 | Separate dashboard tile + amber nav/sidebar badge for open count, distinct from drift (D-03) | ✓ VERIFIED | DashboardAnomalyBadge component + sidebar `.side-badge.alert` -> `?type=anomaly`; Dashboard/TopNav badge tests pass |
| 30 | Settings Anomaly detection section (sensitivity/floor/removable suppression list); first enable dispatches Backfill (D-11/D-18) | ✓ VERIFIED | AnomalySettingsSection dispatches `BackfillAnomaliesJob` on first save while backfilled_at null; SettingsSectionTest passes |

**Score:** 30/30 truths verified

### Required Artifacts

All 17 declared artifacts across 5 plans passed existence + substantive checks (gsd-sdk verify.artifacts: 5/5, 3/3, 3/3, 3/3, 3/3). Spot-confirmed substantive: AnomalyEvaluator 279 lines, RobustStatistics has `1.4826`, DismissAnomalyAlertAsExpected has `0.85`/`1.15`, StateMachine has `dismissed => ['open']`.

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| AnomalyServiceProvider | Migrations | loadMigrationsFrom | ✓ WIRED | |
| AnomalyAlert | transitions | HasMany | ✓ WIRED | |
| AnomalyEvaluator | anomaly_alerts | insertGetId/QueryException | ✓ WIRED | |
| (DuplicateChargeDetector) | RecurringSeriesQuery | seriesMembershipForTransactionIds | ✓ WIRED | SDK flagged false-negative — call lives in `DuplicateChargeDetector.php:114` (a detector orchestrated by the evaluator), not literally in AnomalyEvaluator.php. Recurring Public method exists at RecurringSeriesQuery.php:318. Real, correct Public crossing. |
| AnomalyEvaluator | anomaly_suppression_rules | isSuppressed before insert | ✓ WIRED | |
| AcknowledgeAnomalyAlert | StateMachine | transition('acknowledged') | ✓ WIRED | |
| AnomalyAlertQuery | CounterpartyProfileQuery | identitiesForIds | ✓ WIRED | |
| DismissAnomalyAlertAsExpected | anomaly_suppression_rules | source_anomaly_alert_id | ✓ WIRED | |
| Provider | TransactionImported | events->listen | ✓ WIRED | |
| DetectAnomaliesJob | AnomalyEvaluator | handle->evaluate | ✓ WIRED | |
| console.php | ReviveExpiredAnomalySnoozesJob | anomaly.revive-snoozes hourly | ✓ WIRED | |
| DriftPage | AnomalyAlertQuery | method-param DI | ✓ WIRED | |
| AnomalySettingsSection | BackfillAnomaliesJob | dispatch on first enable | ✓ WIRED | |
| app-sidebar | drift.index?type=anomaly | nav item + .side-badge.alert | ✓ WIRED | |

14/14 declared key links wired (the single SDK false-negative resolved by manual trace).

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Full Anomaly module suite | `pest Modules/Anomaly/tests` | 173 passed (650 assertions) | ✓ PASS |
| Arch invariants | `pest BoundaryArchTest --filter=nomaly` | 3 passed | ✓ PASS |
| Detector firing (3 detectors) | Evaluator/FirstTime/Duplicate tests | 11 passed — all reasons fire per corpus | ✓ PASS |
| Cross-user isolation | AnomalyAlertCrossUser404Test | 3 passed — all 404, state preserved | ✓ PASS |
| Suppression undo/remove (D-18) | AnomalySuppressionUndoTest | 4 passed | ✓ PASS |
| UI surfaces | Home/DashboardBadge/TopNavBadge/Settings | 20 passed (64 assertions) | ✓ PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ----------- | ----------- | ------ | -------- |
| ANOM-01 | 09-01,02,04 | System flags charges unusual vs baseline (large-vs-typical, first-time merchant) | ✓ SATISFIED | 3 detectors + evaluator + reactive/backfill/sweep runtime, all tested |
| ANOM-02 | 09-01,03,05 | Anomaly flags surface through existing alerts surface, dismissible/acknowledgeable | ✓ SATISFIED | /drift type switch + 4 Public Actions + state machine + dashboard/sidebar/settings surfaces, all tested + UAT |

No orphaned requirements: REQUIREMENTS.md maps only ANOM-01/ANOM-02 to Phase 9, both claimed in plans, both marked Complete.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| AnomalyServiceProvider.php | 44 | "Plan 05 TODO stubs" in docblock | ℹ️ Info | Stale narrative comment from Plan 04 describing pending Plan 05 work; the referenced registrations (Livewire components, badge) were actually implemented in Plan 05 and are wired (confirmed in source). Not live debt; not auditability-blocking. No `TBD`/`FIXME`/`XXX` markers found anywhere in Anomaly src. |

`return []`/`return null` matches in Public services are legitimate empty-result/early-return guards (e.g. `if ($rows->isEmpty()) return [];`), not stubs — each method has a real query path below the guard.

### Code Review Posture

09-REVIEW found 2 BLOCKERs (CR-01, CR-02) + 7 WARNINGs + IN-03; 09-REVIEW-FIX shows all 10 in-scope fixed (status: all_fixed) with +18 regression tests. CR-01 (suppression band silently not written for duplicate-only/first-time-only alerts) fix proven by DismissExpectedFallbackBandTest closing the D-17 gap. CR-02 (synthetic large reason muted by per-merchant bands) fix proven by SyntheticLargeNotSuppressedTest. PHPStan L10 strict + Pint clean per fix report.

### Human Verification

Already performed and PASSED via browser-MCP UAT (per phase context): /drift type switch, anomaly rows with reason chips, all 4 actions firing + decrementing reactive badge, Dashboard tile + amber sidebar badge, Settings section with server-computed ±15% suppression band + Remove, responsive markup. No outstanding human-verify items remain.

### Gaps Summary

None. Both ROADMAP success criteria are observably true in the codebase, all 30 derived/plan truths verified, all artifacts substantive, all key links wired, both requirements satisfied, arch boundaries enforced, and the two review blockers closed with regression coverage. The single SDK key-link false-negative was a pattern-location mismatch, not a missing wire. The only anti-pattern is a stale docblock comment with no functional impact.

---

_Verified: 2026-06-13_
_Verifier: Claude (gsd-verifier)_
