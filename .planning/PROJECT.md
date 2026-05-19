# diederik

## What This Is

A local-only personal finance dashboard that ingests transactions from ASN Bank (CSV / CAMT.053 / MT940), ICS Cards (PDF), PayPal (CSV), and email receipts (Gmail / Microsoft Graph / `.eml`/`.mbox`) into a single canonical ledger. It resolves the routing chains between these accounts (PayPal → ASN or ICS, ICS → ASN via bulk iDEAL settlement) so that fixed monthly payments, real underlying funding sources, subscription drift, and 30/60/90-day cash-flow projections (with what-if scenarios) are visible in one place instead of buried across statements.

**Shipped as v1.0 on 2026-05-19** — see [MILESTONES.md](MILESTONES.md) for the full inventory and [milestones/v1.0-ROADMAP.md](milestones/v1.0-ROADMAP.md) for the per-phase breakdown.

## Current State

- **Shipped version:** v1.0 MVP (11 phases / 66 plans / 154 tasks)
- **Status:** Daily-use ready on the developer's machine. Operational hardening complete (`db:backup` + `db:restore` + `diederik:doctor` + `SystemAlertsBanner` + launchd-managed background workers).
- **Quality gates:** Larastan level 10 strict + Laravel Pint + Pest — all green; 1644 project tests green at v1.0 close.
- **Known carry-over:** 25 human-UAT scenarios across 5 phases pending in-person walkthrough; 3 verification artifacts in `human_needed` state; 1 dormant seed (`SEED-001-public-release-milestone`). Tracked in [STATE.md → Deferred Items](STATE.md).

## Next Milestone Goals

Not yet scoped. Run `/gsd-new-milestone` to gather context and produce a roadmap. Two candidate themes surfaced during v1.0:

1. **Public release readiness** (per dormant `SEED-001`) — desktop packaging (Tauri / Electron / Herd-as-binary), CI/CD pipeline, deep Modules pass, screenshot-driven docs, distribution channel. Turns a developer-machine tool into something a partner could install.
2. **Real-data hardening + UAT close-out** — work through the 25 deferred UAT scenarios end-to-end with real ASN + ICS + PayPal + Gmail data; address any divergences from the synthesised fixtures; revisit ING-09 (PayPal Reporting API) if the user upgrades to a Business account.

## Core Value

**Show me, in one place, what I actually owe and where the money truly came from — across every account chain — so my monthly finances stop being a manual reconciliation puzzle.**

Validated by v1.0: chain resolution (Phase 5) traces a Netflix charge from PayPal → funding card → ICS line → ASN bulk-iDEAL settlement → ASN balance impact. Fixed-payments view (Phase 8) + drift alerts (Phase 9) + forecast (Phase 10) surface the recurring picture. The core value is confirmed; the next milestone tunes operations and reach, not direction.

## Requirements

### Validated

<!-- Shipped in v1.0 and confirmed valuable. -->

- ✓ Import ASN transactions via CSV, CAMT.053 (XML), and MT940 (hand-rolled) — v1.0 (Phase 2)
- ✓ Idempotent imports across formats — v3 fingerprint composer + `enriched_from` cross-format upgrade — v1.0 (Phase 2)
- ✓ Import ICS Cards PDF statements with foreign-currency charges preserved as original + settled-EUR — v1.0 (Phase 3)
- ✓ Multi-currency views — per-page currency toggle on `/transactions`, per-currency dashboard tiles, locale-aware `Money::format()` — v1.0 (Phase 3)
- ✓ Import PayPal CSV with event-log rollup (parent / child-fee / child-fx) and transfer detection — v1.0 (Phase 4)
- ✓ Chain resolution — deterministic PayPal-funder links + fuzzy fallback + ICS bulk-iDEAL settlement decomposition + candidate review queue + per-merchant learning — v1.0 (Phase 5)
- ✓ Email receipt ingestion infrastructure — Gmail + Microsoft Graph OAuth2, per-inbox UID-resume scanning, queued backfill, `.eml`/`.mbox` drop-in — v1.0 (Phase 6)
- ✓ Email template matchers + categorization learning — PayPal / ICS / Google Play matchers, user-defined rules with specificity scoring, per-merchant memory — v1.0 (Phase 7)
- ✓ Detect recurring expenses + income at any cadence with user-tunable variance tolerance — v1.0 (Phase 8)
- ✓ Suggest-never-auto-apply recurring detection — state machine + arch test enforced — v1.0 (Phase 8)
- ✓ Curated fixed-payments view + drill-in chart + dashboard inline card — v1.0 (Phase 8)
- ✓ Subscription drift detection + alerts — signed-delta evaluator, annualized impact, acknowledge / snooze / cancel-what-if actions — v1.0 (Phase 9)
- ✓ Cash-flow forecasting + what-if scenarios — 30/60/90-day per-account projection, R-7 percentile ranges, chain-aware routing, shortfall windows, non-persisted scenarios with side-by-side comparison — v1.0 (Phase 10)
- ✓ Operational hardening — `db:backup` via `VACUUM INTO`, restore-verification, `diederik:doctor`, `SystemAlertsBanner`, `diederik:failed-jobs prune`, launchd plists — v1.0 (Phase 11)

