# ASN MT940 — empirical fixture record

`asn-mt940-sample-1.sta` is a **synthesised** ASN MT940 statement. The bank
no longer ships an MT940 download channel on the modern online-banking
surface — only CAMT.053 and CSV are exposed. To keep the hand-rolled MT940
adapter testable end-to-end, the fixture is derived from a curated
subset of `asn-camt053-sample-1.xml` and rendered into ASN's published
MT940 dialect.

Because every counterparty name + IBAN in the source CAMT is already
anonymised, the MT940 file is fully synthetic by construction — no
PII passes through.

## Confirmed format

| Property | Value |
|----------|-------|
| **Dialect** | ASN MT940 (extended `:61:` customer reference, 34 chars vs SWIFT-standard 16) |
| **Encoding** | UTF-8 with `\n` line terminators |
| **End-of-message marker** | trailing `-` on its own line |
| **Statement count** | 1 (single-statement file) |
| **Entry count** | 12 |
| **Booking date range** | 2026-02-02 → 2026-03-10 (cherry-picked across the 3-month CAMT corpus) |
| **Bank-transaction families covered** | RDDT (SEPA direct debit), ICDT/RCDT (transfers), CCRD (card payment), OTHR (cash withdrawal), ACMT (bank charge) — at least one per family the hand-rolled parser needs to recognise |
| **`:86:` structure** | structured `?NN`-prefixed subfields with SEPA narrative inside `?20–?29`, counterparty IBAN in `?31`, counterparty name in `?32` |
| **GVC keywords present** | `SVWZ` (purpose) and `EREF` (end-to-end reference) in narrative |
| **Own IBAN** | `NL57ASNB0123456789` (`:25:` tag) |
| **Opening balance** | `C260202EUR1000,00` (synthetic — running sum recomputed from picked entries) |
| **Closing balance** | matches the running sum (computed at write time; do not edit by hand) |

## Why synthetic and not a real export

ASN's modern online-banking UI offers two statement downloads (CAMT.053 XML
and CSV) but no MT940 endpoint. MT940 remains in scope for this project
(legacy statement periods + interoperability) but the user could not
acquire a real `.sta` file from ASN's customer portal. Two paths were on
the table:

| Option | Tradeoff |
|--------|----------|
| Skip MT940 entirely | The MT940 adapter + cross-format scenarios that depend on `.sta` cannot ship |
| **Synthesise from the anonymised CAMT corpus** (chosen) | Loses one degree of "real wire" realism, but preserves the same anonymised data through three formats, which is exactly what the cross-format dedup tests need |

The fixture-generation script lives at `/tmp/anonymize_phase2.py` (not committed —
the deterministic mapping is recorded below; rerun by hand if a new CAMT
import requires regenerating the MT940).

## Mapping consistency

This fixture, `asn-camt053-sample-1.xml`, and `asn-cross-format/february.csv`

+ `asn-cross-format/february.camt053.xml` are anonymised through **the same
shared mapping**. The same real counterparty resolves to the same synthetic
merchant in every output:

| Real counterparty | Synthetic merchant | Synthetic IBAN |
|-------------------|-------------------|----------------|
| ANWB Energie B.V. Laadpassen | Albert Heijn | NL67BANK0000000019 |
| Stg. Rabobank Pensioenfonds | Tikkie Payments | NL40BANK0000000020 |
| ASR ZIEKTEKOSTEN | Albert Heijn 2 | NL94BANK0000000018 |
| AYVENS | Albert Heijn 3 | NL62BANK0000000012 |
| VCN Verzekeringen | Vattenfall Energie | NL50BANK0000000034 |

The complete 39-entry name map and 34-entry IBAN map are reproducible by
sorting the source counterparty names + IBANs alphabetically and zipping
against the 39-name canonical merchant pool documented in `asn-sample-1.md`.

## ASN `:61:` Tag layout — illustrated

The hand-rolled adapter in `Modules\Ingestion\Internal\Adapters\Asn\AsnMt940Adapter`
must read the **34-char** extended customer-reference variant. Example
from this fixture (line 5):

