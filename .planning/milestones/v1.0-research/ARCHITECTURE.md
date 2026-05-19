# Architecture Research — diederik

**Domain:** Local-only personal finance dashboard with multi-source ingestion, chain resolution, recurrence detection, and forecasting
**Researched:** 2026-05-12
**Confidence:** HIGH (Laravel patterns, Firefly III precedent, idempotent ingest patterns); MEDIUM (chain-resolution specifics — domain-novel)

---

## Executive Recommendation

Build diederik as a **modular Laravel monolith with a clear pipeline-and-engine seam**:

- A **Blade + Livewire** server-rendered UI (no SPA, no Inertia — single user, localhost, calm dashboard, form-light)
- A **pluggable Source Adapter pipeline** (per source: parser → normalizer → fingerprinter → loader) for CSV / MT940 / IMAP / `.eml`
- A **single canonical Transaction table with typed entries** (transfer / income / expense) — *not* full double-entry; the chain-link graph captures cross-account flow without bookkeeping ceremony
- A **Link/Chain table** separate from Transaction — links are first-class with confidence + state (candidate / confirmed / rejected) so learning is observable
- **Synchronous import flow with optional queued chain-resolution job** — fast feedback during import, heavy work deferred
- **Materialized-only-when-asked** forecasting (compute on-the-fly with caching; never persist predicted occurrences)
- **Multi-user-ready schema from day one**: every domain row has `user_id` (nullable v1 → enforced v2), wrapped behind a `CurrentUser` accessor so v1 ignores it cleanly

Phase 1 vertical slice: **ASN CSV → canonical Transaction → categorized list view**. One source, one transformation, one screen. Everything else (chains, forecasts, IMAP, recurring) builds on this skeleton.

---

## Standard Architecture

### System Overview

```
┌──────────────────────────────────────────────────────────────────────────┐
│                      PRESENTATION (Blade + Livewire)                      │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌─────────────────┐ │
│  │  Dashboard   │ │ Transactions │ │ Fixed/Recur. │ │ Import / Review │ │
│  │  (month at   │ │  list & drill│ │  + chains    │ │  queue          │ │
│  │   a glance)  │ │              │ │              │ │                 │ │
│  └──────┬───────┘ └──────┬───────┘ └──────┬───────┘ └────────┬────────┘ │
│         │                │                │                  │          │
├─────────┼────────────────┼────────────────┼──────────────────┼──────────┤
│                       APPLICATION (Actions + Read Models)                │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  Actions (single-purpose, invokable):                            │   │
│  │  ImportCsvFile · ScanInbox · ConfirmChainLink · RecategorizeRule │   │
│  │  ForecastBalances · WhatIfScenario · DetectRecurringSeries       │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  Read Models (query services for views):                         │   │
│  │  MonthAtAGlanceQuery · FundingChainQuery · BalanceForecastQuery  │   │
│  └──────────────────────────────────────────────────────────────────┘   │
├──────────────────────────────────────────────────────────────────────────┤
│                   DOMAIN (Bounded Modules — same DB, clear seams)        │
│  ┌────────────────┐ ┌─────────────────┐ ┌──────────────────────────┐    │
│  │   Ingestion    │ │ Categorization  │ │       Chains             │    │
│  │ • SourceAdapter│ │ • RuleEngine    │ │ • LinkResolver           │    │
│  │ • Pipeline     │ │ • RecurringDetect│ │ • PayPal→Funding         │    │
│  │ • Fingerprint  │ │ • LearningLoop  │ │ • ASN→ICS settlement    │    │
│  │ • RawSource    │ │                 │ │ • Confidence + Candidates│    │
│  └────────┬───────┘ └────────┬────────┘ └──────────┬───────────────┘    │
│           │                  │                     │                     │
│  ┌────────┴──────────────────┴─────────────────────┴───────────────┐    │
│  │              Ledger (the canonical transaction core)             │    │
│  │  Account · Transaction · Money · Currency · TransactionType      │    │
│  └──────────────────────────────────────────────────────────────────┘    │
│  ┌──────────────────┐ ┌──────────────────┐                              │
│  │   Forecasting    │ │   Email Scanner  │                              │
│  │ • Projector      │ │ • IMAPState      │                              │
│  │ • WhatIfEngine   │ │ • TemplateMatcher│                              │
│  └──────────────────┘ └──────────────────┘                              │
├──────────────────────────────────────────────────────────────────────────┤
│                     INFRASTRUCTURE                                        │
│  ┌─────────────────┐ ┌─────────────────┐ ┌───────────────────────────┐  │
│  │ SQLite (WAL)    │ │ Laravel Queue   │ │ Filesystem                │  │
│  │ • domain data   │ │ • database driver│ │ • uploaded CSVs (raw)    │  │
│  │                 │ │ • for chain      │ │ • IMAP creds (config)    │  │
│  │                 │ │   resolution &   │ │ • .eml import area       │  │
│  │                 │ │   IMAP scans     │ │                           │  │
│  └─────────────────┘ └─────────────────┘ └───────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Responsibility | Implementation |
|-----------|---------------|----------------|
| **SourceAdapter** | One per source format (ASN-CSV, ASN-MT940, ICS-CSV, ICS-Excel, PayPal-CSV, Email-PayPal, Email-ICS, Email-GooglePlay). Parses raw bytes → array of `RawEntry` DTOs | Interface `SourceAdapter` with `parse(stream): Generator<RawEntry>` |
| **Normalizer** | Maps `RawEntry` → `CanonicalTransaction` (currency, sign, dates, merchant cleanup) | One per adapter, composed via pipeline |
| **Fingerprinter** | SHA-256 hash of `(account_id, posted_at, amount_minor, currency, normalized_counterparty, source_ref)` → `external_id` | Stateless service, one strategy per source |
| **Loader** | Idempotent upsert against `transactions` keyed by `(source_id, external_id)` | Single DB transaction per import file |
| **RuleEngine** | Apply user-defined merchant→category rules; learn from corrections | `categorization_rules` table + match service |
| **RecurringDetector** | Identify N≥3 same-counterparty-similar-amount entries at regular cadence → create `recurring_series` | Async job, runs after each import |
| **LinkResolver** | Connect PayPal lines to underlying ASN/ICS lines; explode ASN→ICS settlement lump into its ICS detail lines | Async job, produces `chain_links` with confidence |
| **Projector (Forecasting)** | Read recurring_series + balances → predicted occurrences as transient objects | Read-only service; never persists |
| **WhatIfEngine** | Apply hypothetical mutations (cancel sub, add expense) over Projector results | Stateless overlay on top of Projector |
| **IMAPScanner** | Per-inbox UID-based incremental scan; route messages to template matchers | Webklex/laravel-imap, state in `inbox_scan_state` table |
| **TemplateMatcher** | Sender-specific regex/DOM extractors that turn an email body into a `RawEntry` | One matcher per known sender; generic fallback |
| **Ledger** | Holds canonical transactions, accounts, currencies. Nothing else writes here except Ingestion via Loader | Eloquent models + migrations |

---

## Domain Model

### Core Entities

```
┌─────────────┐       ┌─────────────────┐       ┌─────────────────┐
│    User     │       │     Account     │       │    Currency    │
│ id          │◄──┐   │ id              │       │ code (PK)       │
│ name        │   │   │ user_id ────────┼──┐    │ name            │
│ email       │   │   │ name            │  │    │ minor_unit (2)  │
└─────────────┘   │   │ slug            │  │    └────────┬────────┘
                  │   │ kind (asn|ics|  │  │             │
                  │   │       paypal|   │  │             │
                  │   │       gplay|    │  │             │
                  │   │       cash)     │  │             │
                  │   │ default_currency├──┼─────────────┘
                  │   │ external_id     │  │
                  │   │ opened_at       │  │
                  │   │ metadata (JSON) │  │
                  │   └────────┬────────┘  │
                  │            │           │
                  │   ┌────────┴────────────┴────────────────┐
                  └───┤           Transaction                 │
                      │ id                                    │
                      │ user_id ──── (nullable v1)            │
                      │ account_id (the "self" account)       │
                      │ type (expense|income|transfer_out|    │
                      │       transfer_in|fee|adjustment)     │
                      │ posted_at (date)                      │
                      │ booked_at (datetime, when seen)       │
                      │ value_date (date, when economic)      │
                      │ amount_minor (BIGINT, signed)         │
                      │ currency (FK Currency)                │
                      │ settled_amount_minor (BIGINT)         │
                      │ settled_currency (FK Currency)        │
                      │ fx_rate_used (DECIMAL 18,8 nullable)  │
                      │ counterparty_name (text)              │
                      │ counterparty_normalized (text)        │
                      │ counterparty_id (FK Merchant, null)   │
                      │ description (text)                    │
                      │ category_id (FK, nullable)            │
                      │ recurring_series_id (FK, nullable)    │
                      │ source_id (FK Source)                 │
                      │ raw_source_id (FK RawSource)          │
                      │ external_id (per source)              │
                      │ fingerprint (SHA-256 hex)             │
                      │ pair_transaction_id (FK self, null)   │
                      │ tags (JSON or pivot)                  │
                      │ status (cleared|pending|forecasted)   │
                      │ created_at, updated_at                │
                      │ UNIQUE (account_id, fingerprint)      │
                      │ UNIQUE (source_id, external_id)       │
                      └───────────┬───────────────────────────┘
                                  │
              ┌───────────────────┼───────────────────┐
              │                   │                   │
       ┌──────┴──────┐    ┌───────┴───────┐   ┌──────┴───────┐
       │  ChainLink  │    │ RawSource     │   │  Category    │
       │ id          │    │ id            │   │ id           │
       │ user_id     │    │ source_id     │   │ user_id      │
       │ from_txn_id │    │ kind (csv|    │   │ parent_id    │
       │ to_txn_id   │    │   mt940|eml|  │   │ name         │
       │ kind (paypal│    │   imap)       │   │ slug         │
       │   _funding| │    │ filename      │   │ color, icon  │
       │   ics_bulk_ │    │ original_     │   │ kind (expense│
       │   settle|   │    │  uploaded_at  │   │  |income|    │
       │   transfer_ │    │ checksum      │   │  transfer)   │
       │   pair)     │    │ payload_path  │   └──────────────┘
       │ confidence  │    │ row_count     │
       │ state       │    │ user_id       │
       │  (candidate|│    └───────────────┘
       │   confirmed│
       │   rejected)│
       │ resolver   │
       │  (auto|user│
       │   rule)    │
       │ evidence   │
       │  (JSON)    │
       │ created_at │
       └────────────┘

   ┌─────────────────────────┐         ┌──────────────────────┐
   │  RecurringSeries        │         │ CategorizationRule   │
   │ id                      │         │ id                   │
   │ user_id                 │         │ user_id              │
   │ label (e.g. "Netflix")  │         │ priority             │
   │ canonical_amount_minor  │         │ match (JSON: pattern │
   │ canonical_currency      │         │   on counterparty,   │
   │ cadence (monthly|       │         │   amount, account)   │
   │   quarterly|yearly|...) │         │ category_id          │
   │ next_expected_at        │         │ source (user|system) │
   │ funding_account_id (FK) │         │ confidence           │
   │ funding_chain_kind      │         │ uses_count           │
   │ category_id (FK)        │         │ last_matched_at      │
   │ confidence              │         └──────────────────────┘
   │ status (active|paused|  │
   │   cancelled)            │         ┌──────────────────────┐
   │ first_seen_at           │         │ Merchant             │
   │ last_seen_at            │         │ id                   │
   │ created_at, updated_at  │         │ user_id (or global?) │
   └─────────────────────────┘         │ canonical_name       │
                                       │ aliases (JSON)       │
   ┌─────────────────────────┐         │ default_category_id  │
   │ Source                  │         │ icon                 │
   │ id                      │         └──────────────────────┘
   │ slug (asn|ics|paypal|   │
   │   gplay|email-paypal|   │         ┌──────────────────────┐
   │   email-ics|email-gplay)│         │ InboxScanState       │
   │ display_name            │         │ id                   │
   │ adapter_class           │         │ user_id              │
   │ default_account_id (FK) │         │ inbox_identifier     │
   │ config (JSON)           │         │ folder               │
   └─────────────────────────┘         │ last_uid             │
                                       │ last_scanned_at      │
                                       │ scan_window_start    │
                                       └──────────────────────┘