### Active

<!-- Scope for the next milestone. -->

Not yet scoped — run `/gsd-new-milestone` to gather requirements.

### Deferred (carried from v1.0)

- ING-09: PayPal Reporting API (Transaction Search) via OAuth2 — trigger: user upgrades to PayPal Business account. CSV path (ING-05) covers the same data without API gating.
- SEED-001: Public release readiness, desktop packaging, CI/CD, deep Modules rev — captured as the candidate next milestone theme.

### Out of Scope

<!-- Explicit boundaries. Includes reasoning to prevent re-adding. -->

- **Cloud hosting / multi-device sync** — Privacy-first design; validated through v1.0 that single-machine works. Revisit only if a partner-sharing use case forces it.
- **Bank PSD2 / open-banking API integrations** (Tink, Plaid, Nordigen, Enable Banking) — GoCardless stopped accepting new Dutch-bank accounts in July 2025; remaining options are paid. CSV + MT940 + CAMT.053 covered the same data in v1.0 without recurring cost. Reasoning still valid.
- **ICS Cards API integration** — No buyer-side API exists. Reasoning still valid.
- **Google Play buyer-side API** — No public API. Email receipts are the canonical path (validated in Phase 7).
- **Outbound payments / iDEAL initiation** — System recommends amounts; the user pays via their bank. v1.0's forecast tile + chain drill-down made this gap a non-issue. Reasoning still valid.
- **Investment / brokerage / portfolio tracking** — Scope is cash and card flow. Reasoning still valid.
- **Mobile native client** — Web UI on localhost served the user well through v1.0. Reasoning still valid.
- **iCloud Mail integration** — No public API; would force IMAP back into the stack. User confirmed iCloud is not where financial receipts arrive. Reasoning still valid.
- **Tax / VAT / bookkeeping reporting** — Visibility tool, not accounting. Reasoning still valid.
- **Multi-user / partner sharing in v1** — Single-user first. Schema is multi-user-ready (`user_id` + `BelongsToUser` on every domain table, enforced by `UserIdColumnArchTest`); arrival deferred to the user's partner-sharing decision.
- **Receipt-image OCR** — Email + CSV is the data spine. Defer to v2+.
- **Budgeting / envelope / goals (YNAB-style)** — Different product. Reasoning still valid.
- **Full double-entry accounting** — Adds complexity Firefly III's own creator says drives users away. Reasoning still valid.
- **LLM categorization** — Rules + per-merchant memory proved sufficient in Phase 7. Privacy + cold-start concerns also apply. Reasoning still valid.
- **Auto-applied recurring detection** — Always-suggest-never-auto-apply; validated as the right call in Phase 8.

## Context

**Shipped state at v1.0 close (2026-05-19):**

- **LOC:** Backend PHP + Blade across `Modules/Core`, `Modules/Ingestion`, `Modules/Ledger`, `Modules/Categorization`, `Modules/Recurring`, `Modules/DriftAlerts`, `Modules/Forecasting`, `Modules/Chains`, `Modules/Transfers`, `Modules/EmailScan`, `Modules/Receipts`. Frontend Blade + Livewire 4 SFCs + Alpine sprinkles + Tailwind 4.
- **Tests:** 1644 Pest tests green; 34+ `BoundaryArchTest` invariants enforcing module + DI + secrets + state-machine boundaries; arch-test layer is the load-bearing safety net.
- **Stack pinned:** PHP 8.5 + Laravel 13 + Livewire 4 + Volt + Flux UI + Tailwind 4 + SQLite (WAL + `synchronous=NORMAL`) + Pest 3 + Larastan level 10 strict + Pint + Horizon 5.46 + Redis 7-alpine (loopback-bound only) + macOS launchd plists.
- **Domain libraries:** `genkgo/camt`, `brick/money`, `league/csv`, `spatie/laravel-data`, ApexCharts via Livewire wrapper, `laravel/horizon`, `predis/predis`.
- **Carry-over backlog:** see [STATE.md → Deferred Items](STATE.md).

