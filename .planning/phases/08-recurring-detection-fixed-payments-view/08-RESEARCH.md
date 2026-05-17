# Phase 8: Recurring Detection + Fixed Payments View — Research

**Researched:** 2026-05-17
**Domain:** Recurring-transaction clustering + cadence inference + suggest-then-approve UX (Laravel 13, Livewire 4, SQLite/WAL, brick/money) — analytical layer over a locked transaction ledger
**Confidence:** HIGH for stack patterns and architectural shape (verified against in-repo Phase 1–7 code); MEDIUM for cadence-inference algorithm specifics (industry-standard heuristics, no canonical reference); MEDIUM for ApexCharts wiring (CONTEXT.md asserts it exists in repo, but the codebase shows it has never been installed — flagged below).

<user_constraints>
## User Constraints (from CONTEXT.md)

> Every decision below was locked in `/gsd:discuss-phase` and is **non-negotiable** during planning. Research and plans MUST honor these and never reopen alternatives.

### Locked Decisions

#### Detection Trigger Model
- **D-801:** Hybrid trigger model: `Schedule::daily()` sweep + `/recurring/re-detect` button. Both dispatch the **same** `DetectRecurringSeriesJob` with `ShouldBeUniqueUntilProcessing` keyed per-user. No event-driven listener on `TransactionImported`.
- **D-802:** User-configurable detection window; default 18 months. Lives in `/settings` (`users.recurring_detection_window_months`).
- **D-803:** Minimum 2 occurrences for a candidate to appear in `/recurring/review`.
- **D-804:** Approved series have their metrics refreshed by every sweep. Day-to-day interval jitter never triggers re-approval. Only a cadence-class flip (monthly → quarterly etc.) pushes the series to `cadence_changed`.
- **D-805:** On-demand re-detect re-queues the same sweep job; user sees "detecting…" toast.

#### Approval Surface
- **D-806:** Dedicated `/recurring/review` queue. `/recurring` shows only approved series. Pending-count badge in top-nav (View Factory composer).
- **D-807:** Four actions on a pending suggestion: Approve / Reject / Edit name / Snooze.
- **D-808:** Reject is permanent until un-rejected; lives in a "Rejected" tab on `/recurring/review`.
- **D-809:** Dashboard surfacing = top-nav badge + dashboard inline "Fixed monthly payments" card.
- **D-810:** Snooze uses date picker (1 week / 1 month / 3 months / custom). Reused by Phase 9 drift-alert snooze.
- **D-811:** Approval is independent of merchant memory — approving does NOT touch `merchant_memories`.
- **D-812:** Checkbox-select + bulk Approve/Reject sticky action bar on `/recurring/review`. Bulk Snooze and bulk Edit-name are NOT in scope.
- **D-813:** Edit-name persists across re-detection — `display_name_override` overrides `detected_name`; auto-derived name keeps refreshing underneath.
- **D-814:** 10-second Undo toast on every Approve / Reject / Snooze action. Reuses Phase 4/5 toast pattern.
- **D-815:** Full state-machine history table: `recurring_series_transitions` (id, user_id, recurring_series_id, from_state, to_state, transition_reason, actor, transitioned_at, notes).

#### Income vs Expense
- **D-816:** Unified `recurring_series` table with a `direction` enum (`'expense'` / `'income'`).
- **D-817:** Income clustering = hybrid IBAN-primary + normalized-description-fallback.
- **D-818:** `/recurring` = one list with grouped sections (Expenses on top, Income below, optional Transfers collapsed). Net "monthly fixed flow" summary at the top: `−€X + €Y = €Z`. Rows sorted by monthly equivalent descending.
- **D-819:** Recurring transfers (ASN → ICS settlement) excluded from main view; visible in a collapsed "Recurring transfers" section, informational only. NOT in cash-flow totals.
- **D-820:** Income minimum threshold: user-configurable, default €2000 (salary-sized). Lives in `users.recurring_income_min_amount_minor` (BIGINT minor units, FND-04).
- **D-821:** Each distinct IBAN cluster becomes its own series (no "merge" UI in v1).
- **D-822:** Income detector runs ONLY on `transaction.type='income'` (trusts upstream Phase 4 LED-05). Never writes `transaction.type`.
- **D-823:** Rejecting a series does NOT change underlying transactions' type or category.

#### Drift Display + Variance Tolerance
- **D-824:** Amount column on `/recurring` shows latest amount + small drift indicator chip.
- **D-825:** Per-series `variance_tolerance_percent` column, default 25. User-editable inline. Clustering tolerates `latest_amount × (1 ± tolerance)`.
- **D-826:** Monthly equivalent = latest occurrence amount × cadence multiplier. Multipliers: weekly × 4.33, monthly × 1, quarterly ÷ 3, yearly ÷ 12.
- **D-827:** Drill-in `/recurring/series/{id}` = full ApexCharts line/bar chart + occurrences table. Original-currency primary; EUR shadow when distinct.
- **D-828:** Funding chain icon = account-icon stack with chain-link badge. Click opens Phase 5 chain drawer.
- **D-829:** Funding chain shown = most-recent occurrence's chain. Fallback to previous occurrence if newest chain is unresolved (Phase 5 chain resolution is async).
- **D-830:** Next-expected-charge displayed as relative + absolute date with a low-confidence indicator (dim/italic) when cadence variance is high (stddev > 5 days across the window).

#### Module Home + Boundary Tests
- **D-831:** New `Modules/Recurring/` bounded module. Mirrors `Modules/Receipts/`, `Modules/Chains/`, `Modules/Transfers/`. Owns: `recurring_series` + `recurring_series_occurrences` + `recurring_series_transitions` tables, the sweep detector + on-demand job, the `SeriesDetector` contract + two implementations, all `/recurring*` Livewire SFCs.
- **D-832:** Public surface = Queries + DTOs + Events + Actions. Detector internals + state-machine internals stay private.
- **D-833:** Four BoundaryArchTest invariants — (1) `noFacadeCallsFromRecurring`, (2) `noTransactionWritesFromRecurring`, (3) `crossModuleAccessGoesThroughPublic`, (4) `noSynchronousDetectionInRequestLifecycle`.
- **D-834:** Recurring reads merchant categorization via a **new** `Modules\Categorization\Public\Services\MerchantMemoryQuery` capability — verify the existing class signature and extend (do NOT create a duplicate).

#### Dashboard Integration
- **D-835:** Dashboard `/` renders an inline "Fixed monthly payments" card with top ~6 series + "View all →" link. Sourced via `FixedPaymentsViewQuery` (Public).
- **D-836:** Income on dashboard surfaces via the existing in/out/remaining tile. Recurring breakdown in the card's income section.
- **D-837:** The Phase 5 "Next ICS settlement" dashboard tile stays untouched.
- **D-838:** Dashboard card carries an "All series / This month only" toggle.

#### Multi-currency Series
- **D-839:** Clustering happens on original-currency + amount, not settled EUR.
- **D-840:** Monthly equivalent renders original-currency primary + EUR shadow.
- **D-841:** Dashboard "total monthly fixed" = single EUR sum using each series' latest FX rate.
- **D-842:** FX drift shown prominently in drill-in only; never on `/recurring` amount column unless original-currency price actually moves.

#### Cadence Detection + Fixtures
- **D-843:** Cadence inference = median interval + nearest-class snap. Snap bands: <10d=weekly, 10–45d=monthly, 80–100d=quarterly, 350–380d=yearly. Outside all bands → `irregular`, candidate skipped.
- **D-844:** Missed-occurrence tolerance: any interval > 1.8 × median counts as 1 missed period; > 2 missed in any rolling 6-period window flips approved series to `cadence_changed`.
- **D-845:** Wave 0 fixtures = controlled-time-series corpus + one anonymised real export (≥6 months). Must include: stable monthly (Spotify €9.99), drifting monthly (Spotify €9.99 → €11.49), quarterly insurance, yearly domain renewal, weekly streaming credit, monthly salary, two-employer salary, irregular gym charges (must NOT cluster), missing-month subscription (must remain one series), mixed-currency Netflix (must cluster on USD), variable-amount-beyond-tolerance bills (must fragment).
- **D-846:** Tests = unit (`Modules/Recurring/tests/Unit/CadenceInferenceTest.php` with Pest dataset) + contract (`tests/Contracts/RecurringDetectionContractTest.php` over the Wave 0 corpus).

### Claude's Discretion (planner picks within these)

- **D-847:** Wave structure — suggested 5-wave shape (skeleton → migrations + state machine → expense detector + review queue → income detector + `/recurring` page → drill-in + dashboard + bulk actions + drift chip). Planner re-validates by goal-backward analysis.
- **D-848:** Exact storage shape for `recurring_series.detected_cadence` — string vs enum column.
- **D-849:** Container-tag name for `SeriesDetector` implementations (suggested `'recurring.detector'`).
- **D-850:** Exact mechanism for `noSynchronousDetectionInRequestLifecycle` arch test — marker interface vs callsite assertion.
- **D-851:** Exact FX rate source for EUR shadow — per-occurrence `transaction.fx_rate_used` (recommended below) vs latest-across-currency.
- **D-852:** "Recurring transfers" section default collapse state — auto-collapsed first visit only vs always-collapsed.
- **D-853:** Top-nav slot positioning + potential submenu grouping if `/rules` + `/uncategorized` + `/chains/review` + `/recurring` + `/recurring/review` overcrowd the bar.
- **D-854:** Per-series `variance_tolerance_percent` editor input — slider / numeric / dropdown.
- **D-855:** Dashboard "This month only" toggle persistence — user setting vs `#[Url]` query string.

### Deferred Ideas (OUT OF SCOPE — never mention in plans)

