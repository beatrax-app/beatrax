# `DriftAlerts` — specs

The behavioural contract for the `DriftAlerts` module.

## Behavioral contracts

- **The evaluator never imports `Recurring`'s internals.** Every
  read of a recurring series flows through
  `Modules\Recurring\Public\Services\RecurringSeriesQuery`. The
  evaluator never runs a raw SELECT on `recurring_series`.
  (`tests/Unit/DriftEvaluatorTest.php`)
- **The effective threshold is
  `max(perSeriesOverride, userGlobal, 5%)`.** A user-global override
  below 5% does not silence drift; a per-series override below the
  user-global does not silence below the user's chosen floor.
  (`tests/Unit/DriftEvaluatorEffectiveThresholdTest.php`,
  `tests/Feature/GlobalDriftThresholdSettingTest.php`,
  `tests/Feature/DriftThresholdOverrideEditorTest.php`)
- **The evaluator is idempotent.** Re-running for the same
  `(seriesId, latestOccurrenceId)` pair is caught at the UNIQUE
  constraint and treated as a no-op via the `QueryException`
  catch. (`tests/Unit/DriftEvaluatorTest.php`)
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
- **The top-nav badge query is a single COUNT against
  `(user_id, state)`.** The provider's badge composer memoises
  within a boot cycle so repeated renders in the same response
  collapse to one query. (`tests/Feature/TopNavDriftBadgeTest.php`)

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
  `snoozed-at-series-level-ignored.php` fixture covers the path;
  the evaluator does not produce a fresh alert for a series-level
  snooze window.
- **Multi-drift in a single batch refresh** — each `(user, series)`
  gets its own dispatched job; the unique lock keys are distinct.
  (`tests/fixtures/drift-corpus/multi-drift.php`)
- **Volatile-series threshold override** — the per-series override
  beats the user-global; volatile series can carry a higher
  threshold to silence noise.
  (`tests/fixtures/drift-corpus/volatile-with-override.php`)

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/specs.md) — `User`, `Clock`, `BelongsToUser`,
    `CurrentUser`.
  - [`Recurring`](../recurring/specs.md) —
    `RecurringSeriesMetricsRefreshed` event,
    `RecurringSeriesQuery` Public service.
- **Depended on by**
  - [`Desktop`](../desktop/specs.md) — subscribes to
    `DriftAlertOpened` for OS notifications (bundle-only).
  - [`Forecasting`](../forecasting/specs.md) — uses
    `CancellationImpactQuery` when projecting the
    "what if I cancel X" scenario.
  - The shared layout — reads `DriftAlertQuery::openCountForUser`
    via the top-nav badge composer.

## Configuration + feature flags

- `users.drift_threshold_pct` — per-user global threshold (default
  null = use the 5% floor).
- `recurring_series.drift_threshold_pct` (per-series override) —
  takes precedence over the user-global when set; clamped by the
  5% floor.
- The 5% hard floor is fixed in the evaluator source — no config
  knob; lowering it would weaken the watchdog.
- The snooze-revival job runs via the scheduler tick (every
  minute under `schedule:work`); no per-user opt-out.
