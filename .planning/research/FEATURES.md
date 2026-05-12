# Feature Research

**Domain:** Personal-finance / transaction-aggregator dashboard (multi-source, Dutch banking context, local-only)
**Researched:** 2026-05-12
**Confidence:** HIGH for ecosystem table-stakes and differentiator gap analysis (verified across Lunch Money, Actual, Firefly III, Monarch, YNAB official docs + community discussions); MEDIUM for Dutch-banking specifics (MT940/CAMT.053 well documented; ICS-bulk-iDEAL has effectively no prior art).

## Executive Framing

The personal-finance / transaction-aggregator category has a mature feature consensus (table stakes are well-established across Lunch Money, Actual, Firefly III, YNAB, Monarch, Copilot). Where diederik can win is on **chain resolution** — the routing from PayPal → ASN/ICS and ICS-bulk-iDEAL → ASN reconciliation has no prior art in any tool I could find. Firefly III is the closest comparison (PHP/Laravel, self-hosted, multi-currency, multi-source) but its rigid double-entry model and lack of forecasting + lack of automatic cross-account chain resolution leave a clear gap.

The killer differentiators here are:
1. **PayPal → underlying-funder chain resolution** (no tool does this natively).
2. **ICS bulk iDEAL settlement decomposition** (zero prior art for Dutch ICS; standard tools treat the settlement as one opaque transaction).
3. **Local-only privacy-first stance** with CSV+MT940+CAMT.053+IMAP ingestion (no API tokens, no cloud).
4. **Cash-flow forecasting with what-if** (Firefly III explicitly lacks this; Monarch has it but is cloud-only and US-centric).

The risks: recurring detection and auto-categorization are well-trodden but tricky in their failure modes (false positives kill trust); idempotent re-import is harder than it looks because bank transactions lack reliable unique IDs in CSV exports.

## Feature Landscape

### Table Stakes (Users Expect These)

Missing any of these and the tool feels broken — even though no user will *praise* having them.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **CSV import (multi-format)** | Every competitor supports it; user explicitly listed it | M | Per-source parser; column mapping per institution. ASN, ICS, PayPal each have distinct CSV schemas — don't try to autodetect, declare the source per upload. |
| **MT940 + CAMT.053 import (ASN)** | ASN provides both; CAMT.053 is the SEPA-zone default since most banks switched | M | CAMT.053 is hierarchical XML (less ambiguous to parse); MT940 is fixed-tag SWIFT legacy (Tag 20=ref, Tag 60F=opening balance, Tag 61=lines, Tag 86=description). Prefer CAMT.053; keep MT940 as fallback. |
| **Transaction list with filters** | Universal — date range, account, category, search | L | Sort, paginate, virtual-scroll for years of history. |
| **Per-month view ("this month at a glance")** | Stated core value | M | Sums in/out/remaining; matches PROJECT.md core value verbatim. |
| **Categories with manual override** | Universal | S | Hierarchical (Subscriptions > Streaming > Netflix) is overkill for v1; flat-with-tags is simpler and more flexible. |
| **Idempotent re-import (dedup)** | Hard requirement per PROJECT.md; user-flagged risk | **L** | See "Idempotency Strategy" deep dive below — this is more subtle than "hash the row". |
| **Notes / attachments per transaction** | Lunch Money, Actual, Firefly all have it | S | Text notes for v1; attachments deferred. |
| **Account list with balances** | Universal | S | Per-account current balance + last-import-date. |
| **Single-user auth** | Local-only doesn't excuse skipping auth — local web servers can still be reached on LAN | S | Laravel's built-in auth; one user in v1, schema supports more. |
| **Settings / config UI** | Users expect to configure currencies, default views, IMAP creds | S | Read-only display of file-stored secrets; never expose to network. |
| **Export to CSV** | "It's my data" is table stakes for self-hosted | S | One-shot CSV dump per account or all. |

### Differentiators (Competitive Advantage)

