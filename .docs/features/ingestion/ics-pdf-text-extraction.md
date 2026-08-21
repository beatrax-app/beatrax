# ICS PDF text extraction

The Mijn ICS consumer portal publishes credit-card statements as PDF and
nothing else — there is no CSV, no OFX, no API. `Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter`
therefore has to recover a table from a page layout, and every rule it
applies is an empirical observation about how one issuer renders one
document. This page describes those rules, the order they run in, and
which of them break first when ICS changes the statement.

For the module map see [architecture.md](architecture.md); for what
happens to the resulting rows see
[../../architecture/ingestion-pipeline.md](../../architecture/ingestion-pipeline.md).

## Why the obvious approaches fail

A PDF has no rows and no columns — it has glyphs at coordinates. Two
approaches suggest themselves and both are rejected:

- **Fixed character offsets.** `pdftotext -layout` pads columns with
  spaces to approximate the visual layout, but the padding is derived
  from the widest cell on the page. A long merchant name on page 2
  shifts every column right of it, so an offset calibrated on one
  statement mis-slices the next.
- **Left-to-right tokenising.** The description column ("Omschrijving")
  is free text of unbounded width containing spaces, and it sits in the
  middle of the row. Consuming tokens left to right runs into it and
  cannot tell where it ends.

The adapter instead anchors on the two ends of the line and peels
inward, because every field except the description has a recognisable
shape and a fixed position relative to one end.

## Extraction

`PdfTextExtractor::extract()` shells out to poppler's `pdftotext` through
`spatie/pdf-to-text` with exactly four options, all of them load-bearing:

| Option | Why |
|---|---|
| `layout` | preserves the horizontal space runs that make a row one line; without it the columns arrive as separate lines |
| `enc UTF-8` | the statement carries `€` and Dutch diacritics |
| `eol unix` | one line-splitting shape for every platform |
| `nopgbrk` | drops the form-feed page separators so a multi-page statement is one continuous stream |

Before invoking the binary the extractor rejects a path that does not end
in `.pdf` and a file larger than `UploadLimits::MAX_BYTES`. The suffix
check is defence in depth for callers that bypass the upload wizard's
`HeaderSniffer` — `Pdf::text()` invokes Symfony Process with an argv
array, so the path never reaches a shell either way.

Every failure — missing binary, unreadable file, any Process error —
surfaces as `PdfExtractionFailed`. That typed identity is what lets the
upload wizard render "pdftotext binary missing — install poppler"
instead of an amount-parser exception thrown three layers deeper.

## Raw text and cleaned text are both needed

`IcsPdfExtractionMap::PAGE_NOISE_PATTERNS` strips the furniture that
repeats on every page: the `KAARTHOUDER` banner, the card watermark
line, the repeating `Datum / ICS-klantnummer / Volgnummer / Bladnummer`
header, an Apple Pay marketing banner, the deposit-guarantee disclaimer,
and two recurring body-paragraph directives.

The statement-level metadata is read from the **raw** text, before that
pass, and the transaction rows from the **cleaned** text after it. The
noise patterns delete the very lines the metadata anchors on — the card
watermark carries the last four digits, the header block carries the
statement date and sequence number. Reading metadata after the strip
would silently yield `null` for all of it.

## Recognising a transaction row

`looksLikeTransactionRow()` requires two anchors at opposite ends of the
same line:

1. it starts with `<day> <three-letter Dutch month>`, optionally
   followed by a period (`23 jan.`);
2. it ends with a whitespace-delimited `Af` or `Bij` direction marker.

Either test alone produces false positives — body paragraphs open with
dates, and the summary block ends with `Af`. Requiring both is what
separates the table from the prose around it, without needing to locate
the table header at all.

A foreign-currency purchase occupies **two** lines: the merchant line,
then a continuation line beginning with `IcsPdfExtractionMap::FX_LINE_ANCHOR`
(`'Wisselkoers '`) carrying the native currency code and the displayed
conversion rate. `iterateTransactionBlocks()` joins the pair into one
block. It is a `while` loop rather than a `for` loop precisely because
the cursor advances by one or two lines depending on what the body found.

## Peeling one row apart

`buildDto()` works from the right end of the primary line inward, in this
order. Each step removes what it matched, so the next regex anchors on a
new end-of-string:

1. **Direction** — the trailing whitespace-delimited `Af` / `Bij` token.
   Missing marker is a parse error, never a default.
2. **Settled EUR amount** — now the trailing `[\d.,]+` run. `Af` negates
   it; the statement itself never prints a minus sign, the marker column
   carries the sign.
