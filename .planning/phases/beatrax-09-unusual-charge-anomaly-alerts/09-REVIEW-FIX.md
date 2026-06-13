---
phase: beatrax-09-unusual-charge-anomaly-alerts
fixed_at: 2026-06-13T00:00:00Z
review_path: .planning/phases/beatrax-09-unusual-charge-anomaly-alerts/09-REVIEW.md
iteration: 1
findings_in_scope: 10
fixed: 10
skipped: 0
status: all_fixed
---

# Phase 9: Code Review Fix Report

**Fixed at:** 2026-06-13
**Source review:** 09-REVIEW.md
**Iteration:** 1
**Branch:** release/v1.3 (atomic conventional commits, scope `09`, hooks on)

**Summary:**
- Findings in scope: 10 (CR-01, CR-02, WR-01..WR-07, IN-03)
- Fixed: 10
- Skipped: 0
- Deliberately out of scope (per the task): IN-01, IN-02, IN-04, IN-05

**Quality gates after the fixes:**
- Anomaly suite + `tests/Contracts/BoundaryArchTest.php`: **231 passed** (742 assertions) — baseline was 213; +18 new tests.
- DriftAlerts suite (DriftPage was touched): **165 passed**, 3 todos.
- PHPStan L10 + strict across `Modules/Anomaly` and `Modules/DriftAlerts`: **No errors**.
- Pint: clean on all 20 touched files.
- Module boundaries: BoundaryArchTest green — every Anomaly→Ledger access stayed a READ; no new Transaction writes.

## Fixed Issues

### CR-01: "Mark as expected" creates no suppression rule for duplicate-only / first-time-only alerts
**Files modified:** `Modules/Anomaly/Public/Actions/DismissAnomalyAlertAsExpected.php`, `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php`, `Modules/Anomaly/tests/Feature/DismissExpectedFallbackBandTest.php`
**Commit:** a2f6587
**Applied fix:** When `latest_amount_minor` is null (the duplicate-only / first-time-only case), the action now falls back to the alert transaction's own `settled_amount_minor` + `settled_currency` via a new `settledChargeForTransaction()` sibling to `counterpartyIdForTransaction()` (a permitted ledger READ), so a ±15% band IS written. `__invoke` now returns whether a rule was written, and `DriftPage::markAnomalyExpected` only shows the "Suppression rule added — Undo" toast when one actually was (otherwise "Dismissed as expected"). Tests prove the fallback band is written for a duplicate-only alert AND that the next identical duplicate is then suppressed (the D-17 gap is closed).

### CR-02: First-time's synthetic `large` reason muted by per-merchant suppression bands
**Files modified:** `Modules/Anomaly/Internal/AnomalyEvaluator.php`, `Modules/Anomaly/tests/Feature/SyntheticLargeNotSuppressedTest.php`
**Commit:** 9c5d1e1
**Applied fix:** Tracks `$largeFromMerchantBaseline = $largeResult !== null` (true only when `LargeVsTypicalDetector` actually trips). `filterSuppressed` now excludes a synthetic (first-time-injected) `large` from the rule-matching detector set, so a per-merchant — or NULL-counterparty wildcard — `large` band can no longer mute a brand-new merchant's large-amount signal. The D-09 large+first_time coupling is preserved (`large` is never stripped while `first_time` survives). The headline test was **verified to fail without the provenance guard** (temporarily neutralised the guard → test red → restored → green); a second test confirms genuine merchant-baseline bands still suppress.
**Note:** Behaviour-affecting suppression-logic change — recommend a human eyeball on the provenance branch before the phase proceeds.

### WR-01: BackfillAnomaliesJob can double-walk full history under concurrent dispatch
**Files modified:** `Modules/Anomaly/Internal/Jobs/BackfillAnomaliesJob.php`, `Modules/Anomaly/tests/Feature/BackfillAnomaliesJobTest.php`
**Commit:** 0ad5443
**Applied fix:** `anomaly_backfilled_at` is now claimed via an atomic `whereNull(...)->update(...)` mutex BEFORE the history walk; a 0-row claim returns early. Documented the resumability trade-off (a crash mid-walk now no-ops on retry; the hourly SafetyNetAnomalySweepJob is the durable backstop). Added a claim test.
**Note:** Logic/concurrency change — the trade-off (no longer crash-resumable within the same job) is intentional and documented, but worth a human confirmation that the safety-net sweep coverage is acceptable.

