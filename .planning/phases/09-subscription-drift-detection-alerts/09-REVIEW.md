---
phase: 09-subscription-drift-detection-alerts
reviewed: 2026-05-17T12:00:00Z
depth: standard
files_reviewed: 79
files_reviewed_list:
  - Modules/Core/Internal/Http/Livewire/SettingsPage.php
  - Modules/Core/Models/User.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/settings-page.blade.php
  - Modules/Core/Resources/views/livewire/top-nav.blade.php
  - Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php
  - Modules/DriftAlerts/Database/Factories/DriftAlertTransitionFactory.php
  - Modules/DriftAlerts/Database/Migrations/2026_05_19_010001_create_drift_alerts_table.php
  - Modules/DriftAlerts/Database/Migrations/2026_05_19_010002_create_drift_alert_transitions_table.php
  - Modules/DriftAlerts/Internal/DriftEvaluator.php
  - Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php
  - Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php
  - Modules/DriftAlerts/Internal/Http/Livewire/DriftThresholdEditor.php
  - Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php
  - Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php
  - Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php
  - Modules/DriftAlerts/Internal/Mapping/DriftAlertDtoMapper.php
  - Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php
  - Modules/DriftAlerts/Internal/StateMachines/InvalidStateTransitionException.php
  - Modules/DriftAlerts/Models/DriftAlert.php
  - Modules/DriftAlerts/Models/DriftAlertTransition.php
  - Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php
  - Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php
  - Modules/DriftAlerts/Public/Actions/DismissDriftAlertAsCancelled.php
  - Modules/DriftAlerts/Public/Actions/SnoozeDriftAlert.php
  - Modules/DriftAlerts/Public/Dto/CancellationImpactDto.php
  - Modules/DriftAlerts/Public/Dto/DriftAlertDto.php
  - Modules/DriftAlerts/Public/Events/DriftAlertAcknowledged.php
  - Modules/DriftAlerts/Public/Events/DriftAlertDismissedCancelled.php
  - Modules/DriftAlerts/Public/Events/DriftAlertOpened.php
  - Modules/DriftAlerts/Public/Events/DriftAlertSnoozed.php
  - Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php
  - Modules/DriftAlerts/Public/Services/DriftAlertQuery.php
  - Modules/DriftAlerts/Resources/views/livewire/dashboard-drift-badge.blade.php
  - Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php
  - Modules/DriftAlerts/Resources/views/livewire/drift-threshold-editor.blade.php
  - Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php
  - Modules/DriftAlerts/Routes/web.php
  - Modules/DriftAlerts/composer.json
  - Modules/DriftAlerts/tests/Feature/AcknowledgeDriftAlertTest.php
  - Modules/DriftAlerts/tests/Feature/CancellationImpactDisplayTest.php
  - Modules/DriftAlerts/tests/Feature/DashboardDriftBadgeTest.php
  - Modules/DriftAlerts/tests/Feature/DismissDriftAlertAsCancelledTest.php
  - Modules/DriftAlerts/tests/Feature/DriftAlertCrossUser404Test.php
  - Modules/DriftAlerts/tests/Feature/DriftPageTest.php
  - Modules/DriftAlerts/tests/Feature/DriftThresholdOverrideEditorTest.php
  - Modules/DriftAlerts/tests/Feature/GlobalDriftThresholdSettingTest.php
  - Modules/DriftAlerts/tests/Feature/SnoozeDriftAlertTest.php
  - Modules/DriftAlerts/tests/Feature/SnoozedAlertRevivalTest.php
  - Modules/DriftAlerts/tests/Feature/TopNavDriftBadgeTest.php
  - Modules/DriftAlerts/tests/Pest.php
  - Modules/DriftAlerts/tests/TestCase.php
  - Modules/DriftAlerts/tests/Unit/CancellationImpactQueryTest.php
  - Modules/DriftAlerts/tests/Unit/DetectDriftAlertsJobUniqueTest.php
  - Modules/DriftAlerts/tests/Unit/DriftAlertDtoTest.php
  - Modules/DriftAlerts/tests/Unit/DriftAlertStateMachineTest.php
  - Modules/DriftAlerts/tests/Unit/DriftAlertTransitionsMigrationTest.php
  - Modules/DriftAlerts/tests/Unit/DriftAlertsMigrationTest.php
  - Modules/DriftAlerts/tests/Unit/DriftEvaluatorCadenceChangedTest.php
  - Modules/DriftAlerts/tests/Unit/DriftEvaluatorEdgeCases.php
  - Modules/DriftAlerts/tests/Unit/DriftEvaluatorEffectiveThresholdTest.php
  - Modules/DriftAlerts/tests/Unit/DriftEvaluatorFxInvariant.php
  - Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php
  - Modules/DriftAlerts/tests/Unit/DriftThresholdMigrationsTest.php
  - Modules/DriftAlerts/tests/Unit/EvaluateDriftListenerTest.php
  - Modules/DriftAlerts/tests/Unit/FixtureCorpusTest.php
  - Modules/DriftAlerts/tests/fixtures/drift-corpus/income-cut.php
  - Modules/DriftAlerts/tests/fixtures/drift-corpus/volatile-with-override.php
  - Modules/Recurring/Database/Migrations/2026_05_19_010002_add_drift_threshold_percent_to_recurring_series.php
  - Modules/Recurring/Database/Migrations/2026_05_19_010003_add_drift_alert_threshold_percent_to_users.php
  - Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php
  - Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php
  - Modules/Recurring/Providers/RecurringServiceProvider.php
  - Modules/Recurring/Public/Actions/SetDriftThresholdForSeries.php
  - Modules/Recurring/Public/Events/RecurringSeriesMetricsRefreshed.php
  - Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php
  - routes/console.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Contracts/DriftDetectionContractTest.php
  - tests/Pest.php