- "Create rule from this series" affordance (v2)
- "Merge series" action for multi-payroll / currency-switch (v2)
- Bulk Edit-name (v2 nicety)
- Adaptive variance tolerance (v2 — v1 ships per-series tolerance editor only)
- Forecasting baseline integration (Phase 10 consumes `FixedPaymentsViewQuery::monthlyEquivalentTotals()` — Phase 8 ships the API; Phase 10 ships the consumer)
- Drift alerts surface (REC-06/07/08 → Phase 9)
- Series export to CSV/JSON (v2)
- Per-merchant "always cluster" / "never cluster" hint (v2)
- Annual recurring detection improvements (REQUIREMENTS.md v2; Phase 8 ships yearly cadence but stays conservative)
- Sub-weekly cadence — explicitly out of scope; cadence-class set is `weekly`/`monthly`/`quarterly`/`yearly` only
- "Why is this a series?" explanation panel (v2)
- Push notifications (PLT-01 localhost-only)
- Onboarding wizard for first big backfill (v2)
- Series tagging / custom user labels (v2)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| REC-01 | Detect recurring transactions by clustering on normalized merchant + inferring cadence (weekly / monthly / quarterly / yearly) | `ExpenseSeriesDetector` clusters on `(merchant, original_currency)`; `CadenceInferrer` runs median-interval-snap algorithm — see Pattern 2 + Pattern 4 |
| REC-02 | Tolerate moderate amount variance (±25%) so Spotify €9.99 → €11.49 stays one series | Per-series `variance_tolerance_percent` column (D-825); detector clusters within `latest_amount × (1 ± tolerance)` band — Pattern 4 |
| REC-03 | User approves detected series before they appear on the fixed-payments view (suggest-never-auto-apply) | `/recurring/review` Livewire SFC mirrors `/chains/review` (Phase 5); `RecurringSeriesStateMachine` only flips `pending → approved` on explicit user action — Pattern 5 |
| REC-04 | Fixed-monthly-payments overview shows name, normalized monthly equivalent, funding source + chain icon, category, next-expected charge | `/recurring` Livewire SFC + `FixedPaymentsViewQuery` Public service + chain icon via `Modules\Chains\Public\Services\ChainLinkQuery` + category via new `MerchantMemoryQuery` cap (D-834) — Pattern 6 |
| REC-05 | Drill into any fixed payment to see all historical occurrences and amount-drift trend | `/recurring/series/{id}` Livewire SFC + `RecurringSeriesQuery::occurrencesForSeries()` + ApexCharts line/bar chart — Pattern 7 (ApexCharts gap flagged in Wave 0) |
| LED-06 | Recurring income detected the same way recurring expenses are | Same `SeriesDetector` contract; `IncomeSeriesDetector` clusters on counterparty IBAN with normalized-description fallback over `transaction.type='income'` rows — Pattern 4 |
| UI-03 | From any fixed payment, user can drill into its history and amount-drift trend | `/recurring/series/{id}` route + chart-rendered ApexCharts amount-over-time — Pattern 7 |
</phase_requirements>

## Summary

Phase 8 ships a new `Modules/Recurring/` bounded module: an analytical layer that clusters existing transactions into recurring series (expense + income) on a hybrid scheduled-daily + user-on-demand trigger, persists candidates as suggestions in a state machine, and surfaces approved series on a curated `/recurring` view with drill-in `/recurring/series/{id}` (UI-03). The detector NEVER writes the `transactions` table — a load-bearing boundary enforced by a new `noTransactionWritesFromRecurring` Pest arch test.

The module mirrors three locked-in patterns from prior phases: (1) **Public/Internal split with composer.json + ServiceProvider singleton bindings** (`Modules/Chains/`, `Modules/Receipts/`); (2) **state-machine sole-mutator invariant** (`InboxScanStateMachine` from Phase 6 — the only legal writer of its target columns, enforced by a Pest arch test); and (3) **review-queue UX** (`/chains/review` Livewire SFC with action-method DI injection — Phase 5). Three new migrations land — `recurring_series`, `recurring_series_occurrences`, `recurring_series_transitions` — plus two new `users` columns (`recurring_detection_window_months`, `recurring_income_min_amount_minor`).

