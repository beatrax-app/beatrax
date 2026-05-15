# diederik — v1 Requirements

**Project:** Local-only Laravel personal finance dashboard with cross-account chain resolution
**Version:** v1
**Stack pin:** PHP 8.5 + Laravel 13 (latest released March 2026)
**Last updated:** 2026-05-12

---

## v1 Requirements

### Foundation

- [ ] **FND-01**: App binds to `127.0.0.1` only; no external network exposure
- [ ] **FND-02**: User can log in with a single-user credential (username + password) before viewing any data
- [ ] **FND-03**: Every domain table includes a nullable `user_id` column wired to a `BelongsToUser` trait, so a second user can be enabled later without schema migration
- [ ] **FND-04**: All monetary amounts are stored as signed `BIGINT` minor units (cents); no `REAL`/`FLOAT` columns are used for money
- [ ] **FND-05**: User can run an artisan `db:backup` command that produces a consistent SQLite backup (via `VACUUM INTO` or online backup API), safe to copy while the app is running
- [ ] **FND-06**: SQLite database runs in WAL mode with `synchronous=NORMAL` set on app startup
- [ ] **FND-07**: Currency arithmetic uses `brick/money` value objects throughout the domain code

### Ingestion — Bank & Card Sources

- [ ] **ING-01**: User can upload an ASN CSV export and have its transactions imported into the canonical transaction store
- [x] **ING-02**: User can upload an ASN CAMT.053 (XML) export and have its transactions imported, using `EndToEndId` / `AcctSvcrRef` as the stable source reference
- [x] **ING-03**: User can upload an ASN MT940 export as a fallback ingestion path (older statement periods)
- [x] **ING-04**: User can upload an ICS Cards CSV or Excel statement and have its transactions imported, with original-currency + settled-EUR preserved per line where applicable
- [ ] **ING-05**: User can upload a PayPal activity CSV and have its transactions imported, with the event-log rolled up by `Transaction ID` / `Reference Txn ID` so fees, holds, and currency-conversion rows enrich a single canonical transaction (rather than landing as duplicates)
- [x] **ING-06**: Re-uploading the same statement file (or an overlapping period) does not create duplicate transactions — idempotent by a v3 fingerprint of `(user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)` enforced at the DB layer. Cross-format re-imports (CSV ↔ CAMT.053 ↔ MT940) ENRICH existing rows with stronger source_ref via the rank function (asn-camt053 > asn-mt940 > asn-csv) rather than inserting duplicates.
- [ ] **ING-07**: User can declare which source format an upload is (no auto-detection), eliminating a class of misclassification errors
- [ ] **ING-08**: Every imported row preserves a link back to its raw source row (for audit / debugging)
- [ ] **ING-09**: PayPal Reporting API (Transaction Search) is supported as an optional alternative to CSV upload; user authorizes via OAuth2 and the app pulls activity directly. Phase research verifies feasibility for the user's account type (personal vs business). CSV path remains as the supported fallback in case Transaction Search is gated behind a business account.

### Ingestion — Email Receipts (API-based)

- [ ] **EML-01**: User can authorize a Gmail account via OAuth2 (Gmail API) so the app can read message metadata + bodies
- [ ] **EML-02**: User can authorize a Microsoft 365 / Outlook account via OAuth2 (Microsoft Graph) so the app can read message metadata + bodies
- [ ] **EML-03**: User can connect multiple inboxes of each provider type, and the scanner runs against all connected inboxes
- [ ] **EML-04**: User can configure the historical back-fill window per inbox (anywhere from 1 month up to a maximum of 12 months); default is 3 months. Back-fill runs as a queued background job and never blocks the UI
- [ ] **EML-05**: Per-sender template matchers exist for PayPal, ICS Cards, and Google Play receipts; each extracts merchant, amount, currency, and reference IDs into canonical transactions
- [ ] **EML-06**: Inbox scan state is persisted per-inbox per-provider (last successful timestamp / message ID) so incremental scans resume cleanly
- [ ] **EML-07**: User can drop an `.eml` or `.mbox` file in an import folder and have it ingested via the same matcher pipeline (covers iCloud, Fastmail, or any provider without an API)
- [ ] **EML-08**: API rate-limit failures retry with exponential backoff; persistent failures surface in a health view

