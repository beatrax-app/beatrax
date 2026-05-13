# Phase 2: ASN Statement Coverage (CAMT.053 + MT940) - Research

**Researched:** 2026-05-13
**Domain:** ISO 20022 CAMT.053 bank-statement parsing + SWIFT MT940 legacy parsing + cross-format idempotency
**Confidence:** HIGH (genkgo/camt API surface read directly from source; ASN MT940 Tag 61 variant verified in WoLpH/mt940; fingerprint v3 design grounded in existing Phase 1 code)

## Summary

Phase 2 extends Phase 1's CSV-only ingestion to ASN's two statement formats — CAMT.053 (primary, ISO 20022 XML, parsed via `genkgo/camt ^2.10`) and MT940 (legacy SWIFT text, parsed by a hand-rolled state machine) — while solving the cross-format duplicate problem that the v2 fingerprint cannot. The mechanism is locked: bump `FingerprintComposer::NORMALIZATION_VERSION` from 2 → 3, drop `source_ref` from the v3 tuple, widen with `booked_at` (already a `dateTime` column on `transactions`), re-derive every existing v2 row inside the migration with abort-on-collision, and introduce a new ENRICHED preview state plus an `enriched_from` JSON column so a CAMT-after-CSV import updates the existing row with the stronger `EndToEndId` source_ref rather than skipping or duplicating it.

