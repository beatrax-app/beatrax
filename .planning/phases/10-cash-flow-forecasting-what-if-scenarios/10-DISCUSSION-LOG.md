# Phase 10: Cash-Flow Forecasting + What-If Scenarios - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-18
**Phase:** 10-Cash-Flow Forecasting + What-If Scenarios
**Areas discussed:** Balance source + per-account scope + chain routing, Uncertainty math + range display, What-if surface (operations / persistence / comparison), Surplus/shortfall thresholds + page placement

---

## Balance source + per-account scope + chain routing

### Sub-question 1: Where should the 'current balance' per account come from?

| Option | Description | Selected |
|--------|-------------|----------|
| User-input opening balance per account, updated occasionally | `opening_balance_minor` + `opening_balance_as_of_date` per account; forecast = anchor + sum-since. Simplest, uniform across all sources. | |
| CAMT.053 statement balance + delta-since (Recommended) | Use latest CAMT.053 closing balance from `statement_summaries` for ASN, latest `card_statements` closing balance for ICS, user-input fallback for PayPal/CSV-only. Honest — mirrors bank UI. | ✓ |
| Pure sum-of-transactions from earliest ingested row | No anchor; only works if ingested history covers from account zero. Likely incorrect for most accounts. | |
| Hybrid statement-balance + user-input + auto-correct on next statement | Combines B + A with auto-correction. Most resilient but more state churn. | |

**User's choice:** CAMT.053 statement balance + delta-since
**Notes:** Captured as D-1001. PayPal + CSV-only sources still need a `accounts.opening_balance_minor` + `opening_balance_as_of_date` user-input fallback because CSV has no balance line.

### Sub-question 2: How should the Phase 5 chain layer affect per-account projections?

| Option | Description | Selected |
|--------|-------------|----------|
| Chain-aware routing: project the funder, show the funded as 'amount owed by settlement' (Recommended) | ASN deducts upcoming ICS bulk-iDEAL on settlement date; ICS shows running 'amount owed by next settlement'; PayPal-funded charges deduct from funder on actual debit date. | |
| Per-account naive projection — each account in isolation | Loses the project's headline chain-visibility differentiator. | |
| Roll everything up into a single 'household cash position' line | Loses per-account detail FCT-01 mandates. | |
| Chain-aware routing PLUS optional 'view by funder' rollup as a toggle | Option A + `#[Url]`-bound toggle that collapses chain-resolved series onto the funder account row. | ✓ |

**User's choice:** Chain-aware routing PLUS 'view by funder' toggle
**Notes:** Captured as D-1002. User picked the most expressive option — power users who think in terms of 'where will my real money be on the 30th' get a single rollup line per funder via the toggle.

---

## Uncertainty math + range display (FCT-02)

### Sub-question 1: How should projected balance ranges be COMPUTED?

| Option | Description | Selected |
|--------|-------------|----------|
| Simple envelope from per-series variance_tolerance + cadence-date jitter (Recommended) | Closed-form using Phase 8 `variance_tolerance_percent` verbatim; ±3-day cadence jitter; quadrature daily fold. | |
| Historical P10/P90 percentile bands from occurrence history per series | Statistically purer; fragile for new series with <6 occurrences. More query weight. | |
| Monte Carlo simulation | Most flexible, but overkill for the data volume + harder to test deterministically. | |
| Two-tier: envelope (Option A) for simple cases + P10/P90 (Option B) only on series flagged 'volatile' | Default envelope; switch to historical-percentile for high-variance / freelance-income series. | ✓ |

**User's choice:** Two-tier (envelope + percentile for volatile series)
**Notes:** Captured as D-1004. No new user-facing toggle — auto-derive the tier from variance_tolerance_percent + stddev heuristic. Planner picks exact thresholds (D-1019).

### Sub-question 2: How should the (low, point, high) triple be RENDERED?

