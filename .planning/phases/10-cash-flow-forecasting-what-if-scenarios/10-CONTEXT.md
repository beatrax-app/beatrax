# Phase 10: Cash-Flow Forecasting + What-If Scenarios - Context

**Gathered:** 2026-05-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 10 ships the cash-flow forecasting + what-if-scenarios layer on top of the locked Phase 1–9 substrate. The deliverable is: a new `Modules/Forecasting/` bounded module that projects per-account balance forward 30 / 60 / 90 days from a derived starting balance + approved Recurring inflows/outflows (Phase 8) + the next pending ICS bulk-iDEAL settlement (Phase 5), renders the projection as honest **ranges** (not single-point values) on an ApexCharts `rangeArea` band, highlights surplus/shortfall windows against a per-account user-set buffer, and supports ephemeral-by-construction (yet user-saveable as named scenarios) what-if mutations compared against the baseline in a two-panel side-by-side layout. The chain layer is honored: ASN's projection deducts the upcoming ICS bulk-iDEAL settlement on its forecast date so the user sees the dip on the funder, not a fake balance on the credit card.

**What Phase 10 delivers (vertical):**
- A new `Modules/Forecasting/` bounded module mirroring `Modules/DriftAlerts/` (Phase 9) / `Modules/Recurring/` (Phase 8) / `Modules/Chains/` (Phase 5). Public/Internal split from day one. composer.json, `ForecastingServiceProvider`, dedicated tests dir.
- Six migrations:
  - `forecast_scenarios` — `id`, `user_id` (FND-03), `name`, `description` (nullable), `created_at`, `updated_at`. Holds named saved scenarios the user can re-open. NEVER joined into transaction queries (enforced by arch test).
  - `forecast_scenario_mutations` — `id`, `user_id`, `forecast_scenario_id`, `kind` enum (`cancel_series` / `add_one_off` / `add_recurring` / `change_series_amount` / `shift_series_date`), `target_series_id` nullable (FK Recurring), `payload` JSON (typed envelope per kind), `created_at`. The five mutation kinds correspond to the five what-if operations Phase 10 supports.
  - `forecast_shortfall_windows` — `id`, `user_id`, `account_id` (FK Ledger), `scenario_id` nullable (NULL = baseline), `starts_at`, `ends_at`, `lowest_balance_minor`, `currency`, `buffer_used_minor` (captures the effective threshold at detection time so audit stays honest if buffer later changes — Phase 9 D-915 analog). Computed asynchronously per sweep; UI reads from it for the dashboard "Forecast highlights" tile.
  - `forecast_runs` — `id`, `user_id`, `scenario_id` nullable, `horizon_days` (30/60/90), `started_at`, `completed_at`, `status` enum. Audit trail for forecasting compute (mirrors Phase 5 `chain_resolution_runs` + Phase 9 detection-job lifecycle).
  - `accounts.forecast_min_buffer_minor` (nullable BIGINT, FND-04, default NULL = effective 0 = zero-crossing) — the per-account shortfall buffer.
  - `accounts.opening_balance_minor` (nullable BIGINT) + `accounts.opening_balance_as_of_date` (nullable date) — user-input opening balance fallback for sources without statement-balance lines (PayPal CSV; legacy CSV-only ASN imports).
- A `BalanceAnchorResolver` (Internal): for each account, returns `(opening_balance_minor, opening_balance_as_of_date)` from the most authoritative source available — ASN: latest CAMT.053 `statement_summaries.closing_balance`; ICS: latest `card_statements.closing_balance` (Phase 5); PayPal / CSV-only: `accounts.opening_balance_minor`. The starting balance for any forecast = anchor + sum-of-transactions-since-anchor-date.
- A `RangeProjector` (Internal): for each approved Recurring series, generates per-occurrence (date, low, point, high) triples. Two-tier math:
  - **Envelope tier (default, stable series):** `low = latest_amount × (1 − variance_tolerance_percent)`, `high = latest_amount × (1 + variance_tolerance_percent)`, `point = latest_amount`. Cadence-date jitter adds a fixed ±3-day window on each occurrence date so the band thickens around uncertain charge dates.
  - **Percentile tier (volatile series):** P10 / P50 / P90 of historical `recurring_series_occurrences.observed_amount_minor`. Trigger: planner-discretion (D-1014) — likely auto-derived from `variance_tolerance_percent ≥ 40%` OR `<3 stable-amount occurrences in last 6 with stddev > X`, with no new user-facing toggle.
  - Daily fold: at any forecast day D, the running balance band is `opening_balance + Σ(point), ± √(Σ(spread²))` — spreads combine in quadrature (statistically correct for independent series). FX: every projection is computed in original currency per series and converted to the account's `default_currency` using each occurrence's `latest_fx_rate_used` (Phase 8 D-840 carry-forward).