**Primary recommendation:** Build the module skeleton + four boundary arch tests + Wave 0 fixture corpus FIRST (Wave 0), then ship migrations + state machine + Public DTOs/Actions/Events skeleton (Wave 1), then the expense detector + `/recurring/review` queue (Wave 2), then income detector + `/recurring` page (Wave 3), then drill-in chart + dashboard card + bulk actions + Undo toast (Wave 4). Block on the **ApexCharts gap discovered during research** — the project has never installed it despite repeated CONTEXT.md references; Wave 0 must add the npm install + Vite wiring or Wave 4 will stall.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|--------------|----------------|-----------|
| Recurring detection (cadence inference + clustering) | API / Backend (Internal) | Queue worker | Heavy analytical work over a transactions-table window; must never run in the HTTP request lifecycle (D-833 invariant #4) |
| State-machine transitions (pending → approved / rejected / snoozed / cadence_changed) | API / Backend (Internal) | Database | Sole-mutator state machine writes both the series row + audit row in a SQLite transaction with `lockForUpdate` + `busy_timeout=5000` (Phase 6 InboxScanStateMachine pattern) |
| Public read APIs (`RecurringSeriesQuery`, `FixedPaymentsViewQuery`) | API / Backend (Public) | — | Phase 9 + Phase 10 consume only the Public surface; internal queries stay private |
| `/recurring` + `/recurring/review` + `/recurring/series/{id}` UI | Frontend Server (Livewire SFC) | Browser (Alpine) | Server-rendered Blade with `wire:click` actions + Alpine toast handler (Phase 4/5 precedent) |
| Drill-in chart rendering | Browser (ApexCharts) | Frontend Server (data feed) | ApexCharts runs client-side; server feeds it the amount-trend dataset via Blade JSON injection |
| Dashboard "Fixed monthly payments" card | Frontend Server (Blade View composer) | — | View Factory composer pattern from Phase 5 (issue #12 fix — `view()->composer` global helper forbidden) |
| Top-nav "Recurring" pending-count badge | Frontend Server (Blade View composer) | — | Same composer pattern, single COUNT against `recurring_series` indexed on `(user_id, state)` |
| Scheduled detector dispatch | Background (`Schedule::daily()` + Horizon queue) | OS (launchd `schedule:work` from Phase 6) | Existing scheduler infra; Phase 8 adds one `Schedule::call(...)` entry to `routes/console.php` |
| Storage | Database (SQLite WAL) | — | Three new tables + two new columns on `users`; same WAL-mode patterns as Phases 1–7 |

## Project Constraints (from CLAUDE.md)

> Treat with the same authority as locked decisions. Plans MUST honor these.

- **DI-only invariant**: Constructor injection only. NO facades (`Auth::`, `DB::`, `Cache::`, `Queue::`, etc.) and NO global helpers (`auth()`, `request()`, `config()`, `view()`) inside `Modules/Recurring/`. The one carve-out the project tolerates is `Cache::driver('redis')` inside a queued job's `uniqueVia()` because Laravel calls it before constructor DI completes — but Phase 8's `DetectRecurringSeriesJob` MUST be added to the `BoundaryArchTest` facade carve-out list explicitly when it ships.
- **Codebase stays GSD-agnostic**: No `.planning/`, `PLAN.md`, `RESEARCH.md`, `D-8xx`, `REC-0x` references in runtime PHP, Blade, comments, or PHPDoc. Rationale only — never the planning code.
- **Docs describe current state, never history**: No "I changed this because X" PHPDoc. Describe what the code does NOW.
- **Fix every severity, not just blockers**: Address BLOCKER + WARNING + INFO together.
- **Eloquent direct OK; raw `DatabaseManager` for whereBetween/whereIn**: `RecurringSeries::query()->where('user_id', $user->id)` allowed; `DB::table(...)` forbidden; constructor-inject `DatabaseManager` for raw query-builder shapes (matches existing `Modules\Categorization\Public\Services\MerchantMemoryQuery` precedent).
- **Stack pinned to Laravel 13 / PHP 8.5**: `composer.json` already requires `^8.5` and `laravel/framework: ^13.0`. Phase 8 adds NO new composer dependencies (verified — every library in the Standard Stack table below is already installed).

## Standard Stack

### Core (already installed — verify and reuse, do not bump)

| Library | Installed Version | Purpose | Why Standard | Provenance |
|---------|-------------------|---------|--------------|------------|
| `laravel/framework` | `^13.0` (resolved per composer.lock) | Framework | Project pin | `[VERIFIED: composer.json line 13]` |
| `livewire/livewire` | `^4.0` | Reactive UI for `/recurring`, `/recurring/review`, `/recurring/series/{id}` | Pinned project-wide; SFCs from Phase 4 onward | `[VERIFIED: composer.json line 17]` |
| `livewire/flux` | `^2.0` | Headless component library — popover (snooze date picker D-810), dropdown, table, chips, button stack | Pinned project-wide | `[VERIFIED: composer.json line 16]` |
| `laravel/horizon` | `^5.46` | Redis-backed queue manager for `DetectRecurringSeriesJob` | Installed in Phase 5; `ShouldBeUniqueUntilProcessing` already proven on `ResolveChainLinksJob` | `[VERIFIED: composer.json line 15]` |
| `predis/predis` | `^3.4` | Redis client | Phase 5 STACK flip | `[VERIFIED: composer.json line 19]` |
| `nwidart/laravel-modules` | `^13.0` | Bounded modules — `Modules/Recurring/` registered via module.json | Project pin | `[VERIFIED: composer.json line 18]` |
| `brick/money` | `^0.11` | Money arithmetic — variance bands, monthly-equivalent multipliers (D-826) | Project pin; Phase 1 FND-07 invariant | `[VERIFIED: composer.json line 8]` |
| `spatie/laravel-data` | `^4.0` | Typed DTOs (`RecurringSeriesDto`, `RecurringOccurrenceDto`, `NextExpectedChargeDto`, `RecurringSeriesAmountTrendDto`) | Project pin; every Phase 1–7 Public DTO extends `Spatie\LaravelData\Data` | `[VERIFIED: composer.json line 20 + MerchantMemoryDto.php]` |
| `nesbot/carbon` (shipped with Laravel) | bundled | Cadence math — diff in days, interval intervals, date arithmetic | Default in Laravel 13; `CarbonImmutable` already used in `ChainLinkQuery` | `[VERIFIED: ChainLinkQuery.php line 7]` |

### Supporting (already installed)

| Library | Purpose for Phase 8 | When to Use |
|---------|--------------------|-------------|
| `pestphp/pest` `^4.0` + `pest-plugin-arch` | Unit tests for `CadenceInferrer`, the four new arch invariants | Every Pest test in this phase |
| `pest-plugin-snapshots` `^2.0` | Snapshot rendering of `/recurring` row layout (UI-stable smoke tests) | UI feature tests if useful |
| `larastan/larastan` `^3.0` + `phpstan/phpstan-strict-rules` + `canvural/larastan-strict-rules` | Static analysis at level max | Every `composer analyse` run |
| `laravel/pint` | Code style | Every commit |

### NOT installed but required by CONTEXT.md — flagged for Wave 0

| Library | Why Needed | Action |
|---------|-----------|--------|
| **ApexCharts** (npm: `apexcharts` + optionally `apexcharts.js`) | D-827 mandates a full chart on `/recurring/series/{id}`. CONTEXT.md `<canonical_refs>` calls ApexCharts "the project's locked chart library" — but `package.json` lacks any chart dependency and no Blade/JS file in the repo currently references ApexCharts. | **Wave 0 must add** `npm i apexcharts` + the Alpine glue snippet OR pick an alternative. See "Open Questions" — escalate to the user before locking. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff | Recommendation |
|------------|-----------|----------|----------------|
| ApexCharts | Chart.js | Smaller bundle but less polished defaults | Use ApexCharts unless user objects — matches CONTEXT.md stated intent |
| ApexCharts | Tailwind-only sparkline (CSS `path` + Blade) | Zero npm dependency; calmer aesthetic; matches Linear/Notion brief | **Worth surfacing as an option** — UI-SPEC pass owns this if user reconsiders |
| Pure ApexCharts | `asantibanez/livewire-charts` | Composer wrapper for ApexCharts — Livewire-aware | Adds a transitive dependency; bare ApexCharts via Blade JSON + Alpine `init` is simpler |
| Median-interval-snap (D-843) | KS / chi-squared statistical fit | Overkill for the data volume; locked out per D-843 | — |
| Mode (most-common interval) | Brittle on small sample (n=2 or 3) — every value is a mode | Locked out per D-843 | — |

**No new composer dependencies required** — the entire Phase 8 stack reuses what's already installed.

**Version verification (run before plan locks):**

```bash
composer show laravel/framework livewire/livewire livewire/flux laravel/horizon brick/money spatie/laravel-data nwidart/laravel-modules pestphp/pest 2>&1 | grep -E "^(name|versions)"
```

## Package Legitimacy Audit

**No new composer or npm packages will be installed by Phase 8 PHP code.** The ApexCharts npm package (if accepted) is the only new dependency under consideration.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `apexcharts` | npm | 8+ yrs (since 2018) | ~700K/wk (per npm) | `github.com/apexcharts/apexcharts.js` | not run (Wave 0 decision) | **Tentative — pending user re-confirmation that ApexCharts is acceptable given it has not actually been installed in any earlier phase** |

slopcheck was not run in this research session because no new PHP package is being introduced. If the user accepts ApexCharts:

```bash
pip install slopcheck --break-system-packages 2>/dev/null || true
slopcheck install apexcharts --json  # Run as part of Wave 0
npm view apexcharts version  # Verify registry presence
```

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none — but `apexcharts` is tagged `[ASSUMED]` pending Wave 0 verification and user confirmation that the project actually wants a heavy JS chart library on a "calm Linear/Notion aesthetic" page.

## Architecture Patterns

### System Architecture Diagram

```
                            ┌──────────────────────────────┐
                            │  HTTP Request (loopback)     │
                            └──────────────┬───────────────┘
                                           │
            ┌──────────────────────────────┼─────────────────────────────────┐
            │                              │                                 │
   GET /recurring                  GET /recurring/review              GET /recurring/series/{id}
            │                              │                                 │
            ▼                              ▼                                 ▼
┌──────────────────────────┐  ┌────────────────────────────┐  ┌───────────────────────────────┐
│ RecurringPage SFC        │  │ RecurringReviewPage SFC    │  │ RecurringSeriesDetailPage SFC │
│ (one list, grouped)      │  │ (pending + rejected tabs,  │  │ (chart + occurrences table)   │
│                          │  │  bulk-action bar)          │  │                               │
└──────────────┬───────────┘  └────────────┬───────────────┘  └──────────────┬────────────────┘
               │                           │                                 │
               ▼                           ▼                                 ▼
┌────────────────────────────────────────────────────────────────────────────────────────────┐
│  Public surface — Modules\Recurring\Public\*                                                │
│  Queries:    RecurringSeriesQuery, FixedPaymentsViewQuery                                   │
│  Actions:    ApproveRecurringSeries, RejectRecurringSeries, SnoozeRecurringSeries,          │
│              EditRecurringSeriesName, UnRejectRecurringSeries                               │
│  Events:     RecurringSeriesDetected, …Approved, …Rejected, …CadenceFlipped                 │
│  DTOs:       RecurringSeriesDto, RecurringOccurrenceDto, NextExpectedChargeDto,             │
│              RecurringSeriesAmountTrendDto                                                  │
│  Contracts:  SeriesDetector  (interface implemented by Internal detectors)                  │
└────────────────────────────────────────┬───────────────────────────────────────────────────┘
                                         │
                                         ▼
┌────────────────────────────────────────────────────────────────────────────────────────────┐
│  Internal — Modules\Recurring\Internal\*                                                    │
│                                                                                             │
│  RecurringSeriesStateMachine ◄─────┐     Container-tagged 'recurring.detector':             │
│   (sole mutator of recurring_      │       ExpenseSeriesDetector  (reads transactions      │
│    series.state + the audit row)   │         WHERE type IN expense/fee/refund clusters      │
│           ▲                        │         on merchant + original_currency)               │
│           │                        │       IncomeSeriesDetector  (reads transactions        │
│           │                        │         WHERE type='income' clusters on counterparty   │
│           │                        │         IBAN + normalized-description fallback)        │
│           │                        │                                                        │
│           │                        ▼                                                        │
│  CadenceInferrer  ◄──── DetectRecurringSeriesJob  ◄─── Schedule::daily()                    │
│   (median-snap + missed-tol)        (ShouldBeUniqueUntilProcessing per user)                │
│                                       ▲                                                     │
│                                       │ dispatched on-demand                                │
│                                       │                                                     │
│                                  /recurring/re-detect button                                │
└────────────────────────────────────────┬───────────────────────────────────────────────────┘
                                         │
                                         ▼
            ┌────────────────────────────────────────────────────────┐
            │  SQLite (WAL)                                          │
            │  recurring_series                                       │
            │  recurring_series_occurrences                           │
            │  recurring_series_transitions                           │
            │  + users.recurring_detection_window_months              │
            │  + users.recurring_income_min_amount_minor              │
            └────────────────────────────────────────────────────────┘
                                         │
                                         │ READ-ONLY (analytical, never writes)
                                         ▼
            ┌────────────────────────────────────────────────────────┐
            │  Existing tables (Phase 1–7) — NEVER WRITTEN BY PHASE 8 │
            │  transactions, merchants, merchant_memories, chain_links│
            │  (read via Public queries of foreign modules ONLY)      │
            └────────────────────────────────────────────────────────┘
```

### Recommended Project Structure

```
Modules/Recurring/
├── composer.json                                    # mirrors Chains/composer.json verbatim
├── module.json                                      # mirrors Categorization/module.json (priority: 5)
├── Database/
│   └── Migrations/
│       ├── 2026_05_18_010001_create_recurring_series_table.php
│       ├── 2026_05_18_010002_create_recurring_series_occurrences_table.php
│       ├── 2026_05_18_010003_create_recurring_series_transitions_table.php
│       └── 2026_05_18_010004_add_recurring_settings_to_users.php
├── Internal/                                        # blocked by Pest arch test from outside imports
│   ├── CadenceInferrer.php                          # stateless service; median-snap + missed-tol
│   ├── Detectors/
│   │   ├── ExpenseSeriesDetector.php                # implements Public/Contracts/SeriesDetector
│   │   └── IncomeSeriesDetector.php                 # implements Public/Contracts/SeriesDetector
│   ├── Http/Livewire/
│   │   ├── FixedPaymentsCard.php                    # dashboard inline card
│   │   ├── RecurringPage.php                        # /recurring
│   │   ├── RecurringReviewPage.php                  # /recurring/review (bulk-action bar)
│   │   └── RecurringSeriesDetailPage.php            # /recurring/series/{id}
│   ├── Jobs/
│   │   └── DetectRecurringSeriesJob.php             # ShouldBeUniqueUntilProcessing per-user
│   └── StateMachines/
│       └── RecurringSeriesStateMachine.php          # sole mutator of recurring_series.state +
│                                                   #   recurring_series_transitions writer
├── Models/
│   ├── RecurringSeries.php                          # final class extends Model; BelongsToUser
│   ├── RecurringSeriesOccurrence.php
│   └── RecurringSeriesTransition.php
├── Providers/
│   └── RecurringServiceProvider.php                 # singletons + tagged detectors + composer +
│                                                   #   migration/route/view loaders + Livewire
│                                                   #   component registrations
├── Public/                                          # consumed by Phase 9 + Phase 10
│   ├── Actions/
│   │   ├── ApproveRecurringSeries.php
│   │   ├── RejectRecurringSeries.php
│   │   ├── SnoozeRecurringSeries.php
│   │   ├── EditRecurringSeriesName.php
│   │   └── UnRejectRecurringSeries.php
│   ├── Contracts/
│   │   └── SeriesDetector.php
│   ├── Dto/
│   │   ├── NextExpectedChargeDto.php
│   │   ├── RecurringOccurrenceDto.php
│   │   ├── RecurringSeriesAmountTrendDto.php
│   │   └── RecurringSeriesDto.php
│   ├── Events/
│   │   ├── RecurringSeriesApproved.php
│   │   ├── RecurringSeriesCadenceFlipped.php
│   │   ├── RecurringSeriesDetected.php
│   │   └── RecurringSeriesRejected.php
│   └── Services/
│       ├── FixedPaymentsViewQuery.php
│       └── RecurringSeriesQuery.php
├── Resources/
│   └── views/
│       └── livewire/
│           ├── fixed-payments-card.blade.php
│           ├── recurring-page.blade.php
│           ├── recurring-review-page.blade.php
│           └── recurring-series-detail-page.blade.php
├── Routes/
│   └── web.php                                      # GET /recurring, /recurring/review,
│                                                   #   /recurring/series/{id}; loopback + auth
└── tests/
    ├── Pest.php                                     # inert per project convention
    ├── TestCase.php                                 # inert per project convention
    ├── Unit/
    │   ├── CadenceInferenceTest.php                 # Pest dataset (~15-20 rows)
    │   └── RecurringSeriesStateMachineTest.php
    ├── Feature/
    │   ├── RecurringPageTest.php
    │   ├── RecurringReviewPageTest.php
    │   ├── RecurringSeriesDetailPageTest.php
    │   ├── FixedPaymentsCardTest.php
    │   ├── ApproveRecurringSeriesTest.php           # + cross-user 404 invariant
    │   ├── RejectRecurringSeriesTest.php
    │   ├── SnoozeRecurringSeriesTest.php
    │   ├── EditRecurringSeriesNameTest.php
    │   └── DetectRecurringSeriesJobTest.php
    └── fixtures/
        ├── synthesised/                             # 12-fixture corpus per D-845
        │   ├── stable-monthly-spotify.php
        │   ├── drifting-monthly-spotify.php
        │   ├── quarterly-insurance.php
        │   ├── yearly-domain.php
        │   ├── weekly-streaming.php
        │   ├── monthly-salary.php
        │   ├── two-employer-salary.php
        │   ├── irregular-gym-must-not-cluster.php
        │   ├── missing-month-subscription.php
        │   ├── mixed-currency-netflix-usd.php
        │   └── variable-amount-beyond-tolerance-bills.php
        └── real/
            └── anonymised-asn-ics-6mo.php           # mined from user's own exports
```

Plus root-level changes:

```
tests/Contracts/
├── BoundaryArchTest.php                            # +4 invariants (D-833)
└── RecurringDetectionContractTest.php              # NEW — end-to-end over Wave 0 corpus
phpunit.xml                                         # +Modules/Recurring/tests/Unit + Feature
composer.json                                       # +Modules\\Recurring\\Tests\\
tests/Pest.php                                      # +per-module TestCase map row
routes/console.php                                  # +Schedule::call(...)->daily() entry
Modules/Categorization/Public/Services/
└── MerchantMemoryQuery.php                          # extend with new method(s) Phase 8 needs
```

### Pattern 1: Bounded Module Skeleton (mirror `Modules/Chains/`)

**What:** Every Phase 4+ module ships as a `nwidart/laravel-modules` bounded module with its own `composer.json`, `module.json`, `Providers/<Name>ServiceProvider.php`, and a Public/Internal directory split. Public/ is the cross-module boundary; Internal/ is private.

**When to use:** All new code in Phase 8 lives under `Modules/Recurring/`. There are no exceptions.

**Source:** `Modules/Chains/composer.json` + `Modules/Chains/Providers/ChainsServiceProvider.php` + `Modules/Categorization/module.json`. The shape is:

```jsonc
// Modules/Recurring/composer.json
{
    "name": "diederik/recurring",
    "description": "Recurring module — series detection, fixed-payments view, drill-in chart.",
    "type": "laravel-module",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Modules\\Recurring\\": ""
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Recurring\\Tests\\": "tests/"
        }
    }
}
```

```jsonc
// Modules/Recurring/module.json
{
    "name": "Recurring",
    "alias": "recurring",
    "description": "Recurring-series detector, fixed-payments view, drill-in chart.",
    "keywords": ["recurring", "series", "fixed-payments"],
    "priority": 5,
    "providers": [
        "Modules\\Recurring\\Providers\\RecurringServiceProvider"
    ],
    "files": []
}
```

The ServiceProvider register()/boot() follows `ChainsServiceProvider` verbatim — singleton-bind every service + Public Action, register Livewire components in `boot()`, register View composers for the top-nav badge + dashboard card.

### Pattern 2: Sole-Mutator State Machine (mirror `InboxScanStateMachine`)

**What:** A single class is the **only** legal mutator of a row's lifecycle column. A Pest arch test scans the module for forbidden writes and fails if any other file touches the column.

**When to use:** `RecurringSeriesStateMachine` is the only legal writer of `recurring_series.state` AND the only legal inserter of `recurring_series_transitions` rows. Pest arch invariant: `noOtherRecurringSeriesStateMutator`.

**Source:** `Modules/EmailScan/Internal/InboxScanStateMachine.php` (Phase 6) + the `noOtherInboxScanStateMutator` arch test in `tests/Contracts/BoundaryArchTest.php` lines 288–343.

```php
<?php
declare(strict_types=1);

namespace Modules\Recurring\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;
use RuntimeException;

/**
 * Single legal mutator of recurring_series.state AND the only inserter
 * of recurring_series_transitions rows. A BoundaryArchTest invariant
 * (`noOtherRecurringSeriesStateMutator`) blocks every other write path
 * under Modules/Recurring/.
 *
 * Allowed transitions (rejected -> reopened lands as a `pending` row
 * via the un-reject action so the audit chain stays linear):
 *   pending     -> approved | rejected | snoozed
 *   approved    -> cadence_changed (detector) | rejected (user)
 *   cadence_changed -> approved (user re-approve) | rejected
 *   snoozed     -> pending (when snoozed_until expires) | approved | rejected
 *   rejected    -> pending (via UnRejectRecurringSeries)
 *
 * SQLite contention guard: every write path opens a transaction, sets
 * `PRAGMA busy_timeout = 5000`, and reads the row via lockForUpdate.
 * Mirrors Phase 6 EmailScan + Phase 5 Chains patterns. The
 * busy_timeout pragma is the load-bearing fence on SQLite.
 */
final class RecurringSeriesStateMachine
{
    private const ALLOWED_TRANSITIONS = [
        'pending'         => ['approved', 'rejected', 'snoozed'],
        'approved'        => ['cadence_changed', 'rejected'],
        'cadence_changed' => ['approved', 'rejected'],
        'snoozed'         => ['pending', 'approved', 'rejected'],
        'rejected'        => ['pending'],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function transition(
        RecurringSeries $series,
        string $toState,
        string $reason,                       // 'user_action' | 'detector_cadence_flip' | 'detector_promoted' | 'snooze_expired'
        string $actor,                        // 'user' | 'detector'
        ?string $notes = null,
    ): void {
        // wraps in DB transaction with lockForUpdate + busy_timeout pragma
        // validates against ALLOWED_TRANSITIONS
        // writes recurring_series.state + inserts recurring_series_transitions row atomically
    }
}
```

### Pattern 3: Queued Job — `ShouldBeUniqueUntilProcessing` per-user

**What:** A queued job marked `ShouldBeUniqueUntilProcessing` with `uniqueId() = $userId` so spam-clicking "Re-detect now" is a no-op while the prior pass runs. Released the moment a worker begins `handle()`, so the next dispatch can queue.

**When to use:** `DetectRecurringSeriesJob` follows this exact shape. Mirror `Modules\Chains\Internal\Jobs\ResolveChainLinksJob` (Phase 5) line-for-line on the boilerplate.

**Source:** `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` lines 56–143.

```php
<?php
declare(strict_types=1);

namespace Modules\Recurring\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Public\Contracts\SeriesDetector;

final class DetectRecurringSeriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string { return (string) $this->userId; }
    public function uniqueFor(): int { return 600; }
    public function uniqueVia(): Repository
    {
        // Single permitted Cache facade — Laravel queue infra calls uniqueVia()
        // before constructor DI completes (BoundaryArchTest carve-out must add
        // this class to the allowlist).
        return Cache::driver('redis');
    }

    /**
     * @param iterable<SeriesDetector> $detectors  Container-tagged 'recurring.detector'
     */
    public function handle(
        DatabaseManager $db,
        Clock $clock,
        iterable $detectors,
    ): void {
        $user = User::query()->where('id', $this->userId)->firstOrFail();
        foreach ($detectors as $detector) {
            $detector->detectForUser($user);
        }
    }
}
```

The scheduled trigger in `routes/console.php` follows the Phase 6 pattern verbatim:

```php
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $userIds = $db->connection()->table('users')->pluck('id');
    foreach ($userIds as $id) {
        $bus->dispatch(new DetectRecurringSeriesJob((int) $id));
    }
})->name('recurring.detect')->daily()->withoutOverlapping(30);
```

### Pattern 4: Cadence Inferrer — median-interval-snap + variance bands

**What:** A stateless service that takes a sorted list of occurrence timestamps + a tolerance percent and returns `(cadence_class, next_expected_at, confidence)`.

**When to use:** Detectors call `CadenceInferrer::infer($timestamps, $amounts, $tolerancePercent)` for every candidate cluster.

**Source:** D-843 + D-844 (locked algorithm), industry consensus per `.planning/research/SUMMARY.md` lines 240–256 + Pitfall 8 in PITFALLS.md. No external library — bespoke implementation over `CarbonImmutable::diffInDays()`.

```php
<?php
declare(strict_types=1);

namespace Modules\Recurring\Internal;

use Carbon\CarbonImmutable;

final class CadenceInferrer
{
    /** Snap bands per D-843. */
    private const WEEKLY_MAX = 10;
    private const MONTHLY_MIN = 10;
    private const MONTHLY_MAX = 45;
    private const QUARTERLY_MIN = 80;
    private const QUARTERLY_MAX = 100;
    private const YEARLY_MIN = 350;
    private const YEARLY_MAX = 380;

    /** D-844 missed-occurrence tolerance. */
    private const MISSED_INTERVAL_MULTIPLIER = 1.8;
    private const MAX_MISSED_PER_WINDOW = 2;
    private const MISSED_WINDOW_SIZE = 6;

    /**
     * @param list<CarbonImmutable> $sortedTimestamps  ascending
     * @return array{
     *   cadence: 'weekly'|'monthly'|'quarterly'|'yearly'|'irregular',
     *   median_interval_days: float,
     *   next_expected_at: ?CarbonImmutable,
     *   confidence_low: bool,    // true when stddev > 5 days (D-830)
     *   missed_count: int,
     * }
     */
    public function infer(array $sortedTimestamps): array
    {
        // 1. Compute intervals in days
        // 2. Compute median + stddev
        // 3. Snap median to nearest class (or 'irregular')
        // 4. Count "missed periods" (any interval > 1.8 * median)
        // 5. Project next_expected_at = last_timestamp + median_interval_days
        // 6. confidence_low when stddev > 5 days
    }
}
```

### Pattern 5: Suggest-then-Approve Review Queue (mirror `/chains/review`)

**What:** A Livewire SFC with cursor pagination, per-row Approve/Reject/Snooze/Edit-name actions, plus a sticky bulk-action bar with checkbox-select. Service collaborators arrive as parameters on action methods + `render()` (constructor injection is banned on Livewire `Component` subclasses by phpstan-strict-rules).

**When to use:** `RecurringReviewPage` extends `Livewire\Component` and mirrors `ChainReviewQueue` verbatim on the action-method DI pattern.

**Source:** `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` lines 42–82.

```php
final class RecurringReviewPage extends Component
{
    public ?int $cursorId = null;

    /** @var array<int, int> */
    public array $selectedIds = [];   // for bulk-action bar (D-812)

    public string $tab = 'pending';   // 'pending' | 'rejected' | 'cadence_changed'

    public function approve(int $seriesId, CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        ($action)($seriesId, $currentUser->user());
        $this->dispatch('toast', message: 'Approved');   // Phase 4/5 Undo toast pattern
    }

    public function reject(int $seriesId, CurrentUser $currentUser, RejectRecurringSeries $action): void { ... }

    public function bulkApprove(CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        foreach ($this->selectedIds as $id) {
            ($action)($id, $currentUser->user());
        }
        $this->selectedIds = [];
        $this->dispatch('toast', message: count($this->selectedIds).' approved');
    }

    public function render(CurrentUser $currentUser, RecurringSeriesQuery $query, ViewFactory $views): View
    {
        // cursor-paginate via $query->pendingForUser(...)
    }
}
```

### Pattern 6: Public Read Query — `MerchantMemoryQuery` mirror

**What:** A `final readonly class` constructor-injecting `DatabaseManager`, scoping every read by `user_id`, returning `Spatie\LaravelData\Data` DTOs.

**When to use:** `RecurringSeriesQuery` + `FixedPaymentsViewQuery` follow this exact pattern.

**Source:** `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` (lines 30–77 in repo). Every read scopes by `user_id`; raw query builder is preferred over Eloquent for whereBetween/whereIn shapes to clear phpstan strict-rules `staticMethod.dynamicCall`.

The `MerchantMemoryQuery` class already exists. D-834 requires Phase 8 to **add a new method** (e.g. `forCounterpartiesNormalized(User, array<string>): array<MerchantMemoryDto>`) — DO NOT create a duplicate class. Verify the existing method name `latestForCounterpartyNormalized` and add a batch variant Phase 8 uses to decorate `/recurring` rows.

### Pattern 7: Drill-in Chart — ApexCharts via Blade JSON + Alpine init

**What:** Server renders the amount-trend dataset as a JSON-encoded `<script>` tag; Alpine bootstraps ApexCharts on `x-init`.

**When to use:** `/recurring/series/{id}` only. Other UI surfaces use plain HTML.

**Source:** ApexCharts official docs `https://apexcharts.com/docs/installation/` + Alpine integration per ApexCharts JS docs. NO Laravel-side composer wrapper needed.

**⚠ Gap:** ApexCharts is not currently installed in the repo despite CONTEXT.md claiming it is. Wave 0 must:

```bash
npm install apexcharts
```

…then add an import to `resources/js/app.js`:

```js
import ApexCharts from 'apexcharts'
window.ApexCharts = ApexCharts
```

…and the Blade pattern in the drill-in view:

```blade
<div x-data="{ chart: null }"
     x-init="chart = new ApexCharts($el.querySelector('#series-chart'), JSON.parse($el.dataset.options)); chart.render()"
     data-options='@json($apexOptions)'>
    <div id="series-chart"></div>
</div>
```

`$apexOptions` is built in the Livewire `render()` method from a `RecurringSeriesAmountTrendDto`.

### Anti-Patterns to Avoid

- **Running detection synchronously in the HTTP request lifecycle** — load-bearing boundary; D-833 invariant #4 (`noSynchronousDetectionInRequestLifecycle`) blocks this. Even a "quick" detector run over 10k transactions is too slow for a web request.
- **Writing `transactions.type` from `Modules/Recurring/`** — strictly forbidden. The income detector trusts upstream Phase 4 LED-05 classification. Any misclassified inflow gets fixed via the existing reclassify flow (Phase 4 D-80), not by Recurring.
- **Using facades or global helpers** (`auth()`, `request()`, `view()`, `Auth::`, `DB::`) anywhere inside `Modules/Recurring/`. The one carve-out is `Cache::driver('redis')` inside `DetectRecurringSeriesJob::uniqueVia()` — that single call must be added to the existing `BoundaryArchTest` allowlist (lines 47–75).
- **Locking metrics at approval** — explicitly rejected by D-804. Approved series MUST refresh their amount + monthly-equivalent + funding-chain + next-expected-charge on every sweep. Phase 9 drift alerts depend on this.
- **Two parallel tables for expense + income series** — explicitly rejected by D-816. One `recurring_series` table with a `direction` enum.
- **Clustering on settled EUR amount** — explicitly rejected by D-839. Cluster on `(merchant_or_iban, original_currency, amount_within_tolerance)`. FX is incidental.
- **Resurfacing rejected suggestions** — explicitly rejected by D-808. Reject = permanent until un-rejected. Calm aesthetic > naggy aesthetic.
- **`view()` global helper in any composer** — issue #12 fix (Phase 5). Resolve `Illuminate\Contracts\View\Factory` through `$this->app->make(ViewFactoryContract::class)` instead.
- **`Transaction::query()->update(...)` or `DB::table('transactions')->update(...)` anywhere under `Modules/Recurring/`** — caught by the new `noTransactionWritesFromRecurring` arch test that mirrors `noTransactionWritesFromEmailScan` (lines 237–286 in BoundaryArchTest).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Cadence inference from arbitrary timestamps | A full statistical fit (chi-squared / KS) | The locked median-interval-snap algorithm (D-843) | Overkill for the data volume; locked-in industry-standard heuristic |
| Money arithmetic for variance bands + monthly-equivalent multipliers | Hand-rolled float math | `brick/money` Money + multiplier methods | Floating-point will silently corrupt €9.99 vs €11.49 tolerance comparisons — Pitfall 1 |
| Queue locks for spam-click prevention | A `cache()->lock()` wrapper around the dispatch | `ShouldBeUniqueUntilProcessing` (already proven on `ResolveChainLinksJob`) | Built into Laravel; the project already has the Redis driver + carve-out pattern |
| Cross-module merchant-memory reads | A direct `MerchantMemory::query()->where(...)` from `Modules/Recurring/` | Extend `Modules\Categorization\Public\Services\MerchantMemoryQuery` (D-834) | Boundary leakage; the `crossModuleAccessGoesThroughPublic` arch test will catch it |
| Cross-module chain-link reads | Direct queries on `chain_links` table | `Modules\Chains\Public\Services\ChainLinkQuery` | Same — Public surface only |
| State-machine validation | Ad-hoc `if ($from === 'x' && $to === 'y')` checks scattered across actions | `RecurringSeriesStateMachine` sole-mutator (Pattern 2) | Phase 6 `InboxScanStateMachine` is the working precedent |
| Date arithmetic | Raw `\DateTime` / `mktime` math | `Carbon\CarbonImmutable` (bundled with Laravel) | Already used everywhere in repo; `diffInDays`, `addDays`, etc. handle DST + month-end |
| Cursor pagination on `/recurring/review` | LIMIT/OFFSET pagination | The `cursorId` + `cursorConfidence` pattern from `ChainReviewQueue` lines 49–55 | OFFSET pagination drifts when rows are approved/rejected mid-scroll |
| Chart rendering | Hand-rolled SVG bars | ApexCharts (Wave 0 install) **or** a Tailwind-only sparkline (escalate to user — see Open Questions) | Either is fine; do not build a custom chart engine |
| Toast notifications | A new toast renderer | The existing Phase 4/5 pattern: `$this->dispatch('toast', message: ...)` + Alpine `x-on:toast.window` | Inherits the same Blade + Alpine snippet; no new infra |
| Top-nav badge + dashboard card view injection | `view()` global helper composer | View Factory contract via `$this->app->make(ViewFactoryContract::class)->composer(...)` — Phase 5 issue #12 fix | DI invariant from CLAUDE.md |

**Key insight:** Phase 8 is mostly composition over inheritance — every pattern it needs already exists in repo. The only genuinely new code is `CadenceInferrer` (Pattern 4) + the two detectors + the three migrations + the four Livewire SFCs. Nothing else should be hand-rolled.

## Runtime State Inventory

**Not applicable** — Phase 8 is a greenfield analytical layer, not a rename/refactor/migration. The detector reads existing transaction rows and writes new rows into three new tables. No prior runtime state needs migration.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — verified via `grep -rn "recurring" Modules/` (only matches are in unrelated fixture filenames + Chains comments) | none |
| Live service config | None — no n8n / Datadog / Tailscale state in this project (PLT-01 localhost-only) | none |
| OS-registered state | Adds one new scheduled task name to launchd via `routes/console.php` Schedule entry. The existing `schedule:work` launchd plist runs all scheduled callbacks; no new plist required (Phase 6 already deployed `deploy/launchd/diederik.scheduler.plist`). | None — existing scheduler picks the new entry up automatically |
| Secrets/env vars | None — no new env vars (Phase 8 uses no external APIs) | none |
| Build artifacts | If ApexCharts is accepted: `package.json` + `package-lock.json` + `resources/js/app.js` change. Wave 0 must `npm install` and verify `npm run build` succeeds. | Run `npm install && npm run build` after Wave 0 commit |

## Common Pitfalls

### Pitfall 1: Pitfall 8 (PITFALLS.md) — Naive detector fragments legitimate price changes
**What goes wrong:** Detector treats Spotify €9.99 → €11.49 as a brand-new series or fails to detect at all.
**Why it happens:** Implementing "recurring" as "same amount + same cadence + ≥N occurrences."
**How to avoid:** Cluster by merchant identity first, amount second. Per-series `variance_tolerance_percent` default 25% (D-825). The Wave 0 fixture `drifting-monthly-spotify.php` exists specifically to lock this.
**Warning signs:** A series fragments mid-window in the contract test snapshot. A `variance_tolerance_percent IS NULL` row in `recurring_series`.

### Pitfall 2: Cross-currency clustering loses original-currency truth
**What goes wrong:** Detector clusters on settled EUR amount; Netflix USD $11.99 charges fragment because EUR settlement drifts 3% with FX.
**Why it happens:** Convenient because `transactions.settled_amount_minor` is the column most queries already use.
**How to avoid:** D-839: cluster on `(merchant, original_currency, original_amount_minor)`. Wave 0 fixture `mixed-currency-netflix-usd.php` locks this.
**Warning signs:** The mixed-currency Netflix contract test fails. Two separate "Netflix" series in the corpus output.

### Pitfall 3: Approved-series-still-cached snapshot vs live-recompute
**What goes wrong:** User approves Spotify @ €9.99. Sweep next day refreshes the row. The state-machine transition log shows `pending → approved` and then `approved → approved` for no apparent reason (just a metric refresh).
**Why it happens:** Some implementations treat "any change to a series row" as a state transition.
**How to avoid:** Only state-machine transitions touch `recurring_series.state` AND insert a `recurring_series_transitions` row. Metric updates (amount, monthly_equivalent, next_expected_at, latest_funding_chain_link_id) write the series row WITHOUT touching `state` and WITHOUT inserting a transition row. The arch test `noOtherRecurringSeriesStateMutator` only watches the `state` column, not the row.
**Warning signs:** Transitions table contains rows where `from_state === to_state`.

### Pitfall 4: Cadence-class flip during a one-off cancellation
**What goes wrong:** User pauses Spotify for two months, then resumes. The 90-day gap shifts the median interval from 30 days to 60 days — detector flips the series to `cadence_changed`, demands re-approval.
**Why it happens:** Median-snap is robust against single outliers but ONE large gap among small samples can move the median into the next band.
**How to avoid:** D-844 missed-occurrence tolerance — any single interval > 1.8 × median counts as 1 missed period (so the inflated interval is excluded from the median). Only > 2 missed in any rolling 6-period window flips the series. Wave 0 fixture `missing-month-subscription.php` locks this.
**Warning signs:** Sweep contract test produces a `cadence_changed` event when fewer than 3 missed periods accumulated.

### Pitfall 5: Funding-chain icon staleness (Phase 5 chain resolution is async)
**What goes wrong:** A brand-new occurrence's chain link is still `candidate` (or unresolved entirely) at sweep time. The icon shows "no chain."
**Why it happens:** Phase 5 `ResolveChainLinksJob` is queued + eventually consistent. Phase 8's daily sweep can race ahead.
**How to avoid:** D-829 — fall back to the previous occurrence's chain when the most recent is unresolved. Implement in `FixedPaymentsViewQuery` via a LEFT JOIN that picks `(MAX(occurrence)) JOIN chain_links ON state IN ('confirmed','candidate')` with a fallback to the next-latest occurrence.
**Warning signs:** `/recurring` rows show "no funding chain" badge for series with known PayPal-funding history.

### Pitfall 6: Two-employer payroll fragments into noise
**What goes wrong:** User receives salary from two payroll providers monthly; detector creates two separate series; user can't tell them apart on `/recurring`.
**Why it happens:** D-821 says "each IBAN cluster = its own series" — by design, but the UI needs to make this distinguishable.
**How to avoid:** Surface counterparty IBAN as a subtitle on income series rows. Use Edit-name (D-813) to let the user rename ("Salary — Acme Corp" / "Salary — Side gig"). Wave 0 fixture `two-employer-salary.php` validates the detector emits two distinct series; UI feature test validates both render legibly.
**Warning signs:** Two income series with identical detected names in the Wave 0 contract test output.

### Pitfall 7: Wizard-time backfill creates initial-load suggestion overload
**What goes wrong:** User imports 12 months of history on Phase 1 wizard. Phase 8's first scheduled sweep surfaces 30+ candidates simultaneously. User abandons.
**Why it happens:** Suggest-never-auto-apply (REC-03) is non-negotiable but the first-run experience is brutal without bulk actions.
**How to avoid:** D-812 — checkbox-select + bulk Approve/Reject sticky action bar. Feature test must cover "approve 20 in one click."
**Warning signs:** No `bulkApprove`/`bulkReject` Livewire method on `RecurringReviewPage`. No `selectedIds` array property.

### Pitfall 8: Snooze-until-date written to a column that doesn't auto-expire
**What goes wrong:** User snoozes Spotify until June 1. June 1 arrives; the suggestion stays hidden because nothing flips it back to `pending`.
**Why it happens:** No background job picks up `snoozed_until <= NOW()` rows.
**How to avoid:** The daily sweep `DetectRecurringSeriesJob` MUST include a first-step pass: `UPDATE recurring_series SET state='pending' WHERE state='snoozed' AND snoozed_until <= NOW()` (via the state machine). Insert the corresponding `snoozed → pending` audit row with `transition_reason='snooze_expired'`, `actor='detector'`.
**Warning signs:** Feature test that snoozes for 1 second + advances `CarbonImmutable::now()` + re-runs job finds the suggestion still hidden.

### Pitfall 9: ApexCharts memory bloat with large drill-in datasets
**What goes wrong:** A series with 60 occurrences over 5 years rendered as a full ApexCharts line chart with point markers consumes significant memory; combined with EUR shadow line + drift markers, the page hangs briefly on mid-spec laptops.
**Why it happens:** ApexCharts is a full SVG renderer; large datasets without sampling render every point.
**How to avoid:** Cap `RecurringSeriesAmountTrendDto::occurrences` at the most recent 24 points for the chart by default; show "view all 60" toggle that re-renders with `sampling: true` ApexCharts option. Also lazy-load ApexCharts on the drill-in page only (NOT bundled into the dashboard or `/recurring`).
**Warning signs:** First-render of `/recurring/series/{id}` for a 60-occurrence yearly series takes > 1 second on a current-gen laptop.

### Pitfall 10: ApexCharts not installed despite CONTEXT.md asserting it is
**What goes wrong:** Plans cite ApexCharts as "already shipped" and Wave 4 tries to render the drill-in chart with no JS dependency loaded.
**Why it happens:** CONTEXT.md inherited a misreading of prior phase research — neither Phase 3 nor Phase 5 actually installed it.
**How to avoid:** Wave 0 install step: `npm install apexcharts` + Vite wire-up + `package.json` commit. Verify via `grep -r ApexCharts resources/ Modules/` after Wave 0.
**Warning signs:** `package.json` lacks `apexcharts`. No `import ApexCharts` in `resources/js/app.js`. ⚠ **This pitfall is currently REAL in repo** — see Open Question Q1.

## Code Examples

### Example 1: Public Action — `ApproveRecurringSeries`

```php
<?php
declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Approves a pending or cadence_changed recurring series.
 *
 * Idempotent: approving an already-approved series is a no-op and does
 * not write a transitions row.
 *
 * Cross-user safety: throws NotFoundHttpException (404) when the
 * target series belongs to a different user — mirrors the Phase 5
 * ChainLinkQuery pattern.
 *
 * Action shape mirrors Modules/Chains/Public/Actions/ConfirmChainLink.php.
 */
final readonly class ApproveRecurringSeries
{
    public function __construct(
        private DatabaseManager $db,
        private RecurringSeriesStateMachine $stateMachine,
        private Clock $clock,
    ) {}

    public function __invoke(int $seriesId, User $user): void
    {
        $series = RecurringSeries::query()
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();

        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }
        if ($series->state === 'approved') {
            return;
        }
        $this->stateMachine->transition(
            $series,
            toState: 'approved',
            reason: 'user_action',
            actor: 'user',
        );
        event(new RecurringSeriesApproved(/* readonly props */));
    }
}
```

### Example 2: Public DTO — `RecurringSeriesDto`

```php
<?php
declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of one recurring_series row + the joined latest-
 * occurrence funding-chain pointer. Consumed by /recurring + dashboard
 * inline card + Phase 9 drift surface.
 *
 * latestAmount preserves original currency; eurEquivalent is the EUR
 * shadow at the latest occurrence's fx_rate_used (D-840 / D-851).
 */
final class RecurringSeriesDto extends Data
{
    public function __construct(
        public readonly int $seriesId,
        public readonly string $direction,            // 'expense' | 'income'
        public readonly string $detectedName,
        public readonly ?string $displayNameOverride,
        public readonly string $state,
        public readonly string $cadence,              // 'weekly'|'monthly'|'quarterly'|'yearly'|'irregular'
        public readonly Money $latestAmount,           // original currency
        public readonly ?Money $eurEquivalent,         // null when original_currency = EUR
        public readonly Money $monthlyEquivalent,      // EUR, computed via cadence multiplier
        public readonly ?int $latestFundingChainLinkId,
        public readonly ?CarbonImmutable $nextExpectedAt,
        public readonly bool $nextExpectedConfidenceLow,
        public readonly int $varianceTolerancePercent, // default 25
        public readonly ?CarbonImmutable $snoozedUntil,
    ) {}

    public function displayName(): string
    {
        return $this->displayNameOverride ?? $this->detectedName;
    }
}
```

### Example 3: `noTransactionWritesFromRecurring` arch test

```php
it('does not allow any file under Modules/Recurring/ to mutate the transactions table (noTransactionWritesFromRecurring)', function (): void {
    // Phase 8 architectural boundary: the Recurring module is analytical-
    // only. Transaction-type ownership stays with Phase 4 LED-05. Mirrors
    // the Phase 6 noTransactionWritesFromEmailScan invariant.
    $hits = [];
    $recurringDir = base_path('Modules/Recurring');
    if (! is_dir($recurringDir)) {
        expect(true)->toBeTrue();
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($recurringDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile()) continue;
        $path = $file->getPathname();
        if (preg_match('/\.php$/', $path) !== 1) continue;
        if (str_contains($path, '/tests/')) continue;
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match('/Transaction::query|Transaction::where|Transaction::create/', $stripped) === 1
            || preg_match("/->table\(['\"]transactions['\"]\)[^;]*->(update|insert|delete)\\s*\\(/", $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe([], "Modules/Recurring/ must not mutate the transactions table. Offenders:\n  ".implode("\n  ", $hits));
});
```

### Example 4: Migration shape — `create_recurring_series_table`

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('recurring_series', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();      // FND-03
            $t->enum('direction', ['expense', 'income']);                                       // D-816
            $t->string('detected_name');
            $t->string('display_name_override')->nullable();                                    // D-813
            $t->enum('state', ['pending', 'approved', 'rejected', 'snoozed', 'cadence_changed'])
                ->default('pending');                                                           // D-815 state set
            $t->string('cadence')->default('irregular');                                        // D-848: string per current decision
            $t->bigInteger('latest_amount_minor');                                              // FND-04
            $t->string('latest_currency', 3);
            $t->string('latest_fx_rate_used')->nullable();                                      // D-851
            $t->bigInteger('monthly_equivalent_minor')->nullable();                             // D-826
            $t->unsignedTinyInteger('variance_tolerance_percent')->default(25);                 // D-825
            $t->foreignId('latest_funding_chain_link_id')->nullable()
                ->constrained('chain_links')->nullOnDelete();                                   // D-828/D-829
            $t->timestamp('snoozed_until')->nullable();                                         // D-810
            $t->date('next_expected_at')->nullable();
            $t->boolean('next_expected_confidence_low')->default(false);                        // D-830
            $t->string('cluster_key');                                                          // expense: merchant fingerprint; income: IBAN or normalized desc
            $t->timestamps();

            $t->unique(['user_id', 'direction', 'cluster_key', 'latest_currency'], 'rec_series_uniq');
            $t->index(['user_id', 'state']);                                                    // top-nav badge query
            $t->index(['user_id', 'state', 'next_expected_at']);                                // 'This month only' toggle
        });
    }
};
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Hand-rolled scheduling via cron | Laravel `Schedule::call()->daily()` registered in `routes/console.php`, run by `schedule:work` daemon under launchd | Phase 6 (Phase 8 inherits) | Single canonical scheduler |
| Hand-rolled queue locking via `cache()->lock()` | `ShouldBeUniqueUntilProcessing` interface contract | Laravel 9+ | Lock released the moment a worker begins `handle()`; subsequent dispatches queue cleanly |
| Polymorphic `transactions.kind` strings | `transactions.type` enum (`expense`/`income`/`transfer_out`/`transfer_in`/`fee`/`refund`/`adjustment`) with DB-trigger validation | Phase 1 (already locked) | Phase 8's income detector trusts upstream — `WHERE type='income'` is the entire pre-filter |
| Plain floats for money | `brick/money` Money value object backed by BIGINT minor units | Phase 1 (FND-04 + FND-07) | Variance bands stay exact at the cent |
| Per-controller services injected via service location | Constructor DI throughout; `final readonly class` for stateless services | Phase 1 CLAUDE.md | Larastan strict-rules + arch tests enforce |
| Inline ad-hoc state checks | Sole-mutator state machine + `recurring_series_transitions` audit table | Phase 6 InboxScanStateMachine | Phase 9 drift queries depend on the audit trail |