```

### Why a single `transactions` table, not full double-entry

Firefly III models every economic event as a **journal of two paired transactions** (one credit, one debit), enforcing zero-sum at the journal level. That gives true accounting integrity but adds significant ceremony — every category change, every split, every transfer requires choreographed paired writes.

For diederik, a **single signed-row model with optional pairing** is the right trade:

- **Each row owns the perspective of one account.** A salary deposit into ASN is one row in `transactions` with `type=income`, `amount_minor=+250000` on `account_id=asn-main`. An ICS purchase is one row with `type=expense`, `amount_minor=-1299` on `account_id=ics-card`.
- **Inter-account transfers create two rows** (one `transfer_out`, one `transfer_in`) joined by `pair_transaction_id`. The Loader writes both atomically; a CHECK constraint (or app-level invariant) ensures the pair's amounts sum to zero in the same currency, or are linked via an FX rate.
- **Chain links live in `chain_links`** — they are *semantic* relationships ("this PayPal expense was funded by that ASN transfer-out"), not bookkeeping pairs. This keeps the chain graph queryable without polluting the canonical ledger.

Why not double-entry:
- The user wants *visibility*, not bookkeeping reports. The system never needs to produce a trial balance.
- The data source is already single-sided per account (each bank statement is the bank's view). Forcing it into double-entry would mean fabricating phantom counterparty accounts for every merchant — exactly the Firefly III "expense account" complexity that the user said feels like over-engineering for personal use.
- It keeps Phase 1 small: import a CSV, you get rows in `transactions`. No journal scaffolding.

Why not pure single-entry either:
- Without `pair_transaction_id`, a transfer between two of *your* accounts shows up twice as "income" or "expense" — exactly the bug the user explicitly called out as painful (ASN→ICS settlement being counted as expense when it's a transfer).

### Money & Currency

- **Store amounts as signed `BIGINT` in the minor unit** (cents for EUR/USD, etc.). Index-friendly, exact, no float drift, accepted Laravel convention. Use accessor/mutator or a custom cast to surface `Money` value objects in the application layer.
- **Use `moneyphp/money`** (or `akaunting/laravel-money` which wraps it) for arithmetic, formatting, and FX. Built on BCMath; production-grade for financial calc.
- **Three amounts per transaction:**
  1. `amount_minor` + `currency` — the natural-currency amount the user actually transacted in (e.g. $9.99 USD on Google Play)
  2. `settled_amount_minor` + `settled_currency` — what hit the account in account currency (e.g. €9.18 EUR after ICS conversion)
  3. `fx_rate_used` — captured from the source if present; otherwise null (FX-info preservation is a stated requirement)
- For native-currency transactions, `amount == settled_amount` and `currency == settled_currency`; both columns still populated for query simplicity.

### Income / Transfer / Expense — one table with types

**Decision: one `transactions` table, `type` enum column.** Not separate tables.

Rationale:
- All three share 90% of fields (account, date, amount, counterparty, source, fingerprint).
- The differentiator is *semantic*, not structural: a transfer is an expense from one account that pairs with income to another; an income is just an expense with positive sign and no chain link to your other accounts.
- Polymorphic income/expense tables make every cross-cutting query (e.g. "this month's net cash flow") into a UNION. With one table + a `type` enum, it's a single `SELECT SUM(amount_minor) WHERE user_id=…`.
- The cost (a wider table) is irrelevant at personal-finance scale (years × thousands of rows = trivial for SQLite).
- The Firefly III precedent (which scales to many users) also uses one table with a type discriminator.

`type` values:
- `expense` — money out, not to one of your own accounts
- `income` — money in, not from one of your own accounts
- `transfer_out` / `transfer_in` — paired across your own accounts (always come in pairs via `pair_transaction_id`)
- `fee` — bank fees, FX fees, treated as expenses but separable for reporting
- `adjustment` — manual correction (opening balance, dispute reversal)

`status` values:
- `cleared` — appears in an official source (CSV, statement, IMAP receipt confirmed)
- `pending` — promised but unsettled (e.g. ICS line known from email but not yet in ASN statement)
- `forecasted` — projector-generated for the future (NEVER persisted; in-memory only). Only included in the enum for completeness — actual forecasted rows are transient DTOs.

### What makes each Account different

| Account Kind | Currency | Settles To | Funded By | Notes |
|---|---|---|---|---|
| `asn` | EUR (native) | — (terminal) | salary, refunds | Root of most chains. Source: MT940 / CAMT.053 / CSV. |
| `ics` | EUR (settled), but transactions can be USD/other | `asn` via monthly bulk iDEAL | direct from ICS-issued card | Bulk-settlement is the painful case. Source: CSV/Excel statement, plus email receipts for individual lines. |
| `paypal` | EUR (default) but multi-currency | `asn` or `ics` per-transaction | linked card/account | Per-transaction funding source. Source: CSV + email receipts. |
| `gplay` | USD often | `paypal` or `ics` | linked PayPal/card | Modeled as an account so its receipts can be linked through. Source: email receipts. Acts as a pass-through funding chain. |
| `cash` (future) | EUR | — | manual | Optional v2+. Manual entry only. |

The `kind` field drives adapter selection and chain-resolution rules. It is *not* the same as "currency" — an ICS card account is `kind=ics` but its transactions can be in many currencies.

---

## Ingestion Pipeline Architecture

### Pipeline Stages

```
   ┌────────────┐    ┌───────────┐    ┌────────────┐    ┌────────────┐    ┌─────────┐
   │  Acquire   │───▶│  Parse    │───▶│ Normalize  │───▶│ Fingerprint│───▶│  Load   │
   │ (CSV file/ │    │ (per      │    │ (canonical │    │ (compute   │    │ (upsert │
   │  IMAP msg/ │    │  adapter) │    │  shape)    │    │  hash)     │    │  + pair)│
   │  .eml)     │    │           │    │            │    │            │    │         │
   └────────────┘    └───────────┘    └────────────┘    └────────────┘    └────┬────┘
                                                                                │
                                                                                ▼
                                                                       ┌────────────────┐
                                                                       │ Post-Load Jobs │
                                                                       │ (queued):      │
                                                                       │ • Categorize   │
                                                                       │ • Detect       │
                                                                       │   recurring    │
                                                                       │ • Resolve      │
                                                                       │   chain links  │
                                                                       └────────────────┘
