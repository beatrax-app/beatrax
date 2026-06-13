---
phase: beatrax-09-unusual-charge-anomaly-alerts
reviewed: 2026-06-13T00:00:00Z
depth: deep
files_reviewed: 36
files_reviewed_list:
  - Modules/Anomaly/Internal/AnomalyEvaluator.php
  - Modules/Anomaly/Internal/Detectors/DuplicateChargeDetector.php
  - Modules/Anomaly/Internal/Detectors/FirstTimeMerchantDetector.php
  - Modules/Anomaly/Internal/Detectors/LargeVsTypicalDetector.php
  - Modules/Anomaly/Internal/Http/Livewire/AnomalySettingsSection.php
  - Modules/Anomaly/Internal/Http/Livewire/DashboardAnomalyBadge.php
  - Modules/Anomaly/Internal/Jobs/BackfillAnomaliesJob.php
  - Modules/Anomaly/Internal/Jobs/DetectAnomaliesJob.php
  - Modules/Anomaly/Internal/Jobs/ReviveExpiredAnomalySnoozesJob.php
  - Modules/Anomaly/Internal/Jobs/SafetyNetAnomalySweepJob.php
  - Modules/Anomaly/Internal/Listeners/EvaluateAnomaliesOnTransactionImport.php
  - Modules/Anomaly/Internal/Mapping/AnomalyAlertDtoMapper.php
  - Modules/Anomaly/Internal/StateMachines/AnomalyAlertStateMachine.php
  - Modules/Anomaly/Internal/StateMachines/InvalidStateTransitionException.php
  - Modules/Anomaly/Internal/Support/RobustStatistics.php
  - Modules/Anomaly/Models/AnomalyAlert.php
  - Modules/Anomaly/Models/AnomalyAlertTransition.php
  - Modules/Anomaly/Models/AnomalySuppressionRule.php
  - Modules/Anomaly/Providers/AnomalyServiceProvider.php
  - Modules/Anomaly/Public/Actions/AcknowledgeAnomalyAlert.php
  - Modules/Anomaly/Public/Actions/DismissAnomalyAlert.php
  - Modules/Anomaly/Public/Actions/DismissAnomalyAlertAsExpected.php
  - Modules/Anomaly/Public/Actions/RemoveAnomalySuppressionRule.php
  - Modules/Anomaly/Public/Actions/SnoozeAnomalyAlert.php
  - Modules/Anomaly/Public/Dto/AnomalyAlertDto.php
  - Modules/Anomaly/Public/Dto/AnomalySuppressionRuleDto.php
  - Modules/Anomaly/Public/Events/AnomalyAlertAcknowledged.php
  - Modules/Anomaly/Public/Events/AnomalyAlertDismissed.php
  - Modules/Anomaly/Public/Events/AnomalyAlertOpened.php
  - Modules/Anomaly/Public/Events/AnomalyAlertSnoozed.php
  - Modules/Anomaly/Public/Services/AnomalyAlertQuery.php
  - Modules/Anomaly/Public/Services/AnomalySuppressionRuleQuery.php
  - Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php
  - Modules/Recurring/Public/Services/RecurringSeriesQuery.php
  - routes/console.php
  - tests/Contracts/BoundaryArchTest.php
findings:
  critical: 2
  warning: 7
  info: 5
  total: 14
status: issues_found
---

# Phase 9: Code Review Report

**Reviewed:** 2026-06-13
**Depth:** deep
**Files Reviewed:** 36
**Status:** issues_found

## Summary

Phase 9 adds the `Modules/Anomaly` module, cloned from the DriftAlerts shape but re-keyed to a per-transaction (rather than per-series) anomaly model. The architecture is solid: cross-module reads go through Public services (`RecurringSeriesQuery`, `CounterpartyProfileQuery`), the state machine is the sole mutator, every evaluation-time query carries an explicit `where('user_id', ...)`, and the UNIQUE(transaction_id) seam plus QueryException no-op makes the insert path re-runnable.

Adversarial review surfaced **2 BLOCKER** correctness bugs and **7 WARNINGs**:

1. **The duplicate-charge detector is partially blind to the suppression band currency boundary AND the suppression band is computed from `latest_amount_minor`, which is `null` for duplicate-only / first-time-only alerts — so "Mark as expected" silently creates no rule for those reasons, defeating D-17 suppression** (BLOCKER CR-01).
2. **The `filterSuppressed` query can suppress the synthetic `large` reason that first-time injects, using a band that was never validated against the overall-spend comparison — a stored suppression rule for `large` from a per-merchant context wrongly mutes a first-time-merchant `large` signal** (BLOCKER CR-02).

