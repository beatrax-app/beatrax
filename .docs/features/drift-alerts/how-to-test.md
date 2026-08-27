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
  - The sidebar drift badge, including the revived-snooze count and
    its user scoping (`SidebarDriftBadgeTest`).
  - The threshold-editor (per-series + global)
    (`DriftThresholdOverrideEditorTest`,
    `GlobalDriftThresholdSettingTest`).
  - That `/drift` reads every series threshold in one query however
    many editors it mounts, shows each series the value its own read
    would have found, and still lets a standalone mount read its own
    row (`DriftPageLoadsThresholdsOnceTest`).
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
  the threshold ladder: per-series override, else user-global, else
  the 5% default. The most common cause is a per-series override
  above the actual ratio.
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
- **Sidebar badge showing a stale count after acknowledging** — the
  count comes from `NavCountsService`, whose payload is cached for
  five minutes per user. Nothing invalidates it on a drift transition,
  so a badge that lags an Acknowledge by up to five minutes is the
  cache, not a render bug. Call `NavCountsService::forget($userId)` to
  confirm — if the badge stays stale after that, the Livewire render
  did not re-mount the nav — confirm the layout's nav is the same
  Livewire component instance the action invalidated.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `DriftAlerts` module.

## Behavioral contracts

- **The evaluator never imports `Recurring`'s internals.** Every
  read of a recurring series flows through
  `Modules\Recurring\Public\Services\RecurringSeriesQuery`. The
  evaluator never runs a raw SELECT on `recurring_series`.
  (`tests/Unit/DriftEvaluatorTest.php`)
- **The effective threshold is the most specific setting that
  exists**: per-series override, else user-global, else the 5%
  default. There is no floor over the top — the settings ladder
  offers 1% and 2%, and a floor would make both inert while the
  screen went on reading back the number the reader chose. A lower
  threshold reports more drift, never less.
  (`tests/Unit/DriftEvaluatorEffectiveThresholdTest.php`,
  `tests/fixtures/drift-corpus/sub-five-global-threshold.php`,
  `tests/Feature/GlobalDriftThresholdSettingTest.php`,
  `tests/Feature/DriftThresholdOverrideEditorTest.php`)
- **Every corpus fixture is replayed through the real evaluator.**
  Each transaction lands, becomes an occurrence, and the evaluator
  runs before the next arrives; the resulting `drift_alerts` rows are
  compared column by column against the fixture's `expected.alerts`.
  The corpus previously checked a fixture's literals against its own
  other literals, so the evaluator could return anything.
  (`tests/Feature/TheDriftCorpusWasNeverFedToTheEvaluatorTest.php`)
- **The occurrence read is bounded.** The evaluator asks for the newest
  three occurrences rather than hydrating a series' whole history to
  reach the newest two.
  (`tests/Feature/TheEvaluatorHydratedEveryOccurrenceToReadTwoTest.php`)
- **A movement has to be one.** A zero prior, a currency change
  mid-series and a sign flip (a refund against a charge) are each
  refused rather than subtracted.
  (`tests/fixtures/drift-corpus/prior-zero.php`,
  `mixed-currency-within-series.php`, `sign-flip-refund.php`)
- **A cadence restructure is annualised per side.** Monthly EUR 10
  becoming yearly EUR 100 is a EUR 20/yr saving, not EUR 90/yr extra.
  (`tests/fixtures/drift-corpus/cadence-restructure.php`)
- **An already-actioned alert no-ops rather than raising.** A second
  tab acknowledging a dismissed row, or re-snoozing a lapsed snooze to
  a new date, leaves the row consistent instead of returning a 500.
  (`tests/Feature/AnAlreadyActionedDriftAlertRaisesInsteadOfNoOppingTest.php`)
- **The dashboard total counts rises only.** A price drop cannot
  cancel a price rise out of "potential annualized cost".
  (`tests/Feature/APriceDropCancelsAPriceRiseInTheDashboardTotalTest.php`)
- **Load more extends the list.** The rows already on screen stay
  there, and the Open tab is bounded rather than unpaged.
  (`tests/Feature/LoadMoreReplacedTheListInsteadOfExtendingItTest.php`)
- **The evaluator is idempotent, and only for the right reason.**
  Re-running for the same `(seriesId, latestOccurrenceId)` pair is
  caught at the UNIQUE constraint and treated as a no-op; any other
  write failure is re-raised rather than silently suppressing the
  alert. (`tests/Unit/DriftEvaluatorTest.php`,
  `tests/Feature/AFailedAlertWriteWasSwallowedAsIfItWereADuplicateTest.php`)
- **Prior amount of zero does not divide.** The
  `prior-zero.php` fixture covers the path; the evaluator skips the
  ratio computation and the row stays absent.
  (`tests/Unit/DriftEvaluatorEdgeCasesTest.php`)
- **FX-only swings do not raise drift.** When both occurrences are
  in the same source currency, the ratio is computed in that
  currency; a USD subscription whose EUR-equivalent moved purely
  because of FX is not flagged.
  (`tests/Unit/DriftEvaluatorFxInvariantTest.php`,
  `tests/fixtures/drift-corpus/fx-only-swing.php`)
- **A mixed-currency real drift IS flagged.** A USD subscription
  whose USD price went up by >threshold raises an alert with
  `currency=USD`. (`tests/fixtures/drift-corpus/mixed-currency-real-usd-drift.php`)
