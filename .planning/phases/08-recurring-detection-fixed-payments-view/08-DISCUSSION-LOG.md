# Phase 8: Recurring Detection + Fixed Payments View - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-17
**Phase:** 8-Recurring Detection + Fixed Payments View
**Areas discussed:** Detection Trigger Model, Approval Surface, Income vs Expense, Drift Display + Variance Tolerance, Module Home + Boundary Tests, Dashboard Integration, Multi-currency Series, Cadence Detection + Fixtures

---

## Detection Trigger Model

### Q1: How should the recurring detector run?

| Option | Description | Selected |
|--------|-------------|----------|
| Scheduled daily sweep | A single daily Horizon job sweeps all transactions and (re)computes candidate series | |
| Event-driven on TransactionImported | Listener fires on each TransactionImported, re-clusters the affected merchant | |
| Hybrid: scheduled + on-demand | Daily sweep is the source of truth; user can press 'Re-detect now' on /recurring | ✓ |

**User's choice:** Hybrid: scheduled + on-demand

### Q2: Detection window — all-time or sliding?

| Option | Description | Selected |
|--------|-------------|----------|
| Sliding 18-month window | Detector only considers transactions ≤ 18 months old | |
| All-time history | Detector considers every transaction ever | |
| User-configurable window | Expose the window as a setting (default 18 months) | ✓ |

**User's choice:** User-configurable window (default 18 months)

### Q3: Minimum occurrence threshold?

| Option | Description | Selected |
|--------|-------------|----------|
| 3 occurrences | Matches ROADMAP "≥3 months of history" dependency | |
| 2 occurrences | More aggressive — surfaces candidates faster | ✓ |
| 4 occurrences | More conservative | |

**User's choice:** 2 occurrences

### Q4: Re-evaluate approved series or lock metrics?

| Option | Description | Selected |
|--------|-------------|----------|
| Always re-evaluate | Each sweep refreshes amount/cadence/next-expected on approved series | |
| Lock metrics at approval | Approval snapshots the values | |
| Re-evaluate but require re-approval on cadence change | Hybrid — protects user trust | ✓ |

**User's choice:** Re-evaluate but require re-approval on cadence change

### Q5: Default detection-window value?

| Option | Description | Selected |
|--------|-------------|----------|
| 18 months | Covers monthly + quarterly + yearly cadences | ✓ |
| 12 months | Tighter | |
| 24 months | Generous | |

**User's choice:** 18 months

### Q6: What counts as a cadence change that triggers re-approval?

| Option | Description | Selected |
|--------|-------------|----------|
| Cadence-class flip only (monthly→quarterly etc.) | Most stable | ✓ |
| Any interval drift > 25% | More sensitive | |
| Cadence-class flip OR series-gap > 2 expected intervals | Hybrid | |

**User's choice:** Cadence-class flip only

### Q7: 'Re-detect now' action mechanics?

| Option | Description | Selected |
|--------|-------------|----------|
| Re-queue the same sweep job (ShouldBeUniqueUntilProcessing) | Mirrors Phase 6 'Scan now' pattern | ✓ |
| Synchronous re-detect | Runs inline in the request | |
| User-scoped re-detect only | On-demand scoped to current user only | |

**User's choice:** Re-queue the same sweep job

---

## Approval Surface

### Q1: Where does the user approve a suggested series?

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated /recurring/review queue | Mirrors /chains/review from Phase 5 | ✓ |
| Inline state on a single /recurring page | One page with pending/approved/rejected filter chips | |
| First-time onboarding wizard, then drawer | Triages all candidates in one pass | |

**User's choice:** Dedicated /recurring/review queue

### Q2: Actions on a pending suggestion?

| Option | Description | Selected |
|--------|-------------|----------|
| Approve / Reject / Edit name | Minimal action set | |
| Approve / Reject / Edit name / Snooze | Plus snooze for "I'll decide later" cases | ✓ |
| Approve / Reject / Edit name / Edit category | Plus inline category override | |

**User's choice:** Approve / Reject / Edit name / Snooze

### Q3: Reject permanence?

| Option | Description | Selected |
|--------|-------------|----------|
| Rejected = permanent unless user undoes via /recurring | Calm aesthetic; user is in control | ✓ |
| Rejected resurfaces after large changes | More forgiving but may feel naggy | |
| Rejected is hard-permanent (no resurface, no undo) | Simpler but loses audit trail | |

**User's choice:** Permanent unless user un-rejects via /recurring rejected tab