**Why this project exists:**
The user's payments fan out through multiple providers — ASN for direct debits and iDEAL, ICS Cards for credit-card purchases (settled in bulk back to ASN), PayPal as an intermediate funder that pulls from either ASN or ICS depending on configuration, and Google Play as another billing layer. Before v1.0, tracing a single subscription back to the real funding source required manually cross-referencing four sources. v1.0 closed that gap.

**Technical environment:**
- macOS development machine; Laravel Herd as the primary host (`https://diederik.test`)
- SQLite on local disk; backups via `php artisan db:backup` to `storage/app/backups/`
- Single-user
- launchd plists installed via `php artisan diederik:install --launchd` for scheduler + queue worker + IMAP-idle worker

**User context:**
- Single user (the developer); partner-sharing remains a future possibility — schema is ready
- Comfortable with technical setup, including OAuth dance for Gmail + Microsoft 365
- Prefers a calm, content-first aesthetic over dense data-table or chart-heavy designs
- Wants a working app fast — vertical MVP per phase. This was honoured through all 11 phases.

## Constraints

- **Tech stack**: PHP 8.5 + Laravel 13 (latest released March 2026) — User preference, mature ecosystem; pin to current versions to stay supported and avoid legacy deprecation cycles
- **Email integration**: Provider APIs only (Gmail API, Microsoft Graph) — Avoids any dependency on `ext-imap` (removed from PHP 8.4 core) and the IMAP library churn. iCloud Mail is explicitly out of scope
- **OAuth redirect URI scheme**: Google and Microsoft both reject `https://*.test` subdomains as redirect URIs. Diederik uses the RFC 8252 loopback IP scheme `http://127.0.0.1:PORT/oauth/callback/{provider}` for the OAuth dance — the port is read from the `app.url` configuration value (with a fallback to port 8000). The OAuth callback never leaves the device. Laravel Herd's `https://diederik.test` remains the primary UI entry point; the loopback URI is used only during the ~2-second consent redirect.
- **Modular architecture**: Code is organized into bounded modules via `nwidart/laravel-modules` — Enforces clean boundaries between Ingestion, Ledger, Categorization, Recurring, Chains, Forecasting, EmailScan, Receipts, DriftAlerts, Transfers. Cross-module access goes through public service classes or events; no module reaches into another's models or internals
- **Code quality gates (CI-enforced)**: Larastan at level 10 (max) with strict mode + Laravel Pint formatting + Pest unit/feature tests — Every PR must pass all three before merge. No frontend tests are required (the UI is server-rendered + thin; investment goes into backend correctness)
- **Dependency Injection only — no global helpers or facade calls**: All collaborators are constructor-injected. Forbidden: helper functions (`auth()`, `request()`, `config()`, `app()`, `now()` etc.) and facade static calls (`Auth::user()`, `DB::table()`, `Cache::get()`, etc.). Allowed: Eloquent models used directly (instantiation, `Model::find()`, relationships, query builder via `$model->newQuery()`). Reason: explicit dependencies make Larastan level 10 honest, unit tests trivial, and module boundaries enforceable
- **Project slicing**: Vertical MVP per phase — Each phase ends with an end-to-end demoable capability, not an isolated layer
- **Hosting**: Local only (localhost) — Privacy requirement; financial data must never leave the machine
- **Idempotency**: All ingestion paths (CSV upload, IMAP scan, .eml import) must be safe to re-run — Same source + same transaction must never duplicate
- **History**: Full history retained forever — Long-term subscription-drift analysis requires it; pruning is a non-goal
- **Multi-user readiness**: Single-user v1 but schema must permit a second user later without migration pain — User intends to share with a partner once the product is proven
- **Currency**: Multi-currency tracking required from v1 — Google Play (USD) and some ICS merchants charge non-EUR; preserving both currencies prevents losing FX information that can't be recovered later
- **Secrets**: IMAP / OAuth credentials live in local files with chmod 600, not the DB — Enforced via `OAuthSecretsRepository` and `PLT-03` BoundaryArchTest invariant

## Key Decisions

