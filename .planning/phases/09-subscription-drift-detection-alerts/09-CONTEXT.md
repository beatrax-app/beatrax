# Phase 9: Subscription Drift Detection + Alerts - Context

**Gathered:** 2026-05-17
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 9 ships subscription drift detection over the approved recurring series Phase 8 already produces, plus a dedicated `/drift` alerts surface with persistent alert rows and an audit trail. The deliverable is: a new `Modules/DriftAlerts/` bounded module (mirroring `Modules/Recurring/` and `Modules/Chains/`) that owns the drift alerts table + state machine + `/drift` view, and a small extension to `Modules/Recurring/` that owns the drift-math service (because drift is intrinsically a recurring-series concept) plus a new `RecurringSeriesOccurrenceAppended`-style event surface DriftAlerts subscribes to. DriftAlerts evaluates drift via its own `DetectDriftAlertsJob`, writes one drift_alert row per detected drift event (queued — multiple open alerts per series may coexist), surfaces them on `/drift` with a count badge on the dashboard, and offers three actions: acknowledge, snooze (reusing Phase 8 D-810's date-picker component), and what-if-cancel.

**What Phase 9 delivers (vertical):**
- A new `Modules/DriftAlerts/` bounded module with Public/Internal split from day one. Owns `drift_alerts` + `drift_alert_transitions` tables, the `DetectDriftAlertsJob`, the alert state machine, the `/drift` Livewire SFC, the dashboard count badge composer, the `CancellationImpactQuery` Public service that Phase 10 will reuse.
- A small extension to `Modules/Recurring/` that publishes a `RecurringSeriesOccurrenceAppended` (or equivalent) public event after every approved-series sweep recomputes `latest_amount_minor`. The drift-math service itself is a `DriftEvaluator` (planner picks home: `Modules/Recurring/Internal/` if it needs raw access to occurrence rows for the immediately-prior amount, OR `Modules/DriftAlerts/Internal/` if it reads through `Modules\Recurring\Public\Services\RecurringSeriesQuery::occurrencesForSeries`).
- A `drift_alerts` table: `id`, `user_id` (FND-03), `recurring_series_id` (FK Recurring), `state` enum (`open` / `acknowledged` / `snoozed` / `dismissed_cancelled`), `direction` enum (`expense` / `income`, denormalised from recurring_series for query speed), `baseline_amount_minor`, `latest_amount_minor`, `currency` (3), `delta_minor` (signed BIGINT), `annualized_impact_minor` (signed BIGINT — delta × cadence-to-year multiplier), `threshold_percent_used` (tinyint — captures the effective threshold at detection time), `snoozed_until` (nullable timestamp), `detected_at`, `actioned_at` (nullable), `created_at`, `updated_at`.
- A `drift_alert_transitions` table mirroring Phase 8 D-815 audit shape: `id`, `user_id`, `drift_alert_id`, `from_state`, `to_state`, `transition_reason` enum, `actor` enum (`user` / `detector`), `transitioned_at`, `notes` (nullable). Every state change writes a row.
- A `DetectDriftAlertsJob` (`ShouldBeUniqueUntilProcessing` per-user) subscribed to the Recurring occurrence-appended event. Listener queues the job; the job reads approved-state series (skipping `cadence_changed` is NOT done — drift fires alongside cadence flips per D-910), computes drift, writes alert rows. Re-entry-safe: re-running the job for the same occurrence does not double-write because the job consumes the same event payload deterministically and the (recurring_series_id, detected_at_at_occurrence) tuple is unique.
- A `/drift` Livewire SFC: lists open drift alerts sorted by `detected_at DESC`. Each row shows: series name (display_name_override ?: detected_name from Phase 8 D-813), direction-aware copy ("Netflix up €1.50/mo → +€18/yr" for expense, "Salary up €150/mo → +€1800/yr" for income), inline annualized impact, three action buttons (Acknowledge / Snooze / What if I cancel). Acknowledged + snoozed + dismissed alerts live in tabs.
- A `/drift` count badge on the dashboard + top-nav (reusing the Phase 5/6/7/8 View Factory composer pattern).
- `Modules/DriftAlerts/Public/Services/CancellationImpactQuery` — Returns `{annual_savings_minor, monthly_savings_minor, currency}` for a given `recurring_series_id` using `monthly_equivalent_minor × 12` from Phase 8's data. Phase 9 wires its `/drift` view to display this inline as static text on each alert. Phase 10 will reuse the same query as the data source for its richer forecasting view.
- Public Actions: `AcknowledgeDriftAlert` / `SnoozeDriftAlert` / `DismissDriftAlertAsCancelled` (the "I cancelled this series" path — records the user's intent and gives the auditability REC-08 requires). Snooze reuses Phase 8's date-picker component (D-810).
- Global drift threshold setting: `users.drift_alert_threshold_percent` (default 5). Per-series override: new column on `recurring_series.drift_threshold_percent` (nullable; null = use global default). The threshold actually used for each alert is captured in `drift_alerts.threshold_percent_used` so audit + display are honest.
- Phase 8's snooze component (D-810: 1w / 1m / 3m / custom) is REUSED verbatim — no second snooze UI. Snooze on a drift alert sets `drift_alerts.snoozed_until`. After expiry the alert flips back to `open` via a scheduled sweep or a `wire:poll`-style refresh (planner picks).
- Multi-currency: drift detection compares `original_amount_minor` (in `original_currency`), never settled EUR. Phase 8 D-842 mandate carries forward. The alert row stores the original currency. Display: `+$1.50/mo → +$18/yr` (≈ €16.80) — original-currency primary with EUR shadow when distinct, per the Phase 3 D-44/D-47 multi-currency pattern.
- BoundaryArchTest invariants (mirroring Phase 8 D-833): `noFacadeCallsFromDriftAlerts`; `noRecurringSeriesWritesFromDriftAlerts` (DriftAlerts only writes to its own tables); `crossModuleAccessGoesThroughPublic` (DriftAlerts reads Recurring exclusively via `Modules\Recurring\Public\*`); `noSynchronousDriftDetectionInRequestLifecycle` (detector runs only inside jobs).

**What Phase 9 does NOT deliver:**
- Cash-flow forecasting + what-if scenarios (FCT-01..05) — Phase 10. Phase 9 only ships the thin `CancellationImpactQuery` Public surface that Phase 10 will reuse.
- A rich "if I cancel" forecast modal — Phase 9 surfaces inline static text only (`save €X/yr`); the rich projection view is Phase 10.
- Merging the dashboard "Next ICS settlement" tile (Phase 5) with anything in Phase 9 — separate contexts.
- Changes to Phase 8's recurring detector logic, cadence math, or approval flow. Phase 9 only consumes Phase 8 Public surfaces.
- Push notifications, email digests, or any non-localhost surface (PLT-01 localhost-only carry-forward).
- Drift detection on rejected, snoozed-series-level, or transfer-direction series — only `direction IN ('expense', 'income')` AND `state IN ('approved', 'cadence_changed')` participate.
- Acknowledging a drift alert NEVER mutates the underlying transaction or the recurring_series amount. DriftAlerts is analytical-only.

**Architectural anchor:**
Phase 9 layers an alerting concern on top of Phase 8's locked recurring-series substrate. The split — Recurring publishes events with the drift math available as a service; DriftAlerts owns the job, the alert table, the state machine, and the surface — keeps Recurring focused on detection-and-approval lifecycle and DriftAlerts focused on alert-lifecycle. Phase 10 will consume DriftAlerts' `CancellationImpactQuery` Public surface the same way Phase 9 consumes Recurring's Public Query.

</domain>

<decisions>
## Implementation Decisions

### Module Placement + Boundary

- **D-901:** **New `Modules/DriftAlerts/` bounded module owns the alerts surface + state machine + `/drift` view; `Modules/Recurring/` owns the drift math + a new event surface.** Mirrors the Chains/Recurring/Transfers/EmailScan precedent. DriftAlerts has Public/Internal split, ServiceProvider, dedicated tests dir. The drift evaluator is series-aware so its math lives close to Recurring; the alerts table, state machine, and UI live in DriftAlerts because they're a separate concern with their own lifecycle. Folding everything into Recurring was rejected as boundary dilution; pure DriftAlerts-owns-everything was rejected because the math needs intimate access to occurrence rows.
- **D-902:** **Four BoundaryArchTest invariants.** (1) `noFacadeCallsFromDriftAlerts` (DI invariant carry-forward — no `auth()` / `request()` / `config()` / `Auth::` / `DB::` etc.). (2) `noRecurringSeriesWritesFromDriftAlerts` — DriftAlerts only writes to `drift_alerts` + `drift_alert_transitions`; mutations of `recurring_series.snoozed_until` happen exclusively via `Modules\Recurring\Public\Actions\SnoozeRecurringSeries` (which DriftAlerts may call). (3) `crossModuleAccessGoesThroughPublic` — every DriftAlerts import of `Modules\Recurring\*` MUST go through `Modules\Recurring\Public\*`. (4) `noSynchronousDriftDetectionInRequestLifecycle` — drift evaluator code may only run inside queue workers / scheduled jobs.
- **D-903:** **Public surface = Queries + DTOs + Events + Actions; detector + state-machine + Livewire stay Internal.** Public: `DriftAlertQuery` (lists / filters / counts), `CancellationImpactQuery` (the Phase 10 hand-off surface), `DriftAlertDto` / `CancellationImpactDto`, the events (`DriftAlertOpened` / `DriftAlertAcknowledged` / `DriftAlertSnoozed` / `DriftAlertDismissedCancelled`), and the Public Actions (`AcknowledgeDriftAlert` / `SnoozeDriftAlert` / `DismissDriftAlertAsCancelled`). Detector internals + state-machine internals + projection queries stay private.

### Alert Lifecycle + Persistence

- **D-904:** **One drift_alert row per drift event; queue all as open.** Every time the detector finds a drift beyond threshold for an approved series, a new `drift_alerts` row is written with `state='open'`. Multiple open alerts for the same series may coexist on `/drift`. Acknowledging closes only the alert that was acted on; future drifts produce future alerts. Honest history of every drift event; trades off some `/drift` noise for full auditability. Supersede-in-place was rejected; "one row per series with state in_drift" was rejected — both lose the per-event history REC-08's audit requirement implies.
- **D-905:** **`/drift` sorts open alerts by `detected_at DESC` and groups by series visually only.** Open alerts list shows newest first. When multiple open alerts share a `recurring_series_id`, the UI groups them under a single collapsible header showing the series name + a stacked-count chip ("3 drifts open"). Per-row Acknowledge / Snooze still operates on the individual alert. A bulk "Acknowledge all for this series" affordance lives on the group header.
- **D-906:** **Acknowledge has NO special effect on future detection — it only closes the alert.** Acknowledging flips `drift_alerts.state` to `acknowledged` and writes `drift_alerts.actioned_at`. Future detection is unchanged: each NEW occurrence is compared to its immediately-prior occurrence (D-908). If the price is now stable at the acknowledged amount, no further alert fires naturally (delta = 0 vs prior). If it drifts again, a fresh alert fires. Falls out cleanly from the immediately-prior baseline rule.
- **D-907:** **Drift alerts persist forever (no auto-expiry).** Even acknowledged / snoozed / dismissed alerts stay in `drift_alerts` so the audit trail survives. REC-07 mandates persistence until acted on; the project's "History: full history retained forever" constraint extends naturally. Storage cost is negligible (drift events are rare relative to transactions).

### Detection Math + Baseline

- **D-908:** **Baseline = immediately-prior occurrence's amount, in original currency.** For an approved series with a new occurrence appended, the detector computes `delta = latest_occurrence.original_amount_minor − prior_occurrence.original_amount_minor`. If `|delta / prior_amount| × 100 > effective_threshold_percent`, a drift alert fires. Simplest math; matches Phase 8 D-826's "latest amount as headline" framing. Median-of-N baseline and approved-at-locked baseline were both rejected — they introduce extra storage and conflict with the per-event alert lifecycle.
- **D-909:** **Drift is checked in original currency only; FX-only EUR moves never fire a drift alert.** Phase 8 D-842 carry-forward. A USD $11.99 → $11.99 month-over-month produces no alert regardless of how the EUR settled value drifted. The alert UI displays original-currency primary with EUR shadow when distinct (Phase 3 D-44/D-47 pattern).
- **D-910:** **Drift fires on BOTH `approved` AND `cadence_changed` series.** A series whose cadence flipped (Phase 8 D-804) is awaiting user re-approval, but drift on its underlying amount is still actionable signal. The user sees both — a cadence-flip notice in `/recurring/review` AND a drift alert on `/drift`. They can act on each independently. Skipping cadence_changed was rejected as hiding real signal.
- **D-911:** **Pending / rejected / snoozed-at-series-level / irregular series are excluded.** Drift detection only runs against series in `state IN ('approved', 'cadence_changed')`. The detector reads `Modules\Recurring\Public\Services\RecurringSeriesQuery` projections that already filter by state, so this is a query-level invariant rather than a downstream check.

### Trigger Model

- **D-912:** **Dedicated `DetectDriftAlertsJob` owned by `Modules/DriftAlerts/Internal/`; listens to a Recurring-published event.** `Modules/Recurring/` publishes a `RecurringSeriesOccurrenceAppended` (or equivalent — planner picks the exact name; see D-921) Public event after every approved-series sweep refreshes `latest_amount_minor`. `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnOccurrenceAppended` queues `DetectDriftAlertsJob`. The job runs `ShouldBeUniqueUntilProcessing` keyed `(user_id, recurring_series_id)` so concurrent triggers collapse safely. Inline-inside-DetectRecurringSeriesJob was rejected as coupling — Phase 9 owns its own job for the same reason Phase 8 owns its own. A DriftAlerts-side daily scheduler that re-reads all approved series was rejected as wasteful when the event-driven path already covers it.
- **D-913:** **The on-demand `/recurring/re-detect` button (Phase 8 D-805) gets drift evaluation for free.** Because Phase 8's job fires the occurrence-appended event after every sweep, the on-demand path naturally re-evaluates drift via the same listener.
- **D-914:** **Drift detection is analytical-only — never writes to `recurring_series` or `transactions`.** Mutations of `recurring_series.snoozed_until` for the Phase 8 snooze action are unaffected; that's a Recurring concern with its own Public Action. DriftAlerts only writes to `drift_alerts` + `drift_alert_transitions`. Enforced by `noRecurringSeriesWritesFromDriftAlerts` arch test (D-902).

### Threshold + Direction + Display

- **D-915:** **Global default ±5% (column `users.drift_alert_threshold_percent`) + per-series override (nullable `recurring_series.drift_threshold_percent`).** Effective threshold per evaluation = `series.drift_threshold_percent ?? user.drift_alert_threshold_percent ?? 5`. The actual value used is captured on the alert row (`drift_alerts.threshold_percent_used`) so the audit trail is honest if the user later changes the global default. The per-series override editor renders inline on the alert row + on `/recurring/series/{id}` (mirrors Phase 8 D-825 variance-tolerance editor placement).
- **D-916:** **Drift fires on BOTH expense AND income series.** Income drift (salary cut/raise, regular transfer increase) is structurally identical and equally actionable. The alert UI is direction-aware in copy: "Netflix up €1.50/mo → +€18/yr" (expense, red-leaning indicator) vs "Salary up €150/mo → +€1800/yr" (income, green-leaning indicator) vs "Salary down €50/mo → −€600/yr" (income drop, red-leaning indicator). The `direction` column on `drift_alerts` is denormalised from `recurring_series.direction` for query speed.
- **D-917:** **Annualized impact = signed delta × cadence-to-year multiplier.** Formula: `annualized_impact_minor = delta_minor × multiplier` where `multiplier` is `monthly=12 / quarterly=4 / yearly=1 / weekly=52.18`. Stored signed (BIGINT) so the sign is preserved through the audit. Display: signed text with sign always rendered (`+€18/yr` or `−€24/yr`). Total-old-vs-total-new display was rejected (vertically heavier without delivering more information); unsigned framing was rejected because income drift loses meaning. Note: monthly_equivalent_minor on the recurring_series row is updated by Phase 8's sweep and matches this multiplier choice (Phase 8 D-826 uses ×4.33 for weekly; Phase 9 stays consistent at ×4.33 if Phase 8's value is canonical for the EUR shadow display, but the drift formula uses ×52.18/12 ≈ ×4.348 — close enough that planner can pick either; see D-924).
- **D-918:** **Annualized impact display is original-currency primary with EUR shadow when distinct.** `+$18/yr ≈ +€16.80/yr`. Extends the Phase 3 D-44/D-47 and Phase 8 D-840 multi-currency display. The EUR shadow uses the alert's latest occurrence's `fx_rate` (preserved per LED-03).

### What-If-Cancel Hand-Off

- **D-919:** **Phase 9 ships a thin `Modules\DriftAlerts\Public\Services\CancellationImpactQuery` that Phase 10 reuses.** Contract: `forSeries(int $seriesId, User $user): CancellationImpactDto` returning `{annual_savings_minor, monthly_savings_minor, currency}`. Math is straightforward: `monthly_savings = recurring_series.monthly_equivalent_minor`; `annual_savings = monthly_savings × 12`; currency from `recurring_series.latest_currency`. Phase 9 wires the query into its `/drift` row as a static inline display ("Cancel this → save €18/yr"). Phase 10 builds a richer modal/view on top of the same query without API divergence.
- **D-920:** **Dismiss-as-cancelled is a first-class user intent recorded in the audit trail.** When the user clicks "I cancelled this series" on a drift alert, the action: (1) flips the drift alert to `state='dismissed_cancelled'`, (2) writes a transition row with `transition_reason='user_dismissed_cancelled'`, (3) emits `DriftAlertDismissedCancelled` event. Phase 10 forecasting can listen to this event and exclude the series from forward projections. Does NOT mutate `recurring_series.state` — the series may legitimately still drift in if a stray charge appears (e.g., a cancel-and-restore mistake); the user can manually reject the series via the Phase 8 path.

### Claude's Discretion

- **D-921:** Exact name + shape of the Recurring-published event Phase 9 subscribes to. Candidates: `RecurringSeriesOccurrenceAppended` (per-occurrence, fires once per new transaction joined to the series), `RecurringSeriesMetricsRefreshed` (per-series, fires once per sweep after `latest_amount_minor` is recomputed). Planner picks based on Phase 8's existing job lifecycle; the latter is likely cheaper (one event per series per sweep, not per occurrence) but the former is more precise.
- **D-922:** Where the `DriftEvaluator` service lives — `Modules/Recurring/Internal/` (closer to occurrence data) vs `Modules/DriftAlerts/Internal/` (reads through Recurring's Public Query). Planner picks based on which boundary feels least leaky after running the four arch tests against a draft layout.
- **D-923:** Wave structure (suggested: Wave 0 = `Modules/DriftAlerts/` skeleton + boundary tests + synthesised drift fixtures + new Recurring Public event surface; Wave 1 = migrations + drift_alerts schema + state machine + DTOs + Public Actions skeleton; Wave 2 = DriftEvaluator math + DetectDriftAlertsJob + listener on Recurring event + RecurringSeriesQuery integration; Wave 3 = `/drift` Livewire SFC + Acknowledge/Snooze/Dismiss actions + top-nav badge composer + dashboard count badge; Wave 4 = `CancellationImpactQuery` Public + inline "save €X/yr" display + per-series threshold override editor + `/settings` global threshold field + multi-currency rendering + snoozed-alert revival sweep). Planner verifies against goal-backward analysis.
- **D-924:** Weekly-cadence-to-year multiplier — `×52.18` (calendar-accurate) vs `×4.33 × 12 = 51.96` (matches Phase 8 D-826's monthly-equivalent multiplier exactly). Difference is sub-0.5% — planner picks the value that keeps the Phase 8 multiplier and the Phase 9 multiplier consistent so `series.monthly_equivalent_minor × 12` and `delta × weekly_multiplier` agree at the per-fixture level.
- **D-925:** Snoozed-alert revival mechanism — a scheduled sweep that flips `snoozed → open` when `snoozed_until <= now()`, vs a query-time conditional that treats `snoozed AND snoozed_until <= now()` as `open`. Sweep is simpler for the count badge but adds a scheduled task; query-time conditional avoids the task but complicates every list query. Planner picks.
- **D-926:** Exact persistence of "I cancelled this series" downstream — DriftAlerts emits the event, but does anything else listen yet? Likely no in Phase 9 (Phase 10 will). Planner confirms there are no orphan listeners.
- **D-927:** Top-nav slot positioning for "Drift" badge — Phase 8 D-853 already flagged top-nav crowding. The Drift badge may live as a secondary indicator on the existing "Recurring" top-nav slot (count of "drifted approved series") rather than its own slot. UI-SPEC pass decides.
- **D-928:** Whether `/drift` is a sibling route under `/recurring/drift` or a top-level `/drift` route. Planner picks based on what reads most naturally with the existing route map.
- **D-929:** Whether the `drift_alerts.threshold_percent_used` column also captures the SOURCE of the effective threshold (global default vs per-series override) for debug. Likely yes — a small `threshold_source` enum (`'global' / 'series_override'`). Planner picks.
- **D-930:** The grouped-by-series collapsible-header UI for `/drift` (D-905) — exact Flux UI primitive (accordion vs disclosure vs collapsible card). UI-SPEC pass owns this.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-Level
- `.planning/PROJECT.md` — Project constraints. Critical here: DI-only invariant (constructor injection, no facades/helpers); `nwidart/laravel-modules` bounded modules with Public/Internal split; Larastan level 10 strict + Pint + Pest CI gates; calm aesthetic (Linear/Notion); GSD-agnostic code comments invariant; income as a first-class concept (not "negative expense") — applies directly to D-916.
- `.planning/REQUIREMENTS.md` — Phase 9 covers REC-06, REC-07, REC-08. Adjacent (already-met) requirements Phase 9 must respect: REC-01..05 + LED-06 (Phase 8 recurring substrate); LED-01/02/04/05 (transaction types + transfer-pair); MC-01/02 (multi-currency display); FND-03 (per-user data isolation); FND-04 (BIGINT minor units); PLT-01 (localhost-only, no push); CHN-01..07 (chain links for funding-icon display on alerts).
- `.planning/ROADMAP.md` §"Phase 9" — Goal + three success criteria (drift threshold default ±5% with annualized impact; persistent alerts that cannot be silently missed; three actions — acknowledge / snooze / what-if-cancel — each recorded with timestamp for auditability).

### Prior Phase Artefacts (read for continuity)
- `.planning/phases/08-recurring-detection-fixed-payments-view/08-CONTEXT.md` — **REQUIRED READ.** Phase 8 ships the entire substrate Phase 9 reads. Critical anchors: D-810 snooze date-picker component (REUSED verbatim — no second snooze UI); D-815 transitions table audit pattern (mirrored for `drift_alert_transitions`); D-816 unified `recurring_series` table with `direction` column; D-822 income detector trusts upstream LED-05; D-826 monthly-equivalent multiplier (anchor for D-917/D-924 annualized formula consistency); D-829 funding-chain icon uses most-recent occurrence; D-833 four boundary invariants (Phase 9 mirrors all four for DriftAlerts); D-839/D-840 multi-currency clustering on original currency (anchor for D-909 FX exclusion); D-842 FX drift exclusion mandate (LOAD-BEARING for Phase 9 detector); D-853 top-nav crowding concern (anchor for D-927). Public surfaces consumed: `RecurringSeriesQuery`, `FixedPaymentsViewQuery`, `SnoozeRecurringSeries` action, `RecurringSeriesApproved` / `RecurringSeriesCadenceFlipped` events.
- `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-CONTEXT.md` — `chain_links` table + `ChainLinkQuery` Public surface for funding-source icon display. ShouldBeUniqueUntilProcessing job pattern (anchor for D-912). `/chains/review` Livewire SFC precedent (`/drift` mirrors its shape). View Factory composer pattern for top-nav badge.
- `.planning/phases/04-paypal-ingestion-transfer-detection/04-CONTEXT.md` — Reclassify toast / Undo pattern (anchor for `/drift` action toasts).
- `.planning/phases/03-ics-cards-multi-currency-display/03-CONTEXT.md` — D-44/D-47 multi-currency display rules + `/settings` page extension pattern + locale-aware `Money::format()` (anchor for D-915 settings field + D-918 EUR shadow).
- `.planning/phases/07-email-template-matchers-categorization-learning/07-CONTEXT.md` — Background on `merchant_memories` + Categorization Public surface; Phase 9 does NOT read merchant_memories directly (drift is amount-only, not category-aware) but the boundary invariant pattern carries forward.

### Research
- `.planning/research/SUMMARY.md` — Read for industry-consensus drift heuristics if a section exists; Phase 9 stays conservative (±5% default, immediately-prior baseline) so deep research not required.
- `.planning/research/STACK.md` — Horizon + Redis queue stack inherited (Phase 5 amendment); the DetectDriftAlertsJob runs on it.
- `.planning/research/PITFALLS.md` — Any flagged drift / alert-fatigue pitfalls inform UX defaults.

### Existing Source (read before extending)
- `composer.json` — Phase 9 adds NO new dependencies. ApexCharts + Horizon + Pest already locked. Composer audit confirms no `ext-imap` regression (PLT-05 carry-forward).
- `Modules/Recurring/Database/Migrations/2026_05_18_010001_create_recurring_series_table.php` — The series shape Phase 9 reads. Key columns: `direction` enum, `state` enum, `cadence`, `latest_amount_minor`, `latest_currency`, `latest_fx_rate_used`, `monthly_equivalent_minor`, `variance_tolerance_percent`, `snoozed_until`. Phase 9 adds a `drift_threshold_percent` (nullable) column via a new migration.
- `Modules/Recurring/Database/Migrations/2026_05_18_010002_create_recurring_series_occurrences_table.php` — Occurrence shape Phase 9 reads to compute the immediately-prior amount for D-908.
- `Modules/Recurring/Database/Migrations/2026_05_18_010003_create_recurring_series_transitions_table.php` — Audit shape Phase 9's `drift_alert_transitions` mirrors.
- `Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php` — Existing pattern Phase 9 follows for the new `users.drift_alert_threshold_percent` migration.
- `Modules/Recurring/Public/Services/RecurringSeriesQuery.php` — Read API DriftAlerts consumes. Phase 9 may need a new method like `approvedAndCadenceChangedForUser` or `occurrencesForSeries($seriesId, $user, $limit = 2)` for the immediately-prior baseline; if those methods don't already exist, Phase 9 plans them as small Recurring-side additions (planner confirms).
- `Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php` — Read API the dashboard count-badge composer may consume to render "N series have open drift alerts".
- `Modules/Recurring/Public/Actions/SnoozeRecurringSeries.php` — Phase 9 reuses Phase 8's snooze date-picker COMPONENT but writes to `drift_alerts.snoozed_until`, not `recurring_series.snoozed_until`. Two distinct snooze concepts; same UI.
- `Modules/Recurring/Public/Events/RecurringSeriesApproved.php` + `RecurringSeriesCadenceFlipped.php` — Existing Phase 8 events. Phase 9 may also listen to these for boundary cleanup; planner picks.
- `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php` — Phase 8's sweep. Phase 9 does NOT modify it but needs Recurring to publish a new event after this job runs (planner picks the exact insertion point; minimal Recurring-side surgery is the goal).
- `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` — Reference shape for the new `DriftAlertStateMachine`.
- `Modules/Chains/Public/Services/ChainLinkQuery.php` — Funding-source chain icon API (alerts may show a small chain icon next to the series name, mirroring Phase 8 D-828).
- `Modules/Core/Public/Contracts/CurrentUser.php` — DI-only contract every Phase 9 service injects.
- `tests/Contracts/BoundaryArchTest.php` — Phase 9 adds four new invariants (D-902); existing carry-forwards (Phase 6/7/8 invariants) must stay green.
- `tests/Pest.php` — New `Modules\\DriftAlerts\\Tests\\` PSR-4 entry must be added (3-step pattern: composer.json autoload-dev + phpunit.xml testsuite + Pest.php).
- `routes/web.php` — New `GET /drift` (or `GET /recurring/drift` per D-928) + Livewire action endpoints for Acknowledge / Snooze / Dismiss. All gated by LoopbackOnly + Fortify auth.
- `routes/console.php` — Optionally a new scheduled task for the snoozed-alert revival sweep (D-925; planner picks).
- `app/Http/Composers/` — Phase 9 adds the "Drift" top-nav badge composer + dashboard count-badge composer (View Factory pattern from Phase 5/6/7/8).
- `resources/views/dashboard.blade.php` — Embed the new drift count badge alongside the existing tiles.
- `/settings` Livewire SFC — Phase 9 adds the `drift_alert_threshold_percent` field. Follows Phase 3's settings-page extension pattern.

### External Documentation (Phase 9's research targets)
- Livewire 4 docs — https://livewire.laravel.com/docs — `wire:poll` for snoozed-alert revival (D-925 alternative), `$this->dispatch('toast')` for Undo, `#[Url]` for any tab state on `/drift`.
- Flux UI accordion / disclosure / collapsible card — https://fluxui.dev/ — Grouped-by-series collapsible header on `/drift` (D-905 + D-930).
- Pest `arch()` plugin docs — https://pestphp.com/docs/arch-testing — Four new BoundaryArchTest invariants.
- Laravel queue docs — https://laravel.com/docs/queues — `ShouldBeUniqueUntilProcessing` per-user keying for `DetectDriftAlertsJob`.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`recurring_series` table + state machine + transitions audit (Phase 8)** — Substrate Phase 9 reads. Approved/cadence_changed states feed the detector (D-911).
- **`recurring_series_occurrences` table (Phase 8)** — Drives the immediately-prior baseline lookup (D-908).
- **`recurring_series.monthly_equivalent_minor` (Phase 8 D-826)** — Source of truth for the inline "save €X/yr" display via `CancellationImpactQuery` (D-919).
- **`recurring_series.latest_currency` + `latest_fx_rate_used` (Phase 8)** — Drift comparison happens in original currency only (D-909); EUR shadow uses the stored FX rate (D-918).
- **Phase 8 D-810 snooze date-picker component** — REUSED for drift alert snooze (no second snooze UI).
- **Phase 8 D-815 transitions table pattern** — Mirrored for `drift_alert_transitions`.
- **`SnoozeRecurringSeries` Public Action (Phase 8)** — Reference pattern, not directly reused (Phase 9 builds `SnoozeDriftAlert` against its own table; the date-picker COMPONENT is shared, the Action is per-table).
- **`RecurringSeriesQuery` + `FixedPaymentsViewQuery` (Phase 8)** — Read APIs Phase 9 consumes.
- **`RecurringSeriesApproved` + `RecurringSeriesCadenceFlipped` events (Phase 8)** — Public surface Phase 9 may listen to; the new `RecurringSeriesOccurrenceAppended` (D-921) is added in Phase 9 if not already present.
- **`ChainLinkQuery` (Phase 5)** — Funding-source icon on alerts (optional row decoration).
- **Phase 5/6/7/8 View Factory composer pattern** — Top-nav "Drift" badge + dashboard count badge.
- **Phase 4/5 + Phase 8 D-814 Undo toast pattern** — `$this->dispatch('toast', ...)` + Alpine `x-on:toast.window` for Acknowledge / Snooze / Dismiss actions.
- **Phase 3 `/settings` page extension pattern** — New `drift_alert_threshold_percent` field.
- **Phase 3 D-44/D-47 + Phase 8 D-840 multi-currency display** — Original-currency primary + EUR shadow.
- **Horizon + Redis (Phase 5)** — `DetectDriftAlertsJob` runs on the existing infrastructure.
- **`ShouldBeUniqueUntilProcessing` per-user keying (Phase 5 / 6 / 8 idiom)** — Reused for the new job (D-912).
- **Cross-user 404 invariant (Phase 3-07 / 4-04 / 5-04 / 8)** — All `/drift/*` actions enforce `$alert->user_id === $currentUser->id` + `where('user_id', ...)` clauses.
- **DI-only + raw `DatabaseManager` for `whereBetween`/`whereIn`/`orderBy`** — Every Phase 9 service follows the locked invariant.

### New Code Surface (Phase 9 adds)
- **`Modules/DriftAlerts/` bounded module** — composer.json, ServiceProvider (`DriftAlertsServiceProvider`), Public/Internal split, dedicated tests dir.
- **`Modules/DriftAlerts/Public/Dto/DriftAlertDto.php`** + **`CancellationImpactDto.php`**.
- **`Modules/DriftAlerts/Public/Events/DriftAlertOpened.php`** + **`DriftAlertAcknowledged.php`** + **`DriftAlertSnoozed.php`** + **`DriftAlertDismissedCancelled.php`** — Public surface Phase 10 listens to.
- **`Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php`** + **`SnoozeDriftAlert.php`** + **`DismissDriftAlertAsCancelled.php`**.
- **`Modules/DriftAlerts/Public/Services/DriftAlertQuery.php`** — Read API for `/drift` view + count badges.
- **`Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php`** — Phase 10 hand-off surface (D-919).
- **`Modules/DriftAlerts/Internal/DriftEvaluator.php`** (home subject to D-922) — Math service: latest vs prior amount, threshold check, signed delta, annualized impact.
- **`Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php`** — `ShouldBeUniqueUntilProcessing` per `(user_id, recurring_series_id)`.
- **`Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnOccurrenceAppended.php`** — Subscribes to the new Recurring event; dispatches the job.
- **`Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php`** — `open` / `acknowledged` / `snoozed` / `dismissed_cancelled` transitions with `lockForUpdate` + history write.
- **`Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php`** + **`DashboardDriftBadge.php`** (composer contributor).
- **`Modules/DriftAlerts/Database/Migrations/*_create_drift_alerts_table.php`**.
- **`Modules/DriftAlerts/Database/Migrations/*_create_drift_alert_transitions_table.php`**.
- **Recurring-side migration: `*_add_drift_threshold_percent_to_recurring_series.php`** (Phase 9 owns the migration; lives in `Modules/Recurring/Database/Migrations/` since the column is on the recurring_series table — planner decides whether this is a Phase 9 file path that targets Recurring's table, or a Recurring-owned migration that Phase 9's plan calls).
- **Users migration: `*_add_drift_alert_threshold_percent_to_users.php`** (Phase 9 owns).
- **Recurring-side new Public event: `RecurringSeriesOccurrenceAppended.php`** (or equivalent — D-921). Small Recurring-side addition; Phase 9's plan includes the change.
- **`tests/Contracts/BoundaryArchTest.php`** — Four new invariants (D-902).
- **`tests/Contracts/DriftDetectionContractTest.php`** — End-to-end against the Wave 0 fixture corpus (mirrors Phase 8 `RecurringDetectionContractTest`).
- **`Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php`** — Pest dataset over `(prior, latest, threshold) → expected_alert`.
- **`Modules/DriftAlerts/tests/Feature/*.php`** — `/drift` page tests, action tests, composer tests, cross-user 404 tests.
- **`Modules/DriftAlerts/tests/fixtures/`** — Synthesised drift corpus: stable series (no alert), small drift (below threshold, no alert), large drift (alert), income raise (alert with green-leaning copy), income cut (alert with red-leaning copy), FX-only swing (NO alert per D-909), cadence-changed series (alert per D-910), multi-drift series (multiple open alerts per D-904), per-series threshold override (uses series-level threshold per D-915).

### Established Patterns
- **DI-only** — every new service constructor-injects collaborators. `DriftEvaluator` injects `DatabaseManager` + `Clock`; `DetectDriftAlertsJob` injects `DriftEvaluator` + the events dispatcher.
- **Public/ vs Internal/ split from day one** — `Modules/DriftAlerts/Public/` ships DTOs + events + actions + queries; the evaluator + state machine + Livewire stay Internal.
- **Eloquent direct OK, no facades** — `DriftAlert::query()->where('user_id', $user->id)` allowed; `DB::table(...)` forbidden; raw `DatabaseManager` injected for `whereBetween`/`whereIn`.
- **Pest test layout** — unit next to the code (`Modules/DriftAlerts/tests/Unit/`); feature tests for `/drift` under `Modules/DriftAlerts/tests/Feature/`; contract under `tests/Contracts/`.
- **Synthesised fixture-first Wave 0** — Same precedent as Phase 5 D-107 + Phase 6 D-140 + Phase 7 + Phase 8 D-845.
- **GSD-agnostic runtime code** — No D-numbers / REQ-IDs in runtime code or PHPDocs (project-level CLAUDE.md invariant).

### Integration Points
- **`Modules/Recurring/` event-publishing** — Phase 9 requires a new Public event (D-921) emitted from Phase 8's existing sweep. Minimal Recurring-side surgery; planner picks the insertion point.
- **`Modules/Recurring/Public/Services/RecurringSeriesQuery` reads** — Phase 9 reads approved + cadence_changed series, plus the last two occurrences per series for baseline lookup. If the existing query doesn't expose this, Phase 9's plan includes a small Recurring-side addition.
- **`/settings` page** — One new field: `drift_alert_threshold_percent` (default 5). Follows Phase 3's settings-page extension pattern.
- **Dashboard `/`** — New drift count badge alongside the existing "this month at a glance" + "Next ICS settlement" + "Fixed monthly payments" tiles. Calm presentation — count number only, no embedded list.
- **Top-nav** — New "Drift" badge entry OR a secondary count on the existing "Recurring" entry (D-927). UI-SPEC pass decides.
- **Routes** — NEW `GET /drift` (or `/recurring/drift` — D-928) + Livewire action endpoints. All loopback-only + Fortify-authenticated.
- **Composer** — No new dependencies. Phase 9 builds on the locked stack.
- **Phase 10 hand-off** — `Modules\DriftAlerts\Public\Services\CancellationImpactQuery` (D-919) + `DriftAlertDismissedCancelled` event (D-920) are the surfaces Phase 10 consumes.

### Risks Phase 9 Specifically Owns
- **Alert noise from queue-all-as-open (D-904)** — A volatile series (electricity, gym pricing) could produce a fresh drift alert every month. Mitigation: per-series threshold override (D-915) lets the user widen tolerance on volatile rows. The `/drift` grouped-by-series collapsible header (D-905) keeps the visual surface calm even when a series has 3+ open alerts. The Phase 9 fixture corpus must include a volatile-series scenario to keep the UX honest.
- **First-time sweep alert avalanche** — When Phase 9 first ships against a user with years of approved history, the initial backfill of drift alerts could be hundreds of rows. Mitigation: the detector only fires on NEW occurrences appended after Phase 9 ships (anchored to the trigger event D-912), so a clean cutover produces zero retro-alerts. If a backfill-mode is needed (planner decides), it should require explicit opt-in and be bounded.
- **Acknowledge-but-keep-paying UX** — User acknowledges a drift but never cancels; alert closes; next month the price drifts AGAIN (smaller delta below threshold) — no alert fires, but the user is still paying the higher price. Mitigation: `/recurring`'s amount column already shows the latest amount (Phase 8 D-824); drift alerts are a "spotlight on changes" not a "current-cost ledger". Document the limit; it's not a defect.
- **Cadence-changed + drift double-alert (D-910)** — A series whose cadence flips AND drifts simultaneously will surface in `/recurring/review` AND `/drift`. Mitigation: deliberate per D-910 — the user has two separate decisions to make. UI copy on both surfaces hints at the other ("This series also flipped cadence — see /recurring/review").
- **Concurrent job races** — Phase 8's daily sweep + on-demand re-detect can both fire the Recurring event; the `ShouldBeUniqueUntilProcessing` key on `DetectDriftAlertsJob` collapses concurrent triggers per `(user_id, recurring_series_id)`. Also: state-machine transitions on `drift_alerts` use `lockForUpdate`.
- **Snoozed-alert revival (D-925)** — A snoozed alert needs to surface again when `snoozed_until <= now()`. The choice (scheduled sweep vs query-time conditional) has a small ops-cost trade-off; planner picks.
- **Threshold edge case** — A series with `prior_amount = 0` or `null` (first occurrence after approval) divides-by-zero. Mitigation: detector skips evaluation when `prior_amount IS NULL OR prior_amount = 0`. Document and test.
- **Currency switch mid-series (Phase 8 D-839 footnote)** — Phase 8 produces two separate series when currency flips. Phase 9 inherits this — drift detection naturally treats them as independent series. Not a defect.
- **Income drift "alert fatigue" on variable income** — Freelance income that fluctuates would generate alerts constantly. Mitigation: the per-series threshold override (D-915) covers this; users with freelance income set their series threshold to ±50% or higher. Phase 8 D-820's `recurring_income_min_amount_minor` also filters small irregular inflows out of the series substrate so they never feed the detector.

</code_context>

<specifics>
## Specific Ideas

- **The Recurring → DriftAlerts boundary is the load-bearing architectural decision.** DriftAlerts owns its own job + state machine + table — that's what lets Phase 10 consume DriftAlerts the same way Phase 9 consumes Recurring (via Public). The drift math sitting close to the occurrences data is a pragmatic call: cheap to read, expensive to refactor later if Recurring's internals shift.
- **Queue-all-as-open (D-904) is a deliberate noise-vs-history trade-off the user owns.** The honest audit trail of every drift event is worth the slightly-busier `/drift` view. The grouped-by-series UI (D-905) mitigates the visual noise without losing the per-event history.
- **Acknowledging a drift alert is intentionally toothless beyond closing the alert.** No baseline reset, no special future suppression. The immediately-prior baseline rule (D-908) makes the math symmetric: if the price stays stable after acknowledging, no future alert fires (delta=0). If it drifts again, a fresh alert fires. Simple to reason about; no hidden state.
- **The thin `CancellationImpactQuery` Public surface (D-919) is the Phase 10 hand-off contract.** Phase 9 ships the contract + a static inline display. Phase 10 ships the rich forecasting view. Same query, two consumers. This is the cleanest version of "ship the value now, ship the polish later" the project's vertical-MVP slicing asks for.
- **Drift checked in original currency only (D-909) is non-negotiable** — Phase 8 D-842 made this the project-wide rule. An FX-only EUR swing should never fire a "Spotify cost changed" alert.
- **Direction-aware copy on alerts (D-916) is small but important.** "Salary down €50/mo → −€600/yr" reads differently from "Netflix up €1.50/mo → +€18/yr". Same data, different emotional weight; the UI honors that.
- **Per-series threshold override (D-915) is a graceful escape valve.** Volatile series (electricity, freelance income) get a wider tolerance without polluting the global default. Mirrors Phase 8 D-825's variance-tolerance editor pattern.

</specifics>

<deferred>
## Deferred Ideas

- **Rich what-if-cancel modal / projection** — Phase 10 owns this. Phase 9 ships the `CancellationImpactQuery` Public surface + inline static display only.
- **"Acknowledge all drifts for this series" with optional baseline reset** — The collapsible header (D-905) ships bulk acknowledge; baseline reset is intentionally NOT shipped (D-906). Defer to v2 if users explicitly request it.
- **Drift digest email / push notification** — Out of scope (PLT-01 localhost-only).
- **Adaptive threshold (auto-loosen on volatile series)** — Mirrors Phase 8's deferred "adaptive variance tolerance" idea. v2.
- **Backfill mode** — Phase 9 only fires alerts on new occurrences after ship-date (per the trigger event D-912). Retroactive backfill of historical drifts requires explicit opt-in and is deferred unless user demand surfaces.
- **Cross-series drift correlation** — "Your Netflix and Disney+ both went up in the same month" pattern detection. Power-user feature; v2.
- **Per-currency aggregate "your subscription spend changed by €X" dashboard line** — Cumulative version of annualized impact across all open alerts. Could land in Phase 9 Wave 4 if it fits; otherwise v2.
- **Drift-alert tagging / custom user labels** — Mirrors Phase 8's deferred series-tagging idea. v2.
- **"Snooze this series's drift detection for N months"** — A coarser snooze that suppresses all future drift alerts for a series for a window, distinct from snoozing a single alert. Could conflict with Phase 8's series-level snooze; v2.
- **Email-based "I cancelled this" follow-through** — Auto-detect cancellation receipts via the Phase 7 email matcher and auto-dismiss the relevant drift alert. Cross-phase nicety; v2.
- **Threshold-source captured on the alert row (D-929 partial)** — Planner discretion whether to denormalise the `'global' / 'series_override'` source onto the alert row.

</deferred>

---

*Phase: 9-Subscription Drift Detection + Alerts*
*Context gathered: 2026-05-17*
