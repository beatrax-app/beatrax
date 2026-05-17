# Phase 9: Subscription Drift Detection + Alerts - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-17
**Phase:** 9-Subscription Drift Detection + Alerts
**Areas discussed:** Module placement + alert lifecycle, Baseline definition, What-if-cancel path, Threshold + direction + impact formula, Acknowledge semantics, Trigger model, Cadence-flipped interaction

---

## Area Selection

| Option | Description | Selected |
|--------|-------------|----------|
| Module placement + alert lifecycle | New `Modules/DriftAlerts/` vs fold into Recurring; alert-per-event vs row-per-series with supersede | ✓ |
| Baseline definition | Immediately-prior occurrence vs median-of-N vs approved-at vs last-acknowledged | ✓ |
| What-if-cancel path | Static inline only vs minimal modal vs thin Public + Phase 10 owns UI | ✓ |
| Threshold + direction + impact formula | Per-series / global / both, expense / both, signed delta math | ✓ |

**User's choice:** All four areas selected.

---

## Module placement

| Option | Description | Selected |
|--------|-------------|----------|
| New `Modules/DriftAlerts/` | Dedicated bounded module mirrors Chains/Recurring/Transfers; cleanest separation; sets up Phase 10 to consume DriftAlerts Public the same way | |
| Fold into `Modules/Recurring/` | Drift logic inside the existing module; less boilerplate; tighter coupling; risks growing Recurring beyond its name | |
| Hybrid: Recurring owns detection; DriftAlerts owns surface + state machine | Drift evaluator close to occurrence data; alerts table, state machine, /drift view live in DriftAlerts | ✓ |

**User's choice:** Hybrid — Recurring owns detection-side, DriftAlerts owns surface + state machine.
**Notes:** Drives D-901 (module split) and D-922 (exact `DriftEvaluator` home subject to planner discretion).

---

## Alert lifecycle

| Option | Description | Selected |
|--------|-------------|----------|
| One row per series; supersede in place | Calm UX; one open alert per series; no per-event history | |
| One row per drift event; supersede the prior open one | Per-event audit; only latest visible | |
| One row per drift event; queue all as open | Every drift writes a new open row; multiple open alerts per series may coexist; honest history; more noise | ✓ |

**User's choice:** Queue-all-as-open.
**Notes:** Drives D-904 (alert-per-event persistence) and D-905 (grouped-by-series collapsible UI to absorb visual noise).

---

## Baseline definition

| Option | Description | Selected |
|--------|-------------|----------|
| Immediately prior occurrence | Compare latest_amount to previous occurrence's amount; simplest math; symmetric with Phase 8 D-826 "latest amount" framing | ✓ |
| Last acknowledged amount (rolling) | Approved-at baseline; updates on each acknowledge; quieter alerts | |
| Median of last 3 occurrences | Robust to one-off blips; requires ≥3 occurrences before drift can fire | |
| Approved-at amount (locked) | Baseline = original approval amount; never changes; strict | |

**User's choice:** Immediately prior occurrence.
**Notes:** Drives D-908. Combined with queue-all-as-open this means month-over-month deltas drive every alert; acknowledging is naturally toothless beyond closing the alert (D-906).

---

## What-if-cancel path

| Option | Description | Selected |
|--------|-------------|----------|
| Static inline impact only | "Save €X/yr" as static text on each alert; minimum surface; clean Phase 10 hand-off | |
| Minimal what-if-cancel modal in Phase 9 | Click opens modal; sums monthly_equivalent across remaining series; records the click for auditability | |
| Thin Public CancellationImpact surface; Phase 10 owns UI | DriftAlerts publishes CancellationImpactQuery contract; Phase 9 wires it to inline display; Phase 10 reuses for richer view | ✓ |
| Stub the action; mark for Phase 10 | Disabled button with tooltip | |

**User's choice:** Thin Public surface; Phase 10 owns rich UI.
**Notes:** Drives D-919 (`Modules\DriftAlerts\Public\Services\CancellationImpactQuery`) and D-920 (`DriftAlertDismissedCancelled` event for Phase 10).

---

## Threshold configurability

| Option | Description | Selected |
|--------|-------------|----------|
| Per-series override only (global default constant ±5%) | Hard-coded global default; per-series override column on recurring_series; mirrors Phase 8 D-825 variance_tolerance_percent | |
| Global setting only | One number in /settings; applies to every series | |
| Global default + per-series override | Global ±5% setting in /settings; per-series override on the series row; most flexible | ✓ |

**User's choice:** Global default + per-series override.
**Notes:** Drives D-915. New `users.drift_alert_threshold_percent` (default 5) + new nullable `recurring_series.drift_threshold_percent`. Captured per-alert in `drift_alerts.threshold_percent_used` for honest audit.

---

## Direction scope

| Option | Description | Selected |
|--------|-------------|----------|
| Both — expense AND income drift fire alerts | Income drift is structurally identical and equally actionable; direction-aware copy | ✓ |
| Expense-only | Matches "subscription drift" framing; cleaner UX; punts income drift to v2 | |
| Expense by default; setting to enable income alerts | Compromise; adds a setting | |