```

#### Stage 1: Acquire
- Upload form (Livewire), `.eml` drag-and-drop, IMAP polling job, mbox import command.
- Always writes the raw bytes to `storage/imports/{user}/{source}/{date}/{hash}.{ext}` first.
- Creates a `RawSource` row pointing to that file with `checksum` (SHA-256 of bytes) so re-uploading the same file is a no-op at this stage.

#### Stage 2: Parse — `SourceAdapter` interface

```php
interface SourceAdapter
{
    public function supports(RawSource $raw): bool;
    /** @return iterable<RawEntry> */
    public function parse(RawSource $raw): iterable;
}
```

`RawEntry` is a flat DTO — strings/dates/Money values — with whatever the source provided. No interpretation yet.

Adapters in v1:
- `AsnCsvAdapter` (League\Csv, ASN's specific column layout)
- `AsnMt940Adapter` (jejik/mt940 — most widely used PHP MT940 parser)
- `AsnCamt053Adapter` (genkgo/camt — for future-proofing, CAMT.053 is replacing MT940 across EU banks)
- `IcsCsvAdapter`, `IcsExcelAdapter` (PhpSpreadsheet for .xls/.xlsx)
- `PaypalCsvAdapter` (preserves funding-source column!)
- `EmailPaypalAdapter`, `EmailIcsAdapter`, `EmailGooglePlayAdapter` (template matchers; one per known sender)

Registration: tagged Laravel container binding so adding a new adapter = one class + one tag entry, no controller changes.

#### Stage 3: Normalize — canonical shape

Maps each `RawEntry` to a `CanonicalTransaction` DTO matching the `transactions` columns:
- Sign convention enforced (negative = leaving the account)
- Counterparty cleaned (`counterparty_normalized` = lowercase, strip locations like "AMSTERDAM NL", strip transaction IDs, collapse whitespace)
- Currency resolved (default to account's currency if absent)
- For PayPal: `funding_source_hint` extracted from the funding column → seeds chain resolution
- For ICS: `original_currency` and `original_amount` preserved if statement showed both

Normalization is per-adapter (each has its own quirks) but emits the same DTO type.

#### Stage 4: Fingerprint — idempotent identity

**Two-layer dedup** (industry-standard pattern from financial ETL):

1. **File-level idempotency**: `RawSource.checksum` — re-uploading the exact same CSV is a no-op.

2. **Row-level fingerprint**: SHA-256 over a *canonical tuple*:
   - `account_id`
   - `posted_at` (date only, normalized to ISO)
   - `amount_minor` (signed)
   - `currency`
   - `counterparty_normalized` (after cleanup)
   - `source_ref` (if the source provides a stable per-line ID — MT940 has reference IDs; PayPal has Transaction ID; emails have a Message-ID derivable hash; ICS has a posting ID)

   The fingerprint is stored in `transactions.fingerprint`. Unique on `(account_id, fingerprint)`.

**Why both layers** (per consensus from financial-ETL research): file-checksum catches the easy case (same file re-uploaded). Row-fingerprint catches the cross-source case (e.g. an ICS email receipt and a later ICS CSV entry for the same charge), where the file checksums differ but the underlying transaction is identical.

**Versioning the hash**: store a `fingerprint_version` column. If the normalization logic ever changes (e.g. better counterparty cleanup), bump the version and re-fingerprint historical rows via a backfill command — never silently change the algorithm in place.

**Tolerance for fuzziness**: the fingerprint is strict. For cross-source matches that *should* be the same row but the fingerprint differs (e.g. ICS email arrives Jan 3 with merchant "NETFLIX.COM 35314376933" but the ICS statement Jan 31 shows "Netflix Intl B.V."), the chain-resolver handles them as separate transactions with a `chain_link` of kind `same_event_different_source` (low confidence, surfaced for user merge).

#### Stage 5: Load — upsert

```php
DB::transaction(function () use ($canonical) {
    Transaction::updateOrCreate(
        ['account_id' => $c->account_id, 'fingerprint' => $c->fingerprint],
        $c->toAttributes(),
    );
});
```

One DB transaction per import file (or per batch of 500 if the file is huge). Wraps the whole import for atomicity — partial imports never leave the ledger in a torn state.

For transfers between own accounts (detected by counterparty matching another `Account.external_id`), the Loader also creates the paired row in the destination account and sets `pair_transaction_id` symmetrically.

#### Stage 6: Post-Load — queued enrichment

After load, queue these jobs (database queue driver — no Redis needed for local use):
1. `CategorizeNewTransactionsJob` — applies user rules, sets `category_id` where rules match. Unmatched go to review queue.
2. `DetectRecurringSeriesJob` — looks at the last N months for new recurrence candidates; creates/updates `RecurringSeries` records.
3. `ResolveChainLinksJob` — runs LinkResolver; creates `chain_links` rows.

These run after the user sees "Imported 42 transactions" — they enrich in the background, and the dashboard shows fresh data after a brief polling refresh (Livewire `wire:poll` on the import status pane).

#### Where does learning happen?

**At the review queue, not in normalization.**

Normalization is deterministic by design — same input always produces the same output. Putting ML/heuristics inside the normalizer would make imports non-reproducible.

Learning lives in two clearly-separated places:
1. **Categorization**: `CategorizationRule` table. When the user recategorizes a transaction, the app offers "create a rule from this?" (counterparty + amount range → category). User confirms; rule is persisted. Future imports auto-apply matching rules during the `CategorizeNewTransactionsJob`.
2. **Chain links**: `ChainLink.state` transitions from `candidate` → `confirmed` when the user accepts. The LinkResolver records the *features* that produced the suggestion (counterparty hash, amount, date delta, funding-source-hint) in `evidence`. A simple "if this feature combination was confirmed N times, auto-confirm next time" heuristic learns the pattern. No actual ML model; just rule promotion based on confirmation count.

---

## Chain-Resolution Engine

### Where it lives: **async after import, with user-confirmation seam**

- **Sync during import = too slow** for large CSVs and blocks the UI on cross-table resolution.
- **On-demand at view time = wrong for fixed-payment dashboards** which need pre-computed chains to render.
- **Async post-load** is the right seam: import returns fast, chain-resolver runs as a job, dashboard polls or auto-refreshes when done.

### Representing uncertainty: `ChainLink.state` + `confidence`

```
ChainLink.state ∈ { candidate, confirmed, rejected }
ChainLink.confidence ∈ [0.0, 1.0]
ChainLink.resolver ∈ { auto, user, rule }
ChainLink.evidence (JSON): { matched_reference_id?, counterparty_similarity?, amount_delta?, date_delta_days?, funding_source_hint? }
```

- **`confirmed` with `confidence=1.0`**: deterministic match (e.g. PayPal CSV says funding source = "Bank account ending 5678" and that matches an ASN account → link is automatic & confirmed).
- **`candidate` with `confidence=0.7`**: fuzzy match (counterparty + amount + date within 3 days but no shared reference ID) → user must confirm.
- **`rejected`**: user explicitly said "no, these aren't the same flow" → resolver remembers and won't suggest again.

The user-facing review queue surfaces all `candidate` links with a one-click confirm/reject. Confirmation either promotes the link (`state=confirmed`) and, if the same evidence signature has been confirmed ≥3 times for this user, creates an auto-promotion rule so future similar candidates land as `confirmed` automatically.

### ICS bulk-settlement: the closed-loop case

The hard case: a single ASN → ICS iDEAL transfer (e.g. €847.32 on Feb 15) actually pays for **N ICS transactions** from the prior statement (Jan 10 – Feb 10 statement period).

**Data model:**
- The ASN → ICS transfer is **one transfer pair** (`transfer_out` on ASN, `transfer_in` on ICS) joined via `pair_transaction_id`.
- The relationship "this transfer settles those N ICS lines" is **N `chain_link` rows** of `kind='ics_bulk_settle'` linking the `transfer_in` on ICS to each of the N ICS expense transactions, with `confidence=1.0` once the math checks out.
- A constraint (app-level invariant, asserted by the resolver): `SUM(linked_transactions.amount_minor) + statement_balance_carry == transfer_in.amount_minor`. The resolver explicitly verifies this and only confirms if the sum matches (within ±€0.01 for rounding).

This means:
- The ICS bulk-settle row appears as a *transfer* in the ledger (correctly: it's not income), and the chain graph queryable from it shows the underlying purchases.
- The dashboard can render "€847.32 paid Feb 15 → covered 23 ICS charges from Jan/Feb" by following `chain_links`.
- If the math doesn't reconcile (because the user paid the wrong amount, or there's an outstanding balance carrying forward), the link goes to `candidate` and the review queue shows "€2.18 unaccounted for — was there a fee or balance carry?"

**Forecasting "what to pay ICS next month":** sum of `cleared ICS expense transactions` since last settlement, minus any credits, minus any prior unpaid carry. The recurring-series detector also tracks the *date* of recent settlements to predict when the next payment is due.

### Where the resolver reads/writes

- **Reads:** `transactions` (all accounts), prior `chain_links`, `categorization_rules` (to recognize funding-source patterns).
- **Writes:** `chain_links` only. The resolver **never modifies `transactions`**. (Exception: it may write a derived `funding_account_id` on a `RecurringSeries` when it can determine the consistent funding chain.)
- **Concurrency**: the resolver job uses SQLite's WAL mode (readers don't block the writer). When two import jobs run back-to-back, each spawns its own resolver job; the queue serializes them via a per-user job-uniqueness key (`Job::uniqueFor()`) so chain resolution for the same user never runs in parallel — eliminating the race entirely without needing row-level locks.

---

## Forecasting Engine

### Compute on the fly, cache aggressively, never persist forecasts

**Decision: forecasted occurrences are transient DTOs, not table rows.**

Why:
- Forecasts change continuously as new transactions arrive, recurrence cadence is refined, or what-if scenarios run.
- Persisted forecasts are a tar pit of "is this row real or predicted?" branches throughout the codebase.
- Computing 90 days × ~30 recurring series × N accounts is fast: a few thousand operations, well under 100ms on SQLite with proper indexes.

**Implementation:**
- `BalanceProjector` service: given an account + a date range, returns `Collection<ForecastedTransaction>` — DTOs with the same shape as `Transaction` but `status='forecasted'` and no DB id.
- The Livewire view calls the projector; output is cached per-(account, date-range, ledger-mtime) using Laravel's cache with the ledger's max `updated_at` as cache key suffix. Any new transaction invalidates the cache naturally.

**Recurrence materialization:**
- `RecurringSeries.cadence` + `RecurringSeries.canonical_amount_minor` + `last_seen_at` → generate occurrences forward.
- Cadence engine handles: `monthly`, `monthly_on_day_N`, `quarterly`, `yearly`, `weekly`, `every_4_weeks`. Recurly/Plaid-style patterns based on transaction-frequency research.
- Variable amounts (utility bills): store last 6 occurrences' amounts on the series; project using the median.

### What-if mutations: in-memory overlay

```php
$scenario = WhatIfScenario::make()
    ->cancel($netflixSeries)
    ->add(new ForecastedTransaction([
        'posted_at' => '2026-06-01',
        'amount_minor' => -50000,
        'description' => 'planned new gym membership',
    ]));

