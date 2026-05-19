# Project Research Summary

**Project:** diederik
**Domain:** Local-only personal finance dashboard — multi-source ingestion, cross-account chain resolution, cash-flow forecasting
**Researched:** 2026-05-12
**Confidence:** HIGH (stack and pitfalls verified against official sources); MEDIUM for ICS-bulk-iDEAL specifics — confirmed zero prior art

---

## Executive Summary

Diederik is a local-only Laravel personal finance dashboard that solves a problem no existing tool addresses: tracing the complete funding chain from a subscription charge back through PayPal's intermediary layer to the real bank account (ASN or ICS), and decomposing the ICS monthly bulk iDEAL settlement into the individual card transactions it covers. The closest comparator is Firefly III (PHP/Laravel, self-hosted, multi-currency), but Firefly III lacks both chain resolution and cash-flow forecasting — which are diederik's two headline differentiators. Every other competitor (Monarch, Lunch Money, Actual Budget) is cloud-only or US-centric. This is genuine greenfield territory for the Dutch ICS settlement case; no prior art was found in any tool.

The recommended approach is a **modular Laravel 12 monolith with a pluggable ingestion pipeline**, Livewire 4 + Volt + Flux UI (server-rendered, no SPA layer), SQLite in WAL mode, and the `webklex/laravel-imap` pure-PHP IMAP library. The Phase 1 vertical slice — one source end-to-end proving idempotency — is the right first move. Three foundational decisions must be locked in before any transaction data lands: integer-cents money storage (BIGINT minor units, never floats), `user_id` on every domain table from day one, and pure-PHP IMAP from day one (bypassing the `ext-imap` extension that was removed from PHP 8.4). These are effectively irreversible once data exists.

The key risks map directly to implementation order. Floating-point money and missing `user_id` columns are the highest-cost mistakes to retrofit — requiring full re-import and full schema migration respectively — so they must be treated as non-negotiable before any data is loaded. PayPal CSV is an event log, not a transaction log: its fee and currency-conversion rows must be rolled up by `Transaction ID` before persisting, or monthly totals will never reconcile. ICS bulk-settlement matching must tolerate amount differences (partial payments, overpayments, carry-forward credit) and use a tolerant date window, not exact-match logic. IMAP backfill of years of history must be a background queue job with sequential single-connection fetching and UID resume — not a synchronous HTTP request.

---

## Key Findings

### Recommended Stack

Laravel 12 on PHP 8.3 is the correct version pair. PHP 8.4 removed `ext-imap` from core; all IMAP work must go through `webklex/laravel-imap` (pure PHP, no native extension) — this is non-negotiable for a project that will run for years. Laravel Herd (free tier) on macOS gives zero-setup PHP 8.3 + nginx + `diederik.test` HTTPS with no Homebrew dependency. Livewire 4 + Volt + Flux UI eliminates the Vite/TypeScript/Node build pipeline while delivering calm Linear/Notion aesthetics. SQLite in WAL mode handles personal-finance scale (up to ~60,000 lifetime rows) trivially.

**Core technologies:**
- PHP 8.3 + Laravel 12: language + framework — pins to 8.3 to avoid ext-imap PECL fallout on 8.4
- SQLite 3.45+ WAL mode: local data store — zero setup, single file, WAL for concurrent read/write
- Laravel Herd (free): local dev — native macOS, `diederik.test` HTTPS, PHP version switching
- Livewire 4 + Volt + Flux UI: server-rendered reactive UI — no SPA, pure PHP, calm aesthetic
- `webklex/laravel-imap` 6.2: IMAP — pure PHP, no ext-imap, UID-based incremental sync
- `genkgo/camt` 2.10: CAMT.053 parser — primary ASN bank statement format, actively maintained
- `kingsquare/php-mt940` 2.0: MT940 fallback — stable but stagnant (2020); use as fallback only
- `league/csv` 9.28: all CSV paths — encoding-safe streaming for ASN/ICS/PayPal CSV
- `brick/money` 0.13: multi-currency arithmetic — immutable Money objects, exact arithmetic
- `spatie/laravel-data` 4.x: typed DTOs — immutable wrappers before Eloquent
- Pest 3 + PHPStan level 8: testing and static analysis — dataset tests per source parser
- `database` queue driver + launchd: background jobs — no Redis; macOS launchd for auto-start

### Expected Features

