# ASN CSV — the bank side of the PayPal funding legs

`asn-paypal-funding-2026-04-05.csv` is a **derived** fixture, not an
anonymized export. It exists because a PayPal statement read on its own cannot
see the bug it guards.

## Why it exists

`Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` nets to zero:
every purchase in it was funded on the spot, so `Bruto` over the whole file
sums to `0,00`. Whether the funding legs survive the rollup or are folded away
and lost, the PayPal file still balances. Only the bank side shows the
difference — and there was no bank-side fixture, which is why the funding legs
could be discarded for a release with every PayPal test green.

Imported together, the two files reconcile to a net worth of **−€554.08**. With
the funding legs dropped, the same pair reports **−€1,108.16**: the PayPal
purchases once, and the unpaired bank debits again.

## What it contains

One ASN row per funding leg in the PayPal fixture — 41 rows, `01-04-2026`
through `15-05-2026`, totalling **−€554.08**:

| Column | Value |
|---|---|
| `Je rekening` | `NL57ASNB0123456789` — the account `tests/TestCase::seedFixtureUserAndAccount()` seeds |
| `Van / naar` | `LU89751000135104200E` — PayPal SARL, the alias `DefaultKnownCounterpartyIbansSeeder` maps to `AccountKind::Paypal` |
| `Datum` / `Bedrag bij / af` | the PayPal leg's own date, and its amount negated |
| `Omschrijving` | carries the leg's `Transactiereferentie` as the mandate reference, so a row can be traced back by eye |
| `Type` | `EIC` for a `Bankstorting naar PP-rekening` leg, `BEA` for an `Algemene kaartstorting` one |

The layout is `CsvPresetRegistry::ASN`, identical to `asn-sample-1.csv`.

## Keeping the two in step

**If you change the funding rows in `paypal-sample-1.csv`, this file must be
regenerated**, or the pairing test asserts against a bank side that no longer
matches. The rows are a mechanical transform of the PayPal legs: same date,
negated amount, sorted by date then transaction reference.

Pinned by `Modules/Transfers/tests/Feature/APaypalFundingLegIsNotCountedTwiceTest.php`.
The reasoning is on
[PayPal funding legs](../../.docs/features/import/paypal-funding-legs.md).
