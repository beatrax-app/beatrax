# `Forecasting` — architecture

The `Forecasting` module projects the user's near-term cash-flow
across 30 / 60 / 90 days, surfaces shortfall windows when a projected
balance crosses the per-account buffer threshold, and lets the user
model what-if scenarios (cancel a subscription, change an amount,
add a one-off, shift a date) without ever mutating the underlying
ledger.

## What this module is for

A statement view tells the user where they've been; a forecast tells
them where they're going. The module reads the
canonical recurring series from [`Recurring`](../recurring/architecture.md),
runs a deterministic projection per scenario over the chosen horizon,
folds the per-day contributions into a balance curve, and surfaces
the result as percentile bands (so a noisy recurring series shows up
as a wider band, not a fake single line). When the curve crosses the
buffer, a `ForecastShortfallDetected` event is raised; the user sees
the shortfall window on the chart and, optionally, on the dashboard
highlights tile.

The scenario isolation boundary is strict: scenarios live in
`forecast_scenarios` + `forecast_scenario_mutations`; they NEVER write
to `transactions`, `recurring_series`, or `chain_links`. The arch
invariant `noScenarioMutationsJoinedToTransactionQueries` blocks any
JOIN that would couple the scenario substrate to the ledger.

What the module explicitly does NOT do:

- It never mutates a user's recurring series. The scenario layer is
  an in-memory transform on top of the routed baseline contributions.
- It never persists a scenario's projected balance curve into the
  ledger. Every projection is a per-run artefact saved to
  `forecast_runs.result_json`.
- It never speaks to the network. The projection is local-only;
  the only outbound surface in the codebase is the auto-update
  channel in `Core`.

## Module boundary

`Public/` exposes the cross-module surface:

