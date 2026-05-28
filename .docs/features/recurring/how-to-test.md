# `Recurring` — how to test

Practical recipes for exercising the `Recurring` module in
isolation.

## Unit tests

- **Location:** `Modules/Recurring/tests/Unit/` (when present)
- **What they test:** the `CadenceInferrer` classification
  against fixture occurrence sequences; the
  `ClusterKeyComposer` against canonical transactions; the
  state machine's allowed-transition matrix; the
  detectors against synthetic input rows.

## Feature tests

- **Location:** `Modules/Recurring/tests/Feature/`
- **What they test:**
  - The full detect-job lifecycle on a realistic month of
    transactions (assert series rows + events).
  - The seven Public actions end-to-end (transition + event +
    audit row).
  - The `RecurringPage`, `RecurringReviewPage`,
    `RecurringSeriesDetailPage` Livewire SFCs.
  - The `FixedPaymentsCard` dashboard tile.
  - The top-nav badge memoisation.
  - The cross-user 404 posture on every action + mount.
  - The cadence-flip path (an `approved` series whose
    inferred cadence shifted).
  - The `RecurringSeriesQuery::lastTwoOccurrences` shape
    consumed by `DriftAlerts`.

## Contract / arch invariants

- The repo-wide
  `noRecurringSeriesStateWritesOutsideMachine` — only
  `Internal\StateMachines\RecurringSeriesStateMachine` may
  write `recurring_series.state`.
- The repo-wide module-boundary invariant — forbids any
  external module from importing
  `Modules\Recurring\Internal\*` or
  `Modules\Recurring\Models\*`. External reads go through
  the Public `RecurringSeriesQuery`.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Recurring/tests

# Just the detector + cadence math
vendor/bin/pest Modules/Recurring/tests --filter "Detector|Cadence"

# Just the state machine
vendor/bin/pest Modules/Recurring/tests --filter "StateMachine"

# Stop on first failure
vendor/bin/pest Modules/Recurring/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A real subscription not being detected as a series** —
  walk the detector path. The most common cause is the
  user's history did not yet contain enough occurrences for
  the cadence inferrer to settle on a stable cadence (the
  default window is two months; a quarterly subscription
  needs three months minimum to land as a candidate).
  Increase `users.recurring_detection_window` for the user
  and re-run.
- **A series's cadence flipping repeatedly between two
  values** — the `CadenceInferrer` is on the boundary. The
  series's variance tolerance can be widened via
  `EditRecurringSeriesVarianceTolerance` to stabilise.
- **`RecurringSeriesMetricsRefreshed` not firing on a new
  occurrence** — the new occurrence didn't get linked to
  the series. Check `recurring_series_occurrences` for a
  row pointing at the new `transactions.id`; if missing, the
  detector pass didn't run (confirm the import dispatched
  detection via `DispatchesRecurringDetection`).
- **A pending series the user keeps re-detecting after
  reject** — the rejected row's cluster key matches the
  re-detected one; the detector dedups, but if the
  cluster key changed (because the user added an alias
  that renamed the counterparty), a new pending row appears.
  Pattern: edit the existing rejected row instead (via the
  series detail page).
- **The badge shows a stale pending count after Approve** —
  the composer reads
  `RecurringSeriesQuery::pendingCountForUser` per render.
  A Livewire roundtrip that didn't re-render the nav uses
  the prior payload's count; a hard reload forces a fresh
  read.
- **`DetectRecurringSeriesJob::handle` not resolving its
  detectors** — `iterable<SeriesDetector>` can't be
  auto-resolved by Container::call; the provider's
  `bindMethod()` is the resolution path. If detectors are
  missing at runtime, the `recurring.detector` tag is empty
  — confirm the detectors are bound + tagged in
  `register()`.