**Must have (table stakes):**
- CSV import per source (ASN, ICS, PayPal) — declare source on upload; no auto-detection
- CAMT.053 import (ASN) — preferred over MT940; `EndToEndId` is the stable dedup key
- Idempotent re-import — fingerprint on `(account_id, posted_at, amount_minor, currency, normalized_counterparty, source_ref)`; `UNIQUE(source_id, external_id)` enforced at DB layer
- Multi-currency dual-amount from day one — `original_amount`/`original_currency` + `settled_amount`/`settled_currency`; losing FX info is irreversible
- Transaction list with filters, per-month "this month at a glance" dashboard
- Manual categorization with per-merchant memory (rules + learned mapping, no LLM)
- Income vs. internal-transfer detection — ASN→ICS settlement is not income; salary is
- Single-user auth + localhost-only binding (127.0.0.1)

**Should have (differentiators — no prior art for the top two):**
- **PayPal → ASN/ICS funding-chain resolution** — deterministic via `Transaction ID`; fuzzy fallback on amount + date; learning loop from user confirmations — **no competitor does this natively**
- **ICS bulk iDEAL settlement decomposition** — match ASN lump iDEAL debit to sum of ICS statement lines; reverse-link each ICS line to parent ASN settlement — **zero prior art in any personal finance tool**
- ICS next-settlement forecast — "what will I owe ICS this month?"
- Cash-flow forecast 30/60/90 day — Firefly III explicitly and permanently lacks this
- What-if scenarios — non-persisted in-memory overlay (cancel Netflix, add planned expense)
- Recurring detection with approval queue — suggest, never auto-apply
- Funding-chain drill-down UI — tree/flow from any charge to root funding source
- IMAP receipt scanning — multi-inbox, app-password, 3-month backfill capability
- Subscription drift / trend per recurring item

**Defer (v2+):**
- Multi-user / partner sharing — schema supports it from v1; UI/auth deferred
- Receipt-image OCR, PSD2/open-banking APIs, mobile native app, tax/VAT reporting, investment tracking — all explicitly out of scope per PROJECT.md

### Architecture Approach

A **modular Laravel monolith with a five-stage ingestion pipeline** (Acquire → Parse → Normalize → Fingerprint → Load) and a separate async chain-resolution engine. Each bounded domain (Ingestion, Ledger, Categorization, Chains, Recurring, Forecasting) owns its models, services, jobs, and actions under `app/Domain/`. Cross-module coordination via Laravel events (`TransactionImported`) so enrichment modules never touch the Loader. Single `transactions` table with `type` enum — not double-entry — keeps every cross-cutting query a simple `SELECT SUM`. Chain relationships in a `chain_links` table with `state` (candidate/confirmed/rejected) and `confidence` making the learning loop observable. Forecasted occurrences are transient DTOs that are never persisted.

**Major components:**
1. **IngestionPipeline** — one `SourceAdapter` per format; same pipeline for all sources; post-load jobs queued asynchronously
2. **Ledger** (Account, Transaction, Currency, Merchant) — the only module that writes `transactions`
3. **ChainResolver** (PayPalFundingResolver, IcsSettlementResolver) — async queued job; writes `chain_links` only; never modifies transactions
4. **RecurrenceDetector** — async post-import job; groups by normalized merchant; creates `RecurringSeries` candidates for user approval
5. **BalanceProjector + WhatIfEngine** — pure read-only; transient `ForecastedTransaction` DTOs; cache keyed on `max(transactions.updated_at)` for auto-invalidation
6. **IMAPScanner + TemplateMatcher** — per-sender templates; `InboxScanState` tracks last UID per folder/inbox; UIDVALIDITY-safe
7. **CurrentUser facade** — the multi-user seam: returns `User::find(1)` in v1; swapped to `auth()->user()` in v2 with no domain code changes

### Critical Pitfalls

1. **Floating-point money storage** — store all amounts as signed BIGINT minor units (cents); forbid REAL/FLOAT columns via CI grep gate; any float written to the DB produces permanently corrupted balances with no fix short of full re-import

2. **Transaction identity from free-text description** — fingerprint must use `(account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)` and never include free-text; use CAMT.053 `EndToEndId`/`AcctSvcrRef` as primary stable source reference; description text is unstable across exports

3. **PayPal CSV is an event log, not a transaction log** — group by `Transaction ID` / walk `Reference Txn ID` chains before persisting; fee rows are enrichment; "Transfer to bank" rows are funding-chain hints to ASN/ICS, not expenses; failing this produces monthly totals that never reconcile

4. **PHP 8.4 ext-imap removal** — configure `webklex/laravel-imap` for pure-PHP socket driver explicitly; `composer.json` must not contain `"ext-imap": "*"`; CI must include PHP 8.4; cheap to do right on day one, expensive to discover after years of deployment

5. **Missing `user_id` on every domain table** — add `user_id` (nullable v1) to every domain table including transactions, accounts, chain_links, categorization_rules, categories, recurring_series, merchants, inbox_scan_state; wrap all queries in `BelongsToUser` trait; retrofitting this after years of data is a full schema migration with no clean backfill path

