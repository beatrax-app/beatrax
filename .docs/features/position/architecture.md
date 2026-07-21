# `Position` — architecture

The `Position` module composes "your current position" — net worth, budget
status, upcoming recurring charges, and forecast shortfall risk — into one
`PositionSummaryDto`, read from other modules' existing Public seams rather
than any raw cross-module query. It exists so the periodic position digest
(delivered through Notifications) and, from a later plan onward, the
dashboard itself can never silently disagree about what "your position" is:
both surfaces resolve through the same `PositionQuery::forUser()`.

The module is register-only and thin: no routes, no views, no Livewire
components. Its first consumer is `EmitPositionDigestJob`; the dashboard's
own adoption of this DTO is a deliberately separate, independently
revertible plan.

## Composition, never a raw SELECT

`PositionQuery::forUser()` builds a `PositionSummaryDto` purely by calling
four other modules' Public seams:

- `Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery` — the
  dashboard's own "this period at a glance" composer (`for()` /
  `forByCurrency()` / `emailScanHealth()`).
- `Modules\Budgets\Public\Services\BudgetProgressQuery` — current-period
  budget status (its own resolved period, not the caller's `$period`
  argument).
- `Modules\Recurring\Public\Services\RecurringSeriesQuery` — approved
  recurring series, filtered here to those whose `nextExpectedAt` falls
  inside `[period->start, period->endExclusive)`; series with no
  `nextExpectedAt` (irregular cadence, no anchor) are excluded since there
  is no well-defined "upcoming" date for them.
- `Modules\Forecasting\Public\Services\ForecastHighlightsQuery` — the
  shortfall signal (`activeShortfallCountForUser()` > 0).

`summary` is byte-for-byte the same `DashboardSummary` value the dashboard's
own composer would return for the same `(user, period)` — this equality is
the property `PositionQueryTest` asserts and a later seam-swap plan reuses as
its regression anchor. `tilesByCurrency` mirrors the dashboard's
`default_currency_view === 'original'` toggle exactly (null in EUR-only
mode) so that eventual seam swap is a pure no-op.

A user with zero transactions, zero budgets, zero upcoming charges, and no
shortfall still gets a fully-populated DTO — never null. "Nothing notable"
is itself a valid position; the digest's whole ritual is dispatching it
regardless.

## The digest job

`EmitPositionDigestJob` is dispatched by a scheduler entry (owned by the
Notifications module's per-device preferences) with a `cadence` of `daily`,
`weekly`, or `off`. Position itself never reads that preference — it only
accepts the resolved cadence as a constructor argument, keeping this module
ignorant of Notifications' internals.

- `cadence === 'off'` short-circuits before any work; nothing is dispatched.
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
`PeriodQuery` and `BudgetProgressQuery::forCurrentPeriod()`), but a queued
job has no authenticated web guard user. The job binds the loaded `$user`
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