These are where diederik wins. They map 1:1 to the PROJECT.md Core Value and most have no prior art.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **PayPal → underlying-funder chain resolution** | Killer feature. No other tool ties a Netflix PayPal charge back to the actual ASN debit that funded it. | **L** | Deterministic match when PayPal CSV carries `Transaction Reference ID` and ICS/ASN line has matching order ID; fallback to fuzzy (amount + date ± 3 days + merchant tokens). Learn from confirmations. |
| **ICS bulk iDEAL → ASN decomposition** | The signature problem. ICS settles monthly as one lump iDEAL payment from ASN; users currently reconcile this manually. | **L** | Match by (a) settlement amount equals sum of ICS statement lines in the period (b) settlement date within a few days of statement close. Reverse-link each ICS line to the parent ASN settlement. |
| **ICS next-settlement forecast** | "What will I owe ICS this month?" — answers a question competitors don't ask | M | Sum of ICS transactions since last settlement, plus FX adjustments for non-EUR cards. |
| **Cash-flow forecast (per-account, day-level)** | Firefly III explicitly lacks this; Monarch has it but is cloud-only and US-centric | **L** | Project balance forward using known recurring + scheduled one-offs + pending ICS settlement. 30/60/90-day horizons. |
| **What-if scenarios (non-persisted)** | Standard in Monarch Plus / YNAB; novel in self-hosted tools | M | "What if I cancel Netflix?" — mutates forecast in-memory only, never writes to DB. Diff view vs. baseline. |
| **Recurring at any cadence + monthly-equivalent normalization** | Most tools handle monthly well; quarterly/annual subscriptions get miscounted | M | Normalize €120/year → €10/month-equivalent; detect cadence from observed periodicity. Critical for "fixed monthly payments" view. |
| **IMAP receipt scanning (multi-inbox, no per-source config)** | Catches forwarded receipts, app-store charges, services that only email | **L** | Scan all configured inboxes for any known sender pattern (regex/template per merchant). Backfill 3+ months. App-password auth. |
| **`.eml` / `.mbox` import as fallback** | Users with privacy concerns about IMAP creds; portable | S | Same parser pipeline as IMAP, different transport. |
| **Funding-chain drill-down UI** | "Show me where this charge came from" — the visual payoff | M | Tree/flow view: ICS line → ICS settlement → ASN debit; PayPal charge → PayPal funding event → ASN/ICS source. |
| **Income as first-class (vs. negative expense)** | Distinct from internal transfers — see "Income vs. Transfer Detection" deep dive | M | Salary, refunds, transfers-in have different semantics; cash-flow forecast needs both sides balanced. |
| **Multi-currency: preserve original + settled** | Most US-centric tools assume one currency; PocketSmith and Firefly III handle it but with friction | M | Two columns: original (USD/etc) + settled (EUR). FX rate captured at settlement time, never recomputed. |
| **Local-only / no-cloud / no-telemetry** | Privacy-conscious users will not use Monarch/Copilot/Lunch Money; self-hosted is the entire reason Firefly III has a user base | S | Laravel serves on `127.0.0.1`; don't bind `0.0.0.0`. Document it. |
| **Subscription drift / trend analysis** | Firefly III's "Bills" view is static; users want "Netflix went up 12% this year" | M | Trend per recurring item over its history. |
| **Calm, content-first UI** | Firefly III is dense and accountant-flavoured; competitors like Copilot win on aesthetic | M | Per PROJECT.md: Linear / Notion vibe; not a dashboard maximalist. |

### Anti-Features (Commonly Requested, Often Problematic)

These look reasonable to add but conflict with the project's stated scope or cause more pain than they're worth.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| **Budgeting / envelope allocation** (YNAB-style) | YNAB and Actual users will ask for it; it's table stakes *in those tools* | Pulls scope from "show me what's happening" to "tell me what to spend"; doubles UI complexity. PROJECT.md positions this as a visibility tool, not a budgeting tool. | Skip entirely for v1. If user wants budgets later, the recurring-detection + forecasting features already give 80% of the value with 20% of the UX. |
| **Investment / brokerage tracking** | Comes up in every personal-finance feature request | Explicitly out of scope per PROJECT.md; portfolio tracking is its own domain (price history, dividends, splits, cost basis, FIFO/LIFO). | Out of scope. Document in PROJECT.md. |
| **Goals / savings targets** | Popular in Monarch, Lunch Money | Visibility tool, not a coaching tool. Goals require commitment UI, progress bars, notifications — scope creep. | Out of scope for v1. |
| **Tax / VAT reports** | "I track expenses, why not also tax-categorize?" | Tax is jurisdiction-specific, has audit-trail requirements, conflicts with simple ad-hoc categories. PROJECT.md explicitly excludes. | Out of scope. CSV export is the escape hatch. |
| **Bank PSD2 / open-banking API integrations** (Tink, Nordigen, Plaid, Enable Banking) | Standard for modern fintech | Token rotation, consent expiry every 90 days, ICS+Google Play don't expose APIs anyway. PROJECT.md explicitly excludes. | CSV + MT940 + CAMT.053 + IMAP. |
| **Receipt-image OCR** | Looks like a natural fit alongside IMAP scanning | OCR adds a fragile ML dependency; email + CSV is the data spine. PROJECT.md explicitly defers to v2+. | Defer to v2+ unless validated. |
| **Mobile / native app** | Asked for in every web app | Localhost + mobile is incompatible. Hosted deployment is itself out of scope. | Out of scope. Web UI on desktop is sufficient. |
| **Outbound payment initiation (iDEAL / SEPA)** | "Since you know what I owe ICS, just pay it" | Payment initiation is a separate regulated capability; security responsibility user does not want. PROJECT.md excludes. | Recommend amount; user pays in their bank app. |
| **Full double-entry accounting** | Firefly III does this | Friction the original creator of Firefly *admits* drives users away ("you feel transactions twice"). Single-entry with chain links is enough. | Single-entry with explicit chain relationships (parent-link IDs). |
| **AI / LLM auto-categorization** | Trendy | Cold-start with a single user gives 60% accuracy; rules + per-merchant memory hits 95% with no LLM call and no data leaving the machine. | Rule-based first; learned-merchant-mapping second; optional small-model layer only if rules+memory aren't enough. |
| **Real-time / streaming updates** | "Push notifications when a charge comes in" | Incompatible with batch CSV + IMAP-poll architecture. Diederik is not transactional, it's reconciliatory. | Manual refresh + scheduled IMAP poll is fine. |
| **Granular per-transaction budgeting / split allocations across categories** | Firefly III split transactions | Each split is a UX trap; users rarely use it and it complicates every aggregation. | Tags (1:N) instead of splits. |

---

## Deep Dives (Question-Specific)

### 1. Recurring Detection Algorithms

**State of the art across competitors:**

