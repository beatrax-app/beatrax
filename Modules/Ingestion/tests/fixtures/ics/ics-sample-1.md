# ICS PDF — empirical fixture record

`ics-sample-1.txt` is the `pdftotext -layout` extraction of a real Mijn ICS
consumer-portal monthly statement (statement period 2026-01-15 → 2026-02-15),
anonymised in-repo per the extract-then-redact-text protocol. The raw
`.pdf` lives outside the git tree under `local/ics/raw-ics-statement.pdf`
(gitignored); only the redacted plain-UTF-8 text fixture is committed.

The companion redaction script lives at `scripts/anonymize_ics_text.php`
and is re-runnable on any future extraction.

## Extraction command

```sh
pdftotext -layout -enc UTF-8 -eol unix -nopgbrk \
    local/ics/raw-ics-statement.pdf \
    local/ics/raw-extracted.txt
```

Flags:

| Flag | Purpose |
|------|---------|
| `-layout` | Preserve the statement's tabular column structure via whitespace padding — load-bearing for the downstream regex-anchor parser. |
| `-enc UTF-8` | Force UTF-8 output (the source has `€`, `é`, ligatures e.g. `ﬁ`). |
| `-eol unix` | LF line terminators so PHP regexes anchor cleanly. |
| `-nopgbrk` | Strip form-feed (`\f`) between pages so line-anchored per-page-noise regexes work. |

The extraction produces a single 102-line UTF-8 text dump for this statement.

## Anonymisation protocol

Implemented in `scripts/anonymize_ics_text.php`. Eight regex-driven passes
applied in order:

1. **Card-last-four watermark** — `Uw Card met als laatste vier cijfers NNNN`
   → `Uw Card met als laatste vier cijfers XXXX (****-****-****-XXXX)`.
   The trailing parenthesised group is a synthetic full-card placeholder
   so downstream tests can grep for the canonical four-group form even
   though the real PDF only renders the last-four.
2. **Spaced IBAN** — `NL75 ABNA 0844 9970 56` → `NL95 BANK 0000 0000 00`
   (spacing preserved; appears in body paragraphs that print the
   payment-deposit IBAN).
3. **Card-number runs** — any 12+ contiguous digits with optional
   space/hyphen separators → `****-****-****-XXXX`. No matches in this
   statement (ICS only renders the last-four).
4. **ICS klantnummer** — standalone 11-digit run → `KLANTNUMMER`
   (non-digit placeholder so the phone regex below cannot cascade-match
   it together with neighbouring page-number columns).
5. **Compact IBAN** — `NL75ABNA0844997056` → `NL95BANK0000000000`
   (the project-wide deterministic anonymised-IBAN placeholder).
6. **Cardholder name** — heuristic match on a standalone line of all-caps
   initials + surname → `KAARTHOUDER` (Dutch literal, all caps).
   Conservative: requires the line to consist of nothing but the name
   so statement-summary headings remain untouched.
7. **Email addresses** — none in this statement.
8. **NL-style phone numbers anchored on `Telefoon` / `Tel.`** —
   `Telefoon 020 - 6 600 600` → `Telefoon +31 6 0000 0000`. Anchored on
   the keyword to avoid cascade-matching across statement-column
   whitespace.