$projection = $balanceProjector->withOverlay($scenario)->project('asn-main', $today, $today->copy()->addMonths(3));
```

The `WhatIfScenario` is a value object held in Livewire component state (or session for cross-page persistence). It is never written to disk. When the user "saves" a what-if, the system can offer to materialize it as a real `RecurringSeries` (with `status='active'`) or a single planned `Transaction` (with `status='pending'`) — but that's an explicit promotion, not a side effect.

---

## Frontend ↔ Backend Architecture

### Choice: **Blade + Livewire 4**, not Inertia, not SPA

Rationale:
- Single user, localhost, low concurrency — the network round-trip cost of Livewire's "every interaction is a request" model is essentially zero (loopback).
- The UI is form-and-list-heavy (import upload, transaction list, category assignment, link review queue, settings) — exactly Livewire's sweet spot per current consensus comparing Livewire vs Inertia for dashboards.
- Keeps the entire stack PHP — no Vue/React build pipeline to maintain. One Composer install, one `php artisan serve`, and you're running. Matches the user's stated preference for "working app fast, not six-month architecture exercise."
- Livewire 4's **Islands** feature pins expensive views (e.g. the cash-flow chart) to their own update cycle, mitigating the historical "everything re-renders" complaint.
- **Defer chart rendering to Alpine.js + a tiny chart lib** (Chart.js, or even hand-rolled SVG sparklines for the "calm" aesthetic) — Livewire passes data, Alpine renders.

### Keeping it "calm" on years of transactions

Personal-finance scale: 5 accounts × ~50 transactions/month × 10 years = ~30,000 rows. SQLite + WAL + good indexes handles this trivially.

**Concrete steps:**
1. **SQLite WAL mode** + `busy_timeout=5000` in the connection setup. Standard Laravel optimization; readers no longer block writers.
2. **Indexes on:**
   - `transactions (account_id, posted_at DESC)` — primary list view
   - `transactions (user_id, posted_at DESC)` — cross-account month view
   - `transactions (recurring_series_id)` — fixed-payment drill
   - `transactions (category_id, posted_at)` — category trends
   - `transactions (fingerprint)` UNIQUE — dedup
   - `chain_links (from_transaction_id)` and `(to_transaction_id)`
3. **Default view = last 90 days.** "Show full history" loads more on demand with cursor pagination. Matches the PROJECT.md requirement for "Default UI to recent (last 3–6 months); offer 'show full history' toggle."
4. **Pre-aggregated month-roll-ups** for the dashboard:
   - `monthly_account_summary` view or materialized table: `(user_id, account_id, year_month, income_minor, expense_minor, transfer_in_minor, transfer_out_minor, net_minor)`. Refreshed by an event listener on `Transaction` save, or rebuilt on the fly for the current month.
   - The dashboard's "this month at a glance" reads from this rollup, not from `transactions` directly.

---

## Email Scanning Architecture

### Sync model: **manual-triggered + scheduled cron**, no IMAP IDLE

Rationale:
- A local dev machine isn't always on or always online; persistent IMAP IDLE connections are fragile in that environment.
- The product value is "show me my finances" — there's no real-time requirement. Once-an-hour or once-a-day scanning is plenty.
- Manual trigger ("Scan inboxes now" button) is essential for the impatient first-import case.

**Implementation:**
- Laravel scheduled task: `php artisan inbox:scan --since=last` every hour (configurable). Cron-driven via Laravel's `schedule:run`.
- A `ScanInboxes` action queues per-inbox `ScanSingleInboxJob`s — the database queue worker processes them serially.
- For backfill (the "3 months of history" requirement), a one-shot `inbox:backfill --since=2026-02-01` command iterates date windows.

### State tracking

```
InboxScanState:
  user_id, inbox_identifier (hash of host:port:user), folder,
  last_uid (BIGINT, per IMAP UIDVALIDITY),
  uid_validity (BIGINT),
  last_scanned_at,
  scan_window_start
