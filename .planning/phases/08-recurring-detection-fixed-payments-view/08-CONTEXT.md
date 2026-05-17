# Phase 8: Recurring Detection + Fixed Payments View - Context

**Gathered:** 2026-05-17
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 8 ships the recurring-detection brain plus the fixed-monthly-payments view (the headline UI deliverable of the project's core value statement). The deliverable is: a new `Modules/Recurring/` bounded module that clusters imported transactions into recurring series (expense + income) on a hybrid trigger model (scheduled daily sweep + user-initiated on-demand re-detect), holds suggestions in a state machine until the user approves them, surfaces them on a curated `/recurring` fixed-payments view with funding-source chain icons (REC-04), and lets the user drill into any series to see its full historical occurrences plus an amount-drift trend (REC-05, UI-03). Recurring income (LED-06) shares the same detector code path and lives in the same unified `recurring_series` table.

**What Phase 8 delivers (vertical):**
- A new `Modules/Recurring/` bounded module mirroring `Modules/Receipts/` (Phase 7), `Modules/Chains/` (Phase 5), `Modules/Transfers/` (Phase 4). Public/Internal split from day one. Composer.json, ServiceProvider, dedicated tests dir.
- Three migrations: `recurring_series` (the series row with state machine, edit-name overlay, cadence class, monthly equivalent, latest amount, latest FX rate snapshot, latest funding chain reference), `recurring_series_occurrences` (linking transactions to their parent series so drill-in is a single query), `recurring_series_transitions` (full state-machine history audit for every pending→approved / approved→cadence_changed / approved→rejected / rejected→reopened transition).
- A `SeriesDetector` contract with two Internal implementations: `ExpenseSeriesDetector` (clusters on normalized merchant via the new `Modules\Categorization\Public\Services\MerchantMemoryQuery`) and `IncomeSeriesDetector` (clusters on counterparty IBAN with normalized-description fallback, restricted to `transaction.type='income'` from Phase 4 LED-05). Container-tagged so both run inside the same sweep job.
- A daily scheduled sweep job (`DetectRecurringSeriesJob`, `ShouldBeUniqueUntilProcessing` per-user) that runs all `SeriesDetector` implementations against the user's configurable detection window, classifies new candidates, refreshes metrics on approved series, and flips state to `cadence_changed` when the inferred cadence class flips on an approved series.
- A `/recurring/re-detect` action that dispatches the same job on demand (idempotent — `ShouldBeUniqueUntilProcessing` makes spam-clicks a no-op; user sees a "detecting…" toast).
- A `/recurring/review` Livewire SFC queue (mirrors `/chains/review` from Phase 5) with: a sticky action bar for checkbox-driven bulk Approve/Reject; per-row inline Approve / Reject / Snooze / Edit-name actions; a 10-second Undo toast on every action (Phase 4/5 toast precedent); a "Rejected" tab with an un-reject action.
- A `/recurring` Livewire SFC page (the headline UI) with two stacked sections — "Recurring expenses" then "Recurring income" — plus a collapsed "Recurring transfers" section that is informational only. Each row shows: name (user-editable, persisting across re-detection), latest amount + drift indicator chip, monthly equivalent, funding-source account icon stack with chain link badge, category (read-only from Categorization), next-expected-charge as relative-plus-absolute text with a low-confidence dim/italic indicator when cadence variance is high.
- A `/recurring/series/{id}` drill-in Livewire SFC with a full ApexCharts line chart of amount-over-time, plus a table of every occurrence linking to its underlying transaction (UI-03).
- Dashboard integration: an inline "Fixed monthly payments" card on `/` rendering the top ~6 series by monthly equivalent, with a "View all →" link to `/recurring`. The card carries an "All series / This month only" toggle. The Phase 5 "Next ICS settlement" tile stays unmodified — recurring transfers are a separate context. The existing dashboard "in/out/remaining" tile is the income-on-dashboard surface; income detail lives on `/recurring`.
- A new top-nav slot for "Recurring" with a badge showing pending-suggestion count (View Factory composer pattern from Phase 5/6/7).
- A new `Modules/Categorization/Public/Services/MerchantMemoryQuery` — Categorization's first read-only Public projection of `merchant_memories`. Phase 8 consumes it; future phases can too.
- Per-series `variance_tolerance` column (default ±25%, editable on the series row) so the user can loosen tolerance on volatile series (electricity bills, variable salaries) without affecting stable ones.
- A user-configurable detection window setting (default 18 months) plus a user-configurable minimum-amount threshold for income detection (default €2000, salary-sized) in `/settings`.
- Multi-currency: detector clusters on original-currency + amount; the monthly equivalent renders as original-currency-primary with EUR shadow (extends Phase 3 D-44/D-47 patterns); the dashboard "total monthly fixed" card sums in EUR using each series' latest FX rate; FX drift surfaces in the drill-in but never on `/recurring` unless original-currency price actually moves.
- A new `BoundaryArchTest::noFacadeCallsFromRecurring` invariant + `noTransactionWritesFromRecurring` (analytical-only constraint) + `crossModuleAccessGoesThroughPublic` (Phase 9/10 must consume `Modules\Recurring\Public\*`) + `noSynchronousDetectionInRequestLifecycle` (detector code may only run inside queue workers / scheduled jobs, never in the HTTP request cycle).

**What Phase 8 does NOT deliver:**
- Subscription drift alerts and the dedicated "Drift alerts" view (REC-06/07/08) — Phase 9.
- Cash-flow forecasting + what-if scenarios (FCT-01..05) — Phase 10, which consumes `Modules\Recurring\Public\*` queries + events.
- Categorization changes or new categorization rules — Phase 7 covers CAT-02/04 fully; Phase 8 only reads merchant categorization to decorate the view.
- Mutations of `transaction.type` or any other ledger writes — Recurring is analytical-only (`noTransactionWritesFromRecurring` invariant).
- Snooze for transactions themselves — snooze is a per-series suggestion-state action only.
- Regex / DSL match rules on series — no rule engine in Phase 8.
- A "create rule from this series" affordance — bridges Phase 7 + Phase 8, deferred to v2 unless a Wave fits.

**Architectural anchor:**
Phase 8 is a NEW analytical layer that sits on top of the locked Phase 1–7 transaction pipeline. It never writes to `transactions`; it only reads + clusters them. The hybrid trigger model (scheduled sweep + on-demand re-detect) keeps the import path fast (no synchronous detection in `RecordTransactions`) while still letting power users force a refresh after a big backfill.

</domain>

<decisions>
## Implementation Decisions

### Detection Trigger Model

- **D-801:** **Hybrid trigger model: scheduled daily sweep + user-initiated on-demand re-detect.** The detector runs as a scheduled job (`Schedule::daily()`), and `/recurring` carries a "Re-detect now" button that dispatches the same job for an immediate sweep. Event-driven re-clustering on every `TransactionImported` was rejected — recurring detection is a holistic-window analysis, not a per-row analysis, and large CSV backfills would otherwise trigger N expensive re-clusters. The on-demand path uses `ShouldBeUniqueUntilProcessing` so spam-clicking the button is a no-op (mirrors Phase 5/6 patterns).
- **D-802:** **User-configurable detection window; default 18 months.** Exposed in `/settings` alongside the income-minimum-amount threshold. Default 18 months covers monthly + quarterly + yearly cadences with room for one missed billing. Tightening to 12 months delays yearly-cadence detection (2 occurrences min × yearly = 2 years before yearly subscriptions are caught at that setting); widening to 24 months keeps stale discontinued series visible longer. The default is what most users will keep.
- **D-803:** **Minimum 2 occurrences for a candidate to appear in `/recurring/review`.** Faster initial-month populated view; matches the ROADMAP "≥3 months of imported history" dependency (3 months of monthly subscription = 3 occurrences, so 2-occurrence detection produces candidates after month 2). False positives are a tolerable trade — the suggest-never-auto-apply gate (REC-03) catches them.
- **D-804:** **Re-evaluate approved series every sweep; require re-approval ONLY on a cadence-class flip.** Approved series have their amount / monthly-equivalent / latest-funding-chain / next-expected-charge / variance-tolerance metrics refreshed by every sweep — this is what makes the "amount drift over time" history (REC-05, UI-03) work AND what feeds Phase 9 drift alerts. Day-to-day interval jitter (28d vs 31d for monthly) does not trigger re-approval. Only a cadence-class flip (monthly → quarterly, weekly → monthly, etc.) pushes the series to `cadence_changed` state, where it waits for explicit user confirmation. Locking metrics at approval was rejected because it blocks Phase 9.
- **D-805:** **On-demand re-detect re-queues the same sweep job.** Both the scheduled task and the `/recurring/re-detect` button dispatch `DetectRecurringSeriesJob` with the same `ShouldBeUniqueUntilProcessing` key. The user sees a "detecting…" toast (Phase 4/5 dispatch + Alpine `x-on:toast.window` pattern) and refreshes the page when the toast resolves. Synchronous in-request detection was rejected — even modest datasets push the operation past acceptable request latency.

### Approval Surface

- **D-806:** **Dedicated `/recurring/review` queue, not inline filters on `/recurring`.** Pending suggestions live in `/recurring/review`; the `/recurring` page only shows approved series. Mirrors Phase 5 `/chains/review` precedent. Top-nav slot for "Recurring" carries a badge with the pending count (View Factory composer pattern from Phase 5/6/7). Inline approval on `/recurring` was rejected because it would pollute the fixed-payments view with not-yet-approved noise. A first-time onboarding wizard was rejected as too heavy.
- **D-807:** **Four actions on a pending suggestion: Approve / Reject / Edit name / Snooze.** Approve flips state and surfaces the series on `/recurring`. Reject hides the suggestion permanently (no auto-resurface) but is reversible via a "Rejected" tab + un-reject action. Edit-name lets the user override the auto-derived merchant name; the override is persisted on the series row and respected across re-detection sweeps (the auto-derived name keeps tracking underneath so un-overriding works). Snooze hides for a user-picked duration (D-810).
- **D-808:** **Reject is permanent until un-rejected, never auto-resurfaces.** Rejected suggestions live in a "Rejected" tab on `/recurring/review` with an un-reject action. New occurrences of a rejected series do NOT re-prompt the user. Calm aesthetic — once-decided stays decided. Resurfacing on "large changes" was rejected as too naggy.
- **D-809:** **Dashboard surfacing = top-nav badge + dashboard card.** Top-nav "Recurring" carries a pending-count badge (View Factory composer pattern). Dashboard `/` renders an inline "Fixed monthly payments" card showing the top ~6 series by monthly equivalent with a "View all →" link to `/recurring`. Mirrors UI-01's explicit "fixed-payments list with chain icons" deliverable. The dashboard card carries an "All series / This month only" toggle (D-815 expands).
- **D-810:** **Snooze uses a date picker: 1 week / 1 month / 3 months / custom.** Date-picker UX (popover with 3 preset chips + a custom-duration text input) so the user controls the snooze interval. Phase 9 drift-alert snooze (REC-08) reuses the same component. Fixed-30-day snooze was rejected because Phase 9's snooze must be configurable — building two snooze UIs is wasteful. Snooze-until-next-occurrence was rejected as confusing.
- **D-811:** **Approval is independent of merchant memory.** Approving a series flips its state machine. It does NOT touch `merchant_memories` — that table grows from manual categorization (Phase 7 `MerchantMemoryWriter` listening to `TransactionCategorized`). Keeps the two concepts decoupled: a user can approve a series without locking its category, and the user can re-categorize the underlying merchant without affecting the series.
- **D-812:** **Checkbox-select + bulk Approve/Reject action bar on `/recurring/review`.** Essential after the first big backfill when ~20 suggestions land at once. Row checkboxes + a sticky bottom action bar showing "Approve N / Reject N". Bulk Snooze and bulk Edit-name are out (per-row actions stay per-row).
- **D-813:** **Edit-name persists across re-detection.** User-overridden display name lives in `recurring_series.display_name_override` (nullable). The auto-derived name lives in `recurring_series.detected_name` and keeps refreshing on every sweep. The `/recurring` view shows `display_name_override ?: detected_name`. Daily sweeps never clobber the override; the user can null-out the override to fall back to auto-derived.
- **D-814:** **10-second Undo toast on every Approve / Reject / Snooze action.** Same pattern as Phase 4 reclassify toast + Phase 5 chain confirm toast (`$this->dispatch('toast', ...)` + Alpine `x-on:toast.window`). The action is also reversible from `/recurring` / `/recurring/review` so the undo is a UX nicety, not a recovery mechanism.
- **D-815:** **Full state-machine history table: `recurring_series_transitions`.** Columns: `id`, `user_id` (FND-03), `recurring_series_id`, `from_state`, `to_state`, `transition_reason` (enum: `'user_action'` / `'detector_cadence_flip'` / `'detector_promoted'` / etc.), `actor` (enum: `'user'` / `'detector'`), `transitioned_at`, `notes` (nullable). Every state transition writes a row. State-only-on-the-row was rejected because Phase 9 needs the transition history to compute "this series drifted within N days of the last drift acknowledgement"-style queries, and the audit trail makes debugging detector regressions much easier.

### Income vs Expense Detection

- **D-816:** **Unified `recurring_series` table with a `direction` enum (`'expense'` / `'income'`).** Single detector code path; the only branch is the WHERE clause that seeds candidates (`transaction.type IN ('expense', 'fee', 'refund')` for the expense detector; `transaction.type = 'income'` for the income detector). LED-06 explicitly says "detected the same way" — unified is the cleanest interpretation. Phase 10 forecasting reads both sides from the same table via the Public Query. Two parallel tables was rejected — doubles every UI surface and every Phase 9/10 cross-reference.
- **D-817:** **Income clustering: hybrid IBAN-primary + normalized-description-fallback.** The `IncomeSeriesDetector` clusters first on counterparty IBAN (stable for most employers and recurring transfers); if IBAN is missing or unstable across occurrences (some multi-payroll-provider setups), it falls back to a normalized counterparty-name match. Pure-IBAN clustering misses employers who vary IBAN; pure-description clustering misses employers who vary descriptions. Hybrid is the realistic real-world shape.
- **D-818:** **`/recurring` page = one list with grouped sections (Expenses on top, Income below, optional Transfers section at the bottom collapsed by default).** A net "monthly fixed flow" summary at the very top: `−€X expenses + €Y income = €Z net`. Inside each section, rows are sorted by monthly equivalent descending (largest first). Tabs were rejected because tabs hide one side at a time, defeating the "balanced cash-flow picture" intent. A single mixed table was rejected because the eye re-orients between in/out on every row.
- **D-819:** **Recurring transfers (e.g., ASN → ICS settlement) are excluded from the main view by default; visible in a collapsed "Recurring transfers" section for visibility only.** Phase 5 LED-04 already pairs ASN→ICS bulk-iDEAL as transfer-out/transfer-in, and the Phase 5 dashboard "Next ICS settlement" tile is the canonical surface for that flow. Phase 8 detects these as recurring (the cadence math doesn't know the difference) but the view treats them as a separate, informational-only section that excludes them from the cash-flow totals. Phase 10 forecasting reads its own filter so transfers never double-count.
- **D-820:** **Income minimum-amount threshold: user-configurable, default €2000 (salary-sized).** Incomes below €2000 are not auto-clustered into series — they're typically refunds, cashback, occasional reimbursements, not recurring income. Default chosen to be salary-sized; user lowers it in `/settings` if they have stipends or smaller regular inflows. Threshold lives in `users.recurring_income_min_amount_minor` (BIGINT minor units, FND-04). A no-threshold option was rejected as too noisy; an adaptive (average/10) threshold was rejected as opaque.
- **D-821:** **Each distinct IBAN cluster becomes its own series in multi-payroll situations.** Two stable income IBANs = two series, each with its own cadence and amount. User can rename them via the edit-name action (D-813). No "merge series" UI in v1 — defer to v2 if the use case proves real.
- **D-822:** **Income detector runs ONLY on transactions classified `type='income'` by Phase 4 LED-05.** Trusts upstream classification; clean separation of concerns. Misclassified inflows are corrected via the existing transaction-level reclassify flow (Phase 4 D-80), not by the recurring detector. The detector does not write `transaction.type` (covered by the `noTransactionWritesFromRecurring` BoundaryArchTest invariant — D-830).
- **D-823:** **Rejecting a series does NOT change the underlying transactions' type or category.** Reject only affects the series suggestion. The series is a clustering layer; underlying rows are unaffected. Lets the user keep individual transactions categorized while rejecting the "series" framing.

### Drift Display + Variance Tolerance

- **D-824:** **Amount column on `/recurring` shows latest amount + small drift indicator chip.** Primary text is the most recent occurrence amount (e.g., `€11.49`); a subtle `↑ €1.50` chip renders alongside when the latest differs from the immediately-prior occurrence. Drill-in (`/recurring/series/{id}`) shows the full amount-over-time chart. Calm but informative. Range display and rolling-average display were both rejected — the range hides the "what am I paying right now" answer, and the rolling average masks the recent price changes that Phase 9 drift alerts need to catch.
- **D-825:** **Variance tolerance is per-series, default ±25%.** Each row carries its own `variance_tolerance_percent` column (default 25). User can edit it inline on the row when a series is volatile (electricity bills, gym pricing, freelance income). Phase 8 enforces tolerance during clustering: occurrences whose amount falls within `latest_amount × (1 ± tolerance)` join the series; outliers don't fragment the series. A global locked tolerance was rejected because the user explicitly wants flexibility on volatile rows; a global `/settings` knob was rejected because the right value differs per series.
- **D-826:** **Monthly equivalent = latest occurrence amount × cadence multiplier.** Spotify just bumped to €11.49? `/recurring` shows €11.49/mo. Matches "what am I paying NOW" expectation. Cadence multiplier: weekly × 4.33; monthly × 1; quarterly ÷ 3; yearly ÷ 12. The last-3 average and full-series median were rejected because they smear price changes and would conflict with the Phase 9 drift baseline math.
- **D-827:** **Drill-in (`/recurring/series/{id}`) = full ApexCharts line/bar chart + occurrences table.** Top of page: full ApexCharts visualization with x-axis dates, y-axis amount (original currency primary, EUR shadow when distinct), drift markers when amount-class flips. Bottom: table of every occurrence with date, amount, transaction-id link. UI-03 explicitly asks for "amount-drift trend over time" — a real chart, not a sparkline. The page is intentionally heavier than `/recurring` because the user is on it deliberately.
- **D-828:** **Funding chain icon = account icon stack with chain link badge.** Each `/recurring` row renders the funding-source account avatar (ASN / ICS / PayPal — Phase 4/5 icon set) with a small chain link badge on top when a Phase 5 chain link exists. Click opens the Phase 5 chain drawer (already shipped). The icon reflects the most-recent occurrence's chain (D-829). Inline "ASN → PayPal → Netflix" text was rejected as cluttering the calm aesthetic; tooltip-only was rejected because the chain isn't discoverable.
- **D-829:** **Funding chain shown = most-recent occurrence's chain.** Consistent with the latest-amount choice (D-824/D-826). If a subscription switches from PayPal-via-ICS to direct ASN, the icon reflects the new chain. Modal-frequency and "mixed chain" badges were rejected — the user wants to see what's happening NOW. The chain drawer (Phase 5) shows the full chain history anyway.
- **D-830:** **Next-expected-charge displayed as relative + absolute date with a low-confidence indicator.** Format: `in 5 days · May 22` for near dates; `Jun 18` only for dates > 2 weeks out (relative loses meaning past ~14 days). When cadence variance is high (stddev > 5 days across the detection window), render the date dim/italic with a tooltip explaining the uncertainty. Single-date estimate keeps Phase 8 simple; Phase 10 forecasting introduces explicit uncertainty ranges.

### Module Home + Boundary Tests

- **D-831:** **New `Modules/Recurring/` bounded module.** Mirrors `Modules/Receipts/` (Phase 7), `Modules/Chains/` (Phase 5), `Modules/Transfers/` (Phase 4). Public/Internal split from day one. Owns: `recurring_series` + `recurring_series_occurrences` + `recurring_series_transitions` tables, the sweep detector + on-demand re-detect job, the `SeriesDetector` contract + the two implementations, the `/recurring` + `/recurring/review` + `/recurring/series/{id}` Livewire SFCs, the dashboard-card view-composer contributor. Composer.json, ServiceProvider, dedicated tests dir. Extending Categorization or Ledger was rejected as boundary dilution.
- **D-832:** **Public surface = Queries + DTOs + Events + Actions; mutations stay Internal.** Public: `RecurringSeriesQuery` (lists, filters, drill-in), `FixedPaymentsViewQuery` (the headline dashboard + `/recurring` projection), `RecurringSeriesDto` / `RecurringOccurrenceDto` / `NextExpectedChargeDto` / `RecurringSeriesAmountTrendDto`, the events (`RecurringSeriesDetected` / `RecurringSeriesApproved` / `RecurringSeriesRejected` / `RecurringSeriesCadenceFlipped`), and the Public Actions (`ApproveRecurringSeries` / `RejectRecurringSeries` / `SnoozeRecurringSeries` / `EditRecurringSeriesName` / `UnRejectRecurringSeries`). Detector internals + state-machine internals + projection queries stay private. Phase 9 (drift) + Phase 10 (forecasting) consume only the Public layer.
- **D-833:** **Four BoundaryArchTest invariants.** (1) `noFacadeCallsFromRecurring` — DI invariant carry-forward; no `auth()` / `request()` / `config()` / `Auth::` / `DB::` etc. inside `Modules/Recurring/`. (2) `noTransactionWritesFromRecurring` — Recurring is analytical-only; transaction.type stays owned by Phase 4 LED-05. (3) `crossModuleAccessGoesThroughPublic` — every Phase 9/10 import of `Modules\Recurring\*` MUST go through `Modules\Recurring\Public\*`. (4) `noSynchronousDetectionInRequestLifecycle` — detector code may only run inside queue workers / scheduled jobs, never in the HTTP request cycle. Implemented via a `RunsDetection` marker interface or a base class assertion (planner picks the exact mechanism that PHPStan + Pest can express cleanly).
- **D-834:** **Recurring reads merchant categorization via a new `Modules\Categorization\Public\Services\MerchantMemoryQuery`.** Phase 7 already writes `merchant_memories` from its `MerchantMemoryWriter` listener. Phase 8 needs to read it (to decorate `/recurring` rows with the user-assigned category). Adding the Public service formalizes the cross-module read boundary so a future Categorization schema change doesn't ripple into Recurring. Direct Eloquent reads from `Recurring` into `merchant_memories` rows were rejected as boundary leakage.

### Dashboard Integration

- **D-835:** **Dashboard `/` renders an inline "Fixed monthly payments" card with the top ~6 series + "View all →" link.** UI-01 explicitly asks for this. Card sources its data from `FixedPaymentsViewQuery` (Public). Limit ~6 keeps the dashboard calm; the full list is one click away. Summary-tile-only and full-list-on-`/` were both rejected — the former loses the headline information density UI-01 calls for, the latter conflates dashboard + detail page.
- **D-836:** **Income shows up on the dashboard via the existing in/out/remaining tile.** The Phase 1 / Phase 5 "this month at a glance" top tile already shows monthly inflow. The fixed-payments card's income section (mirroring `/recurring`'s expense+income layout) gives the recurring breakdown. No new dedicated income tile.
- **D-837:** **The Phase 5 "Next ICS settlement" dashboard tile stays as-is.** Recurring transfers are a separate context (D-819). Two dashboard tiles, two purposes — the ICS forecast tile uses cleared-since-last-settlement math (Phase 5), the recurring-transfers section uses cadence inference. Merging them was rejected as coupling Phase 8 to Phase 5 internals.
- **D-838:** **Dashboard "Fixed monthly payments" card carries an "All series / This month only" toggle.** Default `All series` (every approved series with its monthly equivalent, including quarterlies + yearlies normalized to monthly). Toggle to `This month only` to filter to series whose next-expected-charge falls in the current month. The toggle state persists in the user's preferences (mirrors Phase 3 `default_currency_view` precedent).

### Multi-currency Series

- **D-839:** **Clustering happens on original currency + amount, not settled EUR.** A USD $11.99 Netflix charge clusters with other USD $11.99 Netflix charges regardless of the EUR settlement drift. Honest representation — the user IS paying $11.99 monthly; FX is incidental. EUR-native sources (ASN) populate original_currency=EUR/settled_currency=EUR so the same code path works. The "hybrid: original first, fall back to settled" option collapses to this in practice because every Phase 1+ ingestion preserves both fields.
- **D-840:** **Monthly equivalent renders original-currency primary with EUR shadow.** `$11.99/mo` (primary) `≈ €11.20` (secondary, smaller, gray). Extends the Phase 3 D-44/D-47 dual-currency display patterns. The EUR shadow uses the latest occurrence's FX rate. Settled-EUR-only was rejected because it conflicts with the project's preserve-original-currency invariant.
- **D-841:** **Dashboard total monthly fixed = single EUR sum using each series' latest FX rate.** The dashboard "Fixed monthly payments" card headline number is one EUR figure. Foreign series contribute their latest-occurrence settled EUR amount. The per-row detail still shows original currency. Matches Phase 5's existing dashboard EUR-summary behavior. Per-currency tile rows (Phase 3 D-46) were rejected for the dashboard summary specifically — too dense in this surface; the original-currency truth is one row down in the card.
- **D-842:** **FX drift is shown prominently in the drill-in but never on `/recurring`'s amount column unless original-currency price actually moves.** The drift indicator chip (D-824) on `/recurring` is original-currency-only. The drill-in chart (D-827) shows both original-currency line + EUR-cost line, with the EUR line dimmer. Phase 9 drift alerts check original-currency drift only (so FX-only changes never fire a "subscription price changed" alert). This is the honest answer to "did my Spotify cost change" — yes-if-it-cost-more-in-the-currency-charged.

### Cadence Detection + Fixtures

- **D-843:** **Cadence inference: median interval + nearest-class snap.** Compute median days-between-occurrences. Snap: <10d=weekly, 10-45d=monthly, 80-100d=quarterly, 350-380d=yearly. Anything outside all bands → cadence=`'irregular'` and the candidate is skipped (don't propose a series the math doesn't support). Median is robust to one or two outliers; mode is brittle on small-sample series; statistical fit (chi-squared/KS) is overkill for the data volume.
- **D-844:** **Missed-occurrence tolerance: 1 missed period per 6 observed.** Compute interval gaps; any single interval > 1.8 × median triggers a "missed period" counter. > 2 missing in any rolling 6-period window flips the series to `cadence_changed` state (D-804). One bank-holiday + one slow-processing month should NOT fragment a 2-year monthly subscription, which the strictest variant would.
- **D-845:** **Wave 0 fixtures: controlled-time-series corpus + one anonymised real export.** Synthesised series: stable monthly (Spotify @ €9.99), drifting monthly (Spotify €9.99 → €11.49 mid-window — REC-02 exemplar), quarterly insurance, yearly domain renewal, weekly streaming credit, monthly salary, two-employer salary, irregular gym charges (must NOT cluster), missing-month subscription (must remain one series under D-844 tolerance), mixed-currency Netflix (must cluster on USD per D-839), variable-amount-beyond-tolerance bills (must fragment per D-825). Plus one anonymised real ASN + ICS export covering ≥6 months for end-to-end validation. Same precedent as Phase 5 D-107 and Phase 6 D-140.
- **D-846:** **Tests: unit (`Modules/Recurring/tests/Unit/`) for cadence math + contract (`tests/Contracts/RecurringDetectionContractTest.php`) for end-to-end.** Unit tests use a Pest dataset over interval-list/expected-class pairs (~15-20 rows). Contract test runs the full sweep against the Wave 0 fixture corpus and asserts the full set of detected series, their states, cadences, and metrics. Standard test pyramid; matches the `IdempotencyContractTest` pattern.

### Claude's Discretion

- **D-847:** Wave structure (suggested: Wave 0 = `Modules/Recurring/` skeleton + boundary tests + synthesised fixtures + `MerchantMemoryQuery` Public surface in Categorization + Pest registration; Wave 1 = migrations + `recurring_series` schema + state machine + DTOs + Public Actions skeleton; Wave 2 = `ExpenseSeriesDetector` + cadence math + `DetectRecurringSeriesJob` + `/recurring/review` queue with single-item Approve/Reject/Snooze; Wave 3 = `IncomeSeriesDetector` + unified detector run + `/recurring` page with expense + income sections; Wave 4 = drill-in page with ApexCharts + funding-chain icon + multi-currency rendering + dashboard inline card + "Re-detect now" + bulk actions + Undo toast). Planner verifies against goal-backward analysis.
- **D-848:** Exact storage shape for `recurring_series.detected_cadence` — likely a string enum mirroring the cadence-class set (`'weekly'` | `'monthly'` | `'quarterly'` | `'yearly'` | `'cadence_changed'` | `'irregular'`). Planner picks the column type (enum vs string).
- **D-849:** Container-tag name for `SeriesDetector` implementations — suggested `'recurring.detector'`. Planner picks and codifies in the `Modules/Recurring/Providers/RecurringServiceProvider.php`.
- **D-850:** Exact mechanism for `noSynchronousDetectionInRequestLifecycle` — could be a `RunsDetection` marker interface that arch test verifies is only implemented by jobs/commands, or a stricter "classes named `*Detector` must be invoked only from `*Job` or `*Command` callsites". Planner picks based on what Pest's `arch()` plugin expresses cleanly.
- **D-851:** Exact FX rate source for the EUR shadow on `/recurring` — likely `transaction.fx_rate` from the latest occurrence (already preserved per LED-03). Planner confirms; alternative is the most-recent FX rate across ANY transaction in that currency, but per-occurrence is more honest.
- **D-852:** Whether the "Recurring transfers" collapsed section appears on first-visit-only (auto-collapsed thereafter) or always-collapsed (user opens). Planner picks based on UI-SPEC pass.
- **D-853:** Top-nav slot positioning for "Recurring" — Phase 7's CONTEXT (D-721) flagged top-nav crowding. If `/rules` + `/uncategorized` + `/chains/review` + `/recurring` overcrowd the bar, planner may introduce a "Triage" or "Categorize" submenu. UI-SPEC pass owns this.
- **D-854:** Whether the per-series `variance_tolerance_percent` editor is a slider, a numeric input, or a dropdown of fixed steps (10% / 25% / 50% / custom). Planner / UI-SPEC pass.
- **D-855:** Whether the dashboard "Fixed monthly payments" card's "This month only / All series" toggle persists as a per-user setting or as a `#[Url]` query string (Phase 3 D-44 precedent for the currency toggle uses `#[Url]`). Planner picks.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-Level
- `.planning/PROJECT.md` — Project constraints. Critical here: DI-only invariant (constructor injection, no facades/helpers); `nwidart/laravel-modules` bounded modules with Public/Internal split; Larastan level 10 strict + Pint + Pest CI gates; calm aesthetic (Linear/Notion); GSD-agnostic code comments invariant; income as a first-class concept (not "negative expense").
- `.planning/REQUIREMENTS.md` — Phase 8 covers REC-01..05, LED-06, UI-03. Adjacent (already-met) requirements that Phase 8 must respect: LED-01/02/04/05 (transaction types + transfer-pair contract + income detector), MC-01/02 (multi-currency display), CAT-01..05 (categorization read-side via the new MerchantMemoryQuery), CHN-01..07 (chain links feeding funding-source icons), FND-03 (per-user data isolation), FND-04 (BIGINT minor units).
- `.planning/ROADMAP.md` §"Phase 8" — Goal + five success criteria (fixed-monthly-payments view with name/monthly-equivalent/funding/chain/category/next-expected; tolerate moderate amount variance; suggest-never-auto-apply; drill-in with history + trend; recurring income alongside expenses).

### Prior Phase Artefacts (read for continuity — same patterns apply)
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-CONTEXT.md` — Module split, DI-only, wizard preview-then-confirm pattern, BelongsToUser invariant, merchant_memories + merchants table origin, dashboard "this month at a glance" tile pattern (UI-01).
- `.planning/phases/02-asn-statement-coverage-camt-053-mt940/02-CONTEXT.md` — Pipeline + FingerprintComposer v3 reference. Phase 8 reads transactions but never writes them — this CONTEXT shows the locked transaction shape Recurring consumes.
- `.planning/phases/03-ics-cards-multi-currency-display/03-CONTEXT.md` — `/settings` page extension pattern + `default_currency_view` precedent (D-836 dashboard toggle borrows from D-44 toggle). Multi-currency display rules (D-44/D-46/D-47) that D-840/D-841 extend.
- `.planning/phases/04-paypal-ingestion-transfer-detection/04-CONTEXT.md` — LED-04 transfer-pair contract that D-819 honors. LED-05 income detector that D-822 trusts upstream. `Modules/Transfers/` Public/Internal module shape that `Modules/Recurring/` mirrors. Reclassify toast pattern (Phase 4 D-80) that D-814 reuses.
- `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-CONTEXT.md` — Horizon + Redis queue infrastructure inherited (Phase 5 D-95 + STACK.md flip). `chain_links` table + ChainLinkQuery Public surface that D-828 funding-chain icon reads. `/chains/review` queue UX precedent that `/recurring/review` (D-806) mirrors. ShouldBeUniqueUntilProcessing job pattern that D-801/D-805 reuse. Dashboard tile + top-nav badge view-composer pattern (Phase 5 issue #12 fix) that D-809 reuses.
- `.planning/phases/06-email-receipt-ingestion-infrastructure/06-CONTEXT.md` — InboxScanStateMachine pattern reference for the new `recurring_series_transitions` audit (D-815). Scheduled task + launchd pattern that D-801 / D-805 reuse.
- `.planning/phases/07-email-template-matchers-categorization-learning/07-CONTEXT.md` — **Required read.** `MerchantMemoryWriter` listener pattern (Phase 7) that D-811 deliberately decouples from. The merchant-memory schema (already in Ledger; written by Categorization) that the new `Modules\Categorization\Public\Services\MerchantMemoryQuery` (D-834) exposes for Phase 8's reads. ApplyAutoCategoryStage pipeline-stage shape if Phase 8 needs to feed categorization hints (likely not — Phase 8 stays analytical).

### Research
- `.planning/research/SUMMARY.md` §"Phase 8" — Cadence inference + recurring detection state-of-the-art. Plan-phase must read for industry-consensus heuristics (median-interval-snap, ±25% amount tolerance, suggest-never-auto-apply).
- `.planning/research/ARCHITECTURE.md` — Section on recurring-series modeling if present. The state-machine + audit-table shape that D-815 uses.
- `.planning/research/PITFALLS.md` — Any pitfalls flagged for cadence detection (banking-day shifts at month boundaries; week-of-month-vs-day-of-month for "every fourth Wednesday" subscriptions; quarterly insurance billing that arrives mid-month) inform the missed-occurrence tolerance + edge-case fixture set.
- `.planning/research/STACK.md` — ApexCharts (D-827 drill-in chart) is the project's locked chart library. Horizon + Redis queue infrastructure (Phase 5 STACK.md amendment) is inherited.

### External Documentation (Phase 8's research targets)
- ApexCharts line/bar chart docs — https://apexcharts.com/docs/chart-types/line-chart/ — D-827 drill-in chart.
- Livewire 4 docs — https://livewire.laravel.com/docs — `wire:poll`, `$this->dispatch('toast')`, `#[Url]` for the dashboard toggle (D-838), `wire:transition` for the rejected→approved state change animation.
- Flux UI table + popover + dropdown + chips — https://fluxui.dev/ — `/recurring` table, snooze date-picker popover (D-810), variance-tolerance editor (D-854).
- Laravel Schedule docs — https://laravel.com/docs/scheduling — `Schedule::daily()` for D-801, `withoutOverlapping()` complementing `ShouldBeUniqueUntilProcessing`.
- Pest `arch()` plugin docs — https://pestphp.com/docs/arch-testing — the four new BoundaryArchTest invariants (D-833), especially the synchronous-detection arch test (D-850).

### Existing Source (read before extending)
- `composer.json` — Phase 8 adds no new dependencies (ApexCharts + Horizon + Pest already present). Composer audit must confirm no `ext-imap` regression (PLT-05 carry-forward).
- `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` — Transaction shape Recurring reads. `type` enum (expense / income / transfer-out / transfer-in / fee / refund), `original_amount` / `original_currency` / `settled_amount` / `settled_currency` / `fx_rate` (D-839/D-840/D-851), `merchant_id`, `counterparty_iban`, `counterparty_name_normalized`.
- `Modules/Ledger/Database/Migrations/2026_05_12_010006_create_merchants_table.php` — Merchants table used as the clustering key for `ExpenseSeriesDetector` via `MerchantMemoryQuery`.
- `Modules/Ledger/Database/Migrations/2026_05_12_010007_create_merchant_memories_table.php` — `merchant_memories` shape. Phase 8 reads it via the new Categorization Public service (D-834).
- `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php` — LED-04 transfer pair column. The "Recurring transfers" section (D-819) keys off `pair_transaction_id IS NOT NULL` to distinguish internal moves.
- `Modules/Transfers/Public/` — Module shape reference for `Modules/Recurring/Public/`.
- `Modules/Chains/Public/Services/ChainLinkQuery.php` — Read API the funding-chain icon (D-828) consumes.
- `Modules/Categorization/Public/Actions/AssignCategory.php` + `Modules/Categorization/Public/Events/TransactionCategorized.php` — Event the existing Phase 7 MerchantMemoryWriter listens to. Phase 8 does NOT listen to this event (D-811).
- `Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php` (Phase 7) — Pattern reference; Phase 8 doesn't extend it but reads its output.
- `Modules/Core/Public/Contracts/CurrentUser.php` — DI-only contract every Phase 8 service injects.
- `tests/Contracts/BoundaryArchTest.php` — Add the four new invariants (D-833) without breaking existing carry-forwards (especially Phase 6 `noTransactionWritesFromEmailScan` and Phase 7 `noEmailFetchFromReceipts`).
- `tests/Contracts/IdempotencyContractTest.php` — Phase 8 does not add new ingestion paths; no new dataset row required. The detector must be idempotent across re-runs (running the sweep twice produces the same series set), but that's a separate contract test (`RecurringDetectionContractTest`).
- `tests/Pest.php` — New `Modules\\Recurring\\Tests\\` PSR-4 entry must be added (3-step pattern documented in Phase 4 D-80b: composer.json autoload-dev + phpunit.xml testsuite + Pest.php).
- `routes/web.php` — New `GET /recurring`, `GET /recurring/review`, `GET /recurring/series/{id}`, plus Livewire-internal action endpoints. All gated by the Phase 1 LoopbackOnly + Fortify auth.
- `routes/console.php` — New scheduled task: `Schedule::call(fn () => DetectRecurringSeriesJob::dispatch(...))->daily()` per-user (multi-user-ready — FND-03).
- `app/Http/Composers/` (or wherever the View Factory composers live per Phase 5) — Add the "Recurring" top-nav badge composer + dashboard inline-card composer.
- `resources/views/dashboard.blade.php` (or wherever `/` lives) — Embed the new "Fixed monthly payments" card.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`transaction.type` enum from Phase 1/4** — Seeds the expense vs income split (D-816/D-822).
- **`merchant_memories` table from Phase 1, grown by Phase 7** — Source of merchant→category for `/recurring` row decoration via the new Categorization Public service (D-834).
- **`transactions.original_amount` + `original_currency` + `fx_rate` from Phase 3 LED-03** — Drives original-currency clustering (D-839) and EUR shadow display (D-840).
- **`chain_links` + ChainLinkQuery from Phase 5** — Funding-source chain icons on `/recurring` rows (D-828).
- **`pair_transaction_id` column from Phase 4 LED-04** — Distinguishes internal transfers from expenses for the Recurring transfers section (D-819).
- **Horizon + Redis queue infrastructure from Phase 5** — `DetectRecurringSeriesJob` runs on this; `ShouldBeUniqueUntilProcessing` is already a project idiom.
- **`/chains/review` Livewire SFC pattern from Phase 5** — `/recurring/review` mirrors this exact shape (D-806).
- **Toast pattern (`$this->dispatch('toast', ...)` + Alpine `x-on:toast.window`) from Phase 4/5** — D-814 Undo toast + D-805 "detecting..." toast reuse this.
- **View Factory composer pattern from Phase 5 (issue #12 fix)** — Top-nav "Recurring" badge with pending-count + dashboard inline-card both follow this (no `view()` global helper).
- **Phase 3 `default_currency_view` settings + Money formatter** — Locale-aware EUR formatting + multi-currency display (D-840/D-841).
- **ApexCharts from Phase 5 (chains drawer charts) / Phase 3 (potential dashboard chart)** — Drill-in chart on `/recurring/series/{id}` reuses the same library + theming (D-827).
- **`#[Url]` Livewire URL binding from Phase 3 D-44** — Optional precedent for the dashboard toggle (D-838/D-855).
- **Public/Internal split pattern from `Modules/Transfers/`, `Modules/Chains/`, `Modules/Receipts/`** — `Modules/Recurring/` is the fourth bounded analytical module; structure is locked.
- **BoundaryArchTest invariants from Phase 4/5/6/7** — D-833's four new invariants drop into the same Pest `arch()` test file.
- **Phase 6 InboxScanStateMachine** — Reference pattern for `recurring_series_transitions` (D-815). State + transition + lockForUpdate semantics.
- **Cross-user 404 invariant (Phase 3-07 + Phase 4-04 + Phase 5-04)** — All `/recurring/*` actions assert `$series->user_id === $currentUser->id` defensively + via `where('user_id', ...)` clauses.
- **DI-only invariant + raw `DatabaseManager` for whereBetween/whereIn/orderBy** — Every Phase 8 service follows this.

### New Code Surface (Phase 8 adds)
- **`Modules/Recurring/` bounded module** — composer.json, ServiceProvider (`RecurringServiceProvider`), Public/Internal split, dedicated tests dir.
- **`Modules/Recurring/Public/Contracts/SeriesDetector.php`** — Interface implemented by both `ExpenseSeriesDetector` and `IncomeSeriesDetector`.
- **`Modules/Recurring/Public/Dto/RecurringSeriesDto.php`** + **`RecurringOccurrenceDto.php`** + **`NextExpectedChargeDto.php`** + **`RecurringSeriesAmountTrendDto.php`**.
- **`Modules/Recurring/Public/Events/RecurringSeriesDetected.php`** + **`RecurringSeriesApproved.php`** + **`RecurringSeriesRejected.php`** + **`RecurringSeriesCadenceFlipped.php`** — Consumed by Phase 9 + Phase 10.
- **`Modules/Recurring/Public/Actions/ApproveRecurringSeries.php`** + **`RejectRecurringSeries.php`** + **`SnoozeRecurringSeries.php`** + **`EditRecurringSeriesName.php`** + **`UnRejectRecurringSeries.php`**.
- **`Modules/Recurring/Public/Services/RecurringSeriesQuery.php`** + **`FixedPaymentsViewQuery.php`** — Read APIs consumed by `/recurring`, `/recurring/review`, dashboard card, Phase 9, Phase 10.
- **`Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php`** + **`IncomeSeriesDetector.php`**.
- **`Modules/Recurring/Internal/CadenceInferrer.php`** — Median-interval-snap algorithm (D-843) + missed-occurrence tolerance (D-844).
- **`Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php`** — `ShouldBeUniqueUntilProcessing` per user; runs both detectors; updates state machine.
- **`Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php`** — `pending` / `approved` / `rejected` / `snoozed` / `cadence_changed` transitions with `lockForUpdate` + history write to `recurring_series_transitions`.
- **`Modules/Recurring/Internal/Http/Livewire/RecurringPage.php`** + **`RecurringReviewPage.php`** + **`RecurringSeriesDetailPage.php`** + **`FixedPaymentsCard.php`** (dashboard contributor).
- **`Modules/Recurring/Database/Migrations/*_create_recurring_series_table.php`** — id, user_id (FND-03), direction enum, detected_name, display_name_override (nullable), state enum, cadence enum, monthly_equivalent_minor (FND-04), latest_amount_minor, latest_currency, latest_fx_rate, latest_funding_chain_link_id (nullable), variance_tolerance_percent (default 25), snoozed_until (nullable timestamp), next_expected_at (nullable date), next_expected_confidence enum, created_at, updated_at.
- **`Modules/Recurring/Database/Migrations/*_create_recurring_series_occurrences_table.php`** — id, user_id, recurring_series_id, transaction_id, observed_at, observed_amount_minor.
- **`Modules/Recurring/Database/Migrations/*_create_recurring_series_transitions_table.php`** — id, user_id, recurring_series_id, from_state, to_state, transition_reason enum, actor enum, transitioned_at, notes (nullable).
- **`Modules/Recurring/Database/Migrations/*_add_recurring_settings_to_users.php`** — Adds `recurring_detection_window_months` (default 18) + `recurring_income_min_amount_minor` (default 200000 = €2000) to users.
- **`Modules/Categorization/Public/Services/MerchantMemoryQuery.php`** — D-834 new Public read API.
- **`tests/Contracts/BoundaryArchTest`** — Four new invariants (D-833).
- **`tests/Contracts/RecurringDetectionContractTest.php`** — End-to-end sweep over the Wave 0 fixture corpus.
- **`Modules/Recurring/tests/Unit/CadenceInferenceTest.php`** — Pest dataset over interval-list/expected-class.
- **`Modules/Recurring/tests/fixtures/`** — Synthesised + one anonymised real export (D-845).

### Established Patterns
- **DI-only — every new service injects collaborators via constructor.** `CadenceInferrer` is a stateless service; `ExpenseSeriesDetector` injects `MerchantMemoryQuery` + `DatabaseManager` + `CadenceInferrer`; the state machine injects `Clock` + the events dispatcher.
- **Public/ vs Internal/ split from day one** — `Modules/Recurring/Public/` ships the contract + DTOs + events + actions + queries; detectors / cadence math / state machine / Livewire pages stay Internal.
- **Eloquent direct OK, no facades** — `RecurringSeries::query()->where('user_id', $user->id)` allowed; `DB::table(...)` forbidden; raw `DatabaseManager` injected via constructor for whereBetween/whereIn shapes.
- **BoundaryArchTest invariants** — D-833 invariants; existing carry-forwards (Phase 6 `noTransactionWritesFromEmailScan`, Phase 7 `noEmailFetchFromReceipts`) stay green.
- **Pest test layout** — unit next to the code (`Modules/Recurring/tests/Unit/`), feature tests for `/recurring` and `/recurring/review` under `tests/Feature/`, contract under `tests/Contracts/`.
- **Synthesised fixture-first Wave 0** — same precedent as Phase 5 D-107 + Phase 6 D-140 + Phase 7 Wave 0.
- **GSD-agnostic code comments** — No D-numbers / REQ-IDs in runtime code or PHPDocs.

### Integration Points
- **`TransactionImported` event (Phase 5)** — Phase 8 does NOT listen to this event (the hybrid trigger model in D-801 deliberately avoids per-row detector dispatch). It's listed only because future Phase 8 maintenance may add a fast-path; the locked baseline is scheduled + on-demand.
- **`merchant_memories` reads** — Through `Modules\Categorization\Public\Services\MerchantMemoryQuery` (D-834) only. Direct Eloquent reads from `Modules/Recurring/` are forbidden by the new `crossModuleAccessGoesThroughPublic` arch test (D-833).
- **`chain_links` reads** — Through `Modules\Chains\Public\Services\ChainLinkQuery` for the funding icon (D-828).
- **Dashboard `/`** — New "Fixed monthly payments" card rendered alongside the existing "this month at a glance" tile + "Next ICS settlement" tile.
- **Top-nav** — New "Recurring" item with pending-count badge. UI-SPEC pass owns positioning (D-853).
- **Routes** — NEW `GET /recurring`, `GET /recurring/review`, `GET /recurring/series/{id}`, plus Livewire action endpoints for Approve/Reject/Snooze/EditName/UnReject/ReDetect. All loopback-only + Fortify-authenticated.
- **Settings page** — Two new settings fields: `recurring_detection_window_months` + `recurring_income_min_amount_minor`. Extends the Phase 3 `/settings` form per its established pattern.
- **Composer** — No new dependencies. Phase 8 builds on the locked stack.
- **Phase 9 hand-off** — `RecurringSeriesQuery::approvedForUser()` + `RecurringSeriesQuery::occurrencesForSeries()` are the read APIs Phase 9 (drift detection) consumes. `RecurringSeriesApproved` + `RecurringSeriesCadenceFlipped` events let Phase 9 react.
- **Phase 10 hand-off** — `FixedPaymentsViewQuery::monthlyEquivalentTotals()` feeds the forecasting baseline. Phase 10 receives a stable Public surface so its own work doesn't depend on Phase 8 internals.

### Risks Phase 8 Specifically Owns
- **Cadence inference brittleness** — Median-interval-snap (D-843) is robust to single outliers but misclassifies "every fourth Wednesday" subscriptions (interval = 28d, in the monthly band but actually weekly-flavoured). Mitigation: the Wave 0 fixture corpus must include several real-world quirks. Drill-in chart makes misclassifications visually obvious for user correction.
- **Suggest-never-auto-apply UX fatigue** — REC-03 mandates approval before display. If the first sweep surfaces 30+ candidates, the user faces a triage burden. Mitigation: bulk-approve action bar (D-812) and the "Approve all" pattern from Phase 5 chain review.
- **Income detector false positives** — A monthly €2500 reimbursement from an employer-affiliated travel account could look like salary. Mitigation: IBAN-primary clustering (D-817) + €2000 default threshold (D-820) + Reject is reversible (D-808).
- **Multi-currency edge case — currency switch mid-series** — User's Netflix charges flip from USD to EUR mid-window because they switched payment methods. Mitigation: detector clusters on (merchant_id, original_currency), so this produces two series — user can rename and treat as a continuation. Future v2: explicit "merge series" action.
- **`recurring_series_occurrences` table size** — Every transaction in every approved series gets a row. 10K transactions × 30% recurring = 3K rows; manageable. But the table grows monotonically; consider an index on (`recurring_series_id`, `observed_at DESC`) for the drill-in chart.
- **State-machine race conditions** — Two concurrent sweep jobs (scheduled + user-clicked re-detect) could mutate the same series. `ShouldBeUniqueUntilProcessing` per-user + `lockForUpdate` in the state-machine transitions handle this.
- **Re-approval-on-cadence-flip UX confusion** — Approved Spotify suddenly becomes "needs re-approval" because three skipped months pushed it from monthly to quarterly. Mitigation: a dedicated "Cadence changed" tab in `/recurring/review` with a "What changed?" inline panel showing old vs new cadence.
- **Funding-chain icon staleness** — Phase 5 chain links resolve asynchronously; a brand-new occurrence may not have its chain resolved yet at the time the sweep runs. Mitigation: D-829 says "most recent" — if it's unresolved, fall back to the previous occurrence's chain (or null with a placeholder icon).
- **Top-nav crowding** — D-853 already flags this. UI-SPEC pass decides.

</code_context>

<specifics>
## Specific Ideas

- **The hybrid trigger model is deliberately conservative.** Event-driven detection feels modern but burns CPU on the import path for marginal user benefit. Scheduled + on-demand gives the user "instant if I want it" without paying the import-path tax.
- **Approval gating is the single most important UX choice in this phase.** Suggest-never-auto-apply (REC-03) is industry consensus. The bulk-approve action bar exists specifically because the first big backfill is a triage moment — without bulk actions, the user faces a 30-item one-by-one queue and bounces.
- **Edit-name persistence (D-813) is a small detail with outsized UX impact.** "NETFLIX.COM" → "Netflix" is a one-time edit the user does once and never thinks about again. If the daily sweep clobbered that edit, the user would lose trust fast.
- **The unified `recurring_series` table is non-negotiable.** Phase 10 forecasting reads from ONE source of truth. Two parallel tables would force every forecast query to UNION-ALL and remember to filter transfers out twice.
- **Detector is analytical-only.** `noTransactionWritesFromRecurring` is the load-bearing boundary. If Phase 8 ever needs to "fix" a misclassified income, it does so by emitting an event that Phase 4's income detector consumes — not by mutating `transaction.type` directly.
- **Multi-currency clustering on original currency (D-839) is the honest interpretation.** The user is paying $11.99 monthly to Netflix. The EUR amount is what they happen to settle in. Phase 9 drift alerts must agree (D-842) — a 3% EUR move on a stable $11.99 charge is FX noise, not subscription drift.
- **Drill-in is intentionally heavier than the list views.** The user lands on `/recurring/series/{id}` deliberately to study a specific series. A full ApexCharts chart belongs there; sparklines belong on `/recurring`.
- **Re-approval on cadence-class flip only (D-804) is calibrated for trust.** Within-class drift (28d vs 31d) is normal banking jitter — re-prompting is fatigue. Cross-class flips (monthly→quarterly = "did this become annual? did the user cancel and re-up?") is a real signal worth acknowledging.

</specifics>

<deferred>
## Deferred Ideas

- **"Create rule from this series" affordance** — Quick-action on `/recurring/series/{id}` to pre-populate a Phase 7 categorization rule from the series. Cross-phase nicety; v2 if Wave 4 doesn't fit.
- **"Merge series" action** — Combining two detected series into one (e.g., multi-employer payroll, currency-switch mid-window). v2 power-user feature.
- **Bulk Edit-name** — User wants to rename 5 series at once. v2 nicety; v1 has per-row Edit-name.
- **Adaptive variance tolerance** — Automatically loosen tolerance on series with consistently-volatile amounts (electricity bills). v1 has per-series tolerance editor (D-825); auto-tuning is v2.
- **Forecasting baseline integration (Phase 10)** — `FixedPaymentsViewQuery::monthlyEquivalentTotals()` is the read API Phase 10 consumes. Phase 8 ships the API; Phase 10 ships the consumer.
- **Drift alerts (Phase 9)** — REC-06/07/08. Phase 8 ships the data and the events; Phase 9 ships the surface.
- **Series export to CSV/JSON** — Power-user / external-tool integration. v2.
- **Per-merchant "always cluster" / "never cluster" hint** — Override the detector's clustering decision per merchant. v2 — current rejection-as-permanent (D-808) covers most use cases.
- **Annual recurring detection improvements** — REQUIREMENTS.md already deferred this to v2 (needs ≥13 months of history to be reliable). Phase 8 ships yearly cadence detection but stays conservative on it.
- **Sub-weekly cadence (daily, sub-daily)** — Out of scope. The cadence-class set is weekly / monthly / quarterly / yearly only. Sub-weekly recurring transactions are unusual for the project's domain.
- **"Why is this a series?" explanation panel** — Surface the matched intervals + amounts + cadence-fit confidence as a debug panel on the drill-in. v2 power-user.
- **Push notification when a new series is detected** — Local toast on the dashboard happens naturally via the badge; push notifications are out of scope (PLT-01 localhost-only constraint).
- **Onboarding wizard for first big backfill** — Considered for D-806 and rejected as too heavy for v1. v2 if users report the bulk-approve action bar isn't enough.
- **Series tagging / custom user labels** — Tag series with custom labels ("essentials", "discretionary"). v2.

</deferred>

---

*Phase: 8-Recurring Detection + Fixed Payments View*
*Context gathered: 2026-05-17*
