# `Ingestion` — architecture

The `Ingestion` module owns every source-format adapter: the
CAMT.053 / MT940 / CSV parsers for ASN, the PDF parser for ICS, the
CSV parser for PayPal, and the preset-driven generic CSV importer that
covers every other bank. Each adapter turns its source format into a
stream of typed `SourceTransactionDto` instances ready for the
`ImportPipeline`'s `NormalizeStage`. The module also exposes the
`HeaderSniffer` the upload wizard calls to validate a file's shape
before parsing, and the `SourceAdapterRegistry` that maps stable
format identifiers to adapters.

## What this module is for

The user chooses which file goes through which adapter — there is no
content-sniffing fallback. The user-facing source-format picker on
the upload wizard is the contract; this module is the implementation.
Each adapter is responsible for the parsing-and-normalisation
boundary: it transforms the source's idiosyncratic shape (CAMT.053
ISO-20020 XML; MT940 `:61:` / `:86:` SWIFT tags; PayPal CSV with
language profiles + transaction rollup; ICS PDF with positional text
extraction; a bank preset's own column names and decimal comma) into a
uniform DTO stream.

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
  - `SourceAdapter::parse(string $localPath, AccountResolver $accounts): iterable<SourceTransactionDto>`
    — the single adapter contract. Every per-source adapter
    implements it, alongside `format()` and `statementMetadata()`.
  - `AccountResolver::resolve(string $iban): AccountResolution`
    — abstract; the concrete `EloquentAccountResolver` lives in
    [`Import`](../import/architecture.md) and is injected where
    ingestion code needs account routing. It is constructed per import
    run with the user already bound, which is why the method takes
    only the IBAN.
  - `NamesAFormatMismatch` — the marker every "this file is not the
    format you declared" exception carries, so `Import` can tell that
    class of failure apart without naming an `Internal/` exception.