**User's choice:** Both.
**Notes:** Drives D-916. Alert UI is direction-aware: "Netflix up €1.50/mo → +€18/yr" (expense) vs "Salary up €150/mo → +€1800/yr" (income raise) vs "Salary down €50/mo → −€600/yr" (income cut).

---

## Annualized impact formula

| Option | Description | Selected |
|--------|-------------|----------|
| Signed delta × cadence-to-year multiplier | annualized_impact = (latest − baseline) × multiplier; signed display; matches Phase 8 D-826 | ✓ |
| Total-new-annual vs total-old-annual | Show both totals plus delta; vertically heavier | |
| Delta only, no sign (always positive framing) | Loses meaning for income drops | |

**User's choice:** Signed delta × cadence-to-year multiplier.
**Notes:** Drives D-917 + D-924 (weekly-multiplier consistency with Phase 8). Display: signed text with sign always rendered (`+€18/yr` or `−€24/yr`). Original-currency primary + EUR shadow per D-918.

---

## Acknowledge semantics

| Option | Description | Selected |
|--------|-------------|----------|
| No special effect — just closes the alert | Future detection unchanged; baseline=immediately-prior naturally handles stability post-acknowledge | ✓ |
| Suppress alerts on this series for one occurrence | Cooldown of 1 period; adds complexity | |
| Store acknowledged_amount and compare future drifts vs that | Switches baseline mode after first acknowledge; conflicts with baseline=immediately-prior choice | |

**User's choice:** No special effect — just closes.
**Notes:** Drives D-906. Combined with the immediately-prior baseline (D-908) this means if the price stabilises after the user acknowledges, no future alert fires (delta=0 vs prior). If it drifts again, a fresh alert fires.

---

## Trigger model

| Option | Description | Selected |
|--------|-------------|----------|
| Inline inside Phase 8's existing DetectRecurringSeriesJob | Cheapest path; Phase 8 already refreshes latest_amount on every sweep; emit event from inside Phase 8's job | |
| Dedicated DetectDriftAlertsJob listening to events | DriftAlerts subscribes to a Recurring event; its own job evaluates drift; cleaner module boundary | ✓ |
| DriftAlerts subscribes to RecurringSeriesApproved + dispatches its own daily scheduled job | Decoupled; doubles the daily sweep work | |

**User's choice:** Dedicated DetectDriftAlertsJob listening to events.
**Notes:** Drives D-912 + D-921. Requires a small Recurring-side addition: a new public event (`RecurringSeriesOccurrenceAppended` / `RecurringSeriesMetricsRefreshed`) emitted after Phase 8's sweep refreshes `latest_amount_minor`. Planner picks the exact event name/shape.

---

## Cadence-flipped interaction

| Option | Description | Selected |
|--------|-------------|----------|
| Skip cadence_changed series | Drift only runs against state=approved; cadence flips have their own re-approval flow first | |
| Fire both — cadence-changed AND drift alerts can coexist | More information; user sees both alerts and acts on each independently | ✓ |

**User's choice:** Fire both.
**Notes:** Drives D-910 + D-911. A series in `cadence_changed` state still has actionable amount drift; UI copy on both surfaces should hint at the other.

---

## Claude's Discretion

- D-921: Exact event name + shape (`RecurringSeriesOccurrenceAppended` vs `RecurringSeriesMetricsRefreshed`); planner picks based on Phase 8 internals
- D-922: Exact home for `DriftEvaluator` service (Recurring/Internal vs DriftAlerts/Internal); planner picks based on arch-test cleanliness
- D-923: Wave structure (suggested 0–4); planner verifies via goal-backward analysis
- D-924: Weekly-cadence multiplier (×52.18 calendar-accurate vs ×4.33 × 12 matching Phase 8 D-826); planner picks for consistency
- D-925: Snoozed-alert revival mechanism (scheduled sweep vs query-time conditional); planner picks
- D-926: Confirm no orphan listeners on `DriftAlertDismissedCancelled` in Phase 9
- D-927: Top-nav slot positioning ("Drift" vs secondary indicator on "Recurring"); UI-SPEC pass owns this
- D-928: `/drift` vs `/recurring/drift` route placement; planner picks
- D-929: Whether `drift_alerts.threshold_percent_used` also captures source (`global` / `series_override`); planner picks
- D-930: Exact Flux UI primitive for grouped-by-series collapsible header; UI-SPEC pass owns this

## Deferred Ideas

- Rich what-if-cancel modal — Phase 10 owns
- "Acknowledge all drifts for this series with baseline reset" — bulk acknowledge ships; baseline reset deferred to v2
- Drift digest email / push notification — out of scope per PLT-01
- Adaptive threshold (auto-loosen on volatile series) — v2
- Backfill mode for historical drift alerts — explicit opt-in, deferred
- Cross-series drift correlation ("Netflix AND Disney+ went up the same month") — v2
- Per-currency aggregate "subscription spend changed by €X" dashboard line — could fit Wave 4 if planner agrees, otherwise v2
- Drift-alert tagging / custom labels — v2
- "Snooze this series's drift detection for N months" (series-level mute) — v2; could conflict with Phase 8 snooze
- Email-based "I cancelled this" auto-dismiss via Phase 7 matchers — v2