The remaining warnings concern a backfill double-walk race, a duplicate-detector window asymmetry, a snooze-revival audit gap on the global sweep, and several edge cases in the robust statistics and currency handling. Quality gates (PHPStan L10, Pint, Pest) already pass, so these are logic/concurrency findings the static tooling cannot catch.

## Critical Issues

### CR-01: "Mark as expected" creates no suppression rule for duplicate-only or first-time-only alerts (D-17 silently defeated)

**File:** `Modules/Anomaly/Public/Actions/DismissAnomalyAlertAsExpected.php:103-109`
**Issue:**
The suppression band is derived exclusively from `$alert->latest_amount_minor`:

```php
$latestMinor = $alert->latest_amount_minor;
if ($latestMinor === null) {
    // ...record no rule. The dismissal still stands.
    return;
}
```

But `latest_amount_minor` is only populated by the `LargeVsTypicalDetector` path (`AnomalyEvaluator.php:85-86`). When an alert fires on `duplicate` alone, or `first_time` without a large-vs-typical per-merchant baseline, `baselineMinor`/`latestMinor` stay `null` and the row persists with `latest_amount_minor = NULL` (the schema allows it — migration line 65). For such an alert, dismissing "as expected" hits the `return` and writes **zero** suppression rules, even though the user explicitly asked for the merchant/charge to be muted. The next identical duplicate or first-time charge re-fires the alert — the suppression contract (D-17) is silently broken for two of the three detectors.

This is a correctness/data-integrity defect: the UI toast says "Suppression rule added — Undo" (`DriftPage.php:133`), but no rule exists, so "Undo" deletes nothing and the user's mute intent is lost.

**Fix:** When `latest_amount_minor` is null, fall back to the transaction's own `settled_amount_minor` to compute the band (the evaluator already matches on `settled_amount_minor`), or store a band-less "any amount for this detector+merchant" rule shape. Concretely, read the charge amount the same way the evaluator does:

```php
$latestMinor = $alert->latest_amount_minor;
if ($latestMinor === null) {
    // Fall back to the settled amount of the alert's own transaction so
    // a duplicate-only / first-time-only dismissal still produces a
    // suppression band (D-17). Read it via the same ledger READ used
    // for counterparty resolution.
    $latestMinor = $this->settledAmountForTransaction($user, $alert->transaction_id);
    if ($latestMinor === null) {
        return;
    }
}
```

and add a `settledAmountForTransaction()` sibling to `counterpartyIdForTransaction()` that selects `settled_amount_minor`. Reconcile the UI toast so it only promises a rule when one is written.

### CR-02: First-time's synthetic `large` reason is matched against per-merchant suppression bands, muting a distinct large-vs-overall signal

**File:** `Modules/Anomaly/Internal/AnomalyEvaluator.php:95-100`, `Modules/Anomaly/Internal/AnomalyEvaluator.php:168-206`
**Issue:**
When `FirstTimeMerchantDetector` fires, the evaluator injects a synthetic `large` reason even though `LargeVsTypicalDetector` did NOT trip (lines 97-99). That synthetic `large` reason then flows into `filterSuppressed` (line 116), where it is matched against `anomaly_suppression_rules.detector = 'large'` rows (line 178, `whereIn('detector', $reasons)`).