6. **ICS settlement exact-match logic** — ICS statements are paid in installments with overpayments carrying forward and refunds arriving after statement close; matcher must use `amount within ±€5 / ±2%` across `±10-day window`; model a `card_statement` entity with partial settlement tracking; a boolean `is_settled` is insufficient

---

## Implications for Roadmap

### Phase 1: Foundation + Vertical Slice (ASN CSV end-to-end)
**Rationale:** Prove the entire pipeline — schema, fingerprinting, idempotency, one importer, one list view — before building any second source. Every downstream feature depends on the transaction model being correct. This is also the moment to lock in the non-negotiable decisions at near-zero cost: integer-cents, user_id everywhere, pure-PHP IMAP, WAL backup. Getting them wrong here costs the entire project.

**Delivers:** ASN CSV import, idempotent re-import verified by test (re-import same file twice → zero new rows), canonical transaction model, per-month dashboard in/out/remaining, manual categorization with per-merchant memory, single-user auth + localhost binding, SQLite WAL + `db:backup` via `VACUUM INTO`.

**Non-negotiables locked in this phase:**
- BIGINT minor units for all amount columns — grep gate in CI
- `user_id` (nullable) + `BelongsToUser` trait on every domain table
- `UNIQUE(source_id, external_id)` + `UNIQUE(account_id, fingerprint)` at DB layer
- `webklex/laravel-imap` pure-PHP driver configured (no ext-imap)
- SQLite WAL mode + `db:backup` artisan command

**Avoids:** Pitfalls 1 (float money), 2 (unstable identity), 10 (WAL backup), 11 (user_id), 12 (inconsistent amount scales)

---

### Phase 2: Full Ingestion Suite (ICS + PayPal + multi-currency)
**Rationale:** Add the two remaining primary sources before chain resolution, which requires all three. Multi-currency dual-amount storage must be in place before any ICS or PayPal row lands — it cannot be retrofitted.

**Delivers:** ICS CSV/Excel import with dual-amount FX preservation, PayPal CSV import with `Transaction ID` roll-up (fees as enrichment, not separate transactions, "Transfer to bank" tagged as funding-chain hints), income vs. internal-transfer heuristic detection, transfer-pair linking.

**Avoids:** Pitfall 3 (PayPal event log), Pitfall 9 (FX divergence), "settlement counted as income" bug

**Research flag:** Needs phase research — ICS CSV column layout and PayPal event-type taxonomy need empirical validation against real exports before writing adapters.

---

### Phase 3: Chain Resolution (PayPal + ICS Settlement) — the killer differentiators
**Rationale:** The two headline differentiators. Both require all three source importers to be stable. This is the core value of the product. Chain resolution runs async (queued job) — architecture designed for this from Phase 1.

**Delivers:** PayPal → ASN/ICS funding-chain resolution (deterministic via reference ID; fuzzy with confidence scoring; user confirmation queue; auto-promotion after N confirmations), ICS bulk iDEAL settlement decomposition (tolerant amount/date matching, partial-payment + overpayment + carry-forward handling, `chain_links` with sum verification invariant), ICS next-settlement forecast, funding-chain drill-down UI, fixed monthly payments view with chain icons.

**Key constraints:** `ChainLink` has `state`/`confidence`/`evidence`; resolver writes `chain_links` only; ICS matcher uses ±€5/±2%/±10-day tolerance; per-user job uniqueness prevents parallel resolution.

**Avoids:** Pitfall 4 (ICS settlement collapses), Pitfall 9 (cross-source merchant/FX divergence)

**Research flag:** HIGH need for phase research on ICS CSV/Excel structure and ASN CAMT.053 iDEAL settlement field layout — no public documentation; empirical validation against real exports required before writing the resolver.

---

### Phase 4: IMAP Receipt Scanning + Email Parsers
**Rationale:** Email scanning fills gaps for transactions that only appear as receipts (Google Play, etc.). Comes after chain resolution because receipts enrich existing chains, not the core ledger. Rate-limiting pitfall is severe enough to require well-designed background queue before touching a real inbox.

**Delivers:** IMAP multi-inbox scanning (Gmail/iCloud/Outlook) with app-password auth, UID-based incremental sync with UIDVALIDITY handling, rate-limit-safe sequential single-connection fetching with exponential backoff, 3-month backfill via artisan command, per-sender template matchers (PayPal, ICS, Google Play), `.eml`/`.mbox` file import, generic low-confidence fallback with user review queue, launchd plist for macOS scheduling.

