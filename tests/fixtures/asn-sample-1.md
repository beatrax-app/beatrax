# ASN CSV — empirical fixture record

`asn-sample-1.csv` is an anonymized real ASN Online Bankieren CSV export. It is
the gold fixture that drives `PositionalCsvAdapter`'s tests, the snapshot test,
and the `IdempotencyContractTest` Pest dataset. The layout below is what
`CsvPresetRegistry::ASN` encodes: a bank's column shape is preset data, not a
class of its own.

The unanonymized source is **not committed** and **must never be committed** —
it lived only in the contributor's `~/Downloads/` long enough to be processed
by `tests/fixtures/anonymize_asn.py` (see the project history if the script is
re-added later).

Its dates are absolute (2026-02 to 2026-04) and stay that way: several tests
assert on specific rows, amounts, IBANs and the file's own `sha256`. To get this
statement inside the date windows the product actually reads — for hand-testing
a build — generate a rebased copy rather than editing these bytes:
[`.docs/local_development/rebasing-a-statement-fixture.md`](../../.docs/local_development/rebasing-a-statement-fixture.md).

## Confirmed format

| Property | Value |
|----------|-------|
| **Delimiter** | comma `,` |
| **Encoding (source)** | `us-ascii` (subset of UTF-8 — `file -bI` reports `text/csv; charset=us-ascii`) |
| **Encoding (committed fixture)** | UTF-8 (synthetic merchant names include diacritics, e.g. `Café Plein`) |
| **BOM** | none |
| **Header row** | yes — column names on line 1, data starts on line 2 |
| **Column count** | **20** (community-reported docs from 2018–2021 listed 17–18; 2026 export adds `Afschriftnummer` and `Categorie`) |
| **Date format** | `dd-mm-yyyy` |
| **Decimal separator** | period `.` |
| **Amount sign** | leading `-` for debits, no leading sign for credits, leading `+` not observed in this export |
| **Multi-line cells** | not observed in this fixture; description column may contain literal `\r` sequences per ASN's historical docs |

## Column map

Indices are zero-based, matching the row array produced by `league/csv` after
its default `array_values` step.

| Index | Header (NL) | Field | Notes |
|-------|-------------|-------|-------|
| 0 | `Datum` | posted date | `dd-mm-yyyy` |
| 1 | `Je rekening` | own IBAN | always the placeholder `NL57ASNB0123456789` in this fixture |
| 2 | `Van / naar` | counterparty IBAN | placeholders shaped `NLccBANK00000000NN` (distinct per real counterparty, two-digit check segment recomputed so the IBAN passes ISO 7064 mod-97 validation); self-transfers use `NL91ASNB9876543210` |
| 3 | `Naam` | counterparty name | synthetic merchant from a 18-entry pool |
| 4 | `Adres` | counterparty street address | blanked |
| 5 | `Postcode` | counterparty postal code | blanked |
| 6 | `Woonplaats` | counterparty city | blanked |
| 7 | `Valuta saldo` | saldo currency | `EUR` |
| 8 | `Saldo voor boeking` | running balance before this entry | period-decimal, signed |
| 9 | `Valuta` | mutation currency | `EUR` |
| 10 | `Bedrag bij / af` | signed amount (`-` for debits) | period-decimal |
| 11 | `Verwerkingsdatum` | journal / processing date | `dd-mm-yyyy` |
| 12 | `Valutadatum` | value date | `dd-mm-yyyy` |
| 13 | `Code` | internal transaction code | e.g. `BEA`, `OVB`, `IDB` |
| 14 | `Type` | global transaction type | e.g. `iDEAL-betaling`, `SEPA Overboeking`, `Online betaling` |
| 15 | `Volgnummer` | sequence number → `source_ref` | unique within an account |
| 16 | `Betalingskenmerk` | payment reference / mandate id | IBAN-like substrings and long digit runs scrubbed |
| 17 | `Omschrijving` | free-text description | IBAN-like substrings and long digit runs scrubbed |
| 18 | `Afschriftnummer` | statement number | unchanged |
| 19 | `Categorie` | ASN-side category label | unchanged (typically empty) |

## Differences from the prior assumed layout

Three of four values on the then-`AsnCsvHeaderProfile` required correction.
They now live on the `PositionalCsvPreset` the registry returns for
`CsvPresetRegistry::ASN`:

| Constant | Assumed | Actual | Delta |
|----------|---------|--------|-------|
| `DELIMITER` | `,` | `,` | — |
| `HAS_HEADER` | `false` | `true` | the 2026 export ships a header row |
| `SOURCE_ENCODING` | `windows-1252` | `us-ascii` (UTF-8 compatible) | bank moved to ASCII; CharsetConverter still safe to use for legacy exports |
| `EXPECTED_COLUMN_COUNT` | `18` | `20` | `Afschriftnummer` (18) and `Categorie` (19) added |

Two extra columns (`Afschriftnummer`, `Categorie`) sit at the end of every row;
none of the existing column positions shifted, so the historical layout from
the open-source converters is still correct for indices 0–17.

## Anonymization protocol

1. **Own IBAN** — every occurrence replaced with `NL57ASNB0123456789`. This is
   the placeholder seeded by `tests/TestCase::seedFixtureUserAndAccount()` so
   the `EloquentAccountResolver` returns `Known(accountId)` on the first parse
   without prompting.
2. **Counterparty IBAN** — each distinct real IBAN maps deterministically to
   a placeholder shaped `NLccBANK00000000NN` (zero-padded counter NN, with
   the two-digit check segment cc recomputed so the IBAN passes ISO 7064
   mod-97 validation — required because the CAMT.053 parser validates
   check digits eagerly at unmarshal). One real counterparty = one
   placeholder, so duplicate-detection logic still has variance.
3. **Self-transfers** (counterparty IBAN equals one of the user's own ASN
   accounts) — placeholder `NL91ASNB9876543210`, name forced to
   `Eigen Spaarrekening`.
4. **Counterparty name** — synthetic merchant from a pool of 18 names. The pool
   includes `Café Plein` (diacritic) to exercise the diacritic round-trip the
   normaliser handles; a post-anonymization step forces at least one row to
   contain it regardless of pool selection.
5. **Address / postcode / city** — blanked.
6. **Payment reference + description** — IBAN-like substrings replaced with
   `NL00XXXX0000000000`; any run of 8+ digits replaced with `X` of equal
   length. Format and length preserved, semantic content destroyed.
7. **Amounts, balances, dates, currencies, codes, types, sequence numbers,
   statement numbers, categories** — left as-is. The parser cares about
   format, not values.

## Derived overlap fixtures

| File | Months covered | Row count | Purpose |
|------|----------------|-----------|---------|
| `asn-sample-1.csv` | 2026-02, 2026-03, 2026-04 | 229 | Snapshot test, full-format coverage |
| `asn-month-a.csv` | 2026-02 | 72 | "Single month" idempotency baseline |
| `asn-month-a-and-b.csv` | 2026-02 + 2026-03 | 143 | Overlapping window: re-importing this after `asn-month-a.csv` must produce zero new rows for February entries and append March entries (idempotency contract) |

Every row in `asn-month-a.csv` also exists in `asn-month-a-and-b.csv` — verified
by `diff` against sorted inputs. `asn-month-a-and-b.csv` has strictly more rows
than `asn-month-a.csv`.