- **Lunch Money**: Detects recurring on "same payee + same amount + repeated cadence" (literally "same payment 2 months in a row" for monthlies). Suggestions go to a separate approval queue — never auto-applied. They explicitly note their algorithm has been improved over time "to get fewer false positives." Match rule: payee + amount + date window (e.g., "between 1st and 5th of the month").
- **Actual Budget**: A "Find schedules" button scans for recurring patterns and lists candidates. User confirms. Schedules can be linked to rules for auto-categorization.
- **Firefly III**: Recurring transactions are user-defined templates that auto-generate transactions on schedule. This causes a known problem: imports + scheduled-generation creates duplicates. Users have complained for years.

**Failure modes (false positives):**
- **Variable-amount subscriptions** (utilities, phone bills with usage-based components) — exact-amount matching misses them. Solution: amount tolerance (±10–20%) for variable items; let user flag an item as "variable" to widen the band.
- **Annual / quarterly cadence** — needs more than 2 observations to confirm. Lunch Money's "2 months in a row" doesn't work for annual. Solution: require ≥2 observations *of the cadence*, not 2 months — and a longer minimum history before suggesting annual.
- **One-off charges that happen to repeat by coincidence** (two €15.99 purchases from different merchants) — name-fuzziness blowback. Solution: require merchant-token overlap, not just amount + date.
- **Cancelled-then-reactivated subscriptions** — gap should not break the recurring chain. Solution: tolerate one missed period before classifying as "ended".

**Recommended algorithm for diederik:**

1. Group transactions by `(normalized_merchant, amount_bucket)` where `amount_bucket` is `(amount / amount_tolerance)` rounded (start with 5% tolerance, configurable).
2. For each group with ≥3 occurrences: compute median interval in days. Snap to cadence: 7±2 (weekly), 30±5 (monthly), 90±10 (quarterly), 365±15 (yearly).
3. Confidence score: based on (count, interval consistency stddev, amount stddev).
4. **Always suggest, never auto-apply.** Approval queue is non-negotiable — false positives erode trust faster than missed positives.
5. Once approved, future matches auto-link (and the system tells the user "this looks like your Netflix subscription — confirmed").

**Complexity:** M for v1 (basic group-by + interval detection); L if you want robust variable-amount handling.

### 2. Auto-Categorization with Learning

**Industry approaches:**
- **Naive Bayes on merchant descriptions** is standard for transaction categorization in academic and OSS implementations. Cold-start ~60% accuracy; rises to 95% after ~100 user-labeled examples.
- **Rule-based first, ML fallback** is the dominant production pattern. Rules cover predictable recurring items (string-contains "NETFLIX" → Subscriptions); ML covers the long tail.
- **Actual Budget's pattern is the cleanest**: "imported payee" is preserved verbatim; user assigns a "payee" (canonical name); auto-creates a rule after seeing the same import→assignment twice. No ML needed for the common case.

**Recommended approach for diederik (single-user, no cloud):**

1. **Layer 1 — Explicit rules** (user-defined): "If description matches regex X → category Y". UI for editing.
2. **Layer 2 — Learned merchant mapping**: `(normalized_description) → category` table built from user corrections. Once user categorizes "PYPL *NETFLIX 7032" as "Subscriptions", every future "PYPL *NETFLIX *" gets the same category. This is just a dictionary lookup; no ML library needed.
3. **Layer 3 — Suggestion ranking** (optional, v1.x): For uncategorized rows, rank top-3 categories by token overlap with previously-categorized rows. Naive Bayes is fine; even simpler TF-IDF + cosine is plenty for a single user.

**Avoid:** LLM-based categorization for v1. It leaks data (privacy violation per PROJECT.md), it's slow, it costs money, and rules + memory handles 95% of cases. Reconsider only if user demand emerges.

**Complexity:** S (Layer 1+2). M (with Layer 3).

### 3. Idempotent Statement Re-Import (Dedup)

This is harder than it looks because **bank CSV exports lack stable unique IDs**. The same transaction re-exported can come out with subtly different field values (whitespace, capitalization, description re-rendered, date format varying).

**Industry strategy (verified against Modern Treasury, financial reconciliation patterns):**

1. **Canonicalize first, hash second.** Don't hash raw rows.
2. **Multi-hash approach.** Compute a primary fingerprint from `(account_id, post_date, amount, normalized_description)`. If insert fails, query the colliding record and compute secondary hashes to decide: same transaction or different one that happens to collide?
3. **Source-bank reference field is gold.** ASN's MT940 Tag 20 + CAMT.053 `<AcctSvcrRef>` and `<EndToEndId>` are stable per-transaction; use them when present. Falls back to the canonical fingerprint when absent.

**Recommended fingerprint for diederik:**

Primary fingerprint per transaction:
```
sha256(
  account_id || "|" ||
  iso_date(post_date) || "|" ||
  amount_in_minor_units || "|" ||
  currency || "|" ||
  normalize(description)
)
```
Where `normalize(description)` does: trim, collapse whitespace, lowercase, strip non-alphanumeric (or keep `*` and `/` for merchant tokens like `PYPL *NETFLIX`), apply per-source canonicalization rules.

**Plus a secondary fingerprint** that includes the source bank reference (when available):
```
sha256(account_id || "|" || bank_ref)
```

**Insert logic:**
- Insert if neither fingerprint exists. 
- If primary matches: skip (true duplicate).
- If secondary matches but primary differs: likely the same transaction with normalized description differences — flag for review, don't insert blindly.