Two adapter classes land: `Modules\Ingestion\Internal\Adapters\Asn\AsnCamt053Adapter` (consumes `genkgo/camt`'s eager `Genkgo\Camt\Reader::readFile()` and walks `Statement → Entry → EntryTransactionDetail → Reference::getEndToEndId()`) and `Modules\Ingestion\Internal\Adapters\Asn\AsnMt940Adapter` (hand-rolled tag scanner producing `:61:` + `:86:` pairs, with a structured-`?NN`-subfield decoder for the German-style GVC narrative ASN inherited from the SNS/Volksbank stack). Both implement the existing `SourceAdapter` Generator contract, register in `SourceAdapterRegistry`, and extend `HeaderSniffer` with format-specific signatures. The wizard dropdown grows from 1 to 3 options; the validator changes from `'in:asn-csv'` to `'in:asn-csv,asn-camt053,asn-mt940'`. A new `statement_summaries` table captures CAMT/MT940 opening/closing balance + period for the "statement coverage" view.

**Primary recommendation:** Land the v3 fingerprint migration FIRST (with collision pre-check + abort), then the `enriched_from` column, then `statement_summaries`, then the two adapters in parallel (CAMT first — strictly typed library API removes most of the risk), then the wizard surface (ENRICHED state + dropdown), then the cross-format dedup Pest tests as the closing acceptance gate.

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Fingerprint Algorithm**

- **D-21:** Bump `FingerprintComposer::NORMALIZATION_VERSION` from **2 → 3**. The v3 tuple **drops `source_ref`**: `user_id | account_id | posted_at | booked_at | amount_minor | currency | counterparty_normalized`. Existing v2 rows must be re-derived under v3 during the migration step.
- **D-22:** Widen the v3 tuple with **`booked_at`** (full `dateTime`, second-resolution). ASN CSV rows fall to `00:00:00`; CAMT and MT940 carry real booking times. The composite UNIQUE on `transactions(user_id, fingerprint)` stays — only the *content* of the fingerprint changes.
- **D-21a:** A migration walks every existing `transactions` row, re-computes the v3 fingerprint, writes both `fingerprint` and `normalization_version` in a single UPDATE. Pre-checks for collisions before bumping the version stamp — if any same-tuple collision is detected, the migration aborts with a clear error and leaves v2 intact.

**SEPA Reference Handling**

- **D-23:** `source_ref` for CAMT.053 rows is **`EndToEndId` only** (`Ntry/NtryDtls/TxDtls/Refs/EndToEndId`). Missing → `NULL`. No fallback to weaker refs.
- **D-24:** Secondary SEPA refs (`AcctSvcrRef`, `InstrId`, `TxId`, `MsgId`, full `Ntry`/`TxDtls` block) preserved verbatim inside `SourceTransactionDto::$rawPayload['sepa']`. No new columns on `transactions`.
- **D-23a:** MT940 has no `EndToEndId` equivalent. `source_ref` from `:61:` customer reference when meaningful, else `NULL`.

**Cross-Format Re-import Semantics**

- **D-28:** Fingerprint match + stronger `source_ref` → ENRICH (UPDATE writing new `source_ref` + appending to `enriched_from`). Order: `EndToEndId > AcctSvcrRef > InstrId > MT940 ref > CSV ref`. Non-null > null.
- **D-28a:** New nullable JSON column `enriched_from` on `transactions`: array of `{format, ran_at, import_run_id, added: ['source_ref']}` records, one per contributing import (including initial create).
- **D-28b:** Wizard adds fourth preview state **ENRICHED** with diff indicator (`source_ref: ∅ → ENDTOEND-XYZ`). Results summary grows to `"N imported · M skipped · P enriched · K errors"`.
- **D-28c:** `transactions.source_format` continues to record the *creating* format. Enriching format(s) live only in `enriched_from`.

**Adapter Implementations**

- **D-25:** **MT940 parser is hand-rolled**. New module-internal class `Modules\Ingestion\Internal\Adapters\Asn\AsnMt940Adapter`. No new composer dependency for MT940.
- **D-26:** **CAMT.053 parser uses `genkgo/camt` `^2.10`**. New module-internal class `Modules\Ingestion\Internal\Adapters\Asn\AsnCamt053Adapter`. `composer require genkgo/camt` is added in this phase.
- **D-27:** **MT940-specific counterparty pre-normalisation** runs BEFORE the shared `FingerprintComposer::normalize`. The MT940 adapter (or its Normalize-stage extension) strips GVC codes, BIC prefixes, `/REMI/`, `/NAME/`, etc. before handing the cleaned form to the shared normaliser.

**Upload Wizard Surface**

- **D-29:** Dropdown grows from 1 → 3 options: `asn-csv`, `asn-camt053`, `asn-mt940`. Validator: `'in:asn-csv,asn-camt053,asn-mt940'`. Each format has its own `HeaderSniffer` signature.
- **D-29a:** No auto-detection (ING-07 holds project-wide).
- **D-29b:** New entries in `SourceAdapterRegistry` wired in `IngestionServiceProvider`.

**Statement-Level Metadata**

- **D-30:** New `statement_summaries` table (one row per import_run when source carries it), FK to `import_runs`. Captures opening/closing balance, statement number, period start/end, IBAN owner. CSV imports leave the row absent.

### Claude's Discretion

- Concrete batch size for the v3 fingerprint re-derive migration (single transaction vs chunked vs temp-table swap)
- Whether the v3 re-derive lives inside a Laravel migration `up()` or in a separate `php artisan diederik:rederive-fingerprints` command invoked from the migration
- Exact in-app surface for the statement-coverage view (separate page vs inline panel on the import-results page)
- Whether to ship `enriched_from` as `null` by default or `[]` (empty array) on initial-create
- Per-row vs per-DTO encoding of the SEPA secondary-ref sub-array inside `rawPayload['sepa']` (flat keys vs nested structure)
- Whether the cross-format dedup logic lives in a new `EnrichmentStage` between Fingerprint and Persist, or as a new disposition inside `FingerprintStage::isExistingFingerprint`
- The single-key-press keymap on the new ENRICHED row in the preview wizard (if any)
- Anonymised CAMT and MT940 fixture filenames — pick a uniform pattern that matches the Phase 1 `asn-sample-1.csv` / `asn-month-a.csv` shape

### Deferred Ideas (OUT OF SCOPE)

- `AcctSvcrRef` / `InstrId` / `TxId` as indexable columns — Phase 5 if needed
- PayPal Reporting API path (ING-09) — Phase 4
- ICS Cards / multi-currency display — Phase 3
- Statement-coverage page polish (richer UI) — later phase
- Auto-detect uploaded file format — rejected project-wide (ING-07)
- Migrating CSV imports to use `genkgo/camt` — out of scope; CSV adapter stays untouched
- `kingsquare/php-mt940` as a fallback engine — rejected in favour of hand-rolled (D-25)

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ING-02 | User can upload an ASN CAMT.053 (XML) export and have its transactions imported, using `EndToEndId` / `AcctSvcrRef` as the stable source reference | `genkgo/camt 2.10.3` API surface (Reader → Message → Statement → Entry → EntryTransactionDetail → Reference::getEndToEndId) — full method chain documented in §Library / API Surface |
| ING-03 | User can upload an ASN MT940 export as a fallback ingestion path (older statement periods) | MT940 tag map and ASN-specific Tag 61 variant (34-char customer reference) confirmed against WoLpH/mt940's `StatementASNB` class — documented in §Library / API Surface |
| ING-06 | Re-uploading the same statement file (or an overlapping period) does not create duplicate transactions — idempotent | Fingerprint v3 design + ENRICHED state + cross-format dedup tests cover the re-touch — documented in §Fingerprint v3 Migration + §Pipeline Integration |

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Parse CAMT.053 XML | Ingestion module (Internal/Adapters/Asn) | — | Adapters own format-specific parsing; the public contract `SourceAdapter` is already the seam |
| Parse MT940 SWIFT text | Ingestion module (Internal/Adapters/Asn) | — | Same as CAMT; module-internal hand-rolled scanner behind the same `SourceAdapter` interface |
| Pre-parse file-format validation | Ingestion (`HeaderSniffer`) | Import (UploadWizard) | Sniffer owns byte-level signature checks; wizard owns the user-facing error rendering |
| Stable format → adapter map | Ingestion (`SourceAdapterRegistry`) | — | Already the public seam; just add two entries |
| Fingerprint composition (v3 tuple) | Ledger (`FingerprintComposer`) | — | The composer is the single canonical place that knows the algorithm; bump the constant + tuple shape |
| Re-derive v3 fingerprints over existing rows | Ledger (migration) | — | Migration is the only writer to historical rows; uses `FingerprintComposer` directly |
| Cross-format ENRICH decision | Import (`FingerprintStage` or new `EnrichmentStage`) | Ledger (writer) | Pipeline orchestrates the decision; Ledger persists the UPDATE |
| Capture statement-level metadata | Ledger (`statement_summaries` table) | Ingestion adapter (sets the row) | Adapter emits the data; Ledger owns the table and writer action |
| Preview wizard ENRICHED state | Import (`PreviewWizard` Livewire + Blade) | — | Wizard is already the row-disposition renderer |
| Source-format dropdown + validation | Import (`UploadWizard` Livewire) | Ingestion (registry) | Wizard owns the form; registry owns the format list |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `genkgo/camt` | `^2.10` (2.10.3, Aug 26 2025) | CAMT.052/053/054 ISO 20022 parser | 1.2M installs · MIT · actively maintained · handles all 4 CAMT.053 sub-versions (V02/V03/V04/V08) ASN ships [CITED: packagist.org/packages/genkgo/camt] |
| (no new dep for MT940) | — | Hand-rolled state machine | Per D-25; library-supplied engines mis-read ASN-specific `:86:` GVC structure |

### Supporting (already installed from Phase 1)
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `league/csv` | `^9.28` | (CSV only) | Unchanged from Phase 1 — no new CAMT/MT940 use |
| `spatie/laravel-data` | `^4.x` | DTOs for parsed rows | `SourceTransactionDto` already extends `Spatie\LaravelData\Data` — CAMT and MT940 adapters reuse it with no new DTO type |
| `brick/money` | `^0.13` | Multi-currency arithmetic | Used when converting CAMT's `Money\Money` (moneyphp/money) to integer minor units at the adapter boundary |
| `nesbot/carbon` | `^3.x` | Dates | CAMT exposes `DateTimeImmutable`; the adapter wraps to `CarbonImmutable` to match `SourceTransactionDto::$bookedAt/postedAt/valueDate` types |
| `spatie/pest-plugin-snapshots` | latest | Snapshot tests | Same plugin Phase 1 uses for the CSV adapter snapshot |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Hand-rolled MT940 | `kingsquare/php-mt940` | Stagnant (last release Nov 2020); the bank-specific Tag 61 variant for ASN isn't covered out-of-the-box — same hand-rolled work hidden behind a wrapper. Rejected in D-25. |
| `genkgo/camt` | `digitick/sepa-xml` / abandoned `php-sepa-xml` | Those generate SEPA *payment-initiation* XML; they don't parse statements. Not viable. |

**Installation:**

```bash
composer require genkgo/camt:^2.10
```

**Version verification:** `genkgo/camt 2.10.3` published 2025-08-26 on Packagist, requires `php ^8.1`, `ext-dom`, `ext-libxml`, `ext-simplexml`, depends on `moneyphp/money ^4.6` and `jschaedl/iban-validation ^2.5` [VERIFIED: packagist.org/packages/genkgo/camt]. The transitive `moneyphp/money` already coexists with `brick/money` in the project per STACK.md.

## Library / API Surface

### `genkgo/camt` 2.10.3 — exact call chain

The library exposes an **eager (whole-document)** API — no SAX/XMLReader streaming. The Reader returns a fully-materialised `Message` graph. For ASN files (single statement, < 50 MB), this is acceptable; multi-year backfills will land within Phase 2's "synchronous in a Livewire action" envelope.

**Entry point** [VERIFIED: src/Reader.php]:

```php
use Genkgo\Camt\Config;
use Genkgo\Camt\Reader;

$reader = new Reader(Config::getDefault());
$message = $reader->readFile($localPath);  // throws Genkgo\Camt\Exception\ReaderException
$statements = $message->getRecords();
```

**Format detection** [VERIFIED: src/Reader.php]: The Reader reads the root XML element's `xmlns` attribute and iterates the registered `MessageFormatInterface[]` looking for a namespace match. `Config::getDefault()` registers **all four** CAMT.053 versions ASN can emit:

| Version | XML namespace |
|---------|---------------|
| CAMT.053.001.02 | `urn:iso:std:iso:20022:tech:xsd:camt.053.001.02` |
| CAMT.053.001.03 | `urn:iso:std:iso:20022:tech:xsd:camt.053.001.03` |
| CAMT.053.001.04 | `urn:iso:std:iso:20022:tech:xsd:camt.053.001.04` |
| CAMT.053.001.08 | `urn:iso:std:iso:20022:tech:xsd:camt.053.001.08` |

**One call site handles all four** — the adapter does NOT need to branch on version. If the namespace is unrecognised, `Reader::readDom` throws `ReaderException` with `"Unsupported format, cannot find message format with xmlns {$xmlNs}"`. The XSD validation can be disabled via `$config->disableXsdValidation()` for malformed but parseable real-world exports — recommend leaving XSD ON in production, OFF in adapter unit tests where the fixture may be a hand-trimmed minimal example.

**Type hierarchy** (from `src/DTO/`):

```
Genkgo\Camt\DTO\Message
  └─ getRecords(): array<Genkgo\Camt\Camt053\DTO\Statement>     // CAMT.053
        ↑ Statement extends Genkgo\Camt\DTO\RecordWithBalances
            ↑ RecordWithBalances extends Genkgo\Camt\DTO\Record (abstract)
              public function getId(): string
              public function getAccount(): Account
              public function getElectronicSequenceNumber(): ?string
              public function getLegalSequenceNumber(): ?string
              public function getFromDate(): ?DateTimeImmutable
              public function getToDate(): ?DateTimeImmutable
              public function getEntries(): array<Entry>
              public function getAdditionalInformation(): ?string
              public function getCreatedOn(): DateTimeImmutable
              public function getBalances(): array<Balance>      // opening + closing + interim
```

**Entry** [VERIFIED: src/DTO/Entry.php]:

```php
$entry->getAmount(): Money\Money                    // moneyphp/money — has ->getAmount() (string, minor) and ->getCurrency()->getCode()
$entry->getBookingDate(): ?DateTimeImmutable
$entry->getValueDate(): ?DateTimeImmutable
$entry->getReversalIndicator(): bool
$entry->getReference(): ?string                     // entry-level ref (rare)
$entry->getAccountServicerReference(): ?string       // bank-side ID
$entry->getBatchPaymentId(): ?string
$entry->getBankTransactionCode(): ?BankTransactionCode
$entry->getCharges(): ?Charges
$entry->getStatus(): ?string                        // 'BOOK' / 'PDNG' / 'INFO'
$entry->getCreditDebitIndicator(): ?string          // 'CRDT' or 'DBIT' — combine with getAmount() to get signed minor units
$entry->getAdditionalInfo(): ?string
$entry->getIndex(): int
$entry->getTransactionDetails(): array<EntryTransactionDetail>   // 0..N
$entry->getTransactionDetail(): ?EntryTransactionDetail          // first only — DO NOT USE in our adapter (we need all)
```

**EntryTransactionDetail** [VERIFIED: src/DTO/EntryTransactionDetail.php]:

```php
$txDtls->getReference(): ?Genkgo\Camt\DTO\Reference
$txDtls->getReturnInformation(): ?ReturnInformation
$txDtls->getRelatedParties(): array<RelatedParty>
$txDtls->getRelatedParty(): ?RelatedParty            // first only
$txDtls->getRemittanceInformation(): ?RemittanceInformation
$txDtls->getRelatedDates(): ?RelatedDates
$txDtls->getCharges(): ?Charges
$txDtls->getAdditionalTransactionInformation(): ?AdditionalTransactionInformation
$txDtls->getBankTransactionCode(): ?BankTransactionCode
$txDtls->getRelatedAgents(): array<RelatedAgent>
$txDtls->getAmountDetails(): ?Money\Money            // per-TxDtl (a batch entry's children carry per-row amounts here)
$txDtls->getAmount(): ?Money\Money
$txDtls->getCreditDebitIndicator(): ?string
```

**Reference** [VERIFIED: src/DTO/Reference.php] — the chain-resolution anchor:

```php
$ref->getMessageId(): ?string                          // MsgId — the file/batch ID
$ref->getAccountServicerReference(): ?string           // AcctSvcrRef
$ref->getPaymentInformationId(): ?string               // PmtInfId
$ref->getInstructionId(): ?string                      // InstrId
$ref->getEndToEndId(): ?string                         // ★ EndToEndId — D-23 anchor
$ref->getTransactionId(): ?string                      // TxId
$ref->getMandateId(): ?string                          // MndtId — SEPA DD mandate
$ref->getChequeNumber(): ?string
$ref->getClearingSystemReference(): ?string
$ref->getAccountOwnerTransactionId(): ?string
$ref->getAccountServicerTransactionId(): ?string
$ref->getMarketInfrastructureTransactionId(): ?string
$ref->getProcessingId(): ?string
$ref->getProprietaries(): array<ProprietaryReference>  // bank-specific extensions
```

**Batch entries — one Ntry, many TxDtls**: An entry with `BankTransactionCode` family `BBDD` (Bulk Direct Debit) or `BBCD` (Bulk Credit Transfer) may carry N `TxDtls` children, each with its own `EndToEndId` and per-row amount in `getAmountDetails()`. **The adapter MUST iterate `$entry->getTransactionDetails()` and yield one `SourceTransactionDto` per TxDtls**, NOT one per Entry. A bulk iDEAL aggregation that lands as one ASN debit but is itemised inside CAMT must surface as N rows so Phase 5's chain resolution can join on the per-row `EndToEndId`. For non-batch entries (`getTransactionDetails()` returns 0 or 1), the adapter yields one row keyed off the entry-level amount.

**Balances** [VERIFIED: src/DTO/Balance.php]:

```php
Balance::TYPE_OPENING  = 'opening';     // OPBD
Balance::TYPE_CLOSING  = 'closing';     // CLBD
// + 7 more: OPENING_AVAILABLE, CLOSING_AVAILABLE, FORWARD_AVAILABLE, INTERIM_AVAILABLE, INFORMATION, INTERIM, EXPECTED_CREDIT
$balance->getAmount(): Money\Money
$balance->getType(): string             // one of the TYPE_* constants
$balance->getDate(): DateTimeImmutable
```

For the `statement_summaries` table (D-30), filter `$statement->getBalances()` by `TYPE_OPENING` and `TYPE_CLOSING`. Both must exist for a well-formed CAMT.053 file.

### MT940 — hand-rolled state machine

**Tag map for ASN MT940** [VERIFIED: WoLpH/mt940 tags.py + RTD docs]:

| Tag | Required | Description | Pattern | Multiplicity |
|-----|----------|-------------|---------|--------------|
| `:20:` | yes | Transaction Reference Number — the *file-level* reference (NOT per-transaction) | `16x` | 1 per statement |
| `:25:` | yes | Account Identification — own IBAN | `35x` | 1 per statement |
| `:28C:` | yes | Statement Number / Sequence Number | `5n[/5n]` | 1 per statement |
| `:60F:` | yes | Opening Balance (final) | `1!a 6!n 3!a 15d` → `[D|C] [YYMMDD] [CCY] [amount,comma]` | 1 per statement |
| `:60M:` | no | Opening Balance (intermediate, multi-part) | same as 60F | 0..1 |
| `:61:` | yes | Statement Line — one per transaction | see below — **ASN VARIANT** | 1..N |
| `:86:` | no | Information to Account Owner — narrative for the immediately preceding `:61:` | `?NN`-prefixed structured subfields, up to 6 lines × 65 chars | 0..1 per `:61:` |
| `:62F:` | yes | Closing Balance (final) | same as 60F | 1 per statement |
| `:62M:` | no | Closing Balance (intermediate) | same as 62F | 0..1 |
| `:64:` | no | Closing Available Balance | same as 60F | 0..1 |
| `:65:` | no | Forward Available Balance | same as 60F | 0..N |

**`:61:` — ASN-specific layout** [VERIFIED: WoLpH/mt940 tags.py `StatementASNB` class]:

Standard SWIFT MT940 reserves 16 characters for `customer_reference`. **ASN extends this to 34 characters** to accommodate a full IBAN, then 16 chars for `bank_reference` (down from 23). The hand-rolled adapter MUST use the ASN regex, not the SWIFT-standard one:

```
^
(?P<year>\d{2})            # value date YYMMDD (first 6 chars)
(?P<month>\d{2})
(?P<day>\d{2})
(?P<entry_month>\d{2})?    # optional entry date MMDD
(?P<entry_day>\d{2})?
(?P<status>[A-Z]?[DC])      # D, C, RD, RC (Dutch banks sometimes emit "RC" reversal)
(?P<funds_code>[A-Z])?      # optional 3rd currency char
\n?
(?P<amount>[\d,]{1,15})     # amount with comma decimal — e.g. "1234,56" or "0,29"
(?P<id>[A-Z][A-Z0-9 ]{3})?  # transaction-type identification: S/N/F + 3 chars, e.g. "NTRF", "NMSC", "SCHG"
(?P<customer_reference>.{0,34})   # ★ ASN: extended to 34 chars (vs SWIFT 16)
(//(?P<bank_reference>.{0,16}))?  # optional, after `//`
(\n?(?P<extra_details>.{0,34}))?  # optional supplementary details
$
```

`(?P<status>)` mapping:
- `C` → credit (positive amountMinor)
- `D` → debit (negative amountMinor)
- `RC` → reversal of credit (negative amountMinor)
- `RD` → reversal of debit (positive amountMinor)

`(?P<id>)` is the four-character transaction-type code. Common ASN values [CITED: Dutch-bank-MT940 community knowledge — needs empirical confirmation from a real ASN file]:
- `NTRF` — non-SWIFT transfer (most common — domestic iDEAL, SEPA CT)
- `NDDT` — SEPA direct debit
- `NMSC` — miscellaneous (card / fee / cash withdrawal)
- `SCHG` — bank charges
- `NREF` — refund

`(?P<amount>)` is **comma-decimal** (Dutch/European convention). The hand-rolled `Mt940AmountParser` MUST replace `,` with `.` before integer-cents conversion. The adapter MUST reuse the integer-only path from `Modules\Ingestion\Internal\Adapters\Asn\AsnAmountParser` (Phase 1 Pitfall 1 mitigation): no `(int) ((float) $amount * 100)` anywhere.

**`:86:` — GVC structured subfields**

Two formats exist in the wild:

1. **Unstructured** — first 6 chars are the local code, rest is free-text narrative. Treat the whole field as `description`. Counterparty name is not separable.
2. **Structured** — `?NN`-prefixed subfields, first 3 chars are the GVC transaction-type code (e.g. `100` = bank transfer, `005` = SEPA Direct Debit). Subfields:

| Code | Meaning | Maps to |
|------|---------|---------|
| `?00` | Posting text / GVC type | (informational; goes into rawPayload) |
| `?10` | Prima nota / journal entry | (informational; goes into rawPayload) |
| `?20–?29` | Purpose / SEPA narrative — concatenate in order | `description` + further structured codes embedded |
| `?30` | Counterparty BIC | (informational) |
| `?31` | Counterparty IBAN | `counterpartyIban` |
| `?32–?33` | Counterparty name (continuation) — concatenate | `counterpartyName` |
| `?34` | Return-debit notes | (informational) |
| `?60–?65` | Additional purpose lines — concatenate | append to `description` |

Inside `?20–?29` and `?60–?65`, the SEPA narrative carries **GVC keyword codes** with `+` or end-of-field as delimiter [VERIFIED: WoLpH/mt940 processors.py `GVC_KEYS`]:

| Code | Semantic | Action |
|------|----------|--------|
| `EREF` | End-to-end reference (SEPA `EndToEndId` equivalent) | Promote to `sourceRef` if non-empty and not literal `NOTPROVIDED` |
| `MREF` | Mandate reference | rawPayload only |
| `CRED` | Creditor identifier (SEPA CI) | rawPayload only |
| `SVWZ` | Purpose / unstructured remittance | `description` text |
| `KREF` | Customer reference | rawPayload only |
| `PURP` | Purpose code (ISO 20022 `Purp/Cd`) | rawPayload only |
| `IBAN` | Counterparty IBAN (when not in `?31`) | `counterpartyIban` fallback |
| `BIC` (note trailing space) | Counterparty BIC | rawPayload only |
| `ABWA` | Deviating applicant | rawPayload only |
| `MDAT` | Mandate signing date | rawPayload only |
| `COAM` / `OAMT` | Compensation / original amount | rawPayload only |

**State machine** (lexical scanner producing token pairs `(tag, content)`):

```
state := START
buffer := ''
current_tag := null
for each line in mt940_file:
    if line matches /^:(\d{2}[A-Z]?):(.*)$/:
        # flush previous tag
        if current_tag is not null:
            yield (current_tag, buffer.rstrip('\r\n'))
        current_tag := matched_tag
        buffer := matched_content
    else if line in ('-', '-\r', ''):
        # end-of-message marker
        if current_tag is not null:
            yield (current_tag, buffer.rstrip('\r\n'))
        current_tag := null
        buffer := ''
    else:
        # continuation line — append to previous tag's buffer with newline preserved
        buffer .= "\n" . line
flush remaining
```

Critical edge cases:

1. **`:86:` spans up to 6 continuation lines, each up to 65 chars.** Continuation rule: any line that does NOT start with `:\d{2}[A-Z]?:` is appended to the buffer of the current tag. **Do NOT strip the embedded newline** before scanning `?NN` subfield codes — `?20` can start at the very beginning of line 2, and the line break IS the subfield delimiter when no explicit `?NN` is present mid-field.
2. **CR/LF handling.** ASN files are typically `\r\n`-terminated. The scanner MUST strip `\r` at line-split time but preserve `\n` as the in-buffer continuation marker.
3. **End-of-message marker** is a literal `-` on its own line (sometimes followed by `\r\n`). Some ASN exports omit it; the parser MUST flush the last tag at EOF if no `-` marker is seen.
4. **`{1:F01ASNBNL21XXXX...}` SWIFT block-1 prefix.** Some MT940 files (rarely from ASN's online banking, more common from corporate MT940 over MT/SWIFT network) start with `{1:F01...}{2:O940...}{4:` envelope before the `:20:`. The adapter MUST tolerate (and skip) this envelope: detect `{` as first non-blank char, skip until `{4:`, then scan tags normally; ignore trailing `-}` after the last tag.
5. **Multi-statement files.** Some MT940 exports concatenate multiple statements (`:20:` repeats). For Phase 2's ASN use-case, treat each `:20:`→`:62F:` block as a separate statement-summary record, and yield all statement lines in stream order. The first `:20:` block's metadata populates `statement_summaries` for the import_run if the user uploaded a single-statement file; for multi-statement files, store the FIRST statement's data and flag in `rawPayload`.

## File-Format Signatures

`HeaderSniffer` already strips a leading UTF-8 BOM and reads the first 8 KB. Extend the `match()` dispatch in `HeaderSniffer::sniff()` to recognise the two new formats:

### CAMT.053 signature

```php
private function sniffAsnCamt053(string $path, string $head): SniffResult
{
    if (preg_match('/\.xml$/i', $path) !== 1) {
        throw new SniffMismatchException(
            "That file doesn't look like an XML file. Drop in the ASN CAMT.053 XML export."
        );
    }

    // Tolerate leading XML declaration + optional comments / whitespace before
    // <Document ...>. Match the ISO 20022 CAMT.053 family namespace.
    if (preg_match('#xmlns(?::\w+)?\s*=\s*"urn:iso:std:iso:20022:tech:xsd:camt\.053\.001\.(\d{2})"#', $head, $m) !== 1) {
        throw new SniffMismatchException(
            "This XML file does not declare an ISO 20022 CAMT.053 namespace. If ASN changed their export, file an issue."
        );
    }

    // Optional: capture the sub-version (02/03/04/08) into SniffResult so the
    // wizard can render it.
    return new SniffResult(
        format: 'asn-camt053',
        delimiter: '',                  // N/A for XML
        hasHeader: false,
        encoding: 'UTF-8',              // CAMT.053 is always UTF-8 per ISO 20022
        columnCount: 0,
    );
}
```

Edge cases tested for:
- UTF-8 BOM at start (already stripped by `HeaderSniffer`)
- Leading XML declaration `<?xml version="1.0" encoding="UTF-8"?>` — allowed, namespace can appear up to ~1 KB into the file
- Leading XML comments before `<Document>` — tolerated by the regex (no anchoring to start-of-string)
- Wrong CAMT family (e.g. camt.052 / camt.054) — rejected by the explicit `053.001.\d{2}` match

### MT940 signature

```php
private function sniffAsnMt940(string $path, string $head): SniffResult
{
    if (preg_match('/\.(sta|mt940|940|txt)$/i', $path) !== 1) {
        throw new SniffMismatchException(
            "That file doesn't look like an MT940 export. Drop in the ASN MT940 file (.sta / .mt940 / .txt)."
        );
    }

    // Strip optional SWIFT envelope blocks 1/2/3 before scanning for :20:.
    $body = $this->stripSwiftEnvelope($head);

    // First non-blank line must be a :20: tag.
    if (preg_match('/(?:^|[\r\n])\s*:20:/', $body) !== 1) {
        throw new SniffMismatchException(
            "This file does not look like MT940 (no :20: tag at the start). If ASN changed their export, file an issue."
        );
    }

    return new SniffResult(
        format: 'asn-mt940',
        delimiter: '',
        hasHeader: false,
        encoding: 'UTF-8',              // ASN online-banking MT940 is UTF-8
        columnCount: 0,
    );
}

private function stripSwiftEnvelope(string $head): string
{
    // {1:F01...}{2:O940...}{3:...}{4: ... -} — keep only block-4 contents.
    if (preg_match('/\{4:\s*([\s\S]+?)-\}/', $head, $m) === 1) {
        return $m[1];
    }
    return $head;
}
```

Edge cases:
- Files starting with the SWIFT block envelope `{1:F01...}` — the envelope is stripped before searching for `:20:`
- BOM-prefixed UTF-8 — already handled by `HeaderSniffer`'s existing BOM strip
- Windows line endings (`\r\n`) — the regex tolerates both

**Add to `HeaderSniffer::sniff()`'s `match()` dispatch:**

```php
return match ($declaredFormat) {
    AsnCsvHeaderProfile::FORMAT      => $this->sniffAsnCsv($localPath, $head),
    AsnCamt053HeaderProfile::FORMAT  => $this->sniffAsnCamt053($localPath, $head),
    AsnMt940HeaderProfile::FORMAT    => $this->sniffAsnMt940($localPath, $head),
    default => throw new SniffMismatchException(...),
};
```

Each format's stable string constant lives on its own `*HeaderProfile` class for symmetry with the existing `AsnCsvHeaderProfile`.

## Adapter Architecture

### File / class layout (Phase 2 additions only)

```
Modules/Ingestion/
├── Internal/
│   └── Adapters/
│       └── Asn/
│           ├── AsnAmountParser.php              (existing — reused by both new adapters)
│           ├── AsnCsvAdapter.php                (existing — unchanged)
│           ├── AsnCsvColumnMap.php              (existing)
│           ├── AsnCsvHeaderProfile.php          (existing)
│           ├── AsnCamt053Adapter.php            ← NEW
│           ├── AsnCamt053HeaderProfile.php      ← NEW (constants only)
│           ├── AsnMt940Adapter.php              ← NEW
│           ├── AsnMt940HeaderProfile.php        ← NEW
│           ├── AsnMt940Lexer.php                ← NEW (the tag-stream tokenizer)
│           ├── AsnMt940Tag61Parser.php          ← NEW (parses one :61: line)
│           ├── AsnMt940Tag86Parser.php          ← NEW (parses one :86: structured field)
│           └── AsnMt940CounterpartyCleaner.php  ← NEW (D-27 pre-normalisation)
├── Public/
│   └── Services/
│       └── HeaderSniffer.php                    ← MODIFIED (two new sniff methods + match arms)
└── Providers/
    └── IngestionServiceProvider.php             ← MODIFIED (two new registry entries)
```

### `AsnCamt053Adapter` skeleton

```php
namespace Modules\Ingestion\Internal\Adapters\Asn;

use Generator;
use Genkgo\Camt\Config;
use Genkgo\Camt\Reader;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;

final class AsnCamt053Adapter implements SourceAdapter
{
    public function __construct(private readonly HeaderSniffer $sniffer) {}

    public function format(): string
    {
        return AsnCamt053HeaderProfile::FORMAT;  // 'asn-camt053'
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, AsnCamt053HeaderProfile::FORMAT);

        $message = (new Reader(Config::getDefault()))->readFile($localPath);

        $index = 0;
        foreach ($message->getRecords() as $statement) {
            $ownIban = $this->extractOwnIban($statement->getAccount());
            $accounts->resolve($ownIban);  // branch point for unknown-account wizard prompt

            foreach ($statement->getEntries() as $entry) {
                $txDtlsList = $entry->getTransactionDetails();
                $isBatch = count($txDtlsList) > 1;

                if ($txDtlsList === []) {
                    // Rare: an Ntry with no NtryDtls/TxDtls — fall back to entry-level data
                    yield $this->buildDto($entry, null, $ownIban, $index++, isBatch: false);
                    continue;
                }

                foreach ($txDtlsList as $txDtls) {
                    yield $this->buildDto($entry, $txDtls, $ownIban, $index++, isBatch: $isBatch);
                }
            }
        }
    }

    private function buildDto(
        \Genkgo\Camt\DTO\Entry $entry,
        ?\Genkgo\Camt\DTO\EntryTransactionDetail $txDtls,
        string $ownIban,
        int $rowIndex,
        bool $isBatch,
    ): SourceTransactionDto {
        // Amount: per-TxDtls if batch, else entry-level
        $money = $isBatch && $txDtls !== null && $txDtls->getAmountDetails() !== null
            ? $txDtls->getAmountDetails()
            : $entry->getAmount();
        $minor = (int) $money->getAmount();                      // moneyphp/money returns minor as string
        $currency = $money->getCurrency()->getCode();

        // Sign from credit/debit indicator
        $cdi = $txDtls?->getCreditDebitIndicator() ?? $entry->getCreditDebitIndicator();
        $signed = $cdi === 'DBIT' ? -$minor : $minor;            // CRDT = positive

        // EndToEndId — D-23 anchor
        $endToEndId = $txDtls?->getReference()?->getEndToEndId();
        $sourceRef = ($endToEndId !== null && $endToEndId !== '' && $endToEndId !== 'NOTPROVIDED')
            ? $endToEndId
            : null;

        // Counterparty — pick the party opposite the debit/credit direction
        $counterparty = $this->extractCounterparty($txDtls, $cdi);

        // Build the rawPayload['sepa'] sub-array per D-24
        $rawPayload = $this->serialiseSepaFragment($entry, $txDtls);

        $booking = $entry->getBookingDate() ?? $entry->getValueDate() ?? new \DateTimeImmutable();
        $value   = $entry->getValueDate()   ?? $booking;

        return new SourceTransactionDto(
            bookedAt: \Carbon\CarbonImmutable::instance($booking),
            postedAt: \Carbon\CarbonImmutable::instance($booking),
            valueDate: \Carbon\CarbonImmutable::instance($value),
            ownIban: $ownIban,
            counterpartyIban: $counterparty['iban'],
            counterpartyName: $counterparty['name'],
            currency: $currency,
            amountMinor: $signed,
            sourceRef: $sourceRef,
            description: $this->extractRemittance($txDtls),
            rawPayload: $rawPayload,
            sourceRowIndex: $rowIndex,
        );
    }

    // extractOwnIban / extractCounterparty / extractRemittance / serialiseSepaFragment
    // are all small private helpers — see §"rawPayload['sepa'] shape" below.
}
```

### `rawPayload['sepa']` shape (D-24)

The CAMT adapter's `rawPayload` is keyed differently from the CSV adapter's `array<int,string>` row-cell layout. Use a single `sepa` sub-key:

```php
[
    'sepa' => [
        // Entry-level identifiers
        'msgId'                       => $message->getGroupHeader()?->getMessageId(),
        'acctSvcrRef'                 => $entry->getAccountServicerReference(),
        'entryRef'                    => $entry->getReference(),
        'batchPaymentId'              => $entry->getBatchPaymentId(),
        'btc' => [
            'domain'  => $entry->getBankTransactionCode()?->getDomainCode(),
            'family'  => $entry->getBankTransactionCode()?->getFamilyCode(),
            'subFamily' => $entry->getBankTransactionCode()?->getSubFamilyCode(),
            'proprietary' => $entry->getBankTransactionCode()?->getProprietaryCode(),
        ],
        // TxDtls-level identifiers (the chain-resolution row)
        'endToEndId'                  => $txDtls?->getReference()?->getEndToEndId(),
        'instrId'                     => $txDtls?->getReference()?->getInstructionId(),
        'txId'                        => $txDtls?->getReference()?->getTransactionId(),
        'mandateId'                   => $txDtls?->getReference()?->getMandateId(),
        'pmtInfId'                    => $txDtls?->getReference()?->getPaymentInformationId(),
        'creditDebitIndicator'        => $txDtls?->getCreditDebitIndicator(),
        // Remittance + addtl info
        'remittanceUnstructured'      => $this->extractUnstructuredRemittance($txDtls),
        'remittanceStructured'        => $this->extractStructuredRemittance($txDtls),
        'addtlTxInf'                  => $txDtls?->getAdditionalTransactionInformation()?->getMessage(),
    ],
]
```

Phase 5's chain resolver re-reads this sub-array directly off the persisted DTO; nothing else in the pipeline cares about its contents. Schema unchanged — `rawPayload` is already a JSON-castable array on `SourceTransactionDto`.

### `AsnMt940Adapter` skeleton

```php
namespace Modules\Ingestion\Internal\Adapters\Asn;

use Generator;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\HeaderSniffer;

final class AsnMt940Adapter implements SourceAdapter
{
    public function __construct(
        private readonly AsnAmountParser $amounts,            // reused from Phase 1
        private readonly AsnMt940Lexer $lexer,
        private readonly AsnMt940Tag61Parser $tag61,
        private readonly AsnMt940Tag86Parser $tag86,
        private readonly AsnMt940CounterpartyCleaner $counterpartyCleaner,
        private readonly HeaderSniffer $sniffer,
    ) {}

    public function format(): string
    {
        return AsnMt940HeaderProfile::FORMAT;  // 'asn-mt940'
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, AsnMt940HeaderProfile::FORMAT);

        $ownIban = null;
        $pendingTag61 = null;
        $rowIndex = 0;

        foreach ($this->lexer->tokenize($localPath) as [$tag, $content]) {
            switch ($tag) {
                case '20':
                    // Statement-level reference — captured for statement_summaries
                    break;
                case '25':
                    $ownIban = trim($content);
                    $accounts->resolve($ownIban);  // branch point for unknown-account
                    break;
                case '28C':
                    // Statement number + page — captured for statement_summaries
                    break;
                case '60F': case '60M':
                case '62F': case '62M':
                case '64':
                    // Captured for statement_summaries (Phase 2 D-30)
                    break;
                case '61':
                    if ($pendingTag61 !== null) {
                        // Previous :61: had no :86: — yield with empty narrative
                        yield $this->buildDto($pendingTag61, null, $ownIban, $rowIndex++);
                    }
                    $pendingTag61 = $this->tag61->parse($content);
                    break;
                case '86':
                    if ($pendingTag61 !== null) {
                        $narrative = $this->tag86->parse($content);
                        yield $this->buildDto($pendingTag61, $narrative, $ownIban, $rowIndex++);
                        $pendingTag61 = null;
                    }
                    break;
            }
        }

        // Flush trailing :61: with no :86:
        if ($pendingTag61 !== null) {
            yield $this->buildDto($pendingTag61, null, $ownIban, $rowIndex++);
        }
    }

    // buildDto applies AsnMt940CounterpartyCleaner (D-27) to the counterparty name
    // and uses AsnAmountParser for the amount (Phase 1 Pitfall 1 reuse).
}
```

The lexer yields raw `(tag, content)` pairs in stream order; the adapter then maintains a tiny state machine that pairs each `:61:` with the optional `:86:` that immediately follows. The MT940 file is line-oriented and fits in memory for ASN's typical export size (a year of statements is ~200 KB), but the lexer should still be a `Generator` over `fopen()`-streamed lines so multi-year imports do not load the whole file.

### Pattern alignment with Phase 1

| Phase 1 pattern | Phase 2 application |
|-----------------|--------------------|
| Adapter is a `Generator` (D-05, `SourceAdapter::parse(): Generator`) | Both new adapters yield row-by-row |
| `AsnAmountParser` for integer-only minor units | MT940 reuses it (after `,` → `.`); CAMT bypasses (moneyphp/money already returns minor as string — `(int) $money->getAmount()`) |
| `HeaderSniffer` extended through a `match()` arm | Two new arms added |
| `*HeaderProfile` constant carrier | `AsnCamt053HeaderProfile` + `AsnMt940HeaderProfile` constants |
| `IngestionServiceProvider::register` lazy registry | Two new entries via `$app->make(...)` |
| `AccountResolver` consulted in `parse()` so wizard can branch | Both adapters call `$accounts->resolve($ownIban)` once per statement (CAMT — `<Stmt><Acct>`; MT940 — `:25:`) |
| Lazy generator → never materialise file in memory | CAMT is eager at the library boundary but the adapter still yields rows; MT940 lexer streams lines |
| Failures in one row → `InvalidAmountException` caught by ImportPipeline → ERROR PreviewRowDto | Both adapters throw on per-row failure; pipeline already converts to ERROR row |

## Pipeline Integration

### Where ENRICHMENT slots in

The Phase 1 `ImportPipeline::preview()` loop classifies each canonical row as `new` or `duplicate` via `FingerprintStage::isExistingFingerprint`. Phase 2 extends this into a four-way classification (`new` / `duplicate` / `enriched` / `error`). **Recommended placement: extend `FingerprintStage` with a new method `classify()` that returns a richer disposition rather than the bool**; do NOT introduce a new pipeline stage. The pipeline shape is already adapter-agnostic and a new stage would add ceremony without changing the data flow.

**Proposed `FingerprintStage` shape:**

```php
final class FingerprintStage
{
    public function classify(CanonicalTransaction $tx, User $user): FingerprintDisposition
    {
        $fingerprint = $this->fingerprints->compose($tx);
        $existing = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->first(['id', 'source_ref', 'source_format', 'enriched_from']);

        if ($existing === null) {
            return FingerprintDisposition::newRow();
        }

        $incoming = $tx->sourceRef;
        $current  = $existing->source_ref;

        if ($this->isStronger($incoming, $current, $tx->sourceFormat, $existing->source_format)) {
            return FingerprintDisposition::enriched(
                existingId: (int) $existing->id,
                fromSourceRef: $current,
                toSourceRef: $incoming,
            );
        }

        return FingerprintDisposition::duplicate();
    }

    private function isStronger(?string $incoming, ?string $current, string $incomingFormat, string $currentFormat): bool
    {
        if ($incoming === null || $incoming === '') return false;
        if ($current === null || $current === '')   return true;
        // Within non-null, the canonical order is:
        // EndToEndId (asn-camt053) > AcctSvcrRef > InstrId > MT940 ref (asn-mt940) > CSV ref (asn-csv)
        return $this->refRank($incoming, $incomingFormat) > $this->refRank($current, $currentFormat);
    }

    private function refRank(string $ref, string $format): int
    {
        return match ($format) {
            'asn-camt053' => 4,   // EndToEndId is the strongest
            'asn-mt940'   => 2,
            'asn-csv'     => 1,
            default       => 0,
        };
    }
}
```

`FingerprintDisposition` is a small DTO (Spatie\LaravelData\Data) with three named-constructor variants: `newRow()`, `duplicate()`, `enriched(int $existingId, ?string $fromSourceRef, string $toSourceRef)`.

### ImportPipeline::preview() changes

```php
$disposition = $this->fingerprint->classify($normalized, $user);

$preview[] = new PreviewRowDto(
    rowIndex: $source->sourceRowIndex,
    status: $disposition->status(),       // 'new' | 'duplicate' | 'enriched' | 'error'
    accountId: $accountId,
    bookedAt: $source->bookedAt->format('d-m-Y'),
    counterpartyName: $source->counterpartyName,
    categoryName: null,
    amountMinor: $source->amountMinor,
    currency: $source->currency,
    error: null,
    // NEW fields for the ENRICHED diff indicator (D-28b):
    diff: $disposition->isEnriched()
        ? ['source_ref' => ['from' => $disposition->fromSourceRef(), 'to' => $disposition->toSourceRef()]]
        : null,
);

if ($disposition->isNew()) {
    $canonical[] = $normalized;
} elseif ($disposition->isEnriched()) {
    $enrichments[] = new PendingEnrichment(
        existingTransactionId: $disposition->existingId(),
        newSourceRef: $disposition->toSourceRef(),
        importRunId: $importRunId,
        sourceFormat: $source->sourceFormat,
    );
}
// duplicate: discard (no canonical, no enrichment)
```

The pipeline's return-tuple grows to a 4-key array:

```php
return [
    'rows'         => $preview,
    'canonical'    => $canonical,
    'enrichments'  => $enrichments,       // NEW
    'unknownIbans' => array_values($unknownIbans),
];
```

### ConfirmImport changes

`ConfirmImport::__invoke` already replays `$canonical` through `RecordsTransactions`. Phase 2 adds: replay the cached `$enrichments` through a new `Ledger\Public\Contracts\AppliesEnrichments` action that runs `UPDATE transactions SET source_ref = ?, enriched_from = json(...)` per row inside the same DB transaction as the canonical insert. The PreviewCache shape grows from 2 keys to 3 (`preview`, `canonical`, `enrichments`).

`ImportConfirmResult` grows a new `enriched: int` field; the results summary blade prints `"N imported · M skipped · P enriched · K errors"` only when `$enriched > 0`.

### Backwards compatibility with Phase 1's existing `FingerprintStage::isExistingFingerprint`

The Phase 1 method is called from one place (`ImportPipeline::preview`). Phase 2 replaces the call site with `classify()`. **Keep `isExistingFingerprint` for one-version transition** if any test references it directly; mark it deprecated and remove in Phase 3 cleanup. Larastan strict will complain about the unused method — that's the planner's call.

## Fingerprint v3 Migration

### Strategy

**Single migration, single transaction, batched UPDATE, abort-on-collision.** Reasoning per SQLite WAL constraint:

- Project explicitly retains full history forever — but realistic scale at end of Phase 2 is single-user, a single account, on the order of 200–500 rows for the entire fixture corpus. The migration runs on the user's own machine. There is no fleet-wide rollout.
- A single transaction wrapping the entire re-derive keeps writes atomic — if collision is detected mid-walk, `DB::rollBack()` leaves the table on the v2 fingerprints with NO partial state.
- WAL mode handles single-writer fine. No checkpointing concerns at this row count.
- Chunked transactions are only needed when row count crosses ~50k AND the migration would block reads — neither applies here.

**Recommended pattern: dedicated `php artisan` command invoked from the migration**, NOT raw SQL in `up()`. Reasoning:

1. The migration's `up()` runs inside Laravel's migration runner; pulling in `FingerprintComposer` and `CanonicalTransaction` from the Ledger module from a migration file is awkward (migrations live at module-level path, not under namespaces).
2. A standalone artisan command (`php artisan diederik:rederive-fingerprints --dry-run --confirm`) can be run by the user **before** the migration to pre-flight, then again by the migration itself with `--confirm`.
3. The command can output progress (`123/229 rows`) which a raw migration cannot.

**Migration calls the artisan command via the Console Kernel injected into the migration class** — there's no Laravel facility for migrations to invoke artisan commands directly, but the migration `up()` can resolve `Illuminate\Contracts\Console\Kernel` from the container and call `->call('diederik:rederive-fingerprints', ['--confirm' => true])`. The migration `down()` is intentionally a no-op (do not revert the v3 hash to v2 — that's "fingerprint regression" and is destructive). Re-running `up()` is idempotent because the command first checks the existing `normalization_version` column and skips already-v3 rows.

### Collision pre-check

```php
final class RederiveFingerprintsCommand extends Command
{
    protected $signature = 'diederik:rederive-fingerprints {--confirm} {--dry-run}';

    public function handle(
        FingerprintComposer $fingerprints,
        DatabaseManager $db,
    ): int {
        $connection = $db->connection();

        // Phase 1: dry-run all rows, collect new fingerprints in memory, detect collisions.
        $seen = [];           // user_id . '|' . newFingerprint => Transaction id
        $updates = [];        // id => newFingerprint
        $collisions = [];

        $rows = $connection->table('transactions')
            ->select(['id', 'user_id', 'account_id', 'posted_at', 'booked_at', 'amount_minor',
                      'currency', 'counterparty_normalized', 'source_ref', 'normalization_version'])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ((int) $row->normalization_version >= 3) continue;       // already v3 — skip

            $tx = $this->buildCanonicalFromRow($row);
            $newFp = $fingerprints->compose($tx);                       // v3 tuple
            $key = $row->user_id . '|' . $newFp;

            if (isset($seen[$key])) {
                $collisions[] = [
                    'existing_id' => $seen[$key],
                    'colliding_id' => $row->id,
                    'fingerprint' => $newFp,
                ];
                continue;
            }
            $seen[$key] = $row->id;
            $updates[$row->id] = $newFp;
        }

        if ($collisions !== []) {
            $this->error(sprintf(
                "Fingerprint v3 migration ABORTED. %d collision(s) detected:\n%s",
                count($collisions),
                json_encode($collisions, JSON_PRETTY_PRINT),
            ));
            $this->error("Existing v2 rows left intact. Manual reconciliation required before re-running.");
            return self::FAILURE;
        }

        if ($this->option('dry-run') || ! $this->option('confirm')) {
            $this->info(sprintf("Dry-run OK. %d rows would be re-derived to v3.", count($updates)));
            return self::SUCCESS;
        }

        // Phase 2: apply updates inside one transaction.
        $connection->transaction(function () use ($connection, $updates) {
            foreach ($updates as $id => $newFp) {
                $connection->table('transactions')
                    ->where('id', $id)
                    ->update([
                        'fingerprint' => $newFp,
                        'normalization_version' => 3,
                    ]);
            }
        });

        $this->info(sprintf("Re-derived %d rows to v3.", count($updates)));
        return self::SUCCESS;
    }
}
```

### Why this is single-pass (no N+1)

The dry-run loop computes new fingerprints in memory using `FingerprintComposer::compose()` (pure CPU — no DB calls). Collision detection is an O(1) hash-map lookup per row. Total cost: one `SELECT` over `transactions` + one transaction wrapping N `UPDATE`s. At 500 rows, well under 100 ms on SQLite WAL.

### Migration ordering with `enriched_from` schema add

Two ordering options:

**Option A — re-derive first, then add column** (RECOMMENDED):
1. Migration `2026_06_01_010001_rederive_fingerprints_to_v3.php` — calls the artisan command
2. Migration `2026_06_01_010002_add_enriched_from_to_transactions.php` — `$table->json('enriched_from')->nullable()->after('source_ref')`
3. Migration `2026_06_01_010003_create_statement_summaries_table.php`
4. Migration `2026_06_01_010004_bump_normalization_version_constant_in_code.php` — no-op DDL (the constant lives in code, not the DB); existence of this migration signals "the codebase now operates on v3"

**Option B — same migration handles both** (NOT recommended — couples concerns; harder to roll back, harder to test).

`enriched_from` JSON column on SQLite: Laravel's `$table->json('enriched_from')->nullable()` lands as `TEXT` under the hood on SQLite ≤ 3.37 and as native JSON1 on ≥ 3.38. Laravel 12+'s `'use_native_json' => true` connection flag activates native JSON when available. Either way, Eloquent's `'enriched_from' => AsCollection::class` cast works transparently — `AsCollection` reads/writes the JSON-encoded string and exposes a `Collection`. Atomic-append-on-update can be done by reading-then-writing inside the same DB transaction (`SELECT enriched_from FOR UPDATE` semantics don't exist in SQLite but the single-writer model means the same effect is achieved without explicit locking).

**Recommended default:** `enriched_from = null` initially, populated to `[]` then appended-to on first enrichment. The initial-create entry is appended too, so every row that has ever been enriched carries the full provenance trail.

## Schema Changes

### `enriched_from` column on `transactions`

```php
Schema::table('transactions', function (Blueprint $table): void {
    $table->json('enriched_from')->nullable()->after('source_ref');
});
```

The column stores `?array<int,array{format:string,ran_at:string,import_run_id:int,added:list<string>}>`.

Example value after one CSV create + one CAMT enrichment:

```json
[
  {
    "format": "asn-csv",
    "ran_at": "2026-04-12T10:14:33+02:00",
    "import_run_id": 42,
    "added": ["initial_create"]
  },
  {
    "format": "asn-camt053",
    "ran_at": "2026-05-13T08:02:11+02:00",
    "import_run_id": 51,
    "added": ["source_ref"]
  }
]
```

Eloquent cast on `Transaction` model:

```php
protected function casts(): array
{
    return [
        ...,
        'enriched_from' => AsArrayObject::class,    // or AsCollection::class — pick consistent with rest of project
    ];
}
```

Use `AsArrayObject` if mutating-in-place is desired (`$tx->enriched_from[] = [...]; $tx->save();`); use `AsCollection` if Collection methods (`->push`, `->filter`) are preferred. Phase 1 has no existing JSON-cast precedent — Claude's discretion.

### `statement_summaries` table

```php
Schema::create('statement_summaries', static function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();
    $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
    $table->string('iban_owner', 34);                         // own IBAN as it appears in the statement
    $table->string('statement_number', 32)->nullable();       // CAMT: ElectronicSequenceNumber; MT940: :28C:
    $table->dateTime('period_start')->nullable();             // CAMT: getFromDate; MT940: derived from :60F:
    $table->dateTime('period_end')->nullable();               // CAMT: getToDate; MT940: derived from :62F:
    $table->bigInteger('opening_balance_minor')->nullable();
    $table->char('opening_balance_currency', 3)->nullable();
    $table->dateTime('opening_balance_date')->nullable();
    $table->bigInteger('closing_balance_minor')->nullable();
    $table->char('closing_balance_currency', 3)->nullable();
    $table->dateTime('closing_balance_date')->nullable();
    $table->unsignedInteger('entry_count')->default(0);       // number of statement-line entries the parser produced
    $table->json('extras')->nullable();                       // sub-version-specific metadata (CAMT MsgId, MT940 :20:, etc.)
    $table->timestamps();

    $table->unique(['user_id', 'import_run_id']);             // 1 statement-summary per import_run when present
});
```

Phase 1's `import_runs` (verified at `Modules/Ledger/Database/Migrations/2026_05_12_010004_create_import_runs_table.php`) has: `id`, `user_id`, `source_format`, `raw_file_path`, `sha256`, `uploaded_at`, `confirmed_at`, `inserted_count`, `duplicate_count`, `error_count`, `status`, timestamps. A nullable FK from `statement_summaries.import_run_id` to `import_runs.id` is the right shape — CSV imports leave the row absent (no FK violation; just no row).

The `extras` JSON holds version-specific metadata that doesn't deserve a column: CAMT's `MsgId`, the four CAMT.053 sub-version (`001.02`/`.03`/`.04`/`.08`), MT940's `:20:` file reference, and any multi-statement-file flag. Bounded set; planner picks the exact keys.

## Wizard Surface Changes

### Source-format dropdown — `UploadWizard` Livewire component

Change at `Modules/Import/Internal/Http/Livewire/UploadWizard.php`:

```php
// Phase 1:
public string $sourceFormat = 'asn-csv';