```

On each scan: query `UID > last_uid` since the start window. Webklex/laravel-imap is the PHP IMAP wrapper with the largest ecosystem; it exposes UID-based queries via its `Query` API and supports the `ST_UID` mode. The package doesn't ship its own state tracking — that's `InboxScanState`'s job.

**UIDVALIDITY handling**: if the server returns a different `UIDVALIDITY`, the entire UID space has been invalidated (server-side rebuild). In that case: reset `last_uid=0`, re-scan from `scan_window_start`. The two-layer fingerprint dedup catches the resulting reprocessed messages.

### Parsing strategy: **per-sender templates with generic fallback**

- A registry of `EmailTemplateMatcher` classes, each declaring `supports(message): bool` (matches by `From:` address or `X-Sender` pattern). Examples: `PayPalReceiptMatcher`, `IcsStatementMatcher`, `GooglePlayReceiptMatcher`.
- Templates are ordered by specificity. First-match wins.
- A generic fallback `HeuristicReceiptMatcher` scans for amount-looking patterns + a known merchant in the subject — flagged with low confidence, queued for user review.

**Why templates over a single generic parser** (per project requirement and ML-research consensus):
- These email formats are stable and small in number (~5 senders v1). Hand-tuned regex/DOM parsing is fast, deterministic, and easy to debug.
- A template-per-sender keeps each parser small (~50 lines) and lets the user contribute new templates without retraining anything.

---

## Data Flow / Boundaries

### Authoritative balance for an account today

**Owner: the `Ledger` module, specifically the `BalanceQuery` read service.**

The balance is **derived**, not stored — to avoid drift bugs.

```php
SELECT SUM(amount_minor) FROM transactions
WHERE account_id = ? AND user_id = ? AND status = 'cleared' AND posted_at <= ?
```

For performance with years of data, this is paired with a checkpoint pattern:
- `account_balance_snapshots` table: `(account_id, as_of_date, balance_minor)` — written nightly for each account.
- Today's balance = nearest snapshot + delta from snapshot date forward.

This is the "calm performance" path: even with 30,000 lifetime rows, the live calculation reads at most ~50 rows (a month's worth after the most recent snapshot).

### Race-condition discipline during concurrent imports

- **Only the Loader writes to `transactions`.** Everything else (resolver, recurring-detector, categorizer) writes to *adjacent* tables (`chain_links`, `recurring_series`, etc.) or updates non-key columns of `transactions` (e.g. `category_id`).
- **SQLite WAL** lets readers proceed during writes. Writers serialize.
- **Per-user job uniqueness** for resolver/recurring jobs: `Job::unique($userId)` ensures only one chain-resolution job runs per user at a time. This is sufficient for a single-user app and remains correct when multi-user is added.
- **Bulk-import in a single transaction** per file: either the whole file lands or none of it does.

### Internal boundaries (module → module communication)

| Boundary | Communication style | Notes |
|---|---|---|
| Presentation → Application | Direct method calls on Actions | Livewire components instantiate Actions |
| Application → Domain | Direct service calls, value objects | Same process, no events needed |
| Ingestion → Categorization/Chain/Recurring | **Laravel events** (`TransactionImported`) | Loose coupling so enrichment modules can be added later without touching the Loader |
| Domain → Ledger | Direct Eloquent | Ledger is the only domain module that writes Transaction; others write their own tables |
| Ledger → all readers | Eloquent queries + read-model services | No write access from readers |

---

## Project Structure

```
app/
├── Domain/
│   ├── Ledger/                       # The canonical ledger
│   │   ├── Models/
│   │   │   ├── Account.php
│   │   │   ├── Transaction.php
│   │   │   ├── Currency.php
│   │   │   └── Merchant.php
│   │   ├── ValueObjects/
│   │   │   ├── Money.php             # wraps moneyphp/money
│   │   │   ├── Fingerprint.php
│   │   │   └── TransactionType.php   # enum
│   │   ├── Services/
│   │   │   ├── BalanceQuery.php
│   │   │   └── TransactionUpserter.php
│   │   └── Events/
│   │       └── TransactionImported.php
│   │
│   ├── Ingestion/
│   │   ├── Contracts/
│   │   │   ├── SourceAdapter.php
│   │   │   └── Fingerprinter.php
│   │   ├── Adapters/
│   │   │   ├── Asn/
│   │   │   │   ├── AsnCsvAdapter.php
│   │   │   │   ├── AsnMt940Adapter.php
│   │   │   │   └── AsnCamt053Adapter.php
│   │   │   ├── Ics/
│   │   │   │   ├── IcsCsvAdapter.php
│   │   │   │   └── IcsExcelAdapter.php
│   │   │   ├── Paypal/
│   │   │   │   └── PaypalCsvAdapter.php
│   │   │   └── Email/
│   │   │       ├── PaypalReceiptMatcher.php
│   │   │       ├── IcsStatementMatcher.php
│   │   │       └── GooglePlayReceiptMatcher.php
│   │   ├── Pipeline/
│   │   │   ├── ImportPipeline.php
│   │   │   ├── Stages/
│   │   │   │   ├── ParseStage.php
│   │   │   │   ├── NormalizeStage.php
│   │   │   │   ├── FingerprintStage.php
│   │   │   │   └── LoadStage.php
│   │   │   └── Dto/
│   │   │       ├── RawEntry.php
│   │   │       └── CanonicalTransaction.php
│   │   ├── Models/
│   │   │   ├── Source.php
│   │   │   ├── RawSource.php
│   │   │   └── InboxScanState.php
│   │   └── Actions/
│   │       ├── ImportCsvFile.php
│   │       ├── ImportEml.php
│   │       └── ScanInbox.php
│   │
│   ├── Categorization/
│   │   ├── Models/
│   │   │   ├── Category.php
│   │   │   └── CategorizationRule.php
│   │   ├── Services/
│   │   │   └── RuleEngine.php
│   │   ├── Jobs/
│   │   │   └── CategorizeNewTransactionsJob.php
│   │   └── Actions/
│   │       └── RecategorizeRule.php
│   │
│   ├── Recurring/
│   │   ├── Models/
│   │   │   └── RecurringSeries.php
│   │   ├── Services/
│   │   │   └── RecurrenceDetector.php
│   │   └── Jobs/
│   │       └── DetectRecurringSeriesJob.php
│   │
│   ├── Chains/
│   │   ├── Models/
│   │   │   └── ChainLink.php
│   │   ├── Services/
│   │   │   ├── LinkResolver.php
│   │   │   ├── PayPalFundingResolver.php
│   │   │   └── IcsSettlementResolver.php
│   │   ├── Jobs/
│   │   │   └── ResolveChainLinksJob.php
│   │   └── Actions/
│   │       └── ConfirmChainLink.php
│   │
│   └── Forecasting/
│       ├── Services/
│       │   ├── BalanceProjector.php
│       │   └── WhatIfEngine.php
│       └── Dto/
│           ├── ForecastedTransaction.php
│           └── WhatIfScenario.php
│
├── Http/
│   ├── Livewire/
│   │   ├── Dashboard/
│   │   │   ├── MonthAtAGlance.php
│   │   │   └── CashflowChart.php
│   │   ├── Transactions/
│   │   │   ├── TransactionList.php
│   │   │   └── TransactionDetail.php
│   │   ├── Import/
│   │   │   ├── UploadForm.php
│   │   │   └── ReviewQueue.php
│   │   └── Recurring/
│   │       └── FixedPayments.php
│   └── Controllers/
│       └── (almost empty — Livewire handles most of it)
│
├── Console/Commands/
│   ├── InboxScan.php           # php artisan inbox:scan
│   ├── InboxBackfill.php
│   └── FingerprintRebuild.php  # for hash-version bumps
│
└── Support/
    ├── Concerns/
    │   └── BelongsToUser.php   # trait for the multi-user-ready scope
    └── CurrentUser.php          # facade-style accessor; returns user 1 in v1