```
:61:2602050205C11,67NTRF150034222143
```

| Field | Value | Position |
|-------|-------|----------|
| Value date | `260205` (2026-02-05) | 1–6 |
| Entry month / day | `0205` (Feb 5) | 7–10 |
| Status | `C` (credit, positive amountMinor) | 11 |
| Amount | `11,67` (comma-decimal) | 12–17 |
| Transaction-type id | `NTRF` (non-SWIFT transfer) | 18–21 |
| Customer reference (ASN-extended 34 chars) | `150034222143` (preserved per protocol — SEPA EndToEndId) | 22+ |

Status mapping the parser must implement:

| Status | Sign |
|--------|------|
| `C` | credit (positive amountMinor) |
| `D` | debit (negative amountMinor) |
| `RC` | reversal of credit (negative amountMinor) |
| `RD` | reversal of debit (positive amountMinor) |

## ASN `:86:` Tag layout — illustrated

```
:86:100?20SVWZ+NL00XXXXXXXXXXXXXX-Bol.com EREF+20260215-1337416?31NL45BANK0000000027?32Bol.com
```

| Subfield | Meaning | Maps to `SourceTransactionDto` |
|----------|---------|-------------------------------|
| `100` (first 3 chars after `:86:`) | GVC posting code — `100` = SEPA Credit Transfer | rawPayload only |
| `?20–?29` | Purpose / SEPA narrative — concatenate in order, includes embedded GVC keywords | `description` + `sourceRef` (via EREF) |
| `?31` | Counterparty IBAN | `counterpartyIban` |
| `?32–?33` | Counterparty name (continuation) — concatenate | `counterpartyName` |

GVC keywords inside `?20–?29` the parser must promote:

| Code | Behaviour |
|------|-----------|
| `EREF` | Promote to `sourceRef` when non-empty and not literal `NOTPROVIDED` |
| `SVWZ` | Purpose narrative — `description` text |
| `MREF`, `CRED`, `KREF`, `PURP`, `BIC` (trailing space), `ABWA`, `MDAT`, `COAM`/`OAMT` | rawPayload only |
| `IBAN` (when `?31` is absent) | Counterparty IBAN fallback |

## What this fixture exercises

| Test surface | Path |
|--------------|------|
| Lexical scanner happy path | `Modules/Ingestion/tests/Unit/AsnMt940LexerTest.php` |
| `:61:` parser (ASN 34-char variant) | `Modules/Ingestion/tests/Unit/AsnMt940Tag61ParserTest.php` |
| `:86:` GVC structured-subfield decoder | `Modules/Ingestion/tests/Unit/AsnMt940Tag86ParserTest.php` |
| MT940 counterparty pre-normalisation (strip GVC codes before fingerprinting) | `Modules/Ingestion/tests/Unit/AsnMt940CounterpartyCleanerTest.php` |
| End-to-end adapter snapshot | `Modules/Ingestion/tests/Unit/AsnMt940AdapterTest.php` |
| Wizard import happy path | `Modules/Import/tests/Feature/AsnMt940ImportTest.php` |

## Caveats

+ Opening and closing balances are synthetic (running sum recomputed at
  write time). The `statement_summaries` table will record these values
  per import_run even though they don't match the originating CAMT's
  3-month balance window — that is correct behaviour for a single-statement
  MT940 import.
+ The `:20:` file reference is the static literal `ASN-SYN-MT940-001`;
  real ASN exports use a bank-generated reference per export. This does
  not affect parser tests because `:20:` content is opaque per the
  protocol.
+ Transaction-type ids (`NTRF`, `NDDT`, `NMSC`, `SCHG`) are derived from
  the CAMT `BkTxCd/Domn/Fmly/Cd` family using the conventional ASN MT940
  family-to-id mapping. A real ASN export may emit slightly different
  `:61:` ids in edge cases; the parser must treat the 4-char id as
  **informational only** (it is shadowed by the `:86:` GVC code).