### Q4: Dashboard surfacing?

| Option | Description | Selected |
|--------|-------------|----------|
| Top-nav badge + dashboard tile | Mirrors Phase 5/6 patterns | ✓ |
| Top-nav badge only | Lightweight | |
| Dashboard tile only | Surface pending suggestions on the dashboard, no top-nav badge | |

**User's choice:** Top-nav badge + dashboard tile

### Q5: Snooze mechanics?

| Option | Description | Selected |
|--------|-------------|----------|
| Snooze for fixed 30 days | Predictable; no date-picker | |
| Snooze with date picker (1 week / 1 month / 3 months / custom) | Matches Phase 9 planned snooze UX | ✓ |
| Snooze until next occurrence | Smart but harder to explain | |

**User's choice:** Snooze with date picker (shareable with Phase 9)

### Q6: Approval ↔ merchant memory coupling?

| Option | Description | Selected |
|--------|-------------|----------|
| Approval is independent of merchant memory | Keeps the two concepts decoupled | ✓ |
| Approving emits TransactionCategorized to feed memory | Tighter integration with hidden side effect | |
| Approving promotes category to memory if 'apply to all' is checked | Opt-in side effect | |

**User's choice:** Independent — approval flips state, doesn't touch merchant_memories

### Q7: Bulk approve / reject support?

| Option | Description | Selected |
|--------|-------------|----------|
| Checkbox-select + bulk Approve/Reject action bar | Essential after first big backfill | ✓ |
| One-by-one only | Calmer but tedious | |
| Bulk Approve only (no bulk Reject) | Mid-point trade-off | |

**User's choice:** Checkbox-select + bulk Approve/Reject

### Q8: Edit-name persistence?

| Option | Description | Selected |
|--------|-------------|----------|
| Edit-name persists; re-detection respects it | Override on series row, preserved across sweeps | ✓ |
| Edit-name overwrites the auto-derived name (no separate field) | Simpler but risky | |
| Edit-name is display-only, never reused | Trades pragmatism for safety | |

**User's choice:** Persists across re-detection; auto-derived tracked underneath

### Q9: Undo window?

| Option | Description | Selected |
|--------|-------------|----------|
| Toast with 'Undo' (10 seconds) | Same pattern as Phase 4/5 toasts | ✓ |
| No undo — user navigates to /recurring to reverse | Calmer | |
| Per-action audit log | Heavyweight | |

**User's choice:** 10-second toast with Undo

### Q10: State transition recording?

| Option | Description | Selected |
|--------|-------------|----------|
| State + timestamp on the series row | Minimal | |
| Full state-machine history table | Useful for debugging detector drift + Phase 9 dependencies | ✓ |
| State only — no timestamps | Insufficient | |

**User's choice:** Full state-machine history table (recurring_series_transitions)

---

## Income vs Expense

### Q1: Unified table or separate tables?

| Option | Description | Selected |
|--------|-------------|----------|
| Unified table + direction enum | Single detector code path; Phase 10 reads both from one table | ✓ |
| Separate tables (recurring_expenses + recurring_incomes) | Distinct schemas | |
| Unified table; two detector classes that share an interface | Same table; distinct clustering signals | |

**User's choice:** Unified table + direction enum

### Q2: How should the income detector identify recurring income?

| Option | Description | Selected |
|--------|-------------|----------|
| Cluster on counterparty IBAN + cadence | Salary lands from a stable employer IBAN | |
| Cluster on transaction description / counterparty name | Some employers vary IBAN | |
| Hybrid: IBAN primary, description fallback | Most robust for real-world data | ✓ |

**User's choice:** Hybrid: IBAN primary, normalized description fallback

### Q3: Fixed-payments view layout (income + expense)?

| Option | Description | Selected |
|--------|-------------|----------|
| One list with grouped sections | Single page so user sees full picture | ✓ |
| Tabs (Expenses / Income / All) | Cleaner per-view | |
| Single mixed table with a direction column | Compact but harder to scan | |

**User's choice:** One list with grouped sections; net summary at top

### Q4: Recurring transfers on the view?

| Option | Description | Selected |
|--------|-------------|----------|
| Excluded by default; separate 'Recurring transfers' section for visibility | Avoids double-counting; Phase 5 ICS tile is canonical | ✓ |
| Excluded entirely | Cleaner but loses the regularity signal | |
| Included alongside expenses with a transfer badge | Risk of double-counting in cash-flow | |