findings:
  critical: 4
  warning: 11
  info: 5
  total: 20
status: issues_found
---

# Phase 09: Code Review Report

**Reviewed:** 2026-05-17T12:00:00Z
**Depth:** standard
**Files Reviewed:** 79
**Status:** issues_found

## Summary

The DriftAlerts module ships a structurally coherent vertical slice: schema, state machine, evaluator, queued detector, snooze-revival sweep, three Public Actions, two Public Queries, two Spatie-Data DTOs, a /drift Livewire page with three tabs, dashboard + top-nav badges, a per-series threshold editor, and a global settings field. Cross-user isolation is exercised in dedicated tests, state-machine concurrency uses `lockForUpdate` + `PRAGMA busy_timeout`, idempotency leans on the `UNIQUE(recurring_series_id, latest_occurrence_id)` index, and money math stays in signed BIGINT minor units without float arithmetic.

The review surfaces several real correctness, robustness, and convention bugs:

- **A revival-sweep race** that can throw `InvalidStateTransitionException` and cause job retries when a user actions a snoozed alert between SELECT and the state-machine transaction.
- **A pagination cursor bug** in `DriftAlertQuery` where the `id < cursor` filter does not align with the `detected_at DESC, id DESC` ordering — rows can be skipped or duplicated on "Load more".
- **An ambient input-validation gap** in `DriftPage::snooze` that lets a tampered Livewire payload set arbitrary `snoozed_until` timestamps (including dates in the past), bypassing the 1w/1m/3m popover contract.
- **A direct, repeated, project-wide convention violation**: PHPDocs and Blade comments are saturated with phase numbers, wave numbers, UI-SPEC section refs, decision IDs, and pitfall numbers — every one of which is forbidden by `feedback_codebase_gsd_agnostic.md` and `feedback_docs_describe_current_state.md`.

Cross-user isolation is consistently enforced at the Public Action and Query layer. Money handling never touches floats. The schema-level state triggers + arch tests give the state mutator a third defensive layer. None of those areas need rework.

## Critical Issues

### CR-01: Revival-sweep race throws and triggers job retries when user actions a snoozed alert mid-sweep

**File:** `Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php:48-87`
**Issue:** The job SELECTs candidate rows (`state='snoozed' AND snoozed_until <= now()`), then iterates and calls `$stateMachine->transition($alert, 'open', 'detector_revived_snooze', 'detector', null, ['snoozed_until' => null])`. The defensive guard `if ($alert->state !== 'snoozed') continue;` is read *before* the state machine acquires its row lock, so a concurrent `AcknowledgeDriftAlert` or `DismissDriftAlertAsCancelled` action can transition the row to `acknowledged` or `dismissed_cancelled` between the guard read and `transition()`. The state machine then re-reads the row inside `lockForUpdate()`, sees `acknowledged`, and calls `guardTransition('acknowledged', 'open')` — `acknowledged` is terminal (`ALLOWED_TRANSITIONS['acknowledged'] = []`), so `InvalidStateTransitionException` fires. The exception propagates up through `handle()` unhandled, the queue worker marks the job as failed, and Laravel's `tries=1` (no tries override on this job) means the sweep gives up on that row until the next hourly tick.

The race is small but real: `RevivedExpiredDriftSnoozesJob` runs hourly and walks every snoozed-expired row in a single process; on a multi-user instance any one user clicking "Acknowledge" on a snoozed alert in that same second causes the whole sweep to die at that row, skipping every subsequent revival.