Preserved verbatim (load-bearing for the parser's empirical tests):

- All transaction dates (`23 jan.`, `01 feb.`, `15 januari 2026`).
- All amounts (`€ 606,96`, `50,00 USD`, `43,71`, `1.416,50`).
- All currency codes and exchange-rate decimals (`USD`, `GBP`, `1,14390`).
- All merchant strings, transaction-type narrative, country codes
  (`AUGMENT CODE`, `GELDMAAT ROELANTDREEF 239`, `Audible UK`, `US`,
  `NL`, `LU`).
- All Dutch statement-summary tokens (see "Statement summary tokens"
  below).

The redaction is **idempotent**: every replacement is regex-driven on
input shape, not state, so re-running on a fresh extraction is safe.

## Statement layout

| Property | Value |
|----------|-------|
| **Page count** | 2 |
| **Total lines** | 102 (LF-terminated) |
| **Encoding** | UTF-8 |
| **Page 1 starts at line** | 1 |
| **Page 2 starts at line** | ~67 (statement-summary header repeats at line 70) |
| **Transactions on page 1** | 23 rows (lines 19, 22–45) |
| **Transactions on page 2** | 14 rows (lines 79–94) |
| **FX rows present** | 3 (one each of `USD`, `GBP`, `USD` on lines 31, 34, 36) |

The transactions-table region on page 1 spans lines 17–45 (column-header
block at lines 17–18, body at 19–45). On page 2 the same shape repeats
at lines 77–94. The page break is not marked by a form-feed character
(stripped via `-nopgbrk`) but by repetition of the statement-summary
header block (`Datum / ICS-klantnummer / Volgnummer / Bladnummer`).

## Anchor tokens

The transactions-table region opens with a TWO-LINE column header
(verbatim, with surrounding context):

```
17:  Datum      Datum                   Omschrijving                                  Bedrag in                    Bedrag
18:  transactie boeking                                                                vreemde valuta               in euro's
```

The two-line shape (header line 17 + sub-header line 18) is load-bearing
— `Datum` appears twice on line 17 (transactiedatum + boekdatum columns)
and `Bedrag in` appears twice on line 17 (vreemde-valuta + euro
columns). The parser's anchor must NOT key on `Datum` alone.

A robust anchor for `IcsPdfExtractionMap` is the literal string
`transactie boeking` on line N+1 (page 1 = line 18, page 2 = line 78):
this substring appears exactly twice in the document and is unique to
the table-header region.

The transactions-table region closes at the FIRST blank line after the
header — line 46 on page 1, line 95 (footer banner) on page 2.

## Per-page noise patterns

| Pattern | Regex | Notes |
|---------|-------|-------|
| Issuer banner (lines 1–5) | (none — page-1 only) | Appears only on the cover page; the parser strips lines 1–9 of the document as cover-page chrome. |
| Statement-summary header (recurs on every page) | `/^\s*Datum\s+ICS-klantnummer\s+Volgnummer\s+Bladnummer\s*$/` | Followed by `15 februari 2026 KLANTNUMMER 2 1 van 2` on the next line. |
| Card-watermark banner (page-1 only, lines 20–21) | (none repeating) | `Uw Card met als laatste vier cijfers …` + cardholder line. Page-2 does NOT repeat the card watermark in this statement. |
| Page-number / sheet-number footer | NONE — there is no `Pagina X van Y` line. The page index lives at the end of the statement-summary header line as `1 van 2` / `2 van 2`. | No standalone page-footer regex is needed for this statement layout. |
| Apple Pay marketing banner | `/Nu beschikbaar: Apple Pay!/` | Repeats on every page near the bottom of the transactions region. |
| Depositogarantiestelsel disclaimer | `/Dit product valt onder het depositogarantiestelsel/` | Appears once per page near the bottom (lines 69, 102). |
| Body paragraphs (`Het minimaal te betalen bedrag …`, `Uw betalingen aan International Card Services BV …`) | (anchored on first words) | Appear once per page (page 1 at lines 55–58, page 2 at lines 96–97). The parser strips these by anchoring on the leading literals. |
| Bestedingslimiet / Minimaal te betalen bedrag block (lines 63–64, 99–101) | `/^\s*Bestedingslimiet\s+Minimaal te betalen bedrag\s*$/` | Two-column credit-limit block — appears once per page. |

The parser pipeline is therefore: (1) read extracted .txt; (2) strip
known per-page noise via the regexes above; (3) locate the
transactions-table region by the `transactie boeking` anchor; (4)
iterate rows until the next blank line.

## FX-row visual shape

Two-line block, confirmed empirically. The native-currency amount sits
on the same row as the merchant; the conversion rate sits on the
immediately-following row in a `Wisselkoers <CURRENCY> <rate>` form.
Verbatim example block from the redacted fixture (lines 31–32):

```
 23 jan.         24 jan.            AUGMENT CODE                                      WWW.AUGMENTCO                   US           50,00 USD                          43,71    Af
                                    Wisselkoers USD                                   1,14390
```

Three FX rows are present in this statement (lines 31/32, 34/35, 36/37).
All three follow the same two-line shape. The conversion-rate line is
visually aligned to the `Omschrijving` column (no merchant name, only
the `Wisselkoers <CURRENCY> <rate>` token sequence). The parser
recognises an FX row by:

1. A native-currency suffix in the "Bedrag in vreemde valuta" column
   (e.g. `50,00 USD`).
2. The next line beginning with the literal `Wisselkoers` plus the
   same currency code.

The settled-EUR amount is in the rightmost data column on the merchant
row (`43,71` in the example above).

`fx_rate_used` is derived from `settled_amount_minor / amount_minor` at
BigDecimal scale 8 with HALF_UP rounding. The `Wisselkoers` value
(`1.14390` here) is the effective rate the ICS portal displays; the
markup is rolled into the settled amount and not separately itemised.
The parser populates `rawPayload.fxRateDisplayed` from the
`Wisselkoers` line so a future surface can recover the displayed rate
without re-parsing the PDF.

## source_ref availability

**No stable per-transaction identifier is present in the extracted text.**
The transaction lines carry only:

- transactiedatum
- boekdatum
- merchant name (free-form, can contain payment-processor tokens like
  `PAYPAL *JAGEX LTD 35314369001 GB`)
- merchant location (city + country)
- native-currency amount (FX rows only)
- settled-EUR amount
- direction marker (`Af` / `Bij`)

There is NO authorisation code, slip number, or transaction-reference
token. The statement-level `Volgnummer 2` on line 11 is the statement
sequence number (this is the 2nd statement of the contract), not a
per-transaction reference.

**Disposition:** `source_ref` is `NULL` for every ICS PDF row. The v3
fingerprint tuple (`account_id, booked_at, amount_minor, currency,
merchant_cleaned`) is the only dedup anchor — same posture as MT940
entries with a missing EREF.

## Markup separability

**Not separately itemised in the extracted text.** The `Wisselkoers
<CURRENCY> <rate>` line shows the effective (post-markup) rate only;
there is no per-transaction `+ X% markup` footer, no per-statement
markup table, and no markup-fee row. The fee column on each FX row
contains the settled-EUR amount, not a separate markup figure.

**Disposition:** the `Wisselkoers` value rendered on the row IS the
effective rate ICS charged, with the markup rolled into the settled
amount. Surfacing the markup separately would require an external
market-rate provider — out of scope for the local-only product.

The per-transaction extracted-text block stored in `rawPayload`
preserves both lines, so the markup detail is recoverable for any
future enhancement without re-import.

## Statement summary tokens

The Mijn ICS consumer portal uses revolving-credit nomenclature on the
statement-summary header. The six empirically-confirmed tokens are:

| Field on `StatementSummaryData` | Empirical Dutch token | Verbatim line (page 1) | Line offset |
|---------------------------------|----------------------|------------------------|-------------|
| Opening balance | `Vorig openstaand saldo` | `Vorig openstaand saldo   Totaal ontvangen betalingen   Totaal nieuwe uitgaven   Nieuw openstaand saldo` | 12 (column-header line) |
| Payments received | `Totaal ontvangen betalingen` | (column-header line 12; value line 13) | 12 |
| New charges | `Totaal nieuwe uitgaven` | (column-header line 12; value line 13) | 12 |
| Closing balance | `Nieuw openstaand saldo` | (column-header line 12; value line 13) | 12 |
| Credit limit | `Bestedingslimiet` | `Bestedingslimiet   Minimaal te betalen bedrag` | 63 |
| Minimum payment due | `Minimaal te betalen bedrag` | (column-header line 63; value line 64) | 63 |
| Statement date | (header line 11) | `15 februari 2026   KLANTNUMMER   2   1 van 2` | 11 |
| Statement sequence | `Volgnummer` (header line 10) | column value `2` on line 11 | 11 |

There is **no `Periode` field**: the statement does not explicitly print
the period start/end dates anywhere in the extracted text. The earliest
transaction date (line 19 / 22) and the latest transaction date (line
94) are the only positional cues for the period boundaries, so the
period must be derived.

The adapter derives it from the min and max **transactiedatum**
(`posted_at`) across the parsed rows. Not the boekdatum: ICS books a
charge on or after the day the card was used — `15 jan.` books on
`16 jan.` on line 22 of this very fixture — and every reader of the
stored period tests membership on `posted_at`, so a boekdatum-derived
period opens after the earliest charge it bills and the statement can
never settle. See [a period derived from one column and tested on
another](../../../../../.docs/conventions/invariants-from-shipped-failures.md#a-period-derived-from-one-column-and-tested-on-another).

The body paragraph on line 96 (`Uw betalingen aan International Card
Services BV zijn bijgewerkt tot 15 februari 2026`) states a
period-end-ish date and is deliberately not parsed: it is one boundary
out of two, phrased as prose, and a period whose two ends came from
different places is the defect above wearing a different hat.

The values on line 13 (page-1 summary) are written as a four-column row
with `Af` / `Bij` direction markers:

```
 € 606,96                             Af     € 606,96                 Bij                € 1.416,50                           Af     € 1.416,50             Af
```

The direction markers (`Af` for debits, `Bij` for credits) apply to
each summary column — opening balance is `Af` (a debit balance, i.e.
the user owes ICS), payments received is `Bij`, new charges is `Af`,
closing balance is `Af`. The parser handles the four-column horizontal
layout via a single column-aware regex that captures the four amounts
in their fixed order.

## Dutch date formats

Two date formats present:

| Format | Example (verbatim) | Where it appears |
|--------|-------------------|------------------|
| `dd MMM.` (abbreviated month with trailing period) | `23 jan.`, `01 feb.`, `15 jan.` | Transaction lines (transactiedatum + boekdatum columns) |
| `dd MMMM YYYY` (full month + year) | `15 februari 2026`, `8 maart 2026` | Statement-header date (line 11), body-paragraph due-date (line 57) |

Dutch month abbreviations observed in transaction lines: `jan.`, `feb.`
The canonical full set is `jan feb mrt apr mei jun jul aug sep okt nov
dec` (PHP locale `nl_NL`). All twelve abbreviations are supported by
`IcsDateParser` — this statement covers two months so only the first
two are exercised in the fixture; the parser must still handle all
twelve.

Note: transactiedatum / boekdatum on the transaction lines have NO
year — the year must be derived from the statement period. The body
paragraph on line 57 shows the statement issue date `8 maart 2026`
(due-date for payment), and the header date is `15 februari 2026`.
The parser infers the year by rolling from the statement-header date
backwards: any transactie/boek date that resolves to a future month
relative to the header date belongs to the prior year.

## Dutch amount formats

Single format observed:

- **Decimal separator:** comma `,` (e.g. `2,40`, `50,00`, `1,14390`)
- **Thousands separator:** period `.` (e.g. `1.416,50`, `2.500,00`)
- **Currency symbol position (EUR):** prefix with a space —
  `€ 606,96`, `€ 1.416,50`. Always with `€` (U+20AC + space) on
  summary lines.
- **Currency symbol position (non-EUR):** ISO 4217 code as a suffix —
  `50,00 USD`, `8,99 GBP`, `6,00 USD`. No glyph (`$`, `£`, `¥`) appears
  anywhere; only ISO codes.
- **Sign:** debits never carry a leading `-`. The direction marker
  (`Af` / `Bij`) appears in a separate column at the end of the row.
  `IcsAmountParser` must rely on the column marker, NOT a signed-amount
  prefix.

`IcsAmountParser` must:

1. Strip the `€` prefix if present (the glyph set comes from
   `Money::SYMBOLS`, so it is not a second list of currency signs).
2. Remove thousands `.` separators.
3. Require a `,` decimal with exactly two digits after it — the one
   convention observed above. A looser grammar would read a `6,06` that
   lost its comma as six hundred euros.
4. Hand the remaining `<digits>,<2 digits>` to
   `MoneyInput::tryToMinor()` for `amount_minor`, rather than doing the
   ×100 arithmetic here.

No `setLocale()` mutation in the parser.

## Masked-card metadata schema

The adapter writes the following keys into
`statement_summaries.extras` (JSON, archive-only — never read by the
dashboard or transactions-list queries):

```json
{
  "issuer": "Mastercard",
  "cardLast4": "XXXX",
  "cardholderName": "STRIPPED"
}
```

- `issuer` — derived from the issuer banner line 1 (`International
  Card Services BV` → constant `"Mastercard"`, the only product ICS
  Cards consumer issues today).
- `cardLast4` — the four digits from the `Uw Card met als laatste
  vier cijfers NNNN` line (line 20). Captured from the **raw PDF at
  parse-time**, NOT from the committed fixture. The committed fixture
  carries the literal `XXXX` placeholder; the production adapter
  reads the real last-four from the user's actual PDF.
- `cardholderName` — always the literal string `"STRIPPED"`. The
  adapter drops the cardholder name at the boundary regardless of
  the source content; the redacted fixture's `KAARTHOUDER` literal
  is only present so the parser's name-stripping pass can be
  black-box tested.

## Layout notes

### Page-footer pattern

The page index renders as `1 van 2` / `2 van 2` at the right end of
the statement-summary header line (lines 11 / 71); the statement has
no standalone `Pagina X van Y` footer. The parser's per-page noise
pass anchors on the wider statement-summary header pattern.

### Card-number rendering

The Mijn ICS consumer-portal statement renders only the card last-four
on a single banner line (`Uw Card met als laatste vier cijfers NNNN`)
— there is no full-PAN watermark anywhere in the body. The redaction
script still injects a synthetic `****-****-****-XXXX` placeholder on
the same line so the canonical four-group placeholder is present in
the fixture for grep-based contract tests.