public function rules(): array
{
    return [
        'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        'sourceFormat' => ['required', 'in:asn-csv'],
    ];
}

// Phase 2:
public string $sourceFormat = 'asn-csv';        // default unchanged

public function rules(): array
{
    return [
        'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xml,sta,mt940,940'],   // ADD xml/sta/mt940/940
        'sourceFormat' => ['required', 'in:asn-csv,asn-camt053,asn-mt940'],               // ADD two values
    ];
}
```

Blade view change (`Modules/Import/Resources/views/livewire/upload-wizard.blade.php`): the existing dropdown control gets two `<option>` rows for `asn-camt053` ("ASN CAMT.053 (XML)") and `asn-mt940` ("ASN MT940"). Flux UI's `<flux:select>` (or the project's current plain `<select>` per Phase 1 implementation) keeps the same shape.

`UploadWizard::messages()` adds:

```php
'file.mimes' => "That file doesn't look like an ASN export. Drop in the CSV, MT940 (.sta / .mt940 / .txt), or CAMT.053 XML you downloaded from the ASN portal.",
```

### ENRICHED preview state — `PreviewRowDto` + Blade

`Modules/Import/Public/Dto/PreviewRowDto.php` adds a `diff` field:

```php
final class PreviewRowDto extends Data
{
    public function __construct(
        public readonly int $rowIndex,
        /** 'new' | 'duplicate' | 'error' | 'enriched' */                       // ★ extended
        public readonly string $status,
        public readonly ?int $accountId,
        public readonly ?string $bookedAt,
        public readonly ?string $counterpartyName,
        public readonly ?string $categoryName,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?string $error,
        /** @var array<string, array{from: ?string, to: string}>|null  */
        public readonly ?array $diff = null,                                    // ★ NEW
    ) {}
}
```

Blade addition (`Modules/Import/Resources/views/livewire/preview-wizard.blade.php`) — extra `@elseif` arm in the existing status switch:

```blade
@elseif ($row->status === 'enriched')
    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20"
          title="Existing row will be updated with a stronger source reference.">Enriched</span>
    @if ($row->diff && isset($row->diff['source_ref']))
        <div class="mt-1 text-xs text-slate-500 font-mono">
            source_ref:
            <span class="text-slate-400">{{ $row->diff['source_ref']['from'] ?? '∅' }}</span>
            →
            <span class="text-sky-700">{{ $row->diff['source_ref']['to'] }}</span>
        </div>
    @endif