**The trap:** Same transaction from two sources (IMAP receipt + CSV statement line) is *not* a duplicate at fingerprint level (different `account_id`), it's a *cross-source match*. Handle that separately in chain resolution, not in dedup.

**Complexity:** M for v1 (single canonical fingerprint + unique constraint at DB layer); L if you want robust secondary-hash conflict handling.

### 4. Cross-Source Matching (PayPal ↔ ICS ↔ ASN)

**Industry patterns:**
- **Date + amount window** with tolerance is the dominant heuristic (±3 days typical, ±tolerance% on amount for FX).
- **Reference IDs** (PayPal Transaction ID, ICS order ID) are gold when present — use them deterministically before falling back to fuzzy.
- **Levenshtein distance** for merchant-name fuzziness; Cozy Banks' bills-matching is a good reference for the algorithmic structure (date window, amount window, fuzzy name).
- **Confidence scoring**: auto-link at ≥95%, review queue 70–95%, no link below 70% (industry consensus).
- **Embeddings** are overkill for a single-user dataset; token-overlap or Jaccard on merchant tokens is sufficient.

**Recommended approach for diederik:**

For PayPal → ASN/ICS chain:

1. **Deterministic pass**: PayPal CSV has `Transaction ID`. ICS CSV often has a free-text reference that may contain it (or a PayPal order number). Try exact-substring match first.
2. **Fuzzy pass** (when no ref match): 
   - Date window: ASN debit must be within `[paypal_date, paypal_date + 7 days]` (PayPal settles 1–7 days later to funding source). Asymmetric window because direction matters.
   - Amount: exact match in EUR, or original-currency match if PayPal charged a non-EUR amount and ASN saw the converted EUR.
   - Merchant: PayPal funding-source debit on ASN is typically literally "PAYPAL" — *low merchant signal*. Lean on date + amount.
3. **Confidence score** → auto-link ≥95%, queue 70–95%, ignore <70%.
4. **Learn from confirmations**: When user confirms a link, store the pattern (e.g., "PayPal charges from merchant X always come from ASN account Y within 3 days") to bias future matches.

For ICS-bulk-iDEAL → ASN settlement:
- Sum ICS lines per statement period. Match to ASN debit where `amount = sum && description contains "ICS" or matches ICS BIC`.
- Decompose: each ICS line becomes a child of the ASN settlement transaction.
- This is unique to diederik — no prior art found. Implementation is straightforward (sum + match) once the statement-period boundaries are defined.

**Complexity:** L for the full system (chain resolution is the headline feature, do not skimp).

### 5. Cash-Flow Forecasting

**Industry approaches:**
- **Monarch Money**: Forecasts account balances "a few months ahead" using upcoming income + recurring bills. Plus tier adds full what-if scenarios. Considered Monarch's biggest differentiator.
- **Copilot Money**: Has a forecasting feature request that's still open — they don't have it yet as of late 2025.
- **Firefly III**: **Explicitly does not have this.** The maintainer closed the forecasts discussion in 2024. This is the biggest gap and the strongest validation of diederik's pitch.
- **YNAB**: Doesn't forecast; it allocates the present. Different philosophy.

**Recommended approach for diederik:**

1. Per-account day-by-day projection:
   - Start: current balance.
   - For each future day in horizon: apply known recurring debits/credits, scheduled one-offs, pending ICS settlement.
   - Output: array of `{date, balance}` per account.
2. Surface surplus/shortfall windows: highlight days where projected balance crosses configurable thresholds (e.g., `< €0` red, `< €500` amber).
3. Horizon defaults: 30 / 60 / 90 days. Toggleable.
4. UI: line chart per account; collapsed list of "next 14 days of charges" beneath.

**What-if mutations:**
- Show a sidebar of "scenarios" (cancel Netflix; add €500 planned expense on 2026-06-15).
- Apply scenarios as in-memory overlays on the projection. Diff view: "without scenario" line + "with scenario" line.
- **Never persist** unless user explicitly clicks "make this real" (which would then create the scheduled transaction).
- This is a UX pattern: temporary state, draft mode, throwaway.

**Complexity:** L (forecasting engine + what-if overlay system). Highest-value differentiator after chain resolution.

### 6. Income Detection vs. Internal Transfer Detection

**Industry approaches:**
- **YNAB**: Transfers between on-budget accounts are explicitly not income or expenses; they're a separate transaction type. User manually marks them.
- **Lunch Money**: Categorizes income vs. expense via user-set categories; doesn't auto-detect transfers as a distinct class beyond rules.
- **Firefly III**: Three transaction types (deposit/withdrawal/transfer). User declares the type; no auto-detection.

**Heuristics for auto-distinguishing income from transfer (diederik-specific):**

1. **Counterparty IBAN matches one of the user's own accounts** → internal transfer (high confidence).
2. **Counterparty is a known payroll/employer name** (one-time setup: tag your salary source) → income (recurring inflow rule).
3. **Counterparty is ICS or another known credit-card issuer** + amount matches recent statement total → settlement transfer (not income).
4. **PayPal credit** → refund or income depending on source; PayPal CSV has `Type` field; trust it.
5. **Everything else inflow** → flagged as "uncategorized income — please review".

**Critical rule:** When the data model represents a transfer, it must be a **single logical event with two legs** (debit on source account, credit on destination account). Both legs should fingerprint independently for dedup but be linked by a transfer-ID. This avoids the "ASN→ICS settlement counted as income" bug that Firefly III users hit.

