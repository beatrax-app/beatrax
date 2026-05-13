# ASN CAMT.053 — empirical fixture record

`asn-camt053-sample-1.xml` is an anonymised real ASN Online Bankieren CAMT.053
export. It is the gold fixture that drives `AsnCamt053AdapterTest`'s snapshot
test and the cross-format dedup tests that consume the same calendar period
through more than one format.

The unanonymised source is **not committed** and **must never be committed** —
it lived only in the contributor's `~/Downloads/` long enough to be processed
by the deterministic anonymiser before being deleted.

## Confirmed format

| Property | Value |
|----------|-------|
| **ISO 20022 schema** | `urn:iso:std:iso:20022:tech:xsd:camt.053.001.02` (sub-version 001.02) |
| **Encoding** | UTF-8 with `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` |
| **Default namespace** | bound to the empty prefix on `<Document>` |
| **Statement count** | 1 (`<BkToCstmrStmt>/<Stmt>` once) |
| **Entry count** | 229 (`<Ntry>`) — 213 debit + 16 credit |
| **Booking date range** | 2026-02-02 → 2026-04-30 (3 statement months, single export) |
| **Bank-transaction families observed** | `RDDT` (109 — SEPA direct debits), `CCRD` (84 — card payments), `ICDT` (13 — issued credit transfers), `RCDT` (4 — received credit transfers), `RRCT` (12), `MDOP` (6), `ACMT` (6), `OTHR` (6), `IRCT` (1) |
| **Opening balance (OPBD)** | `2158.91 EUR` at 2026-02-01 |
| **Closing balance (CLBD)** | `801.35 EUR` at 2026-04-30 |
| **Own IBAN (anonymised)** | `NL57ASNB0123456789` (canonical placeholder, shared with the CSV fixture) |
| **BIC** | `ASNBNL21` (preserved — public BIC, not PII) |

## Why `001.02` and not `001.08`

ASN's CAMT.053 download endpoint currently serves the older `001.02`
sub-version of the schema (`xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"`).
An earlier expectation was `001.08`; the live empirical export contradicts
that assumption. `genkgo/camt 2.10` supports all three published sub-versions
(`001.02`, `001.03`, `001.08`), so the adapter pins detection on the
`xmlns` URI rather than assuming the newest variant.

## Anonymisation protocol

Applied verbatim from `asn-sample-1.md` (the canonical CSV fixture protocol),
with three CAMT-specific additions for elements that have no CSV equivalent.

1. **Own IBAN** — every `<Stmt>/<Acct>/<Id>/<IBAN>` replaced with
   `NL57ASNB0123456789`. The placeholder seeded by
   `tests/TestCase::seedFixtureUserAndAccount()` is unchanged so
   `EloquentAccountResolver` returns `Known(accountId)` on first parse.
2. **Counterparty IBAN** — each of the 34 distinct real IBANs maps
   deterministically to a placeholder shaped `NLccBANK00000000NN`
   (zero-padded counter NN, with the two-digit check segment cc recomputed
   so the IBAN passes ISO 7064 mod-97 validation — required because the
   CAMT.053 parser validates check digits eagerly at unmarshal time, sorted
   alphabetically by source IBAN). One real counterparty = one placeholder,
   so duplicate-detection logic still has variance. 139 element-level
   replacements landed across `<CdtrAcct>/<Id>/<IBAN>` and
   `<DbtrAcct>/<Id>/<IBAN>`.
3. **Counterparty name** — 39 distinct real
   `<RltdPties>/<Cdtr>/<Nm>` + `<Dbtr>/<Nm>` (plus the `Ultmt*` siblings)
   map alphabetically to the canonical 39-entry synthetic merchant pool
   shared with the CSV fixture. 142 element-level replacements applied.