- **Services/**
  - `ForecastQuery::forUser($accountId, $horizonDays, $scenarioId,
    $user): ForecastDto` — one read, not two. The baseline and the
    scenario-applied projection differ only in whether `$scenarioId`
    is null, so they are the same query rather than a `baseline()`
    and a `forScenario()` that had to be kept in step.
  - `ScenarioQuery::forUser($user)`, `find($scenarioId, $user)`,
    `mutationsFor($scenarioId, $user)` — scenario metadata reads.
  - `ForecastHighlightsQuery::activeShortfallCountForUser($user)` /
    `tileFor($user)` — the dashboard tile + sidebar badge reads.
- **Actions/**
  - Scenario CRUD: `CreateScenario`, `RenameScenario`,
    `DeleteScenario`, `AddScenarioMutation`,
    `EditScenarioMutation`, `RemoveScenarioMutation`.
  - Launchpad atomic actions:
    `CreateCancellationScenarioForAlert` (from a drift alert),
    `CreateCancellationScenarioForSeries`,
    `CreateAmountChangeScenarioForSeries`. Each wraps a
    `CreateScenario` + `AddScenarioMutation` pair in a DB
    transaction.
  - `SetAccountForecastBuffer::__invoke($accountId, $bufferMinor,
    $user)` — the per-account buffer the shortfall detector
    compares against.
  - `SetAccountOpeningBalance::__invoke($accountId, $openingMinor,
    $user)` — manual opening-balance override used when the
    statement-derived anchor needs correcting.
- **DTOs/**
  - `ForecastDto` + `ForecastPointDto` — the projection result
    shape.
  - `ScenarioDto` + `ScenarioMutationDto` + the typed payload
    classes under `Dto/ScenarioMutationPayload/`
    (`AddOneOffPayload`, `AddRecurringPayload`,
    `CancelSeriesPayload`, `ChangeSeriesAmountPayload`,
    `ShiftSeriesDatePayload`).
  - `ForecastHighlightsDto`, `ShortfallWindowDto`,
    `SeriesConfidenceDto`, `BalanceAnchorDto`.
- **Events/**
  - `ForecastShortfallDetected` (`accountId, userId, windowStart,
    windowEnd`). Consumed by `Desktop::DispatchOsNotification`.
  - `ScenarioCreated`, `ScenarioMutated`, `ScenarioDeleted` —
    raised by the scenario CRUD actions; consumed by the
    `ProjectForecastOnScenarioChange` listener to re-run the
    projection.
- **Exceptions/**
  - `OpeningBalanceDivergenceWarning` — raised by
    `SetAccountOpeningBalance` when the manual override diverges
    from the statement anchor by more than the documented threshold.

`Internal/` houses the projection pipeline:

- **Internal/Pipeline/BalanceAnchorResolver** — picks the anchor
  balance for the account (statement-derived; user-overridden
  opening balance when present).
- **Internal/Pipeline/RangeProjector** — produces
  `ForecastContribution` instances per recurring series across the
  horizon, with `CadenceJitter` modelling cadence drift.
- **Internal/Pipeline/ChainAwareForecastRouter** — routes
  contributions through the chains (PayPal-funded charges show up
  on the funder account, ICS purchases reduce the ICS card-statement
  balance + later show up on ASN at settlement).
- **Internal/Pipeline/ScenarioApplier** — applies the scenario's
  mutations on top of the routed contributions; pure in-memory
  transform.
- **Internal/Pipeline/DailyFold** — folds contributions into a
  daily balance curve.
- **Internal/Pipeline/ShortfallDetector** — finds windows where
  the curve crosses the buffer; writes
  `forecast_shortfall_windows` rows.
- **Internal/Pipeline/Percentile** — the percentile fan
  computation (P10 / P50 / P90 bands).
- **Internal/Pipeline/ProjectionPipeline** — orchestrates the
  pipeline; the entry point the queued job calls.
- **Internal/StateMachines/ForecastRunStateMachine** — SOLE
  sanctioned mutator of `forecast_runs.state`.
- **Internal/Jobs/ProjectForecastJob** — per-`(user, scenario,
  horizon)` queued projection.
- **Internal/Listeners/ProjectForecastOnRecurringChange** /
  `OnDriftDismissed` / `OnScenarioChange` — re-project triggers.
- **Internal/Mapping/ForecastDtoMapper** — `forecast_runs` row →
  `ForecastDto`.
- **Internal/Http/Livewire/** — six SFCs (ForecastPage,
  AccountBufferEditor, ForecastHighlightsTile,
  ScenarioEditorSidebar, ModelWhatIfDropdown,
  OpeningBalanceEditor).

## Key services + events

- `ProjectionPipeline::run($user, $scenarioId, $accountId,
  $horizonDays)` — the orchestrator. Steps:
  1. `BalanceAnchorResolver::forAccount` — pick the starting balance.
  2. `RangeProjector::project` — build `ForecastContribution`
     instances from recurring series + scheduled exceptions.
  3. `ChainAwareForecastRouter::route` — apply chain-aware
     routing.
  4. (if scenario) `ScenarioApplier::apply` — transform the
     routed contributions.
  5. `DailyFold::fold` — collapse to a daily balance curve with
     P10/P50/P90 bands.
  6. `ShortfallDetector::detect` — find windows below buffer;
     write rows + dispatch `ForecastShortfallDetected`.
  7. Persist `forecast_runs.result_json` for the read-side query.
  8. Prune the runs this one supersedes — every row with a lower id
     sharing its `(user_id, scenario_id, horizon_days)`.
- `ForecastRunStateMachine::transition($run, $next)` — the
  `pending → running → complete | failed` lifecycle; the sole
  writer of `forecast_runs.state`.
- `ProjectForecastOnRecurringChange::handle($event)` — fans out
  one `ProjectForecastJob` per `(user, scenario, horizon)` when
  Recurring metrics refresh.
- `ProjectForecastOnDriftDismissed::handle($event)` — same when a
  drift alert is dismissed as cancelled.
- `ProjectForecastOnScenarioChange::handle($event)` — same when
  the user creates / edits / deletes a scenario.
- `ForecastShortfallDetected` — raised by `ShortfallDetector`;
  consumed by `Desktop` for OS notifications.

## Data flow

The projection trigger:

```
Recurring metrics refresh (or drift dismissed, or scenario change)
  → ProjectForecastOn{RecurringChange|DriftDismissed|ScenarioChange}
  → fan-out: per (user, scenario, horizon)
      → dispatch ProjectForecastJob
```

The projection itself:

```
ProjectForecastJob::handle()
  → ForecastRunStateMachine: pending → running
  → ProjectionPipeline::run
       → BalanceAnchorResolver
       → RangeProjector (with CadenceJitter)
       → ChainAwareForecastRouter
       → ScenarioApplier (only when scenarioId != null)
       → DailyFold (P10/P50/P90)
       → ShortfallDetector
            → write forecast_shortfall_windows rows
            → dispatch ForecastShortfallDetected
  → persist forecast_runs.result_json
  → ForecastRunStateMachine: running → complete
```

The user-facing surface:

```
/forecast
  → ForecastPage Livewire SFC
       → per-account tab + 30/60/90 horizon control
       → ForecastQuery::baseline($user, $accountId, $horizon)
       → ForecastQuery::forScenario(...) if user picked one
       → render rangeArea chart with P10/P50/P90 + shortfall band

/forecast scenario editor sidebar
  → ScenarioEditorSidebar SFC
       → ScenarioQuery::list($user)
       → user adds / edits / removes mutations
            → Public Action → dispatch ScenarioMutated
                 → ProjectForecastOnScenarioChange re-runs projection

dashboard
  → ForecastHighlightsTile SFC
       → ForecastHighlightsQuery::tileFor($user)
sidebar badge
  → composer reads ForecastHighlightsQuery::activeShortfallCountForUser
```

## Balance anchor resolution

`BalanceAnchorResolver` routes by `accounts.kind` to the most authoritative
starting point available:

- `asn` → most recent `statement_summaries.closing_balance_minor`.
- `ics_card` → most recent `card_statements` "open balance" (the absolute
  amount still owed, negated to a signed running-balance position since the
  user owes that amount to the card vendor).
- `paypal` or any other kind → `accounts.opening_balance_minor` when the
  user entered one; otherwise the fallback below.

Fallback: an ICS card account with no statement and no user-input opening
balance defaults to zero (summing transactions would double-count the
historical billing events the projection is about to re-emit forward). Every
other account with no anchor sums every existing transaction from scratch
(`asOf=1970-01-01`); the UI surfaces this case with an "Opening balance not
set" banner. The returned `BalanceAnchorDto.source` label
(`asn_statement_summary` / `ics_card_statement` / `user_input_opening_balance`
/ `sum_of_transactions` / `ics_card_zero_anchor`) is the audit ribbon's input.
A missing or cross-user account raises `ModelNotFoundException`, converted to
a 404 by the HTTP kernel.

## Cadence jitter

`CadenceJitter` spreads each per-occurrence contribution across a ±N-day
window, because real-world recurring charges rarely hit exactly on the
cadence-derived occurrence date (weekend processing, bulk-settlement lag,
funding-charge drift). Each contribution is replicated `2 × jitterDays + 1`
times with equal weighting (`weight = 100 / window` per replica); integer
rounding of the weight is accepted up to ±2 minor units per replica (locked
by `CadenceJitterTest`). The jitter is deterministic and pure math — no DI.

## Percentile tier (R-7 method)

`Percentile` implements the R-7 method ("linear interpolation between
closest ranks" — the default for numpy, scipy, Excel's `PERCENTILE.INC`, and
R's `quantile(type=7)`). Given N observed amounts, it computes a continuous
index `(N-1) × p/100`, floors it for the lower neighbour, and interpolates
linearly to the neighbour above by the fractional part. R-7 was chosen over
nearest-rank because nearest-rank collapses to a single observation at small
N (the percentile-tier trigger threshold is N=6), hiding the spread between
buckets; R-7 preserves a continuous band even at that size. The sign of the
input is preserved by construction (R-7 is a linear combination). Reference:
Wikipedia, "Percentile § Linear interpolation between closest ranks".

## Range projection tiers

`RangeProjector` walks a recurring series's next-expected date forward by
cadence until the horizon end, emitting one `ForecastContribution` per
occurrence, in one of two tiers:

- **Envelope tier** (default): `magnitude = abs(point)`, `lowMag = magnitude
  - (1 - tol)`,`highMag = magnitude * (1 + tol)`, then re-applies the
  original sign. Dates are the series's own predictable occurrence dates —
  no jitter is applied, since applying it would smear a band across
  uncertainty the series does not carry.
- **Percentile tier**: selected automatically when the series has both a
  high variance tolerance (>= 40%) and at least 6 historical occurrences.
  Replaces the envelope formula with R-7 percentiles (P10/P50/P90) computed
  across the observed occurrences; the triple is constant across every
  occurrence in the horizon (it reflects the full empirical distribution,
  not occurrence-by-occurrence variance). Because both the amount AND the
  date are uncertain for these series, the result is routed through
  `CadenceJitter` (±3 days) so the daily fold's quadrature shows the
  genuine date uncertainty.

## Chain-aware routing

`ChainAwareForecastRouter` sits between `RangeProjector` and `DailyFold`.
Algorithm:

1. For each contribution, look up
   `ChainLinkQuery::confirmedAndDeterministicForSeries`. A confirmed or
   deterministic chain link rewrites the contribution's `accountId` to the
   funder account (point/low/high values are preserved — the funder is the
   one whose balance dips).
2. Regardless of per-series chain links, synthesise the next ICS
   bulk-iDEAL settlement contribution onto the ASN funder account via
   `CardStatementQuery::nextSettlementForUser`. Past settlements are
   dropped — the horizon only extends forward.
3. De-duplicate: drop ONLY the contributions chain-routed onto the funder
   in step 1 that now collide with the synthesised settlement's (account,
   date) tuple. Any OTHER contribution sharing that tuple (an unrelated
   recurring series whose occurrence happens to land on the settlement
   date) survives, so the daily fold sums it alongside the settlement.
4. When `$viewByFunder=true`, per-series contributions collapse onto a
   single per-day-per-account aggregate (one line per funder account
   instead of N series-tagged lines) — the collapse assumes contributions
   sharing a tuple are already in the funder's default currency, since FX
   conversion never happens in this router (it stays at the daily-fold
   boundary per RESEARCH Pitfall 6).

## Daily fold

`DailyFold` combines per-occurrence spreads via quadrature (`√(Σ spread²)`),
which is statistically correct for INDEPENDENT series — if two series share
an underlying cause (e.g. two streaming subscriptions on the same billing
cycle), this under-estimates the combined spread. Every approved recurring
series is treated as independent; the percentile tier sidesteps the
assumption by reading the observed empirical distribution per series.
Reference: Cornell 8.04 / MIT OCW 6.012. Cross-currency contributions are
converted to the account's default currency at fold time using the
contribution's stored `fxRateUsed`; a cross-currency contribution with no
stored rate raises `InvalidArgumentException` rather than silently leaking
a foreign-currency point into the running balance. Days without
contributions carry the previous day's spread forward unchanged so the
chart band stays continuous.

## Scenario isolation boundary

`ScenarioApplier` is the load-bearing scenario in-memory transform. It reads
`forecast_scenario_mutations` (Forecasting-owned) via `ScenarioQuery` and
`recurring_series` (Recurring-owned) via `RecurringSeriesQuery::forSeries`
ONLY to look up variance-tolerance + cadence — both typed Public surfaces. It
NEVER joins `forecast_scenario_mutations` onto `transactions`,
`recurring_series_occurrences`, `chain_links`, or `card_statements`; the
`noScenarioMutationsJoinedToTransactionQueries` arch invariant is the single
most load-bearing structural enforcement of this boundary, with
`ScenarioIsolationContractTest` adding a runtime end-to-end proof. The
series-id validation in `AddScenarioMutation`/`EditScenarioMutation`
guarantees every persisted series_id belongs to a user-owned recurring
series; the Applier trusts that contract and silently skips mutations whose
referenced series has since been deleted. Defensive logging: when
`forSeries` returns null but the underlying row exists, the series belongs
to ANOTHER user — a contract violation that should never reach the Applier
through the Public Actions but could surface if a future seeder/Artisan
command/admin tool skips the Action layer; the Applier logs a warning and
continues with the silent skip.

The five mutation kinds:

- `cancel_series` — filters out every contribution whose seriesId matches.
- `add_one_off` — appends a single contribution at the payload's date when
  inside the horizon (seriesId=0 sentinel, no underlying series).
- `add_recurring` — expands into per-occurrence dates by walking the
  cadence forward, each carrying a ±5% calmest-default envelope (the form
  has no variance field).
- `change_series_amount` — recomputes the `(low, point, high)` triple for
  matching seriesId using the new amount and the series's variance
  tolerance.
- `shift_series_date` — shifts matching contributions by `(newNextDate -
  origNextDate)` days; `scope='next'` shifts only the first matching
  occurrence, `scope='all_subsequent'` shifts every one. Entries shifted
  past the horizon end are dropped.

`pickAccountIdForOneOff` (shared by `add_one_off`/`add_recurring`): one-off
mutations are not bound to a recurring series and the UI does not ask the
user to pick a target account, so the contribution lands on whichever
account already has the most baseline traffic (tie-break: lower accountId
wins, for determinism regardless of `RangeProjector`'s emission order).
Empty-baseline fallback: when no contributions exist (fresh-import user / no
approved series), falls back to the user's lowest-id owned account —
returning a 0 sentinel would silently drop the one-off at the per-account
fold. If the user owns no accounts at all, a warning is logged and 0 is
returned; the caller skips the mutation rather than emitting a phantom row.

## Shortfall detection

`ShortfallDetector` walks the per-day folded balance and writes
`forecast_shortfall_windows` rows when the running balance crosses below the
effective per-account buffer (`accounts.forecast_min_buffer_minor` when set,
else 0 — zero-crossing default). Audit honesty: the captured
`buffer_used_minor` is the buffer effective at detection time; a later
buffer edit triggers a re-projection that writes NEW rows, and historical
rows survive with the original buffer captured (mirrors the DriftAlerts
honest-audit pattern). Every `detect()` call deletes the previous
`(user_id, account_id, scenario_id)` windows BEFORE inserting new ones,
wrapped in a single DB transaction so a partial write never leaves the
table inconsistent. Emits `ForecastShortfallDetected` per new window,
consumed by operational-hardening hooks (backup-trigger, health-monitor
pings) and `Desktop` for OS notifications.

## Forecast run lifecycle

`ForecastRunStateMachine` is the single legal mutator of
`forecast_runs.status` — other module code reads the row freely but never
UPDATEs the column directly; the `crossModuleRawTableWrites` arch
invariant guards the broader substrate. Locked transition map: `pending →
running | failed`, `running → complete | failed`, `complete`/`failed` are
terminal. Every transition opens a DB transaction, takes a row lock (a no-op
on SQLite but the project-wide concurrency-fence pattern for Postgres/future
tier-ups), validates the move against the map, and writes the new status +
lifecycle timestamp atomically; illegal transitions raise
`InvalidForecastRunTransitionException` and leave the row untouched.

## Projection orchestration and output shape

`ProjectionPipeline::project()` composes `BalanceAnchorResolver`,
`RangeProjector` (+ `ChainAwareForecastRouter`, + `ScenarioApplier` when a
scenario is active), and `DailyFold` into a single per-(user, scenarioId,
horizon) run, mediated by `ForecastRunStateMachine` (`pending → running →
complete`, or `→ failed` on any thrown exception, re-thrown so the queue
worker logs the stack trace). The result is serialized to
`forecast_runs.result_json`:

`forecast_runs` is a cache with exactly one live row per
`(user_id, scenario_id, horizon_days)`. Both readers — `ForecastQuery` and
`ForecastHighlightsQuery` — take the newest row for that key and nothing holds
a foreign key into the table, so a completed run deletes the rows it
supersedes. Without that the table is append-only: a round-6 desktop reached
1,305 rows and 54.6 MB of `result_json` in thirteen hours of ordinary use,
taking the database from 9 MB to 62 MB — a weight every encrypted backup, and
every restore, then carries.


```json
{
  "as_of": "YYYY-MM-DD",
  "horizon_days": 30,
  "accounts": {
    "<accountId>": {
      "account_id": 1,
      "account_name": "ASN Betaalrekening",
      "default_currency": "EUR",
      "today_balance_minor": 150000,
      "anchor_source": "user_input_opening_balance",
      "points": [{"date": "...", "low_minor": 0, "point_minor": 0, "high_minor": 0, "currency": "EUR"}]
    }
  }
}
```

`ProjectForecastJob`'s concurrency contract: `ShouldBeUniqueUntilProcessing`
keyed on `uniqueId() = "{userId}:{scenarioKey}:{horizonDays}"`, where
`scenarioKey` is the literal string `'baseline'` for a null scenarioId (so
`(5, null, 30)` and `(5, 0, 30)` produce different keys — `'5:baseline:30'`
vs `'5:0:30'` — avoiding silent collisions since the lock key is plain
string equality). `tries = 3`, `backoff = [60, 300, 900]` (mirrors
`DetectDriftAlertsJob`). The three re-projection listeners
(`ProjectForecastOnRecurringChange`, `ProjectForecastOnDriftDismissed`,
`ProjectForecastOnScenarioChange`) each dispatch one `ProjectForecastJob`
per horizon for the baseline, and the Recurring/DriftAlerts-triggered
listeners additionally fan out per saved scenario the user owns (since the
whole projection surface is invalidated when the recurring-series substrate
changes); `ProjectForecastOnScenarioChange` only re-projects baseline + the
affected scenario (6 dispatches per event: 3 baseline + 3 affected-scenario
horizons), because a scenario mutation does not change what any OTHER saved
scenario should show. For `ScenarioDeleted` only the baseline horizons
dispatch — the deleted scenario's runs were already wiped by the
cascade-on-delete FK. `ProjectForecastOnRecurringChange`/
`ProjectForecastOnDriftDismissed` import only `Modules\Recurring\Public\Events`
/ `Modules\DriftAlerts\Public\Events` — never the sibling `Internal`
namespace — enforced by `crossModuleAccessGoesThroughPublic`.

`ForecastQuery` never triggers a synchronous projection inside the request
lifecycle (`noSynchronousForecastingInRequestLifecycle` blocks importing the
heavy `ProjectionPipeline` class at this surface). When the latest run for a
(user, scenario, horizon) tuple is not yet `complete`, the DTO carries
`isComputing=true` with empty `points` (the chart shows an "Updating…"
caption). When the run is complete but the account has no series of its
own, the points array is hydrated to `horizonDays + 1` flat days at the
account's anchor balance — the calm projection of "nothing changes between
now and the horizon end". The per-series confidence legend buckets on
`band_width / |point|`: <=10% high, <=25% medium, else low, where band width
derives from `variance_tolerance_percent` (var=5% → 10%-wide band, var=10%
→ 20%-wide band); percentile-tier series still report the configured
variance tolerance as their user-visible confidence signal even though the
chart shows an empirical band.

`ForecastHighlightsQuery` (dashboard tile + sidebar badge) counts shortfalls
with a baseline-only filter (`scenario_id IS NULL`) — the dashboard and
the sidebar represent the user's CURRENT financial picture, and scenario
shortfalls are "what-if" simulations that should not count toward the badge.
A window is "active in the next 30 days" when `starts_at <= today + 30d`
AND `ends_at >= today`.

`ScenarioQuery::forUser` LEFT JOINs `forecast_scenarios` to a per-scenario
mutation-count subquery — both Forecasting-owned tables, so this does NOT
violate `noScenarioMutationsJoinedToTransactionQueries` (which guards joins
onto the transaction substrate specifically). `mutationsFor` loads through
the Eloquent model so `ScenarioMutationPayloadCast` runs and the per-kind
payload subclass is hydrated correctly.

`computeAllAccountsAggregate` (in `ForecastPage`) sums every account's
per-day point estimate into a single date-indexed series for the
All-accounts aggregate chart. The rollup intentionally simplifies:
per-account `default_currency` is treated as already-converted (a
PayPal-USD point carries the per-occurrence settled-EUR amount from
upstream FX conversion); multi-currency edge cases are captured at the
per-account chart's confidence-row legend rather than smudged into the
aggregate. The buffer floor is the sum of every account's
`forecast_min_buffer_minor` (NULL treated as 0).

## Net worth roll-up

`NetWorthQuery` reads each account's current balance via the same
`BalanceAnchorResolver` anchor the forecast uses as "today's balance" —
already sign-correct (bank/PayPal positive, credit card negative), so net
worth is simply the sum with no per-kind sign juggling. Non-base-currency
accounts are converted via `ExchangeRateService`; each account line keeps
its own original currency regardless. When no rate exists for a currency
pair, that account is excluded from the total and flagged via
`accountsWithoutRate`/`hasExcludedAccounts` (the no-rate fallback row still
renders in the breakdown, just without a base equivalent).
`paypal_funding` is an internal routing construct excluded from the
roll-up entirely.

## Opening-balance editor soft-warning flow

`OpeningBalanceEditor` is an INLINE editor (no popover wrapper, unlike the
popover-style `AccountBufferEditor`) mounted per account row in the
`/settings` Forecasting section. Flow:

1. User edits the opening balance + as-of date.
2. Save invokes `SetAccountOpeningBalance` with `allowDivergence=false`.
3. If the entered balance diverges from the sum of imported transactions
   by more than €500, the Action throws `OpeningBalanceDivergenceWarning`;
   the editor catches it, shows a soft-warning banner, and keeps the form
   open with `divergenceDiffMinor` set.
4. The user clicks "Use my number" to commit with `allowDivergence=true`,
   or "Use Beatrax's number" to replace the input with the computed
   sum-of-transactions and manually re-save.

`SetAccountForecastBuffer` and `SetAccountOpeningBalance` both: raise a
cross-user 404 via an `(id, user_id)` guard; validate server-side (buffer
must be zero-or-positive; an opening balance requires a non-future as-of
date); write inside a single DB transaction; and on success dispatch the
baseline projection horizons so the chart re-renders against the new floor
or anchor. `$bufferMinor === null` clears the buffer (zero-crossing
default); `$openingBalanceMinor === null` clears both opening-balance
columns and skips the divergence check.

## Amount string parsing

`AmountStringParser::toMinor` is a locale-aware money-input parser shared
by every Forecasting Livewire surface that accepts a free-text amount
field, using a "last-separator wins" heuristic: `"12.50"` → 1250,
`"12,50"` → 1250, `"1,234.56"` → 123456, `"1.234,56"` → 123456, `"1234"` →
123400, `" 1 234,56"` → 123456 (spaces stripped), `"-12,50"` → -1250 (sign
optional unless `$allowNegative` is false). It was centralised here because
the earlier per-component inline implementations silently 100×-multiplied
any dot-decimal input — the dot was being stripped as a thousands separator
unconditionally.