**Complexity:** M (heuristics + transfer-link data model).

### 7. Multi-Currency UX

**Industry patterns:**
- **PocketSmith** does multi-currency well for personal finance (preserves original + converted).
- **NetSuite / business accounting** patterns are over-engineered for personal use but the principle holds: capture rate at settlement, store both amounts, recompute nothing.
- **Firefly III** supports multi-currency but with friction (per-account currency, manual rate updates).

**Best practices for diederik:**

1. **Store both amounts on every transaction**: `original_amount`, `original_currency`, `settled_amount`, `settled_currency` (typically EUR for diederik), `fx_rate` (= original/settled, captured at settlement).
2. **Never recompute historical FX.** The bank/card issuer already did it; preserve their number forever.
3. **Display**: In lists, show settled (EUR) as primary, original currency as muted secondary line. E.g.
   ```
   Google Play       €12.45     ($14.99 USD)
   ```
4. **Totals**: Default to settled currency (EUR); offer a "show in source currency" toggle that re-aggregates from `original_amount` (useful for "how much did I spend in USD this year").
5. **Forecasting**: Project in settled currency only. FX risk on future foreign-currency charges is out of scope.

**Complexity:** M (data model + display logic).

### 8. Dutch Banking Specifics

**MT940 vs CAMT.053 (ASN provides both):**

- **MT940**: SWIFT legacy format. Fixed-tag structure (Tag 20 = transaction reference, Tag 25 = account ID, Tag 28C = statement number, Tag 60F = opening balance, Tag 61 = statement lines, Tag 86 = description, Tag 62F = closing balance). Quirks: Tag 86 often spans multiple lines; subfields encoded in `?20`, `?21`, etc. for SEPA structured data. Whitespace and ordering matter. Parsing libraries exist but most are flaky.
- **CAMT.053**: ISO 20022 XML. Hierarchical, named elements (`<Ntry>` for entries, `<NtryDtls>` for details, `<AcctSvcrRef>`, `<EndToEndId>`, `<RmtInf>`). Larger files, less ambiguous, more reliable. SEPA-zone default since most banks switched.

**Recommendation:** Build CAMT.053 parser first (more reliable, less surprise). Add MT940 as fallback for users who only have legacy exports. The Dutch Payments Association publishes the CAMT.053 spec.

**iDEAL transaction descriptors:**

- iDEAL is the Dutch domestic payment scheme; transactions arrive in ASN's CSV/MT940/CAMT.053 as SEPA transfers with structured remittance info.
- The reference field typically contains: merchant name + iDEAL transaction code (16-digit numeric) + sometimes the merchant's own order reference.
- Key fields per SEPA: `EndToEndId` (uniquely identifies the iDEAL transaction end-to-end, ISO 11649 structured), `RemittanceInformation` (free-text). Use `EndToEndId` for dedup secondary hashes.
- Note: iDEAL is being rebranded to Wero across the EPI region (announced 2024–2026 transition). Worth supporting Wero descriptors as they arrive.

**ICS Cards monthly bulk settlement — prior art:**

- **There is effectively no prior art.** No personal-finance tool I could find handles ICS-as-credit-card-with-bulk-iDEAL-settlement natively. Firefly III treats the ASN→ICS settlement as a generic transfer; the ICS statement lines as separate transactions; the two are not auto-linked.
- ICS Cards is uniquely Dutch — they issue Visa/MasterCard for ABN AMRO, Rabobank, ANWB, etc., and settle monthly via iDEAL pull from the cardholder's bank.
- **This is genuine greenfield differentiator territory.** Implement it well and there is literally no competitor.

**Recommended approach:** 
- ICS CSV/Excel export → individual transactions with statement-period grouping.
- ASN debit matching `ICS` BIC + amount equal to ICS statement total → mark as settlement transfer.
- Link: ASN settlement is parent; ICS lines are children. Drill from either side reveals the full chain.
- Forecast: sum of ICS lines since last settlement = expected next settlement amount.

### 9. Firefly III as Comparison Point

Firefly III is the closest comparable to diederik (PHP/Laravel, self-hosted, multi-source, multi-currency, open-source). Comparing feature-by-feature:

| Firefly III Strength | Firefly III Weakness | Diederik's Opportunity |
|---|---|---|
| Mature multi-currency support | Per-account fixed currency, manual rate updates | Per-transaction dual-amount, automatic from import |
| Rich rules engine | Rules engine is dense + intimidating to configure | Simpler "learn-from-corrections" + optional explicit rules |
| Recurring transactions as templates | Templates *create* transactions, causing dedup issues with imports | Recurring is a *detection layer* over imported transactions, not a generator |
| Robust API + 3rd-party ecosystem (importers, mobile) | Heavy installation footprint; many users abandon it | Single-file SQLite, minimal deps, single binary feel |
| Double-entry accounting model | Forces users to think in source-account + destination-account for every transaction; creator admits the friction drives users away | Single-entry with explicit chain-relationship links (one debit + one optional parent-link) |
| Reconciliation feature | Reconciles on transaction date not booking date; broken for cross-period transactions | Both dates stored; reconcile on the date that matches the source statement |
| Multi-source import (CSV, MT940, CAMT.053 via Data Importer) | Importer is a separate app, complex setup | Built-in, opinionated parsers per source |
| Tagging and categories | No automatic cross-source chain resolution | **The headline differentiator: chain resolution** |
| Bills view | No cash-flow forecast / what-if (explicitly closed by maintainer in 2024) | **Cash-flow forecast + what-if is the second headline differentiator** |
| | UI feels accountant-flavoured | Calm, content-first UI (Linear / Notion vibe) |