- A `ChainAwareForecastRouter` (Internal): consumes `Modules\Chains\Public\Services\ChainLinkQuery` + `CardStatementQuery`. Routes each recurring series's projected occurrence onto the funder account (not the funded account) on its actual debit date. The monthly ASN→ICS bulk-iDEAL settlement appears as a single negative ASN occurrence on the next settlement date (sourced from Phase 5 `CardStatementQuery::nextSettlementForUser`), with the running ICS view showing "amount you'll owe by next settlement" built from cleared ICS lines since the last settlement (reuses Phase 5 math). PayPal-funded-by-ASN/ICS charges deduct from the funder on the actual debit date; PayPal's own forecast view is informational and shows only pending settlements out.
- A `/forecast` route + Livewire SFC `ForecastPage`: per-account switcher (or "All accounts" tab) + 30/60/90-day toggle (default 30, `#[Url]`-bound per Phase 3 D-44 precedent) + scenario picker (baseline + saved scenarios + "New scenario") + side-by-side two-panel comparison (baseline left, scenario right) + scenario mutation editor sidebar + "view by funder" toggle (collapses chain-resolved series onto the funder account row, `#[Url]`-bound).
- ApexCharts `rangeArea` chart per panel: translucent fill between low and high (`fill: { opacity: 0.2 }`), bold center line for point estimate, crosshair tooltip showing `"€1,180 – €1,260 (≈ €1,220) on May 31"`. Red shaded band below the per-account `forecast_min_buffer_minor` with an inline "Shortfall starts May 22 (€−80 below your €500 buffer)" badge on the chart. Y-axis range is shared across the two comparison panels for honest visual delta reading. A "Net diff at day 30 / 60 / 90" delta tile sits between or above the two panels so the user sees the scenario-vs-baseline impact at a glance without ping-pong reading.
- A "forecast accuracy" confidence legend sidebar on `/forecast`: each series listed with a confidence chip ("Netflix: high · Electricity: low") derived from the series's range width relative to its point estimate (planner picks the buckets — D-1024).
- A dashboard `/` change: the Phase 5 "Next ICS settlement" tile is **replaced** by a new "Forecast highlights" tile that surfaces (a) the lowest projected balance + any shortfall windows across all accounts in the next 30 days (e.g. `"ASN dips to €120 on May 22 — below your €500 buffer"`), and (b) the next ICS settlement amount + date (preserving the Phase 5 surface). The underlying Phase 5 `CardStatementQuery` stays — the new tile reads from BOTH `Modules\Chains\Public\Services\CardStatementQuery` AND the new `Modules\Forecasting\Public\Services\ForecastHighlightsQuery`. One click on the tile drills to `/forecast`.
- A top-nav "Forecast" slot with no badge by default (badge appears only when one or more shortfall windows are active in the next 30 days). View Factory composer pattern from Phase 5/6/7/8/9.
- A `/settings` extension: per-account `forecast_min_buffer_minor` editor (one numeric input per account row) + per-account `opening_balance_minor` + `opening_balance_as_of_date` editor (Mijn ICS PDF / PayPal CSV / legacy CSV-only ASN imports surface this). Extends the Phase 3 `/settings` page pattern.
- Public Actions: `CreateScenario`, `RenameScenario`, `DeleteScenario`, `AddScenarioMutation`, `RemoveScenarioMutation`, `SetAccountForecastBuffer`, `SetAccountOpeningBalance`.
- Public Events: `ScenarioCreated`, `ScenarioMutated`, `ScenarioDeleted`, `ForecastShortfallDetected` (for Phase 11 operational hardening hooks if needed).
- A `ProjectForecastJob` (`ShouldBeUniqueUntilProcessing` per `(user_id, scenario_id ?? 0, horizon_days)`) that pre-computes forecast points and writes `forecast_shortfall_windows` rows. Dispatched on: (a) scheduled daily sweep `Schedule::daily()`; (b) any `RecurringSeriesApproved` / `RecurringSeriesCadenceFlipped` / `RecurringSeriesRejected` / `RecurringSeriesMetricsRefreshed` event from Phase 8; (c) any `DriftAlertDismissedCancelled` event from Phase 9 (cancelled series falls out of projection); (d) `ScenarioCreated` / `ScenarioMutated` events. Re-entry-safe via unique key + DB transaction per write.
- `Modules/Forecasting/Public/Services/ForecastQuery` — read API for `/forecast` page: `forUser(int $accountId, int $horizonDays, ?int $scenarioId): ForecastDto`. Returns per-day (low, point, high) triples + per-series breakdown.
- `Modules/Forecasting/Public/Services/ScenarioQuery` — read API: `forUser(User $user): array<ScenarioDto>` + `mutationsFor(int $scenarioId, User $user): array<ScenarioMutationDto>`.
- `Modules/Forecasting/Public/Services/ForecastHighlightsQuery` — read API: `forUser(User $user): ForecastHighlightsDto` returning the lowest projected balance + active shortfall windows in the next 30 days. Dashboard tile reads this + Phase 5's `CardStatementQuery`.
- Five BoundaryArchTest invariants (mirroring Phase 9 D-902): `noFacadeCallsFromForecasting` (DI-only carry-forward); `noTransactionWritesFromForecasting` (analytical-only constraint — Forecasting NEVER writes to `transactions`, `recurring_series`, `card_statements`, `chain_links`, `drift_alerts`); `crossModuleAccessGoesThroughPublic` (every Forecasting import of `Modules\{Recurring,Chains,DriftAlerts}\*` MUST go through `Modules\{...}\Public\*`); `noSynchronousForecastingInRequestLifecycle` (heavy projection runs only inside `ProjectForecastJob` — request-time reads pull pre-computed results from `forecast_shortfall_windows` + scenario mutations cache); **`noScenarioMutationsJoinedToTransactionQueries`** (load-bearing FCT-03 invariant — `forecast_scenario_mutations` rows are NEVER JOINed onto `transactions` or any series query; static analysis + grep-style arch check).
- Synthesised fixture-first Wave 0 (same precedent as Phase 5 D-107 + Phase 6 D-140 + Phase 8 D-845 + Phase 9 D-923): stable monthly subscription (envelope tier, tight band), drifting subscription mid-window, variable utility (percentile tier, wide band), salary income + side income, ICS settlement scenario across the chain, FX-only month-over-month USD subscription (band in USD primary), zero-occurrence edge case (skip), buffer-crossing scenario, multi-account baseline, scenario with each of the five mutation kinds.
- A `tests/Contracts/ForecastingProjectionContractTest.php` end-to-end against the Wave 0 fixture corpus (mirrors Phase 8 `RecurringDetectionContractTest`).
- A `tests/Contracts/ScenarioIsolationContractTest.php` that asserts scenario mutations never bleed into any transaction query (the FCT-03 boundary).

**What Phase 10 does NOT deliver:**
- Operational hardening — backups, restore verification, launchd polish (FND-05) — Phase 11.
- Push notifications, email digests on shortfall — PLT-01 localhost-only.
- Outbound payment initiation (actually paying the ICS settlement) — explicitly out of scope per PROJECT.md "Out of Scope" → "Outbound payments".
- Multi-currency conversion at the FORECAST level beyond what's already preserved per-occurrence (each series projects in its original currency; the per-account chart converts using the latest fx_rate per occurrence; we do NOT introduce a new FX rate provider in Phase 10).
- Drift-correlation forecasting (Phase 9 deferred).
- Goal-based forecasting ("save €X by date") — v2.
- Account-level sub-budgets ("only €200/mo on dining") — v2 categorization layer.
- An `/accounts/{id}` page — Phase 10 does not build per-account infrastructure beyond the `/forecast` switcher. If `/accounts` lands later, the forecast view can be embedded there as a tab.
- Mutations of `transaction.type`, `recurring_series.state`, `drift_alerts.state`, or any other write to upstream modules — Forecasting is strictly analytical (`noTransactionWritesFromForecasting` arch test).

**Architectural anchor:**
Phase 10 is the third analytical layer (after Phase 8 Recurring + Phase 9 DriftAlerts) sitting on top of the locked Phase 1–7 transaction pipeline + Phase 5 chain layer. The split — Recurring publishes events with series + occurrence data, DriftAlerts publishes events with cancellation impact, Chains publishes settlement data, and Forecasting consumes all three to project futures — keeps each module focused on its own lifecycle. Scenarios are first-class persisted entities (the user wants to come back to them after dinner) BUT scenario mutations are HARD-walled off from any transaction query by the `noScenarioMutationsJoinedToTransactionQueries` arch test, so FCT-03's "never persist to the database" invariant is structurally enforced rather than convention-based.

</domain>

<decisions>
## Implementation Decisions

### Balance Source + Per-Account Scope + Chain Routing

- **D-1001:** **Per-account starting balance derived from the most authoritative source available, with user-input fallback.** ASN: latest CAMT.053 `statement_summaries.closing_balance` + sum-of-transactions-since-that-statement's `to_date`. ICS: latest `card_statements.closing_balance` (Phase 5) + delta-since. PayPal + CSV-only sources: `accounts.opening_balance_minor` + `accounts.opening_balance_as_of_date` (two new nullable columns; user-input via `/settings`). The `BalanceAnchorResolver` Internal service encapsulates the strategy per `account.kind`. Pure-sum-of-transactions was rejected because most accounts' ingested history starts mid-stream. Hybrid auto-correct-on-next-statement was rejected as additional state churn for marginal benefit (D-1011).
- **D-1002:** **Chain-aware routing by default, with an opt-in "view by funder" toggle.** ASN projection deducts the upcoming ICS bulk-iDEAL settlement on its forecast date (sourced from Phase 5 `CardStatementQuery::nextSettlementForUser`); ICS view shows running "amount owed by next settlement" built from cleared ICS lines since last settlement; PayPal-funded-by-ASN/ICS charges deduct from the funder on actual debit date. A `#[Url]`-bound "View by funder" toggle on `/forecast` collapses chain-resolved series onto the funder account row so power users who think in terms of "where will my real money be on the 30th" get a single rollup line per funder. Per-account naive projection was rejected as losing the project's chain-visibility differentiator; single-household-line was rejected as conflicting with FCT-01's "per account" wording.
- **D-1003:** **All real accounts get forecasts.** ASN + ICS + PayPal. Forecasting reads `accounts` table (any `kind`) for the user. No "primary account" flag in v1 — falls out naturally from the per-account switcher UI.