**Fix:** Either move the snoozed-state check inside the state machine's own transaction (and skip silently when the row has moved off `snoozed`) or catch `InvalidStateTransitionException` inside the foreach in `RevivedExpiredDriftSnoozesJob::handle()`:

```php
foreach ($rows as $row) {
    /** @var stdClass $row */
    $id = is_numeric($row->id) ? (int) $row->id : 0;
    if ($id <= 0) {
        continue;
    }

    try {
        $alert = DriftAlert::query()->where('id', $id)->first();
        if ($alert === null || $alert->state !== 'snoozed') {
            continue;
        }
        $stateMachine->transition(
            $alert,
            'open',
            'detector_revived_snooze',
            'detector',
            null,
            ['snoozed_until' => null],
        );
    } catch (InvalidStateTransitionException) {
        // A concurrent user action moved the row off 'snoozed' after
        // the candidate scan; skip and continue the sweep.
        continue;
    }
}
```

### CR-02: `DriftAlertQuery` cursor pagination skips or duplicates rows when `detected_at` ties exist

**File:** `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php:193-231` and `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php:222`
**Issue:** `scoped()` and `scopedOpen()` order by `detected_at DESC, id DESC` but the cursor filter is `where('id', '<', $cursorId)`. These are inconsistent. A row with the same `detected_at` as the cursor but a higher `id` than the cursor's `id` would be re-emitted on the next page; a row with a later `detected_at` than the cursor but a lower `id` would also be re-emitted (and is impossible to anchor against). Worse, the "Load more" button in `drift-page.blade.php:222` passes only `cursorId` and the query keeps the `detected_at DESC` ordering — so two alerts that share an exact `detected_at` second can interleave across pages.