**Key insight from Firefly III's failure modes:** users explicitly complain that (a) it's too complex, (b) the double-entry model creates friction, (c) recurring + import causes duplicates, (d) reconciliation math is broken for multi-currency, (e) there's no forecasting. Every one of these is a diederik opportunity.

**Diederik should NOT try to match Firefly III on:** breadth of rules engine, API surface, multi-user, mobile clients, third-party integrations. Lean into focus.

---

## Feature Dependencies

```
[CSV/MT940/CAMT.053 Import]
    └──requires──> [Account Model] ──requires──> [Single-User Auth]
[CSV/MT940/CAMT.053 Import]
    └──enables──> [Transaction List]
                       └──enables──> [Per-Month View]
                       └──enables──> [Categories]
                                        └──enables──> [Auto-Categorize Learning]

[Idempotent Dedup]
    └──underpins──> [All Ingestion] (must be in place before second import)

[IMAP / .eml Scanning]
    └──requires──> [Idempotent Dedup]
    └──requires──> [Account Model]

[Recurring Detection]
    └──requires──> [≥3 months of imported history]
    └──requires──> [Categories or normalized merchant names]
    └──enables──> [Forecasting]
                     └──requires──> [Income Detection vs. Transfers]
                     └──enables──> [What-If Scenarios]

[PayPal → ASN/ICS Chain Resolution]
    └──requires──> [PayPal Import] + [ASN Import] + [ICS Import]
    └──requires──> [Cross-Source Fingerprinting / Reference Capture]
    └──learns-from──> [User Confirmations] ──improves──> [Auto-link confidence]

[ICS Bulk Settlement Decomposition]
    └──requires──> [ASN Import] + [ICS Import]
    └──requires──> [Transfer Detection]
    └──enables──> [ICS Next-Settlement Forecast]

[Multi-Currency Tracking]
    └──must-be-built-in-from-v1── (retrofitting loses FX info forever)

[Dashboard ("This Month at a Glance")]
    └──requires──> [Per-Month View]
    └──requires──> [Recurring Detection]
    └──requires──> [Income Detection]
    └──enhanced-by──> [Chain Resolution Drill-Down]
```

### Dependency Notes

- **Idempotent dedup is foundational.** Build it before the second import path. Retrofitting dedup after data has been double-imported is a migration nightmare.
- **Multi-currency must be in the schema from day one.** Adding `original_amount` / `original_currency` later means historical Google Play / foreign-card transactions lose FX data permanently. Cheap to add upfront, expensive to bolt on.
- **Recurring detection requires history.** Don't build recurring detection in the same phase as initial import — there's nothing to detect yet. Build import first, gather a few months, then detect.
- **Chain resolution learning requires user confirmations.** Build the UI for confirming a candidate match before relying on learned heuristics.
- **What-if requires forecasting requires recurring + income.** Order: imports → categorization → recurring detection → income detection → forecasting → what-if.

---

## MVP Definition

### Launch With (v1 — vertical-MVP slice)

The absolute minimum to deliver the Core Value ("show me, in one place, what I actually owe and where the money truly came from"):

- [ ] **ASN CSV import** — start with the simplest format; CAMT.053 next; MT940 last
- [ ] **ICS CSV/Excel import**
- [ ] **PayPal CSV import** (with funding-source columns preserved)
- [ ] **Idempotent dedup** (canonical fingerprint per transaction)
- [ ] **Account model + single-user auth + localhost-only binding**
- [ ] **Transaction list with date/account/category filters**
- [ ] **Manual categorization with per-merchant memory** (no ML yet — rules + learned mapping)
- [ ] **Multi-currency dual-amount storage** (original + settled, captured at import)
- [ ] **"This month at a glance" dashboard** (in/out/remaining, recent transactions)
- [ ] **PayPal → ASN/ICS chain matching** (deterministic via reference ID; fuzzy with user confirmation)
- [ ] **ICS bulk settlement → ASN decomposition** (sum match per statement period)
- [ ] **Recurring detection (monthly cadence only)** with approval queue
- [ ] **Income vs. internal-transfer flagging** (heuristics + manual override)
- [ ] **Fixed monthly payments list with funding-source chain icons**

### Add After Validation (v1.x)

Once the v1 vertical slice is in daily use and validated:

- [ ] **IMAP receipt scanning** (multi-inbox, app-password) — trigger when CSV is missing a class of transactions (subscriptions only billed via email)
- [ ] **.eml / .mbox import** — adds when user wants offline-portable receipt ingestion
- [ ] **MT940 + CAMT.053 ASN import** — once user has used CSV enough to want the more reliable formats
- [ ] **Cash-flow forecast (30/60/90 day)** — once recurring + income detection is reliable
- [ ] **What-if scenarios** — once forecasting is trusted
- [ ] **Quarterly + annual recurring cadence detection** — once enough history exists
- [ ] **Recurring drift / trend per item** — "Netflix went up 12%"
- [ ] **Subscription cancellation reminders** — based on detected last-charged-date
- [ ] **Export to CSV / JSON** — fulfills "your data is yours"

### Future Consideration (v2+)

Defer until v1 proves itself:

- [ ] **Multi-user / partner sharing** — schema supports it from v1; UI/auth comes later
- [ ] **Receipt-image OCR** — only if email + CSV proves insufficient
- [ ] **Outbound payment initiation** — explicitly out of scope; revisit only if user demand emerges and PSD2 integration becomes simple
- [ ] **PSD2 / open-banking integrations** — explicitly out of scope per PROJECT.md
- [ ] **Hosted / cloud version** — explicitly out of scope per PROJECT.md
- [ ] **Mobile native app** — explicitly out of scope per PROJECT.md
- [ ] **Tax categorization / reports** — explicitly out of scope per PROJECT.md
- [ ] **Investment / brokerage tracking** — explicitly out of scope per PROJECT.md

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| ASN/ICS/PayPal CSV import | HIGH | MEDIUM | P1 |
| Idempotent dedup | HIGH (silent — breaks trust if missing) | MEDIUM | P1 |
| Multi-currency dual-amount | HIGH (irreversible if skipped) | LOW | P1 |
| Transaction list + per-month dashboard | HIGH | MEDIUM | P1 |
| Manual categorization + per-merchant memory | HIGH | LOW | P1 |
| PayPal → ASN/ICS chain resolution | **HIGHEST (core value)** | HIGH | P1 |
| ICS bulk settlement decomposition | **HIGHEST (core value)** | MEDIUM | P1 |
| Recurring detection (monthly) | HIGH | MEDIUM | P1 |
| Income vs. internal-transfer flagging | HIGH | MEDIUM | P1 |
| Fixed monthly payments view | **HIGH (core value)** | LOW | P1 |
| Single-user auth + localhost binding | HIGH | LOW | P1 |
| Cash-flow forecast (30/60/90 day) | HIGH (differentiator vs Firefly III) | HIGH | P2 |
| What-if scenarios | MEDIUM (delightful once forecast exists) | MEDIUM | P2 |
| IMAP receipt scanning | MEDIUM (table stakes for completeness) | HIGH | P2 |
| MT940 + CAMT.053 ASN import | MEDIUM (CSV covers v1) | MEDIUM | P2 |
| Recurring quarterly/annual cadence | MEDIUM | LOW | P2 |
| .eml / .mbox import | LOW (IMAP covers most users) | LOW | P3 |
| Subscription drift / trend | MEDIUM | LOW | P3 |
| ICS next-settlement forecast | HIGH | LOW | P2 |
| Funding-chain drill-down UI | HIGH | MEDIUM | P2 |
| Export CSV | LOW (table stake but not blocking) | LOW | P3 |
| Multi-user / partner sharing | LOW (v2+) | HIGH | P3 |

**Priority key:**
- **P1**: Must have for v1 — required to deliver Core Value
- **P2**: Should have, v1.x — once P1 is validated in daily use
- **P3**: Nice to have, v2+ — only if compelling demand

---

## Competitor Feature Analysis

| Feature | Lunch Money | Actual Budget | Firefly III | Monarch | Diederik's Approach |
|---------|-------------|---------------|-------------|---------|---------------------|
| **Recurring detection** | "Same payment 2 months in a row" + approval queue | "Find schedules" button + manual confirm | User-defined templates that *generate* (causes dedup bugs) | Auto-detect + manual edit | Group-by + median-interval + approval queue (Lunch Money pattern, refined for variable amounts) |
| **Auto-categorization** | Rules + per-payee learning | Rules + auto-learning from user behavior | Rules engine (powerful but complex) | ML-based categorization | Rules + per-merchant memory; ML only if needed |
| **Idempotent import** | Cloud sync, no duplicate problem | Imports dedup on (date, amount, payee, account) | Importer dedups, but recurring templates cause double-entry | Cloud sync | Canonical fingerprint per (account, date, amount, normalized desc); secondary by bank ref |
| **Cross-source chain** | Not natively | Not natively | Linked via transfers (manual) | Not for PayPal/cards | **Killer feature — automatic, learned** |
| **Cash-flow forecast** | Limited | Not really | **None (closed by maintainer)** | Yes (their headline feature) | **Yes — Firefly III gap; Monarch parity** |
| **What-if scenarios** | No | No | No | Yes (Plus tier) | Yes — non-persisted in-memory overlay |
| **Income vs. transfer** | Manual category | Manual transfer type | Manual transaction type | Auto-detect from payroll | Heuristic auto + manual override |
| **Multi-currency** | Limited | Limited | Yes but friction | Yes (US-centric) | Dual-amount stored at import; never recompute |
| **Dutch banking (MT940/CAMT.053)** | No | No | Yes via Data Importer | No | Native CAMT.053 first, MT940 fallback |
| **ICS bulk settlement** | No | No | No (generic transfers) | No | **Unique — no prior art** |
| **iDEAL descriptor parsing** | No | No | Partial via SEPA | No | Native (EndToEndId, RemittanceInformation) |
| **Local-only / privacy** | No (SaaS) | Yes (local + optional sync) | Yes (self-host) | No (SaaS) | Yes (localhost binding; secrets in config file) |
| **UI aesthetic** | Clean | Functional | Dense, accountant-flavoured | Polished | Calm, Linear/Notion vibe (per PROJECT.md) |

---

## Quality Gate Self-Check