### Ledger & Transaction Model

- [ ] **LED-01**: Each account (ASN, ICS, PayPal, plus any future) has a distinct record with a type and currency
- [ ] **LED-02**: Each transaction has a `type` (expense / income / transfer-out / transfer-in / fee / refund) instead of being mixed into a polymorphic table
- [x] **LED-03**: Each transaction stores both original-currency amount and settled-EUR amount where the source provides it, plus the FX rate when available
- [ ] **LED-04**: Internal transfers (ASN → ICS, PayPal → bank) are linked via a `pair_transaction_id` so they are not double-counted as income on the receiving side
- [ ] **LED-05**: An income detector flags inflows that are genuine income (salary, refunds, third-party transfers) vs internal moves between owned accounts
- [ ] **LED-06**: Recurring income (e.g. monthly salary) is detected the same way recurring expenses are — by merchant/source clustering and cadence inference

### Chain Resolution

- [ ] **CHN-01**: When a PayPal charge has a matching reference ID present in an ASN or ICS line item, the two are deterministically linked into a chain
- [ ] **CHN-02**: When no reference ID match exists, the system proposes candidate links using merchant + amount + date window heuristics, with a confidence score
- [ ] **CHN-03**: User can confirm or reject candidate links in a review queue; confirmations train future auto-matches (per-merchant memory)
- [ ] **CHN-04**: User can open any transaction and see the full chain tree (e.g. Netflix charge → PayPal → ICS line → ASN bulk-iDEAL settlement → ASN balance impact)
- [ ] **CHN-05**: The ASN → ICS bulk iDEAL settlement is decomposed: one ASN debit links to N underlying ICS transactions; matcher tolerates partial settlement, overpayment, carry-forward credit (±€5 / ±2% / ±10-day window)
- [ ] **CHN-06**: User can see the next forecasted ICS settlement amount before paying it
- [ ] **CHN-07**: Chain links are stored in their own table with `state` (candidate / confirmed / rejected), `confidence` (0–1), and `evidence` (which fields matched)

### Categorization

- [ ] **CAT-01**: User can categorize a transaction by selecting from a tree of categories (e.g. Subscriptions → Streaming, Insurance → Health)
- [ ] **CAT-02**: After categorizing a merchant once, future transactions from the same normalized merchant are auto-suggested the same category
- [ ] **CAT-03**: User can override auto-suggestions; corrections update the per-merchant memory
- [ ] **CAT-04**: User can define rules ("contains 'SPOTIFY' → Subscriptions / Streaming") that pre-categorize on import
- [ ] **CAT-05**: User can see which transactions remain uncategorized and triage them in bulk

### Recurring Detection & Fixed Payments

- [ ] **REC-01**: System detects recurring transactions by clustering on normalized merchant and inferring cadence (weekly / monthly / quarterly / yearly)
- [ ] **REC-02**: Detector tolerates moderate amount variance (e.g. ±25%) so price changes (Spotify €9.99 → €11.49) don't break the series
- [ ] **REC-03**: User approves detected series before they appear on the fixed-payments view (suggest-never-auto-apply)
- [ ] **REC-04**: User can see the fixed-monthly-payments overview: name, normalized monthly equivalent, funding source (with chain icon), category, next expected charge
- [ ] **REC-05**: User can drill into a fixed payment to see all historical occurrences and amount-drift over time
- [ ] **REC-06**: System detects subscription drift — flags any recurring series whose latest charge differs from the prior baseline by more than a configurable threshold (default ±5%), and computes the annualized impact (e.g. "+€18/yr") so the user sees the year-over-year cost change at a glance
- [ ] **REC-07**: Drifted series surface in a dedicated "Drift alerts" view (and as a count badge on the home dashboard); the alert persists until the user takes action so it can't be silently missed
- [ ] **REC-08**: User can act on each drift alert via one of three responses: (a) acknowledge the new price as accepted, (b) snooze for a configurable interval to revisit later, or (c) jump straight into a what-if scenario that models cancellation of this series so the cash-flow impact of cancelling is visible before the user calls/emails to cancel. Each acknowledged or dismissed drift records the user's decision + timestamp so the history is auditable