**User's choice:** Excluded from main view; separate section informational only

### Q5: Minimum-amount threshold for income detection?

| Option | Description | Selected |
|--------|-------------|----------|
| User-configurable; default €100 | Conservative default | |
| No threshold — detect any recurring income | May produce noise | |
| Threshold = average of historical income / 10 | Adaptive but opaque | |

**User's choice:** User-configurable; default €2000 (salary-sized) — user overrode the default upward

### Q6: Multi-payroll handling?

| Option | Description | Selected |
|--------|-------------|----------|
| Each distinct IBAN cluster becomes its own series | Standard outcome | ✓ |
| Merge if both arrive monthly and total stays stable | Premature complexity | |
| Surface as separate suggestions; offer merge action | Adds a v2 feature | |

**User's choice:** Each IBAN cluster = its own series

### Q7: Income detector input scope?

| Option | Description | Selected |
|--------|-------------|----------|
| Only on transactions classified as income | Trusts upstream LED-05 classification | ✓ |
| Also scan unclassified inflows above the threshold | Conflates roles | |
| Seed from LED-05 income; auto-promote unclassified inflows if series forms | Smart but unclear ownership | |

**User's choice:** Only transactions classified as income by Phase 4 LED-05

### Q8: Reject series → underlying transaction type cascade?

| Option | Description | Selected |
|--------|-------------|----------|
| No — reject only affects the series suggestion | Series is purely a clustering layer | ✓ |
| Yes — reject also un-categorizes the transactions | Aggressive | |
| Prompt: 'Also clear category from N transactions?' | Most controlled but adds friction | |

**User's choice:** No cascade; reject affects the series only

---

## Drift Display + Variance Tolerance

### Q1: Amount column display style?

| Option | Description | Selected |
|--------|-------------|----------|
| Latest amount + small drift indicator | Calm but informative | ✓ |
| Range across the window (€9.99–€11.49) | Most honest about variance | |
| 3-month rolling average | Smooths but masks recent changes | |

**User's choice:** Latest amount + small drift indicator chip

### Q2: Variance tolerance tunability?

| Option | Description | Selected |
|--------|-------------|----------|
| Locked at ±25% for v1 | One less /settings knob | |
| User-configurable in /settings (default ±25%) | Consistent with other detector knobs | |
| Per-series override (default ±25%, editable on the series row) | Powerful for variable-amount cases | ✓ |

**User's choice:** Per-series override (default ±25%)

### Q3: Monthly equivalent computation?

| Option | Description | Selected |
|--------|-------------|----------|
| Latest occurrence × cadence multiplier | Matches "what am I paying NOW" | ✓ |
| Last-3 occurrences average × cadence multiplier | Smooths over one-off events | |
| Median of the full series window × cadence multiplier | Most robust but hard to explain | |

**User's choice:** Latest occurrence × cadence multiplier

### Q4: Drill-in view style?

| Option | Description | Selected |
|--------|-------------|----------|
| Sparkline + occurrences table | Compact, calm | |
| Full chart (line/bar) + occurrences table | Bigger ApexCharts visualization | ✓ |
| Occurrences table only (no chart) | Calmest but UI-03 implies a chart | |

**User's choice:** Full chart + occurrences table

### Q5: Funding chain icon style?

| Option | Description | Selected |
|--------|-------------|----------|
| Account icon stack with chain link badge | Reuses Phase 5 icons | ✓ |
| Inline 'funded by' text | Most explicit but cluttered | |
| Tooltip on the icon | Cleanest but chain not discoverable | |

**User's choice:** Account icon stack with chain link badge

### Q6: Which chain feeds the funding icon (when chains vary)?

| Option | Description | Selected |
|--------|-------------|----------|
| Most recent occurrence's chain | Consistent with latest-amount choice | ✓ |
| Most common chain across occurrences | Masks recent funding-source changes | |
| Mark mixed chains with a 'multi-chain' indicator | Rare edge case for v1 | |

**User's choice:** Most recent occurrence's chain

### Q7: 'Next expected charge date' display?

| Option | Description | Selected |
|--------|-------------|----------|
| Relative + absolute date | Best of both for scanning + planning | ✓ |
| Absolute date only | Cleanest | |
| Relative only | Friendliest scan but breaks for far dates | |

**User's choice:** Relative + absolute date

### Q8: Confidence/uncertainty on next-expected date?