### Uncertainty Math + Range Display (FCT-02)

- **D-1004:** **Two-tier range math: envelope (default) + historical-percentile (volatile series).** Envelope: `low = latest_amount × (1 − variance_tolerance_percent)`, `high = latest_amount × (1 + variance_tolerance_percent)`, `point = latest_amount` — reuses Phase 8 D-825 per-series variance verbatim. Percentile: P10 / P50 / P90 of historical `recurring_series_occurrences.observed_amount_minor` for series flagged volatile (trigger derived from variance_tolerance + occurrence-stddev heuristic — planner picks the exact bucket logic; see D-1014). No new user-facing toggle — the tier choice is internal. Cadence-date jitter adds a fixed ±3-day window on each occurrence date. Daily fold: at day D, `running_balance = opening_balance + Σ(point); spread = √(Σ(spread²))` — quadrature combination (statistically correct for independent series). Monte Carlo and pure-historical-percentile-everywhere were rejected — the former is overkill, the latter is fragile on new series with <6 occurrences.
- **D-1005:** **Range rendered as ApexCharts `rangeArea` translucent band + bold center line + crosshair tooltip + per-series confidence legend.** The band has `fill: { opacity: 0.2 }`; the center line is the point estimate; the tooltip text is `"€1,180 – €1,260 (≈ €1,220) on May 31"`. A confidence legend sidebar on `/forecast` lists each series with a high/medium/low chip derived from the band's relative width (planner picks the bucket thresholds — D-1024). Two-visible-lines and hover-only-range were rejected — the former is busier and less calm; the latter loses the at-a-glance honesty FCT-02 demands.
- **D-1006:** **FX handling: each series projects in its original currency; per-account chart converts using each occurrence's `latest_fx_rate_used`.** Phase 8 D-840 carry-forward. EUR shadow rendered alongside original-currency on the per-series legend; the per-account chart uses the account's `default_currency` (typically EUR). No new FX rate provider; we use the per-occurrence stored rate. A series whose currency switches mid-window remains two separate series (Phase 8 D-839 carry-forward).

### What-If Surface — Operations, Persistence, Comparison (FCT-03 / FCT-04)

- **D-1007:** **Five what-if mutation kinds.** (1) `cancel_series` — locked by the Phase 9 D-919/D-920 hand-off (subscribes to `DriftAlertDismissedCancelled` AND can be triggered directly from `/forecast` / `/recurring/series/{id}`). (2) `add_one_off` — date + amount + currency + direction (±). (3) `add_recurring` — date + amount + currency + direction + cadence (weekly/monthly/quarterly/yearly). (4) `change_series_amount` — overrides `latest_amount_minor` for the scenario only. (5) `shift_series_date` — date-shift on the next-expected occurrence (planner picks whether subsequent occurrences shift with it or stay on cadence; likely "shift with" matches user intent — D-1015).
- **D-1008:** **Scenarios are persisted as named saved DB entities; mutations are NEVER joined to transaction queries.** Two new tables: `forecast_scenarios` (id, user_id, name, description, timestamps) + `forecast_scenario_mutations` (id, user_id, forecast_scenario_id, kind enum, target_series_id nullable, payload JSON, created_at). The user opens `/forecast`, picks a saved scenario or creates a new one, adds mutations via the sidebar, sees the side-by-side comparison. FCT-03 "no persistence to the DB" is satisfied by treating "the DB" as the **transaction/series substrate** — scenario state is a separate concern. The `noScenarioMutationsJoinedToTransactionQueries` BoundaryArchTest invariant structurally enforces this: any JOIN of `forecast_scenario_mutations` onto `transactions` / `recurring_series_occurrences` / `chain_links` is a CI failure. Ephemeral-only-session was rejected because users want resumable modeling.
- **D-1009:** **Comparison UI = two-panel side-by-side layout, baseline left, scenario right, shared y-axis range.** Two separate ApexCharts `rangeArea` charts rendered side-by-side. Y-axis range is identical across both panels so the visual delta is honest. A "Net diff at day 30 / 60 / 90" delta tile renders above or between the two panels showing scenario-minus-baseline at each horizon (e.g. `+€48 at day 30 · +€120 at day 60 · +€216 at day 90`) so the user gets the answer without ping-pong reading. FCT-04's literal "side-by-side" wording wins over single-overlay-chart aesthetics. Multi-scenario overlay deferred; single-scenario-vs-baseline only in v1.
- **D-1010:** **Scenario discoverability surfaces: `/forecast` page + Phase 9 drift alert "What if I cancel" button + `/recurring/series/{id}` "Model cancel" link.** The Phase 9 D-920 `DismissDriftAlertAsCancelled` action becomes a launchpad: clicking it offers "Just dismiss" OR "Model cancel in forecast" (creates a new scenario pre-seeded with a `cancel_series` mutation and drills to `/forecast`). `/recurring/series/{id}` gets a parallel "Model what-if" link. Calm: discoverability is contextual, not banner-style.

### Surplus / Shortfall + Page Placement (FCT-05 + UI)

- **D-1011:** **Per-account `forecast_min_buffer_minor` (nullable BIGINT, FND-04, default NULL = effective 0 = zero-crossing).** Editable inline per account on `/settings` and per-account-row on `/forecast`. Mirrors Phase 8 D-825 + Phase 9 D-915 per-row threshold-editor pattern. The chart shades the band red below the buffer; an inline `"Shortfall starts May 22 (€−80 below your €500 buffer)"` badge surfaces on the chart. The effective buffer is captured on each persisted `forecast_shortfall_windows` row (`buffer_used_minor`) so the audit trail stays honest if the buffer is later changed (Phase 9 D-915 `threshold_percent_used` analog).
- **D-1012:** **Shortfall windows pre-computed asynchronously by `ProjectForecastJob` and written to `forecast_shortfall_windows`.** Dashboard "Forecast highlights" tile reads from this table (cheap read). Re-computation triggers: scheduled daily sweep; Phase 8 / Phase 9 events listed in the architectural section; `ScenarioCreated` / `ScenarioMutated`. Request-time computation was rejected — projection is too heavy for the request lifecycle (matches Phase 8 D-833 `noSynchronousDetectionInRequestLifecycle` precedent).
- **D-1013:** **Forecast lives on a new top-level `/forecast` page; the dashboard Phase 5 "Next ICS settlement" tile is REPLACED by a new "Forecast highlights" tile.** The new tile surfaces (a) lowest projected balance + active shortfall windows in the next 30 days, and (b) the next ICS settlement amount + date (preserving the Phase 5 surface). The underlying Phase 5 `CardStatementQuery` stays unchanged — the new tile is the only thing that moves. `Modules\Forecasting\Public\Services\ForecastHighlightsQuery` is the new read API the tile consumes (alongside `CardStatementQuery`). One click on the tile drills to `/forecast`. Top-nav "Forecast" slot is added (no badge by default; badge appears only when an active shortfall window exists in the next 30 days). UI-SPEC pass owns final top-nav positioning given Phase 8 D-853 + Phase 9 D-927 crowding concerns.

### Module Placement + Boundary Tests

