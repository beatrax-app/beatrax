# `Position` — architecture

The `Position` module composes "your current position" — net worth, budget
status, upcoming recurring charges, and forecast shortfall risk — into one
`PositionSummaryDto`, read from other modules' existing Public seams rather
than any raw cross-module query. It exists so that the surfaces answering
"what is my position" cannot silently disagree: they resolve through one
`PositionQuery::forUser()` rather than each composing their own figures.

The module is register-only and thin: no routes, no views, no Livewire
components. `EmitPositionDigestJob` is its consumer; the dashboard still
composes its own summary through `ThisPeriodAtAGlanceQuery`, which this
module also reads.

## Composition, never a raw SELECT

`PositionQuery::forUser()` builds a `PositionSummaryDto` purely by calling
four other modules' Public seams, and every one of them is asked about the
`$period` it was handed:

- `Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery` — the
  dashboard's own "this period at a glance" composer (`for()` /
  `forByCurrency()` / `emailScanHealth()`).
- `Modules\Budgets\Public\Services\EnvelopeProgressQuery` — budget status
  for the caller's own `$period`, folded out of the envelope model the app
  writes.
- `Modules\Recurring\Public\Services\RecurringSeriesQuery` — approved
  recurring series, filtered here to those whose `nextExpectedAt` falls
  inside `[period->start, period->endExclusive)`; series with no
  `nextExpectedAt` (irregular cadence, no anchor) are excluded since there
  is no well-defined "upcoming" date for them.
- `Modules\Forecasting\Public\Services\ForecastHighlightsQuery` — the
  shortfall signal (`activeShortfallCountForUser()` > 0).

`summary` is byte-for-byte the same `DashboardSummary` value the dashboard's
own composer would return for the same `(user, period)` — the equality
`PositionQueryTest` asserts. `tilesByCurrency` mirrors the dashboard's
`default_currency_view === 'original'` toggle exactly (null in EUR-only
mode), so pointing the dashboard at this seam would change nothing it
renders.

A user with zero transactions, zero budgets, zero upcoming charges, and no
shortfall still gets a fully-populated DTO — never null. "Nothing notable"
is itself a valid position; the digest's whole ritual is dispatching it
regardless.

## The digest job

`EmitPositionDigestJob` is dispatched by a scheduler entry (owned by the
Notifications module's per-device preferences) with a `DigestCadence`.
Position itself never reads that preference — it only accepts the resolved
cadence as a constructor argument, keeping this module ignorant of
Notifications' internals. The enum lives in `Modules\Core\Public\Enums`
rather than beside the preference row it is stored in, because
`noTriggerModuleImportsNotifications` forbids the import that would otherwise
be needed here. That gate covers five modules — `Recurring`, `Budgets`,
`DriftAlerts`, `Position` and `Ledger` — and fails the build on any mention
of the `Modules\Notifications\` namespace inside them, comments stripped
first. `Core` is the module both already depend on, so the shared word costs
no edge.

- `DigestCadence::Off` short-circuits before any work; nothing is dispatched.
- The occurrence key is derived from the injected `Clock` (never `now()`
  directly): the ISO date for `daily`, `{isoWeekYear}-W{isoWeek}` for
  `weekly`. Computing it any other way (e.g. a locale-formatted label) risks
  two independently-computed digests for the same logical occurrence
  diverging and failing to collapse to one notification row.
- The job dispatches exactly one `PositionDigestDue` event, unconditionally
  — there is no "is anything interesting?" gate. The digest itself is the
  reassurance; gating it would make the cadence contract ambiguous.
- The job never dispatches inside an open write transaction — the event
  fires strictly after `PositionQuery`'s reads complete.

### Guard-binding for queue/console context

`PositionQuery::forUser()` transitively resolves `CurrentUser` (via
`PeriodQuery` and the envelope fold behind
`EnvelopeProgressQuery::forPeriod()`), but a queued job has no
authenticated web guard user. The job binds the loaded `$user`
onto the default auth guard for the duration of the composition call only,
then restores the guard's prior state in a `finally` block (a real previous
user via `setUser()`, or `SessionGuard::forgetUser()` when no previous user
existed) — the same pattern used by the Budgets module's nudge job. The
worker process never keeps another user's identity bound to the guard after
the job returns.

## Public surface

- **DTO** — `PositionSummaryDto` (`Public/Dto`), the single composed value
  object.
- **Event** — `PositionDigestDue` (`Public/Events`), raised once per cadence
  occurrence; the sole subscriber is a listener in the Notifications module.
- **Service** — `PositionQuery` (`Public/Services`), the sole composition
  entry point.

`Modules\Position` never imports the Notifications module's namespace —
`PositionDigestDue` is a plain readonly event that any listener elsewhere
may subscribe to.
