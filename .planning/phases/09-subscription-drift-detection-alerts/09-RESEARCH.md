# Phase 9: Subscription Drift Detection + Alerts - Research

**Researched:** 2026-05-17
**Domain:** Subscription drift detection over Phase 8 recurring series; new bounded `Modules/DriftAlerts/` module; `/drift` Livewire SFC; persistent alert lifecycle with acknowledge / snooze / dismiss-as-cancelled audit trail; thin `CancellationImpactQuery` Public surface for Phase 10
**Confidence:** HIGH

## Summary

Phase 9 layers a new analytical module — `Modules/DriftAlerts/` — on top of the locked Phase 8 recurring-series substrate. Every artefact the planner needs is already verified in the codebase: Phase 8 ships `recurring_series` + `recurring_series_occurrences` + `recurring_series_transitions` with `latest_amount_minor`, `latest_currency`, `monthly_equivalent_minor` (denominated in original currency, NOT EUR — see Pitfall 1), the per-user `DetectRecurringSeriesJob` (`ShouldBeUniqueUntilProcessing` keyed on `userId`), and a `RecurringSeriesStateMachine` sole-mutator pattern that Phase 9's `DriftAlertStateMachine` mirrors verbatim. The four BoundaryArchTest invariants Phase 8 introduced (`noFacadeCallsFromRecurring`, `noTransactionWritesFromRecurring`, `noOtherRecurringSeriesStateMutator`, `noSynchronousDetectionInRequestLifecycle`) are the exact template Phase 9's four new invariants follow.

**Primary recommendation:** Mirror Phase 8's architecture point-for-point. New `Modules/DriftAlerts/` bounded module with Public/Internal split. New Recurring-side Public event `RecurringSeriesMetricsRefreshed` (per-series-per-sweep — cheaper than per-occurrence; the cluster refresh already iterates series, not occurrences). `DriftEvaluator` lives in `Modules/DriftAlerts/Internal/` and reads via `Modules\Recurring\Public\Services\RecurringSeriesQuery::occurrencesForSeries` (already exists). Weekly multiplier follows Phase 8's `52/12` literal exactly (verified in `ExpenseSeriesDetector::monthlyEquivalent`) so `series.monthly_equivalent_minor × 12` and `delta × weekly_multiplier` agree at the fixture level. `/drift` lives at top-level (`/drift`, not `/recurring/drift`) mirroring Phase 5's `/chains/review` pattern. Top-nav adds a secondary count chip on the existing "Recurring" slot (D-927 verified by reading `top-nav.blade.php`). Flux UI has no accordion/disclosure primitive — the grouped-by-series collapsible header uses `<flux:card>` + Alpine `x-data="{ open }"` (verified by listing the Flux stubs directory).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Drift math (compare latest vs prior occurrence in original currency) | API / Backend (`Modules/DriftAlerts/Internal/DriftEvaluator`) | — | Pure server-side analytical; reads occurrences via Recurring Public Query, writes only to `drift_alerts` |
| Drift alert persistence + state machine (`open` / `acknowledged` / `snoozed` / `dismissed_cancelled`) | API / Backend (`Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine`) | Database / Storage (sole-mutator triggers on `drift_alerts.state`) | Mirrors `RecurringSeriesStateMachine` precedent — DB triggers + arch test enforce the sole-mutator invariant |
| Event-driven trigger (Recurring publishes; DriftAlerts subscribes) | API / Backend (Recurring publishes `RecurringSeriesMetricsRefreshed`; DriftAlerts `EvaluateDriftOnMetricsRefreshed` listener dispatches the job) | — | Cross-module coordination through Public events; no synchronous detection in request lifecycle |
| Queued drift detection per `(user_id, recurring_series_id)` | API / Backend (`DetectDriftAlertsJob` on Horizon + Redis, `ShouldBeUniqueUntilProcessing`) | — | Concurrent triggers (scheduled sweep + on-demand re-detect + multiple series in one sweep) collapse safely |
| `/drift` Livewire SFC + dashboard count badge | Frontend Server (SSR, Livewire 4) | Browser / Client (Alpine `x-data` for collapsible group + popover snooze date-picker) | Server-rendered list + counts; Alpine only for the open/closed UI state of grouped headers + snooze popover |
| Top-nav "Drift" indicator (secondary chip on existing "Recurring" slot) | Frontend Server (View Factory composer injects `recurringPendingCount` + new `driftOpenCount`) | — | Reuses `core::livewire.top-nav` composer pattern (`Modules\Chains\Providers\ChainsServiceProvider`, `Modules\Recurring\Providers\RecurringServiceProvider`) |
| Per-series + global threshold settings | API / Backend (Recurring-side `recurring_series.drift_threshold_percent` column; Core-side `users.drift_alert_threshold_percent` column on `users`) | Frontend Server (`/settings` Livewire SFC extension) | Two columns; effective value captured on each alert row via `threshold_percent_used` for honest audit |
| Snoozed-alert revival | API / Backend (scheduled sweep, mirrors `DetectRecurringSeriesJob::expireSnoozes`) | — | Phase 8 already runs `expireSnoozes()` at the top of every recurring sweep; Phase 9 follows the same shape — a dedicated `RevivedExpiredDriftSnoozesJob` runs at the top of `DetectDriftAlertsJob::handle()` or on the daily schedule, NOT a query-time conditional (see D-925 resolution below) |
| `CancellationImpactQuery` Public read API (Phase 10 hand-off) | API / Backend (`Modules/DriftAlerts/Public/Services/CancellationImpactQuery`) | — | Stable contract Phase 10 forecasting consumes; identical posture to `FixedPaymentsViewQuery` Phase 8→Phase 9 hand-off |

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Module Placement + Boundary**
- **D-901:** New `Modules/DriftAlerts/` bounded module owns the alerts surface + state machine + `/drift` view; `Modules/Recurring/` owns the drift math + a new event surface. Mirrors the Chains/Recurring/Transfers/EmailScan precedent. DriftAlerts has Public/Internal split, ServiceProvider, dedicated tests dir. The drift evaluator is series-aware so its math lives close to Recurring; the alerts table, state machine, and UI live in DriftAlerts because they're a separate concern with their own lifecycle. Folding everything into Recurring was rejected as boundary dilution; pure DriftAlerts-owns-everything was rejected because the math needs intimate access to occurrence rows.
- **D-902:** Four BoundaryArchTest invariants. (1) `noFacadeCallsFromDriftAlerts` (DI invariant carry-forward — no `auth()` / `request()` / `config()` / `Auth::` / `DB::` etc.). (2) `noRecurringSeriesWritesFromDriftAlerts` — DriftAlerts only writes to `drift_alerts` + `drift_alert_transitions`; mutations of `recurring_series.snoozed_until` happen exclusively via `Modules\Recurring\Public\Actions\SnoozeRecurringSeries` (which DriftAlerts may call). (3) `crossModuleAccessGoesThroughPublic` — every DriftAlerts import of `Modules\Recurring\*` MUST go through `Modules\Recurring\Public\*`. (4) `noSynchronousDriftDetectionInRequestLifecycle` — drift evaluator code may only run inside queue workers / scheduled jobs.
- **D-903:** Public surface = Queries + DTOs + Events + Actions; detector + state-machine + Livewire stay Internal. Public: `DriftAlertQuery`, `CancellationImpactQuery`, `DriftAlertDto` / `CancellationImpactDto`, the events (`DriftAlertOpened` / `DriftAlertAcknowledged` / `DriftAlertSnoozed` / `DriftAlertDismissedCancelled`), and the Public Actions (`AcknowledgeDriftAlert` / `SnoozeDriftAlert` / `DismissDriftAlertAsCancelled`). Detector internals + state-machine internals + projection queries stay private.

**Alert Lifecycle + Persistence**
- **D-904:** One drift_alert row per drift event; queue all as `open`. Multiple open alerts for the same series may coexist on `/drift`.
- **D-905:** `/drift` sorts open alerts by `detected_at DESC` and groups by series visually only. Per-row Acknowledge / Snooze; bulk "Acknowledge all for this series" on the group header.
- **D-906:** Acknowledge has NO special effect on future detection — it only closes the alert. Each NEW occurrence is compared to its immediately-prior occurrence (D-908).
- **D-907:** Drift alerts persist forever (no auto-expiry). REC-07 mandate + project history-retention constraint.

**Detection Math + Baseline**
- **D-908:** Baseline = immediately-prior occurrence's amount, in original currency. `delta = latest.original_amount_minor − prior.original_amount_minor`. Drift fires when `|delta / prior_amount| × 100 > effective_threshold_percent`.
- **D-909:** Drift is checked in original currency only; FX-only EUR moves never fire a drift alert. Phase 8 D-842 carry-forward.
- **D-910:** Drift fires on BOTH `approved` AND `cadence_changed` series.
- **D-911:** Pending / rejected / snoozed-at-series-level / irregular series are excluded.

**Trigger Model**
- **D-912:** Dedicated `DetectDriftAlertsJob` owned by `Modules/DriftAlerts/Internal/`; listens to a Recurring-published event; `ShouldBeUniqueUntilProcessing` keyed `(user_id, recurring_series_id)`.
- **D-913:** The on-demand `/recurring/re-detect` button gets drift evaluation for free via the same Recurring event.
- **D-914:** Drift detection is analytical-only — never writes to `recurring_series` or `transactions`. Enforced by `noRecurringSeriesWritesFromDriftAlerts` arch test.

**Threshold + Direction + Display**
- **D-915:** Global default ±5% (column `users.drift_alert_threshold_percent`) + per-series override (nullable `recurring_series.drift_threshold_percent`). Effective threshold per evaluation = `series.drift_threshold_percent ?? user.drift_alert_threshold_percent ?? 5`. Captured on each alert row.
- **D-916:** Drift fires on BOTH expense AND income series. Direction-aware UI copy. `direction` column on `drift_alerts` denormalised from `recurring_series.direction`.
- **D-917:** Annualized impact = signed delta × cadence-to-year multiplier. `monthly=12 / quarterly=4 / yearly=1 / weekly=52.18`. Stored signed (BIGINT).
- **D-918:** Annualized impact display is original-currency primary with EUR shadow when distinct. Uses the alert's latest occurrence `fx_rate` per LED-03.

**What-If-Cancel Hand-Off**
- **D-919:** Phase 9 ships a thin `Modules\DriftAlerts\Public\Services\CancellationImpactQuery` that Phase 10 reuses. `forSeries(int $seriesId, User $user): CancellationImpactDto` returns `{annual_savings_minor, monthly_savings_minor, currency}`.
- **D-920:** Dismiss-as-cancelled is a first-class user intent recorded in the audit trail; emits `DriftAlertDismissedCancelled` event. Does NOT mutate `recurring_series.state`.

### Claude's Discretion