- **`pending` and `rejected` recurring series are ignored.** The
  evaluator only operates on `approved` and `cadence_changed`
  series. (`tests/fixtures/drift-corpus/pending-state-ignored.php`,
  `tests/fixtures/drift-corpus/rejected-state-ignored.php`)
- **Irregular-cadence series are ignored.** A series whose cadence
  is unstable (deviation beyond the cadence tolerance) is not
  compared; the comparison would not be meaningful.
  (`tests/fixtures/drift-corpus/irregular-cadence-ignored.php`)
- **The state machine is the sole mutator of
  `drift_alerts.state`.** The arch invariant
  `noDriftAlertStateWritesOutsideMachine` blocks any other write
  path. (`tests/Unit/DriftAlertStateMachineTest.php`)
- **Allowed transitions:**
  `open → acknowledged | snoozed | dismissed_cancelled`;
  `snoozed → open | acknowledged | dismissed_cancelled`.
  Any other transition raises `InvalidStateTransitionException`.
  (`tests/Unit/DriftAlertStateMachineTest.php`)
- **Every state transition writes an audit row to
  `drift_alert_transitions`.** Append-only; rows are never updated
  or deleted after insert. (`tests/Feature/AcknowledgeDriftAlertTest.php`)
- **Expired snoozes revive to `open` via
  `RevivedExpiredDriftSnoozesJob`.** A snoozed alert whose
  `snoozed_until <= now()` flips back to `open` on the next
  scheduler tick. (`tests/Feature/SnoozedAlertRevivalTest.php`,
  `tests/fixtures/drift-corpus/snooze-expiry-revival.php`)
- **`DriftAlertOpened` fires once per newly-inserted row.** Re-runs
  that hit the unique-constraint no-op do NOT dispatch a fresh
  event. (`tests/Unit/DriftEvaluatorTest.php`)
- **Cross-user reads / writes return 404.** Every Public action
  filters by `(id, user_id)`; a foreign user's alert is invisible.
  (`tests/Feature/DriftAlertCrossUser404Test.php`)
- **The detector job is unique per `(user, series)`.**
  `ShouldBeUniqueUntilProcessing` with
  `uniqueId() = "{userId}-{seriesId}"` collapses concurrent
  triggers. (`tests/Unit/DetectDriftAlertsJobUniqueTest.php`)
- **The sidebar badge counts what `/drift` lists.** The count moved
  into `NavCountsService`, which applies the same revival-aware
  predicate `DriftAlertQuery::openCountForUser` uses — `open`, plus a
  `snoozed` row whose `snoozed_until` has passed — inside its own
  group, so the OR cannot escape the `user_id` predicate.
  (`tests/Feature/SidebarDriftBadgeTest.php`)

## Edge cases

- **Series with only one occurrence to date** — the evaluator
  short-circuits; no comparison possible.
- **Two same-amount occurrences** — `delta_minor = 0`; no row
  inserted.
- **Cadence-changed but amounts stable** — no drift row (the state
  is still comparable; the threshold simply isn't crossed).
  (`tests/fixtures/drift-corpus/cadence-changed.php`)
- **Quarterly / weekly / yearly cadence** — the evaluator is
  cadence-aware via `RecurringSeriesQuery`; the threshold is
  applied to the per-occurrence amount, not annualised.
  (`tests/fixtures/drift-corpus/quarterly-cadence.php` etc.)
- **Per-series snooze covering the series itself** — the
  `snoozed-at-series-level-ignored.php` fixture covers the path. A
  snoozed series has `state = 'snoozed'`, which is outside the
  projectable set, so the evaluator never sees it.
- **Multi-drift in a single batch refresh** — each `(user, series)`
  gets its own dispatched job; the unique lock keys are distinct.
  (`tests/fixtures/drift-corpus/multi-drift.php`)
- **Volatile-series threshold override** — the per-series override
  beats the user-global; volatile series can carry a higher
  threshold to silence noise.
  (`tests/fixtures/drift-corpus/volatile-with-override.php`)

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `User`, `Clock`, `BelongsToUser`,
    `CurrentUser`.
  - [`Recurring`](../recurring/how-to-test.md) —
    `RecurringSeriesMetricsRefreshed` event,
    `RecurringSeriesQuery` Public service.
- **Depended on by**
  - [`Desktop`](../desktop/how-to-test.md) — subscribes to
    `DriftAlertOpened` for OS notifications (bundle-only).
  - [`Forecasting`](../forecasting/how-to-test.md) — uses
    `CancellationImpactQuery` when projecting the
    "what if I cancel X" scenario.
  - The app sidebar — reads the same open-alert count from
    `Core`'s `NavCountsService`.

## Configuration + feature flags

- `users.drift_alert_threshold_percent` — per-user global threshold
  (unset = use the 5% default).
- `recurring_series.drift_threshold_percent` (per-series override) —
  takes precedence over the user-global when set, in both directions:
  a series can be watched more closely as well as less.
- The 5% default is fixed in the evaluator source — no config knob.
  It is what applies when neither setting is present, not a bound on
  either.
- The snooze-revival job runs via the scheduler tick (every
  minute under `schedule:work`); no per-user opt-out.