```

Sky-50/sky-700 sits between emerald (new) and amber (duplicate) on the existing palette and matches the "calm, content-first" UI-05 directive without inventing a new colour family.

### Results summary

`Modules/Import/Resources/views/livewire/import-results.blade.php` adds the enriched count:

```blade
Imported {{ $importRun->inserted_count }} transactions
· skipped {{ $importRun->duplicate_count }} duplicates
@if ($importRun->enriched_count > 0) · {{ $importRun->enriched_count }} enriched @endif
@if ($importRun->error_count > 0) · {{ $importRun->error_count }} errors @endif.
```

Requires a new `enriched_count` column on `import_runs` (migration: `$table->unsignedInteger('enriched_count')->default(0)->after('duplicate_count')`) and `ImportConfirmResult` to surface it. `ConfirmImport` writes both the recorder result AND the cached enrichment count.

### `SourceAdapterRegistry` wiring

`Modules/Ingestion/Providers/IngestionServiceProvider.php`:

```php
$this->app->singleton(
    SourceAdapterRegistry::class,
    static fn (Container $app): SourceAdapterRegistry => new SourceAdapterRegistry([
        'asn-csv'      => $app->make(AsnCsvAdapter::class),
        'asn-camt053'  => $app->make(AsnCamt053Adapter::class),     // ★ NEW
        'asn-mt940'    => $app->make(AsnMt940Adapter::class),       // ★ NEW
    ]),
);
```

That's the only public-API change. The registry itself doesn't need modification.

## Test Strategy

### Phase 1's pattern (reuse exactly)

- Per-module `tests/Unit` and `tests/Feature` folders.
- Real anonymised fixtures committed under `tests/fixtures/` at the repo root (NOT inside a module). Phase 1 committed `asn-sample-1.csv` + the audit Markdown there.
- Snapshot tests via `spatie/pest-plugin-snapshots`. Phase 1's snapshot lives under `tests/.pest/snapshots/Modules/Ingestion/tests/Unit/AsnCsvAdapterTest/`. Pest 4's native `toMatchSnapshot()` writes to `tests/.pest/snapshots/` and the file IS committed to the repo.
- `IdempotencyContractTest` (`tests/Contracts/IdempotencyContractTest.php`) uses a Pest **dataset** — each new adapter adds one row per scenario to the dataset rather than re-implementing the test body. Phase 2 adds 4 new dataset rows (CAMT same-file ×2 scenarios + MT940 same-file ×2 scenarios).

### Fixture corpus needed from the user

The planner MUST surface this to the user before plan execution begins. Without real ASN exports, the adapters cannot be empirically validated and the snapshot tests will hang on `[ASSUMED]` data.

| Fixture | Purpose | Source |
|---------|---------|--------|
| `tests/fixtures/asn-camt053-sample-1.xml` | Single-statement CAMT.053 V08 (most common 2026 export sub-version) — anonymised but structurally real | User downloads from ASN portal |
| `tests/fixtures/asn-camt053-v02-sample.xml` | Older sub-version coverage (optional but recommended) | Older statement download |
| `tests/fixtures/asn-mt940-sample-1.sta` | Single-statement MT940 with multiple `:61:`/`:86:` pairs, including at least one SEPA direct debit (GVC code `005`) and one bank transfer | User downloads from ASN portal |
| `tests/fixtures/asn-month-a.camt053.xml` + `asn-month-a-and-b.camt053.xml` | CAMT-vs-CSV overlap scenarios mirroring the existing CSV fixture pair | Derived from `asn-month-a.csv` period |
| `tests/fixtures/asn-cross-format/february.csv` + `february.camt053.xml` | The cross-format dedup acceptance test | Same period, both formats |
| `tests/fixtures/asn-camt053-sample-1.md` | Audit Markdown documenting anonymisation protocol + the structural notes (mirroring `asn-sample-1.md`) | Hand-written from the fixture |

Anonymisation protocol mirrors `asn-sample-1.md`: replace counterparty names with public-domain merchant names, replace counterparty IBANs with synthetic-but-checksum-valid IBANs, replace own IBAN with a single fixed test IBAN. Statement balances, dates, and amounts can stay real — they have no PII content.

### Test files Phase 2 introduces

```
Modules/Ingestion/tests/Unit/
├── AsnCamt053AdapterTest.php              ← snapshot + structural assertions
├── AsnMt940LexerTest.php                  ← tag-stream tokenisation
├── AsnMt940Tag61ParserTest.php            ← `:61:` ASN-variant regex + amount sign
├── AsnMt940Tag86ParserTest.php            ← `?NN` subfield extraction + GVC keyword scan
├── AsnMt940CounterpartyCleanerTest.php    ← D-27 pre-normalisation
└── AsnMt940AdapterTest.php                ← snapshot + structural assertions