### WR-02: Duplicate-charge window symmetric ±7 days → double-counts a duplicate pair
**Files modified:** `Modules/Anomaly/Internal/Detectors/DuplicateChargeDetector.php`, `Modules/Anomaly/tests/Unit/AnomalyEvaluatorDuplicateTest.php`
**Commit:** 24a4d65
**Applied fix:** The sibling window is now backward-only (`[anchor-7d, anchor]` with an `id < $thisId` tie-break for same-day siblings), so a genuine double-charge fires exactly once — on the later charge — across reactive / backfill / sweep paths. Test proves the later charge fires and the earlier sibling does not. (A same-day duplicate test was dropped: the `transactions` natural-key UNIQUE constraint makes two truly-identical same-day rows unrepresentable, so that scenario can't be seeded; the `id<` tie-break remains as defensive correctness.)

### WR-03: Snooze-revival sweep loads every expired snooze into memory at once
**Files modified:** `Modules/Anomaly/Internal/Jobs/ReviveExpiredAnomalySnoozesJob.php`, `Modules/Anomaly/tests/Feature/AnomalySnoozeRevivalTest.php`
**Commit:** 17bf696
**Applied fix:** The candidate scan now uses `orderBy('id')->lazyById(500)` (mirroring SafetyNetAnomalySweepJob); the per-row state-machine transition stays inside the chunk callback. Added a multi-row chunked-sweep test.

### WR-04: RobustStatistics percentile boundary can rank a charge equal to the historical max as NOT large
**Files modified:** `Modules/Anomaly/Internal/Support/RobustStatistics.php`, `Modules/Anomaly/Internal/Detectors/LargeVsTypicalDetector.php`, `Modules/Anomaly/Internal/Detectors/FirstTimeMerchantDetector.php`, `Modules/Anomaly/tests/Unit/RobustStatisticsTest.php`
**Commit:** 0282359
**Applied fix:** Deliberate boundary decision: **tie-inclusive (`>=`)** — a charge equal to the p95 fires, so a repeat of the largest-ever charge is not a silent false negative. Centralised the decision in a new `RobustStatistics::exceedsPercentile()` helper consumed by both detectors (no scattered `>=`). Pinned the tie behaviour with dataset tests (constant sample, thin sample, signed-safe, empty sample).
**Note:** This is a deliberate semantic boundary choice — flagged for human confirmation that "tie fires" is the intended policy for this feature.

### WR-05: madFloorFor casts `median * 0.01` through int, collapsing the context floor
**Files modified:** `Modules/Anomaly/Internal/Detectors/LargeVsTypicalDetector.php`, `Modules/Anomaly/tests/Unit/LargeVsTypicalMadFloorTest.php`
**Commit:** c286aa1
**Applied fix:** The 1%-of-median floor is now computed in float and cast to int only at the final denominator. The docblock is rewritten to match actual behaviour (the 1% scaling only engages above a €50 median; cheaper merchants get the flat `MAD_FLOOR_MINOR`). Added a dataset test pinning both regimes (sub-€50 collapse, above-€50 scaling, no fractional truncation before the comparison).

### WR-06: Snooze idempotency compares cast app-tz timestamp against caller offset
**Files modified:** `Modules/Anomaly/Public/Actions/SnoozeAnomalyAlert.php`, `Modules/Anomaly/tests/Feature/SnoozeAnomalyAlertTest.php`
**Commit:** 14b8712
**Applied fix:** The idempotency short-circuit now compares both sides through the same `toDateTimeString()` round-trip the persisted value uses, so a re-snooze to the same intended instant on a non-app source offset no longer writes a redundant transition + re-emits `AnomalyAlertSnoozed`. Added a WR-06 regression test (re-snooze with an America/New_York `$until` whose `getTimestamp()` differs but whose `toDateTimeString()` matches the stored value).

### WR-07: Suppression-rule insert not idempotent across undo→re-dismiss cycles
**Files modified:** `Modules/Anomaly/Public/Actions/DismissAnomalyAlertAsExpected.php`, `Modules/Anomaly/tests/Feature/AnomalySuppressionTest.php`
**Commit:** 4fe2bd1
**Applied fix:** Each per-reason insert is now guarded by a `whereNotExists`-equivalent `->exists()` check on the rule's natural key (user + counterparty + detector + direction + band + currency). Chosen over a UNIQUE index to avoid schema churn AND because a plain UNIQUE treats NULL counterparties as distinct (so it would not dedupe the name-fallback rules). Added a no-duplicate test (pre-seed an identical natural-key rule → re-dismiss writes nothing, count stays 1).

### IN-03: AnomalyAlertQuery default limit 26 is an unexplained magic number
**Files modified:** `Modules/Anomaly/Public/Services/AnomalyAlertQuery.php`
**Commit:** 5375d00
**Applied fix:** Promoted to `public const PAGE_SIZE_WITH_LOOKAHEAD = 26` with a one-line comment explaining the "25 rows + 1 look-ahead" cursor-pagination intent; all three method signatures default to the constant.

## Deliberately Skipped (per the task scope)

These were explicitly excluded from this fix pass (larger refactors / optional), per the objective. None was required to land any blocker/warning fix.

- **IN-01** (add empty-ledger backfill regression test) — optional test-only addition. The backfill no-op-on-empty-ledger behaviour is unchanged by WR-01 (the lazy walk simply yields nothing after the claim succeeds). Not added.
- **IN-02** (extract a shared `loadOwnedAlertOrFail` helper) — cross-action boilerplate de-dup; a structural refactor not needed by any fix.
- **IN-04** (NULL-counterparty wildcard semantics in `filterSuppressed`) — NOT changed. Note: CR-02 deliberately leans on the EXISTING NULL-counterparty wildcard behaviour for its headline test (a band-only NULL rule that would otherwise wildcard-match), so altering IN-04 here would have been entangled with CR-02. Left as a standalone future decision.
- **IN-05** (hoist the duplicated `directionFromType` / `typesForDirection` tables) — verbatim duplication across the evaluator + three detectors; a structural refactor, not required by any fix.

---

_Fixed: 2026-06-13_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