4. **Free-text fields** (`<Ustrd>`, `<AddtlNtryInf>`, `<Strd>/<CdtrRefInf>/<Ref>`):
   - Cascade-replace any residual counterparty name that leaks into the
     free text (sorted by length desc so longer names win over substrings).
   - IBAN-like substrings → `NL00XXXX0000000000`.
   - Runs of 8+ digits → `X` of equal length (format preserved, semantic
     content destroyed).
   - Explicit denylist scrub for: personal names (`Verheij`, `Hoeven`,
     `Tongeren`, `Reitsma`, `Kasperova`, `Filekova`, `Snoek`), the user's
     street (`Gerbrandyhof`) + postcode (`3515 HB`) + city (`Utrecht` →
     `Amsterdam`), the employer's pension fund (`STICHTING RABOBANK
     PENSIOENFONDS` → `Stichting Pensioenfonds`), the user-owned domain
     (`nightworks.io` → `redacted-domain.example`), specific Utrecht
     retailers/locations (`Yamie Pastabar`, `Stichting Museon`,
     `S-GRAVENH`), and the lender brand (`Lender & Spender` → `Geldlener`).
   - 294 free-text element replacements applied across `<Ustrd>` and
     `<AddtlNtryInf>`.
5. **Preserved structurally real** (per protocol — these carry no PII and
   tests rely on the format):
   - All amounts, balances, currencies (`EUR`), and dates.
   - All Bank-Transaction-Code domain/family/sub-family codes.
   - All SEPA reference IDs: `MsgId`, `NtryRef`, `AcctSvcrRef`,
     `EndToEndId`, `InstrId`, `MndtId`. These are user-stream identifiers
     and survive the anonymiser unchanged — except the safety pass that
     swaps the source own IBAN inside any reference that accidentally
     embedded it.
   - The BIC (`ASNBNL21`) and electronic sequence number
     (`ElctrncSeqNb`).
6. **Sub-element omissions** — ASN's CAMT.053 export does not populate
   `<PstlAdr>` (postal address) on any counterparty. No address scrubbing was
   required at element level; address fragments only appeared inline in
   `<Ustrd>` free-text and were caught by the denylist.

## Counterparty mapping (truncated)

Deterministic mapping built by sorting real names alphabetically and
zipping with the 39-entry canonical merchant pool. First 10 of 39:

| Real name | Synthetic merchant |
|-----------|-------------------|
| ADYEN N.V. | ANWB Wegenwacht |
| ANWB Energie B.V. Laadpassen | Albert Heijn |
| ASR ZIEKTEKOSTEN | Albert Heijn 2 |
| AYVENS | Albert Heijn 3 |
| Albert Heijn | Albert Heijn 4 |
| B.M.S. van den Hoeven | Bol.com |
| BGHU Belastingen | Bunq B.V. |
| Breeze | Bunq B.V. 2 |
| C. Verheij | Bunq B.V. 3 |
| DERD GELD LENDER SPENDER | Bunq B.V. 4 |
| … | … |

The complete mapping is reproducible by running the anonymiser on the same
source file with the merchant pool ordering pinned in this repository.

This same mapping is shared with `asn-cross-format/february.csv`,
`asn-cross-format/february.camt053.xml`, and `asn-mt940-sample-1.sta`,
so the same real counterparty resolves to the same synthetic merchant
in every output — required for the cross-format dedup tests to match
fingerprints across formats.

## What this fixture exercises

| Test surface | Path |
|--------------|------|
| Snapshot test for the CAMT.053 adapter happy path | `Modules/Ingestion/tests/Unit/AsnCamt053AdapterTest.php` |
| Namespace / sub-version detection (001.02 path) | `Modules/Ingestion/tests/Unit/AsnCamt053AdapterNamespaceTest.php` |
| Batch-entry handling (multiple `<NtryDtls>` per `<Ntry>`) | `Modules/Ingestion/tests/Unit/AsnCamt053AdapterBatchEntryTest.php` |
| Cross-format dedup (when paired with the same-period CSV) | `Modules/Import/tests/Feature/CrossFormatDedupTest.php` |