Modules/Ingestion/tests/Feature/
└── HeaderSnifferTest.php                  ← MODIFIED: add CAMT + MT940 sniff cases

Modules/Ledger/tests/Unit/
├── FingerprintComposerV3Test.php          ← v3 tuple shape + version constant = 3
└── FingerprintRederiveCommandTest.php     ← migration command incl. collision pre-check + abort

Modules/Import/tests/Unit/
├── FingerprintStageClassifyTest.php       ← new disposition: new / duplicate / enriched
└── EnrichmentPersistenceTest.php          ← enriched_from JSON append + UPDATE on stronger ref

Modules/Import/tests/Feature/
├── AsnCamt053ImportTest.php               ← end-to-end: upload → preview → confirm
├── AsnMt940ImportTest.php                 ← end-to-end
├── CrossFormatDedupTest.php               ← CSV then CAMT same period → zero new + N enriched
└── PreviewWizardEnrichedStateTest.php     ← Livewire feature test for ENRICHED badge + diff

tests/Contracts/
└── IdempotencyContractTest.php            ← MODIFIED: add 4 new dataset rows for camt053 + mt940

tests/.pest/snapshots/Modules/Ingestion/tests/Unit/
├── AsnCamt053AdapterTest/                 ← committed snapshot files
└── AsnMt940AdapterTest/                   ← committed snapshot files
```

### Snapshot test pattern (matches Phase 1 exactly)

```php
// Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php
it('matches the snapshot of the parsed fixture (drift detector)', function () {
    $adapter = app(AsnCamt053Adapter::class);
    $resolver = new class implements AccountResolver {
        public function resolve(string $iban): AccountResolution {
            return new KnownAccount(accountId: 1, iban: $iban);
        }
    };

    $dtos = iterator_to_array(
        $adapter->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $resolver)
    );

    $serialized = array_map(static fn (SourceTransactionDto $d) => [
        'bookedAt'        => $d->bookedAt->format('Y-m-d H:i:s'),
        'postedAt'        => $d->postedAt->format('Y-m-d'),
        'valueDate'       => $d->valueDate->format('Y-m-d'),
        'ownIban'         => $d->ownIban,
        'counterpartyIban'=> $d->counterpartyIban,
        'counterpartyName'=> $d->counterpartyName,
        'currency'        => $d->currency,
        'amountMinor'     => $d->amountMinor,
        'sourceRef'       => $d->sourceRef,
        'description'     => $d->description,
        // Intentionally omit rawPayload — snapshot would be huge.
    ], $dtos);

    expect($serialized)->toMatchSnapshot();
});
```

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4 (on PHPUnit 11) |
| Config file | `phpunit.xml` at repo root |
| Quick run command | `vendor/bin/pest Modules/Ingestion/tests Modules/Import/tests Modules/Ledger/tests --stop-on-failure` |
| Full suite command | `vendor/bin/pest` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| ING-02 | CAMT.053 XML upload imports transactions with `EndToEndId` as `source_ref` | feature | `vendor/bin/pest Modules/Import/tests/Feature/AsnCamt053ImportTest.php -x` | ❌ Wave 0 |
| ING-02 | CAMT.053 adapter snapshot matches the anonymised real fixture | unit | `vendor/bin/pest Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php -x` | ❌ Wave 0 |
| ING-02 | `HeaderSniffer` accepts a valid CAMT.053 XML and rejects wrong-format XML / non-XML | unit | `vendor/bin/pest Modules/Ingestion/tests/Feature/HeaderSnifferTest.php -x` | ✅ (modified) |
| ING-02 | `genkgo/camt` namespace coverage — at minimum 001.02 + 001.08 sub-versions parse cleanly | unit | `vendor/bin/pest Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php --filter='sub_version'` | ❌ Wave 0 |
| ING-03 | MT940 lexer produces correct tag stream from real fixture | unit | `vendor/bin/pest Modules/Ingestion/tests/Unit/AsnMt940LexerTest.php -x` | ❌ Wave 0 |
| ING-03 | MT940 `:61:` parser handles ASN's 34-char customer-reference variant | unit | `vendor/bin/pest Modules/Ingestion/tests/Unit/AsnMt940Tag61ParserTest.php -x` | ❌ Wave 0 |
| ING-03 | MT940 `:86:` parser extracts structured `?NN` subfields + GVC codes (EREF / IBAN / SVWZ) | unit | `vendor/bin/pest Modules/Ingestion/tests/Unit/AsnMt940Tag86ParserTest.php -x` | ❌ Wave 0 |
| ING-03 | MT940 multi-line `:86:` (up to 6 × 65 chars) reassembles to a single transaction | unit | `vendor/bin/pest Modules/Ingestion/tests/Unit/AsnMt940LexerTest.php --filter='multi_line_86'` | ❌ Wave 0 |
| ING-03 | MT940 end-to-end import via the pipeline produces correctly-typed rows | feature | `vendor/bin/pest Modules/Import/tests/Feature/AsnMt940ImportTest.php -x` | ❌ Wave 0 |
| ING-06 | Fingerprint v3 tuple shape — drops `source_ref`, includes `booked_at` | unit | `vendor/bin/pest Modules/Ledger/tests/Unit/FingerprintComposerV3Test.php -x` | ❌ Wave 0 |
| ING-06 | Re-derive command detects collisions and aborts | unit | `vendor/bin/pest Modules/Ledger/tests/Unit/FingerprintRederiveCommandTest.php --filter='aborts_on_collision'` | ❌ Wave 0 |
| ING-06 | Re-derive command succeeds when no collisions | unit | `vendor/bin/pest Modules/Ledger/tests/Unit/FingerprintRederiveCommandTest.php --filter='applies_v3_to_all'` | ❌ Wave 0 |
| ING-06 | `FingerprintStage::classify` returns ENRICHED when the existing row has a weaker `source_ref` | unit | `vendor/bin/pest Modules/Import/tests/Unit/FingerprintStageClassifyTest.php -x` | ❌ Wave 0 |
| ING-06 | CSV-then-CAMT same period → zero new rows + N enriched rows | feature | `vendor/bin/pest Modules/Import/tests/Feature/CrossFormatDedupTest.php --filter='csv_then_camt053'` | ❌ Wave 0 |
| ING-06 | MT940-then-CAMT same period → CAMT enriches MT940 rows | feature | `vendor/bin/pest Modules/Import/tests/Feature/CrossFormatDedupTest.php --filter='mt940_then_camt053'` | ❌ Wave 0 |
| ING-06 | CAMT-then-CSV same period → CSV rows are all DUPLICATE (CSV is weaker — no enrichment back) | feature | `vendor/bin/pest Modules/Import/tests/Feature/CrossFormatDedupTest.php --filter='camt053_then_csv'` | ❌ Wave 0 |
| ING-06 | `enriched_from` JSON column carries one initial-create entry + one enrichment entry after the round-trip | unit | `vendor/bin/pest Modules/Import/tests/Unit/EnrichmentPersistenceTest.php -x` | ❌ Wave 0 |
| ING-06 (UI) | Preview wizard renders ENRICHED badge + diff indicator | feature | `vendor/bin/pest Modules/Import/tests/Feature/PreviewWizardEnrichedStateTest.php -x` | ❌ Wave 0 |
| ING-06 (contract) | IdempotencyContractTest passes for 4 new dataset rows (camt053 same-file ×2 + mt940 same-file ×2) | feature | `vendor/bin/pest tests/Contracts/IdempotencyContractTest.php` | ✅ (modified) |
| ING-02/03 (statement coverage) | `statement_summaries` row is created for CAMT + MT940 imports, absent for CSV | unit | `vendor/bin/pest Modules/Ledger/tests/Unit/StatementSummaryWriterTest.php -x` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest Modules/Ingestion/tests Modules/Import/tests Modules/Ledger/tests --stop-on-failure`
- **Per wave merge:** `vendor/bin/pest`
- **Phase gate:** Full suite green BEFORE `/gsd-verify-work`. PHPStan level max + strict-rules + Pint must also pass.