- **D-1014:** **New `Modules/Forecasting/` bounded module owns scenarios + projection job + `/forecast` + dashboard tile + per-account buffer.** Mirrors Phase 9 D-901's `Modules/DriftAlerts/` precedent. Public/Internal split from day one. Owns: `forecast_scenarios` + `forecast_scenario_mutations` + `forecast_shortfall_windows` + `forecast_runs` tables, `accounts.forecast_min_buffer_minor` + `accounts.opening_balance_minor` + `accounts.opening_balance_as_of_date` columns (on Ledger's `accounts` table — Phase 10 owns the migration that adds them; the table itself stays in Ledger), the `ProjectForecastJob` + the projection pipeline (`BalanceAnchorResolver` + `RangeProjector` + `ChainAwareForecastRouter`), the `/forecast` Livewire SFC + the "Forecast highlights" dashboard tile composer, and the five Public read services (`ForecastQuery` / `ScenarioQuery` / `ForecastHighlightsQuery` plus the `CancellationImpactQuery` re-export from Phase 9). Folding into Recurring was rejected as boundary dilution; folding into DriftAlerts was rejected because forecasting is a fundamentally different concern (projection vs detection).
- **D-1015:** **Five BoundaryArchTest invariants.** (1) `noFacadeCallsFromForecasting` — DI invariant carry-forward; no `auth()` / `request()` / `config()` / `Auth::` / `DB::` etc. inside `Modules/Forecasting/`. (2) `noTransactionWritesFromForecasting` — Forecasting is analytical-only; NEVER writes to `transactions`, `recurring_series`, `card_statements`, `chain_links`, `drift_alerts`. (3) `crossModuleAccessGoesThroughPublic` — every Forecasting import of `Modules\{Recurring,Chains,DriftAlerts,Ledger}\*` MUST go through `Modules\{...}\Public\*`. (4) `noSynchronousForecastingInRequestLifecycle` — heavy projection runs only inside `ProjectForecastJob`; request-time reads pull pre-computed results from `forecast_shortfall_windows` + scenario mutations cache. (5) **`noScenarioMutationsJoinedToTransactionQueries`** — load-bearing FCT-03 invariant; any JOIN of `forecast_scenario_mutations` onto `transactions` / `recurring_series_occurrences` / `chain_links` / `card_statements` is a CI failure (Pest `arch()` + grep-style enforcement; planner picks the exact mechanism).
- **D-1016:** **Public surface = Queries + DTOs + Events + Actions; the projection pipeline + state + Livewire stay Internal.** Public: `ForecastQuery` / `ScenarioQuery` / `ForecastHighlightsQuery`, `ForecastDto` / `ScenarioDto` / `ScenarioMutationDto` / `ForecastHighlightsDto` / `ShortfallWindowDto`, `ScenarioCreated` / `ScenarioMutated` / `ScenarioDeleted` / `ForecastShortfallDetected` events, and the seven Public Actions (`CreateScenario` / `RenameScenario` / `DeleteScenario` / `AddScenarioMutation` / `RemoveScenarioMutation` / `SetAccountForecastBuffer` / `SetAccountOpeningBalance`). The `BalanceAnchorResolver` / `RangeProjector` / `ChainAwareForecastRouter` / `ProjectForecastJob` / `ForecastingProjectionPipeline` stay Internal.

### Trigger Model + Job Lifecycle

- **D-1017:** **`ProjectForecastJob` (`ShouldBeUniqueUntilProcessing` per `(user_id, scenario_id ?? 0, horizon_days)`) computes forecasts asynchronously.** Dispatched on: (a) scheduled daily sweep (`Schedule::call(...)->daily()`); (b) any Phase 8 `RecurringSeriesApproved` / `RecurringSeriesCadenceFlipped` / `RecurringSeriesRejected` / `RecurringSeriesMetricsRefreshed` event (changed recurring substrate → recompute); (c) any Phase 9 `DriftAlertDismissedCancelled` event (cancelled series falls out of projection); (d) `ScenarioCreated` / `ScenarioMutated` events. Re-entry-safe via unique key + DB transaction per write. Inline-on-page-render was rejected — projection is too heavy for the request lifecycle. Pure-on-demand was rejected because the dashboard tile needs fresh data at page load.

### Claude's Discretion (planner picks)

