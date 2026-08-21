# Snooze and revive — an alert's life between open and closed

"Not now" is the most common answer to a drift alert. The user has seen
the €2 rise, is not going to cancel this week, and wants the row out of
the way until they are ready. Snoozing has to satisfy two demands that
pull against each other: the alert must disappear from the working list
*now*, and it must come back — exactly once, with an audit row proving
it came back — when the snooze expires.

The obvious implementation is a background sweep that flips expired rows
back to `open`. On its own that is wrong in a way users notice: between
two sweeps, an expired alert is still stored as `snoozed`, so the drift
count in the top nav and the annualised-impact total on the dashboard
under-report. On a desktop app whose scheduler ticks hourly, that is up
to an hour of visibly wrong numbers.

So the lifecycle runs on **two paths that produce the same set**, one
durable and one fresh.

## The two paths

**The read path** is `DriftAlertQuery::applyOpenStateFilter()`. Every
open-tab projection — `openForUser()`, `openCountForUser()`,
`totalOpenAnnualizedImpactForUser()`, `groupedBySeriesForUser()` —
applies the same compound predicate:

```
state = 'open'
  OR (state = 'snoozed' AND snoozed_until IS NOT NULL AND snoozed_until <= now)
```

An expired snooze is therefore *already* open as far as every count,
sum and list is concerned, the instant it expires. The clause is built
inside a single `where(function ($q) { ... })` group so that the `OR`
cannot escape and swallow the surrounding `user_id` scope or cursor
predicate — an `orWhere` chained at the top level would return other
users' rows. This path never writes.

**The write path** is `RevivedExpiredDriftSnoozesJob`, an hourly
scheduled sweep. It selects candidate ids
(`state = 'snoozed' AND snoozed_until <= now`), then for each one
re-reads the alert and calls
`DriftAlertStateMachine::transition($alert, 'open',
'detector_revived_snooze', 'detector', null, ['snoozed_until' => null])`.
That is what actually flips the stored state, clears `snoozed_until`,
and writes the `drift_alert_transitions` audit row. The sweep is global
rather than per-user: alerts belong to any user, and the audit row's
user id comes from the alert itself.

The sweep is safe to retry. Each transition is idempotent at the state
machine level, and a row that a user acknowledged, dismissed or
re-snoozed between the candidate scan and the state-machine's row lock
makes the transition illegal. The state machine raises
`InvalidStateTransitionException`, the sweep catches it **per row** and
continues, so one mid-sweep user action cannot fail the whole job. The
same race is also caught earlier and more cheaply by the re-read guard
(`$alert->state !== 'snoozed'` → skip), which handles the common case
without paying for a failed transaction.

## Setting a snooze

The `/drift` popover offers three durations, and they are the three
cases of `Modules\Core\Public\Enums\SnoozeWindow` — `OneWeek = '1w'`,
`OneMonth = '1m'`, `ThreeMonths = '3m'`. The targets are computed
**server-side** in `DriftPage::render()` via
`SnoozeWindow::targetsFrom($clock->now())`, so
`CarbonImmutable::setTestNow()` stays authoritative across the suite and
the browser never supplies a date the server did not choose. The enum
measures every window from the moment it is handed rather than reading a
clock of its own, which is what keeps that guarantee.

The same enum serves `/recurring/review` and the anomaly stream, and the
blades iterate `SnoozeWindow::cases()` rather than writing the three
buttons out — `anomaly-action-chips`, `drift-alert-row` and
`recurring-review-page` each render one button per case, keeping their
own `wire:click` target and their own label keys.

The browser sends the chosen ISO string back, and
`DriftPage::snoozeAlert()` re-validates it anyway:

- an unparseable string returns silently, never a 500;
- the target must be strictly after `now`;
- the target must be no later than `now + DriftPage::MAX_UNTIL_MONTHS`
  months, which is **6**.

Nothing in the popover can produce a value outside that range, which is
the point: a Livewire payload is client-controlled, and without the
bound a tampered request could snooze an alert into the year 2400 and
effectively delete it with no audit trail of a dismissal.

`DriftPage::snoozeAlert()` serves both the drift and the anomaly streams,
and the two are not symmetric behind it. `Anomaly`'s
`SnoozeAnomalyAlert` repeats the same `(now, now + 6 months]` bound in
the action itself and throws `InvalidArgumentException` outside it, so
every caller of that action is protected. `SnoozeDriftAlert` does not:
it validates ownership and idempotency but accepts any `$until` it is
handed. For a drift alert the Livewire component is therefore the only
bound, and any future caller of `SnoozeDriftAlert` — a queued job, a
sync op replay, a second UI surface — has to carry its own.

## Why the idempotency check compares epoch seconds

`SnoozeDriftAlert` no-ops when the alert is already snoozed to the same
target, so that a double-submitted click writes one audit row rather
than two. The comparison is:

```php
$alert->snoozed_until->getTimestamp() === $until->getTimestamp()
```

Not the formatted datetime strings. `drift_alerts.snoozed_until` casts
as `immutable_datetime`, which Eloquent hydrates **in the application
timezone**, while `$until` was parsed from an ISO-8601 string that
carries whatever offset its source wrote. The two can describe the same
instant and still format differently. Comparing epoch seconds is
offset-independent; comparing strings would classify an identical
re-snooze as a change and write a spurious transition into the audit
log.

## The atomic write

The state flip and the timestamp are not two writes.
`DriftAlertStateMachine::transition()` takes an `$extraColumns` array —
`['snoozed_until' => $untilString]` on the way in, `['snoozed_until' =>
null]` on the way back out — and applies it to the same `drift_alerts`
row inside the transition's own transaction, under the same
`lockForUpdate()` row lock, alongside the state change and the single
`drift_alert_transitions` insert. There is no window in which a row is
`snoozed` with no target, or `open` with a stale one.

The two directions carry distinct reasons — `user_action` for the
snooze, `detector_revived_snooze` for the revival — and distinct actors
(`user` versus `detector`), so the audit trail reads as a conversation
rather than a sequence of anonymous flips.

## Cursor pagination across the tabs

The drift tabs page on `id DESC`. `drift_alerts.id` is a SQLite
autoincrementing surrogate, so a newer alert always has a larger id, and
the cursor stays consistent even when several alerts share an exact
`detected_at` second — which the revival sweep and the detector listener
both produce, each writing a batch inside one scheduler tick. The
anomaly stream on the same page cannot do this: its ids are derived from
the alert's own columns so that two devices agree on them, which means
they sort in hash order, so it pages on `(detected_at, id)` and keeps
its own cursor properties.

## See also

- [`architecture.md`](architecture.md) — the state machine's transition
  map, its three enforcement layers, and the job concurrency contracts.
- [`drift-detection.md`](drift-detection.md) — how an alert comes to
  exist in the first place.
- [`how-to-test.md`](how-to-test.md) — the revival fixtures and the
  between-sweeps assertions.