### Wave 0 Gaps

The following test files do not exist yet and must be authored as the first task of the phase. The corresponding fixture files must also land in Wave 0 — without real CAMT/MT940 samples the adapter tests cannot be empirically validated:

- [ ] `tests/fixtures/asn-camt053-sample-1.xml` — REQUIRED from user (anonymised real export)
- [ ] `tests/fixtures/asn-camt053-sample-1.md` — anonymisation audit (mirrors `asn-sample-1.md`)
- [ ] `tests/fixtures/asn-mt940-sample-1.sta` — REQUIRED from user (anonymised real export)
- [ ] `tests/fixtures/asn-mt940-sample-1.md` — anonymisation audit
- [ ] `tests/fixtures/asn-cross-format/february.csv` + `february.camt053.xml` — for cross-format dedup test
- [ ] `Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php`
- [ ] `Modules/Ingestion/tests/Unit/AsnMt940LexerTest.php`
- [ ] `Modules/Ingestion/tests/Unit/AsnMt940Tag61ParserTest.php`
- [ ] `Modules/Ingestion/tests/Unit/AsnMt940Tag86ParserTest.php`
- [ ] `Modules/Ingestion/tests/Unit/AsnMt940CounterpartyCleanerTest.php`
- [ ] `Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php`
- [ ] `Modules/Ledger/tests/Unit/FingerprintComposerV3Test.php`
- [ ] `Modules/Ledger/tests/Unit/FingerprintRederiveCommandTest.php`
- [ ] `Modules/Ledger/tests/Unit/StatementSummaryWriterTest.php`
- [ ] `Modules/Import/tests/Unit/FingerprintStageClassifyTest.php`
- [ ] `Modules/Import/tests/Unit/EnrichmentPersistenceTest.php`
- [ ] `Modules/Import/tests/Feature/AsnCamt053ImportTest.php`
- [ ] `Modules/Import/tests/Feature/AsnMt940ImportTest.php`
- [ ] `Modules/Import/tests/Feature/CrossFormatDedupTest.php`
- [ ] `Modules/Import/tests/Feature/PreviewWizardEnrichedStateTest.php`
- [ ] Framework install: NONE — Pest, snapshots, larastan, pint all already configured per Phase 1