### Forecasting

- [ ] **FCT-01**: User can view a 30 / 60 / 90-day projected balance per account, computed from current balance + known recurring inflows/outflows + pending settlements
- [ ] **FCT-02**: Forecast shows uncertainty (e.g. amount ranges for variable items) rather than presenting a single false-precision number
- [ ] **FCT-03**: User can apply "what-if" mutations (cancel a series, add a planned transaction, change an amount) and see the impact, without those mutations persisting to the database
- [ ] **FCT-04**: User can compare a what-if scenario side-by-side with the baseline forecast
- [ ] **FCT-05**: User can see surplus / shortfall windows highlighted (e.g. "ICS settlement on the 14th will dip you below €X")

### Dashboard & UI

- [ ] **UI-01**: The home screen is a "this month at a glance" view: top-line in/out/remaining, fixed-payments list with chain icons, cash-flow chart
- [ ] **UI-02**: From any transaction, user can drill into its full funding chain
- [ ] **UI-03**: From any fixed payment, user can drill into its history and amount-drift trend
- [ ] **UI-04**: UI defaults to the recent window (last 3–6 months); a "show full history" toggle opens the rest
- [ ] **UI-05**: Aesthetic is calm and content-first (Linear / Notion style), monochrome with one accent color
- [ ] **UI-06**: All currency amounts surface their original currency when different from settled (e.g. "$12.99 USD → €12.07 EUR")

### Multi-Currency

- [ ] **MC-01**: Foreign-currency charges from PayPal, ICS, Google Play preserve original currency + amount alongside the settled EUR amount, captured at import time (cannot be reconstructed later)
- [ ] **MC-02**: User can switch between EUR-only and dual-currency views on transaction lists and reports

### Platform & Privacy

- [ ] **PLT-01**: App runs entirely on localhost (no cloud, no telemetry, no third-party reporting services)
- [ ] **PLT-02**: SQLite database file lives in a path outside iCloud Drive / OneDrive / Dropbox to prevent silent sync of financial data
- [ ] **PLT-03**: OAuth2 credentials (Gmail/Graph client secrets + refresh tokens) and any other secrets live in a config file outside the DB, with restrictive filesystem permissions (chmod 600)
- [ ] **PLT-04**: Background workers (queue + scheduler) run via macOS `launchd` plists, with health visible in the UI ("last scan: X hours ago")
- [ ] **PLT-05**: `composer.json` does not declare `ext-imap`; CI lints for accidental dependence on the deprecated extension

---

## v2 Requirements (Deferred)

- Multi-user / partner UI + auth (schema already supports it)
- iCloud Mail integration (would require IMAP back into the stack)
- Export to CSV / JSON / OFX for tax-prep or external tools
- Per-transaction notes / attachments
- macOS Keychain integration for secret storage
- Annual recurring detection improvements (needs ≥13 months of history to be reliable)
- Receipt-image OCR

---

## Out of Scope (Hard Exclusions)

- Cloud hosting / multi-device sync — privacy requirement
- Bank PSD2 / open-banking API integrations for ASN (Tink, Plaid, Salt Edge, etc.) — GoCardless Bank Account Data (the only previously-free option for Dutch banks) stopped accepting new accounts in July 2025; remaining options are all paid. CSV + MT940 + CAMT.053 covers the same data without recurring cost.
- ICS Cards API integration — no buyer-side API exists.
- Google Play buyer-side API — no public API exists; email receipts are the canonical path.
- Outbound payment initiation / iDEAL execution — system recommends; user pays via their bank
- Investment / brokerage / portfolio tracking — scope is cash & card flow only
- Tax / VAT / bookkeeping reporting — this is a visibility tool, not accounting
- Mobile native client — web UI on localhost is sufficient
- Budgeting / envelope / goals (YNAB-style) — different product
- Full double-entry accounting — adds complexity Firefly III's own creator says drives users away
- LLM categorization — privacy + cold-start accuracy concerns; rules + per-merchant memory are sufficient
- Auto-applied recurring detection — always-suggest-never-auto-apply (industry consensus)
- iCloud Mail integration — no public API; would force IMAP back into the stack