- **D-1018:** Wave structure (suggested: Wave 0 = `Modules/Forecasting/` skeleton + 5 boundary arch tests + synthesised fixture corpus + new event listeners scaffold + Pest registration; Wave 1 = migrations + 4 new tables + 3 column additions to `accounts` + Eloquent models + Public DTOs + state-machine if any; Wave 2 = `BalanceAnchorResolver` + `RangeProjector` envelope tier + `ProjectForecastJob` baseline-only + `ForecastingProjectionContractTest` Wave 2 dataset + `/forecast` skeleton with per-account chart for baseline only; Wave 3 = `ChainAwareForecastRouter` + chain integration + shortfall detection + `/settings` per-account buffer editor + dashboard "Forecast highlights" tile + Phase 5 tile removal + top-nav slot; Wave 4 = scenarios full CRUD + side-by-side two-panel comparison + delta tile + scenario sidebar editor + Phase 9 drift-alert "Model cancel" launchpad + `/recurring/series/{id}` "Model what-if" link; Wave 5 = percentile-tier range math + confidence legend sidebar + cadence-date jitter + opening-balance editor for PayPal/CSV-only sources + `ScenarioIsolationContractTest` + multi-currency edge-case polish). Planner verifies against goal-backward analysis.
- **D-1019:** Exact mechanism for the "volatile series" tier-switch trigger (D-1004). Likely auto-derived from `variance_tolerance_percent ≥ 40%` OR a stddev-over-recent-occurrences heuristic. NO new user-facing toggle in v1. Planner picks the threshold + heuristic that passes the Wave 0 fixture corpus correctly.
- **D-1020:** Exact behavior of `shift_series_date` mutation when shifting (D-1007 §5): do subsequent occurrences shift with it (maintaining cadence offset) or stay on original cadence (just the next one is delayed)? Likely "shift with" matches user mental model ("my whole salary cycle is one week late this month"), but planner picks based on fixture review.
- **D-1021:** Whether scenario mutations are also held in Livewire component state for instant-feedback rendering BEFORE the `ProjectForecastJob` re-computes (optimistic UI vs job-completes-then-poll). Optimistic UI is more responsive; job-completes is simpler. Planner picks based on perceived latency of the projection job against the Wave 0 fixture corpus.
- **D-1022:** Whether `add_recurring` mutation occurrences are bounded by the chosen horizon (30/60/90) or by a fixed forward window (e.g. 365 days) so scenario rendering is consistent across horizon toggles. Likely bounded by horizon for memory efficiency.
- **D-1023:** Exact JSON schema for `forecast_scenario_mutations.payload` per `kind`. Suggested: `cancel_series → {series_id: int}`; `add_one_off → {date, amount_minor, currency, direction}`; `add_recurring → {start_date, amount_minor, currency, direction, cadence}`; `change_series_amount → {series_id, new_amount_minor}`; `shift_series_date → {series_id, new_next_date}`. Planner formalizes with a typed envelope (likely Spatie LaravelData DTO mapped via Eloquent cast — pattern reference: any existing module DTO).
- **D-1024:** Confidence-legend bucket thresholds (D-1005). Suggested: `band_width / point ≤ 10%` → high; `10–25%` → medium; `>25%` → low. Planner verifies against fixtures.
- **D-1025:** Top-nav slot positioning for "Forecast" given Phase 8 D-853 + Phase 9 D-927 crowding concerns. UI-SPEC pass owns this — may consolidate Recurring + Drift + Forecast under a single "Money" parent or similar.
- **D-1026:** Whether the dashboard "Forecast highlights" tile renders a tiny inline sparkline preview chart (compact ApexCharts) or stays text-only. Calm aesthetic argues text-only; UI-SPEC pass owns.
- **D-1027:** Whether `/forecast` shows ALL accounts as a stacked / aggregated single chart in addition to the per-account chart switcher. The "household cash position" view is intentionally NOT the only view (D-1002 rejected single-line as sole projection) but it could appear as an additional "All accounts" tab. Planner picks based on whether the chart axis logic supports stacked rangeArea cleanly.
- **D-1028:** Whether the `forecast_runs` audit table needs a `failed_reason` column or whether Horizon failed-job log is sufficient (Phase 5 D-95 precedent — re-use, don't duplicate).
- **D-1029:** Whether `accounts.opening_balance_minor` editor in `/settings` warns the user when the entered value diverges significantly from the computed sum-of-transactions-from-earliest (e.g. ">€500 delta → 'Are you sure?' modal"). Calm UX consideration; planner discretion.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-Level
- `.planning/PROJECT.md` — Project constraints. Critical here: DI-only invariant (constructor injection, no facades/helpers); `nwidart/laravel-modules` bounded modules with Public/Internal split; Larastan level 10 strict + Pint + Pest CI gates; calm aesthetic (Linear/Notion); GSD-agnostic code comments invariant; income as a first-class concept (not "negative expense") — applies directly to D-1007 (recurring + one-off direction toggle); local-only / PLT-01 — no push notifications on shortfall.
- `.planning/REQUIREMENTS.md` — Phase 10 covers FCT-01..05. Adjacent (already-met) requirements Phase 10 must respect: REC-01..08 + LED-06 (Phase 8 + Phase 9 recurring + drift substrate); CHN-01..07 (Phase 5 chain layer + ICS settlement); MC-01/02 (multi-currency display); FND-03 (per-user data isolation); FND-04 (BIGINT minor units); PLT-01 (localhost-only); UI-05 (calm aesthetic).
- `.planning/ROADMAP.md` §"Phase 10" — Goal + five success criteria (30/60/90-day per-account projection; ranges not single-point; surplus/shortfall windows; non-persisted what-if mutations; side-by-side comparison).

### Prior Phase Artefacts (read for continuity)
- `.planning/phases/09-subscription-drift-detection-alerts/09-CONTEXT.md` — **REQUIRED READ.** Phase 9 ships the `CancellationImpactQuery` Public service (D-919) Phase 10 reuses as the `cancel_series` mutation cost source. The `DriftAlertDismissedCancelled` event (D-920) is the launchpad for the Phase 10 "Model cancel in forecast" flow (D-1010). The four BoundaryArchTest invariants pattern (D-902) is the template for Phase 10's five invariants (D-1015). The `ShouldBeUniqueUntilProcessing` per-user keying (D-912) is reused for `ProjectForecastJob` (D-1017). The Phase 9 `drift_alerts.threshold_percent_used` honest-audit pattern (D-915) is mirrored for `forecast_shortfall_windows.buffer_used_minor` (D-1011).
- `.planning/phases/08-recurring-detection-fixed-payments-view/08-CONTEXT.md` — **REQUIRED READ.** Phase 8 ships the entire approved-series substrate Phase 10 reads. Critical anchors: `RecurringSeriesQuery` + `FixedPaymentsViewQuery` + `monthly_equivalent_minor` math (D-826); `recurring_series.variance_tolerance_percent` (D-825) feeds the envelope-tier range math (D-1004); `recurring_series_occurrences` table feeds the percentile-tier math; `direction` enum (D-816) drives the income/expense polarity in projections; `latest_currency` + `latest_fx_rate_used` (D-839/D-840) carry through to Phase 10 multi-currency display (D-1006); `RecurringSeriesApproved` / `RecurringSeriesCadenceFlipped` / `RecurringSeriesRejected` events trigger `ProjectForecastJob` re-runs (D-1017); the four BoundaryArchTest invariants (D-833) including `noTransactionWritesFromRecurring` is the precedent for `noTransactionWritesFromForecasting` (D-1015); the `noSynchronousDetectionInRequestLifecycle` invariant (D-833) is the precedent for `noSynchronousForecastingInRequestLifecycle` (D-1015); the "next-expected-charge" relative+absolute date format (D-830) extends to forecast-occurrence labels.
- `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-CONTEXT.md` — **REQUIRED READ.** Phase 5 ships the chain layer that Phase 10's `ChainAwareForecastRouter` reads. Critical: `chain_links` table + `ChainLinkQuery` Public surface (drives PayPal→funder routing); `card_statements` table + `CardStatementQuery::nextSettlementForUser` (D-1002 — Phase 10's ASN-deducts-ICS-settlement math reuses this); the existing dashboard "Next ICS settlement" tile (Phase 5) is the tile Phase 10 REPLACES with a more general "Forecast highlights" tile (D-1013) — the underlying `CardStatementQuery` stays unchanged; the `ShouldBeUniqueUntilProcessing` job pattern Phase 9 + Phase 10 reuse (D-1017); the `/chains/review` Livewire SFC precedent the `/forecast` page mirrors structurally.
- `.planning/phases/04-paypal-ingestion-transfer-detection/04-CONTEXT.md` — `pair_transaction_id` column (LED-04) — the `ChainAwareForecastRouter` honors transfer pairs to avoid double-counting internal moves in projections.
- `.planning/phases/03-ics-cards-multi-currency-display/03-CONTEXT.md` — D-44/D-47 multi-currency display rules + `/settings` page extension pattern + locale-aware `Money::format()` (anchor for D-1011 settings extension + D-1006 EUR shadow). The `default_currency_view` `#[Url]` toggle pattern (D-44) is the precedent for Phase 10's `/forecast` 30/60/90 toggle and "view by funder" toggle (D-1002).
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-CONTEXT.md` — `accounts` table shape, `transactions` table shape, BelongsToUser invariant. Phase 10 adds three nullable columns to `accounts` (`forecast_min_buffer_minor`, `opening_balance_minor`, `opening_balance_as_of_date`).
- `.planning/phases/02-asn-statement-coverage-camt-053-mt940/02-CONTEXT.md` — `statement_summaries` table with CAMT.053 closing balance — this is the canonical source for the ASN starting-balance anchor (D-1001).

### Research
- `.planning/research/SUMMARY.md` — Read for industry-consensus forecasting / cash-flow projection patterns. Quadrature-spread combination, P10/P90 percentile bands, and rangeArea visualization are textbook patterns; deep research not strictly required.
- `.planning/research/STACK.md` — ApexCharts confirmed locked (Phase 5/8 carry-forward); Horizon + Redis confirmed locked.
- `.planning/research/PITFALLS.md` — Any flagged forecasting / scenario-modeling pitfalls inform UX defaults.

### Existing Source (read before extending)
- `composer.json` — Phase 10 adds NO new dependencies. ApexCharts (rangeArea variant supported in v3+ already locked), Horizon, Pest, Spatie LaravelData (likely already present from Phase 7/8 — planner verifies) all in place. Composer audit must confirm no `ext-imap` regression (PLT-05 carry-forward).
- `Modules/Ledger/Database/Migrations/2026_05_12_010002_create_accounts_table.php` — `accounts` shape Phase 10 reads + extends with three nullable columns via a new migration.
- `Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php` — CAMT.053 closing-balance source for D-1001 ASN anchor.
- `Modules/Ledger/Public/Services/PeriodQuery.php` + `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` — Existing dashboard period query API; Phase 10 may reuse `PeriodQuery` for the "transactions-since-anchor-date" sum in `BalanceAnchorResolver` (planner picks vs new method).
- `Modules/Recurring/Public/Services/RecurringSeriesQuery.php` + `Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php` — Read APIs Phase 10 consumes for the approved-series substrate. `RecurringSeriesQuery::approvedForUser()` is the primary read; `RecurringSeriesQuery::occurrencesForSeries()` feeds the percentile-tier math.
- `Modules/Recurring/Public/Events/RecurringSeriesApproved.php` + `RecurringSeriesCadenceFlipped.php` + `RecurringSeriesRejected.php` + (likely) `RecurringSeriesMetricsRefreshed.php` — Phase 10 listens to these via a `ProjectForecastOnRecurringChange` listener that dispatches `ProjectForecastJob` (D-1017).
- `Modules/Chains/Public/Services/ChainLinkQuery.php` + `Modules/Chains/Public/Services/CardStatementQuery.php` — Read APIs the `ChainAwareForecastRouter` consumes for PayPal→funder routing and the next-ICS-settlement deduction (D-1002).
- `Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php` — Read API the `cancel_series` mutation uses for the cancellation cost (D-1007 §1).
- `Modules/DriftAlerts/Public/Events/DriftAlertDismissedCancelled.php` — Event Phase 10 listens to via a `ProjectForecastOnDriftDismissed` listener (D-1017).
- `Modules/Core/Public/Contracts/CurrentUser.php` — DI-only contract every Phase 10 service injects.
- `tests/Contracts/BoundaryArchTest.php` — Phase 10 adds FIVE new invariants (D-1015); existing carry-forwards (Phase 6/7/8/9 invariants) must stay green.
- `tests/Pest.php` — New `Modules\\Forecasting\\Tests\\` PSR-4 entry must be added (3-step pattern: composer.json autoload-dev + phpunit.xml testsuite + Pest.php).
- `routes/web.php` — New `GET /forecast` + Livewire action endpoints for scenario CRUD + per-account buffer editor. All gated by LoopbackOnly + Fortify auth.
- `routes/console.php` — New scheduled task: `Schedule::call(fn () => ProjectForecastJob::dispatch(...))->daily()` per-user (FND-03 multi-user-ready). And an hourly tick if request-time freshness on the dashboard tile proves insufficient against fixture coverage (planner picks).
- `app/Http/Composers/` — Phase 10 adds the "Forecast" top-nav slot composer + dashboard "Forecast highlights" tile composer (and REMOVES the Phase 5 "Next ICS settlement" tile composer — the new tile subsumes its surface).
- `resources/views/dashboard.blade.php` — Replace the Phase 5 "Next ICS settlement" tile with the new "Forecast highlights" tile.
- `/settings` Livewire SFC — Phase 10 adds the per-account `forecast_min_buffer_minor` editor + per-account `opening_balance_minor` + `opening_balance_as_of_date` editor. Follows Phase 3's `/settings` page extension pattern.

### External Documentation (Phase 10's research targets)
- ApexCharts `rangeArea` series type — https://apexcharts.com/docs/chart-types/range-area-chart/ — D-1005 chart variant.
- Livewire 4 docs — https://livewire.laravel.com/docs — `#[Url]` for the 30/60/90 horizon toggle + "view by funder" toggle (D-1002), `wire:poll` if optimistic-UI path is not chosen (D-1021), `$this->dispatch('toast')` for scenario CRUD feedback toasts.
- Flux UI segmented control + popover + tabs + numeric input — https://fluxui.dev/ — `/forecast` horizon + scenario picker, side-by-side panel layout, `/settings` per-account buffer editor.
- Pest `arch()` plugin docs — https://pestphp.com/docs/arch-testing — Five new BoundaryArchTest invariants, especially the load-bearing `noScenarioMutationsJoinedToTransactionQueries` (D-1015).
- Laravel queue docs — https://laravel.com/docs/queues — `ShouldBeUniqueUntilProcessing` per-user keying for `ProjectForecastJob`.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`Modules\Recurring\Public\Services\RecurringSeriesQuery` + `FixedPaymentsViewQuery` (Phase 8)** — Substrate Phase 10 reads for approved-series projection input.
- **`recurring_series.variance_tolerance_percent` (Phase 8 D-825)** — Drives the envelope-tier range math (D-1004) directly.
- **`recurring_series_occurrences` (Phase 8)** — Drives the percentile-tier range math (D-1004) for volatile series.
- **`recurring_series.latest_currency` + `latest_fx_rate_used` (Phase 8 D-840)** — FX preservation through forecast projection (D-1006).
- **`Modules\Chains\Public\Services\ChainLinkQuery` + `CardStatementQuery` (Phase 5)** — Chain-aware routing source (D-1002).
- **Phase 5 `card_statements` + `CardStatementQuery::nextSettlementForUser` math** — Phase 10 reuses for the ASN-deducts-ICS-settlement projection AND for the "Next ICS settlement" portion of the new dashboard tile (D-1013).
- **`Modules\DriftAlerts\Public\Services\CancellationImpactQuery` (Phase 9 D-919)** — Source for the `cancel_series` mutation impact (D-1007 §1).
- **`Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled` (Phase 9 D-920)** — Launchpad for the "Model cancel in forecast" flow (D-1010); also triggers `ProjectForecastJob` re-runs (D-1017).
- **`accounts` table (Phase 1)** — Phase 10 extends with three nullable columns (`forecast_min_buffer_minor`, `opening_balance_minor`, `opening_balance_as_of_date`); the table itself stays in Ledger.
- **`statement_summaries` (Phase 2 D-201+)** — CAMT.053 closing-balance source for ASN starting-balance anchor (D-1001).
- **`Modules\Ledger\Public\Services\PeriodQuery`** — Existing date-range query helper; Phase 10 may reuse for "transactions-since-anchor-date" sums.
- **Horizon + Redis (Phase 5)** — `ProjectForecastJob` runs on existing infrastructure.
- **`ShouldBeUniqueUntilProcessing` per-user keying (Phase 5/6/8/9 idiom)** — Reused for `ProjectForecastJob` (D-1017).
- **Phase 5/6/7/8/9 View Factory composer pattern** — Top-nav "Forecast" slot + dashboard "Forecast highlights" tile.
- **Phase 8 D-810 / Phase 9 snooze date-picker COMPONENT** — Potential reuse for the `shift_series_date` mutation date input (planner picks; the input pattern is generic enough).
- **Phase 3 D-44 `#[Url]` toggle pattern + Phase 8 D-855 dashboard toggle** — `/forecast` horizon + scenario + "view by funder" toggles all `#[Url]`-bind.
- **Phase 3 D-44/D-47 + Phase 8 D-840 multi-currency display** — Original-currency primary + EUR shadow on `/forecast` per-series legend (D-1006).
- **Phase 4/5 + Phase 8 D-814 + Phase 9 toast pattern** — `$this->dispatch('toast', ...)` + Alpine `x-on:toast.window` for scenario CRUD feedback (Create / Mutate / Delete) + buffer-change confirmations.
- **ApexCharts (Phase 5 + Phase 8 D-827)** — Phase 10 uses the `rangeArea` chart variant; no new chart library.
- **Cross-user 404 invariant (Phase 3/4/5/8/9)** — All `/forecast/*` actions enforce `$entity->user_id === $currentUser->id` + `where('user_id', ...)`.
- **DI-only + raw `DatabaseManager` for `whereBetween`/`whereIn`/`orderBy`** — Every Phase 10 service follows the locked invariant.
- **Phase 1 `LoopbackOnly` + Fortify auth** — All `/forecast/*` routes gated.