- [x] **Categories are clear** (table stakes vs differentiators vs anti-features) — each has a dedicated section with explicit categorization
- [x] **Complexity noted for each feature** (S/M/L in every table)
- [x] **Dependencies between features identified** — dedicated Dependencies section with diagram and ordering rationale
- [x] **Specifically covers Dutch banking quirks** (MT940 / CAMT.053 differences, iDEAL descriptor structure with EndToEndId + RemittanceInformation, ICS bulk settlement — confirmed zero prior art)
- [x] **Specifically references Firefly III as comparison point** — dedicated deep-dive section + competitor matrix row

---

## Sources

### Competitor product documentation
- [Lunch Money — Recurring Items feature](https://lunchmoney.app/features/recurring-expenses/)
- [Lunch Money — Recurring Items basics](https://support.lunchmoney.app/finances/recurring-items/the-basics-of-recurring)
- [Actual Budget — Schedules docs](https://actualbudget.org/docs/schedules/)
- [Actual Budget — Importing transactions](https://actualbudget.org/docs/transactions/importing/)
- [Actual Budget — Rules](https://actualbudget.org/docs/budgeting/rules/)
- [Firefly III — Missing features (official)](https://docs.firefly-iii.org/explanation/more-information/what-its-not/)
- [Firefly III — Forecasts discussion (closed 2024)](https://github.com/orgs/firefly-iii/discussions/8811)
- [Firefly III — Cashflow predictions issue](https://github.com/firefly-iii/firefly-iii/issues/393)
- [Firefly III — Reconciliation booking-date issue](https://github.com/orgs/firefly-iii/discussions/10834)
- [Firefly III — Recurring + import dedup issue](https://github.com/firefly-iii/firefly-iii/issues/5830)
- [Firefly III — Not double-entry critique](https://news.ycombinator.com/item?id=31572679)
- [Monarch — Cash Flow help](https://help.monarch.com/hc/en-us/articles/20504904768020-Cash-Flow)
- [Monarch — NerdWallet review](https://www.nerdwallet.com/finance/learn/monarch-money-app-review)
- [Copilot Money — Forecasting feature request (open)](https://copilot.canny.io/feature-requests/p/forecasting-1)
- [YNAB — Transfers between accounts](https://support.ynab.com/en_us/categorizing-transactions-a-guide-HyRl60sks)

### Banking formats
- [Confused by MT940, camt.053, pain.001? — format primer](https://backspace-tech.medium.com/banking-file-formats-101-the-basics-and-the-big-buckets-part-1-8e8af29d8f19)
- [MT940 vs CAMT.053 migration guide](https://treasuryease.com/mt940-to-camt053-migration-guide/)
- [Reading a camt.053 bank statement — structure and key fields](https://validatefin.com/en/blog/camt053-bank-statement)
- [Dutch Payments Association — CAMT.053](https://www.betaalvereniging.nl/en/knowledge-base/standards-in-payments/camt-053/)
- [Future of Financial Messaging: MT940 → ISO 20022](https://treasuryxl.com/blog/the-future-of-financial-messaging-migrating-from-mt940-to-iso-20022/)

### Reconciliation & matching algorithms
- [Modern Treasury — Deduplication at Scale](https://www.moderntreasury.com/journal/deduplication-at-scale)
- [Cozy Banks — Bills matching algorithm](https://docs.cozy.io/en/cozy-banks/docs/bills-matching/)
- [Midday — Building an automatic reconciliation engine](https://midday.ai/updates/automatic-reconciliation-engine/)
- [Fuzzy Matching Algorithms in Bank Reconciliation](https://optimus.tech/blog/fuzzy-matching-algorithms-in-bank-reconciliation-when-exact-match-fails)
- [Transaction Matching Algorithms — technical guide](https://www.zerabooks.com/blog/transaction-matching-algorithms-explained)
- [Idempotency in financial services (CockroachDB)](https://www.cockroachlabs.com/blog/idempotency-in-finance/)
- [Mastering idempotency for secure financial transactions](https://www.pingcap.com/article/mastering-idempotency-secure-financial-transactions/)

### Categorization
- [Categorizing Transactions with Machine Learning and rules — mvvenrooij](https://mvvenrooij.nl/2024/12/categorizing-transactions-with-machine-learning-and-rules/)
- [Transaction Classification Overview — Medium](https://marc-deveaux.medium.com/transaction-classification-overview-93e3840dce79)
- [Meniga — What is Transaction Categorisation](https://www.meniga.com/resources/transaction-categorisation/)
- [Scalable and weakly supervised bank transaction classification (arxiv)](https://arxiv.org/pdf/2305.18430)

### PayPal & ICS
- [PayPal — Monthly and Custom Statements for Merchant Reconciliation](https://developer.paypal.com/docs/reports/online-reports/monthly-statement/)
- [PayPal — Reference Transactions](https://www.paypal.com/us/cshelp/article/what-are-reference-transactions-tokenization-ts1469)
- [ICS Cards — Statement and balance](https://www.icscards.nl/abnamrogb/abnamrogb-customer-service/statement-and-balance/statement-and-balance)
- [International Card Services (ICS) overview — ANWB](https://www.anwb.nl/creditcard/informatie/ics)

### Multi-currency UX
- [Workday Design — The UX of Currency Display](https://medium.com/workday-design/the-ux-of-currency-display-whats-in-a-sign-6447cbc4fb88)
- [PocketSmith — Multi Currency Personal Finance](https://www.pocketsmith.com/tour/multi-currency/)

---

*Feature research for: personal-finance / transaction-aggregator dashboard with Dutch banking specifics*
*Researched: 2026-05-12*
