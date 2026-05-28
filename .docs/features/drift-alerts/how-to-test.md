# `DriftAlerts` — how to test

Practical recipes for exercising the `DriftAlerts` module in
isolation.

## Unit tests

- **Location:** `Modules/DriftAlerts/tests/Unit/`
- **What they test:**
  - The evaluator math against the 24-fixture `drift-corpus/`
    (`DriftEvaluatorTest`, `DriftEvaluatorEdgeCasesTest`,
    `DriftEvaluatorEffectiveThresholdTest`,
    `DriftEvaluatorFxInvariantTest`,
    `DriftEvaluatorCadenceChangedTest`).
  - The corpus shape itself (`FixtureCorpusTest`).
  - The state-machine transitions + the `InvalidStateTransitionException`
    (`DriftAlertStateMachineTest`).
  - The job uniqueness (`DetectDriftAlertsJobUniqueTest`).
  - The listener path (`EvaluateDriftListenerTest`).
  - The DTO mapper (`DriftAlertDtoMapperTest`,
    `DriftAlertDtoTest`).
  - The two migrations (`DriftAlertsMigrationTest`,
    `DriftAlertTransitionsMigrationTest`,
    `DriftThresholdMigrationsTest`).
  - The `CancellationImpactQuery` (`CancellationImpactQueryTest`).

## Feature tests

- **Location:** `Modules/DriftAlerts/tests/Feature/`
- **What they test:**
  - The three Public actions end-to-end (`AcknowledgeDriftAlertTest`,
    `SnoozeDriftAlertTest`, `DismissDriftAlertAsCancelledTest`).
  - Cross-user 404 posture (`DriftAlertCrossUser404Test`).
  - The drift page render (`DriftPageTest`).
  - The dashboard badge (`DashboardDriftBadgeTest`).
  - The top-nav badge memoisation
    (`TopNavDriftBadgeTest`).
  - The threshold-editor (per-series + global)
    (`DriftThresholdOverrideEditorTest`,
    `GlobalDriftThresholdSettingTest`).
  - The snooze-revival job (`SnoozedAlertRevivalTest`).
  - The cancellation-impact display
    (`CancellationImpactDisplayTest`).
- **Setup:** every test uses `RefreshDatabase`. Drift-corpus
  fixtures are referenced as data providers — the `FixtureCorpusTest`
  scans `tests/fixtures/drift-corpus/*.php` and asserts every file
  exposes the expected closure shape.

## Contract / arch invariants

- The repo-wide `noDriftAlertStateWritesOutsideMachine` invariant —
  forbids any class outside
  `Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine`
  from writing `drift_alerts.state`.
- The repo-wide module-boundary invariant — forbids any class under
  `Modules\DriftAlerts\` from importing from
  `Modules\Recurring\Internal` / `Modules\Recurring\Models`. Every
  cross-module recurring read goes through `RecurringSeriesQuery`.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/DriftAlerts/tests

# Just the corpus-driven evaluator tests
vendor/bin/pest Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php

# Just the state machine
vendor/bin/pest Modules/DriftAlerts/tests/Unit/DriftAlertStateMachineTest.php

# Stop on first failure
vendor/bin/pest Modules/DriftAlerts/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A drift alert raised for a recurring series the user just
  cancelled** — the user should use Dismiss-cancelled on the alert
  itself; the evaluator does not introspect series cancellation
  intent. The dismiss flow is what removes the alert from the open
  list.
- **A drift alert that the user expected, but did not fire** — walk
  the threshold ladder: per-series override → user-global → 5%
  floor (max). The most common cause is a per-series override above
  the actual ratio.
- **The corpus fixture passes in isolation but the evaluator fails
  in production** — every fixture must mirror a real-world shape;
  manually-injected unrealistic rows are forbidden (a lesson from an
  earlier wave on `Chains`). Inspect the failing input vs the
  fixture for shape drift.
- **`DriftAlertOpened` not firing on a new alert** — confirm the
  evaluator returned a fresh insert (not a unique-constraint
  no-op). The event dispatch is gated on the insert returning a
  non-zero affected count.
- **Snooze never reviving** — confirm
  `RevivedExpiredDriftSnoozesJob` is scheduled (every minute under
  `schedule:work`); confirm the snooze's `snoozed_until` is in the
  past. The state machine's `snoozed → open` transition is the
  path; check `drift_alert_transitions` for the audit trail.
- **Top-nav badge showing a stale count after acknowledging** —
  Livewire's response cycle re-renders the nav; the boot-scoped
  memo collapses repeated COUNTs to one query per `&$cache` key
  miss. A user id new to the cache produces a fresh COUNT; if the
  badge stays stale after Acknowledge, the Livewire render did not
  re-mount the nav — confirm the layout's nav is the same
  Livewire component instance the action invalidated.