### New Code Surface (Phase 10 adds)
- **`Modules/Forecasting/` bounded module** — composer.json, `ForecastingServiceProvider`, Public/Internal split, dedicated tests dir.
- **`Modules/Forecasting/Public/Dto/`** — `ForecastDto`, `ForecastPointDto` (date, low, point, high, currency), `ScenarioDto`, `ScenarioMutationDto` (typed envelope per kind), `ForecastHighlightsDto`, `ShortfallWindowDto`, `BalanceAnchorDto`, `SeriesConfidenceDto`.
- **`Modules/Forecasting/Public/Events/`** — `ScenarioCreated`, `ScenarioMutated` (subtype on `kind`), `ScenarioDeleted`, `ForecastShortfallDetected`.
- **`Modules/Forecasting/Public/Actions/`** — `CreateScenario`, `RenameScenario`, `DeleteScenario`, `AddScenarioMutation`, `RemoveScenarioMutation`, `SetAccountForecastBuffer`, `SetAccountOpeningBalance`.
- **`Modules/Forecasting/Public/Services/ForecastQuery.php`** — Read API for `/forecast` page.
- **`Modules/Forecasting/Public/Services/ScenarioQuery.php`** — Read API for scenario list + mutation list.
- **`Modules/Forecasting/Public/Services/ForecastHighlightsQuery.php`** — Read API for dashboard tile.
- **`Modules/Forecasting/Internal/Pipeline/BalanceAnchorResolver.php`** — Per-account anchor resolution (D-1001).
- **`Modules/Forecasting/Internal/Pipeline/RangeProjector.php`** — Envelope + percentile tier math (D-1004).
- **`Modules/Forecasting/Internal/Pipeline/ChainAwareForecastRouter.php`** — Routes occurrences onto funders (D-1002).
- **`Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php`** — Applies a saved scenario's mutations on top of the baseline projection (D-1007 / D-1008).
- **`Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php`** — `ShouldBeUniqueUntilProcessing` per `(user_id, scenario_id ?? 0, horizon_days)`.
- **`Modules/Forecasting/Internal/Listeners/ProjectForecastOnRecurringChange.php`** + **`ProjectForecastOnDriftDismissed.php`** + **`ProjectForecastOnScenarioChange.php`** — Event-driven re-projection triggers (D-1017).
- **`Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php`** + **`ScenarioEditorSidebar.php`** + **`ForecastHighlightsTile.php`** (dashboard composer contributor) + **`AccountBufferEditor.php`** (inline on `/forecast` per-account row) + **`OpeningBalanceEditor.php`** (inline on `/settings` per-account row).
- **`Modules/Forecasting/Database/Migrations/*_create_forecast_scenarios_table.php`**.
- **`Modules/Forecasting/Database/Migrations/*_create_forecast_scenario_mutations_table.php`**.
- **`Modules/Forecasting/Database/Migrations/*_create_forecast_shortfall_windows_table.php`**.
- **`Modules/Forecasting/Database/Migrations/*_create_forecast_runs_table.php`**.
- **`Modules/Forecasting/Database/Migrations/*_add_forecast_columns_to_accounts.php`** — `forecast_min_buffer_minor` + `opening_balance_minor` + `opening_balance_as_of_date` (all nullable; lives in Forecasting's migration dir but targets Ledger's `accounts` table — same pattern Phase 9 used for `recurring_series.drift_threshold_percent`).
- **`tests/Contracts/BoundaryArchTest.php`** — Five new invariants (D-1015), especially the load-bearing `noScenarioMutationsJoinedToTransactionQueries`.
- **`tests/Contracts/ForecastingProjectionContractTest.php`** — End-to-end against Wave 0 fixture corpus.
- **`tests/Contracts/ScenarioIsolationContractTest.php`** — Asserts the FCT-03 boundary: scenario mutations never bleed into any transaction query.
- **`Modules/Forecasting/tests/Unit/RangeProjectorTest.php`** — Pest dataset over `(series_inputs, horizon) → expected_range_per_day`.
- **`Modules/Forecasting/tests/Unit/ChainAwareForecastRouterTest.php`** — Routing math against ASN/ICS/PayPal fixture trio.
- **`Modules/Forecasting/tests/Unit/BalanceAnchorResolverTest.php`** — Anchor selection per `account.kind`.
- **`Modules/Forecasting/tests/Feature/*.php`** — `/forecast` page, scenario CRUD, side-by-side rendering, buffer editor, "Forecast highlights" tile, "Model cancel" launchpad, cross-user 404 tests.
- **`Modules/Forecasting/tests/fixtures/`** — Synthesised corpus per D-1018 Wave 0.