| Option | Description | Selected |
|--------|-------------|----------|
| Single date estimate | Simple, scannable | |
| Date range (±3 days) | More honest but crowds the row | |
| Date + low-confidence indicator (dim/italic) | Conservative middle ground | ✓ |

**User's choice:** Date + low-confidence indicator when cadence variance is high

---

## Module Home + Boundary Tests

### Q1: Where does Phase 8 code live?

| Option | Description | Selected |
|--------|-------------|----------|
| New Modules/Recurring/ bounded module | Mirrors Receipts/Chains/Transfers precedent | ✓ |
| Extend Modules/Categorization/ | Conflates two distinct domains | |
| Extend Modules/Ledger/ | Dilutes the Ledger boundary | |

**User's choice:** New Modules/Recurring/ bounded module

### Q2: Public surface shape?

| Option | Description | Selected |
|--------|-------------|----------|
| Queries + DTOs only; mutations are internal | Clean encapsulation | ✓ |
| Full CRUD on the Public surface | Maximum flexibility but breaks encapsulation | |
| Events-only Public surface | Strictest boundary | |

**User's choice:** Queries + DTOs + Events + Actions; detector + state-machine internals stay private

### Q3: BoundaryArchTest invariants?

| Option | Description | Selected |
|--------|-------------|----------|
| All three: no facades, no Transaction writes, Public-only cross-module reads | Three invariants caught at CI time | |
| Just noFacadeCalls + Public-only access | Less ceremony | |
| All three + a stricter 'no synchronous detection in request lifecycle' | Catches accidental N+1 detector calls | ✓ |

**User's choice:** All four invariants

### Q4: How does Modules/Recurring/ read merchant_memories?

| Option | Description | Selected |
|--------|-------------|----------|
| Via new Modules\Categorization\Public\Services\MerchantMemoryQuery | Clean cross-module boundary | ✓ |
| Direct Eloquent read on Ledger\Models\MerchantMemory | Simpler but boundary leakage | |
| Listen to TransactionCategorized events and project locally | Strongest boundary but adds projection table | |

**User's choice:** Via new MerchantMemoryQuery Public service

---

## Dashboard / Home Integration

### Q1: Fixed-payments list integration with dashboard?

| Option | Description | Selected |
|--------|-------------|----------|
| Inline list on / with header link to full /recurring | Matches UI-01 explicit deliverable | ✓ |
| Summary tile only; full list lives on /recurring | Calmer dashboard | |
| Full list on /; no separate /recurring page | Conflates dashboard + detail | |

**User's choice:** Inline list on dashboard with View-all link

### Q2: Income on the dashboard?

| Option | Description | Selected |
|--------|-------------|----------|
| Top-line in/out/remaining already has 'in' | Reuses existing UI-01 tile | ✓ |
| Dedicated 'Recurring income' tile separate | More visual real estate | |
| Income only on /recurring; dashboard expenses-only | Simpler but loses net picture | |

**User's choice:** Existing in/out/remaining tile + income section in the fixed-payments card

### Q3: Coexistence with Phase 5 'Next ICS settlement' tile?

| Option | Description | Selected |
|--------|-------------|----------|
| Keep Phase 5 tile as-is; transfer section is separate context | Two surfaces, two purposes | ✓ |
| Merge: 'Recurring transfers' section consumes Phase 5 forecast | Stronger integration but couples to Phase 5 | |
| Hide Phase 5 tile when user approves ASN→ICS as recurring series | Cleanest UI but loses carry-forward logic | |

**User's choice:** Keep Phase 5 tile as-is

### Q4: Dashboard card scope?

| Option | Description | Selected |
|--------|-------------|----------|
| All approved series — monthly equivalent is the headline | Matches "what am I paying every month, total" | |
| Only series with a charge expected in current month | Hides yearly insurance bill | |
| All approved + a 'this month only' toggle | Best of both | ✓ |

**User's choice:** All approved + 'This month only' toggle

---

## Multi-currency Series

### Q1: Clustering basis (original vs settled currency)?

| Option | Description | Selected |
|--------|-------------|----------|
| Original currency + amount | Honest — user is paying $11.99 monthly | ✓ |
| Settled EUR amount | Simpler but FX-jitter fragments stable USD series | |
| Original first, fall back to settled if original is missing | Most robust hybrid | |

**User's choice:** Original currency + amount

### Q2: Monthly equivalent display for non-EUR series?