- **D-921:** Exact name + shape of the Recurring-published event Phase 9 subscribes to (`RecurringSeriesOccurrenceAppended` per-occurrence vs `RecurringSeriesMetricsRefreshed` per-series-per-sweep). **Research resolution below.**
- **D-922:** Where the `DriftEvaluator` service lives — `Modules/Recurring/Internal/` vs `Modules/DriftAlerts/Internal/`. **Research resolution below.**
- **D-923:** Wave structure verification (5 waves suggested).
- **D-924:** Weekly-cadence-to-year multiplier (`×52.18` calendar-accurate vs Phase 8's effective `×52/12`-derived value).
- **D-925:** Snoozed-alert revival mechanism — scheduled sweep vs query-time conditional.
- **D-926:** Whether anything else listens to `DriftAlertDismissedCancelled` in Phase 9 (likely no — Phase 10 will).
- **D-927:** Top-nav slot positioning for "Drift" badge.
- **D-928:** `/drift` route placement — `/recurring/drift` vs top-level `/drift`.
- **D-929:** Whether `drift_alerts.threshold_percent_used` also captures `threshold_source` (`'global' | 'series_override'`) enum.
- **D-930:** Flux UI primitive for grouped-by-series collapsible header.

### Deferred Ideas (OUT OF SCOPE)

- Rich what-if-cancel modal / projection — Phase 10
- "Acknowledge all drifts for this series" with optional baseline reset — bulk acknowledge ships; baseline reset deferred
- Drift digest email / push notification — out of scope (PLT-01 localhost-only)
- Adaptive threshold (auto-loosen on volatile series) — v2
- Backfill mode — Phase 9 only fires on new occurrences after ship-date; retroactive opt-in deferred
- Cross-series drift correlation — v2 power-user
- Per-currency aggregate "your subscription spend changed by €X" dashboard line — could fit Wave 4; otherwise v2
- Drift-alert tagging / custom user labels — v2
- "Snooze this series's drift detection for N months" coarser snooze — v2
- Email-based "I cancelled this" follow-through — v2

## Project Constraints (from CLAUDE.md)

The following directives from `./CLAUDE.md` are LOCKED and constrain every Phase 9 plan. They have the same authority as CONTEXT.md decisions.

| Directive | Implication for Phase 9 |
|-----------|-------------------------|
| **Tech stack: PHP 8.5 + Laravel 13 (March 2026)** | All new code targets these versions; `composer.json` already pinned `"php": "^8.5"` + `"laravel/framework": "^13.0"` |
| **Modular architecture via `nwidart/laravel-modules`; cross-module access goes through public service classes or events; no module reaches into another's models or internals** | DriftAlerts reads Recurring exclusively via `Modules\Recurring\Public\*`. Enforced by arch test D-902(3). |
| **Larastan level 10 strict + Pint + Pest CI gates** | Every new file passes all three. No PHPDoc-only types — every property + parameter + return is hard-typed. |
| **DI-only — no helpers, no facade calls; Eloquent models direct OK** | Every Phase 9 service constructor-injects `DatabaseManager`, `Clock`, `Dispatcher`, `RecurringSeriesQuery`, etc. No `auth()` / `request()` / `Auth::` / `DB::`. Single carve-out: the new `DetectDriftAlertsJob::uniqueVia()` MUST call `Cache::driver('redis')` — Laravel resolves the lock store at queue-push time before constructor DI completes. The carve-out FQN must be added to `tests/Contracts/BoundaryArchTest.php`'s `noFacadeCallsFromDriftAlerts` ignore list, mirroring the Phase 8 `DetectRecurringSeriesJob` precedent. |
| **Local-only hosting (localhost)** | `/drift` routes sit behind `web` + `auth` middleware + the existing `LoopbackOnly` middleware. PLT-01 carry-forward. |
| **Idempotency: all ingestion paths safe to re-run** | `DetectDriftAlertsJob` re-runs against the same `(user_id, recurring_series_id, latest_observation_id)` MUST be a no-op. The job's idempotency seam is enforced via a UNIQUE composite on `drift_alerts(recurring_series_id, latest_occurrence_id)` OR via a defensive `WHERE NOT EXISTS` check before insert — planner picks. |
| **History: full history retained forever** | D-907 carry-forward — even acknowledged / dismissed alerts stay in `drift_alerts`. |
| **Multi-user readiness — every domain table has nullable `user_id`** | FND-03 carry-forward: `drift_alerts.user_id` + `drift_alert_transitions.user_id` are nullable foreign-keys with `BelongsToUser` trait usage on the models. |
| **Currency: multi-currency tracking required from v1** | Drift compares `original_amount_minor` in `original_currency` only. EUR-only column writes are forbidden. |
| **Secrets: IMAP credentials in local config file** | Not applicable to Phase 9. |
| **Codebase stays agnostic from GSD** | NO `.planning/`, `PLAN.md`, `RESEARCH.md`, D-numbers (`D-921`), or REQ-IDs (`REC-06`) in runtime code or PHPDocs. The runtime code describes WHAT it does in plain technical language; the planning artefacts live in `.planning/` only. |
| **Docs describe current state, never history** | No "I changed this because X" PHPDoc comments. No "previously did Y, now does Z" framing. |
| **Fix every severity, not just blockers** | BLOCKER + WARNING + INFO addressed in the same plan; quality above speed. |
| **ICS Cards consumer portal is PDF-only** | Not applicable to Phase 9. |
| **Email integration via provider APIs only (no `ext-imap`)** | Not applicable; arch test `noExtImapDeclared` still must stay green after Phase 9. |

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| REC-06 | System detects subscription drift — flags any recurring series whose latest charge differs from the prior baseline by more than a configurable threshold (default ±5%), and computes the annualized impact | `DriftEvaluator` math (D-908 immediately-prior baseline + D-915 effective-threshold lookup + D-917 cadence-to-year multiplier × signed delta). Phase 8's `recurring_series_occurrences` table is the substrate (`observed_amount_minor` per occurrence, `observed_currency`); the `(recurring_series_id, observed_at)` index makes the "latest two occurrences" lookup an index-walk. |
| REC-07 | Drifted series surface in a dedicated "Drift alerts" view (and as a count badge on the home dashboard); the alert persists until the user takes action so it can't be silently missed | `/drift` Livewire SFC + dashboard count badge + top-nav indicator. Persistence enforced by D-904 (one row per drift event with `state='open'`) + D-907 (no auto-expiry). The drift alerts surface mirrors `/chains/review` and `/recurring/review` precedents. |
| REC-08 | User can act on each drift alert via one of three responses: acknowledge / snooze / what-if-cancel; each acknowledged or dismissed drift records the user's decision + timestamp so the history is auditable | Three Public Actions (`AcknowledgeDriftAlert` / `SnoozeDriftAlert` / `DismissDriftAlertAsCancelled`) + `drift_alert_transitions` audit table mirroring Phase 8 D-815. State machine writes one transition row per state change (`from_state`, `to_state`, `transition_reason`, `actor`, `transitioned_at`, `notes`). |

## Standard Stack

### Core (already locked, no new dependencies)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^13.0 | App framework | Pinned in `composer.json` [VERIFIED: composer.json line 14] |
| `php` | ^8.5 | Language | Pinned in `composer.json` [VERIFIED: composer.json line 8] |
| `livewire/livewire` | ^4.0 | Server-rendered reactive UI for `/drift` | Locked since Phase 1; Livewire 4 ships SFC + `wire:poll` + `#[Url]` + `$this->dispatch('toast', ...)` patterns Phase 9 inherits verbatim [VERIFIED: composer.json line 19] |
| `livewire/flux` | ^2.0 | UI primitives (`flux:card`, `flux:badge`, `flux:callout`) for `/drift` | Locked since Phase 6; Phase 9 uses `flux:card` + `flux:badge` for alert rows and the count badge. Flux stubs verified: `callout`, `card`, `table` exist; `accordion` / `disclosure` / `collapsible` do NOT [VERIFIED: vendor/livewire/flux/stubs/resources/views/flux/]. The grouped-by-series collapsible header (D-930) uses `<flux:card>` + Alpine `x-data="{ open: false }"`. |
| `laravel/horizon` | ^5.46 | Queue supervisor for `DetectDriftAlertsJob` | Locked since Phase 5; `ShouldBeUniqueUntilProcessing` jobs already run on this infrastructure [VERIFIED: composer.json line 15] |
| `predis/predis` | ^3.4 | Redis client backing the `Cache::driver('redis')` atomic lock for `ShouldBeUniqueUntilProcessing` | Locked since Phase 5 [VERIFIED: composer.json line 22] |
| `nwidart/laravel-modules` | ^13.0 | Bounded modules (`Modules/DriftAlerts/`) | Locked since Phase 1 [VERIFIED: composer.json line 21] |
| `spatie/laravel-data` | ^4.0 | Typed DTOs (`DriftAlertDto`, `CancellationImpactDto`) | Locked since Phase 1 [VERIFIED: composer.json line 23] |
| `brick/money` | ^0.11 | Multi-currency arithmetic on `delta_minor` + `annualized_impact_minor` | Locked since Phase 1; the existing `Modules\Ledger\Public\ValueObjects\Money` wrapper is used [VERIFIED: composer.json line 9] |
| `pestphp/pest` + `pestphp/pest-plugin-arch` | ^4.0 | Unit + feature + contract + arch tests | Locked since Phase 1; the four new BoundaryArchTest invariants (D-902) use `arch()` and `it()` helpers [VERIFIED: composer.json lines 41–42] |
| `larastan/larastan` + `canvural/larastan-strict-rules` | ^3.0 | Larastan level 10 strict | Locked since Phase 1; every new Phase 9 file passes Larastan + strict rules in CI [VERIFIED: composer.json lines 36–37] |

### Supporting (no new dependencies)

| Component | Version | Purpose | When to Use |
|-----------|---------|---------|-------------|
| `Carbon\CarbonImmutable` (bundled with Laravel) | 3.x | Date/time on `detected_at`, `actioned_at`, `snoozed_until` | Default for any new Phase 9 time column |
| `ApexCharts` (Phase 8 vendored asset) | n/a | Not used in Phase 9 itself (drift display is inline static text); Phase 10 will use it for the rich what-if view | — |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Per-series-per-sweep `RecurringSeriesMetricsRefreshed` event | Per-occurrence `RecurringSeriesOccurrenceAppended` event | Per-occurrence fires N times per sweep where N = approved series count; the metrics-refresh event fires once per series per sweep AFTER the row's `latest_amount_minor` is set. Per-occurrence is more precise but produces more wakeups. **Recommendation: `RecurringSeriesMetricsRefreshed`** — the drift evaluator already does its own "compare latest vs prior" inside the job, so it doesn't need per-occurrence granularity. Cheaper, less noise on the queue, and aligns with the existing detector's per-series write cadence. |
| Scheduled snooze-revival sweep | Query-time conditional (`WHERE state='snoozed' AND snoozed_until <= now()` treated-as-open) | Phase 8 already runs `expireSnoozes()` at the top of every recurring sweep (verified in `DetectRecurringSeriesJob::expireSnoozes`). **Recommendation: scheduled sweep**, mirroring the Phase 8 idiom verbatim. The count badge needs `state='open'` to be honest at SQL level; a query-time conditional forces every list + count query to repeat the conditional and would drift when callers (Phase 10 forecasting) forget to apply it. The sweep runs at the top of `DetectDriftAlertsJob::handle()` AND on a `routes/console.php` daily schedule for the case where no recurring sweep fires for ≥ 1 day. |
| Top-level `/drift` route | `/recurring/drift` sibling | Phase 8's existing routes are `/recurring`, `/recurring/review`, `/recurring/series/{id}` [VERIFIED: Modules/Recurring/Routes/web.php]. Phase 5's `/chains/review` sits at a top-level URI. **Recommendation: top-level `/drift`** — DriftAlerts is its own bounded module, its routes live in `Modules/DriftAlerts/Routes/web.php`, and the mental model "Recurring owns approval lifecycle; DriftAlerts owns alert lifecycle" reads naturally with sibling top-level routes. |
| New dedicated top-nav "Drift" slot | Secondary count chip on the existing "Recurring" slot | Phase 8 D-853 flagged top-nav crowding. Current nav slots (verified in `top-nav.blade.php`): Dashboard, Transactions, Imports, Inboxes, Uncategorized, Rules, Review chains, Recurring, Settings. That's 9 slots — adding a 10th risks visual clutter on narrower laptop widths. **Recommendation for the planner to pass to UI-SPEC: dual-count chip on existing "Recurring" slot**, but Phase 9 SHIPS THE QUERY (`DriftAlertQuery::openCountForUser(User $user): int`) + the View Factory composer registers BOTH `recurringPendingCount` AND `driftOpenCount`. UI-SPEC pass owns the final visual placement; the data layer is ready for either choice. |
| `threshold_source` enum on `drift_alerts` | Omit | The audit trail is genuinely more debuggable with `threshold_source` ('global' | 'series_override') captured alongside `threshold_percent_used`. Storage cost is negligible (one char column). **Recommendation: ship `threshold_source`** — it's load-bearing the first time a user reports "why did this fire at 5% when my series override is 50%?" and the answer is a 1-byte column lookup. |

**Installation:**

```bash
# No new composer requires. Phase 9 adds zero dependencies.
# After plans land:
composer dump-autoload  # picks up new Modules\DriftAlerts\* PSR-4 entry in composer.json autoload-dev
php artisan migrate     # runs the 4 new Phase 9 migrations
```

**Version verification:** Every package above was verified against the existing `composer.json` in this commit — no installs needed for Phase 9. Composer is already current.

## Package Legitimacy Audit

> Phase 9 installs **zero** new packages. The audit below documents the existing pinned versions for completeness — every package referenced in this RESEARCH.md is already present in `composer.json` from prior phases.

| Package | Registry | Disposition |
|---------|----------|-------------|
| All packages in `composer.json` | Packagist (npm/PyPI/crates n/a) | All pre-existing — no new install gate required for Phase 9 |

**Packages removed due to slopcheck [SLOP] verdict:** none (no new packages introduced).
**Packages flagged as suspicious [SUS]:** none.

## Architecture Patterns

### System Architecture Diagram

```
                                       ┌──────────────────────────────────────┐
                                       │  Phase 8 substrate (locked)          │
                                       │                                      │
                                       │   recurring_series                   │
                                       │   recurring_series_occurrences       │
                                       │   recurring_series_transitions       │
                                       └────────────────┬─────────────────────┘
                                                        │
                          ┌─────────────────────────────┴─────────────────────────────┐
                          │                                                           │
                          ▼                                                           ▼
   ┌──────────────────────────────────────┐                  ┌──────────────────────────────────────┐
   │ Modules/Recurring                    │                  │ Modules/DriftAlerts (NEW)            │
   │                                      │                  │                                      │
   │  DetectRecurringSeriesJob.handle()   │                  │   /drift  ──>  DriftPage (Livewire)  │
   │     ├── snooze expiry pass           │                  │                       │              │
   │     ├── ExpenseSeriesDetector        │                  │                       ▼              │
   │     │     ├── insertNewSeries        │                  │              DriftAlertQuery         │
   │     │     │   └── RecurringSeries-   │                  │              (Public)                │
   │     │     │       Detected event     │                  │                       ▲              │
   │     │     └── refreshExistingSeries  │                  │                       │              │
   │     │         ├── UPDATE metrics     │                  │            drift_alerts table        │
   │     │         ├── dispatch NEW       │ ─────────────>   │   ┌───────────────────────────────┐  │
   │     │         │   RecurringSeries-   │   listener       │   │                               │  │
   │     │         │   MetricsRefreshed   │                  │   ▼                               │  │
   │     │         └── (on cadence flip)  │   queues         │  DetectDriftAlertsJob             │  │
   │     │             state machine      │ ─────────────>   │  (ShouldBeUniqueUntilProcessing,  │  │
   │     │             transition         │                  │   keyed (user_id, series_id))     │  │
   │     │                                │                  │     │                             │  │
   │     └── IncomeSeriesDetector         │                  │     ▼                             │  │
   │           (same insertion points)    │                  │   DriftEvaluator (Internal)       │  │
   │                                      │                  │     ├── reads last 2 occurrences  │  │
   │  Public surface (read API):          │                  │     │   via RecurringSeriesQuery  │  │
   │     RecurringSeriesQuery             │ <─── reads ───── │     │   ::occurrencesForSeries()  │  │
   │       ::occurrencesForSeries()       │                  │     ├── computes delta + ratio    │  │
   │       ::approvedForUser()            │                  │     ├── effective threshold       │  │
   │       ::cadenceChangedForUser()      │                  │     │   = series_override         │  │
   │     FixedPaymentsViewQuery           │                  │     │     ?? user_global ?? 5     │  │
   │       (Phase 8 surface; Phase 9      │                  │     ├── annualized impact         │  │
   │        DOES NOT use it for math)     │                  │     └── INSERT drift_alerts row   │  │
   │                                      │                  │         + DriftAlertOpened event  │  │
   │  Public Action:                      │                  │                                   │  │
   │     SnoozeRecurringSeries            │ <───── called    │   DriftAlertStateMachine          │  │
   │       (DriftAlerts MAY invoke, but   │      by Phase 10 │     ├── open → acknowledged       │  │
   │        only as a cross-module Public │   ── never by ── │     ├── open → snoozed            │  │
   │        Action; never writes the      │   Phase 9 ──── > │     ├── snoozed → open (revival)  │  │
   │        recurring_series row direct)  │                  │     └── open → dismissed_cancelled│  │
   │                                      │                  │                                   │  │
   │  Settings extension:                 │                  │   drift_alert_transitions table   │  │
   │     recurring_series.drift_          │ <── written by   │     (every transition writes one  │  │
   │       threshold_percent (nullable)   │    Phase 9 plan  │      audit row; mirrors Phase 8   │  │
   │                                      │                  │      recurring_series_transitions)│  │
   └──────────────────────────────────────┘                  └──────────────────────────────────────┘
                          ▲                                                           ▲
                          │                                                           │
                          │                                                           │
                  daily Schedule::call                                  Public read API for Phase 10:
                  routes/console.php                                       CancellationImpactQuery
                  (recurring.detect)                                         ::forSeries(): CancellationImpactDto
                                                                            { annual_savings_minor,
                                                                              monthly_savings_minor,
                                                                              currency }


  Settings page (/settings)                       Top-nav  ───────  View Factory composer
    ├── users.drift_alert_threshold_percent       (existing)        (RecurringServiceProvider + new
    │     (NEW column, default 5)                                    DriftAlertsServiceProvider both
    └── per-series override editor inline                            inject counts into
        on /drift row + /recurring/series/{id}                       core::livewire.top-nav)
                                                                       ├── recurringPendingCount
                                                                       └── driftOpenCount (NEW)

  Dashboard (/)
    ├── (existing) "This month at a glance"
    ├── (existing) "Fixed monthly payments" card (Phase 8)
    ├── (existing) "Next ICS settlement" tile (Phase 5)
    └── (NEW) "Drift alerts" count badge → links to /drift
```

### Component Responsibilities

| Component | Location | Responsibility |
|-----------|----------|----------------|
| `Modules/DriftAlerts/Public/Dto/DriftAlertDto.php` | DriftAlerts Public | Spatie-Data DTO for `/drift` rows. Carries `seriesId`, `direction`, `displayName`, `baselineAmount` (Money in original currency), `latestAmount` (Money in original currency), `delta` (Money signed), `annualizedImpact` (Money signed), `eurEquivalent` (nullable Money in EUR), `thresholdPercentUsed`, `thresholdSource`, `state`, `detectedAt`, `actionedAt`, `snoozedUntil`. |
| `Modules/DriftAlerts/Public/Dto/CancellationImpactDto.php` | DriftAlerts Public | `monthlySavings: Money`, `annualSavings: Money`, `currency: string`. Phase 10 hand-off contract. |
| `Modules/DriftAlerts/Public/Events/DriftAlertOpened.php` | DriftAlerts Public | `userId`, `driftAlertId`, `recurringSeriesId`, `direction`, `deltaMinor`, `annualizedImpactMinor`, `currency`. Phase 10 may listen. |
| `Modules/DriftAlerts/Public/Events/DriftAlertAcknowledged.php` | DriftAlerts Public | `userId`, `driftAlertId`, `acknowledgedAt`. |
| `Modules/DriftAlerts/Public/Events/DriftAlertSnoozed.php` | DriftAlerts Public | `userId`, `driftAlertId`, `snoozedUntil`. |
| `Modules/DriftAlerts/Public/Events/DriftAlertDismissedCancelled.php` | DriftAlerts Public | `userId`, `driftAlertId`, `recurringSeriesId`. Phase 10 forecasting subscribes (excludes the series from forward projections). Phase 9 has NO Phase-9-internal listener — confirmed via D-926 resolution. |
| `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` | DriftAlerts Public | Constructor: `DriftAlertStateMachine`, `Dispatcher`. `__invoke(int $alertId, User $user): void`. Flips state to `acknowledged`; writes transition row; dispatches `DriftAlertAcknowledged`. Cross-user → `NotFoundHttpException` (404). Mirrors `ApproveRecurringSeries` precedent. |
| `Modules/DriftAlerts/Public/Actions/SnoozeDriftAlert.php` | DriftAlerts Public | Constructor: `DriftAlertStateMachine`. `__invoke(int $alertId, User $user, CarbonImmutable $until): void`. Mirrors `SnoozeRecurringSeries` precedent (snoozed_until passed via `$extraColumns` to state machine for atomic state + timestamp). |
| `Modules/DriftAlerts/Public/Actions/DismissDriftAlertAsCancelled.php` | DriftAlerts Public | Constructor: `DriftAlertStateMachine`, `Dispatcher`. Flips state to `dismissed_cancelled`; emits `DriftAlertDismissedCancelled`. Does NOT mutate `recurring_series.state` (D-920 invariant). |
| `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` | DriftAlerts Public | Read API. `openForUser(User, ?cursor, limit): list<DriftAlertDto>`, `groupedByseriesForUser(User): array<seriesId, list<DriftAlertDto>>`, `historyForUser(User, ?cursor, limit): list<DriftAlertDto>` (acknowledged + dismissed tabs), `openCountForUser(User): int`. Cursor on `detected_at DESC, id DESC`. |
| `Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php` | DriftAlerts Public | `forSeries(int $seriesId, User $user): CancellationImpactDto`. Reads `recurring_series.monthly_equivalent_minor` + `recurring_series.latest_currency` via `RecurringSeriesQuery::forSeries`. Math: `monthly = monthly_equivalent_minor`; `annual = monthly × 12`; `currency = latest_currency`. Cross-user → returns a zero-value DTO with `currency='EUR'` (planner picks: throw 404 vs zero-DTO; the existing `RecurringSeriesQuery::forSeries` returns `null` on cross-user so the throw posture is the safer match — verified via `RecurringSeriesQuery.php` lines 91–104). |
| `Modules/DriftAlerts/Internal/DriftEvaluator.php` | DriftAlerts Internal | Math. Constructor: `DatabaseManager`, `Clock`, `RecurringSeriesQuery` (the Public read), `Dispatcher`, `DriftAlertStateMachine` (insert path). Method: `evaluateForSeries(int $seriesId, User $user): void`. Reads last 2 occurrences via Public Query; computes signed delta in original currency; effective threshold lookup via `series.drift_threshold_percent ?? user.drift_alert_threshold_percent ?? 5`; if `|delta / prior| × 100 > effective_threshold AND prior_amount != 0 AND prior_amount IS NOT NULL`, computes annualized impact via `delta × cadence_multiplier_for_year(cadence)` and inserts a `drift_alerts` row with `state='open'`; emits `DriftAlertOpened`. |
| `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php` | DriftAlerts Internal | Queued. `ShouldBeUniqueUntilProcessing` keyed `userId:seriesId` (literal interpolated `"{$userId}:{$seriesId}"`). `uniqueFor = 600` (10 minutes, mirrors Phase 8). `uniqueVia()` → `Cache::driver('redis')` — single facade carve-out documented in arch test ignore list. `handle()` invokes `DriftEvaluator::evaluateForSeries`. `tries=3`, `backoff=[60,300,900]`. |
| `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php` | DriftAlerts Internal | Listens to the new Recurring-side `RecurringSeriesMetricsRefreshed` Public event. Dispatches one `DetectDriftAlertsJob($userId, $seriesId)` per event. The listener stays synchronous (no `ShouldQueue` on the listener itself — the JOB is queued; double-queueing would defeat the unique key). |
| `Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php` | DriftAlerts Internal | Sole mutator of `drift_alerts.state`. Mirrors `RecurringSeriesStateMachine` verbatim. `transition(DriftAlert $alert, string $toState, string $reason, string $actor, ?string $notes, array $extraColumns)` opens a DB transaction, sets `PRAGMA busy_timeout=5000`, `lockForUpdate` on the row, validates against `ALLOWED_TRANSITIONS`, writes new state + updated_at + audit row. ALLOWED_TRANSITIONS: `open → [acknowledged, snoozed, dismissed_cancelled]`, `acknowledged → []` (terminal), `snoozed → [open, acknowledged, dismissed_cancelled]`, `dismissed_cancelled → []` (terminal). |
| `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` | DriftAlerts Internal | The `/drift` Livewire SFC. Constructor-injects `CurrentUser` + `DriftAlertQuery` + the three Public Actions. Renders three tabs: Open (default) / History / Dismissed. Open tab groups by series (collapsible header with stacked-count chip). Each row: direction-aware copy + Acknowledge / Snooze (popover) / Dismiss-as-cancelled buttons. `#[Url(as: 'tab', except: 'open')]` for tab state. `wire:poll.30s` for the count badge cell only — NOT for the full table (avoids interaction races during the snooze popover). |
| `Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php` | DriftAlerts Internal | Renders the dashboard's drift-alerts count badge tile via `@livewire('drift-alerts.dashboard-drift-badge')` from `dashboard.blade.php`. Mirrors `FixedPaymentsCard` precedent. |
| `Modules/DriftAlerts/Database/Migrations/*_create_drift_alerts_table.php` | DriftAlerts migrations | See schema below. |
| `Modules/DriftAlerts/Database/Migrations/*_create_drift_alert_transitions_table.php` | DriftAlerts migrations | See schema below. |
| `Modules/Recurring/Database/Migrations/*_add_drift_threshold_percent_to_recurring_series.php` | Recurring migrations (Phase 9 plan owns the FILE) | Adds `recurring_series.drift_threshold_percent` (nullable unsignedTinyInteger). The file path lives in `Modules/Recurring/Database/Migrations/` because the column is on a Recurring-owned table; the Phase 9 plan's task list creates the file (cross-module migration is acceptable per the Phase 4 D-80 precedent of cross-module schema additions). |
| `Modules/Core/Database/Migrations/*_add_drift_alert_threshold_percent_to_users.php` | Core migrations (Phase 9 plan owns the FILE) | Adds `users.drift_alert_threshold_percent` (unsignedTinyInteger, default 5). Lives in `Modules/Core/Database/Migrations/` mirroring the existing `add_recurring_settings_to_users.php` precedent (which is in `Modules/Recurring/Database/Migrations/` — alternative: keep the new users-side column inside `Modules/Recurring/Database/Migrations/` for consistency; planner picks). |
| `Modules/Recurring/Public/Events/RecurringSeriesMetricsRefreshed.php` | Recurring Public (NEW — Phase 9 plan owns the file) | Final readonly class. Carries `userId: int`, `recurringSeriesId: int`, `direction: string`, `cadence: string`, `latestAmountMinor: int`, `latestCurrency: string`. Dispatched from `ExpenseSeriesDetector::refreshExistingSeries` AND `IncomeSeriesDetector::refreshExistingSeries` immediately after the metric `update()` commits, AND from `insertNewSeries` (so a brand-new series with 2+ historical occurrences gets evaluated for drift on its first surfacing). |

### Recommended Project Structure

```
Modules/DriftAlerts/
├── composer.json              # PSR-4 namespace registration
├── Database/
│   └── Migrations/
│       ├── 2026_05_19_010001_create_drift_alerts_table.php
│       └── 2026_05_19_010002_create_drift_alert_transitions_table.php
├── Internal/
│   ├── DriftEvaluator.php
│   ├── Http/
│   │   └── Livewire/
│   │       ├── DriftPage.php
│   │       └── DashboardDriftBadge.php
│   ├── Jobs/
│   │   └── DetectDriftAlertsJob.php
│   ├── Listeners/
│   │   └── EvaluateDriftOnMetricsRefreshed.php
│   ├── Mapping/
│   │   └── DriftAlertDtoMapper.php
│   └── StateMachines/
│       ├── DriftAlertStateMachine.php
│       └── InvalidStateTransitionException.php
├── Models/
│   ├── DriftAlert.php
│   └── DriftAlertTransition.php
├── Providers/
│   └── DriftAlertsServiceProvider.php
├── Public/
│   ├── Actions/
│   │   ├── AcknowledgeDriftAlert.php
│   │   ├── SnoozeDriftAlert.php
│   │   └── DismissDriftAlertAsCancelled.php
│   ├── Dto/
│   │   ├── DriftAlertDto.php
│   │   └── CancellationImpactDto.php
│   ├── Events/
│   │   ├── DriftAlertOpened.php
│   │   ├── DriftAlertAcknowledged.php
│   │   ├── DriftAlertSnoozed.php
│   │   └── DriftAlertDismissedCancelled.php
│   └── Services/
│       ├── DriftAlertQuery.php
│       └── CancellationImpactQuery.php
├── Resources/
│   └── views/
│       └── livewire/
│           ├── drift-page.blade.php
│           └── dashboard-drift-badge.blade.php
├── Routes/
│   └── web.php                # GET /drift + Livewire endpoints
└── tests/
    ├── Feature/
    │   ├── DriftPageTest.php
    │   ├── AcknowledgeDriftAlertTest.php
    │   ├── SnoozeDriftAlertTest.php
    │   ├── DismissDriftAlertAsCancelledTest.php
    │   ├── DriftAlertCrossUser404Test.php
    │   └── DashboardDriftBadgeTest.php
    ├── Unit/
    │   ├── DriftEvaluatorTest.php
    │   ├── DriftAlertStateMachineTest.php
    │   └── CancellationImpactQueryTest.php
    └── fixtures/
        └── drift-corpus/      # see Wave 0 fixture corpus below
```

### Pattern 1: Sole-mutator state machine with arch test + DB triggers

**What:** Phase 8's `RecurringSeriesStateMachine` is the single legal mutator of `recurring_series.state`. Triple-enforcement: arch test (`noOtherRecurringSeriesStateMutator`) + DB triggers (`recurring_series_state_check_insert/update`) + the state machine's per-row validation against `ALLOWED_TRANSITIONS`.

**When to use:** Phase 9 ships an identical pattern for `drift_alerts.state`.

**Example:** verbatim mirror of `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` (lines 42–172, especially the transaction + PRAGMA busy_timeout + lockForUpdate envelope around the UPDATE + audit-row INSERT).

```php
// Source: Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php lines 76–135 (locked Phase 8 pattern)
public function transition(
    DriftAlert $alert,
    string $toState,
    string $reason,        // 'user_action' | 'detector_revived_snooze' | 'detector_opened'
    string $actor,         // 'user' | 'detector'
    ?string $notes = null,
    array $extraColumns = [],
): void {
    if (! in_array($actor, ['user', 'detector'], true)) {
        throw new InvalidArgumentException("...");
    }

    $this->db->connection()->transaction(function () use (...): void {
        $connection = $this->db->connection();
        $connection->statement('PRAGMA busy_timeout = 5000');

        $row = $connection->table('drift_alerts')
            ->where('id', $alertId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw new RuntimeException("...");
        }

        $this->guardTransition($alertId, $row->state, $toState);

        $now = $this->clock->now()->toDateTimeString();
        $connection->table('drift_alerts')
            ->where('id', $alertId)
            ->update(array_merge($extraColumns, ['state' => $toState, 'updated_at' => $now]));

        $connection->table('drift_alert_transitions')->insert([
            'user_id' => $userId,
            'drift_alert_id' => $alertId,
            'from_state' => $row->state,
            'to_state' => $toState,
            'transition_reason' => $reason,
            'actor' => $actor,
            'transitioned_at' => $now,
            'notes' => $notes,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    });
}
```

### Pattern 2: Public event + Internal listener + queued job

**What:** Phase 5/6/7/8 idiom — a Public event (`RecurringSeriesApproved`, `TransactionImported`, etc.) is dispatched after the writing transaction commits; an Internal listener subscribes; the listener dispatches a queued job; the job carries `ShouldBeUniqueUntilProcessing` so concurrent triggers collapse.

**When to use:** Phase 9's `EvaluateDriftOnMetricsRefreshed` listener.

```php
// Source: Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php lines 53–80 (verbatim pattern)
final class DetectDriftAlertsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId, public readonly int $recurringSeriesId) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->recurringSeriesId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): \Illuminate\Contracts\Cache\Repository
    {
        // Documented facade carve-out (mirrors Phase 8 DetectRecurringSeriesJob).
        // BoundaryArchTest::noFacadeCallsFromDriftAlerts ignore list includes
        // this FQN.
        return \Illuminate\Support\Facades\Cache::driver('redis');
    }

    public function handle(DriftEvaluator $evaluator): void
    {
        $evaluator->evaluateForSeries($this->recurringSeriesId, /* user resolved inside */);
    }
}
```

### Pattern 3: Top-nav badge via View Factory composer

**What:** Each module's `ServiceProvider::boot()` registers a `core::livewire.top-nav` composer through `$this->app->make(ViewFactoryContract::class)->composer(...)` — never the `view()` global helper. Composer queries the count and injects it into the view.

**When to use:** The new `DriftAlertsServiceProvider::registerTopNavBadgeComposer()` follows verbatim (or — depending on UI-SPEC's D-927 decision — the existing `RecurringServiceProvider::registerTopNavBadgeComposer()` is extended to also inject `driftOpenCount`).

```php
// Source: Modules/Recurring/Providers/RecurringServiceProvider.php lines 111–126
private function registerTopNavBadgeComposer(): void
{
    $app = $this->app;
    $factory = $app->make(ViewFactoryContract::class);

    $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {
        $currentUser = $app->make(CurrentUser::class);
        if (! $currentUser->isAuthenticated()) {
            $compose->with('driftOpenCount', 0);
            return;
        }
        $query = $app->make(DriftAlertQuery::class);
        $compose->with('driftOpenCount', $query->openCountForUser($currentUser->user()));
    });
}
```

### Pattern 4: PSR-4 test discovery (3-step wire-up)

**What:** Adding a new module requires three coordinated changes — `composer.json` autoload-dev + `phpunit.xml` testsuite + `tests/Pest.php` per-module map row. Per-module `Pest.php` is documented inert.

**When to use:** Wave 0 of Phase 9 — adding `Modules\\DriftAlerts\\Tests\\` PSR-4 entry.

### Pattern 5: Cross-user 404 invariant

**What:** Every Public Action and every page route asserts `$row->user_id === $currentUser->id` defensively + via `->where('user_id', ...)` clauses. Cross-user invocation → `NotFoundHttpException`.

**When to use:** All three Public Actions + `DriftPage::mount()` + `DriftAlertQuery` methods.

```php
// Source: Modules/Recurring/Public/Actions/ApproveRecurringSeries.php lines 32–45
$alert = DriftAlert::query()
    ->where('id', $alertId)
    ->where('user_id', $user->id)
    ->first();

if ($alert === null) {
    throw new NotFoundHttpException('Drift alert not found.');
}
```

### Anti-Patterns to Avoid

- **Synchronous drift detection inside an HTTP request.** Drift evaluation runs inside `DetectDriftAlertsJob` only. The `noSynchronousDriftDetectionInRequestLifecycle` arch test enforces this by checking that `DriftEvaluator` is not imported by anything under `Modules\DriftAlerts\Internal\Http`. Mirrors Phase 8's pattern (`noSynchronousDetectionInRequestLifecycle`).
- **Writing to `recurring_series` from `Modules/DriftAlerts/`.** The `noRecurringSeriesWritesFromDriftAlerts` arch test scans for `RecurringSeries::query|RecurringSeries::where|RecurringSeries::create` patterns + `->table('recurring_series')->update|insert|delete` shapes anywhere under `Modules/DriftAlerts/`. The single permitted cross-module write path is calling the existing `Modules\Recurring\Public\Actions\SnoozeRecurringSeries` (if a UI flow ever wires "snooze the whole series" from a drift alert — Phase 9 does NOT wire this, but the carve-out is in place for v2).
- **EUR-only drift detection.** Drift compares `original_amount_minor` in `original_currency` only. EUR shadow is display-only. A test in the Wave 0 fixture corpus asserts that an FX-only swing on a USD subscription produces ZERO drift alerts.
- **Division by zero on `prior_amount = 0` or `NULL`.** `DriftEvaluator` guard: `if ($priorAmountMinor === null || $priorAmountMinor === 0) { return; }`. Unit test covers this.
- **Mutating `drift_alerts.state` outside `DriftAlertStateMachine`.** Enforced by `noOtherDriftAlertStateMutator` arch test (5th invariant beyond the 4 in D-902 — see the resolution note in `Open Questions` below).
- **Per-occurrence event subscription that fires hundreds of times per sweep.** Use `RecurringSeriesMetricsRefreshed` (per-series-per-sweep), NOT `RecurringSeriesOccurrenceAppended` (per-occurrence). The per-series event suffices because the drift evaluator does its own "last 2 occurrences" lookup.
- **Storing EUR shadow as the canonical `delta_minor`.** `delta_minor` is in `original_currency`. Phase 8 D-839/D-840/D-842 carry-forward.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Per-user job deduplication | Custom Redis SET + cron-sweep cleanup | `ShouldBeUniqueUntilProcessing` + `uniqueId()` + `uniqueFor` | Laravel ships atomic-lock semantics; reimplementing is bug bait |
| State machine with audit trail | Custom enum + hand-coded transition table + manual audit row inserts | Phase 8 `RecurringSeriesStateMachine` pattern (DB triggers + arch test + transaction + lockForUpdate + audit row insertion in the same DB transaction) | Triple-enforcement (schema + arch + runtime) catches every mis-write |
| Money arithmetic on `delta_minor` × multiplier | `(float) $delta * 12` cast | `brick/money` Money + integer-cents BIGINT throughout; multiplier applied as integer arithmetic | Floating-point money corrupts balances irreversibly (PITFALLS.md Pitfall 1 carry-forward) |
| Top-nav badge composition | `view()` global helper inside ServiceProvider | `$this->app->make(ViewFactoryContract::class)->composer(...)` | DI-only invariant (CLAUDE.md); `view()` is a forbidden helper |
| `/drift` count freshness on dashboard | Polling the full Livewire SFC every 5 seconds | `wire:poll.30s` scoped to the count cell only | Polling full tables causes interaction races during popover/modal open states (see Pitfall 2 below) |
| Cross-user query isolation | Trust the page guard alone | Every query AND every action `->where('user_id', $user->id)` (defence-in-depth) | Project FND-03 invariant + cross-user 404 invariant from Phases 3-07 / 4-04 / 5-04 / 8 |
| New currency conversion logic for the EUR shadow | Re-fetch FX rate from anywhere | Use the alert's latest occurrence `fx_rate_used` (already preserved per LED-03) | Phase 3 D-44/D-47 + Phase 8 D-840 pattern; no new FX provider needed |

**Key insight:** Phase 9 introduces ZERO new domain primitives. Every concept (state machine, sole-mutator arch test, View Factory composer, queued job with per-user single-flight lock, cross-user 404 guard, Public/Internal split, BoundaryArchTest invariants, Wave 0 fixture corpus) already exists in Phase 5–8. The phase is mechanical: clone the patterns, swap the table names, write the math.

## Common Pitfalls

### Pitfall 1: `monthly_equivalent_minor` is NOT in EUR — it's in `latest_currency`

**What goes wrong:** A planner reading `RecurringSeriesDto::monthlyEquivalent` (typed `Money`) and the Phase 8 CONTEXT docblock might conclude `monthly_equivalent_minor` is in EUR. It is not.

**Why it happens:** The Phase 8 CONTEXT.md describes the dashboard rendering (which EUR-converts at view time, D-841) but the underlying column `recurring_series.monthly_equivalent_minor` is computed from `latest_amount_minor` (in `latest_currency`) via `monthlyEquivalent()` in `ExpenseSeriesDetector` lines 433-445. The mapper at `Modules/Recurring/Internal/Mapping/RecurringSeriesDtoMapper.php` line 46–49 hydrates the `monthlyEquivalent` Money with `$latestCurrency !== '' ? $latestCurrency : 'EUR'`. Verified in code.

**How to avoid:** `CancellationImpactQuery::forSeries` reads `monthly_equivalent_minor` AND `latest_currency` together. The output DTO's `currency` field is `latest_currency`, NOT `'EUR'`. The display layer is responsible for EUR shadow if the user has dual-currency view enabled. Document explicitly in `CancellationImpactQuery` PHPDoc.

**Warning signs:** A test that asserts `$dto->currency === 'EUR'` for a USD Netflix series — will fail correctly under this rule.

### Pitfall 2: `wire:poll` on the whole `/drift` SFC races with popover open-state

**What goes wrong:** If `/drift` carries a top-level `wire:poll.5s`, the polling refresh resets `x-data="{ open: false }"` on every Snooze popover the user has opened mid-interaction. The popover closes mid-click; the user can't pick a snooze date.

**Why it happens:** Livewire re-rendering replaces the DOM subtree; Alpine state initialized via `x-data` resets on re-mount unless explicitly persisted.

**How to avoid:** Scope `wire:poll.30s` to the count badge cell only — NOT the table. Or omit polling on `/drift` entirely; the count badge on the top-nav and dashboard updates via the View Factory composer on next page load, which is calm and predictable. (Recommendation: omit `wire:poll` from `/drift` proper; let the user refresh manually. Use `wire:poll.30s` on the dashboard count badge ONLY.)

**Warning signs:** Manual test of "open snooze popover, wait 5 seconds, click '1 month'" — the popover should still be open after 5s.

### Pitfall 3: Per-occurrence event subscription floods the queue

**What goes wrong:** If Phase 9 subscribes to `RecurringSeriesOccurrenceAppended` (one event per matched transaction), a typical sweep with 200 approved series × 6 new occurrences = 1200 events × 1200 listener wakeups, each dispatching a job (most of which collapse under the unique lock, but the queue churn is real).

**Why it happens:** Per-occurrence granularity is finer than the detector needs. The detector only cares about the post-refresh metric state, not each occurrence write.

**How to avoid:** Use `RecurringSeriesMetricsRefreshed` (per-series-per-sweep) — fires once per refreshed series AFTER `latest_amount_minor` is updated. Recurring's `ExpenseSeriesDetector::refreshExistingSeries` and `IncomeSeriesDetector::refreshExistingSeries` both dispatch the event at the same call site where they currently dispatch `RecurringSeriesCadenceFlipped` (verified — lines 364–402 in `ExpenseSeriesDetector`). New series get the event too via `insertNewSeries` post-INSERT so brand-new series with 2+ historical occurrences see drift evaluation immediately.

**Warning signs:** Queue depth metric spiking during a daily sweep — would indicate the per-occurrence variant landed accidentally.

### Pitfall 4: `prior_amount = 0` divides by zero

**What goes wrong:** The detector's first observation cluster could have an outlier amount of 0 (a refund equal-and-opposite to the original charge that landed as a separate row pre-Phase-4 LED-04 transfer-pair logic, or a misclassified row). `delta / 0` → `INF` → `INF > threshold_percent` → false alert.

**Why it happens:** Phase 8 detector clusters on counterparty + currency without an explicit "non-zero amount" filter; the variance-tolerance filter is statistical (median-based) not boundary-enforcing.

**How to avoid:** `DriftEvaluator` guard at the top: `if ($priorAmountMinor === null || $priorAmountMinor === 0) { return; }`. Wave 0 fixture corpus includes a "prior=NULL" scenario asserting ZERO alerts fired.

**Warning signs:** A unit test of "prior amount = 0 cents" producing a non-empty alert list.

### Pitfall 5: Snoozed-alert revival timing precision

**What goes wrong:** A user snoozes a drift alert until `2026-06-01 00:00:00`. The next recurring sweep doesn't fire until `2026-06-01 02:00:00` because the Laravel scheduler runs daily at 02:00. The alert stays "snoozed" for 2 hours past its expiry — the count badge is stale.

**Why it happens:** Phase 8's `expireSnoozes()` runs at the top of the daily sweep job; if the user views `/drift` between midnight and the 02:00 sweep, they see stale "snoozed" rows.

**How to avoid:** Two complementary mechanisms:
1. **Primary:** Scheduled sweep `RevivedExpiredDriftSnoozesJob` runs hourly (NOT daily) via `Schedule::call(...)->hourly()`. Mirrors Phase 8's `expireSnoozes` shape verbatim but on its own cadence.
2. **Secondary:** Every `DriftAlertQuery::openForUser` call also reads `WHERE state IN ('open') OR (state='snoozed' AND snoozed_until <= now())`. The count badge is honest immediately; the actual state flip lags by up to 1h but the audit row write happens lazily on the next sweep (acceptable for an analytical surface).

(Planner picks: pure-scheduled vs hybrid. The hybrid posture is what this RESEARCH recommends for honest count freshness without forcing per-second precision on a scheduled task.)

**Warning signs:** A Wave 0 fixture scenario "snooze expires at T+1min" + the test asserts the alert flips back to `open` within 1 hour of expiry.

### Pitfall 6: `cluster_counterparty_key` lookup ambiguity for cross-currency series

**What goes wrong:** Phase 8 D-839 says a USD→EUR currency switch produces TWO series (one per `latest_currency`). Phase 9's drift detector might naively read `RecurringSeriesQuery::occurrencesForSeries($seriesId, $user)` and find occurrences with both currencies if the series row's `latest_currency` ever drifts.

**Why it happens:** `recurring_series_occurrences.observed_currency` is per-row (not always equal to `recurring_series.latest_currency`). If the user re-imported some old USD transactions for the EUR-tagged series, the occurrences table would have a mix.

**How to avoid:** The detector filters: `WHERE observed_currency = $series->latest_currency` when reading the last-two occurrences. Or simpler: read the last two occurrences ordered by `observed_at DESC, id DESC` and trust the Phase 8 detector's clustering invariant (one series = one currency). The Wave 0 fixture corpus includes a "currency switch mid-window" scenario to validate the chosen approach.

**Warning signs:** Drift alert fires with `baseline_currency='USD'` and `latest_currency='EUR'` — a mixed-currency alert that makes no sense.

## Code Examples

### Effective Threshold Lookup

```php
// Source: deduced from D-915 + Phase 8 settings precedent in
// Modules/Core/Internal/Http/Livewire/SettingsPage.php lines 92-93
private function effectiveThresholdPercent(int $recurringSeriesId, User $user): array
{
    $seriesRow = $this->db->connection()->table('recurring_series')
        ->where('id', $recurringSeriesId)
        ->where('user_id', $user->id)
        ->first(['drift_threshold_percent']);

    $seriesOverride = $seriesRow !== null && is_numeric($seriesRow->drift_threshold_percent)
        ? (int) $seriesRow->drift_threshold_percent
        : null;

    if ($seriesOverride !== null) {
        return ['percent' => $seriesOverride, 'source' => 'series_override'];
    }

    $userValue = $user->drift_alert_threshold_percent;
    if (is_int($userValue) && $userValue > 0) {
        return ['percent' => $userValue, 'source' => 'global'];
    }

    return ['percent' => 5, 'source' => 'global'];  // hard-coded fallback default
}
```

### Cadence-to-Year Multiplier (D-917 + D-924 resolution)

```php
// Source: Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php lines 433-445
// Phase 8 uses `* 52 / 12` (effectively × 4.3333) for weekly.
// For Phase 9's cadence-to-year multiplier, the planner picks `× 52` for weekly
// so that delta × 52 == (delta × 52/12) × 12 at the integer level
// (modulo rounding — round at the END, not at intermediate steps).
//
// Verified consistency check:
//   Phase 8: latestAmount × 52 / 12 → monthly_equivalent_minor
//   Phase 9: monthly_equivalent_minor × 12 → annual_savings_minor
//             = latestAmount × 52 / 12 × 12 = latestAmount × 52  ✓
//   Phase 9: delta × 52 → annualized_impact_minor  ✓
//
// Both formulas agree at the integer level. ×52.18 (calendar-accurate)
// would diverge by ~0.35% per fixture and break the consistency check.
private function cadenceMultiplierForYear(string $cadence): int
{
    return match ($cadence) {
        'weekly' => 52,
        'monthly' => 12,
        'quarterly' => 4,
        'yearly' => 1,
        default => 0,  // cadence='irregular' produces zero impact — guard upstream
    };
}
```

### Detector Insertion Point in Phase 8 (where the new event fires)

```php
// Source: Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php
// PHASE 9 PLAN ADDS: a `$this->events->dispatch(new RecurringSeriesMetricsRefreshed(...))`
// call at the END of refreshExistingSeries() (after the cadence-flip block at lines 381-401)
// AND at the END of insertNewSeries() (after the RecurringSeriesDetected dispatch at line 333).
// IncomeSeriesDetector gets the same two-call-site addition at its equivalent methods.

private function refreshExistingSeries(/* ... */): void
{
    $now = $this->clock->now()->toDateTimeString();
    $connection = $this->db->connection();

    $previousCadence = $series->cadence;

    $connection->table('recurring_series')
        ->where('id', $series->id)
        ->update([/* ... metric refresh ... */]);

    $seriesId = $series->id;
    $this->insertOccurrenceRows($user->id, $seriesId, $rows, $currency);

    if (in_array($series->state, ['approved', 'cadence_changed'], true) && $previousCadence !== $cadence) {
        // ... cadence-flip state-machine transition + event ...
    }

    // NEW Phase 9 insertion point — fires for EVERY refreshed series
    // (approved + cadence_changed + pending), NOT just cadence-flipped ones.
    // DriftAlerts' listener filters by state internally; emitting universally
    // keeps the event surface clean (drift detection is per-series, not
    // per-state-transition).
    $this->events->dispatch(new RecurringSeriesMetricsRefreshed(
        userId: $user->id,
        recurringSeriesId: $seriesId,
        direction: 'expense',
        cadence: $cadence,
        latestAmountMinor: $latestAmountMinor,
        latestCurrency: $currency,
    ));
}
```

### `drift_alerts` schema

```php
// Source: planner draws this from D-901 + D-908 + D-915 + D-917 + Phase 8 schema patterns.
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create('drift_alerts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('recurring_series_id')->constrained('recurring_series')->cascadeOnDelete();
            $table->string('state', 24)->default('open');           // open / acknowledged / snoozed / dismissed_cancelled
            $table->enum('direction', ['expense', 'income']);       // denormalised from recurring_series.direction
            $table->bigInteger('baseline_amount_minor');            // prior occurrence original_amount_minor
            $table->bigInteger('latest_amount_minor');              // latest occurrence original_amount_minor
            $table->string('currency', 3);                          // original currency
            $table->bigInteger('delta_minor');                      // signed; latest - baseline
            $table->bigInteger('annualized_impact_minor');          // signed; delta × cadence_multiplier_for_year
            $table->unsignedTinyInteger('threshold_percent_used');  // effective threshold AT detection time
            $table->string('threshold_source', 24);                 // 'global' | 'series_override'
            $table->foreignId('latest_occurrence_id')               // ← idempotency seam (one drift alert per (series, latest occurrence))
                ->constrained('recurring_series_occurrences')->cascadeOnDelete();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->unique(['recurring_series_id', 'latest_occurrence_id'], 'drift_alerts_uniq');
            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'state', 'detected_at']);
            $table->index(['user_id', 'recurring_series_id', 'state']);
        });

        // Sole-mutator trigger pair (mirrors Phase 8 recurring_series.state pattern):
        $allowedStates = "'open','acknowledged','snoozed','dismissed_cancelled'";
        $connection->statement(sprintf(
            "CREATE TRIGGER drift_alerts_state_check_insert BEFORE INSERT ON drift_alerts FOR EACH ROW
             WHEN NEW.state NOT IN (%s) BEGIN SELECT RAISE(ABORT, 'Invalid drift_alerts.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER drift_alerts_state_check_update BEFORE UPDATE OF state ON drift_alerts FOR EACH ROW
             WHEN NEW.state NOT IN (%s) BEGIN SELECT RAISE(ABORT, 'Invalid drift_alerts.state value'); END",
            $allowedStates,
        ));
    }
};
```

### `drift_alert_transitions` schema

Verbatim mirror of `recurring_series_transitions`. Columns: `id`, `user_id`, `drift_alert_id`, `from_state`, `to_state`, `transition_reason`, `actor`, `transitioned_at`, `notes`, `created_at`, `updated_at`.

## Runtime State Inventory

> Phase 9 is greenfield (new tables, new module, new event surface). No rename / refactor / migration concerns.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — verified by scanning `database/` and `Modules/*/Database/Migrations/` for any reference to "drift" or REC-06/07/08. | none |
| Live service config | None — no external service consumes the drift surface in Phase 9. Phase 10 will, but that's downstream. | none |
| OS-registered state | None — no launchd plist needs touching; the new `Schedule::call` entry in `routes/console.php` for the hourly snooze-revival sweep runs inside the existing `schedule:work` daemon Phase 5 wired up. | Add one new `Schedule::call(...)->name('drift-alerts.revive-snoozes')->hourly()` entry to `routes/console.php`. |
| Secrets/env vars | None — Phase 9 uses no new secrets. | none |
| Build artifacts | None — no compiled assets, no published packages. | none |

## Environment Availability

> Phase 9 inherits the existing stack; no new external dependencies.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.5 | Module code | (assumed — same as Phase 8) | ^8.5 | — |
| Laravel 13 | Framework | (assumed — same as Phase 8) | ^13.0 | — |
| SQLite 3 with WAL | DB | (assumed — Phase 1 lock) | — | — |
| Redis (Docker `diederik-redis`, loopback-bound) | `Cache::driver('redis')` for `ShouldBeUniqueUntilProcessing` lock | (assumed — Phase 5 lock; verified via `composer.json` `predis/predis` ^3.4 + Phase 5 STACK.md amendment) | n/a | — |
| Horizon | Queue supervisor for `DetectDriftAlertsJob` | (assumed — Phase 5 lock) | ^5.46 | — |

**Missing dependencies with no fallback:** none. **Missing dependencies with fallback:** none.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest ^4.0 + pest-plugin-laravel ^4.0 + pest-plugin-arch ^4.0 [VERIFIED: composer.json lines 41–43] |
| Config file | `phpunit.xml` (project root) + per-module test PSR-4 in `composer.json` autoload-dev |
| Quick run command | `vendor/bin/pest --filter=DriftAlerts` (per-module slice) |
| Full suite command | `composer test` → `pest --parallel` [VERIFIED: composer.json scripts line 71] |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| REC-06 | Drift fires above ±5% in original currency on approved/cadence_changed series | unit | `vendor/bin/pest Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php` | ❌ Wave 0 |
| REC-06 | Annualized impact = signed delta × cadence multiplier | unit (Pest dataset) | `vendor/bin/pest Modules/DriftAlerts/tests/Unit/DriftEvaluatorAnnualizedImpactTest.php` | ❌ Wave 0 |
| REC-06 | FX-only swing produces ZERO alerts (D-909) | unit + contract | `vendor/bin/pest tests/Contracts/DriftDetectionContractTest.php --filter="fx-only swing"` | ❌ Wave 0 |
| REC-06 | `prior_amount = 0 OR NULL` produces ZERO alerts | unit | `vendor/bin/pest Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php --filter="prior null"` | ❌ Wave 0 |
| REC-06 | Per-series threshold override beats user global default | unit | `vendor/bin/pest Modules/DriftAlerts/tests/Unit/DriftEvaluatorEffectiveThresholdTest.php` | ❌ Wave 0 |
| REC-07 | `/drift` page lists open alerts sorted by `detected_at DESC` grouped by series | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/DriftPageTest.php` | ❌ Wave 0 |
| REC-07 | Dashboard count badge renders open count > 0 | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/DashboardDriftBadgeTest.php` | ❌ Wave 0 |
| REC-07 | Top-nav reflects open count via View Factory composer | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/TopNavDriftBadgeTest.php` | ❌ Wave 0 |
| REC-07 | Open alert persists across page reloads + login (no auto-expiry) | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/DriftAlertPersistenceTest.php` | ❌ Wave 0 |
| REC-08 | Acknowledge writes one transition row + actioned_at + dispatches event | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/AcknowledgeDriftAlertTest.php` | ❌ Wave 0 |
| REC-08 | Snooze sets snoozed_until + revives after expiry | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/SnoozeDriftAlertTest.php` | ❌ Wave 0 |
| REC-08 | Dismiss-as-cancelled writes transition + dispatches DriftAlertDismissedCancelled | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/DismissDriftAlertAsCancelledTest.php` | ❌ Wave 0 |
| REC-08 | Every action writes exactly one `drift_alert_transitions` row | feature | (same as above; assertions count transitions table) | ❌ Wave 0 |
| REC-08 | Cross-user 404 on every action | feature | `vendor/bin/pest Modules/DriftAlerts/tests/Feature/DriftAlertCrossUser404Test.php` | ❌ Wave 0 |
| All | Four BoundaryArchTest invariants stay green | arch | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` | ✅ (file exists; new invariants added in Wave 0) |
| All | DriftEvaluator never imported from `Modules\DriftAlerts\Internal\Http\*` (noSynchronousDriftDetectionInRequestLifecycle) | arch | (within BoundaryArchTest) | ❌ Wave 0 |
| All | Synthesised fixture corpus | contract | `vendor/bin/pest tests/Contracts/DriftDetectionContractTest.php` | ❌ Wave 0 |

### Wave 0 Fixture Corpus

Mirrors Phase 5 D-107 + Phase 6 D-140 + Phase 8 D-845 precedent. Lives in `Modules/DriftAlerts/tests/fixtures/`. Each scenario is a self-contained recurring_series row + 2–N occurrence rows + expected drift_alerts outcome (an array of `{state, baseline, latest, delta, annualized, threshold_used, threshold_source}` tuples — empty array = no alert).

| Scenario | Description | Expected Outcome |
|----------|-------------|------------------|
| `stable-monthly` | Netflix €9.99 × 6 monthly | ZERO alerts |
| `small-drift-below-threshold` | Spotify €9.99 → €10.20 (+2.1%) monthly with default 5% threshold | ZERO alerts |
| `large-drift-above-threshold` | Spotify €9.99 → €11.49 (+15%) monthly | 1 alert, direction=expense, +€18/yr annualized, threshold_used=5, threshold_source=global |
| `income-raise` | Salary €3500 → €3650 (+4.3%) monthly with default 5% threshold | ZERO alerts |
| `income-raise-large` | Salary €3500 → €3850 (+10%) monthly | 1 alert, direction=income, +€4200/yr annualized |
| `income-cut` | Salary €3500 → €3325 (-5.0%) monthly | 1 alert, direction=income, signed −€2100/yr annualized |
| `fx-only-swing` | Netflix $11.99 stable × 6 monthly, EUR-settled drifts from €11.20 to €10.80 due to FX | ZERO alerts (D-909) |
| `cadence-changed` | Subscription on `cadence_changed` state with +10% drift | 1 alert (D-910) — fires alongside cadence flip; both surfaces are independent |
| `multi-drift` | Spotify €9.99 → €10.99 → €11.99 over 3 sweeps | 2 alerts (D-904 queue-all-as-open), both with `state='open'` until user acts |
| `per-series-override` | Electricity €120 → €150 (+25%) with `recurring_series.drift_threshold_percent = 50` | ZERO alerts (override beats global default; D-915) |
| `prior-null` | First detected occurrence; no prior | ZERO alerts (Pitfall 4 guard) |
| `prior-zero` | Prior occurrence amount is 0 cents | ZERO alerts (Pitfall 4 guard) |
| `volatile-series` | Variable monthly bill with ±30% natural variance, default 5% threshold | Multiple alerts; documents the alert-noise UX problem |
| `volatile-with-override` | Same volatile bill with `recurring_series.drift_threshold_percent = 50` | ZERO alerts |
| `weekly-cadence` | Streaming credit €10/wk → €11/wk (+10%) | 1 alert, annualized = €52/yr (weekly multiplier verified) |
| `quarterly-cadence` | Insurance €240/qtr → €270/qtr (+12.5%) | 1 alert, annualized = +€120/yr |
| `yearly-cadence` | Domain renewal €10/yr → €15/yr (+50%) | 1 alert, annualized = +€5/yr |
| `mixed-currency-stable-usd` | USD $11.99 stable × 6 monthly (no EUR drift either) | ZERO alerts |
| `mixed-currency-real-usd-drift` | USD $11.99 → $14.99 (+25%) monthly | 1 alert, baseline_currency=USD, delta_minor signed in USD cents |
| `pending-state-ignored` | Pending (un-approved) series with +20% drift | ZERO alerts (D-911) |
| `rejected-state-ignored` | Rejected series with +20% drift | ZERO alerts (D-911) |
| `snoozed-at-series-level-ignored` | Recurring-side snoozed series with +20% drift | ZERO alerts (D-911) |
| `irregular-cadence-ignored` | Series with `cadence='irregular'` and +20% drift | ZERO alerts (D-911) |
| `snooze-expiry-revival` | Acknowledged-then-snoozed alert; snoozed_until past | Revival sweep flips alert back to `state='open'`; transition row records `transition_reason='detector_revived_snooze'` |

### Sampling Rate (Nyquist Dimension 8)

- **Per task commit:** `vendor/bin/pest --filter=DriftAlerts` (per-module slice; runs in ≤ 5s)
- **Per wave merge:** `vendor/bin/pest --parallel` (full suite green before next wave)
- **Phase gate:** Full suite green + Larastan green + Pint green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `Modules/DriftAlerts/composer.json` — module manifest
- [ ] `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php` — module provider
- [ ] `Modules/DriftAlerts/tests/Pest.php` — per-module Pest entry (documented inert)
- [ ] `composer.json` autoload-dev — `Modules\\DriftAlerts\\Tests\\` PSR-4 entry
- [ ] `phpunit.xml` — new testsuite entry for `Modules/DriftAlerts/tests`
- [ ] `tests/Pest.php` — per-module wire-up row
- [ ] `tests/Contracts/BoundaryArchTest.php` — four new arch invariants (D-902 ① ② ③ ④) + the fifth `noOtherDriftAlertStateMutator` invariant + the `DetectDriftAlertsJob` carve-out in the `noFacadeCallsFromRecurring`-style ignore list (the `Cache` facade carve-out)
- [ ] `tests/Contracts/DriftDetectionContractTest.php` — end-to-end contract test scaffold
- [ ] `Modules/DriftAlerts/tests/fixtures/drift-corpus/` — fixture data (24 scenarios)
- [ ] `Modules/Recurring/Public/Events/RecurringSeriesMetricsRefreshed.php` — NEW Public event surface
- [ ] `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php` — subscriber

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Drift detection as a synchronous post-import step | Drift detection as a queued listener on a Public domain event | Phase 5+ (Horizon + Redis amendment) | Phase 9 follows the project-wide event-driven idiom; never blocks the import path |
| Drift state lived as a column on `recurring_series` (`in_drift` flag) | Per-event `drift_alerts` rows with full state-machine lifecycle | Phase 9 D-904 (CONTEXT decision) | Honest history of every drift event survives forever; one row per occurrence-crossing-threshold |
| Snooze-revival via query-time conditional | Scheduled sweep mirroring Phase 8's `expireSnoozes` pattern + secondary query-time conditional for honest count freshness | Phase 9 D-925 (research resolution) | Count badge stays honest within 1 hour of snooze expiry; full audit row writes happen lazily on the next sweep |

**Deprecated/outdated:** none — Phase 9 introduces no new patterns and deprecates nothing.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The Phase 8 `monthly_equivalent_minor` column is denominated in `latest_currency` (not always EUR) | Pitfall 1 + CancellationImpactQuery design | Wrong currency in Phase 10 forecasting; mis-reported "save €X/yr" on a USD subscription. Verified via `Modules/Recurring/Internal/Mapping/RecurringSeriesDtoMapper.php` line 46–49 — claim is `[VERIFIED]` not `[ASSUMED]`. |
| A2 | `RecurringSeriesQuery::occurrencesForSeries` exists and returns rows ordered by `observed_at DESC, id DESC` | DriftEvaluator design | If the method's order semantics flip in the future, the "latest two occurrences" lookup returns wrong rows. Verified via `Modules/Recurring/Public/Services/RecurringSeriesQuery.php` lines 112–146 — claim is `[VERIFIED]` not `[ASSUMED]`. |
| A3 | A new method `RecurringSeriesQuery::approvedAndCadenceChangedForUser(User $user): list<RecurringSeriesDto>` does NOT yet exist | Phase 8 surface inventory | If the planner assumes it exists and DriftAlerts depends on it, the plan misses a small Recurring-side addition. Verified via `grep -n "approvedAndCadenceChanged\|approvedForUser\|cadenceChangedForUser" Modules/Recurring/Public/Services/RecurringSeriesQuery.php` — only `approvedForUser` and `cadenceChangedForUser` exist as separate methods; `approvedAndCadenceChangedForUser` is `[ASSUMED]` NOT to exist and the Wave 0 plan adds it (or the DriftEvaluator stitches the two calls). |
| A4 | The Phase 8 detectors will pick up the new `$this->events->dispatch(new RecurringSeriesMetricsRefreshed(...))` call site without breaking existing tests | Recurring-side event surface addition | If the dispatcher's call shape changes between Laravel 12 and 13, the planner needs to verify. Phase 8 ships on Laravel 13; the dispatcher API is stable. `[VERIFIED]` against existing Phase 8 detector code (`$this->events->dispatch(new RecurringSeriesCadenceFlipped(...))` at line 394). |
| A5 | Flux UI 2.x ships no accordion / disclosure / collapsible primitive | D-930 resolution | The recommended Alpine fallback is suboptimal if a missed Flux release adds the primitive. Verified via `ls vendor/livewire/flux/stubs/resources/views/flux/` — only `callout` / `card` / `table` exist. `[VERIFIED]`. |
| A6 | Phase 8's `monthly_equivalent_minor` weekly multiplier is `× 52 / 12` (NOT `× 4.33`) | D-924 resolution | Wrong multiplier causes `series.monthly_equivalent_minor × 12` and `delta × weekly_multiplier` to disagree at the fixture level. Verified via `ExpenseSeriesDetector::monthlyEquivalent` line 439 — `(int) round($latestAmountMinor * 52 / 12)`. The Phase 8 CONTEXT.md mentions `× 4.33` colloquially but the CODE uses `× 52 / 12`. `[VERIFIED]`. Phase 9 weekly-cadence-to-year multiplier MUST be exactly `× 52` (not 52.18) to stay consistent. |
| A7 | Phase 5's Horizon + Redis infrastructure handles Phase 9's added per-(user, series) lock cardinality | Capacity / queue capacity | If the user has 200 approved series and a sweep fires, the queue gets 200 dispatched jobs (most collapse under `ShouldBeUniqueUntilProcessing` if duplicate keys fire fast, but the queue table briefly grows). Acceptable for a single-user app; documented for future scaling consideration. `[ASSUMED]`. |
| A8 | The fifth arch invariant `noOtherDriftAlertStateMutator` (state-machine sole-mutator) is in-scope despite D-902 enumerating only four | Boundary protection | If the planner ships only the 4 invariants verbatim from D-902, the state-machine sole-mutator pattern is enforced only at the SQL trigger level, not at the static-analysis level. The `recurring_series_state` precedent in Phase 8 has BOTH. `[ASSUMED]` — Phase 9 plan should include the 5th invariant for parity. |

## Open Questions

1. **D-927 placement of "Drift" count chip — own slot vs sub-chip on existing "Recurring" slot.**
   - What we know: Top-nav has 9 slots; D-853 already flagged crowding. The composer pattern allows EITHER without code restructuring.
   - What's unclear: User aesthetic preference (calm vs glanceable).
   - Recommendation: Phase 9 plan ships the QUERY (`DriftAlertQuery::openCountForUser`) + the composer registers `driftOpenCount`. UI-SPEC pass picks the final visual; the data is ready for either choice. (Pass through to discuss-phase if UI-SPEC is not yet wired.)

2. **D-925 mechanism — pure scheduled vs hybrid.**
   - What we know: Phase 8 `expireSnoozes` runs only inside the daily sweep, so a snooze that expires at 00:00 stays "snoozed" in the count badge until the 02:00 sweep.
   - What's unclear: Whether a 2-hour count-badge staleness window is acceptable, or whether the hybrid (scheduled + query-time conditional) is worth the complexity.
   - Recommendation: Ship the hybrid (recommended in Pitfall 5). Cost is one extra `WHERE` clause in `DriftAlertQuery::openCountForUser` and `openForUser`; benefit is honest count freshness.

3. **D-929 `threshold_source` enum.**
   - What we know: Adding the column is one schema line + one assignment in `DriftEvaluator`.
   - What's unclear: Whether the audit trail benefit outweighs the schema surface area.
   - Recommendation: Ship the `threshold_source` column. The first debug "why did this fire?" question resolves to a 1-byte column lookup instead of cross-referencing the user's settings row + the series row at the time of the alert (the user may have changed the global default since).

4. **D-922 home of `DriftEvaluator` — `Modules/Recurring/Internal/` vs `Modules/DriftAlerts/Internal/`.**
   - What we know: The arch test `crossModuleAccessGoesThroughPublic` (D-902 ③) requires every `Modules\DriftAlerts\*` import of `Modules\Recurring\*` go through `Modules\Recurring\Public\*`. The `RecurringSeriesQuery::occurrencesForSeries` Public method already exists.
   - What's unclear: Whether a direct `recurring_series_occurrences` Eloquent read is materially faster than going through the Public Query.
   - Recommendation: **`Modules/DriftAlerts/Internal/`**. The Public Query is already the documented seam; the performance delta is negligible (a single index walk on `(recurring_series_id, observed_at DESC)`); the boundary test stays simpler.

5. **D-924 weekly multiplier — `× 52` (matches Phase 8) vs `× 52.18` (calendar-accurate).**
   - What we know: Phase 8 uses `× 52 / 12` (effectively `× 4.3333`) for `monthly_equivalent_minor` weekly conversion. `× 52` for the year multiplier is the inverse and produces an exact integer-level consistency check between `series.monthly_equivalent_minor × 12` and `delta × 52`.
   - What's unclear: Whether the ~0.35% calendar-accuracy advantage matters more than the consistency advantage.
   - Recommendation: **`× 52` for weekly cadence-to-year multiplier**. The consistency win is load-bearing for the Wave 0 fixture corpus (the `weekly-cadence` test asserts the annualized impact at the integer-cent level — using `× 52.18` would break that test).

6. **Source FX rate for the EUR shadow on a drift alert.**
   - What we know: Phase 8 uses `transaction.fx_rate_used` from the latest occurrence (D-851).
   - What's unclear: Whether Phase 9's alert row should cache the FX rate at detection time OR re-derive on every render.
   - Recommendation: **Cache `fx_rate_at_detection` (nullable string mirroring `recurring_series.latest_fx_rate_used`)** on the `drift_alerts` row. Display layer reads it directly; future FX-rate changes don't retroactively shift the alert's reported EUR shadow. Honest snapshot semantics matching the audit-trail philosophy.

7. **`AcknowledgeDriftAlert` reversibility.**
   - What we know: D-906 says acknowledge has NO special effect on future detection beyond closing the alert. The state machine has `acknowledged` as a terminal state (no `acknowledged → *` transitions in the recommended ALLOWED_TRANSITIONS).
   - What's unclear: Whether a "un-acknowledge" affordance is worth the surface area.
   - Recommendation: **Terminal acknowledged**. No un-acknowledge in v1. Mirrors Phase 8 reject-then-unreject UX where reject is permanent until un-rejected — but drift alerts are per-event so a new alert fires naturally on the next drift. Un-acknowledge would require re-opening a historically closed alert, which conflicts with the audit-trail philosophy.

## Security Domain

> Security is enabled by default unless `security_enforcement: false` in config. No such opt-out is present.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Phase 1 Fortify auth + LoopbackOnly middleware (carry-forward); every `/drift` route + Livewire endpoint is `auth`-gated |
| V3 Session Management | yes | Laravel session driver (Phase 1 standard); no Phase 9 changes |
| V4 Access Control | yes | Cross-user 404 invariant on every action + query (Phase 3-07 / 4-04 / 5-04 / 8 pattern); enforced via `->where('user_id', $user->id)` on every read + write |
| V5 Input Validation | yes | Livewire `wire:model` + Laravel validation rules on the threshold inputs (min=0, max=100); `CarbonImmutable::parse` on the snooze date with try/catch and bounded future date |
| V6 Cryptography | no | No new secrets; no new tokens |
| V8 Data Protection | yes | FND-04 BIGINT minor units; FND-03 nullable user_id + BelongsToUser trait; full-history retention per project constraint |

### Known Threat Patterns for the stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-user data leak | Information disclosure | Cross-user 404 invariant + `->where('user_id', $user->id)` on every query (defence-in-depth); `BoundaryArchTest` cross-user assertions |
| SQL injection via Livewire-bound threshold input | Tampering | Eloquent + raw `DatabaseManager` parameterized queries; Larastan strict catches any string-concat SQL |
| Race condition on concurrent state transitions | Tampering | `lockForUpdate` + `PRAGMA busy_timeout=5000` inside the state-machine transaction; mirrors Phase 8 |
| Replay attack on the snooze action | Tampering | Laravel CSRF on every Livewire action; no idempotency key needed because snooze is idempotent at the application level (D-925 + Phase 8 `SnoozeRecurringSeries` precedent) |
| Mass-assignment via the per-series threshold editor | Tampering | Eloquent `$fillable` whitelist; PHP 8.5 readonly DTOs; only `drift_threshold_percent` is writable on the inline editor |
| DoS via spam-clicking "Acknowledge all for this series" | Denial of service | `ShouldBeUniqueUntilProcessing` is for jobs; the action runs synchronously in-request. Each acknowledge is one row UPDATE + one INSERT. Acceptable load. |
| Cache-poisoning the `Cache::driver('redis')` lock | Tampering | Redis container loopback-bound only (Phase 5 invariant); no external network exposure (FND-01) |

## Sources

### Primary (HIGH confidence)

- `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php` — verbatim job pattern Phase 9 mirrors; `ShouldBeUniqueUntilProcessing` keyed by `uniqueId()` + `uniqueFor()` + `uniqueVia()` shape
- `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` — verbatim state-machine pattern Phase 9 mirrors
- `Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php` — verified the post-sweep insertion point for the new event + the `52/12` weekly multiplier
- `Modules/Recurring/Database/Migrations/2026_05_18_010001_create_recurring_series_table.php` — schema + trigger pattern
- `Modules/Recurring/Database/Migrations/2026_05_18_010002_create_recurring_series_occurrences_table.php` — substrate Phase 9 reads
- `Modules/Recurring/Database/Migrations/2026_05_18_010003_create_recurring_series_transitions_table.php` — audit table shape `drift_alert_transitions` mirrors
- `Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php` — pattern for adding the new `users.drift_alert_threshold_percent` column
- `Modules/Recurring/Public/Services/RecurringSeriesQuery.php` — confirmed `occurrencesForSeries` exists; confirmed `approvedAndCadenceChangedForUser` does NOT
- `Modules/Recurring/Internal/Mapping/RecurringSeriesDtoMapper.php` — confirmed `monthly_equivalent_minor` is denominated in `latest_currency` (NOT EUR)
- `Modules/Recurring/Providers/RecurringServiceProvider.php` — View Factory composer pattern + container tagging pattern
- `tests/Contracts/BoundaryArchTest.php` — verbatim arch-test patterns Phase 9 extends (8 carry-forward invariants + 4–5 new ones)
- `composer.json` — locked stack inventory (PHP ^8.5, Laravel ^13, Livewire ^4, Flux ^2, Horizon ^5.46, Predis ^3.4)
- `Modules/Core/Resources/views/livewire/top-nav.blade.php` — existing 9-slot inventory + the `recurringPendingCount` injection pattern
- `Modules/Core/Resources/views/livewire/settings-page.blade.php` — settings extension pattern Phase 9 follows
- `Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php` — snooze popover shape Phase 9 reuses verbatim
- `.planning/phases/08-recurring-detection-fixed-payments-view/08-CONTEXT.md` — Phase 8 anchor decisions D-810/D-815/D-826/D-833/D-842
- `.planning/phases/09-subscription-drift-detection-alerts/09-CONTEXT.md` — Phase 9 locked decisions
- `.planning/PROJECT.md` — project constraints + locked stack additions
- `.planning/REQUIREMENTS.md` — REC-06/07/08 traceability
- `.planning/ROADMAP.md` §"Phase 9" — three success criteria
- `.planning/research/SUMMARY.md` — industry-consensus drift heuristics (suggest-never-auto-apply; ±5% default)
- `.planning/research/PITFALLS.md` — floating-point money + idempotency carry-forward
- `./CLAUDE.md` — DI-only invariant + GSD-agnostic code invariant
- Laravel queues docs (laravel.com/docs/12.x/queues#unique-jobs) — `ShouldBeUniqueUntilProcessing` exact semantics (`uniqueFor` is seconds, lock releases before `handle()` begins)

### Secondary (MEDIUM confidence)

- Livewire 4 wire:poll documentation (livewire.laravel.com/docs/wire-poll) — confirmed modifiers `.15s`, `.keep-alive`, `.visible` exist; informs Pitfall 2 scoping recommendation
- `vendor/livewire/flux/stubs/resources/views/flux/` directory listing — confirmed `card` / `callout` / `table` exist; `accordion` / `disclosure` / `collapsible` do NOT (informs D-930 fallback)

### Tertiary (LOW confidence)

- None for Phase 9 — every load-bearing claim is verified against the codebase or official docs.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every package verified against `composer.json`; zero new packages introduced
- Architecture: HIGH — every pattern verified against the locked Phase 5/6/7/8 implementations
- Pitfalls: HIGH — all 6 pitfalls traced to specific code locations or specific Phase 8 decisions; the EUR-vs-latest-currency pitfall is a real latent issue in Phase 8 that Phase 9 must not propagate
- Detector insertion point (D-921): HIGH — verified by reading the existing `ExpenseSeriesDetector` and `IncomeSeriesDetector` insertion sites for `RecurringSeriesCadenceFlipped`
- Weekly multiplier consistency (D-924): HIGH — verified `× 52 / 12` literal in `ExpenseSeriesDetector::monthlyEquivalent`
- Snooze-revival mechanism (D-925): MEDIUM — recommendation depends on whether the user accepts 2-hour count-badge staleness; if so, the simpler pure-scheduled posture is fine; the hybrid is recommended for honest count freshness
- Top-nav placement (D-927): MEDIUM — depends on UI-SPEC's verdict; data layer is ready for either
- Route placement (D-928): HIGH — top-level `/drift` is verified against the existing `/chains/review` precedent for sibling top-level routes

**Research date:** 2026-05-17
**Valid until:** 2026-06-17 (30 days; Phase 9 is greenfield and depends only on the locked Phase 8 substrate)