### Established Patterns
- **DI-only** — every new service constructor-injects collaborators. `BalanceAnchorResolver` injects `DatabaseManager` + `StatementSummaryRepository` (Ledger) + `CardStatementQuery` (Chains). `RangeProjector` injects `Clock` + `DatabaseManager`. `ProjectForecastJob` injects the pipeline + the events dispatcher.
- **Public/ vs Internal/ split from day one** — `Modules/Forecasting/Public/` ships DTOs + events + actions + queries; the pipeline + state + jobs + Livewire stay Internal.
- **Eloquent direct OK, no facades** — `ForecastScenario::query()->where('user_id', $user->id)` allowed; `DB::table(...)` forbidden; raw `DatabaseManager` injected via constructor for `whereBetween`/`whereIn`.
- **Pest test layout** — unit next to the code (`Modules/Forecasting/tests/Unit/`); feature tests for `/forecast` under `Modules/Forecasting/tests/Feature/`; contract tests under `tests/Contracts/`.
- **Synthesised fixture-first Wave 0** — Same precedent as Phase 5 D-107 + Phase 6 D-140 + Phase 7 + Phase 8 D-845 + Phase 9 D-923.
- **GSD-agnostic runtime code** — No D-numbers / REQ-IDs in runtime code or PHPDocs (project-level CLAUDE.md invariant).
- **No facade calls, no helpers** — User memory `feedback_laravel_di_only` reinforces this; every Phase 10 service is constructor-injected.

### Integration Points
- **`Modules/Recurring/` event-listening** — `ProjectForecastOnRecurringChange` listener subscribes to four Recurring Public events (D-1017). Minimal Recurring-side surgery; Phase 8 already publishes them.
- **`Modules/DriftAlerts/` event-listening** — `ProjectForecastOnDriftDismissed` listens to `DriftAlertDismissedCancelled` (D-1017).
- **`Modules/Chains/` reads** — `ChainAwareForecastRouter` reads `ChainLinkQuery` + `CardStatementQuery`. Via Public only (D-1015 invariant).
- **`Modules/Ledger/` reads + extends** — Reads `transactions` for the "since-anchor" sum; reads `statement_summaries` for ASN anchor; extends `accounts` with three nullable columns via the Phase 10 migration.
- **Dashboard `/`** — Phase 5 "Next ICS settlement" tile is REPLACED by new "Forecast highlights" tile (D-1013). The composer for the old tile is REMOVED; the new composer is added.
- **`/settings`** — Adds per-account `forecast_min_buffer_minor` editor + per-account `opening_balance_minor` + `opening_balance_as_of_date` editor (Phase 3 extension pattern).
- **Top-nav** — New "Forecast" slot (badge only when active shortfall exists in next 30 days). UI-SPEC pass owns positioning given Phase 8 D-853 + Phase 9 D-927 crowding concerns.
- **Routes** — NEW `GET /forecast` + Livewire action endpoints for scenario CRUD + buffer editing. All loopback-only + Fortify-authenticated.
- **Composer** — No new dependencies.
- **Phase 11 hand-off** — `forecast_runs` audit table + `ForecastShortfallDetected` event give Phase 11 (operational hardening) hooks for backup-restore reliability checks.