```

### Structure Rationale

- **`app/Domain/<Module>/`**: Each bounded module owns its models, services, jobs, and actions. Cross-module communication via events (Ingestion → Categorization/Chains/Recurring) keeps add-ons cheap. This mirrors DDD bounded-context guidance without the ceremony of separate packages.
- **`Ingestion/Adapters/<Source>/`**: One folder per data source — adding a new bank format means dropping one class in here, registering it in the service provider, and writing one normalizer. No other module changes.
- **`Forecasting/Dto/`**: Forecast DTOs live separately so it's obvious they aren't persisted. Same for `WhatIfScenario`.
- **`Http/Livewire/`** organized by feature, not by type, because Livewire components are the application's surface area in this stack.
- **`Support/CurrentUser`**: the seam where multi-user readiness lives. In v1 it returns `User::find(1)`. In v2 it returns `auth()->user()`. The seam means zero rewrites across the domain.

---

## Architectural Patterns Worth Using

### Pattern 1: Action Pattern (single-purpose invokable classes)

**What:** Each user-meaningful operation is a class with one `__invoke` (or `execute`) method. Examples: `ImportCsvFile`, `ConfirmChainLink`, `RecategorizeRule`.

**When:** All write-side operations. Read-side stays in Query/Service classes.

**Trade-offs:**
- ✅ Each action is independently testable, queueable (`ShouldQueue` trait), and invokable from CLI, Livewire, or HTTP.
- ✅ Avoids the "service class becomes a junk drawer" anti-pattern (the well-documented failure mode of generic Laravel service classes).
- ❌ More files. Acceptable for the modularity payoff at this scale.

**Example:**
```php
final class ImportCsvFile
{
    public function __construct(
        private ImportPipeline $pipeline,
        private SourceRegistry $sources,
    ) {}