**Non-negotiables:** Pure-PHP IMAP driver; single connection per inbox, sequential UID fetch; `InboxScanState` with `last_uid` + `uid_validity`; "Scan now" enqueues, never blocks HTTP.

**Avoids:** Pitfalls 5 (PHP 8.4 IMAP removal), 6 (Gmail rate-limiting / lockout), 7 (HTML email parsing fragility)

**Research flag:** Needs phase research — Gmail and iCloud rate-limit behavior under real backfill must be tested before setting queue concurrency/backoff parameters. Per-sender templates need real anonymized email fixtures as test corpus.

---

### Phase 5: Recurring Detection + Cash-Flow Forecasting
**Rationale:** Recurring detection requires ≥3 months of imported history. Cannot come earlier. Order is strict: imports → categorization → recurring → income detection → forecasting → what-if.

**Delivers:** Recurring series detection (group by normalized merchant, median interval, cadence snap to weekly/monthly/quarterly/yearly, ±25% amount tolerance, price-change detection), always-suggest-never-auto-apply approval queue, per-occurrence amount tracking (not fixed series amount), 30/60/90-day per-account balance projection, surplus/shortfall threshold alerts, what-if scenarios as in-memory `WhatIfScenario` value objects (never persisted, no `save()` method), comparison view baseline vs. scenario, forecast displayed with uncertainty range (not single-point precision).

**Avoids:** Pitfalls 8 (recurring brittleness on price changes), 15 (what-if state leaking into persisted state), 19 (forecast false precision)

---

### Phase 6: Polish + Operational Hardening
**Rationale:** Makes the app reliable as a daily-use tool. Launchd automation, health monitoring, migration safety are low-risk to defer and high-distraction during feature development.

**Delivers:** launchd plist files for queue worker + scheduler + inbox scanner, health-check dashboard ("last successful scan: X hours ago"), `db:backup` with nightly `VACUUM INTO` + `PRAGMA integrity_check` verification, CAMT.053 ASN import (preferred; replaces MT940 as primary), MT940 fallback, subscription drift/trend, export to CSV/JSON, per-transaction notes, empty-state UX, post-scan summary, migration-safety pre-backup hook.

**Avoids:** Pitfalls 10 (WAL backup corruption), 13 (scheduler/queue silent failures), 17 (migration safety with historical data), 18 (chain viz with no actionable affordances)

---

### Phase Ordering Rationale

The strict dependency graph enforces the above order. Idempotency must exist before the second source is added. Multi-currency must be in the schema before ICS or PayPal rows land. Chain resolution requires all three source importers. Recurring detection requires ≥3 months of history. Forecasting requires recurring detection. What-if requires forecasting.

Chain resolution (Phase 3) precedes IMAP (Phase 4) because CSV sources produce the bulk of transactions. IMAP receipts are enrichment — they fill gaps in the chain, not the core ledger.

---

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Every library version verified against Packagist May 2026; PHP 8.4 ext-imap removal confirmed via official PHP docs; Laravel 12 official release notes |
| Features | HIGH (table stakes) / MEDIUM (chain resolution) | Table stakes verified across Lunch Money, Actual, Firefly III, Monarch official docs; ICS settlement is novel inference with zero prior art |
| Architecture | HIGH (Laravel patterns) / MEDIUM (chain-resolution specifics) | Modular monolith + pipeline + event-driven enrichment are established; ChainLink confidence model sound but untested against real ICS/PayPal data |
| Pitfalls | HIGH (money/IMAP/SQLite/schema) / MEDIUM (recurring/chain heuristics) | Float money, IMAP removal, SQLite WAL backup verified via official sources; ICS-specific quirks require empirical confirmation |

**Overall confidence:** HIGH for the foundation and stack. MEDIUM for the domain-novel chain resolution specifics.

### Gaps to Address During Phase Research

- **ICS CSV/Excel exact column layout:** Not publicly documented. Confirm field names, FX column positions, statement-period grouping against a real ICS export before writing `IcsCsvAdapter`. Address in Phase 2 planning.
- **PayPal event-type taxonomy:** Which `Type` values to keep vs. skip needs empirical validation against the user's actual PayPal CSV history. Address in Phase 2 planning.
- **ASN CAMT.053 iDEAL settlement field:** The exact representation of the ICS iDEAL settlement debit in ASN CAMT.053 needs confirmation from a real export. Matching key for Phase 3.
- **IMAP rate-limit thresholds:** Gmail/iCloud real-world throttling thresholds. Test backfill on a real inbox during Phase 4 planning.
- **Annual recurring detection minimum history:** Detector needs ≥13 months of data to confirm yearly subscriptions.

---
*Research completed: 2026-05-12*