| Option | Description | Selected |
|--------|-------------|----------|
| ApexCharts rangeArea shaded band + bold center line + crosshair tooltip (Recommended) | Translucent fill, bold line, tooltip text — calm, reads as range immediately. | |
| Two visible high/low lines (no fill) + center line | Three explicit lines, color-coded. Busier; less calm. | |
| Bold center line only; range surfaces in hover tooltip text | Visually quietest but loses at-a-glance honesty FCT-02 demands. | |
| ApexCharts rangeArea PLUS per-series 'forecast accuracy' indicator chip in a legend | Option A + confidence-chip sidebar listing each series. Heavier UI surface; explains WHY band is wide. | ✓ |

**User's choice:** ApexCharts rangeArea + confidence legend sidebar
**Notes:** Captured as D-1005. Legend confidence buckets (high/medium/low) derived from band-width-relative-to-point — planner picks exact buckets (D-1024).

---

## What-if surface — operations, persistence, comparison shape (FCT-03/04)

### Sub-question 1: Which what-if MUTATIONS should v1 support?

| Option | Description | Selected |
|--------|-------------|----------|
| Add planned one-off transaction (date + amount + currency + direction ±) | Single ad-hoc expense or income. | ✓ |
| Add planned recurring transaction (date + amount + cadence + direction ±) | Recurring scenario; superset of A. | ✓ |
| Change an existing series amount | Override `latest_amount_minor` for the scenario. | ✓ |
| Shift a series's next-charge date | Delay or bring forward; subsequent occurrences shift with or stay on cadence (planner picks default). | ✓ |

**User's choice:** ALL FOUR (plus 'Cancel a series' already locked by Phase 9 hand-off — total of 5 mutation kinds)
**Notes:** Captured as D-1007. Full mutation surface for v1.

### Sub-question 2: How should scenarios PERSIST and COMPARE against baseline?

| Option | Description | Selected |
|--------|-------------|----------|
| Named saved scenarios (DB rows holding mutations, never transactions) + multi-scenario comparison (Recommended) | `forecast_scenarios` + `forecast_scenario_mutations` tables; baseline vs one scenario default; toggle to overlay up to 3 scenarios at once. | |
| Ephemeral session-only — mutations live in Livewire component state | Lost on page reload; thin for resumable modeling use case. | |
| Saved scenarios + single-scenario-vs-baseline comparison only (no multi-overlay) | Option A minus multi-overlay. Loses 'compare two plans' use case. | |
| Saved scenarios + side-by-side TWO-PANEL view (baseline left, scenario right; no overlay) | Two stacked charts; harder to read deltas; closest match to FCT-04 literal 'side-by-side' wording. | ✓ |

**User's choice:** Saved scenarios + two-panel side-by-side
**Notes:** Captured as D-1008 + D-1009. User picked the most literal read of FCT-04. A 'Net diff at day 30 / 60 / 90' delta tile renders between/above the two panels (added by Claude) to prevent ping-pong reading without compromising the side-by-side layout the user chose. Shared y-axis range across both panels enforced for honest visual comparison.

---

## Surplus/shortfall thresholds + page placement (FCT-05 + UI)

### Sub-question 1: What counts as a 'shortfall' worth highlighting?

| Option | Description | Selected |
|--------|-------------|----------|
| Per-account user-set buffer (default €0, editable in /settings or per-account row) (Recommended) | `accounts.forecast_min_buffer_minor` (BIGINT, FND-04, nullable default NULL = 0); per-account edit; effective threshold captured on persisted shortfall-window for audit honesty. | ✓ |
| Zero-crossing only — no buffer, no per-account config | Simplest; loses literal FCT-05 wording ('€X' buffer). | |
| Global single-buffer in /settings | Lighter UI but doesn't reflect per-account asymmetry. | |
| Two thresholds per account — 'soft' warning (orange) + 'hard' overdraft (red) | More expressive; more UI knobs; v2 polish. | |