- **Dto/**
  - `SourceTransactionDto` — the close-to-source shape.
  - `SniffResult`, `CsvPreset`, `KnownAccount`, `UnknownAccount`,
    `AccountResolution`.
- **Enums/** — `SourceFormat`, one case per parse — the file's own
  shape, never a bank picked from a list. A bank whose CSV differs only
  in its column mapping is a `CsvPresetRegistry` preset, so a value
  absent from the enum is a preset rather than an error, which is why
  every read of it is a `tryFrom`. `IcsPdf` and `PaypalCsv` remain cases
  because each of those exports needs a parser no column mapping can
  express — ICS's Dutch PDF statement layout, PayPal's parent/child row
  rollup.
- **Services/**
  - `HeaderSniffer::sniff(string $localPath, string $declaredFormat): SniffResult`
    — pre-parse validation of the file against the format the user
    declared.
  - `SourceAdapterRegistry::for(string $formatId): SourceAdapter`
    — maps `'camt053'`, `'mt940'`, `'ics-pdf'`, `'paypal-csv'` plus
    every `CsvPresetRegistry` id to the adapter instance. `'eml'` /
    `'mbox'` are deliberately absent: they are the receipt arm's, not
    the registry's.
  - `CsvPresetRegistry` — the per-issuer CSV dialects, as data.
    `all()` returns the header-name presets `GenericCsvAdapter` is
    instantiated per; `allPositional()` returns the by-index presets
    `PositionalCsvAdapter` is instantiated per.
- **Exceptions/** — `MissingPaypalTransactionTypeMapException`,
  `UnknownPaypalEventTypeException`, `UnsupportedFormatException`,
  `PdfReaderUnavailableException`, `PdfHasNoTextLayerException`,
  `PdfPasswordProtectedException`. The three PDF ones are `Public/`
  together because they are the refusals the import screen has to tell
  apart — a scan, an encrypted file and a build with no reader are three
  different answers, and the reader can act on each. The rest stayed
  `Internal/` —
  `InvalidAmountException`, `InvalidDateException`,
  `SniffMismatchException`, `PdfExtractionFailed`,
  `UnsupportedPaypalCsvLanguageException`,
  `UnsupportedPaypalCsvShapeException` — because `Import` reaches them
  through the `NamesAFormatMismatch` marker, never by class name.
- **Paypal/** — `PaypalCsvEventTypeMap` (the canonical
  PayPal-event-name → PaymentType mapping table).

`Internal/` houses the adapters:

- **Internal/Adapters/Banking/** — the generic bank-statement parsers:
  `Camt053Adapter`, `Mt940Adapter`, plus helpers (`BankAmountParser`,
  per-format header profile classes, the MT940 lexer + Tag61 + Tag86
  parsers + `Mt940CounterpartyCleaner`).
- **Internal/Adapters/Csv/** — the two preset-driven CSV importers.
  `GenericCsvAdapter` + `GenericCsvAmountParser` address columns by
  header name (N26, Revolut, ING); `PositionalCsvAdapter` addresses
  them by index, for an export that renames columns between revisions
  but keeps their order (ASN). Neither names an issuer: the dialect
  arrives as a `CsvPreset` / `PositionalCsvPreset`.
- **Internal/Adapters/Ics/** — `IcsPdfAdapter`, plus parser
  helpers (`PdfTextExtractor`, `PdfTextLayoutReader`, `IcsAmountParser`,
  `IcsDateParser`, `IcsPdfExtractionMap`,
  `IcsPdfHeaderProfile`). `PdfTextExtractor` prefers poppler's
  `pdftotext` and falls back to `PdfTextLayoutReader`, a pure-PHP
  reader over `smalot/pdfparser`, on any machine without it — which is
  every phone, since neither iOS nor Android permits running a second
  binary. Both readers produce the same 23 rows from the committed
  scenario-1 statement; see
  [ics-pdf-text-extraction.md](ics-pdf-text-extraction.md#reading-a-statement-without-poppler).
- **Internal/Adapters/Paypal/** — `PaypalCsvAdapter`, plus
  parser helpers (`PaypalAmountParser`, `PaypalDateParser`,
  `PaypalCsvColumnMap`, `PaypalCsvLanguageProfile`,
  `PaypalTransactionRollup`).

## Key services + events

- `HeaderSniffer::sniff($localPath, $declaredFormat)` — opens
  the file, reads the first 8 KB, and either throws or returns
  a `SniffResult` naming the format and how to read it
  (delimiter, has-header, encoding, column count). It is not
  advisory: a mismatch is an exception, and every adapter calls
  it again itself on the first line of `parse()`, so a caller
  that skipped the wizard is refused just the same.
- `SourceAdapterRegistry::for($formatId)` — keyed lookup;
  unknown id raises `UnsupportedFormatException`.
- `SourceAdapter::parse($localPath, $accounts)` — each concrete
  adapter is stream-based (yields DTOs); memory-efficient against
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
  → HeaderSniffer::sniff($localPath, $declaredFormat)
       → read first 8 KB, strip a UTF-8 BOM
       → per-format arm: extension + signature + header row
       → return SniffResult(format, delimiter, hasHeader,
                            encoding, columnCount)
  → on mismatch: throws; the wizard renders the message,
                 and the adapter never runs
```

The parse phase. `ParseStage` is not a member of `ImportPipeline` — it is an
Import-owned collaborator the pipeline takes in its constructor, entered at
`ParseStage::run()`, and it is where this module's adapters are reached. It
has two arms: `eml` / `mbox` go to the receipt adapter, and every other
format is looked up in the registry. A row off the receipt arm keeps
`eml` / `mbox` as its `source_format` all the way into `transactions` —
the matcher key (`paypal-receipt`, `ics-receipt`, …) rides in
`raw_payload` and is never promoted to the column, which is what
[`Import`](../import/architecture.md)'s receipt gate has to match on.

```
ImportPipeline::preview
  → ParseStage::run($localPath, $sourceFormat, $accounts, $user)
       → SourceAdapterRegistry::for($sourceFormat)
       → SourceAdapter::parse($localPath, $accounts)
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

### The ASN positional CSV preset

`CsvPresetRegistry::ASN` reflects the 20-column shape committed in
`tests/fixtures/asn-sample-1.csv` (a real anonymised 2026 export); the
full Dutch header-cell → field mapping lives next to the fixture at
`tests/fixtures/asn-sample-1.md` — keep the two in sync if ASN changes
the layout. The preset's `acceptedColumnCounts` is `[19, 20]`:
ASN ships the trailing `Categorie` column inconsistently (some accounts
20 columns, some 19); the preset maps neither `Categorie` nor
`Afschriftnummer` so either shape is safe, but older 17/18-column
variants are rejected since `Volgnummer` (column 15, → `source_ref`)
cannot be resolved deterministically against them. `Bedrag bij
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
upgrade doesn't fail at the door. The regex accepts either quote
character, because XML permits both and pinning the double quote refused a
conformant export while telling the reader its namespace was wrong.

Account identity is a CHOICE, on both sides of an entry. ISO 20022 models
`Acct/Id` and `CdtrAcct`/`DbtrAcct` `Id` as `IBAN` **or** `Othr`, and
`genkgo/camt` answers the second branch with a sibling of `IbanAccount`
(`OtherAccount`, and `BBANAccount`/`UPICAccount`/`ProprietaryAccount` on the
052/054 decoders) — every one of them exposing `getIdentification()`. The
adapter reads both branches on both sides and normalises a blank identifier to
`NULL`, so a credit-card settlement or a non-IBAN domestic account keeps the
identifier it arrived with. `accounts.iban` already holds synthetic literals
(`Modules\Ingestion\Public\Enums\SyntheticIban`), so "identifier, IBAN or
not" is the established meaning of the column the value lands in; the adapter's
own extractors are named for the identifier rather than for the IBAN.

`RmtInf` is a CHOICE too. `description` is the concatenated `<Ustrd>` blocks and
deliberately nothing else — stringifying `<Strd>` into it would hide "no
remittance" behind "structured-only" — but the structured blocks are no longer
discarded: each `CdtrRefInf/Ref` and `AddtlRmtInf` pair rides under
`rawPayload['sepa']['remittanceStructured']` beside the other SEPA references.
`BkTxCd/Domn` without a `<Fmly>` is read as a domain code with no family;
genkgo leaves its typed `$family` property uninitialised there, and reading it
raised `Error` — with XSD validation off, one non-conformant statement aborted
the whole import.

Multi-statement messages: one `<BkToCstmrStmt>` may carry several
`<Stmt>` records, and the adapter publishes exactly one of them. It
publishes the **first**, with `extras.multiStatement: true` beside it and
`entry_count` covering that first statement only — the same answer
`Mt940Adapter` gives, deliberately, because the two parsers used to
disagree: CAMT silently kept the *last* statement, so a reader importing
a multi-statement export lost the earlier ones from
`statement_summaries` with nothing recording that they had been in the
file. The later statements' entries still yield rows either way. Unlike
MT940 there is no paged-statement case to separate out here: CAMT.053
pages across *messages* (`<Pgntn>` in the group header), so a second
`<Stmt>` inside one message — with its own `<Id>` and its own
`OPBD`/`CLBD` balances — is a second statement, never page two of the
first. Both parsers write the flag through
`Modules\Ingestion\Internal\Enums\StatementExtraKey`, so the key cannot
drift apart again the way the behaviour did.

### MT940

Source-reference policy: when the `:86:` GVC narrative carries a
non-empty, non-`NOTPROVIDED` `EREF` keyword, that value becomes
`sourceRef`; otherwise the `:61:` customer-reference (34-char extended
variant) is used; otherwise `sourceRef` stays null — MT940's reference
channel is intentionally weaker than CAMT.053's `EndToEndId`, and a
CAMT enrichment pass may overwrite this value later in the pipeline.
Booking-date normalisation mirrors CSV/CAMT.053 (zeroed to `00:00:00`).
Multi-statement files: when a file carries a second statement — one
that opens after the first has been closed by its FINAL `:62F:` balance
— the *first* statement's metadata is captured for
`statement_summaries` and `entry_count` reflects only that first
statement; the later statements' entries still yield rows, and
`extras.multiStatement: true` surfaces the fact. CAMT.053 now answers
the same question the same way — see the section above. A statement PAGED
across several messages is not that: `:62M:` and `:60M:` are the
intermediate close and open that hand one statement from one page to
the next, so only `:62F:` closes the statement, the header fields keep
the first page's values, and the entry count covers every page. Source-format integrity: `:25:` (own IBAN) and
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
`BankAmountParser::parseMt940Minor()`, which absorbs the four shapes
SWIFT writes that the strict `parseMinor()` refuses — a comma decimal,
no decimal at all, a single fractional digit, and the canonical `15d`
form whose comma carries no digits after it at all (`1000,`). That last
one is how SWIFT writes a whole amount, and refusing it made
`parseBalance()` return null for a `:60F:C260202EUR1000,` that was right
there in the file; the reader was then told the `:61:` had arrived
"before any balance tag set a currency", which sent them hunting for a
tag the parser had already read. The message now separates the two: a
balance tag that never came, and one that came and could not be read.
The `:60:`/`:62:` balance cells in `Mt940Adapter` reach the same method;
the six normalisation lines used to be written out in both places, and
each caller now keeps only its own exception message. Both `:61:` date
rules live in `Modules\Ingestion\Public\Banking\SwiftDate`, on the
seam rather than inside the parser, because `App\Fixtures\Mt940Rebaser`
has to date a rebased fixture exactly as the import will read it back.
The two-digit year resolves to a
four-digit calendar year via the SWIFT sliding-window rule (closest
year within ±50 of "now"). The optional entry date (MMDD, no year)
takes its year from the *distance* between the two months, not from
their order: a gap wider than six months either way is the calendar
turning (entry December under a value date in January is last year;
entry January under a value date in December is next year) and anything
narrower is the value date's own year. Ordering alone read the ordinary
month-end value booked the next working day — value 31-01, entry 01-02
— as belonging to the previous year.

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
settled-EUR pair via the trailing `Wisselkoers` line. The native amount
is read at the *native* currency's minor-unit scale — `IcsAmountParser`
takes the code the row named — per
[reading an amount at its currency's own scale](#reading-an-amount-at-its-currencys-own-scale).

Card-number security policy: the per-statement card-watermark line is
parsed for the last-four only (`statement_summaries.extras.cardLast4`);
the cardholder name is dropped at the adapter boundary unconditionally.
Any 12+ contiguous digit run or canonical masked-card placeholder
(`****-****-****-XXXX`) inside a per-transaction text block is scrubbed
to a policy literal before it's written into the DTO's `rawPayload`.

Statement-period policy: a genuine Mijn ICS statement prints no
`Periode` field, so the period is derived, and it is derived from the min
and max `posted_at` across the rows parsed — never `booked_at`. ICS books
a charge on or after the day the card was used, and every reader of the
period tests membership on `posted_at`, so a booked-derived period opened
after the earliest charge it billed and no statement could settle. See
[a period derived from one column and tested on
another](../../conventions/invariants-from-shipped-failures.md#a-period-derived-from-one-column-and-tested-on-another).
`openingBalanceDate` / `closingBalanceDate` follow the same two days.

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
classified `ChildFx` *and* its Reference Txn ID points at another row in
this file; an orphan child (RefId points outside the file — a statement
cut at a month boundary is how that happens) is promoted to a standalone
parent, where the classifier raises
`OrphanedPaypalChildRowException` and the reader is told which row and
that the neighbouring statement holds its parent. Pass 3 folds each parent + its children into one
`SourceTransactionDto`: `amountMinor`/`currency` is the native
(non-EUR) leg of any `child-fx` pair (else the parent's Gross);
`settledAmountMinor`/`settledCurrency` is the EUR leg (else null). The
FX-direction safety net identifies the foreign leg by `Currency !=
'EUR'`, never by row order, since both legs of a currency-conversion
pair share the same event type and Reference Txn ID.

A conversion leg lends its magnitude and nothing else: PayPal books each
leg in the direction *its own* balance moved, so the euro leg of an
outgoing dollar payment is a credit and the dollar leg of an incoming one
is a debit. The rolled-up DTO takes the direction of the parent payment
for both legs. Reading the leg verbatim settled a $22,50 charge as €20,80
of income — see [Invariants written after a shipped
failure](../../conventions/invariants-from-shipped-failures.md).

PayPal ships no opening/closing balance rows, so `PaypalCsvAdapter` is
the only adapter here that *sums* its closing balance rather than
reading it — ICS takes its balances off the document, CAMT.053 and
MT940 off the file. It sums the **settled** leg of each rolled-up DTO
(`settledAmountMinor ?? amountMinor`) and reports the currency those
legs are in, rather than each row's native minor units under a
hardcoded EUR label. Summing the native leg added the sample export's
two USD parents into a euro total: `statement_summaries` carried a
figure the ledger's own rows never summed to, and
`/reconcile` rendered "Difference — −€2.72" beside "Toggle cleared
rows…" — a gap no row could close, because no row was ever €2.72. When
the legs are not all in one currency there is no single closing figure,
so the adapter reports **none**: `/reconcile` filters on a non-null
`closing_balance_minor`, so no target is right where a wrong one would
be an instruction the reader cannot carry out.

`PaypalCsvEventTypeMap::MAP` classifies each event type as a
`PaypalEventAction` — `Skip`, `Parent` or `ChildFx` —
and `TRANSACTION_TYPE` maps every `Parent` event type to a
`TransactionType` (`Ledger`'s enum).

Everything that moves the reader's money is a `Parent`. That covers the
standalone top-ups (`Bankstorting` / `General Withdrawal` / `Transfer to
bank`) and, since they are movements too, the per-purchase funding legs
`Bankstorting naar PP-rekening` and `Algemene kaartstorting`. All five
resolve to `transfer_in`, so each surfaces as the PayPal-side leg that
`PairTransferCandidates` matches against the bank-side `transfer_out`.
Classifying the last two as children folded them into their purchase
parent, where only `ChildFx` children change anything — the leg vanished,
the bank debit was left unpaired, and net worth counted the same euros
twice. See [PayPal funding legs](../import/paypal-funding-legs.md).

Only `ChildFx` remains a child: a conversion leg restates its parent's
amount in a second denomination and owns no canonical type. Any event
type not in `MAP` raises `UnknownPaypalEventTypeException`; a child event
type reaching `transactionType()` raises
`OrphanedPaypalChildRowException`, because the only way it gets there is
the walker promoting an orphan; and a `Parent` present in `MAP` but
missing from `TRANSACTION_TYPE` raises the narrower
`MissingPaypalTransactionTypeMapException` (a code-internal
inconsistency, since the two tables must stay in lock-step).

### Reading an amount at its currency's own scale

`GenericCsvAmountParser`, `BankAmountParser`, `PaypalAmountParser` and
`IcsAmountParser` each take the row's currency and read the figure at
that currency's own minor-unit count, via
`Ledger\Public\ValueObjects\CurrencyScale`, which asks the `Money` value
object and is the one seam every scale reader in the repo goes through
([minor units](../ledger/minor-units-and-zero-decimal-currencies.md#where-the-scale-comes-from)).
A yen has no minor unit and a dinar has three, so
the repo-wide hundredth read a Revolut line whose own `Currency` column
says `JPY` as a hundred times itself: `-1000` became `-100000`, rendered
`-¥100,000` where the file said ¥1,000. Nothing in the pipeline rejects
a currency code, so the fix belongs at the boundary that reads the
digits. Each parser's shape check follows the same count — a JPY amount
carries no fractional digits and one that does is refused rather than
rounded away — and a caller with no currency to hand keeps the
two-decimal assumption.

`IcsAmountParser` was the last one taking no currency at all, so it
could not honour a scale even in principle: it stripped the currency
token off the figure and then threw the token away, hard-requiring two
fractional digits. That is wrong for the *left* of the ICS statement's
two amount columns — "Bedrag in vreemde valuta", which
`ics-sample-1.txt` prints as `50,00 USD`, `8,99 GBP` and `6,00 USD`, and
which `IcsPdfAdapter` reads as the row's own `amountMinor` +
`currency`. A yen amount there carries no fractional digits, so the
fixed rule refused a legitimate row with "Invalid Dutch amount format".
The euro column and the summary blocks pass
`IcsPdfHeaderProfile::STATEMENT_CURRENCY` explicitly, so the notation
they are checked against is named rather than assumed.

The bank-API path (`EnableBankingSourceAdapter`) is a separate boundary
and is not covered by these four.

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
non-breaking spaces — at the row currency's own minor-unit count, per
the section above. Spaces
and non-breaking spaces are removed wherever they sit in the cell, not
only at the ends, because a plain space is the thousands separator in
several EU exports (`1 234,50`); `GenericCsvAmountParserTest` pins the
non-breaking-space form. What survives that has to be digits around at
most one decimal separator, so a cell that is not an amount still raises
`InvalidAmountException`. The cost of the rule is real and accepted: a
`12 34` produced by two cells running together parses as `1234.00`
rather than failing loudly.

Native vs settled, and the fee that pays for the distinction: Revolut's
export ships a `Fee` column, and the two amounts it implies are different
numbers. `Amount` is what the merchant charged; what the account was
actually debited is `Amount` minus `Fee`. The adapter writes the first to
the native pair and the second to the settled pair, so `amount_minor`
stays the merchant's charge and `settled_amount_minor` — the column
`AccountBalanceQuery` sums — tracks the export's own running `Balance`.
The subtraction carries no sign branch, because `Balance` in the
empirical export advances by `Amount - Fee` whichever way `Amount`
points: a `-100.00` exchange with a `1.25` fee settles at `-101.25`, and
a `+12.00` refund with a `0.50` fee settles at `+11.50`. Read as a
magnitude to enlarge instead, that refund would credit `12.50` and the
bank's own balance would disagree — the sign error hides on the credit
side, where a fee makes the settled figure smaller, not larger. A row
whose fee is zero (every row of an ordinary card statement) leaves the
settled pair `null` and inherits the native one, the same convention the
ICS adapter uses for its EUR-native rows. None of this reaches the
dedup key: `FingerprintComposer`'s tuple reads `amount_minor` and
`currency` and never the settled pair, so a ledger imported before the
fee was applied keys identically afterwards and re-imports as
duplicates rather than as new rows — no `fingerprint_version` bump, no
backfill. `Fee` is the only preset column of its kind; N26 and ING NL
export none. `raw_payload` keeps the source row, fee cell included,
untouched.

### HeaderSniffer

Validates a local file matches its declared source format *before* any
adapter starts parsing: the first 8 KB of bytes, the file extension,
the CSV header row, and (for the XML formats) the document namespace
URI. For a header-name preset the columns checked are
`CsvPreset::requiredHeaders()` — the discriminating `headerSignature`
PLUS every column `GenericCsvAdapter` addresses through `cell()`, whose
miss is a throw. The two are derived from one place because a required
column absent from the check is still refused, just later and worse:
`InvalidAmountException` is not a `NamesAFormatMismatch`, so the reader
got a detail-free "read part-way" instead of the column's name. A column
read through `optionalCell()` (Revolut's `Fee`) stays out of the list. It also holds the two receipt arms — `eml` against an
`EmlHeaderProfile` RFC 822 header signature, `mbox` against the
`From ` line the archive opens with — even though neither format
reaches an adapter in this module. The sniff is the wizard's one
validation seam, and moving those two out would give the receipt
transports a different failure message for the same kind of mistake.
A leading UTF-8 byte-order mark is stripped before parsing so
files exported through tools that prepend one (Excel, some browser
downloads) sniff cleanly. The PayPal CSV sniff additionally rejects the
Saldorapport (Balance Reconciliation Report) export before the
language-profile check — its `RH`/`RD`/`RF` record-type-prefixed first
cell never appears as a column name in the per-event Rapport
Transactiegegevens export, so detecting it yields an actionable "wrong
export" hint instead of a confusing "language profile not supported"
fallback.