The two `large` signals are semantically different: one is "large vs this merchant's own history" and the other is "large vs the user's overall same-direction spend." A suppression rule created by dismissing a *per-merchant* `large` alert (with a ±15% band around that merchant's typical charge) can now suppress the *first-time-merchant* `large` reason for a completely different first charge whose amount happens to fall in that band — even though the first-time merchant has, by definition, no prior history with the user. The user muted "this merchant's recurring large charge"; the system silently also mutes "a brand-new merchant charged you a large amount," which is exactly the fraud-adjacent signal the feature exists to surface.

Worse, because `first_time` only fires *with* the synthetic `large` (D-09 coupling), suppressing `large` leaves `first_time` surviving — producing an alert that claims "first time" but has been stripped of its large-amount justification, contradicting the DTO/renderer contract that a first-time flag is always large-coupled.

**Fix:** Either (a) tag the synthetic large reason distinctly (e.g. `large_vs_overall`) so suppression bands keyed on per-merchant `large` cannot match it, or (b) exclude the synthetic `large` reason from `filterSuppressed` when it was injected by first-time rather than tripped by `LargeVsTypicalDetector`. Track provenance explicitly:

```php
$largeFromMerchantBaseline = $largeResult !== null;
// ...
if ($this->firstTimeDetector->fires(...)) {
    $reasons[] = 'first_time';
    if (! in_array('large', $reasons, true)) {
        $reasons[] = 'large';
    }
}
// In filterSuppressed: only let a 'large' suppression rule remove the
// 'large' reason when $largeFromMerchantBaseline is true.
```

## Warnings

### WR-01: BackfillAnomaliesJob can double-walk full history under concurrent dispatch

**File:** `Modules/Anomaly/Internal/Jobs/BackfillAnomaliesJob.php:48,92-114`
**Issue:**
The job is `ShouldBeUniqueUntilProcessing` — the uniqueness lock releases the moment `handle()` begins (documented at lines 30-32). The `anomaly_backfilled_at` guard is only stamped at the END of `handle()` (lines 112-114). If two activation dispatches land close together (e.g. a user double-clicks "Save" in settings, or a retry races a slow first run), worker A starts `handle()`, releases the lock, and reads `anomaly_backfilled_at === null`; worker B then also passes uniqueness (lock released), starts, and also reads `null`. Both walk the entire multi-year history. The per-row UNIQUE(transaction_id) seam prevents duplicate alerts, so this is not a data-corruption bug, but it is wasted full-history work (the exact thing the guard exists to prevent) and on a large ledger doubles backfill cost.

**Fix:** Stamp `anomaly_backfilled_at` with a claim BEFORE the walk using a conditional update that acts as a mutex, and only proceed if the claim succeeded:

```php
$claimed = $db->connection()->table('users')
    ->where('id', $this->userId)
    ->whereNull('anomaly_backfilled_at')
    ->update(['anomaly_backfilled_at' => $clock->now()->toDateTimeString()]);
if ($claimed === 0) {
    return; // another run already claimed the backfill
}
// ...then walk history
```

### WR-02: Duplicate-charge window is symmetric ±7 days, double-counting against future siblings and producing direction-dependent flags

**File:** `Modules/Anomaly/Internal/Detectors/DuplicateChargeDetector.php:75-89`
**Issue:**
The sibling window is symmetric around the candidate's `posted_at` (`subDays(7)` to `addDays(7)`). During backfill and the safety-net sweep, the evaluator runs against *every* transaction, including the earlier of a duplicate pair. So a genuine double-charge produces an alert on BOTH rows (each sees the other as a sibling within ±7 days), creating two alerts for one duplicate event — the opposite of the "one alert per event" intent. In the reactive (import-time) path only the later row exists, so it fires once; but the safety-net sweep re-evaluating the earlier row (which had no sibling at its own import time) will then fire a second alert once the later row exists. The two paths disagree on alert count for the same real-world duplicate.

**Fix:** Make the window directional — only look BACKWARD from the candidate (`whereBetween('posted_at', [$windowOpen, $anchorDate])` with `id < $thisId` as the tie-break for same-day), so only the later charge of a pair is flagged. This also matches the "double-tap / accidental re-charge" mental model where the second charge is the anomaly.

### WR-03: Global snooze-revival sweep writes audit transitions with no user scoping and can silently skip a deleted-then-recreated alert id

**File:** `Modules/Anomaly/Internal/Jobs/ReviveExpiredAnomalySnoozesJob.php:67-102`
**Issue:**
The candidate scan selects `id` only (line 71), then re-fetches each `AnomalyAlert` by id inside the loop (line 82). Between the scan and the re-fetch, a cascade delete (transaction removed → `cascadeOnDelete` on `anomaly_alerts`) can drop the row; the `$alert === null` guard handles that. But the bigger issue: the sweep is global and re-reads the row's `user_id` via the state machine — fine for isolation — yet there is no upper bound on the candidate set. On a multi-user box every expired snooze across all users is processed in one job with no chunking (contrast the safety-net sweep which uses `lazyById(500)`). A large backlog of expired snoozes loads every row via `get(['id'])` into memory at once.

**Fix:** Chunk the candidate scan with `lazyById`/`orderBy('id')->lazyById(500)` mirroring the safety-net sweep, and keep the per-row state-machine transition inside the chunk callback.

### WR-04: RobustStatistics.percentile uses linear interpolation that can rank a charge equal to the historical max as NOT large

**File:** `Modules/Anomaly/Internal/Support/RobustStatistics.php:155-178`; consumers `FirstTimeMerchantDetector.php:113-115`, `LargeVsTypicalDetector.php:115-116`
**Issue:**
`percentile($sample, 95.0)` linearly interpolates and, for small samples, can return a value equal to the sample maximum. The detector trips only on strict `>` (`(float) $absMinor > $threshold`). A charge exactly equal to the largest historical charge (or to the interpolated p95 that lands on the max) will NOT fire. For a category with few samples, p95 collapses toward the max, so a repeat of the largest-ever charge is never flagged as large-vs-overall. This is a silent false-negative at the boundary that the "large AND first-time" volume control depends on.

**Fix:** Decide the boundary deliberately and document it. If a charge tying the historical extreme should fire, use `>=` against the percentile, or clamp the percentile so a single dominant sample does not raise the bar above all observed values. At minimum add a test pinning the tie behaviour so a later refactor cannot flip it unnoticed.

### WR-05: madFloorFor casts `median * 0.01` through int, collapsing the context floor for small-median merchants

**File:** `Modules/Anomaly/Internal/Detectors/LargeVsTypicalDetector.php:169-174`
**Issue:**
```php
return (int) max((float) RobustStatistics::MAD_FLOOR_MINOR, $median * 0.01);
```
For any merchant whose median magnitude is below 5000 minor units (€50.00), `median * 0.01 < 50`, so the context floor always collapses to the hard `MAD_FLOOR_MINOR` (50). The "1% of median" context floor only ever engages for merchants with median > €50, and even then the `(int)` truncation discards the fractional part. The docblock claims the floor scales with merchant value, but for the bulk of small recurring charges it is a no-op. Combined with a near-constant low-value merchant (MAD≈0), the denominator is pinned at 50 minor units, so a deviation as small as ~€0.50 × k can trip — over-sensitive for cheap merchants.

**Fix:** Either remove the misleading "1% of median" claim, or compute the floor in float and only `(int)` at the final denominator. Verify the intended behaviour for sub-€50 medians with a dataset test.

### WR-06: Snooze idempotency compares cast app-timezone timestamp against caller offset; a re-snooze to the "same" wall-clock can still transition

**File:** `Modules/Anomaly/Public/Actions/SnoozeAnomalyAlert.php:71-77`
**Issue:**
The idempotency short-circuit compares `$alert->snoozed_until->getTimestamp()` against `$until->getTimestamp()`. `snoozed_until` is stored via `$until->toDateTimeString()` (line 79), which DROPS sub-second precision and the timezone offset, then re-hydrated as `immutable_datetime` in the app timezone. If the caller's `$until` carries a non-app offset or sub-second component, the round-tripped stored value will not match the original `$until->getTimestamp()`, so a genuine re-snooze to the same intended instant performs a redundant state-machine transition (extra audit row, extra event). Not corrupting, but it pollutes the append-only audit trail and re-emits `AnomalyAlertSnoozed`, which downstream listeners treat as a fresh snooze.

**Fix:** Normalise both sides through the same `toDateTimeString()` round-trip before comparing, or compare the to-be-written string against the stored string:

```php
if ($alert->state === 'snoozed'
    && $alert->snoozed_until !== null
    && $alert->snoozed_until->toDateTimeString() === $until->toDateTimeString()) {
    return;
}
```

### WR-07: Suppression-rule insert is not idempotent across undo→re-dismiss cycles and can accumulate duplicate rules

**File:** `Modules/Anomaly/Public/Actions/DismissAnomalyAlertAsExpected.php:122-138`
**Issue:**
`insertSuppressionRules` does a plain `insert` of one row per reason with no uniqueness guard. The `dismissed→open` undo edge (`RemoveAnomalySuppressionRule::undoSuppression`) deletes rules by `source_anomaly_alert_id`, so a single undo→re-dismiss is clean. But the migration places no UNIQUE constraint on `(user_id, counterparty_id, detector, direction, amount_band_low_minor, amount_band_high_minor, currency)` (migration `2026_..._010003` only adds a non-unique index). If `undoSuppression` is called partially (rule delete succeeds, the state-machine re-open throws and the action is retried), or if a second dismiss path ever lands without the early `state === 'dismissed'` guard firing, duplicate suppression rows accumulate. The settings list then shows the same mute twice and the evaluator's `whereIn('detector', ...)` match still works but the user must remove each duplicate individually.

**Fix:** Add a UNIQUE index on the rule's natural key and use `insertOrIgnore`, or guard the insert with a `whereNotExists` on the same natural key. This makes re-dismissal idempotent.

## Info

### IN-01: Backfill stamps `anomaly_backfilled_at` but never re-evaluates on later imports for users who enabled detection before any transactions existed

**File:** `Modules/Anomaly/Internal/Jobs/BackfillAnomaliesJob.php:96-114`
**Issue:** A user who enables anomaly detection on an empty ledger gets `anomaly_backfilled_at` stamped immediately (the lazy walk yields nothing). Subsequent imports are covered by the reactive listener + safety-net sweep, so this is fine — but it is worth a test asserting the empty-ledger activation still stamps and does not block later reactive detection.
**Fix:** Add a regression test for the empty-ledger activation path.

### IN-02: `DismissAnomalyAlert` and `DismissAnomalyAlertAsExpected` duplicate the load+guard+early-return boilerplate

**File:** `Modules/Anomaly/Public/Actions/DismissAnomalyAlert.php:38-50`, `Modules/Anomaly/Public/Actions/DismissAnomalyAlertAsExpected.php:52-65`
**Issue:** The owner-scoped load, null→404, and `state === 'dismissed'` early-return are byte-identical across both dismiss actions (and largely shared with Acknowledge/Snooze). Drift in one and not the other is a latent maintenance hazard.
**Fix:** Extract a shared `loadOwnedAlertOrFail(int, User): AnomalyAlert` helper (a trait or small collaborator) so the ownership guard cannot diverge.

### IN-03: `AnomalyAlertQuery::openForUser` default limit 26 is an unexplained magic number repeated across methods

**File:** `Modules/Anomaly/Public/Services/AnomalyAlertQuery.php:64,84,95`
**Issue:** `$limit = 26` appears three times with no named constant; it encodes a "25 rows + 1 lookahead for has-next" pattern that is not obvious. A reader cannot tell 26 is intentional.
**Fix:** Promote to a named constant (e.g. `PAGE_SIZE_WITH_LOOKAHEAD = 26`) with a one-line comment, matching the cursor-pagination intent.

### IN-04: `filterSuppressed` matches a NULL-counterparty rule against any charge of the same currency/band/direction, including charges with a resolved counterparty

**File:** `Modules/Anomaly/Internal/AnomalyEvaluator.php:181-188`
**Issue:** When the candidate HAS a counterparty, the closure matches rules where `counterparty_id = candidate OR counterparty_id IS NULL`. A normalized-name-fallback rule (NULL counterparty) created from one unresolved merchant will therefore suppress a *different*, resolved merchant's charge that merely shares the band/currency/direction. This may be intended (the comment frames NULL as a name-fallback), but the name is never actually compared — there is no merchant-name column on the rule, so the "normalized-name fallback" is in practice a band-only wildcard.
**Fix:** Either drop the OR-NULL branch when the candidate has a resolved counterparty, or genuinely store and compare a normalized merchant name so a NULL-counterparty rule cannot wildcard-match unrelated merchants.

### IN-05: `directionFromType` / `typesForDirection` duplicated verbatim across the evaluator and all three detectors

**File:** `Modules/Anomaly/Internal/AnomalyEvaluator.php:223-229`, `Modules/Anomaly/Internal/Detectors/DuplicateChargeDetector.php:119-135`, `Modules/Anomaly/Internal/Detectors/FirstTimeMerchantDetector.php:133-149`, `Modules/Anomaly/Internal/Detectors/LargeVsTypicalDetector.php:191-211`
**Issue:** The direction-mapping and the type-set-per-direction tables are copy-pasted four times. If the Ledger ever adds a transaction `type`, the four copies must be updated in lockstep; a missed copy silently mis-classifies direction and corrupts baselines.
**Fix:** Hoist into a single shared helper (e.g. a `Direction` value object or a static method on `RobustStatistics`/a new `TransactionDirection` support class) consumed by all four.

---

_Reviewed: 2026-06-13_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