| Option | Description | Selected |
|--------|-------------|----------|
| Original-currency primary + EUR shadow | Matches MC-02 dual-currency display | ✓ |
| Settled EUR only | Loses original-currency truth | |
| Follow user's /settings default currency view | Inherits Phase 3 D-44 toggle behavior | |

**User's choice:** Original-currency primary + EUR shadow

### Q3: Dashboard 'total monthly fixed' summation?

| Option | Description | Selected |
|--------|-------------|----------|
| Sum in EUR using each series' latest FX rate | Matches existing dashboard EUR summary | ✓ |
| Per-currency tile rows (Phase 3 D-46) | More accurate but harder to grasp | |
| Single EUR in 'eur' mode; per-currency rows in 'original' mode | Inherits Phase 3 toggle | |

**User's choice:** Single EUR sum using each series' latest FX rate

### Q4: FX drift vs real price drift?

| Option | Description | Selected |
|--------|-------------|----------|
| Original-currency drift is real drift; EUR drift is noise | Honest treatment of FX | |
| Use settled EUR as canonical drift signal | Noise machine | |
| Both: show original-currency drift prominently, EUR drift quietly | Most informative; extra UI but worth it | ✓ |

**User's choice:** Both — original-currency drift prominent, EUR drift quiet (in drill-in only)

---

## Cadence Detection + Fixtures

### Q1: Cadence inference algorithm?

| Option | Description | Selected |
|--------|-------------|----------|
| Median interval + nearest-class snap | Robust to outliers | ✓ |
| Mode (most common interval rounded) + class snap | Brittle on small-sample series | |
| Statistical fit (chi-squared / KS test) | Overkill | |

**User's choice:** Median interval + nearest-class snap

### Q2: Missed-occurrence tolerance?

| Option | Description | Selected |
|--------|-------------|----------|
| Allow 1 missed period per 6 observed | Catches cancellations but tolerates banking jitter | ✓ |
| No tolerance — any gap > 1.5×median breaks the series | Too strict | |
| Tolerance scales with series length | Smart but harder to reason about | |

**User's choice:** 1 missed period per 6 observed

### Q3: Wave 0 fixture corpus?

| Option | Description | Selected |
|--------|-------------|----------|
| Controlled-time-series corpus + one anonymised real export | Mix of fast-iteration + real-world fidelity | ✓ |
| Real-export-only fixtures | Slow to iterate | |
| Synthesised-only | Misses real-world quirks | |

**User's choice:** Controlled-time-series corpus + one anonymised real export

### Q4: Test locations?

| Option | Description | Selected |
|--------|-------------|----------|
| Modules/Recurring/tests/Unit + Pest dataset | Fast, isolated, easy to add cases | |
| tests/Contracts/RecurringDetectionContractTest.php | Higher-fidelity but slower | |
| Both — unit for cadence math, contract for end-to-end | Standard test pyramid | ✓ |

**User's choice:** Both unit + contract tests

---

## Claude's Discretion

The following items were captured in CONTEXT.md `<decisions>` as Claude-discretion (D-847–D-855), to be locked by the planner:

- Wave structure (D-847) — suggested 5-wave breakdown.
- `recurring_series.detected_cadence` column type — enum vs string (D-848).
- Container-tag name for `SeriesDetector` implementations — suggested `'recurring.detector'` (D-849).
- Exact mechanism for the synchronous-detection arch test — marker interface vs callsite assertion (D-850).
- Exact FX rate source for EUR shadow on `/recurring` — per-occurrence vs latest-across-currency (D-851).
- "Recurring transfers" section default collapse state (D-852).
- Top-nav slot positioning + potential submenu grouping (D-853).
- Per-series `variance_tolerance_percent` editor input type — slider / numeric / dropdown (D-854).
- Dashboard 'This month only' toggle persistence — user setting vs `#[Url]` query string (D-855).

## Deferred Ideas

See CONTEXT.md `<deferred>` section. Highlights:

- "Create rule from this series" cross-phase quick-action — v2.
- "Merge series" action for multi-payroll / currency-switch edge cases — v2.
- Bulk Edit-name action — v2 nicety.
- Adaptive (auto-tuning) variance tolerance — v2.
- Annual cadence improvements (needs ≥13 months of history, already deferred at REQUIREMENTS.md level).
- Sub-weekly cadence detection — out of scope.
- "Why is this a series?" explanation panel on drill-in — v2 power-user.
- Onboarding wizard for first big backfill — v2 if bulk-approve isn't enough.
- Series tagging / custom user labels — v2.
- Series export to CSV/JSON — v2.