## Project Constraints (from CLAUDE.md)

The planner must verify every plan respects these directives:

- **DI only** — constructor injection; no facade calls (`Auth::user()`, `DB::table()`, `Cache::get()`); no global helpers (`auth()`, `config()`, `now()`, `app()`); Eloquent models direct OK (`Model::find()`, query builder via `$model->newQuery()`)
- **Codebase agnostic of GSD** — no references to `.planning/`, `PLAN.md`, `RESEARCH.md` in code, PHPDoc, or comments; docstrings describe current state only, never history
- **Larastan level 10 strict + strict-rules + Pint + Pest** — all gates must pass in CI; no exceptions
- **Modular architecture via `nwidart/laravel-modules`** — every new adapter lives under `Modules/Ingestion/Internal/Adapters/Asn/`; cross-module access only through `Public/` classes; the BoundaryArchTest enforces this
- **No `ext-imap`** (PLT-05) — not relevant to Phase 2, but the gate must remain green
- **Multi-user readiness** — every new domain table (`statement_summaries`) has `user_id` from day one
- **Integer minor units for money** — Phase 1's `AsnAmountParser` MUST be reused; never `(int) ((float) $x * 100)`
- **History retained forever** — the v3 fingerprint re-derive cannot drop rows; abort-on-collision preserves v2 state
- **Idempotency at DB layer** — composite UNIQUE on `transactions(user_id, fingerprint)` stays; only the fingerprint's content changes
- **Localhost only** — no new external network calls (genkgo/camt parses XML offline; no schema validation against a remote URL — the library ships its XSDs in `vendor/genkgo/camt/assets/`)

## Common Pitfalls

### Pitfall 1: CAMT batch entries treated as a single transaction

**What goes wrong:** A bulk SEPA Direct Debit (`BankTransactionCode` family `BBDD`) lands as ONE `Ntry` with one entry-level amount but N `TxDtls` children, each with its own `EndToEndId` and per-row amount. A naïve adapter that yields one DTO per `Entry` collapses N transactions into one, losing per-child `EndToEndId` references that Phase 5's chain resolver needs.

**Why it happens:** `Entry::getAmount()` is non-null even for batch entries; it's tempting to treat that as authoritative.

**How to avoid:** ALWAYS iterate `$entry->getTransactionDetails()`. If empty, yield one row from `$entry->getAmount()`. If non-empty, yield one row PER `$txDtls`, taking the per-row amount from `$txDtls->getAmountDetails()` (falls back to `$txDtls->getAmount()`, falls back to `$entry->getAmount()`).

**Warning signs:** Snapshot test row count is much lower than the CSV-equivalent fixture's row count for the same period.

### Pitfall 2: CAMT namespace mismatch yields zero entries silently

**What goes wrong:** A CAMT.053.001.05 file (less common but possible in older ASN archives) hits `Reader::readDom()`, which throws `ReaderException` because that sub-version isn't in `Config::getDefault()`. Without XSD validation enabled, a malformed but parseable variant might also silently yield zero entries.

**Why it happens:** `Config::getDefault()` registers exactly V02/V03/V04/V08 — nothing else. The namespace match is exact-string.

**How to avoid:** Keep XSD validation enabled in production. Catch `ReaderException` in `AsnCamt053Adapter::parse()` and rethrow as `UnsupportedFormatException` with a user-readable message: "This CAMT.053 sub-version is not yet supported. Supported: V02, V03, V04, V08." Run the snapshot test on at least two sub-versions when fixtures are available.

### Pitfall 3: MT940 `:86:` GVC narrative misparsed for ASN's structured format

**What goes wrong:** ASN's MT940 sometimes emits structured `?NN` subfield codes inside `:86:` (German banking standard inherited from SNS), sometimes plain free text. A regex tuned for one format misreads the other — counterparty name lands in `description` or vice versa.

**Why it happens:** ASN was acquired and merged with SNS/Regiobank (per the 2026-05 search result: "ASN bank is the continuation of Regiobank and SNS bank since July 1, 2025"); the legacy SNS MT940 stack is the structured one, the original ASN online-banking export is the simpler one. Either format can land on the user's machine depending on which year the statement comes from.

**How to avoid:** `AsnMt940Tag86Parser` must auto-detect format: if the content matches `^\d{3}\?` (three-digit GVC code followed by `?00` subfield marker), parse as structured. Otherwise treat as unstructured free text and put the whole content in `description`. Both code paths run through `AsnMt940CounterpartyCleaner` (D-27) before reaching `FingerprintComposer::normalize`.

**Warning signs:** Two MT940 imports of supposedly-similar transactions produce wildly different `counterparty_normalized` strings; or the cross-format dedup test fails because MT940 `counterparty_normalized` differs from CAMT's.

### Pitfall 4: Fingerprint v3 collision in genuine same-day same-merchant data

**What goes wrong:** v3 drops `source_ref` and uses `booked_at` (timestamp) instead of `posted_at` (date). For CSV rows where the adapter only knows the posted date, `booked_at` falls to `00:00:00`. Two genuine identical-amount transactions to the same merchant on the same day (both midnight `booked_at`) hash to the same fingerprint and collide.

**Why it happens:** ASN CSV gives only a date. Two real €5 coffees at the same merchant on the same day are not duplicates — but the v3 tuple cannot distinguish them.

**How to avoid:** Accept this as a known limitation for CSV-only imports — the user's ASN doesn't make this scenario possible at the CSV layer because the `Volgnummer` (sequence number, currently mapped to `source_ref` in v2) IS unique per row. After v3 drops `source_ref`, the collision pre-check in the re-derive migration will surface this case if it exists in the user's history. If it does: the migration aborts cleanly and the user resolves by hand (likely by editing one of the two rows to use a slightly different timestamp). For CAMT and MT940, `booked_at` carries a real time and the collision does not happen.

**Warning signs:** The re-derive migration aborts on collisions for the user's existing CSV-only data.

### Pitfall 5: Eager XML parsing exhausts memory on multi-year CAMT files

**What goes wrong:** `Genkgo\Camt\Reader::readFile()` materialises the whole `Message` graph in memory. For a 50-MB CAMT.053 file covering 3 years of ASN history, this peaks at ~200 MB peak memory (DOM + DTO graph).

**Why it happens:** No streaming API on `genkgo/camt`.

**How to avoid:** Phase 2 stays synchronous in a Livewire action per D-15. ASN's annual export is typically < 5 MB. If the user uploads a multi-year archive that exceeds available memory, the adapter throws an `OutOfMemoryError` and the wizard surfaces it as an ERROR row. Acceptable for Phase 2 — a streaming SAX parser would be Phase 6 work when async ingestion arrives. **Document the soft limit in the wizard copy** ("ASN CAMT.053 exports under 10 MB are supported in this version").

### Pitfall 6: Re-derive command run twice = silent no-op

**What goes wrong:** The user (or the migration) runs `php artisan diederik:rederive-fingerprints --confirm` a second time. With the version-skip check (`if ($row->normalization_version >= 3) continue`), the second run does nothing — but the user might think it failed.

**Why it happens:** Idempotent design.

**How to avoid:** The command's success output explicitly states `"Re-derived 0 rows to v3 (all already at v3)"` so a second run is informative, not silent. The migration's `up()` calls the command exactly once and is wrapped in `Schema::hasColumn` / migration idempotency anyway.

### Pitfall 7: `enriched_from` race condition between preview and confirm

**What goes wrong:** User A previews a CAMT import that will enrich row 42. Before confirming, User A (or a background job — none exist yet, but Phase 6 will introduce them) does something that already enriches row 42 via another path. The cached enrichment list still says "update row 42 from null to ENDTOEND-XYZ" but row 42's current `source_ref` is already `ENDTOEND-XYZ`. The UPDATE is a no-op but the `enriched_from` append still happens, creating a misleading audit entry.

**Why it happens:** The preview cache doesn't refresh between preview and confirm.

**How to avoid:** Inside the enrichment apply step, re-check `WHERE source_ref IS NULL` (or `WHERE source_ref != $newRef`) before applying the UPDATE. If the row's `source_ref` already matches the incoming value, skip the enriched_from append entirely. Cheap defence-in-depth; single-user app makes the race extremely unlikely but the check costs nothing.

## Code Examples

Verified patterns from existing source + library docs:

### Parsing a CAMT.053 file (genkgo/camt)

```php
// Source: vendor/genkgo/camt/src/Reader.php + Config.php (verified 2026-05-13)
use Genkgo\Camt\Config;
use Genkgo\Camt\Reader;
use Genkgo\Camt\Exception\ReaderException;

try {
    $message = (new Reader(Config::getDefault()))->readFile($localPath);
} catch (ReaderException $e) {
    throw new UnsupportedFormatException(
        'This CAMT.053 file uses an unsupported sub-version: ' . $e->getMessage(),
        previous: $e,
    );
}

foreach ($message->getRecords() as $statement) {
    foreach ($statement->getEntries() as $entry) {
        foreach ($entry->getTransactionDetails() as $txDtls) {
            $endToEndId = $txDtls->getReference()?->getEndToEndId();
            // ...
        }
    }
}
```

### MT940 line-by-line streaming with tag-pair flushing

```php
// Source: pattern derived from WoLpH/mt940 tag stream + Phase 1's lazy adapter style
final class AsnMt940Lexer
{
    /**
     * @return Generator<int, array{0:string, 1:string}>
     */
    public function tokenize(string $localPath): Generator
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new UnsupportedFormatException("Could not open MT940 file.");
        }

        try {
            // Skip SWIFT envelope if present, position at start of block-4 body.
            $this->skipSwiftEnvelopeIfPresent($handle);

            $currentTag = null;
            $buffer = '';

            while (! feof($handle)) {
                $line = fgets($handle);
                if ($line === false) break;
                $line = rtrim($line, "\r\n");

                if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $line, $m) === 1) {
                    if ($currentTag !== null) yield [$currentTag, $buffer];
                    $currentTag = $m[1];
                    $buffer = $m[2];
                } elseif ($line === '-' || $line === '') {
                    if ($currentTag !== null) {
                        yield [$currentTag, $buffer];
                        $currentTag = null;
                        $buffer = '';
                    }
                } else {
                    $buffer .= "\n" . $line;     // preserve continuation newline
                }
            }
            if ($currentTag !== null) yield [$currentTag, $buffer];
        } finally {
            fclose($handle);
        }
    }
}
```

