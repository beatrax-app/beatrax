# `Ingestion` — architecture

The `Ingestion` module owns every source-format adapter: the
CAMT.053 / MT940 / CSV parsers for ASN, the PDF parser for ICS, the
CSV parser for PayPal. Each adapter turns its source format into a
stream of typed `SourceTransactionDto` instances ready for the
`ImportPipeline`'s `NormalizeStage`. The module also exposes the
`HeaderSniffer` the upload wizard calls to validate a file's shape
before parsing, and the `SourceAdapterRegistry` that maps stable
format identifiers to adapters.

## What this module is for

The project pulls financial data from four sources and the user
chooses which file goes through which adapter — there is no
content-sniffing fallback. The user-facing source-format picker on
the upload wizard is the contract; this module is the implementation.
Each adapter is responsible for the parsing-and-normalisation
boundary: it transforms the source's idiosyncratic shape (CAMT.053
ISO-20020 XML; MT940 `:61:` / `:86:` SWIFT tags; PayPal CSV with
language profiles + transaction rollup; ICS PDF with positional text
extraction) into a uniform DTO stream.

What the module explicitly does NOT do:

- It never auto-detects source format from content. The user
  declares the format up front. Auto-detection would silently mis-
  parse a file that happens to look CSV-ish but is something else.
- It never normalises the canonical-transaction shape — that is
  `Import::NormalizeStage`'s job. This module's output is
  `SourceTransactionDto`, a closer-to-source shape.
- It never persists anything. The adapter output is a stream the
  pipeline consumes; the only persistence path is through Ledger's
  `RecordsTransactions`.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `SourceAdapter::parse(SplFileInfo $file): iterable<SourceTransactionDto>`
    — the single adapter contract. Every per-source adapter
    implements it.
  - `AccountResolver::resolve(string $iban, User $user): AccountResolution`
    — abstract; the concrete resolver lives in
    [`Ledger`](../ledger/architecture.md) and is injected
    where ingestion code needs account routing.
