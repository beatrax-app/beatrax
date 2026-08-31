# Scenario isolation — a what-if that cannot reach the ledger

A scenario lets the user ask "what if I cancelled Netflix, moved rent
to the 3rd, and bought a €900 bike in June?" and see the answer drawn
on the same chart, in the same shape, as their real forecast. That
visual equivalence is the point — and it is also the hazard. The
answer has to be indistinguishable from reality on screen and
completely absent from every other read in the application.

[`Forecasting` — architecture](architecture.md) states the boundary
and lists the five mutation kinds. This page is about why the boundary
is drawn where it is, what specifically would go wrong without it, and
where the enforcement stops.

## Why the obvious implementations fail

Three approaches suggest themselves, and each one fails in a way worth
naming, because each failure is silent.

**Write the mutation into `recurring_series` and undo it after.** The
projection is a queued job. Undo requires the job to complete. A crash,
a worker restart, an exception in the fold, a user closing the desktop
app mid-run — any of those and the user's actual Netflix series is now
cancelled in their real data, with no record that a scenario did it.
The failure surfaces weeks later as "why did my subscription list
change?".

**Write hypothetical rows and flag them.** Add
`transactions.is_hypothetical` and filter it out everywhere. This
works exactly until someone writes the next read. There are dozens of
reads over the transaction substrate across `Ledger`, `Budgets`,
`Reports`, `Tax`, `Goals`, `Position` and `Search`, plus the sync
capture that ships rows to a paired device. Every one of them must
remember the flag forever. The first one that forgets does not throw —
it quietly returns a number that includes a bike the user never
bought, in a tax export or a budget rollup or a peer device's ledger.

**Compute the scenario from a copy of the substrate.** Correct, and
enormously expensive: a per-scenario shadow of the transaction and
series tables, kept in sync, per user, per saved scenario.

## The mechanism

Scenarios persist into exactly two tables, both owned by
`Forecasting`: `forecast_scenarios` (the named container) and
`forecast_scenario_mutations` (the individual what-ifs). Nothing else
is ever written on a scenario's behalf except the projection's own
output, and that output is keyed by `scenario_id` in
`forecast_runs.result_json` and `forecast_shortfall_windows`.

`ScenarioApplier` is a pure in-memory transform with the signature
`list<ForecastContribution> -> list<ForecastContribution>`. It sits at
one point in the pipeline — after `ChainAwareForecastRouter` has
resolved funder accounts, before `CadenceJitter` smears the dates and
`DailyFold` buckets contributions per account. `ProjectionPipeline`
calls it only when `scenarioId` is non-null; with no scenario the
transform is skipped entirely rather than run as a no-op, so the
baseline path does not even load the mutation table.