<!-- Decisions that constrain future work. Outcome column: ✓ Good (validated), ⚠️ Revisit, — Pending. -->

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| PHP 8.5 + Laravel 13 (latest) | User preference; strong ecosystem; staying current avoids legacy deprecation pain | ✓ Good — held through 11 phases without surprise |
| Email via provider APIs (Gmail API + Microsoft Graph), not IMAP | Decouples from `ext-imap` deprecation; cleaner OAuth flow | ✓ Good — Phase 6 OAuth flow worked; webklex was never needed |
| `nwidart/laravel-modules` for module structure | Enforces bounded contexts at the directory level | ✓ Good — 11 modules at v1.0, zero cross-module leakage caught by arch tests |
| Vertical MVP phase slicing | Each phase demoable end-to-end; faster feedback | ✓ Good — every phase produced a usable slice |
| Larastan level 10 strict + Pint + Pest as required CI gates | Highest practical static-analysis bar; uniform formatting; tests live in Pest only | ✓ Good — quality gates held 1644 tests green at close |
| DI-only — no helpers, no facade calls; models direct | Explicit constructor dependencies; trivial unit tests; honest Larastan typing | ✓ Good — invariant proven sustainable across 11 phases; arch tests caught regressions early |
| Local-only deployment, no cloud | Privacy of financial data is paramount | ✓ Good — no scope drift |
| Mixed ingestion (CSV + email + file import) instead of bank APIs | ICS and Google Play lack usable APIs; GoCardless stopped accepting Dutch banks mid-stack | ✓ Good — covered every needed source |
| Scan all inboxes for everything (no per-source inbox config) | Lower setup friction; catches forwarded receipts the user forgot about | ✓ Good — Phase 6 implementation worked first try |
| Single-user v1, multi-user-ready schema | Don't ship complexity that isn't used yet, but avoid a painful migration later | ✓ Good — `user_id` + `BelongsToUser` proved its worth in cross-user 404 tests |
| Idempotent imports as a hard requirement | User explicitly flagged the risk of overlapping CSV downloads / repeated email scans | ✓ Good — v3 fingerprint + cross-format enrichment held against real data |
| Income is a first-class concept, not "negative expense" | Cash-flow forecasting must balance both sides | ✓ Good — Phase 8 IncomeSeriesDetector + Phase 10 forecast both depend on this |
| Calm + readable aesthetic (Linear / Notion vibe) | User preference; this is a tool for daily glance | ✓ Good — Flux UI + Tailwind 4 carried it; Filament was correctly avoided |
| Secrets in config file (chmod 600), not DB | Simplest portable approach for a single user; keeps secrets out of DB backups | ✓ Good — `OAuthSecretsRepository` + `PLT-03` invariant |
| Async chain resolution via Laravel Horizon + Redis (stack override, Phase 5) | The chain-resolver job needs `ShouldBeUniqueUntilProcessing` per-user locking and a real dashboard | ✓ Good — Horizon held through Phase 5–11; loopback-bound Redis carve-out validated |
| Redis runs as a loopback-bound Docker container (stack override, Phase 5) | Single network-only carve-out from the no-Docker rule | ✓ Good — `127.0.0.1:6379` posture held; no Sail-on-Mac performance trap |
| Suggest-never-auto-apply for recurring detection (Phase 8) | Industry consensus; protects against false positives that erode trust | ✓ Good — state machine enforced via SQLite triggers + arch test |
| State machines as sole mutators of `state` columns (Phases 5, 8, 9, 10) | Prevents drift; makes audit straightforward; trigger-enforced at DB layer | ✓ Good — pattern propagated through 4 phases without escape hatches |
| `enriched_from` JSON column as append-only provenance trail (Phase 2) | Avoids destructive overwrites when CAMT enriches an earlier CSV row | ✓ Good — Phase 5 chain-resolver consumed it cleanly |
| ApexCharts via Livewire wrapper (Phases 8, 10) | Calm defaults, SVG render, free + MIT, fits Tailwind aesthetic | ✓ Good — recurring drill-in + forecast charts both shipped clean |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

<details>
<summary>Pre-v1.0 history (collapsed)</summary>

The original PROJECT.md tracked all v1 requirements (FND / ING / EML / LED / CHN / CAT / REC / FCT / UI / MC / PLT prefixes) in `### Active`. All 67 of those requirements except ING-09 (deferred-with-trigger) shipped in v1.0; the per-phase mapping is preserved in [milestones/v1.0-REQUIREMENTS.md](milestones/v1.0-REQUIREMENTS.md).

</details>

---
*Last updated: 2026-05-19 after v1.0 milestone close*