    public function __invoke(UploadedFile $file, Source $source): ImportResult
    {
        $raw = RawSource::createFrom($file, $source);
        return $this->pipeline->run($raw, $this->sources->adapterFor($source));
    }
}
```

### Pattern 2: Pipeline (Laravel's built-in `Pipeline` helper)

**What:** Chain processing stages — `Acquire → Parse → Normalize → Fingerprint → Load`. Each stage is a class with one method that receives state, mutates/returns it, calls `$next($state)`.

**When:** Ingestion (clear use case). Possibly also for chain resolution if multiple resolvers compose.

**Trade-offs:** Reorderable, testable in isolation, easy to add a new stage (e.g. enrichment) without touching the others. The Laravel-Ingest and PHP-ETL communities have validated this pattern repeatedly.

### Pattern 3: Repository-less Eloquent + Read Models

**What:** Skip a generic repository layer. Use Eloquent models directly for writes (Ingestion's Loader, Actions). For reads, build small **Query Service** classes (`MonthAtAGlanceQuery`, `FundingChainQuery`) that encapsulate complex joins/aggregates.

**When:** Always — Laravel-native consensus is that generic repository layers over Eloquent add no value.

**Trade-offs:** Pragmatic; lower ceremony; same testability via in-memory SQLite.

### Pattern 4: Events for Cross-Module Coordination

**What:** `TransactionImported` event is dispatched by the Loader. Categorization, Chains, and Recurring modules each register listeners (which queue jobs).

**When:** Whenever a write in module A should trigger work in module B without A knowing B exists.

**Trade-offs:**
- ✅ Adding a new enrichment module (e.g. anomaly detection in v3) means adding one listener; the Loader doesn't change.
- ❌ Slight indirection. Tolerable given the small module count.

### Pattern 5: Value Objects for Money & Fingerprint

**What:** `Money` (immutable, currency-aware arithmetic via moneyphp/money). `Fingerprint` (sha256 wrapper).

**When:** Anywhere amounts or hashes are passed around.

**Trade-offs:** Prevents currency-mixing bugs, enables `$tx->amount->plus($other->amount)` rather than raw integer arithmetic.

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Storing floats for money

**What people do:** `DECIMAL(10,2)` or worse `FLOAT` columns for amounts.
**Why it's wrong:** Floating-point drift; rounding errors accumulate; FX conversions become unreliable; SUM() can return `0.30000000000000004`.
**Do this instead:** Signed BIGINT in minor units (cents). Use a Money value object in the application layer.

### Anti-Pattern 2: Full double-entry from day one

**What people do:** Model every transaction as a journal-of-two with credit/debit lines, expense-account/revenue-account counterparties.
**Why it's wrong:** It's bookkeeping ceremony the user doesn't need. Doubles the row count, doubles the write paths, complicates every Livewire query.
**Do this instead:** Single signed row per account perspective + `pair_transaction_id` for transfers. Add full double-entry *only* if the system ever needs to produce accounting reports (it won't, per scope).

### Anti-Pattern 3: Polymorphic transaction tables (income/expense/transfer as separate tables)

**What people do:** Three tables for the three concepts; queries become UNIONs.
**Why it's wrong:** Cross-cutting queries (this month's flow, all transactions for an account, year-end summary) all become complex; ORM associations bloat.
**Do this instead:** One `transactions` table + `type` enum + indexes. Same lesson Firefly III learned.

### Anti-Pattern 4: Persisting forecasted transactions

**What people do:** Generate predicted future occurrences and store them, then "convert" them when the real transaction arrives.
**Why it's wrong:** Two sources of truth for the same event; reconciliation logic everywhere; chain resolver has to decide which is real.
**Do this instead:** Forecasts are in-memory DTOs. The `Transaction` table only holds actual events. `status='pending'` is allowed for known-but-not-cleared events (e.g. parsed from an email but not yet on a statement); `forecasted` only exists in DTO form.

### Anti-Pattern 5: Trusting the Source's "transaction ID" as a global key

**What people do:** Assume the bank's reference ID is unique forever, use it as the only dedup key.
**Why it's wrong:** Sources sometimes recycle IDs; emails and CSVs use different ID schemes for the same transaction; some sources don't provide IDs at all (ASN MT940 has weak per-line IDs).
**Do this instead:** Two-layer dedup — file checksum + canonical-tuple fingerprint. Per the financial-ETL research, this is the only robust approach for multi-source ingestion.

### Anti-Pattern 6: Running chain resolution synchronously inside the import controller

**What people do:** Import CSV → resolve chains → commit, all in one HTTP request.
**Why it's wrong:** Imports of years of history take 30+ seconds; the UI hangs; transactions get rolled back on PHP timeouts.
**Do this instead:** Synchronous parse + load (fast); queued resolver job (slow); Livewire `wire:poll` for status. SQLite WAL ensures no read-lock contention.

### Anti-Pattern 7: Coupling IMAP scanning to the request lifecycle

**What people do:** "Scan now" button kicks off a 5-minute IMAP fetch inside an HTTP request.
**Why it's wrong:** Browser timeouts; partial scans; no observability.
**Do this instead:** "Scan now" enqueues a job; UI shows progress via polling.

---

## Scaling Considerations

| Scale | Architecture | Notes |
|---|---|---|
| **1 user, 1 year of data** (~6k rows) | This document, untouched. | Trivial for SQLite + WAL. |
| **1 user, 10 years** (~60k rows) | This document + `account_balance_snapshots` nightly. | Default 90-day view + indexes handles dashboards in <50ms. |
| **2 users (partner)** | This document + enforce `user_id NOT NULL` + add `user_id` to every index. | The schema is already ready. The seam is `CurrentUser`. |
| **Many users (hypothetical SaaS pivot)** | Move queue to Redis; move DB to Postgres; add per-tenant data isolation review. | Out of scope per PROJECT.md. Don't optimize for this. |

### First bottleneck (when it happens)

**The dashboard's "this month at a glance" query** scanning all transactions for the current month across all accounts. Mitigation order:
1. Index on `(user_id, posted_at)` — sufficient up to ~100k rows.
2. Monthly rollup table maintained by an event listener — sufficient up to millions.
3. (Never needed for personal-finance scale) Materialized view.

---

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---|---|---|
| **IMAP servers** | webklex/laravel-imap, per-inbox UID-tracked polling | Credentials in config file (not DB) per PROJECT.md constraint. Support OAuth in v2 if any provider deprecates app-passwords. |
| **No banking APIs in v1** | — | Explicitly out of scope. |

### Internal Module Boundaries

| Boundary | Mechanism | Notes |
|---|---|---|
| Presentation ↔ Application | Livewire components call Action classes directly | No HTTP/JSON layer needed |
| Ingestion ↔ Categorization/Recurring/Chains | Laravel events (`TransactionImported`) | Loose coupling for future enrichment modules |
| Ingestion ↔ Ledger | Direct (Ingestion owns the Loader; only it writes Transactions) | Strict invariant — enforced by code review and tests, not by DB permissions |
| Chains ↔ Ledger | Read-only direct queries; writes to `chain_links` only | Resolver never updates Transactions |
| Forecasting ↔ all | Read-only | Forecasting is a pure function over the ledger + recurring series |

---

## Multi-User Readiness

### Schema decisions to make NOW (no v2 migration pain)

- **`user_id` column on every domain table**, including: accounts, transactions, raw_sources, sources (if user-overridable), chain_links, categorization_rules, categories, recurring_series, merchants, inbox_scan_state.
- **Nullable in v1, NOT NULL after backfill in v2.** The migration in v2 is one line: `ALTER TABLE … ALTER COLUMN user_id SET NOT NULL` after a one-shot backfill that stamps everything to user 1.
- **Composite indexes lead with `user_id`** wherever there's a user dimension (e.g. `(user_id, posted_at DESC)`, not just `(posted_at DESC)`). Critical: changing index order later is a rebuild on production data; doing it now is free.
- **`CurrentUser` indirection** (a tiny class or facade): `CurrentUser::id()` returns `1` in v1, `auth()->user()->id` in v2. Domain code calls `CurrentUser::id()`, never `auth()` directly.
- **Per-user job uniqueness** is already in the architecture: queue jobs are keyed by user. Multi-user just means multiple keys.

### What can be deferred without pain

- **Real auth** — v1 can be `auth.basic` or even no auth (it's localhost). Adding `laravel/breeze` later doesn't migrate any data.
- **Sharing between users** — explicit boundary in PROJECT.md ("Multi-user / partner sharing in v1" is out of scope). The `user_id` column models *ownership*, not *visibility*. Sharing is a v3+ concern and lives in a separate `account_shares` table whenever it's built.
- **Authorization policies** — Laravel policies can be added per-model in v2 without touching data.

---

## Data Flow Examples

### Example 1: Daily ASN CSV import

```
1. User uploads asn_2026-05.csv via Livewire UploadForm.
2. Controller-equivalent (Livewire action) calls ImportCsvFile action.
3. ImportCsvFile creates RawSource with file checksum.
4. If checksum already exists → returns "Already imported, 0 new transactions."
5. ImportPipeline runs:
   a. AsnCsvAdapter.parse() → Generator<RawEntry>
   b. AsnNormalizer.normalize() → Generator<CanonicalTransaction>
   c. Fingerprinter.compute() — adds fingerprint to each
   d. Loader.upsert() — DB::transaction, INSERT...ON CONFLICT DO NOTHING by (account_id, fingerprint)
6. For each newly-inserted row: dispatch TransactionImported event.
7. Listeners enqueue:
   - CategorizeNewTransactionsJob (user rules → category_id)
   - DetectRecurringSeriesJob (updates RecurringSeries)
   - ResolveChainLinksJob (finds PayPal funding, ICS settlement matches)
8. Livewire returns success synchronously: "Imported 42 transactions. Enriching..."
9. UI auto-refreshes after 2s polls until jobs complete.
```

### Example 2: Confirming a fuzzy chain link

```
1. User opens Review Queue → sees candidate ChainLink: 
   PayPal "Netflix.com" €13.99 on May 2 ⇄ ASN "PAYPAL EUROPE" €13.99 on May 4.
2. User clicks Confirm.
3. ConfirmChainLink action:
   a. Updates ChainLink.state = 'confirmed', resolver = 'user'.
   b. Records the evidence signature.
   c. Checks: same evidence signature confirmed ≥3 times? → if yes, create an auto-promotion rule (this kind of match becomes auto-confirmed in future).
4. Dashboard updates: Netflix now shows funding source = ASN (via PayPal).
```

### Example 3: "What if I cancel Netflix?" 

```
1. User clicks the Netflix row → "What if cancelled?"
2. Livewire WhatIfScenario component creates an in-memory scenario:
   { cancellations: [recurring_series_id_Netflix] }
3. BalanceProjector.withOverlay(scenario).project('asn-main', today, today+90d)
   - Iterates each day from today.
   - At each day, generates expected occurrences from active RecurringSeries, MINUS those in the scenario's cancellations.
   - Computes running balance using current cleared balance as start.