---

## Traceability

Each REQ-ID maps to exactly one phase. Roadmap: `.planning/ROADMAP.md`.

| REQ-ID | Phase | Status |
|--------|-------|--------|
| FND-01 | Phase 1 | Pending |
| FND-02 | Phase 1 | Pending |
| FND-03 | Phase 1 | Pending |
| FND-04 | Phase 1 | Pending |
| FND-05 | Phase 11 | Pending |
| FND-06 | Phase 1 | Pending |
| FND-07 | Phase 1 | Pending |
| ING-01 | Phase 1 | Pending |
| ING-02 | Phase 2 | Complete |
| ING-03 | Phase 2 | Complete |
| ING-04 | Phase 3 | Complete |
| ING-05 | Phase 4 | Pending |
| ING-06 | Phase 2 | Complete |
| ING-07 | Phase 1 | Pending |
| ING-08 | Phase 1 | Pending |
| ING-09 | Phase 4 | Pending |
| EML-01 | Phase 6 | Pending |
| EML-02 | Phase 6 | Pending |
| EML-03 | Phase 6 | Pending |
| EML-04 | Phase 6 | Pending |
| EML-05 | Phase 7 | Pending |
| EML-06 | Phase 6 | Pending |
| EML-07 | Phase 7 | Pending |
| EML-08 | Phase 6 | Pending |
| LED-01 | Phase 1 | Pending |
| LED-02 | Phase 1 | Pending |
| LED-03 | Phase 3 | Complete |
| LED-04 | Phase 4 | Pending |
| LED-05 | Phase 4 | Pending |
| LED-06 | Phase 8 | Pending |
| CHN-01 | Phase 5 | Pending |
| CHN-02 | Phase 5 | Pending |
| CHN-03 | Phase 5 | Pending |
| CHN-04 | Phase 5 | Pending |
| CHN-05 | Phase 5 | Pending |
| CHN-06 | Phase 5 | Pending |
| CHN-07 | Phase 5 | Pending |
| CAT-01 | Phase 1 | Pending |
| CAT-02 | Phase 7 | Pending |
| CAT-03 | Phase 1 | Pending |
| CAT-04 | Phase 7 | Pending |
| CAT-05 | Phase 1 | Pending |
| REC-01 | Phase 8 | Pending |
| REC-02 | Phase 8 | Pending |
| REC-03 | Phase 8 | Pending |
| REC-04 | Phase 8 | Pending |
| REC-05 | Phase 8 | Pending |
| REC-06 | Phase 9 | Pending |
| REC-07 | Phase 9 | Pending |
| REC-08 | Phase 9 | Pending |
| FCT-01 | Phase 10 | Pending |
| FCT-02 | Phase 10 | Pending |
| FCT-03 | Phase 10 | Pending |
| FCT-04 | Phase 10 | Pending |
| FCT-05 | Phase 10 | Pending |
| UI-01 | Phase 1 | Pending |
| UI-02 | Phase 5 | Pending |
| UI-03 | Phase 8 | Pending |
| UI-04 | Phase 1 | Pending |
| UI-05 | Phase 1 | Pending |
| UI-06 | Phase 3 | Pending |
| MC-01 | Phase 1 | Pending |
| MC-02 | Phase 3 | Pending |
| PLT-01 | Phase 1 | Pending |
| PLT-02 | Phase 1 | Pending |
| PLT-03 | Phase 6 | Pending |
| PLT-04 | Phase 6 | Pending |
| PLT-05 | Phase 1 | Pending |

**Coverage:** 68 / 68 requirements mapped (100%).
