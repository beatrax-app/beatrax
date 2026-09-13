# `Position` — architecture

The `Position` module composes "your current position" — net worth, budget
status, upcoming recurring charges, and forecast shortfall risk — into one
`PositionSummaryDto`, read from other modules' existing Public seams rather
than any raw cross-module query. It exists so that the surfaces answering
"what is my position" cannot silently disagree: they resolve through one
`PositionQuery::forUser()` rather than each composing their own figures.

The module is register-only and thin: no routes, no views, no Livewire
components. Two consumers read it, and they read different amounts of it:
`EmitPositionDigestJob` takes the whole `PositionSummaryDto`, and the
dashboard takes `PositionTilesDto` — see [two seams, one
composition](#two-seams-one-composition).

## Composition, never a raw SELECT

`PositionQuery::forUser()` builds a `PositionSummaryDto` purely by calling
five Public seams across four other modules. Four of the five are asked
about the `$period` it was handed; the fifth, deliberately, is not:

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
  shortfall signal, via `shortfallRiskForUser()`. Four answers, not a
  boolean: `Ahead`, `None`, `NotYetComputed`, and `Computing` while a
  projection for the tile horizon is pending or running. Without the last
  one a re-projection reported the superseded run's `None` as this
  moment's safety, and the digest's shortfall line said nothing about it.
- `Modules\Forecasting\Public\Services\NetWorthQuery` — `forUser($user)`,
  with **no** period. The roll-up answers "what do you hold now", so paging
  the dashboard back a month must not restate it as what you held then. It
  is the one seam here that takes no `$period`, and the reason is the whole
  point of it. Its figure is not guaranteed to equal `/reports`' net-worth
  series at its most recent point; that divergence, and the two causes of
  it, are set out in
  [the reports page](../reports/architecture.md).

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

## Two seams, one composition

`tilesForUser()` answers with the three members a dashboard render draws —
`summary`, `tilesByCurrency`, `emailScanHealth` — and `forUser()` is
composed **from** it, so the two cannot come to disagree about a tile.

The dashboard reads the narrower one. It draws no figure from the other
four, and each of those four is a child component's own question asked
again by that child — with a client-owned filter or toggle the parent's
snapshot could not carry, and in three of the four cases a *different*
question of the same module:

| member | what the position asks | what the child on the dashboard asks |
|---|---|---|
| `upcoming` | `RecurringSeriesQuery::allApprovedForUser`, filtered to the period | `FixedPaymentsViewQuery::topByMonthlyEquivalent($user, 6, …)` plus monthly totals, under the card's own `#[Url]`-bound `fp-filter` |
| `budgets` | `EnvelopeProgressQuery::forPeriod` | `BudgetProgressQuery::expenseCategories` plus `CarryoverQuery::forUserAndPeriod` |
| `shortfallRisk` | `ForecastHighlightsQuery::shortfallRiskForUser` — one enum | `ForecastHighlightsQuery::forUser` — an eight-member DTO |
| `netWorth` | `NetWorthQuery::forUser` | `NetWorthQuery::forUser`, behind the card's own `$expanded` toggle, which re-renders the child alone |

Only the last is the same call, and it is the one whose child re-renders
without the parent. So the answer to "pass them down or let the children
own them" is that the children own them, and the position stops building
figures for a page that draws none of them: composing all seven ran 37
statements where 11 answer the screen, and a whole `GET /` went from 102
statements to 82.

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

- **DTOs** — `PositionSummaryDto` (`Public/Dto`), the whole composed value
  object, and `PositionTilesDto`, the three members a dashboard render
  draws. The first is composed from the second.
- **Event** — `PositionDigestDue` (`Public/Events`), raised once per cadence
  occurrence; the sole subscriber is a listener in the Notifications module.
- **Service** — `PositionQuery` (`Public/Services`), the sole composition
  entry point, through `forUser()` or `tilesForUser()`.

`Modules\Position` never imports the Notifications module's namespace —
`PositionDigestDue` is a plain readonly event that any listener elsewhere
may subscribe to.