**Deprecated / explicitly forbidden in this project:**
- `ext-imap` — banned (PLT-05 + `composer.json` conflict block)
- `webklex/laravel-imap` / `webklex/php-imap` / `ddeboer/imap` — banned (composer.json `conflict`)
- Native `fgetcsv()` for CSV ingestion — superseded by `league/csv`
- `view()` global helper in any composer or service — superseded by View Factory contract DI (Phase 5 issue #12)
- `auth()->user()` / `request()->user()` anywhere in module code — superseded by `Modules\Core\Public\Contracts\CurrentUser` injection

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | ApexCharts is acceptable to the user despite the "calm Linear/Notion aesthetic" brief | Standard Stack + Pattern 7 | Wave 4 stalls when user objects to a heavy chart on a calm dashboard — escalate before Wave 0 |
| A2 | `apexcharts` npm package at registry is the official ApexCharts library | Package Legitimacy Audit | Wave 0 slopcheck + `npm view apexcharts` verification mitigates |
| A3 | Median-interval-snap with the D-843 band thresholds catches ≥95% of real-world recurring patterns for this user's bank/card mix | Pattern 4 | Detector under-recall surfaces in Wave 0 contract test against real export; if low, tighten bands |
| A4 | The existing `Modules\Categorization\Public\Services\MerchantMemoryQuery` class can be extended in-place with a batch method without breaking Phase 7 callers | D-834 / Pattern 6 | Backwards-compatible additions only; the existing `latestForCounterpartyNormalized` signature must stay verbatim |
| A5 | Phase 5 `chain_links` table already populates `latest_funding_chain_link_id` for every chained expense; Phase 8 can FK to it without orchestrating chain resolution itself | Migration + D-828 | If chain resolution hasn't fully run for a user's transactions, the icon falls back to `null` (graceful — Pitfall 5 covers this) |
| A6 | `users.recurring_detection_window_months` + `users.recurring_income_min_amount_minor` columns can be added by a single migration without conflicting with Phase 3's `users` migration (`default_currency_view`) or Phase 6's `auto_import_drop_folder` | Migration shape | Standard `add_recurring_settings_to_users.php` anonymous-class migration — verified pattern (Phase 3 `default_currency_view`, Phase 6 `auto_import_drop_folder`) |
| A7 | The existing scheduler daemon (Phase 6 launchd plist) will pick up the new `Schedule::call(...)->daily()` entry automatically without a launchd re-registration | Pattern 3 + Runtime State Inventory | Standard Laravel scheduler behavior; verified by Phase 6 `email-scan.discovery` daily-run shape in `routes/console.php` |
| A8 | The pre-existing Phase 4 `TransactionTypeTest::it-rejects-an-invalid-transaction-type` failure (logged to `deferred-items.md`) does not affect Phase 8's contract test for the detector | Test architecture | Phase 8 contract tests live in `tests/Contracts/RecurringDetectionContractTest.php` and do not depend on the failing trigger fixture |

**Each `[ASSUMED]` claim above needs user confirmation in plan-check or discuss before being locked.**

## Open Questions

### Q1: ApexCharts is not installed — install it, swap it, or escape-hatch?

- **What we know:** D-827 explicitly mandates "Full ApexCharts visualization" on `/recurring/series/{id}`. CONTEXT.md `<canonical_refs>` repeatedly cites "ApexCharts from Phase 5 / Phase 3" as if it were already installed.
- **What's unclear:** `package.json` shows zero chart dependencies. `grep -r ApexCharts resources/ Modules/` returns nothing. Neither Phase 3 nor Phase 5 actually installed it — the chain drawer is rendered via Blade partials.
- **Recommendation:** Surface this to the user during planning. Three viable paths:
  1. **Install ApexCharts in Wave 0** (matches stated CONTEXT.md intent; ~150KB JS bundle) — DEFAULT
  2. **Use Chart.js** (~70KB; less polished defaults; Filament's chart widget basis)
  3. **Tailwind-only SVG sparkline** (zero JS dependency; matches calm aesthetic; loses interactive tooltips) — best fit for the "Linear/Notion calm" brief
- **Blocker level:** Wave 4 cannot ship without resolution.

### Q2: Where does the snooze-expiry sweep live — inside `DetectRecurringSeriesJob` or as a separate scheduled task?

- **What we know:** D-810 locks the snooze UX; D-801 locks the trigger model.
- **What's unclear:** Should the snooze-expiry pass (Pitfall 8) be the first step inside the daily detector, or a separate `Schedule::call(...)->hourly()` to surface expired suggestions faster?
- **Recommendation:** Bundle inside `DetectRecurringSeriesJob` to keep one source of truth. Hourly surface for snooze-expiry would be a v2 improvement if users report "I snoozed yesterday and it's still hidden 4 hours after the snooze-until time."

### Q3: Container-tag mechanics for `SeriesDetector` (D-849)

- **What we know:** Two detectors implement the same contract; the job runs all tagged implementations.
- **What's unclear:** Laravel's `tag()` + `tagged()` API is the standard pattern, but the project hasn't used it yet (Phase 1–7 used single-class singletons or direct lookups). Container `tag()` is the cleanest path:
  ```php
  // RecurringServiceProvider::register()
  $this->app->singleton(ExpenseSeriesDetector::class);
  $this->app->singleton(IncomeSeriesDetector::class);
  $this->app->tag([
      ExpenseSeriesDetector::class,
      IncomeSeriesDetector::class,
  ], 'recurring.detector');
  // Job uses: iterable $detectors injected via $this->app->tagged('recurring.detector')
  ```
- **Recommendation:** Use container tagging. Document in `RecurringServiceProvider`. PHPDoc the tag name in `SeriesDetector` contract.

### Q4: Exact mechanism for `noSynchronousDetectionInRequestLifecycle` (D-850)

- **What we know:** Detector code may not run inside the HTTP request cycle.
- **What's unclear:** Two mechanism options:
  1. **Marker interface** — `interface RunsDetection {}` implemented by detectors + the job. Arch test asserts no `Modules/Recurring/Internal/Http/` file imports any `RunsDetection` implementor.
  2. **Naming convention** — files named `*Detector` may only be imported by `*Job` or `*Command` callsites. Arch test scans paths.
- **Recommendation:** Marker interface is cleaner; tests are more explicit. Mirror the `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` import style — the interface lives at `Modules/Recurring/Public/Contracts/RunsDetection.php`.

### Q5: Cluster key shape for the `recurring_series.cluster_key` UNIQUE constraint

- **What we know:** Expense detector clusters on `(merchant_or_iban, original_currency, amount_within_tolerance)`; income detector clusters on `(counterparty_iban OR normalized_description)`.
- **What's unclear:** What canonical string goes in the `cluster_key` column? Proposals:
  - Expense: `"merchant:{merchant_id}|cur:{original_currency}"` — but `merchant_id` is null on transactions table (Phase 7 RESEARCH note); fallback is `counterparty_normalized`
  - Income: `"iban:{counterparty_iban}"` if non-null, else `"desc:{counterparty_normalized}"`
- **Recommendation:** Use a small `ClusterKeyComposer` value-object class (mirrors `FingerprintComposer` from Ledger) so the formatting is centralized and testable. Planner picks the exact string shape.

### Q6: How does the dashboard "Fixed monthly payments" card avoid an N+1 on `chain_links` joins?

- **What we know:** The card renders 6 rows × funding-chain icon each.
- **What's unclear:** Naïve implementation lazy-loads `RecurringSeries::chainLink` for each row. Need a single eager-load query.
- **Recommendation:** `FixedPaymentsViewQuery::topByMonthlyEquivalent(User, int $limit)` returns a list of `RecurringSeriesDto` with pre-joined `latest_funding_chain_link_id` — single query, no N+1.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.5 | Framework runtime | ✓ (composer requires `^8.5`) | from `composer.json` | — |
| Laravel 13 | Framework | ✓ | `^13.0` | — |
| SQLite (WAL) | Storage | ✓ | bundled with PHP via PDO | — |
| Redis | Horizon + `ShouldBeUniqueUntilProcessing` lock store | ✓ | per Phase 5 Docker setup | Cache facade `array` driver for tests (already configured in `tests/TestCase` setUp per Phase 5 Wave 2 D-104) |
| Horizon | Queue manager | ✓ | `^5.46` | — |
| Composer packages above | See Standard Stack table | ✓ | All present | — |
| **ApexCharts** (npm) | Drill-in chart (D-827) | **✗** | **not installed** | **Chart.js OR Tailwind-only SVG sparkline (escalate via Open Q1)** |
| Node + npm + Vite | Frontend bundling | ✓ (per `package.json` + `vite.config.js`) | — | — |
| launchd | Scheduler + queue worker | ✓ (Phase 6 deploys) | — | — |

**Missing dependencies with no fallback:** none — the only gap is ApexCharts, which has two viable fallbacks.

**Missing dependencies with fallback:** ApexCharts — fallback = Chart.js (similar shape, smaller bundle) or Tailwind SVG sparkline (zero npm dep, calmest UX, lacks interactive tooltip).

## Validation Architecture

> Required because `workflow.nyquist_validation: true` in `.planning/config.json`.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.0 (on PHPUnit 11) + pest-plugin-arch 4.0 + pest-plugin-snapshots 2.0 |
| Config file | `phpunit.xml` (root) + `Modules/Recurring/tests/Pest.php` (inert per project convention) + root `tests/Pest.php` (load-bearing per-module TestCase map) |
| Quick run command | `vendor/bin/pest --filter='Recurring' --parallel` |
| Full suite command | `composer test` (= `pest --parallel`) — runs all module suites + `tests/Contracts/` |
| Static analysis | `composer analyse` (= `phpstan analyse --memory-limit=1G`) at level max with strict + larastan-strict + larastan-livewire |
| Code style | `composer format:check` (Pint, default Laravel preset) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| REC-01 | `CadenceInferrer` classifies weekly/monthly/quarterly/yearly/irregular from interval list (Pest dataset, 15–20 rows) | unit | `vendor/bin/pest Modules/Recurring/tests/Unit/CadenceInferenceTest.php` | ❌ Wave 0 (create) |
| REC-01 | `ExpenseSeriesDetector` clusters synthetic Spotify trio into ONE series | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='expense-cluster'` | ❌ Wave 2 |
| REC-02 | Variance tolerance 25%: €9.99 + €11.49 cluster as one series | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='variance-tolerance'` | ❌ Wave 2 |
| REC-02 | Variance tolerance violation: €9.99 + €20.00 fragment into two series | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='variance-exceeded'` | ❌ Wave 2 |
| REC-03 | Suggestion appears in `/recurring/review` and NOT in `/recurring` until Approve | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringReviewPageTest.php --filter='suggest-not-applied'` | ❌ Wave 2 |
| REC-03 | Approve flips state to `approved` AND writes a `recurring_series_transitions` row | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/ApproveRecurringSeriesTest.php` | ❌ Wave 2 |
| REC-03 | Bulk Approve action approves N series in one click | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringReviewPageTest.php --filter='bulk-approve'` | ❌ Wave 4 |
| REC-03 | Reject → un-reject → reappears in pending tab | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/UnRejectRecurringSeriesTest.php` | ❌ Wave 2 |
| REC-03 | Cross-user 404 on every Public Action (Approve / Reject / Snooze / EditName / UnReject) | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/CrossUserRecurringSeriesIsolationTest.php` | ❌ Wave 2 |
| REC-04 | `/recurring` lists approved expense + income series with name + monthly equivalent + funding icon + next-expected date | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringPageTest.php` | ❌ Wave 3 |
| REC-04 | Edit-name override persists across a re-detect | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/EditRecurringSeriesNameTest.php --filter='persists-across-sweep'` | ❌ Wave 2 |
| REC-04 | Funding icon falls back to previous occurrence's chain when newest is unresolved | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringPageTest.php --filter='chain-fallback'` | ❌ Wave 3 |
| REC-04 | Dashboard "Fixed monthly payments" card renders top 6 + View-all link | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/FixedPaymentsCardTest.php` | ❌ Wave 4 |
| REC-05 | `/recurring/series/{id}` lists every occurrence with date + amount + transaction link | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringSeriesDetailPageTest.php` | ❌ Wave 4 |
| REC-05 | Drill-in chart receives well-formed dataset (amount-over-time JSON) | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringSeriesDetailPageTest.php --filter='chart-dataset'` | ❌ Wave 4 |
| LED-06 | `IncomeSeriesDetector` clusters salary-IBAN trio into ONE income series with `direction='income'` | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='income-cluster'` | ❌ Wave 3 |
| LED-06 | Income below `recurring_income_min_amount_minor` does NOT cluster | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='income-threshold'` | ❌ Wave 3 |
| LED-06 | Two-employer salary clusters into two distinct series | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='two-employer'` | ❌ Wave 3 |
| UI-03 | Drill-in route resolves + renders for the series owner; 404s for other users | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringSeriesDetailPageTest.php --filter='cross-user-404'` | ❌ Wave 4 |
| (arch) | `noTransactionWritesFromRecurring` | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='noTransactionWritesFromRecurring'` | ❌ Wave 0 |
| (arch) | `noFacadeCallsFromRecurring` (verifies the existing `no Laravel facade usage in module code` rule covers `Modules\Recurring` with the `DetectRecurringSeriesJob` carve-out) | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='no Laravel facade usage'` | ❌ Wave 0 (extend existing rule) |
| (arch) | `crossModuleAccessGoesThroughPublic` (every `Modules\Recurring\Internal` import of `Modules\<Other>\Internal` is forbidden) | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='Modules\\Recurring\\Internal'` | ❌ Wave 0 |
| (arch) | `noSynchronousDetectionInRequestLifecycle` (no file under `Modules/Recurring/Internal/Http/` imports any `SeriesDetector` implementor) | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='noSynchronousDetectionInRequestLifecycle'` | ❌ Wave 0 |
| (arch) | `noOtherRecurringSeriesStateMutator` (only the state machine writes `recurring_series.state`) | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='noOtherRecurringSeriesStateMutator'` | ❌ Wave 1 |
| (contract) | End-to-end sweep over Wave 0 fixture corpus produces the expected set of series / states / cadences / metrics | contracts | `vendor/bin/pest tests/Contracts/RecurringDetectionContractTest.php` | ❌ Wave 0 + populated through Waves 2/3 |
| (idempotency) | Running the sweep twice produces the same series set (no duplicates, no double-counted occurrences) | contracts | `vendor/bin/pest tests/Contracts/RecurringDetectionContractTest.php --filter='idempotent-re-run'` | ❌ Wave 2 |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest --filter='Recurring' --parallel` (Recurring scope, ~5–10 seconds)
- **Per wave merge:** `composer test` (full suite, ~60 seconds at current scale)
- **Phase gate:** `composer test && composer analyse && composer format:check` all green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `Modules/Recurring/composer.json` — new module manifest (mirrors `Modules/Chains/composer.json`)
- [ ] `Modules/Recurring/module.json` — mirrors `Modules/Categorization/module.json` with `priority: 5`
- [ ] `Modules/Recurring/Providers/RecurringServiceProvider.php` — registers detectors (container tag `'recurring.detector'`), state machine singleton, Public Action singletons, loads migrations + routes, registers Livewire components, registers View composers for top-nav badge + dashboard card
- [ ] `Modules/Recurring/tests/Pest.php` + `Modules/Recurring/tests/TestCase.php` — inert per project convention
- [ ] `tests/Pest.php` (root) — add `'Modules/Recurring' => Modules\Recurring\Tests\TestCase::class` to the foreach map (Phase 4 D-80b 3-step pattern)
- [ ] `phpunit.xml` — add `Modules/Recurring/tests/Unit` and `Modules/Recurring/tests/Feature` directories to existing Unit + Feature testsuites; new `RecurringContracts` testsuite for `Modules/Recurring/tests/Contracts/` if needed
- [ ] `composer.json` autoload-dev — add `"Modules\\Recurring\\Tests\\": "Modules/Recurring/tests/"`
- [ ] `tests/Contracts/BoundaryArchTest.php` — add the four new invariants (D-833) + `noOtherRecurringSeriesStateMutator` + the `DetectRecurringSeriesJob` facade carve-out in the existing `ignoring([...])` array
- [ ] `tests/Contracts/RecurringDetectionContractTest.php` — new contract test scaffolded with the Wave 0 fixture corpus
- [ ] `Modules/Recurring/tests/fixtures/synthesised/` — 12-fixture corpus per D-845 (each as a PHP factory script returning a list of `CanonicalTransaction` rows)
- [ ] `Modules/Recurring/tests/fixtures/real/anonymised-asn-ics-6mo.php` — anonymised real export from the user's ASN + ICS history covering ≥6 months
- [ ] `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` — extend with a batch method (e.g. `forCounterpartiesNormalized(User, list<string>): array<string, MerchantMemoryDto>`) for efficient `/recurring` row decoration (D-834)
- [ ] **ApexCharts decision + install** — `npm install apexcharts` and Vite wire-up (`resources/js/app.js`) IF the user confirms Open Q1; else swap to Chart.js or sparkline. **This is the only Wave 0 blocker before Wave 2.**
- [ ] Framework install: none — every PHP dependency is already present

## Sources

### Primary (HIGH confidence — verified in repo)

- `Modules/Chains/composer.json` + `Modules/Chains/Providers/ChainsServiceProvider.php` — bounded module composer + provider shape (Pattern 1)
- `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` — `ShouldBeUniqueUntilProcessing` queued-job shape (Pattern 3)
- `Modules/Chains/Public/Services/ChainLinkQuery.php` + `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` — review-queue pattern + Public read service (Patterns 5 + 6)
- `Modules/EmailScan/Internal/InboxScanStateMachine.php` — sole-mutator state machine (Pattern 2)
- `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` — `final readonly class` Public read service (Pattern 6)
- `tests/Contracts/BoundaryArchTest.php` — existing four arch invariants Phase 8 mirrors
- `composer.json` + `package.json` — installed dependency list (verified ApexCharts is NOT present)
- `routes/console.php` — Phase 6 scheduler entry shape (Pattern 3 scheduled trigger)
- `phpunit.xml` + `tests/Pest.php` — three-step new-module test wiring (Phase 4 D-80b)

### Secondary (HIGH confidence — verified planning docs)

- `.planning/REQUIREMENTS.md` — REC-01..05, LED-06, UI-03 + traceability table
- `.planning/ROADMAP.md` Phase 8 — goal + 5 success criteria
- `.planning/STATE.md` — phase 1–7 decision log + STACK.md amendments
- `.planning/research/SUMMARY.md` Pitfall 8 — industry-consensus recurring-detection heuristics
- `.planning/research/PITFALLS.md` — Pitfall 1 (float money), Pitfall 8 (naive recurring), Pitfall 9 (cross-source matching)
- `.planning/phases/08-recurring-detection-fixed-payments-view/08-CONTEXT.md` — 55 locked decisions (D-801 .. D-855)
- `.planning/phases/08-recurring-detection-fixed-payments-view/08-DISCUSSION-LOG.md` — alternative options considered + rejected

### Tertiary (CITED — external official docs)

- ApexCharts installation + line-chart docs — `https://apexcharts.com/docs/installation/` `[CITED]`
- ApexCharts line chart configuration — `https://apexcharts.com/docs/chart-types/line-chart/` `[CITED]`
- Laravel 12.x Scheduling — `https://laravel.com/docs/12.x/scheduling` `[CITED]` (Laravel 13 docs may differ in URL path; check on Wave 0)
- Laravel 12.x Queues + `ShouldBeUniqueUntilProcessing` — `https://laravel.com/docs/12.x/queues#unique-jobs` `[CITED]`
- Livewire 4 single-file components + `wire:click` actions — `https://livewire.laravel.com/docs/components` `[CITED]`
- Pest 4.0 arch plugin — `https://pestphp.com/docs/arch-testing` `[CITED]`

## Metadata

**Confidence breakdown:**
- Module skeleton + DI patterns + boundary tests: **HIGH** — every shape exists verbatim in `Modules/Chains/`, `Modules/Receipts/`, `Modules/EmailScan/`
- Migration shape + state machine: **HIGH** — Phase 6 `InboxScanStateMachine` + Phase 5 `card_statements` migrations are direct templates
- Detector algorithm: **MEDIUM** — median-interval-snap is industry-consensus per `.planning/research/SUMMARY.md` and Pitfall 8, but no canonical reference; locked by D-843/D-844 — verified by Wave 0 contract test
- ApexCharts wiring: **LOW** — CONTEXT.md asserts it exists; repo proves it does not. **Resolve via Open Q1 before Wave 0 locks.**
- Multi-currency rendering: **HIGH** — D-839/D-840/D-841 + Phase 3 D-44/D-46/D-47 precedent
- Cross-module reads via Public services: **HIGH** — existing `MerchantMemoryQuery` + `ChainLinkQuery` are working precedents
- Test infrastructure (new module): **HIGH** — Phase 4 D-80b 3-step pattern + Phase 7 Receipts module did exactly this

**Research date:** 2026-05-17
**Valid until:** 2026-06-16 (30 days — stack is stable; Laravel 13 and Livewire 4 are both released and pinned)
