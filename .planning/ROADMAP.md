# Roadmap: diederik

## Overview

diederik is built as a vertical-MVP Laravel monolith: each phase ships an end-to-end demoable slice of the "show me what I actually owe and where the money truly came from" core value. Phase 1 lands a working "see my ASN month" experience — schema, idempotent ASN CSV ingestion, and a calm month-at-a-glance view — proving the spine before any second source touches the database. From there each phase is another vertical slice: richer ASN formats, then ICS + multi-currency display, then PayPal with its event-log rollup, then the headline chain-resolution differentiator (PayPal → underlying funder and ASN→ICS bulk-iDEAL decomposition), then email receipt scanning (Gmail/Graph + `.eml`/`.mbox`) with per-sender template matchers, then recurring detection + fixed-payments view, then subscription drift alerts, then cash-flow forecasting + what-if, and finally operational hardening that turns the app into a daily-use tool. The hard dependency graph from research is honored throughout: idempotency exists before the second source; multi-currency lands in the schema before any non-EUR row; chain resolution waits until all three primary sources are stable; recurring detection waits for ≥3 months of history; forecasting depends on recurring + income detection; what-if depends on forecasting.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [ ] **Phase 1: Foundation + ASN CSV Vertical Slice** - See my ASN month: bounded schema, idempotent ASN CSV import, manual categorization, calm month-at-a-glance view
- [ ] **Phase 2: ASN Statement Coverage (CAMT.053 + MT940)** - Richer ASN ingestion via SEPA-native CAMT.053 (primary) and MT940 fallback with stable EndToEndId source references
- [ ] **Phase 3: ICS Cards + Multi-Currency Display** - Import ICS card statements with dual-amount FX preservation, plus multi-currency views and original-currency display
- [ ] **Phase 4: PayPal Ingestion + Transfer Detection** - PayPal CSV with Transaction ID rollup and optional Reporting API, transfer-pair linking, income-vs-transfer detection
- [ ] **Phase 5: Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition)** - The killer differentiator: deterministic + fuzzy PayPal→funder links, ASN→ICS bulk-settlement decomposition, full chain drill-down
- [ ] **Phase 6: Email Receipt Ingestion Infrastructure** - Gmail API + Microsoft Graph OAuth, multi-inbox UID-resume scanning, queued background workers, rate-limit-safe backfill
- [ ] **Phase 7: Email Template Matchers + Categorization Learning** - Per-sender matchers (PayPal, ICS, Google Play), `.eml`/`.mbox` import, per-merchant memory + user-defined categorization rules
- [ ] **Phase 8: Recurring Detection + Fixed Payments View** - Detect recurring expenses and income at any cadence, normalize to monthly equivalent, surface fixed-payments dashboard with funding-chain icons
- [ ] **Phase 9: Subscription Drift Detection + Alerts** - Flag drifted recurring series with annualized impact, dedicated alerts view with acknowledge/snooze/what-if-cancel actions
- [ ] **Phase 10: Cash-Flow Forecasting + What-If Scenarios** - 30/60/90-day per-account projection with uncertainty ranges, surplus/shortfall windows, non-persisted what-if mutations with side-by-side comparison
- [ ] **Phase 11: Operational Hardening** - `db:backup` via `VACUUM INTO`, restore-verification, launchd polish, post-scan summaries, daily-use reliability

## Phase Details

### Phase 1: Foundation + ASN CSV Vertical Slice
**Goal**: User can import an ASN CSV export and see the current month at a glance — total in, out, remaining — with the ability to manually categorize transactions, and re-uploading the same file changes nothing.
**Mode:** mvp
**Depends on**: Nothing (first phase)
**Requirements**: FND-01, FND-02, FND-03, FND-04, FND-06, FND-07, ING-01, ING-06, ING-07, ING-08, LED-01, LED-02, MC-01, CAT-01, CAT-03, CAT-05, UI-01, UI-04, UI-05, PLT-01, PLT-02, PLT-05
**Success Criteria** (what must be TRUE):
  1. User can log in at `http://127.0.0.1` with single-user credentials and the app refuses any non-loopback bind
  2. User can upload an ASN CSV via a Livewire form, declare it as "ASN CSV", and see the imported transactions appear in a list view scoped to the recent window
  3. User can re-upload the exact same ASN CSV (or an overlapping period) and zero new rows are created — verified by a Pest test
  4. User can open the "this month at a glance" home view and see income / expenses / net for the current month in a calm, monochrome layout
  5. User can manually assign categories to transactions, override existing categorizations, and view a triage list of everything still uncategorized