**User's choice:** Per-account user-set buffer
**Notes:** Captured as D-1011. Audit honesty: `forecast_shortfall_windows.buffer_used_minor` mirrors the Phase 9 D-915 `threshold_percent_used` pattern.

### Sub-question 2: Where should the forecast view live and how should it integrate with the existing dashboard?

| Option | Description | Selected |
|--------|-------------|----------|
| New top-level /forecast page + dashboard 'Lowest projected balance' summary tile (Recommended) | New page + new tile; Phase 5 'Next ICS settlement' tile stays. | |
| Embed forecast chart directly on existing dashboard (no separate page) | Heavier dashboard; loses 'calm content-first' aesthetic. | |
| Per-account /accounts/{id} page with forecast as a tab | Cleaner per-account focus; assumes /accounts page exists (it doesn't yet). | |
| New /forecast page + dashboard tile + ALSO subsume the Phase 5 'Next ICS settlement' tile by replacing it with a more general 'Forecast highlights' tile | Tile consolidation; removes the Phase 5 tile and replaces it with a Phase 10 'Forecast highlights' that subsumes both ICS settlement and shortfall warnings. | ✓ |

**User's choice:** /forecast page + replace Phase 5 'Next ICS settlement' tile with a unified 'Forecast highlights' tile
**Notes:** Captured as D-1013. The new tile is a strict SUPERSET of the Phase 5 tile (preserves the 'next ICS settlement amount + date' surface AND adds lowest-projected-balance + active shortfall windows). The underlying Phase 5 `CardStatementQuery` stays unchanged; only the dashboard composer is rewritten. Risk surfaced in CONTEXT (regression risk): the Phase 5 dashboard test for the old tile must be ported to the new tile.

---

## Claude's Discretion

The following are explicitly punted to the planner / UI-SPEC pass; the user did not opine because they're implementation- or design-system-level details:

- **D-1018:** Wave structure (5 waves suggested; planner verifies via goal-backward analysis).
- **D-1019:** Exact "volatile series" trigger thresholds for the percentile-tier range math.
- **D-1020:** Default behavior of `shift_series_date` mutation (shift with cadence vs single-occurrence shift).
- **D-1021:** Optimistic-UI vs job-completes-then-poll for scenario mutations.
- **D-1022:** Whether `add_recurring` mutation occurrences are bounded by horizon or fixed forward window.
- **D-1023:** JSON schema for `forecast_scenario_mutations.payload` per `kind`.
- **D-1024:** Confidence-legend bucket thresholds.
- **D-1025:** Top-nav slot positioning for "Forecast" given Phase 8 + Phase 9 crowding concerns.
- **D-1026:** Whether the dashboard tile renders an inline mini-sparkline or stays text-only.
- **D-1027:** Whether `/forecast` shows an additional aggregated "All accounts" stacked-chart tab.
- **D-1028:** Whether `forecast_runs` needs a `failed_reason` column or relies on Horizon's failed-job log.
- **D-1029:** Whether `/settings` warns on divergent user-input opening_balance vs computed sum.

## Deferred Ideas

(Captured in CONTEXT.md `<deferred>` section — copied here for audit-only reference.)

- Multi-scenario overlay on a single chart (v2)
- Goal-based forecasting (v2)
- Account-level sub-budgets (v2 categorization-layer)
- Outbound payment initiation (out of scope — PROJECT.md explicit)
- Push notifications on shortfall (out of scope — PLT-01)
- `/accounts/{id}` per-account page with forecast as a tab (v2/Phase 11+)
- Scenario sharing / export / import (v2)
- Forecast accuracy auto-calibration (v2)
- Drift-correlation forecasting (v2; also flagged in Phase 9 deferred)
- FX rate forward-curve provider (v2)
- Soft + hard buffer tiers per account (v2 polish)
- Inline opening-balance "balance mismatch" banner editor (planner discretion D-1029)
- CSV/JSON export of forecast or scenarios (v2; also in REQUIREMENTS.md v2 list)