- **DTOs/**
  - `SourceTransactionDto` — the close-to-source shape.
  - `SniffResult`, `KnownAccount`, `UnknownAccount`,
    `AccountResolution`.
- **Services/**
  - `HeaderSniffer::sniff(SplFileInfo $file): SniffResult` —
    pre-parse validation (header shape + character set + first-row
    sanity).
  - `SourceAdapterRegistry::for(string $formatId): SourceAdapter`
    — maps `'asn-csv'`, `'asn-camt053'`, `'asn-mt940'`,
    `'ics-pdf'`, `'paypal-csv'` to the adapter instance.
- **Exceptions/** — typed exceptions for every documented failure
  mode (`InvalidAmountException`, `InvalidDateException`,
  `MissingPaypalTransactionTypeMapException`,
  `UnknownPaypalEventTypeException`, `UnsupportedFormatException`,
  `SniffMismatchException`, `PdfExtractionFailed`,
  `UnsupportedPaypalCsvLanguageException`,
  `UnsupportedPaypalCsvShapeException`).
- **Paypal/** — `PaypalCsvEventTypeMap` (the canonical
  PayPal-event-name → PaymentType mapping table).

`Internal/` houses the adapters:

- **Internal/Adapters/Banking/** — the generic bank-statement parsers:
  `Camt053Adapter`, `Mt940Adapter`, plus helpers (`BankAmountParser`,
  per-format header profile classes, the MT940 lexer + Tag61 + Tag86
  parsers + `Mt940CounterpartyCleaner`).
- **Internal/Adapters/Asn/** — `AsnCsvAdapter` and its header profile +
  column map (ASN's own proprietary "CSV met IBAN" layout).
- **Internal/Adapters/Csv/** — `GenericCsvAdapter` + `GenericCsvAmountParser`,
  the preset-driven importer for other banks (N26, Revolut, ING…).
- **Internal/Adapters/Ics/** — `IcsPdfAdapter`, plus parser
  helpers (`PdfTextExtractor`, `IcsAmountParser`,
  `IcsDateParser`, `IcsPdfExtractionMap`,
  `IcsPdfHeaderProfile`).
- **Internal/Adapters/Paypal/** — `PaypalCsvAdapter`, plus
  parser helpers (`PaypalAmountParser`, `PaypalDateParser`,
  `PaypalCsvColumnMap`, `PaypalCsvLanguageProfile`,
  `PaypalTransactionRollup`).

## Key services + events

- `HeaderSniffer::sniff($file)` — opens the file, reads the
  first few hundred bytes, returns a `SniffResult` describing
  the detected character set, header signature, and any
  mismatch flags. The upload wizard surfaces a friendly error
  before reaching the adapter.
- `SourceAdapterRegistry::for($formatId)` — keyed lookup;
  unknown id raises `UnsupportedFormatException`.
- `SourceAdapter::parse($file)` — each concrete adapter is
  stream-based (yields DTOs); memory-efficient against
  multi-megabyte CAMT XML or thousand-row PayPal CSV.
- `PaypalCsvEventTypeMap` — the canonical Public mapping
  table. Adding a new PayPal event type is a single edit in
  this Public file so the runtime hinter chain picks it up
  without crossing the module boundary.

The module raises no events; it's purely a parser layer.

## Data flow

The pre-parse sniff:

```
UploadWizard
  → HeaderSniffer::sniff($file)
       → read first chunk
       → return SniffResult(charset, headerSignature, mismatchFlags)
  → wizard validates against declared format
  → on mismatch: friendly error, don't run the adapter
```

The parse phase (called from `ImportPipeline::ParseStage`):

```
ImportPipeline.preview
  → ParseStage
       → SourceAdapterRegistry::for($sourceFormat)
       → SourceAdapter::parse($file)
            yield SourceTransactionDto per row
  → NormalizeStage (Import-owned) — turns the
                                     SourceTransactionDto into a
                                     CanonicalTransaction
```

The PayPal CSV path (most complex):

```
PaypalCsvAdapter::parse
  → PaypalCsvLanguageProfile::detect (en, nl, etc.)
  → read CSV via league/csv (header-aware)
  → for each row:
       → map columns via PaypalCsvColumnMap
       → PaypalAmountParser::parse
       → PaypalDateParser::parse
       → PaypalCsvEventTypeMap::lookup($eventType)
            → UnknownPaypalEventTypeException on miss
       → yield SourceTransactionDto
  → PaypalTransactionRollup::combine (parent-child PayPal events
                                       collapse to one DTO)
```

## Per-adapter format quirks

### ASN CSV

`AsnCsvColumnMap` reflects the 20-column shape committed in
`tests/fixtures/asn-sample-1.csv` (a real anonymised 2026 export); the
full Dutch header-cell → field mapping lives next to the fixture at
`tests/fixtures/asn-sample-1.md` — keep the two in sync if ASN changes
the layout. `AsnCsvHeaderProfile::ACCEPTED_COLUMN_COUNTS` is `[19, 20]`:
ASN ships the trailing `Categorie` column inconsistently (some accounts
20 columns, some 19); the adapter never reads `Categorie` or
`Afschriftnummer` so either shape is safe, but older 17/18-column
variants are rejected since the adapter can't resolve `Volgnummer`
(column 15, → `source_ref`) deterministically against them. `Bedrag bij
/ af` is a signed period-decimal; `Omschrijving` may contain a literal
`\r` which the adapter normalises to a space.

### CAMT.053

Reference policy: `sourceRef` is the TxDtls `EndToEndId` when present,
`NULL` otherwise — weaker SEPA refs (`AcctSvcrRef`/`InstrId`/`TxId`/
`MsgId`/`MandateId`) are never promoted to `sourceRef`; they live
verbatim under `rawPayload['sepa']` for downstream chain resolution.
Booking-date normalisation: when `<BookgDt>` carries a date-only
element, `bookedAt` is zeroed to `00:00:00` to match the CSV adapter's
`startOfDay()` semantics, so a CSV row and a CAMT entry for the same
logical transaction produce identical `FingerprintComposer` v3 hashes;
an `<Ntry>` with neither `<BookgDt>` nor `<ValDt>` is rejected as a
parse error rather than falling back to the wall clock. Security: before
any `Reader` construction, `libxml_set_external_entity_loader()` is
installed and returns `null` for every entity — file, scheme-less, or
network — mitigating XXE regardless of the underlying PHP/libxml defaults
(with XSD validation disabled, nothing legitimate needs to resolve);
XSD validation is disabled deliberately (the shipped XSDs are pedantic
and would reject unforeseen optional elements) since the sniffer +
downstream IBAN/amount validators enforce structure instead. Money
handling: `genkgo/camt` exposes amounts as `Money\Money`
(moneyphp/money); the adapter converts to integer minor units at the
boundary and never lets `Money\Money` escape into the Public DTO
surface. `Camt053HeaderProfile::XML_NAMESPACE_REGEX` anchors on the
CAMT.053 family, not a specific sub-version — any
`urn:iso:std:iso:20022:tech:xsd:camt.053.001.NN` URI passes the sniffer;
unknown sub-versions fail at parse time instead, so a future bank
upgrade doesn't fail at the door.

### MT940

Source-reference policy: when the `:86:` GVC narrative carries a
non-empty, non-`NOTPROVIDED` `EREF` keyword, that value becomes
`sourceRef`; otherwise the `:61:` customer-reference (34-char extended
variant) is used; otherwise `sourceRef` stays null — MT940's reference
channel is intentionally weaker than CAMT.053's `EndToEndId`, and a
CAMT enrichment pass may overwrite this value later in the pipeline.
Booking-date normalisation mirrors CSV/CAMT.053 (zeroed to `00:00:00`).
Multi-statement files: when a file carries multiple `:20:` blocks, the
*first* statement's metadata is captured for `statement_summaries` and
`entry_count` reflects only that first statement; subsequent
statements' entries still yield rows, and `extras.multiStatement: true`
surfaces the fact. Source-format integrity: `:25:` (own IBAN) and
`:60F:`/`:60M:` (currency-bearing opening balance) must precede the
first `:61:`, or the file is rejected as malformed — this prevents an
empty IBAN or silent-default-EUR currency reaching the import pipeline.

`Mt940Lexer` is a defensive, bounded tokenizer: total line count is
capped at `MAX_LINE_COUNT` (100,000) and each tag buffer at
`MAX_BUFFER_BYTES` (16,384) — both caps raise `InvalidAmountException`
with a user-readable message so the upload wizard surfaces a fast error
instead of a hung worker on a pathological input (e.g. a crafted file
whose every byte is a newline, or one absurdly long line).

`Mt940Tag61Parser`'s status code maps to amount sign: `C`/`RD` →
positive, `D`/`RC` → negative. The magnitude itself goes through
`BankAmountParser::parseMt940Minor()`, which absorbs the three shapes
SWIFT writes that the strict `parseMinor()` refuses — a comma decimal,
no decimal at all, and a single fractional digit. The `:60:`/`:62:`
balance cells in `Mt940Adapter` reach the same method; the six
normalisation lines used to be written out in both places, and each
caller now keeps only its own exception message. The two-digit year resolves to a
four-digit calendar year via the SWIFT sliding-window rule (closest
year within ±50 of "now"), and the optional entry date (MMDD, no year)
inherits the value-date year *except* when the entry month is later
than the value month, in which case it rolls back one calendar year
(the standard late-December-entry-on-early-January-value-date
convention).

`Mt940Tag86Parser` accepts two shapes: structured (3-digit GVC posting
code + `?NN`-prefixed subfields — name from `?32`+`?33`, IBAN from
`?31` or the `IBAN` GVC keyword, purpose from `?20–?29`+`?60–?65`
scanned for SEPA GVC keywords `EREF`/`MREF`/`CRED`/`SVWZ`/`KREF`/
`PURP`/`IBAN`/`BIC`/`ABWA`/`MDAT`/`COAM`/`OAMT`) or unstructured (free
text, all other fields null). GVC keywords are paired delimiters
(`KEYWORD+value`, value runs to the next `+KEYWORD+` boundary); `BIC`
is the one exception, using a trailing-space convention instead.

`Mt940CounterpartyCleaner` pre-normalises a counterparty name before the
shared `FingerprintComposer::normalize()` step, stripping MT940-specific
noise the shared normaliser doesn't know about: leading GVC posting
codes, leading 4-char transaction-type codes (`NTRF`/`NDDT`/`NMSC`/
`SCHG`/`NREF`/`NRTI`/`NDAS`/`NCMI`/`NCMZ`), embedded BIC codes, and
`/REMI/`/`/NAME/`/`/IBAN/`/`/BIC/`/GVC-keyword `/`-markers.

### ICS PDF

Source-reference policy: the empirical Mijn ICS statement carries no
stable per-transaction identifier, so `sourceRef` is always `null` —
the v3 `FingerprintComposer` tuple is the only dedup anchor, the same
posture MT940 takes when `EREF` is absent. Currency policy: EUR-native
rows leave the settled pair `null` (NormalizeStage mirrors native into
settled); foreign-currency rows populate the native pair *and* the
settled-EUR pair via the trailing `Wisselkoers` line.

Card-number security policy: the per-statement card-watermark line is
parsed for the last-four only (`statement_summaries.extras.cardLast4`);
the cardholder name is dropped at the adapter boundary unconditionally.
Any 12+ contiguous digit run or canonical masked-card placeholder
(`****-****-****-XXXX`) inside a per-transaction text block is scrubbed
to a policy literal before it's written into the DTO's `rawPayload`.

Statement-metadata sign convention: opening/closing/period-charges
display as positive amounts with an `Af` direction marker meaning "owed
to ICS"; they are persisted signed-negative so ledger semantics line up
with the rest of the project (debits negative, credits positive).
Period-received credits stay positive; credit-limit and minimum-due are
informational and stay positive.

`IcsPdfAdapter::statementMetadata()` is assembled in the parse
generator's terminator step — callers must exhaust the `parse()`
iterator fully (e.g. via a complete `foreach` walk) before reading it;
partial iteration leaves it at `null`.

### PayPal CSV

`PaypalCsvColumnMap` is language-keyed (currently `nl` only); the
`Bruto`/`Kosten` headers ship with a trailing space inside their quoted
cells in the empirical export, so the map lists them *without* the
trailing space and the lookup tries both spellings. `counterpartyIban`
maps to `Bankrekening`, which the NL export ships empty on every row
(funding-source child rows carry no IBAN) — the rollup walker promotes
the empty string to `null`.

`PaypalCsvLanguageProfile::detect()` matches a discriminator token
subset per locale; `Reference Txn ID` is universally English (PayPal
never localises it) and is the strongest discriminator against a
non-PayPal CSV that happens to ship a `Datum` column.

`PaypalTransactionRollup` is a three-pass Transaction-ID /
Reference-Txn-ID walker, because PayPal's CSV is an *event* log, not a
transaction log — a single logical payment can produce up to four rows
(parent + funding-source + EUR conversion leg + foreign conversion
leg). Pass 1 drops `'skip'`-classified rows (Hold/Authorization/
Reserve/Reversal) and indexes survivors by Transaction ID. Pass 2
partitions into parents vs children: a row is a child only when
classified `'child-fee'`/`'child-fx'` *and* its Reference Txn ID points
at another row in this file; an orphan child (RefId points outside the
file, e.g. a cross-period billing-agreement parent) is promoted to a
standalone parent. Pass 3 folds each parent + its children into one
`SourceTransactionDto`: `amountMinor`/`currency` is the native
(non-EUR) leg of any `child-fx` pair (else the parent's Gross);
`settledAmountMinor`/`settledCurrency` is the EUR leg (else null). The
FX-direction safety net identifies the foreign leg by `Currency !=
'EUR'`, never by row order, since both legs of a currency-conversion
pair share the same event type and Reference Txn ID.

`PaypalCsvEventTypeMap::MAP` classifies each event type as `'skip'` /
`'parent'` / `'child-fee'` / `'child-fx'`; `TRANSACTION_TYPE` maps
`'parent'` event types to a `Transaction::TYPES` value. The two funding-
leg parent event types (`Bankstorting`/`General Withdrawal`/`Transfer
to bank`) classify as `'parent'` and resolve to `transfer_in`, so an
ASN→PayPal top-up surfaces as the PayPal-side `transfer_in` leg that
`PairTransferCandidates` matches against the ASN-side `transfer_out` —
distinct from the localised `Bankstorting naar PP-rekening` child-fee
entry, which is a funding-source enrichment riding under a purchase
parent. Any event type not in `MAP` raises
`UnknownPaypalEventTypeException`; a `'parent'` event type present in
`MAP` but missing from `TRANSACTION_TYPE` raises the narrower
`MissingPaypalTransactionTypeMapException` (a code-internal
inconsistency, since the two tables must stay in lock-step).

### Generic CSV (bank presets)

`GenericCsvAdapter` is preset-driven: one instance per `CsvPreset` (see
`CsvPresetRegistry`), so supporting a new bank/fintech is a matter of
adding a preset, not a class. Columns are matched by a *normalised*
header name (lower-cased, whitespace stripped) so minor spelling
differences (`Naam / Omschrijving` vs `Naam/Omschrijving`) still
resolve. `GenericCsvAmountParser` handles the cross-bank zoo of amount
spellings: `-1.234,56` (comma decimal, dot thousands), `1,234.56` (dot
decimal, comma thousands), `1234.56`, `-12,34`, a leading `+`,
parenthesised negatives `(12,34)`, and stray currency symbols /
non-breaking spaces — always assuming a two-decimal minor unit. Internal
whitespace inside the amount is rejected (never trimmed away) since
those shapes never appear in a real export and accepting them would
silently merge accidentally-concatenated cells.

### HeaderSniffer

Validates a local file matches its declared source format *before* any
adapter starts parsing: the first 8 KB of bytes, the file extension,
the CSV header row, and (for the XML formats) the document namespace
URI. A leading UTF-8 byte-order mark is stripped before parsing so
files exported through tools that prepend one (Excel, some browser
downloads) sniff cleanly. The PayPal CSV sniff additionally rejects the
Saldorapport (Balance Reconciliation Report) export before the
language-profile check — its `RH`/`RD`/`RF` record-type-prefixed first
cell never appears as a column name in the per-event Rapport
Transactiegegevens export, so detecting it yields an actionable "wrong
export" hint instead of a confusing "language profile not supported"
fallback.