**Plans**: 7 plans
  - [x] 01-01-PLAN.md — Project scaffold + DI-enforcement gate + 7 contract tests (red baseline)
  - [x] 01-02-PLAN.md — Auth + LoopbackOnly + diederik:install + walking skeleton runnable
  - [x] 01-03-PLAN.md — Ledger schema + Money VO + FingerprintComposer + RecordTransactions
  - [x] 01-04-PLAN.md — ASN CSV adapter + real fixture (empirical A1/A2 resolution)
  - [x] 01-05-PLAN.md — Import pipeline + upload wizard UI (IdempotencyContractTest GREEN)
  - [x] 01-06-PLAN.md — Dashboard /  + /transactions list (UI-01 + UI-04)
  - [x] 01-07-PLAN.md — Default categories + AssignCategory + /uncategorized triage (CAT-01/03/05)
**UI hint**: yes

### Phase 2: ASN Statement Coverage (CAMT.053 + MT940)
**Goal**: User can ingest the richer ASN bank-statement formats (CAMT.053 as primary, MT940 as legacy fallback) and have transactions deduplicated against existing CSV imports via stable SEPA `EndToEndId` / `AcctSvcrRef` references.
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: ING-02, ING-03
**Success Criteria** (what must be TRUE):
  1. User can upload an ASN CAMT.053 XML export and see its transactions imported with `EndToEndId` populated as the primary source reference
  2. User can upload an ASN MT940 export covering older statement periods and have it ingested via the same pipeline
  3. Importing CAMT.053 and CSV exports that cover the same period produces a single set of transactions — no cross-format duplicates