The position in the pipeline is not arbitrary. Running before the
router would mean a scenario-added contribution never gets chain-routed
onto its funder account; running after the fold would mean rewriting
an already-summed balance curve, where individual series are no longer
separable. And it has to run *before* the jitter, because three of the
five mutation kinds select contributions by series id and two of those
act on a single occurrence: with the smearing done first the applier
saw seven replicas where the user had named one charge, and
`change_series_amount` charged a variable bill seven times over. See
[Projection math — cadence jitter](projection-math.md#cadence-jitter).

### Mutations compose in creation order

`ScenarioQuery::mutationsFor` returns mutations ordered by primary key
ascending — creation order — and `ScenarioApplier::apply` folds them
one at a time, each rebuilding the whole contribution list from the
output of the last.

Order therefore matters, and the ordering is the order the user added
them in. "Change Netflix to €15, then cancel Netflix" and "cancel
Netflix, then change Netflix to €15" both end with Netflix gone, but
"shift rent by +8 days, then cancel rent" and the reverse differ if
anything downstream reads dates. There is no commutativity guarantee
and none is needed, as long as the order is the stable, user-visible
one rather than whatever the database felt like returning.

### The `seriesId = 0` sentinel

Contributions synthesised inside the pipeline carry `seriesId = 0`:
the card settlement the router appends, the funder-collapse aggregate,
and the contributions that `add_one_off` and `add_recurring` create.
Zero means "not traceable to a recurring series".

Three of the five mutation kinds — `cancel_series`,
`change_series_amount`, `shift_series_date` — select contributions by
matching `seriesId`. A persisted mutation carrying series id `0` would
therefore match every synthesised contribution at once and, for
`cancel_series`, delete all of them.

That cannot happen, because `AddScenarioMutation` extracts the target
series id from the payload for exactly those three kinds and calls
`assertSeriesOwnedByUser`, which requires a `recurring_series` row with
that id belonging to the calling user. No such row has id `0`, so `0`
never persists. The applier depends on this guarantee and does not
re-check it.

### The reads the applier is allowed to make

`ScenarioApplier` touches the database in four places, and it is
worth being explicit about all of them:

- `ScenarioQuery::mutationsFor` — `Forecasting`-owned tables only.
- `RecurringSeriesQuery::forSeries` — a typed `Public` surface from
  [`Recurring`](../recurring/architecture.md), read purely to recover
  the series' variance tolerance and the sign of its latest amount so
  `change_series_amount` can rebuild a band. It never reads observed
  occurrences.
- `accounts` — to pick a landing account for a one-off when the
  baseline is empty.
- A `recurring_series` existence check used only to decide whether to
  log a warning.

Every one is a read. Nothing in the module writes to `transactions`,
`recurring_series`, `card_statements`, `chain_links` or `drift_alerts`
at all.

A one-off in a second currency used to take the whole projection down.
The applier emitted it with a null rate whatever currency the form was
given, and `DailyFold` refused to fold a cross-currency amount with no
rate — by raising, which fails the run. The mutation persists, so every
retry of the queued job re-crashed on it, and the reader was never told:
a non-complete run falls through to `ForecastQuery`'s flat-line
fallback, so the scenario chart drew a straight line forever. The
applier now emits the contribution in the currency the form was given
and leaves the rate to `DailyFold`, which resolves one per currency
against the account it is folding into and names what it cannot price;
`ForecastDto` carries `runFailed` so a failed run says so on screen
instead of passing its fallback line off as a projection. The currency
field itself is a select of the reader's own account currencies, and
`ScenarioMutationPayload` folds the case and refuses a code that is not
ISO-4217 — `usd` and `ZZZ` both used to persist unchecked.

### Cross-user references fail closed

`RecurringSeriesQuery::forSeries` is user-scoped, so a series id
belonging to another user resolves to null and the mutation is skipped
— the contributions pass through untouched. That skip is the safe
outcome, but it is indistinguishable from "the series was deleted", so
`logCrossUserMismatchIfAny` re-checks whether a row with that id exists
for *anyone*. If it does, the reference crossed a user boundary, which
the `Public` Actions make impossible; a warning is logged naming the
mutation kind, series id and user id, and the run continues. The only
way to reach it is a seeder, an Artisan command or an admin tool that
skipped the Action layer, which is exactly the thing that would
otherwise go unnoticed.

## What enforces it, and where the enforcement stops

The structural guard is the `noScenarioMutationsJoinedToTransactionQueries`
invariant in `tests/Contracts/BoundaryArchTest.php`. It walks the
entire `Modules/` tree — not just `Forecasting`, because the failure
mode is any future contributor anywhere reaching for a convenience
join — strips comments, and fails a file that contains **both** a join
against `forecast_scenario_mutations` and a mention of
`transactions`, `recurring_series_occurrences`, `chain_links` or
`card_statements`. `tests/` is excluded so contract suites can
synthesise both substrates side by side.

Being honest about its reach matters as much as having it:

- It matches `->join(...)`, `->leftJoin(...)`, `->rightJoin(...)` and
  `->crossJoin(...)` with the mutations table as the **argument**. A
  join written the other way round — starting from `transactions` and
  joining the mutations table on — is not caught by this pattern.
- It is a source grep. Raw SQL assembled in a string, or a table name
  built from a variable, passes through.
- It says nothing about writes. That is the separate repo-wide
  `crossModuleRawTableWrites` invariant, which pins every raw-table or
  raw-SQL write a module makes against a table it does not own.
  `Modules/Forecasting` has two, both against `accounts`. Writes made
  through an Eloquent model class are outside its scope.
- It proves nothing about runtime. That is
  `tests/Contracts/ScenarioIsolationContractTest.php`, which seeds a
  real substrate, runs every scenario lifecycle Action plus a full
  `ProjectForecastJob`, and asserts the substrate row counts are
  byte-for-byte unchanged afterwards — including a second user's rows,
  so a missing `user_id` filter fails too.
- Row counts are only half of it. "Absent from every other read"
  includes reads over tables the substrate check never looks at, and
  the notification inbox was one: a what-if dip raised a real
  `ForecastShortfallDetected`, `Notifications` wrote an inbox row for
  it, and the reader was warned about a shortfall they had only asked
  a question about. `ShortfallDetector` now dispatches nothing for a
  scenario run and `PersistForecastShortfall` refuses a scenario event
  should one arrive from anywhere else; the contract test asserts the
  window is written and the inbox stays empty.

The grep is a tripwire against the easy mistake, not a proof. The
proof is the runtime contract test; the design property is that
`ScenarioApplier` is a pure function with nothing to write with.

## What breaks without the boundary

Concretely, if a scenario ever reached the transaction substrate:

- **Net worth and account balances become fiction.** `NetWorthQuery`
  and `BalanceAnchorResolver` both derive from real rows. A
  hypothetical €900 bike in the sum means the number on the dashboard
  is not the user's money.
- **A paired device inherits the fiction permanently.** Sync captures
  substrate mutations and ships them to the peer, where they arrive as
  ordinary rows with no scenario provenance. There is nothing to
  reverse.
- **Recurring detection retrains on invented history.**
  [`Recurring`](../recurring/architecture.md) infers cadence, latest
  amount and variance tolerance from observed occurrences. Feed it
  hypothetical charges and it will re-derive a series around them, and
  the next baseline forecast — the one with no scenario active —
  quietly inherits the what-if.
- **Exports leave the machine wrong.** A tax export or a report built
  over a polluted substrate is wrong in a document the user hands to
  someone else, long after the scenario has been deleted.

Every one of these is silent. None of them throws, and none of them is
visible on the screen where the mistake was made. That is why the
enforcement is structural rather than a code-review convention.

## Related pages

- [`Forecasting` — architecture](architecture.md) — the five mutation
  kinds, the one-off landing-account rule, and the module's wiring.
- [Projection math](projection-math.md) — what the contributions the
  applier transforms actually contain, and what happens to them next.
- [Forecast fixture corpus](forecast-corpus.md) — includes a reference
  scenario exercising all five mutation kinds at once.
- [`Recurring`](../recurring/architecture.md) — the series substrate a
  scenario models against and must never write to.
- [`Ledger`](../ledger/architecture.md) — accounts and the transaction
  substrate the boundary protects.