### Fingerprint v3 tuple in `FingerprintComposer::compose`

```php
// Source: extension of existing Modules/Ledger/Public/Services/FingerprintComposer.php
public const NORMALIZATION_VERSION = 3;

public function compose(CanonicalTransaction $tx): string
{
    // v3: drop source_ref, add booked_at (datetime, second resolution)
    $tuple = implode('|', [
        (string) ($tx->userId ?? 0),
        (string) $tx->accountId,
        $tx->postedAt->toDateString(),
        $tx->bookedAt->toDateTimeString(),         // ★ NEW in v3
        (string) $tx->amountMinor,
        $tx->currency,
        $tx->counterpartyNormalized,
        // source_ref REMOVED in v3
    ]);

    return hash('sha256', $tuple);
}
```

### `enriched_from` append on UPDATE

```php
// Source: pattern derived from Laravel 12 AsArrayObject cast + project's existing DB::transaction usage
public function enrich(
    int $transactionId,
    string $newSourceRef,
    int $importRunId,
    string $sourceFormat,
    User $user,
): void {
    $this->db->connection()->transaction(function () use ($transactionId, $newSourceRef, $importRunId, $sourceFormat, $user) {
        /** @var Transaction $tx */
        $tx = Transaction::query()
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();

        // Defence-in-depth: skip if already at this source_ref
        if ($tx->source_ref === $newSourceRef) {
            return;
        }

        $provenance = $tx->enriched_from ?? collect();      // AsCollection cast
        $provenance->push([
            'format'        => $sourceFormat,
            'ran_at'        => $this->clock->now()->toIso8601String(),
            'import_run_id' => $importRunId,
            'added'         => ['source_ref'],
        ]);

        $tx->update([
            'source_ref'    => $newSourceRef,
            'enriched_from' => $provenance,
        ]);
    });
}
```

## Open Questions / Unresolved

These items block detailed planning and should be routed back to the user via Discussion before plan execution begins:

1. **Real anonymised ASN CAMT.053 + MT940 fixtures are required.** Adapters cannot be empirically validated against `[ASSUMED]` data. The user must download one CAMT.053 XML and one MT940 export from the ASN portal and supply them (anonymised per the protocol in `asn-sample-1.md`). Without these, the adapter snapshot tests are based on guesses and the implementation has no empirical baseline.

2. **CAMT.053 sub-version coverage in the user's actual data.** ASN's portal-2026 export sub-version is unknown without the real fixture. The library supports V02/V03/V04/V08; if the user has older statements that pre-date V02 (very rare), or a non-standard sub-version, the adapter must surface that on the first failed parse. **Decision needed:** does the user want the adapter to fail loudly on an unsupported sub-version, or fall back to a best-effort parse? Recommendation: fail loudly.

3. **MT940 `:86:` structured vs unstructured — which format does ASN actually emit in 2026?** The research above flagged both possibilities. The hand-rolled parser auto-detects, but the planner needs to know which is the dominant case so the test corpus prioritises correctly. **Empirically resolvable once the real fixture lands.**

4. **`enriched_from` JSON value object cast — `AsCollection` vs `AsArrayObject`?** No Phase 1 precedent; either works. Recommendation: `AsArrayObject` (closer to native PHP array semantics; less framework magic). Discretion item — planner picks.

5. **Should the v3 re-derive happen inside the migration `up()` or as a separate artisan command invoked by the migration?** Recommendation: separate artisan command (better progress reporting, easier dry-run, reusable for any future re-derive). Discretion item — planner picks.

6. **`statement_summaries` row created at preview-time or confirm-time?** The summary describes the file the user uploaded, not the rows that were inserted. Recommendation: create at CONFIRM time (so a discarded preview leaves no trace), but populate the summary fields from the parser's first pass (cached alongside the canonical batch in PreviewCache). Discretion item.

7. **Cross-format dedup direction lock — `CSV then CAMT` enriches; what about `CAMT then CSV`?** D-28's ordering says `EndToEndId > AcctSvcrRef > InstrId > MT940 ref > CSV ref`. So CSV-after-CAMT means CSV's `source_ref` (Volgnummer) is WEAKER than CAMT's `EndToEndId`, and the CSV import should mark its rows as DUPLICATE without enriching. This is a planner verification point — write the test for it explicitly (it's already in the validation requirement table above).

8. **Preview wizard ENRICHED state in MULTI-IBAN files.** If a CAMT.053 file covers two accounts and one has a known IBAN (matches an Account row), one is unknown (prompt the user), the preview is mixed. ENRICHED rows from the known account should render normally; the unknown-account block should still prompt for naming first. The Phase 1 wizard already handles this for NEW/DUPLICATE/ERROR — the same logic extends to ENRICHED with no special-case code. Confirm with a feature test.

9. **Statement-coverage view surface — separate page or inline panel?** D-30 leaves the polish to Claude's discretion. Recommendation: inline panel on the existing `/imports/{id}/results` page, behind a `<details>` disclosure ("Statement coverage: balance €X.XX → €Y.YY, period dd-mm-yyyy → dd-mm-yyyy, 47 entries"). No new route, no new component. Phase-later polish can split it out if useful.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | ASN's MT940 export uses the WoLpH/mt940 `StatementASNB` 34-char customer_reference variant in 2026 | §Library / API Surface — MT940 | Medium — if ASN shipped the standard 16-char layout instead, the regex rejects valid `:61:` lines. Mitigation: detect via empirical fixture in Wave 0. |
| A2 | The :86: GVC narrative codes (EREF, MREF, SVWZ, CRED, IBAN, BIC, KREF) are present in ASN's 2026 MT940 output | §Library / API Surface — MT940 | Medium — derived from generic German/Dutch banking convention. Mitigation: empirical fixture check; parser falls back to unstructured-text mode when the structured pattern doesn't match. |
| A3 | ASN's 2026 CAMT.053 export is sub-version 001.08 (or 001.02) | §Library / API Surface — CAMT | Low — `genkgo/camt` handles all four sub-versions transparently via namespace dispatch. |
| A4 | `genkgo/camt`'s `Money\Money::getAmount()` returns minor units as a string (no floating point) | §Library / API Surface — CAMT | Low — moneyphp/money is documented to use string-arithmetic via brick/math underneath. Verified by reading the moneyphp/money README. |
| A5 | ASN MT940 transaction-type codes include `NTRF`, `NDDT`, `NMSC`, `SCHG`, `NREF` | §Library / API Surface — MT940 | Low — these are well-documented SWIFT-standard 4-character codes; not adapter-critical (the adapter does not switch on them; they only go into `rawPayload`). |
| A6 | Real ASN exports under 10 MB stay under PHP's default memory limit (128 MB) with genkgo/camt's eager parse | §Common Pitfalls 5 | Medium — depends on row count. Mitigation: the wizard `max:10240` validation limit already exists in Phase 1. |
| A7 | `enriched_from` JSON column with `AsCollection` / `AsArrayObject` cast works correctly on SQLite WAL with Laravel 12 | §Fingerprint v3 Migration | Low — Laravel 12 added explicit native JSON SQLite support; the cast layer is storage-agnostic. |
| A8 | `Genkgo\Camt\DTO\Account` exposes the IBAN via `->getIdentification()->getValue()` for IBAN-type accounts (not yet verified in source) | §Adapter Architecture — CAMT skeleton | Low — the Account class has variant subclasses (IbanAccount, BBANAccount, OtherAccount); the adapter must handle the discriminated-union via instanceof. Verify in Wave 0 when reading the actual fixture. |

**All claims tagged `[ASSUMED]` in the body above are tracked here.** A1–A3 are the ones that empirically require the real fixture to confirm — these are blocking for production-quality planning and the planner should surface them to the user before plan execution.

## Sources

### Primary (HIGH confidence)
- `Modules/Ingestion/Public/Contracts/SourceAdapter.php` — VERIFIED in this session — adapter contract
- `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` — VERIFIED — target output shape
- `Modules/Ingestion/Public/Services/HeaderSniffer.php` — VERIFIED — sniffer pattern to extend
- `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` — VERIFIED — registry shape
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` — VERIFIED — reference adapter
- `Modules/Ledger/Public/Services/FingerprintComposer.php` — VERIFIED — v2 → v3 lift point
- `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` — VERIFIED — schema baseline
- `Modules/Ledger/Database/Migrations/2026_05_12_010004_create_import_runs_table.php` — VERIFIED — `import_runs` shape for FK
- `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` — VERIFIED — classify() extension point
- `Modules/Import/Internal/Pipeline/ImportPipeline.php` — VERIFIED — preview-loop shape
- `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` — VERIFIED — sentinel + sign-to-type
- `Modules/Import/Internal/Http/Livewire/UploadWizard.php` — VERIFIED — `in:asn-csv` validator
- `Modules/Import/Internal/Http/Livewire/PreviewWizard.php` + Blade — VERIFIED — preview-state extension point
- `vendor/genkgo/camt/src/Reader.php` (read via GitHub raw) — VERIFIED — entry-point shape, namespace dispatch, exception class
- `vendor/genkgo/camt/src/Config.php` (read via GitHub raw) — VERIFIED — `getDefault()` registers V02/V03/V04/V08 for CAMT.053
- `vendor/genkgo/camt/src/DTO/Entry.php` (read via GitHub raw) — VERIFIED — full getter list
- `vendor/genkgo/camt/src/DTO/EntryTransactionDetail.php` (read via GitHub raw) — VERIFIED — full getter list
- `vendor/genkgo/camt/src/DTO/Reference.php` (read via GitHub raw) — VERIFIED — `getEndToEndId()` confirmed
- `vendor/genkgo/camt/src/DTO/Record.php` (read via GitHub raw) — VERIFIED — `getFromDate/getToDate/getEntries/getId` confirmed
- `vendor/genkgo/camt/src/DTO/Balance.php` (read via GitHub raw) — VERIFIED — `TYPE_OPENING` / `TYPE_CLOSING` constants
- `genkgo/camt 2.10.3` on Packagist — VERIFIED — PHP ^8.1, moneyphp/money ^4.6, jschaedl/iban-validation ^2.5
- WoLpH/mt940 `tags.py` (read via GitHub raw) — VERIFIED — ASN's `StatementASNB` regex with 34-char customer_reference
- WoLpH/mt940 `processors.py` (read via GitHub raw) — VERIFIED — full `GVC_KEYS` + `DETAIL_KEYS` dictionaries
- `mt940.readthedocs.io/en/stable/mt940.tags.html` — VERIFIED — tag map and ASN-Bank specific note

### Secondary (MEDIUM confidence)
- `.planning/research/STACK.md` (project-level) — confirms `genkgo/camt ^2.10` rationale and acknowledges the `moneyphp/money` transitive dep
- `.planning/research/PITFALLS.md` (project-level) — confirms cross-format dedup is the canonical pitfall this phase anticipates (Pitfall 2)
- Phase 1 plan summaries (`01-04-SUMMARY.md`, `01-05-SUMMARY.md`) — patterns to mirror
- Laravel 12 native JSON SQLite support (Laravel News v12.3.0 release notes) — confirms `'use_native_json' => true` connection flag

### Tertiary (LOW confidence — flagged for empirical validation)
- ASN MT940 transaction-type codes (`NTRF`, `NDDT`, `NMSC`, `SCHG`, `NREF`) — community knowledge; not adapter-critical
- ASN's 2026 CAMT.053 sub-version (assumed 001.08, possibly 001.02 for older statements) — needs fixture verification
- ASN MT940 structured-vs-unstructured `:86:` mix in 2026 — needs fixture verification

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — `genkgo/camt 2.10.3` API surface read directly from source on this session's date
- Architecture: HIGH — Phase 1 patterns to mirror are verified by source-file reads; the four-way ImportPipeline classification fits the existing shape cleanly
- Pitfalls: MEDIUM — CAMT batch-entry + namespace pitfalls are documented in the library; MT940 :86: format-detection pitfall is derived from cross-referencing two sources (WoLpH/mt940 + general Dutch-banking knowledge); empirical fixture would lift to HIGH

**Research date:** 2026-05-13
**Valid until:** 2026-06-13 (genkgo/camt's last release was Aug 2025; library API surface is stable; SWIFT MT940 has been frozen for decades)

## RESEARCH COMPLETE