**Plans**: 5 plans
  - [x] 02-01-PLAN.md — Wave 0: composer require genkgo/camt, anonymised CAMT.053 + MT940 fixtures, phase-2 Pest group registration
  - [x] 02-02-PLAN.md — Wave 1 foundation: FingerprintComposer v3 (drop source_ref, add booked_at) + RederiveFingerprintsCommand + schema migrations + FingerprintDisposition/PendingEnrichment DTOs
  - [x] 02-03-PLAN.md — Wave 2 CAMT.053 vertical slice: AsnCamt053Adapter via genkgo/camt + HeaderSniffer + statement_summaries + wizard option + end-to-end (Success Criterion #1)
  - [ ] 02-04-PLAN.md — Wave 2 MT940 vertical slice: hand-rolled lexer + Tag61/Tag86 parsers + counterparty cleaner + adapter + wizard option + end-to-end (Success Criterion #2)
  - [ ] 02-05-PLAN.md — Wave 3 ENRICHED state + cross-format dedup: FingerprintStage::classify, ApplyEnrichments, pipeline integration, Blade ENRICHED row state, CrossFormatDedupTest (Success Criterion #3)

### Phase 3: ICS Cards + Multi-Currency Display
**Goal**: User can import ICS Cards CSV or Excel statements with non-EUR charges preserved as both original-currency and settled-EUR, and switch transaction views between EUR-only and dual-currency.
**Mode:** mvp
**Depends on**: Phase 2
**Requirements**: ING-04, LED-03, MC-02, UI-06
**Success Criteria** (what must be TRUE):
  1. User can upload an ICS CSV or Excel statement and see foreign-currency charges (e.g. a USD purchase) display both the original `$X` amount and the settled EUR amount on the transaction line
  2. User can toggle the transaction list between EUR-only and dual-currency presentation and per-transaction FX rates surface when available
  3. The schema preserves original-amount, original-currency, settled-amount, settled-currency, and FX rate for every transaction (verified to never lose FX information that the source provided)
**Plans**: TBD
**UI hint**: yes

### Phase 4: PayPal Ingestion + Transfer Detection
**Goal**: User can import PayPal activity — via CSV (canonical) and optionally the PayPal Reporting API — with the event-log rolled up into a single canonical transaction per payment, and have ASN↔ICS / PayPal↔bank moves correctly flagged as internal transfers rather than income.
**Mode:** mvp
**Depends on**: Phase 3
**Requirements**: ING-05, ING-09, LED-04, LED-05
**Success Criteria** (what must be TRUE):
  1. User can upload a PayPal activity CSV and see one transaction per payment (fees, holds, and currency-conversion rows enriching that single row rather than appearing as duplicates)
  2. User can optionally authorize PayPal via OAuth2 and pull recent activity directly through the Reporting API, with the CSV path remaining as the supported fallback
  3. Internal moves between the user's own accounts (ASN → ICS, PayPal → bank) appear as paired transfer-out / transfer-in rows linked via `pair_transaction_id` and never inflate income totals
  4. Genuine income (salary, refunds, third-party transfers in) is flagged distinctly from internal transfers, with manual override available
**Plans**: TBD

### Phase 5: Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition)
**Goal**: User can see exactly where every charge came from — PayPal payments traced back to the funding card or account, and the monthly ASN → ICS lump-sum iDEAL settlement decomposed into the individual ICS card transactions it covers.
**Mode:** mvp
**Depends on**: Phase 4
**Requirements**: CHN-01, CHN-02, CHN-03, CHN-04, CHN-05, CHN-06, CHN-07, UI-02
**Success Criteria** (what must be TRUE):
  1. User opens a Netflix-via-PayPal transaction and sees the full chain tree back to the ASN or ICS account that ultimately funded it
  2. User sees the monthly ASN → ICS iDEAL debit decomposed into the underlying ICS card transactions it settles, with partial-payment / overpayment / carry-forward credit handled within ±€5 / ±2% / ±10-day tolerances
  3. User can review fuzzy match candidates (no shared reference ID) in a queue, confirm or reject each, and confirmed patterns auto-promote similar future candidates
  4. User sees the next forecasted ICS settlement amount before paying it, computed from cleared ICS lines since the last settlement
**Plans**: TBD
**UI hint**: yes

### Phase 6: Email Receipt Ingestion Infrastructure
**Goal**: User can connect Gmail and/or Microsoft 365 inboxes via OAuth2 and have the app scan them on a schedule (and on demand) for transaction receipts, with a configurable backfill window, rate-limit-safe sequential fetching, UID-based resume, and visible scan health.
**Mode:** mvp
**Depends on**: Phase 5
**Requirements**: EML-01, EML-02, EML-03, EML-04, EML-06, EML-08, PLT-03, PLT-04
**Success Criteria** (what must be TRUE):
  1. User can authorize one or more Gmail accounts and one or more Microsoft 365 accounts via OAuth2 and see them listed as connected inboxes
  2. User can configure each inbox's historical backfill window (1–12 months, default 3) and the backfill runs as a queued background job — never blocking the UI
  3. After a kill / restart, the scanner resumes from the last successful UID per inbox+folder instead of re-scanning from scratch
  4. User sees a health view with "last scan: X hours ago" per inbox and persistent failures (rate limits, auth) surface there with exponential-backoff retry behavior
  5. OAuth client secrets and refresh tokens live in a chmod-600 config file outside the database, and background workers run via macOS `launchd`
**Plans**: TBD
**UI hint**: yes

### Phase 7: Email Template Matchers + Categorization Learning
**Goal**: User sees email receipts from PayPal, ICS Cards, and Google Play become canonical transactions automatically (with `.eml`/`.mbox` drop-in as an alternative path), and after categorizing a merchant once, the same category gets auto-suggested for future transactions — with user-defined rules as an additional layer.
**Mode:** mvp
**Depends on**: Phase 6
**Requirements**: EML-05, EML-07, CAT-02, CAT-04
**Success Criteria** (what must be TRUE):
  1. User receives a PayPal, ICS, or Google Play receipt; the next scan extracts merchant, amount, currency, and reference IDs and creates the canonical transaction (with chain hints feeding the resolver from Phase 5)
  2. User can drop an `.eml` or `.mbox` file in the import folder and have it ingested via the same matcher pipeline that runs against IMAP-fetched messages
  3. After the user categorizes a merchant once, the next transaction from that same normalized merchant arrives pre-suggested with the same category
  4. User can define explicit rules ("contains 'SPOTIFY' → Subscriptions / Streaming") that pre-categorize on import, with rule updates offered when corrections diverge from suggestions
**Plans**: TBD
**UI hint**: yes

### Phase 8: Recurring Detection + Fixed Payments View
**Goal**: User can see the curated list of monthly fixed payments — every recurring expense and recurring income (salary, regular transfers) — normalized to a monthly-equivalent amount with funding-source chain icons, and drill into any series to see its full historical occurrences.
**Mode:** mvp
**Depends on**: Phase 7 (and ≥3 months of imported history)
**Requirements**: REC-01, REC-02, REC-03, REC-04, REC-05, LED-06, UI-03
**Success Criteria** (what must be TRUE):
  1. User opens the fixed-monthly-payments view and sees each detected recurring series with its name, normalized monthly equivalent, funding source + chain icon, category, and next expected charge date
  2. Recurring detection tolerates moderate amount variance (e.g. Spotify €9.99 → €11.49) within a single series rather than fragmenting it
  3. Detected series are surfaced as suggestions and only appear on the fixed-payments view once the user approves them (suggest-never-auto-apply)
  4. User can click into any fixed payment and see every historical occurrence plus an amount trend over time
  5. Recurring income (monthly salary, regular transfers in) appears alongside recurring expenses so cash-flow logic can balance both sides
**Plans**: TBD
**UI hint**: yes

### Phase 9: Subscription Drift Detection + Alerts
**Goal**: User gets a dedicated alerts surface for any recurring series whose latest charge differs from the prior baseline beyond a configurable threshold, with the annualized year-over-year cost impact visible and a one-click path to acknowledge, snooze, or jump into a cancellation what-if.
**Mode:** mvp
**Depends on**: Phase 8
**Requirements**: REC-06, REC-07, REC-08
**Success Criteria** (what must be TRUE):
  1. User sees a drift-alerts view (with a count badge on the home dashboard) whenever a recurring series' latest charge crosses the drift threshold (default ±5%), with the annualized impact (e.g. "+€18/yr") shown per alert
  2. Each drift alert persists until the user explicitly acts on it — it cannot be silently missed by navigating away
  3. User can acknowledge the new price, snooze for a configurable interval, or jump into a what-if scenario that models cancellation of the series, and each decision is recorded with timestamp for auditability
**Plans**: TBD
**UI hint**: yes

### Phase 10: Cash-Flow Forecasting + What-If Scenarios
**Goal**: User can see 30 / 60 / 90-day projected balances per account — built from recurring inflows, recurring outflows, and pending settlements — with honest uncertainty ranges, highlighted surplus / shortfall windows, and the ability to apply non-persisted what-if mutations (cancel a subscription, add a planned expense) compared side-by-side with the baseline.
**Mode:** mvp
**Depends on**: Phase 9
**Requirements**: FCT-01, FCT-02, FCT-03, FCT-04, FCT-05
**Success Criteria** (what must be TRUE):
  1. User opens any account and sees a 30 / 60 / 90-day balance projection built from current balance + recurring + pending ICS settlement
  2. Projections display as ranges (e.g. "€1,180 – €1,260 on May 31st") rather than false-precision single-point values
  3. User can highlight surplus or shortfall windows (e.g. "ICS settlement on the 14th will dip you below €X")
  4. User can apply what-if mutations (cancel a series, add a planned transaction, change an amount) and see the impact without anything being written to the database
  5. User can view a what-if scenario side-by-side with the baseline forecast
**Plans**: TBD
**UI hint**: yes

### Phase 11: Operational Hardening
**Goal**: User can run the app as a reliable daily tool — consistent SQLite backups via artisan command, restore verification, and the daily-use reliability touches that close out v1.
**Mode:** mvp
**Depends on**: Phase 10
**Requirements**: FND-05
**Success Criteria** (what must be TRUE):
  1. User can run `php artisan db:backup` and produce a consistent, restorable SQLite backup while the app is running (via `VACUUM INTO` or the online backup API)
  2. The latest backup is automatically verified by re-opening it and running `PRAGMA integrity_check`, with failures surfaced to the user
  3. Operator documentation explicitly forbids `cp database.sqlite` of the live WAL DB and points to `db:backup` as the supported path
**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation + ASN CSV Vertical Slice | 7/7 | Complete | - |
| 2. ASN Statement Coverage (CAMT.053 + MT940) | 1/5 | In progress | - |
| 3. ICS Cards + Multi-Currency Display | 0/TBD | Not started | - |
| 4. PayPal Ingestion + Transfer Detection | 0/TBD | Not started | - |
| 5. Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition) | 0/TBD | Not started | - |
| 6. Email Receipt Ingestion Infrastructure | 0/TBD | Not started | - |
| 7. Email Template Matchers + Categorization Learning | 0/TBD | Not started | - |
| 8. Recurring Detection + Fixed Payments View | 0/TBD | Not started | - |
| 9. Subscription Drift Detection + Alerts | 0/TBD | Not started | - |
| 10. Cash-Flow Forecasting + What-If Scenarios | 0/TBD | Not started | - |
| 11. Operational Hardening | 0/TBD | Not started | - |