For an alert listing this manifests as visual duplicates or missing rows when many alerts land in the same scheduler tick (the revival sweep can plausibly write 5–10 transitions inside one second; the detector's listener can also emit a batch from a single recurring-detect sweep).

**Fix:** Either change the cursor predicate to a composite `(detected_at, id)` tuple comparison, or order strictly by `id DESC` so the single-column cursor is monotone:

```php
// Option A — composite cursor (carries detected_at alongside id):
if ($cursorAt !== null && $cursorId !== null) {
    $query->where(function (Builder $q) use ($cursorAt, $cursorId): void {
        $q->where('detected_at', '<', $cursorAt)
            ->orWhere(function (Builder $q2) use ($cursorAt, $cursorId): void {
                $q2->where('detected_at', $cursorAt)
                    ->where('id', '<', $cursorId);
            });
    });
}

// Option B — order strictly by id DESC, drop detected_at sort:
$query->orderByDesc('id')->limit($limit);
if ($cursorId !== null) {
    $query->where('id', '<', $cursorId);
}
```

Option B is the smaller change and matches the documented intent in the PHPDoc ("`detected_at` is essentially monotonic for a given user so the composite-cursor approach … is not needed").  The PHPDoc claim is only true on a single-job-per-second tempo, which is exactly the tempo this sweep can break.

### CR-03: `DriftPage::snooze` accepts arbitrary `snoozed_until` strings, bypasses the popover affordance

**File:** `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php:61-66`
**Issue:** `snooze(int $alertId, string $untilIso, ...)` parses `$untilIso` directly via `CarbonImmutable::parse($untilIso)` and hands it to `SnoozeDriftAlert`. A user (or a compromised client) can call `wire:click="snooze($id, '1970-01-01T00:00:00Z')"` and immediately move the alert to `snoozed` with a past timestamp — at which point the open-tab query-time conditional (`DriftAlertQuery::applyOpenStateFilter`) re-surfaces the row as "open" but it's tagged `state='snoozed'` with `snoozed_until` in the past. The audit log records the user as the actor and the state machine's transition is legitimate, so the row's lifecycle ends up in a confusing half-state until the hourly revival sweep cleans up.

There's also no upper bound: `wire:click="snooze($id, '+10 years')"` is equally valid. The popover only offers 1w/1m/3m; the server should enforce that set.

This is bounded by the cross-user 404 guard — a user can only do this to their own alerts — so it's not a privilege-escalation bug. But it is an integrity bug: the server-side action contract should enforce the popover's option list rather than trust the client.

**Fix:** Validate `$untilIso` against an allowed set of relative offsets, or against a future-only window of bounded length:

```php
public function snooze(int $alertId, string $untilIso, CurrentUser $currentUser, SnoozeDriftAlert $action, Clock $clock): void
{
    $until = CarbonImmutable::parse($untilIso);
    $now = $clock->now();
    if ($until->lessThanOrEqualTo($now)) {
        return; // silent reject; UI should never emit this
    }
    if ($until->greaterThan($now->addMonths(6))) {
        return; // bounded snooze horizon
    }
    ($action)($alertId, $currentUser->user(), $until);
    $this->dispatch('toast', message: 'Snoozed');
}
```

Stricter still: compute the three legal snooze targets server-side (the component already does this in `render()` to populate `$snoozeTargets`) and have `snooze()` accept an enum key (`'1w' | '1m' | '3m'`) rather than a free-form ISO string.

### CR-04: PHPDocs, Blade comments, and class docblocks embed phase numbers, wave numbers, UI-SPEC refs, and decision IDs — direct CLAUDE.md violation

**File:** Almost every reviewed PHP file plus the Blade views. Representative offenders:
- `Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php:5-44` ("Phase 8", though no — wait, this one's clean. Other examples below.)
- `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php:5-8` ("Phase 8 D-810 popover")
- `Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php:4-9` ("Phase 8 D-810 popover", "Wave 3")
- `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php:30-40` ("Wave 3", "UI-SPEC § 5")
- `Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php:21-22, 31-37` ("UI-SPEC", language hedged but the surface is referenced indirectly)
- `Modules/DriftAlerts/Public/Events/DriftAlertDismissedCancelled.php:14-15` ("Phase 10 forecasting")
- `Modules/DriftAlerts/Public/Events/DriftAlertSnoozed.php:11-13` ("Phase 10 forecasting")
- `Modules/DriftAlerts/Public/Events/DriftAlertOpened.php:14-16` ("Phase 10 forecasting")
- `Modules/DriftAlerts/Public/Dto/DriftAlertDto.php:23` ("Wave 3 the SUM…")
- `Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php:22-39` ("Phase 8's `RecurringSeriesStateMachine::expireSnoozes()` pattern")
- `Modules/DriftAlerts/Internal/Mapping/DriftAlertDtoMapper.php:21` ("EUR-equivalent in minor units (null when the original currency is already EUR or when no FX shadow is available)" — fine. But corpus tests reference "Wave 0" / "Scenario 6" — `tests/fixtures/drift-corpus/income-cut.php:5`)
- `Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php:13-26` ("Wave 0 corpus", "later wave")
- `Modules/DriftAlerts/Database/Factories/DriftAlertTransitionFactory.php:14-23` ("later waves", "later wave")
- `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php:24-32` ("Phase 8 D-810 popover", references `RecurringReviewPage` / `FixedPaymentsCard` by name to anchor a past comparison)
- `Modules/Core/Resources/views/livewire/dashboard.blade.php:51-55, 56-65, 79, 161-162, 244-285` ("D-99 / D-100, CHN-06", "D-103 / issue #1 + #8", "Phase 3 D-46", "Phase 6", "UI-SPEC § Reauth-detected toast")
- `Modules/Core/Resources/views/livewire/top-nav.blade.php:29-34, 64-65, 88-105` ("Compound badge: when the DriftAlerts composer (DriftAlertsServiceProvider) reports a positive `driftOpenCount`" — fine, no phase ref; but does carry rationale.)

`feedback_codebase_gsd_agnostic.md` is explicit: "Implementation code must never reference … Task IDs (`T-01-04-02`), phase IDs (`Phase 1`), plan IDs (`01-04-PLAN`) … per CONTEXT.md decision D-08 … fixes BLOCKER 2 from plan-checker". `feedback_docs_describe_current_state.md` additionally bans referencing a past change. Many of these PHPDocs do both (e.g. "the planner reserved a per-row cadence join for the follow-on plan" in `drift-page.blade.php`).

The shipped product must be portable. Embedding the planning workflow in PHPDocs couples the codebase to GSD.

**Fix:** Rewrite every offending PHPDoc / Blade comment to describe the current behavior in present tense, with no reference to phases, waves, decision IDs, plan numbers, the corpus's scenario numbers, or "future" / "later wave" hand-offs. For example:

```php
// Before:
/**
 * ...
 * Phase 10 forecasting may subscribe to this event to fold open
 * drifts into its projections.
 */

// After:
/**
 * ...
 * Listeners that need direction-aware copy or projection inputs can
 * subscribe to this event without re-reading the drift_alerts row.
 */
```

And in the Blade view:

```html
{{-- Before --}}
{{-- /drift page — Open / History / Dismissed tabs over drift_alerts
     rows. The Open tab groups multiple alerts that share a
     recurring_series_id under a `<flux:card>` header with an Alpine
     `x-data="{ open: false }"` collapse toggle. Per-alert actions:
     Acknowledge (emerald primary on single-alert groups, slate
     secondary inside multi-alert groups), Snooze (slate, opens the
     Phase 8 D-810 popover), I cancelled this (slate; dispatches the
     DismissDriftAlertAsCancelled action and emits the corresponding
     Public event). --}}

{{-- After --}}
{{-- Drift alerts list. Three tabs (Open / History / Dismissed). The
     Open tab groups multiple alerts sharing a recurring_series_id
     under a collapsible card. Per-alert actions: Acknowledge,
     Snooze, "I cancelled this". --}}
```

Test fixtures may keep filenames (`income-cut.php`) but must drop the "Scenario 6" prefix in their inline comments.

## Warnings

### WR-01: Singleton-binding queued jobs and Livewire components is an anti-pattern

**File:** `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php:51-61`
**Issue:** `DetectDriftAlertsJob`, `RevivedExpiredDriftSnoozesJob`, `DashboardDriftBadge`, and `DriftThresholdEditor` are all `$this->app->singleton(...)`. Queued jobs are serialized to a payload at dispatch time and re-instantiated by the worker through `unserialize()` — the singleton binding is never consulted. Livewire components are instantiated per-request by `LivewireManager`, also bypassing the singleton. The binding is dead code at best and, at worst, misleads a future reader into thinking the state is shared across requests.

**Fix:** Drop the four singleton declarations. The container will auto-resolve them through reflection. The state machine + queries + Public Actions can remain singletons (they are stateless services).

```php
public function register(): void
{
    $this->app->singleton(DriftAlertStateMachine::class);
    $this->app->singleton(DriftEvaluator::class);
    $this->app->singleton(DriftAlertQuery::class);
    $this->app->singleton(CancellationImpactQuery::class);
    $this->app->singleton(AcknowledgeDriftAlert::class);
    $this->app->singleton(SnoozeDriftAlert::class);
    $this->app->singleton(DismissDriftAlertAsCancelled::class);
    // Drop: DetectDriftAlertsJob, RevivedExpiredDriftSnoozesJob,
    //       DashboardDriftBadge, DriftThresholdEditor
}
```

### WR-02: `DriftEvaluator` labels the hard 5% floor as `source='global'`, indistinguishable from a user-set global value

**File:** `Modules/DriftAlerts/Internal/DriftEvaluator.php:151-156`
**Issue:** When the user has `drift_alert_threshold_percent === 0` (or some falsy value), the evaluator returns `['percent' => 5, 'source' => 'global']`. The user-set-to-10% case also returns `['source' => 'global']`. The audit row `threshold_source` column carries the same string in both cases, so the UI cannot distinguish "this fired against your 10% threshold" from "this fired against the built-in 5% floor". The /drift page partial renders `±{{ $alert->thresholdPercentUsed }}%` — the percent is accurate; the source attribution is not.

**Fix:** Introduce a distinct `'default'` (or `'system_default'`) source label for the hard floor:

```php
if ($userValue > 0) {
    return ['percent' => $userValue, 'source' => 'global'];
}

return ['percent' => 5, 'source' => 'default'];
```

Update `FixtureCorpusTest::$allowedThresholdSources` and any downstream renderer copy that branches on the source.

### WR-03: `DriftPage::render()` issues N CancellationImpactQuery calls per page (one per distinct series id)

**File:** `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php:113-120`
**Issue:** The page resolves `CancellationImpactDto` per-series via a foreach over `$uniqueSeriesIds`. Each call hits `RecurringSeriesQuery::forSeries` which itself fires at least one SELECT against `recurring_series`. With the default page size of 26 alerts (often clustered into ~20 distinct series), that's ~20 round trips on every render of /drift. The cumulative cost is small in a single-user app but the pattern is the same N+1 antipattern the rest of the codebase already avoids via batched lookups (compare `DriftAlertQuery::loadSeriesDisplayNames` which does a single `whereIn`).

Performance is explicitly out of v1 review scope; flagged here for correctness only insofar as the duplicate logic is easy to drift from the batched series-display-name path.

**Fix:** Add a `forSeriesIds(array $seriesIds, User $user): array<int, CancellationImpactDto>` method on `CancellationImpactQuery` that does a single SELECT and returns the map keyed by series id, then call that once from `DriftPage::render()`.

### WR-04: `DriftThresholdEditor::save` silently coerces non-numeric strings to `0`

**File:** `Modules/DriftAlerts/Internal/Http/Livewire/DriftThresholdEditor.php:63-75`
**Issue:** `$effective = $newValue === 'global' ? null : (int) $newValue;` — when `$newValue` is the string `"abc"` (tampered Livewire payload), `(int) "abc" === 0`, which is then passed to `SetDriftThresholdForSeries`. The Public Action's whitelist rejects `0` (not in `{1,2,5,10,25,50}`) and throws `InvalidArgumentException`. The exception propagates uncaught to Livewire's error surface. Same shape as the `DriftPage::snooze` issue (CR-03) but bounded by the action's own whitelist, so the worst case is a noisy error rather than a data integrity bug.

**Fix:** Tighten the coercion: explicit whitelist on the Livewire side, falling through to a silent no-op (or a user-facing validation error) before reaching the action:

```php
public function save(int|string $newValue, CurrentUser $currentUser, SetDriftThresholdForSeries $action): void
{
    if ($newValue === 'global') {
        $effective = null;
    } elseif (is_int($newValue) || (is_string($newValue) && ctype_digit($newValue))) {
        $candidate = (int) $newValue;
        if (! in_array($candidate, [1, 2, 5, 10, 25, 50], true)) {
            return;
        }
        $effective = $candidate;
    } else {
        return;
    }

    ($action)($this->recurringSeriesId, $currentUser->user(), $effective);
    $this->currentValue = $effective;
    $this->dispatch('toast', message: 'Threshold updated.');
}
```

### WR-05: `DriftEvaluatorEdgeCases.php` and `DriftEvaluatorFxInvariant.php` lack `Test.php` suffix — invisible to suite-targeted runs

**File:** `Modules/DriftAlerts/tests/Unit/DriftEvaluatorEdgeCases.php`, `Modules/DriftAlerts/tests/Unit/DriftEvaluatorFxInvariant.php`
**Issue:** `phpunit.xml` declares the `DriftAlerts` testsuite as `<directory suffix="Test.php">./Modules/DriftAlerts/tests</directory>`. Files without the `Test.php` suffix are silently skipped when running `phpunit --testsuite DriftAlerts`. The global `Unit` testsuite (`<directory>Modules/DriftAlerts/tests/Unit</directory>`, no `suffix` attribute) still picks them up, so they run in the full `phpunit` invocation — but anyone running `--testsuite=DriftAlerts` (the natural per-module invocation) gets a silently smaller test surface than they expect, including the FX-invariance guard and every divide-by-zero / irregular-cadence / cross-user-evaluator guard.

**Fix:** Rename both files:
- `DriftEvaluatorEdgeCases.php` → `DriftEvaluatorEdgeCasesTest.php`
- `DriftEvaluatorFxInvariant.php` → `DriftEvaluatorFxInvariantTest.php`

No code change needed — Pest auto-discovers by `it()` / `test()` calls, not by class names.

### WR-06: Top-nav badge composer fires a COUNT query on every authenticated request that surfaces the nav

**File:** `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php:115-130`
**Issue:** The `core::livewire.top-nav` composer runs `$query->openCountForUser($currentUser->user())` on every render, which is essentially every authenticated page. The query hits `drift_alerts` with a WHERE-OR predicate (the open-state filter widens to include snoozed-but-expired). On a quiet inbox this is a cheap indexed count; under traffic it is still wasteful. The same pattern repeats in the Recurring + Chains + EmailScan top-nav composers.

Performance is out of v1 scope but the cumulative effect across four composers per request is worth noting.

**Fix:** Cache the count for ~30s per user using a request-cycle-scoped cache (`memo` on a `CurrentUser` instance, or `Cache::remember` keyed on `drift-open-count.{userId}` with a short TTL). Defer if performance is genuinely a non-issue at the single-user scale.

### WR-07: `DriftAlertDtoMapper` always parses `detected_at` via `CarbonImmutable::parse()`, but the SELECT returns it as a `stdClass` string — never typed

**File:** `Modules/DriftAlerts/Internal/Mapping/DriftAlertDtoMapper.php:47, 51, 55-59`
**Issue:** `CarbonImmutable::parse(self::toString($row->detected_at))` will throw if `detected_at` is unparseable. The migration sets `detected_at` as `timestamp` (always present and well-formed), so the happy-path is safe. But the mapper's `toString()` helper returns `''` for non-scalar input — and `CarbonImmutable::parse('')` raises `InvalidFormatException`. There's no test for the corrupt-row case and no defensive fallback. The same shape applies to `snoozed_until` and `actioned_at`, but both of those are gated on `is_string(...) && $rawSnooze !== ''`, so they're already safe. Only `detected_at` is exposed.

**Fix:** Add the same guard to `detected_at`:

```php
$rawDetected = $row->detected_at ?? null;
if (! is_string($rawDetected) || $rawDetected === '') {
    throw new \InvalidArgumentException(
        "DriftAlertDtoMapper: drift_alerts row {$row->id} has empty detected_at",
    );
}
$detectedAt = CarbonImmutable::parse($rawDetected);
```

Or, since the schema guarantees the column is non-null, leave it but add a unit test asserting the exception type so the contract is locked.

### WR-08: `SnoozeDriftAlert` idempotency check is timezone-sensitive

**File:** `Modules/DriftAlerts/Public/Actions/SnoozeDriftAlert.php:46-52`
**Issue:** The idempotency guard compares `$alert->snoozed_until->toDateTimeString() === $until->toDateTimeString()`. Both `toDateTimeString()` calls render in each Carbon instance's current timezone. The stored `snoozed_until` casts to `immutable_datetime` (the model's default timezone, typically UTC); the caller's `$until` comes from `CarbonImmutable::parse($untilIso)` which honors the ISO8601 string's offset. Re-snoozing to the *same wall-clock moment expressed in a different timezone* would not be treated as idempotent.

Single-user local-only app, so the practical risk is low. But the contract documented in the PHPDoc ("Idempotent when re-snoozing to the exact same target timestamp") is timezone-aware in implementation but timezone-naive in description.

**Fix:** Compare `->getTimestamp()` (Unix seconds, timezone-independent) instead of the formatted string:

```php
if (
    $alert->state === 'snoozed'
    && $alert->snoozed_until !== null
    && $alert->snoozed_until->getTimestamp() === $until->getTimestamp()
) {
    return;
}
```

### WR-09: `DriftEvaluator::evaluateForSeries` reads `recurring_series.drift_threshold_percent` via raw SELECT — bypasses the public read API contract

**File:** `Modules/DriftAlerts/Internal/DriftEvaluator.php:137-149`
**Issue:** The PHPDoc on `effectiveThresholdPercent` and the class-level docblock both acknowledge this. The `noRecurringSeriesWritesFromDriftAlerts` arch test allows it because it targets writes. CLAUDE.md mandates that "cross-module access goes through public service classes or events; no module reaches into another's models or internals". A raw SELECT on another module's table is the spirit of that constraint, even if the letter only fires on writes.

The clean fix is to expose a single-purpose `RecurringSeriesQuery::driftThresholdForSeries(int, User): ?int` method that owns the read. The arch test stays green either way; the codebase stays clearly within the module boundary.

**Fix:** Add a method to `RecurringSeriesQuery`:

```php
public function driftThresholdForSeries(int $seriesId, User $user): ?int
{
    $row = $this->db->connection()->table('recurring_series')
        ->where('id', $seriesId)
        ->where('user_id', $user->id)
        ->first(['drift_threshold_percent']);
    if ($row === null) {
        return null;
    }
    $value = $row->drift_threshold_percent ?? null;
    return is_numeric($value) ? (int) $value : null;
}
```

Then `DriftEvaluator::effectiveThresholdPercent` swaps the raw `->table('recurring_series')` call for `$this->recurringQuery->driftThresholdForSeries(...)`.

### WR-10: `DriftAlertQuery::loadSeriesDisplayNames` and `seriesStatesForUser` also read `recurring_series` directly

**File:** `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php:125-150, 284-313`
**Issue:** Same shape as WR-09 — direct table reads from another module's storage. The class-level PHPDoc acknowledges this is permitted by the arch test. Same fix: move the reads to `RecurringSeriesQuery` (a `displayNamesForSeriesIds(array, User): array<int, string>` and `statesForSeriesIds(array, User): array<int, string>`). Keeps the DriftAlerts module reading only its own table.

**Fix:** Mirror WR-09's approach for both helpers.

### WR-11: `DashboardDriftBadge` Blade view tags the headline with `↗` even when total impact is positive (income raise scenario)

**File:** `Modules/DriftAlerts/Resources/views/livewire/dashboard-drift-badge.blade.php:22-37`
**Issue:** The tile always prefixes the EUR-rolled-up annualized impact with `↗`. For expense drift this reads correctly ("costs going up"). For an income series with state='cadence_changed' that produced a positive-signed drift (e.g. a raise), the sum is positive — but the icon `↗` still renders, and the aria-label reads "annualized impact" without direction. A user looking at the tile cannot tell the difference between "subscriptions ballooning by €240/yr" and "salary up by €240/yr". The `$totalAnnualizedImpact` is summed in original-currency-minor units irrespective of direction; the EUR rollup runs on the absolute magnitude.

This is a real UI honesty issue, not a bug per se, but the tile's value proposition ("show me what I owe") rests on direction-aware copy.

**Fix:** Either (a) restrict the totalled sum to expense-direction alerts only (`->where('direction', 'expense')` on `totalOpenAnnualizedImpactForUser`), or (b) render two separate magnitudes split by direction. Option (a) is the cleanest match for the tile's intended copy.

## Info

### IN-01: `DriftAlertFactory::definition()` returns `'user_id' => null, 'recurring_series_id' => null, 'latest_occurrence_id' => null` — FK constraints will fire unless every caller overrides

**File:** `Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php:33-52`
**Issue:** The factory's default state cannot succeed against a schema-enforced FK; every test seeds the three FKs explicitly. That's documented in the class docblock ("`protected $model` reference is a string FQN; the `DriftAlert` Eloquent model lands in a later wave so production wiring resolves the class at factory-invoke time") — but the explanation references "a later wave" which is itself a CR-04 violation. The factory is also unusable for any quick `DriftAlert::factory()->create()` shortcut a future contributor might try.

**Fix:** Either pull FK-seeded sub-factories in (`User::factory()` + `RecurringSeries::factory()` + `RecurringSeriesOccurrence::factory()`) or document the invariant in present tense and drop the "later wave" reference.

### IN-02: `DriftEvaluator` and `DriftEvaluatorTest` exercise a `monthly +25% expense USD` case at the per-mille boundary

**File:** `Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php:115-124`
**Issue:** `prior=-1199, latest=-1499` ⇒ ratio = `300 * 100 / 1199 = 25.020850…`. The threshold compare is `<= 5`, so the dataset passes. But if the dataset author had instead picked `-1200 → -1500`, the exact ratio is `25.0` — fine. The fixture's choice of a non-round prior introduces an unnecessary floating-point hazard; the test happens to pass because the float exceeds 5 generously. A reader following the same shape with a different threshold value (say, 25%) would fall off the cliff: `300 * 100 / 1199 = 25.020…` *exceeds* a 25% threshold (just barely), but a reviewer reading the fixture sees "+25% drift" and expects no alert. Subtle.

**Fix:** Use round-percent fixtures (`prior=-1000, latest=-1250` for a strict +25%); document the strict-greater-than threshold compare explicitly in the test header.

### IN-03: `DriftAlertStateMachine::toIntOrNull` silently treats `user_id='0'` (or `'-1'`) as null

**File:** `Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php:155-170`
**Issue:** Defensive coercion — the PHPDoc says "an id of 0 (or negative) is never a real user". Treating a corrupted `user_id='0'` row as `null` quietly drops the audit-row FK rather than failing loudly. If a row's `user_id` ever does get set to `0` (a real, persistable value in SQLite), the transition audit row records `user_id=null`, which the audit consumers may treat as "system actor". Quieter than an exception, but harder to detect.

**Fix:** Either raise on the corrupt case (`throw new RuntimeException('drift_alerts row has invalid user_id=0')`) or document the swallowing as intentional and add a unit test that locks the behavior.

### IN-04: Routes file uses the `Route` facade — documented carve-out, but the comment refers to the BoundaryArchTest explicitly

**File:** `Modules/DriftAlerts/Routes/web.php:15-17`
**Issue:** "The `Route` facade is the documented carve-out for module Routes files (`BoundaryArchTest` exempts `Modules\*\Routes`)." This is a present-tense statement of the constraint, so it satisfies `feedback_docs_describe_current_state.md`. It does, however, name a test file inside a Routes file — a minor coupling. Cosmetic.

**Fix:** Drop the parenthetical: "The `Route` facade is permitted in module Routes files."

### IN-05: `RevivedExpiredDriftSnoozesJob` lacks `ShouldBeUniqueUntilProcessing` and `tries` configuration

**File:** `Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php:41-48`
**Issue:** Unlike `DetectDriftAlertsJob`, this job carries no `tries` override, no `backoff`, no `ShouldBeUniqueUntilProcessing` lock, and no `withoutOverlapping()` (relies on the scheduler's `withoutOverlapping(30)` instead). If a queue worker crashes mid-sweep after writing some transitions but before flipping all the candidate rows, Laravel's default `tries=1` will fail-final the job — leaving the half-revived rows in their intermediate state until the next scheduled tick (an hour later). The query-time conditional in `DriftAlertQuery::openForUser` papers over the gap for the count + listing, but the audit-row contract (one transition per state flip) goes dormant for that hour.

The scheduler's `withoutOverlapping(30)` already prevents double-dispatch from racing the previous tick, so adding `ShouldBeUniqueUntilProcessing` is belt-and-braces. A `public int $tries = 3;` would help the crash-mid-sweep case more meaningfully.

**Fix:** Set `public int $tries = 3;` and `public array $backoff = [60, 300, 900];` to mirror `DetectDriftAlertsJob`. Idempotency of each individual transition is already enforced by the state-machine guard, so retries are safe.

---

_Reviewed: 2026-05-17T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