### Risks Phase 10 Specifically Owns
- **Starting-balance drift (D-1001)** — User imports a partial-history CSV → opening_balance_minor never gets set → forecast starts from a wrong anchor and silently shows incorrect projections. Mitigation: `/settings` warns when an account has no anchor + the chart shows a banner "Opening balance not set for this account — projections may be inaccurate"; D-1029 considers an "Are you sure?" modal on divergent user input.
- **Scenario state leak into transactions (FCT-03)** — Even with the arch test, a future developer might add an inadvertent JOIN. Mitigation: `noScenarioMutationsJoinedToTransactionQueries` BoundaryArchTest invariant (D-1015) PLUS the dedicated `ScenarioIsolationContractTest` that asserts no row count changes in any transaction-table query when scenarios + mutations exist.
- **Job thundering herd on Phase 8/9 events** — Every approved-series change triggers `ProjectForecastJob` for every saved scenario × every horizon. Mitigation: `ShouldBeUniqueUntilProcessing` per `(user_id, scenario_id ?? 0, horizon_days)` collapses concurrent triggers; the daily scheduled sweep is the safety net if event-driven re-runs miss.
- **Stale dashboard tile** — The "Forecast highlights" tile reads pre-computed `forecast_shortfall_windows` rows; if a recent recurring change hasn't been re-projected yet, the tile is stale by up to a few minutes. Mitigation: event-driven re-runs cover most cases; scheduled daily sweep is the floor; planner may add an hourly tick if fixture coverage shows staleness >1h.
- **Multi-currency rangeArea aesthetic** — Apex `rangeArea` series with non-EUR primary series on an EUR-account chart could confuse the eye. Mitigation: per-account chart uses account.default_currency; per-series legend shows original-currency primary; FX conversion happens once at the per-occurrence level (D-1006).
- **`shift_series_date` semantic ambiguity** — User shifts one occurrence — does the whole cadence shift or just that one? Phase 10 picks one default (D-1020) and surfaces the choice clearly in the UI ("Shift just this occurrence" vs "Shift all subsequent occurrences").
- **Phase 5 tile removal as a regression risk** — Replacing the Phase 5 "Next ICS settlement" tile (D-1013) must preserve the surface (next settlement amount + date). The Phase 5 `CardStatementQuery` stays; the new tile is a strict superset. The Phase 5 dashboard test for the old tile must be ported to the new tile to confirm no regression.
- **Confidence-legend bucket calibration (D-1024)** — The high/medium/low buckets are heuristic; planner verifies against the Wave 0 fixture corpus to ensure they match user mental model on the fixture cases (Spotify = high, electricity = low).
- **Top-nav crowding (D-1025)** — Drift + Recurring + Forecast + existing slots may force a "Money" parent menu. UI-SPEC pass owns.

</code_context>

<specifics>
## Specific Ideas

- **The chain-aware routing (D-1002) is the load-bearing UX choice.** It's what makes Phase 10 deliver the project's core value at the forecast horizon, not just at the historical-ledger horizon. ASN's projection deducting the upcoming ICS bulk-iDEAL settlement on the actual settlement date is the answer to "when will my money actually leave" — which is the question the user is really asking when they open a cash-flow forecast.
- **The two-panel side-by-side comparison (D-1009) is a deliberately honest read of FCT-04.** A single overlaid chart is more visually elegant but the spec literally says "side-by-side." The "Net diff at day 30 / 60 / 90" delta tile between the panels is the bridge that prevents ping-pong reading while staying faithful to the spec.
- **Saved scenarios with the arch-test-enforced isolation (D-1008 / D-1015) is the right resolution of FCT-03.** Users want to come back to "Tighten subscriptions" after dinner — that's resumable modeling. FCT-03's "no persistence" is satisfied by structurally walling scenario mutations off from any transaction query, not by refusing to save anything. The `noScenarioMutationsJoinedToTransactionQueries` arch test is the single most load-bearing invariant in Phase 10.
- **Two-tier range math (D-1004) is the calibrated middle ground.** Pure envelope is fine for Spotify; pure historical-percentile is fine for electricity; switching tiers automatically based on series characteristics keeps the simple case simple and the volatile case honest.
- **Replacing the Phase 5 "Next ICS settlement" tile (D-1013) is a deliberate dashboard consolidation.** The new "Forecast highlights" tile is a strict superset: it surfaces the next ICS settlement (preserving Phase 5's surface) AND the lowest projected balance + active shortfall windows. Two tiles competing for the same dashboard attention slot is dilution; one tile with both signals is calmer.
- **Per-account buffer (D-1011) mirrors the Phase 8 / Phase 9 per-row escape valve pattern.** Calibrated thresholds at the right granularity — global default + per-entity override — is now a project-wide pattern.
- **The five what-if mutation kinds (D-1007) cover the full reasonable surface for v1.** Cancel + add one-off + add recurring + change amount + shift date covers cancel/replace/anticipate/jitter scenarios. Multi-scenario overlay, scenario sharing, and goal-based forecasting are intentionally v2.

</specifics>

<deferred>
## Deferred Ideas

- **Multi-scenario overlay on a single chart** — Stack 2-3 scenarios + baseline on one chart. D-1009 picks two-panel side-by-side for v1. v2 if users explicitly want to compare more than two options at once.
- **Goal-based forecasting** — "Reach €X savings by date Y; show required monthly reduction." v2.
- **Account-level sub-budgets** — "Only €200/mo on dining." Categorization-layer v2 feature.
- **Outbound payment initiation** — Out of scope (PROJECT.md explicit).
- **Push notifications on shortfall** — Out of scope (PLT-01 localhost-only).
- **`/accounts/{id}` per-account page with forecast as a tab** — Phase 10 builds the forecast view; embedding it as a tab on a future `/accounts` page is a v2/Phase 11+ structural refactor.
- **Scenario sharing / export / import** — Power-user feature; v2.
- **Forecast accuracy auto-calibration** — Track actual-vs-projected over time and auto-tune `variance_tolerance_percent`. v2.
- **Drift-correlation forecasting** — "Your subscription drift trend projects €X more annual cost by year-end." v2 (also flagged in Phase 9 deferred).
- **FX rate provider for forward-projection FX** — Phase 10 uses each occurrence's stored `latest_fx_rate_used`. A forward-curve FX provider (e.g., "USD/EUR is expected to drift toward 1.08 over 90 days") is v2.
- **Optimistic-UI for scenario mutations vs job-completes-then-poll (D-1021)** — Planner picks the simpler of the two for v1; the other can land later if perceived latency disappoints.
- **All-accounts stacked chart on `/forecast` (D-1027)** — Per-account chart switcher is the locked v1 view; an additional "All accounts" aggregated tab is planner discretion for v1 or deferred.
- **Inline mini-sparkline preview on the "Forecast highlights" dashboard tile (D-1026)** — Calm aesthetic argues text-only; UI-SPEC pass owns.
- **Hourly `ProjectForecastJob` tick** — Daily sweep + event-driven re-runs is the v1 floor; an hourly tick is added only if fixture coverage shows staleness >1h.
- **Soft + hard buffer tiers per account** — Single buffer per account (D-1011) is the v1; orange/red two-tier is a v2 polish.
- **Inline editing of opening-balance from a "balance mismatch" banner** — Calmer UX; planner discretion (D-1029).
- **CSV/JSON export of forecast or scenarios** — Power-user / tax-prep; v2 (also called out in REQUIREMENTS.md v2 deferred list).

</deferred>

---

*Phase: 10-Cash-Flow Forecasting + What-If Scenarios*
*Context gathered: 2026-05-18*
