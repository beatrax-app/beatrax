# Cross-format ASN fixture corpus

This directory holds an aligned pair of ASN exports covering **the same
calendar period and the same real-world transactions** through two
formats. It exists to drive the cross-format dedup acceptance tests in
`Modules/Import/tests/Feature/CrossFormatDedupTest.php`.

| File | Format | Entries | Period | Source |
|------|--------|---------|--------|--------|
| `february.csv` | ASN CSV (20-column 2026 export) | 72 | 2026-02-02 → 2026-02-27 | Real ASN export anonymised through the shared mapping |
| `february.camt053.xml` | CAMT.053.001.02 (ISO 20022) | 72 | 2026-02-02 → 2026-02-27 | Real ASN export anonymised through the shared mapping |
| `february.mt940.sta` | ASN MT940 | _absent_ | _n/a_ | ASN no longer offers MT940 downloads — no real export available |

## Why the MT940 cross-format file is missing

ASN's modern online-banking surface only ships CAMT.053 and CSV downloads.
A real same-period `.sta` could not be acquired. `asn-mt940-sample-1.sta`
in the parent directory is a hand-synthesised MT940 derived from the
3-month anonymised CAMT — it suffices for adapter unit/snapshot tests but
**must not be used as a cross-format pair** because the entries are a
curated subset rather than the same 72 February rows.

Consequence for `CrossFormatDedupTest`: the `csv_then_camt053` and
`camt053_then_csv` scenarios run on this directory; the `mt940_after_csv`
and `mt940_then_camt053` scenarios are not written at all. They were carried
for a while as tests that skipped on the missing fixture, which on every green
run read as cross-format MT940 coverage that had in fact never executed once.
This file is the record instead. They come back — with a real same-period
`.sta` beside the two files above — when ASN re-introduces MT940 downloads or
a third-party export tool is added to the project.

## Same-period guarantee

Both `february.csv` and `february.camt053.xml` derive from **two
back-to-back downloads** taken within a few minutes of each other from
the same ASN account, then anonymised through the same shared mapping.
Entry-for-entry alignment was verified by sorting both files on
`(booking_date, amount, counterparty_name)` and confirming identical
sequences.

The first 10 transactions are reproduced here as a smoke check — the
CSV's column 11 (signed amount) negates the CAMT's `CdtDbtInd=DBIT`
entries:

| # | Date | CAMT amount + ind | CSV amount | Counterparty (synth) |
|---|------|-------------------|------------|---------------------|
| 1 | 2026-02-02 | 3.99 / DBIT | -3.99 | Albert Heijn |
| 2 | 2026-02-02 | 1154.13 / DBIT | -1154.13 | Tikkie Payments |
| 3 | 2026-02-02 | 72.87 / DBIT | -72.87 | Tikkie Payments |
| 4 | 2026-02-02 | 178.50 / DBIT | -178.50 | Albert Heijn 2 |
| 5 | 2026-02-02 | 58.17 / DBIT | -58.17 | Vattenfall Energie |
| 6 | 2026-02-02 | 419.00 / DBIT | -419.00 | Albert Heijn 3 |
| 7 | 2026-02-03 | 8.10 / DBIT | -8.10 | Werkgever B.V. |
| 8 | 2026-02-03 | 20.50 / DBIT | -20.50 | (card payment, empty counterparty) |
| 9 | 2026-02-05 | 0.98 / DBIT | -0.98 | (bank charge) |
| 10 | 2026-02-05 | 11.67 / CRDT | 11.67 | Coolblue 2 |

## Anonymisation protocol

Same as `asn-sample-1.md` (the canonical CSV fixture protocol) with the
free-text extensions for `<Ustrd>` / `<AddtlNtryInf>`. The mapping is built once
from the union of names + IBANs across all four output files (3-month
CAMT, Feb CAMT, Feb CSV, synthesised MT940) and applied consistently
to each.

| Field | Action |
|-------|--------|
| Own IBAN | Replaced with `NL57ASNB0123456789` everywhere |
| Counterparty IBAN | Maps deterministically to `NLccBANK00000000NN` (one per real IBAN; the two-digit check segment cc is recomputed per placeholder so every IBAN passes ISO 7064 mod-97 validation) |
| Counterparty name | Maps deterministically to a 39-entry synthetic merchant pool |
| `<Ustrd>` + `<AddtlNtryInf>` + CSV `Omschrijving` + CSV `Betalingskenmerk` | Cascade-replace residual names → synth; IBAN-like substrings → `NL00XXXX0000000000`; 8+ digit runs → `X×len`; PII denylist for personal names, the user's address, employer-specific pension fund, user-owned domain, identifying neighbourhood-specific retailers |
| Amounts / balances / dates / currencies / sequence numbers / SEPA reference IDs | Preserved structurally real |

## What this corpus exercises

| Scenario | Test | What it asserts |
|----------|------|----------------|
| Import CSV first → import CAMT second | `CrossFormatDedupTest::csv_then_camt053` | Zero duplicates; existing rows get `enriched_from` JSON appended with the CAMT format + run id; `source_ref` upgrades from CSV value to CAMT `EndToEndId` per the source-ref ranking contract |
| Import CAMT first → import CSV second | `CrossFormatDedupTest::camt053_then_csv` | Zero duplicates; existing rows stay; CSV import marks them SKIP (CSV `source_ref` is strictly weaker than CAMT `EndToEndId` per the source-ref ranking contract) |
| Idempotent re-import | `CrossFormatDedupTest::same_format_replay` | Re-uploading either format produces zero new rows and zero new `enriched_from` entries |
| Fingerprint v3 stability | `IdempotencyContractTest::cross_format_pair_fingerprints_match` | For every row in `february.csv`, an entry exists in `february.camt053.xml` with the **same** v3 fingerprint (no `source_ref` in the v3 fingerprint tuple) |

## Caveats

- Two minor categories in the CSV (`Verzekeringen`, `Pensioen`,
  `Boodschappen`, `Vakantie`, `Eten & drinken`, …) survive the
  anonymisation unchanged because they're ASN UI labels, not PII. The
  parser stores them on `SourceTransactionDto::$rawPayload['csv_category']`
  but they do not feed into the fingerprint.
- The CSV file uses `\r\n` line terminators on disk (Excel-compatible)
  matching the live ASN export. `league/csv` reads both transparently.
- The CAMT file's `Stmt/Bal[OPBD]` and `Stmt/Bal[CLBD]` reflect the
  **real** February statement balances, not synthetic ones, because the
  protocol preserves all balance / amount values verbatim. The MT940
  fixture (parent directory) is different — its balances are
  recalculated because the entry subset doesn't span a full statement
  period.