4. Livewire renders a comparison: original 90-day balance vs scenario.
5. Nothing is written to disk. User closes the panel; scenario is gone.
```

---

## Build Order Implications — Phase 1 Vertical Slice

**Phase 1 must prove the spine works**, not check every box. The right vertical slice:

### Phase 1: "See my ASN month"

Scope (one source, end-to-end):
- Schema: `users`, `currencies`, `accounts`, `transactions`, `categories`, `categorization_rules`, `sources`, `raw_sources`.
- Models, factories, seeders.
- One adapter: `AsnCsvAdapter`.
- ImportPipeline (Parse → Normalize → Fingerprint → Load) — fully working.
- Idempotency: re-uploading the same file is a no-op (file checksum + row fingerprint both functional).
- Money value object + cent-storage casts.
- One Livewire screen: `MonthAtAGlance` showing month total income/expenses/net + a simple transaction list.
- One Livewire screen: transaction list with manual category assignment (no rules learning yet, no automation).
- CurrentUser indirection in place (returns user 1).
- SQLite WAL configuration.

**What's NOT in Phase 1:** chains, recurring detection, forecasting, IMAP, ICS, PayPal, what-if scenarios.

**Why this slice:**
- It proves: the ingestion pipeline works end-to-end on real user data; the canonical Transaction model fits real bank data; the Livewire stack feels good; idempotency holds under re-uploads.
- It delivers immediate user value (the user can already see their ASN month, which they couldn't see "in one place" before — even without other sources).
- Every Phase 2+ feature drops into a working spine: adding MT940 is one new adapter; adding ICS is one new adapter + a chain resolver; adding forecasting is a new service over existing tables.

### Subsequent phase suggestions (informs roadmap, doesn't dictate it)

- **Phase 2: Multi-source ingestion** — Add ICS CSV adapter, PayPal CSV adapter, AsnMt940Adapter. Now user has every account visible. No chains yet; transactions just show up per-account.
- **Phase 3: Chain resolution v1** — PayPal-funding deterministic links (when CSV has the funding source); ASN→ICS bulk-settle math. Review queue UI. This is the "stop being a manual reconciliation puzzle" payoff.
- **Phase 4: Recurring + fixed-payment view** — RecurringSeries detection, fixed-payments dashboard widget, "next ICS settlement amount" projection.
- **Phase 5: IMAP scanning** — adds IMAP, .eml import, EmailPayPal/ICS/GooglePlay template matchers. Significantly increases data coverage; chains improve.
- **Phase 6: Forecasting + what-if** — BalanceProjector, WhatIfEngine. "Show me the next 90 days."
- **Phase 7: Multi-user readiness validation** — backfill user_id, enforce NOT NULL, add basic auth. (May be combined with a sharing pilot.)

---

## Confidence & Open Questions

| Decision | Confidence | Why |
|---|---|---|
| Blade + Livewire over Inertia/SPA | HIGH | Local single user + form-heavy UI is consensus Livewire territory |
| Single transactions table with type enum | HIGH | Firefly III precedent + simpler queries; trade-off well understood |
| Two-layer idempotent fingerprint | HIGH | Industry-standard pattern for multi-source financial ETL |
| Money as BIGINT cents + moneyphp/money | HIGH | PHP/Laravel community consensus |
| Async chain resolution via queue:database | HIGH | Standard Laravel pattern; SQLite WAL covers concurrency |
| ChainLink as separate table with confidence/state | HIGH | Cleanly separates semantic graph from canonical ledger |
| Forecasts as in-memory DTOs, not rows | HIGH | Avoids well-known dual-state bug pattern |
| Templates over generic ML for email parsing | MEDIUM | Right for stable small sender set; revisit if sender count explodes |
| Manual+cron IMAP vs IMAP IDLE | MEDIUM | Reasonable for local app; IMAP IDLE would require Octane or a long-running process |
| user_id-nullable v1 strategy | HIGH | Standard "schema-ready single-user" pattern |

### Open questions worth flagging for phase-specific research

1. **ASN's exact CSV column format** — needs sample export to verify the adapter's mapping. (Phase 1 research)
2. **ICS bulk-settlement math edge cases** — do credits/refunds appear inline or as separate statements? Needs sample data. (Phase 3 research)
3. **PayPal CSV "funding source" column** — current export schema includes it but the exact label has changed over time. (Phase 2 research)
4. **Google Play receipt template** — Google has changed their receipt HTML structure multiple times; need recent samples. (Phase 5 research)
5. **MT940 dialect variations** — different banks add different fields in :86: tags. ASN's specific dialect must be tested. (Phase 2 research)

---

## Sources

### Architecture & Domain Modeling
- [bliki: Bounded Context (Martin Fowler)](https://martinfowler.com/bliki/BoundedContext.html)
- [How to organize transactions — Firefly III documentation](https://docs.firefly-iii.org/how-to/firefly-iii/finances/transactions/)
- [Transactions — Firefly III documentation](https://docs.firefly-iii.org/explanation/financial-concepts/transactions/)
- [Transaction types — Firefly III documentation](https://docs.firefly-iii.org/references/firefly-iii/transaction-types/)
- [Architecture — Firefly III documentation](https://docs.firefly-iii.org/explanation/more-information/architecture/)
- [An Engineer's Guide to Double-Entry Bookkeeping (Anvil)](https://anvil.works/blog/double-entry-accounting-for-engineers)
- [Show HN: Double-entry accounting based personal finance app](https://news.ycombinator.com/item?id=42256125)

### Laravel Patterns
- [Action Pattern in Laravel: Concept, Benefits, Best Practices — Nabil Hassen](https://nabilhassen.com/action-pattern-in-laravel-concept-benefits-best-practices)
- [Service Pattern in Laravel: Why it is meaningless — Nabil Hassen](https://nabilhassen.com/laravel-service-pattern-issues)
- [Why I wrote Laravel Actions — Loris Leiva](https://lorisleiva.com/why-i-wrote-laravel-actions)
- [Laravel Queues — Laravel 12.x documentation](https://laravel.com/docs/12.x/queues)
- [Livewire 4 vs Inertia.js 3: Which Laravel Frontend Stack Should You Use in 2026? — DEV Community](https://dev.to/hafiz619/livewire-4-vs-inertiajs-3-which-laravel-frontend-stack-should-you-use-in-2026-47p4)
- [Livewire vs Inertia — Scalable Path](https://www.scalablepath.com/php/livewire-vs-inertia)

### ETL & Pipelines
- [Laravel Ingest — Laravel News](https://laravel-news.com/laravel-ingest)
- [How I Optimized a Data Import Process by 90% Using ETL Techniques in Laravel 11 — Medium](https://medium.com/@hafierrogarcia/how-i-optimized-a-data-import-process-by-90-using-etl-techniques-in-laravel-11-f1193f9b106a)

### Idempotency & Dedup
- [Mastering Idempotency for Secure Financial Transactions — PingCAP](https://www.pingcap.com/article/mastering-idempotency-secure-financial-transactions/)
- [Idempotency's role in financial services — CockroachLabs](https://www.cockroachlabs.com/blog/idempotency-in-finance/)
- [Detect duplicates across bank files — AI Accountant](https://www.aiaccountant.com/blog/detect-duplicates-across-bank-files)
- [Eliminating duplicate project transactions across systems and re-uploads — fitgap](https://us.fitgap.com/stack-guides/eliminating-duplicate-project-transactions-across-systems-and-re-uploads)

### Money & Currency
- [Handling Money in Laravel/PHP: Essential Tips — Medium](https://medium.com/@laravelprotips/handling-money-in-laravel-php-essential-tips-014b5ee83336)
- [Money for PHP — moneyphp.org documentation](https://www.moneyphp.org/en/stable/)
- [akaunting/laravel-money — GitHub](https://github.com/akaunting/laravel-money)
- [Dealing with Money in Laravel — codecourse](https://codecourse.com/articles/dealing-with-money-in-laravel)

### Bank Statement Parsing
- [jejik/mt940 on Packagist](https://packagist.org/packages/jejik/mt940)
- [genkgo/camt — GitHub](https://github.com/genkgo/camt)
- [php-financial-formats on Packagist](https://packagist.org/packages/dschuppelius/php-financial-formats)
- [A Practical Guide to the Bank Statement CAMT.053 Format — SEPA for Corporates](https://www.sepaforcorporates.com/swift-for-corporates/a-practical-guide-to-the-bank-statement-camt-053-format/)

### Recurring Detection
- [How does Subaio detect recurring payments? — Subaio](https://subaio.com/subaio-explained/how-does-subaio-detect-recurring-payments)
- [Plaid: Build deeper user connections with data driven insights](https://plaid.com/blog/recurring-transactions/)
- [Recurrent Payments Identification and Management — Meniga](https://www.meniga.com/resources/recurring-payments/)

### IMAP / Email
- [Webklex/laravel-imap — GitHub](https://github.com/Webklex/laravel-imap)
- [Webklex laravel-imap documentation](https://webklex.github.io/laravel-imap/)

### SQLite Performance
- [Boost Your Laravel App's Performance with SQLite's WAL Mode](https://supunnethsara.dev/boost-your-laravel-apps-performance-with-sqlites-wal-mode)
- [Using SQLite in production with Laravel — Laravel News](https://laravel-news.com/using-sqlite-in-production-with-laravel)
- [SQLite WAL — sqlite.org](https://sqlite.org/wal.html)
- [SQLite performance tuning — phiresky's blog](https://phiresky.github.io/blog/2020/sqlite-performance-tuning/)

---

*Architecture research for: diederik — local personal-finance dashboard*
*Researched: 2026-05-12*