3. **Native amount + currency** — an optional trailing
   `<amount> <ISO-4217>` pair. Present only on foreign-currency rows.
   When it is present the native pair becomes `amountMinor`/`currency`
   and the EUR figure moves to `settledAmountMinor`/`settledCurrency`;
   when it is absent the settled pair stays `null` and `NormalizeStage`
   mirrors native into settled.
4. **The two date columns** — now at the *left* end: transaction date
   then booking date, each `<day> <month-abbrev>`. The month is matched
   as a bare three-letter run and validated against
   `IcsPdfAdapter::MONTH_ABBREV` afterwards, so an unknown abbreviation
   fails loudly rather than falling through a long inline alternation.
5. **Description** — whatever is left.

## Deriving the year

The transaction line's date columns carry no year. Both dates inherit
the year of the statement header date, which `parseStatementDate()`
recovers from the raw text as a full Dutch month name (`15 februari
2026`); when that line is absent the current calendar year is used.

The one exception: when the transaction month is December and the
booking month is January, the transaction year rolls back by one. That
is the January-statement rollover — a purchase made in late December
that books in the new year.

## Counterparty name

The upstream `Omschrijving` column merges merchant, street, and city into
one free-text field. The only stable terminator is the upper-case alpha-2
country code ICS appends to every description, so
`extractCounterpartyName()` strips that and compacts internal multi-space
runs (so `FingerprintComposer::normalize()` sees a stable shape) — and
stops there. The result can still carry address fragments. That is
accepted: separating merchant from street would need a per-merchant
heuristic, and a wrong split is worse for fingerprint stability than a
consistently over-long name.

## Card-number scrubbing

Two protections, both unconditional:

- The cardholder name is never persisted. `extras.cardholderName` is the
  literal `'STRIPPED'`; only `extras.cardLast4` survives, read from the
  `Uw Card met als laatste vier cijfers <FOUR>` watermark line.
- `scrubCardNumbers()` runs over each per-transaction block before it is
  written into the DTO's `rawPayload['extractedText']`, replacing the
  canonical masked-card placeholder (`****-****-****-XXXX`) and any run
  of 12 or more contiguous digits with
  `IcsPdfAdapter::SCRUB_LITERAL`.

## Statement summary and signs

`parseFourColumnSummary()` matches the four summary labels in document
order (`Vorig openstaand saldo`, `Totaal ontvangen betalingen`, `Totaal
nieuwe uitgaven`, `Nieuw openstaand saldo`) and then four
`€ <amount> Af|Bij` cells; `parseTwoColumnLimitBlock()` does the same for
`Bestedingslimiet` / `Minimaal te betalen bedrag`. Any cell that fails to
parse is simply omitted rather than aborting the statement.

ICS displays opening balance, closing balance, and period charges as
positive amounts with an `Af` marker meaning "owed to ICS". They are
persisted negated, so ledger sign semantics hold across the project
(debits negative, credits positive). Received payments stay positive;
credit limit and minimum due are informational and stay positive.

`statementMetadata()` is assembled in the parse generator's terminator
step. A caller that abandons the iterator early leaves it at `null`.

## Amounts and dates

`IcsAmountParser` reads the Dutch convention — comma is the decimal
separator, period is the thousands separator — and requires exactly two
fractional digits. It strips currency symbols and a closed list of ISO
alpha-3 codes rather than a general `\b[A-Z]{3}\b`, which would
over-consume a three-letter token that is not a currency.

`IcsDateParser` accepts two shapes: `dd-mm-yyyy` (used once the adapter
has resolved a year) and `<day> <Dutch month> <year>` with either the
abbreviated or the full month name. Abbreviations are stored without the
trailing period ICS prints; the parser removes it before lookup.

## What breaks this first

In rough order of likelihood:

- **A label change.** Every anchor in `IcsPdfExtractionMap` is a literal
  Dutch string. Rewording `Vorig openstaand saldo` silently empties the
  statement summary — the parse still succeeds and the rows are still
  right.
- **A new marketing banner** in the transaction table's own vertical
  span that happens to begin with a date and end with `Af` would be
  parsed as a transaction. Adding a pattern to `PAGE_NOISE_PATTERNS` is
  the fix.
- **A merchant whose description legitimately ends in two capitals**
  loses those two characters to the country-code strip.
- **A `pdftotext` version change** to `-layout` spacing. The parser is
  insensitive to column *positions* but does assume one row per line;
  losing that assumption would be a rewrite, not a patch.
