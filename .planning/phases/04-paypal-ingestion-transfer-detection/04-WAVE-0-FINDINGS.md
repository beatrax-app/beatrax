# Phase 4 — Wave 0 Empirical Findings

**Captured:** 2026-05-15
**Source:** Anonymised redaction of the user's real PayPal Activity
Download CSV covering 2026-04-01 → 2026-05-15, committed at
`Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv`.

This document reports back on the D-60 (a–g) empirical set defined in
the phase context. It is the source of truth for the
`PaypalCsvLanguageProfile` / `PaypalCsvEventTypeMap` skeletons landed
in this same wave, and for the contract the Wave 1 adapter has to
honour.

---

## (a) Language profile

**Detected language:** `nl` (Dutch).

**Evidence — verbatim header tokens from the redacted fixture:**

```
Datum,Tijd,Tijdzone,Omschrijving,Valuta,"Bruto ","Kosten ",Netto,Saldo,Transactiereferentie,"Van e-mailadres",Naam,"Naam bank",Bankrekening,Verzendkosten,Btw,Factuurreferentie,"Reference Txn ID"
```

The header carries a UTF-8 BOM (`\xEF\xBB\xBF`) as the first three
bytes. Two header cells (`"Bruto "` and `"Kosten "`) ship with a
TRAILING SPACE inside the quoted token — the redaction script
preserves this verbatim because the parser's header-match must
tolerate either form. `PaypalCsvLanguageProfile::LANGUAGE_SIGNATURES['nl']`
locks the discriminator subset:

- `Datum`, `Tijd`, `Tijdzone`, `Omschrijving`, `Valuta`,
  `Transactiereferentie`, `Reference Txn ID`

The `Reference Txn ID` token is the strongest discriminator — every
other CSV under the user's account portfolio (ASN, ICS) lacks it
entirely. The mixed Dutch + English header (`Reference Txn ID` is
literally English even in the NL export) is intentional on PayPal's
side: this column was never localised.

**Supported set for Phase 4:** `['nl']`. EN-only profile is incremental
work for when (if) a second-language sample arrives.

---

## (b) Event-type vocabulary

The full set of `Omschrijving` values observed across 86 rows
(`PaypalCsvEventTypeMap::MAP['nl']` + `TRANSACTION_TYPE['nl']`):

| Verbatim NL `Omschrijving` | Count | Canonical action (D-62) | `transactionType()` |
|----------------------------|------:|-------------------------|---------------------|
| `Vooraf goedgekeurde betaling – rekening betaald door gebruiker` | 39 | `parent` | `expense` |
| `Bankstorting naar PP-rekening` | 37 | `child-fee` (funding-source) | — (children never own a canonical type) |
| `Algemene kaartstorting` | 4 | `child-fee` (funding-source) | — |
| `Algemene valutaomrekening` | 4 | `child-fx` | — |
| `Express Checkout-betaling` | 2 | `parent` | `expense` |

**No skipped (`'skip'`) event types are present in this export.**
The D-62 default-skip set (`Hold` / `Authorization` / `Reserve` /
`Reversal of General Account Hold`) and their NL equivalents do not
appear anywhere — the user's account has never seen a held / reserved
authorisation in the 45-day period covered. Per the D-62 dispositions
listed in CONTEXT.md, those event types are still WIRED into
`MAP['nl']` as `'skip'` so the adapter handles them correctly when
they DO show up; their NL forms (`Vasthouden`, `Autorisatie`,
`Reservering`, `Storno van algemene rekeningreservering` — empirical
guesses derived from PayPal's NL terminology, NOT directly observed
in this fixture) are left as future-work entries documented under
"Deferred / future-event-type-coverage" below.

**The two event types that distinguish a "child-fee" funding-source
sibling** (`Bankstorting naar PP-rekening` and
`Algemene kaartstorting`) both map to the same canonical action but
carry different funding-channel information: the first means bank
direct-debit, the second means card-funded. Phase 5's chain resolver
can distinguish the two via the rawPayload manifest's event-type
string (D-65).

**Refund is not present.** No `Terugbetaling` / `Refund` event type
appears in this export. The D-62 dispositions still wire `Refund` → 
`parent` → `'refund'` as a forward-compatibility entry; the NL form
(`Terugbetaling`) is left as a forward-work entry. The first refund
the user encounters will require a CONTEXT.md addendum + a fixture
extension; until then the adapter raises
`UnknownPaypalEventTypeException` rather than silently mis-classifying.

**General Withdrawal / Transfer to bank is not present.** No
PayPal → bank sweep is in this export. Same posture: the NL form
(`Algemene opname` / `Overboeking naar bank`) is left as a forward
entry; first encounter requires a CONTEXT.md addendum.

**Implication for Wave 0 skeletons:** `PaypalCsvEventTypeMap::MAP['nl']`
ships with the FIVE empirically-observed entries plus the three
forward-compatible `'skip'` entries (Hold/Authorization/Reserve in
English form — the user's CSV will probably hit them in EN spellings
that PayPal hasn't localised; if NL forms show up first, add them
then). `TRANSACTION_TYPE['nl']` ships only the two parent types
observed (Vooraf goedgekeurde betaling, Express Checkout-betaling),
both mapping to `'expense'`.

---

## (c) Reference Txn ID chain shapes

| Property | Value |
|----------|------:|
| Total rows | 86 |
| Rows with empty `Reference Txn ID` | 1 |
| Rows whose Reference Txn ID points OUTSIDE this file | 40 |
| Rows whose Reference Txn ID points to a Transaction ID INSIDE this file | 45 |
| Parents with exactly 1 child (inside-file) | 39 |
| Parents with exactly 3 children (inside-file) | 2 |
| Maximum child fan-out | 3 |

**Depth:** Single-level (parents have children; children never have
grandchildren). The 86 rows form ~41 distinct logical-payment groups
once rolled up. The walker's three-pass algorithm in PATTERNS.md
§"PaypalTransactionRollup" handles this without recursion.

**Two orientations:**

1. **Majority (≈ 40 of 41 groups):** parent's `Reference Txn ID` is
   either empty OR points to an OUTSIDE-FILE billing-agreement ID
   (`B-…` prefix or its synthetic equivalent in the fixture); each
   child's `Reference Txn ID` points BACK to the parent's
   Transaction ID.
2. **Edge case (1 of 41 groups):** parent's `Reference Txn ID` IS
   empty AND the funding-source row's `Reference Txn ID` points to
   the parent. Same orientation as #1 — the empty-Ref case is just a
   parent that was authorised one-off rather than under a long-running
   billing agreement. The 5/8/2026 Takeaway.com Express Checkout
   payment (`O-00000000000000066`) is the one example in this fixture.

The walker's contract: a row is a PARENT if its `Reference Txn ID`
is empty OR if its Reference Txn ID is NOT another row's Transaction
ID inside this file (= orphan). A row is a CHILD if its
Reference Txn ID matches some other row's Transaction ID inside
this file.

**Fan-out:** Two cases of 3-child fan-out, both being USD currency-
conversion chains: parent + Bankstorting funding-source child +
EUR Algemene-valutaomrekening leg + USD Algemene-valutaomrekening leg.
See (e) below for the FX shape detail.

---

## (d) Funding Source column

**Absent.** No `Funding Source` / `Funding source` column appears in
this NL export. PayPal expresses funding-source information as a
CHILD ROW (`Bankstorting naar PP-rekening` for bank-funded;
`Algemene kaartstorting` for card-funded) under each parent payment.
The funding identity therefore lives in the rawPayload event
manifest (D-65) once the rollup walker has folded the children.

**Implication for Phase 5:** the chain resolver that needs to link a
PayPal charge to its underlying ASN or ICS funding source must walk
the `events` array on the parent's rawPayload and look for the
`Bankstorting naar PP-rekening` (= bank-funded → ASN candidate) or
`Algemene kaartstorting` (= card-funded → ICS candidate) child. The
child row carries no IBAN or card-last-four in PayPal's NL export
(the `Bankrekening` and `Naam bank` columns are empty), so Phase 5
must fall back to amount + date matching against the candidate
account's transaction list. This is the expected Phase 5 work — Phase
4 just stores the data losslessly.

---

## (e) FX representation

**Two paired rows under a shared Reference Txn ID, with an additional
funding-source child making each USD chain a 4-row group.**

Concrete example from the fixture (the 4/19/2026 Cloudflare betweenstacks
chain):

```csv
4/19/2026,…,"Bankstorting naar PP-rekening",EUR, 9.27, …,O-...033,…,O-...034   ← funding-source child
4/19/2026,…,"Algemene valutaomrekening",   EUR,-9.27, …,O-...035,…,O-...034   ← FX leg, EUR out
4/19/2026,…,"Vooraf goedgekeurde betaling …",USD,-10.46,…,O-...034,…,O-...096  ← parent (USD native)
4/19/2026,…,"Algemene valutaomrekening",   USD, 10.46,…,O-...097,…,O-...034   ← FX leg, USD in
```

(Rows reordered for readability; in the fixture the parent's row sits
near the bottom of the file because PayPal sorts the export by
event time + ID — the USD-currency parent is logged a few rows below
the EUR-currency children even though all four share the same
`Datum` / `Tijd` value.)

Per D-63 the canonical `SourceTransactionDto` for this group folds to:

- `amountMinor = -1046`, `currency = 'USD'` — from the parent (the
  non-EUR leg is the native leg; Pitfall 2 says NEVER guess by "first
  row in the file")
- `settledAmountMinor = -927`, `settledCurrency = 'EUR'` — from the
  EUR `Algemene valutaomrekening` child (the leg that landed in
  EUR balance)
- The Bankstorting funding-source child rides in `rawPayload.events`
  as the funding-channel manifest entry
- The USD `Algemene valutaomrekening` child rides in
  `rawPayload.events` as the FX-leg sibling
- `fxRateUsed = null`; NormalizeStage derives the rate via
  `settled / native` at BigDecimal scale 8 per Phase 3 D-39

Two such USD chains exist in this fixture (betweenstacks.com on
4/19/2026 and happetite.app on 4/26/2026). Both follow the identical
4-row shape — the walker can be tested against either.

**The walker MUST detect FX by `Currency != 'EUR'` on a row that
also has a paired sibling whose `Currency == 'EUR'` AND whose
`Omschrijving` is `Algemene valutaomrekening`.** Detecting FX by
"this row's Omschrijving is Algemene valutaomrekening" alone is
insufficient — that string appears on BOTH the EUR side and the
USD side of the pair (siblings under the parent), and only one of
them is the "settled" side.

---

## (f) Transfer to bank row shape

**Absent in this export.** No PayPal → ASN sweep row is present
because the user's funding model in this period was pull-only
(bank → PayPal at payment time, no balance accumulating in PayPal).
The `Naam bank` and `Bankrekening` columns are empty on every row.

**Implication for Layer-1 transfer-pair detection (D-73):** the
deterministic Layer-1 pair-detector will NOT produce any
PayPal → ASN pair from this fixture. ASN → ICS settlement pairs
(via synthetic-IBAN match on `'ICS-CARD'`) WILL still work because
that axis is unrelated to PayPal.

When the user's PayPal balance first accumulates and gets swept to
ASN, that future export will surface a "Transfer to bank" / "Algemene
opname" / "Overboeking naar bank" row. The fixture-record `.md` for
that future fixture extension will document the row shape
empirically (whether `Bankrekening` carries the destination IBAN,
whether `Factuurreferentie` carries the IBAN literal, etc.). Phase
4 stays correct-but-uninteresting for this user's PayPal pattern
until then; Phase 5's chain resolver picks up the slack via the
PayPal child-row event-type matching.

---

## (g) Reconciliation gate

**No explicit opening / closing balance rows present.** PayPal's NL
Activity Download for this account renders the `Saldo` column as a
per-parent-group running balance that resets to `"0,00"` after each
parent + children group is fully settled.

`sum(Netto)` across the full export:

| Currency | Sum (minor units) | Sum (decimal) | Reconciliation |
|----------|------------------:|--------------:|----------------|
| EUR | 0 | 0.00 | CLEAN (zero gap) |
| USD | 0 | 0.00 | CLEAN (zero gap) |

Both currencies net to zero, which matches the user's funding model
(every parent debit is matched by an instant funding-source credit
plus, for USD, by the FX conversion pair). The reconciliation gate
reports CLEAN — no Wave 1 walker concern.

**Wave 1 adapter computes `opening = closing - sum(net)`** per D-71.
For this fixture both halves are zero, so the
`StatementSummaryData` row written will carry
`opening=0, closing=0, totalInflow=0, totalOutflow=0` for each
currency seen in the export. Real exports with an unsettled balance
will surface non-zero values and the soft-warning gate (D-60 g
disposition: "warning, not blocker") fires when the inferred opening
doesn't reconcile.

---

## Deferred / future-event-type coverage

| Event type (NL likely form) | EN form | Disposition |
|-----------------------------|---------|-------------|
| `Terugbetaling` | `Refund` | Not observed yet. `MAP` ships an EN `'Refund' => 'parent'` entry as forward-compatibility; first NL occurrence adds the localised entry and a `TRANSACTION_TYPE['nl']` mapping to `'refund'`. |
| `Algemene opname` / `Overboeking naar bank` | `General Withdrawal` / `Transfer to bank` | Not observed. Forward-compatibility: `MAP['en']` will handle `General Withdrawal` / `Transfer to bank` → `'parent'` / `'transfer_out'`. Empirical NL form to be confirmed when the user's first sweep lands. |
| `Vasthouden` | `Hold` | Not observed. Forward-compatibility: `MAP` ships EN form as `'skip'`. |
| `Autorisatie` | `Authorization` | Not observed. Forward-compatibility: `MAP` ships EN form as `'skip'`. |
| `Reservering` | `Reserve` | Not observed. Forward-compatibility: `MAP` ships EN form as `'skip'`. |
| `Storno van algemene rekeningreservering` | `Reversal of General Account Hold` | Not observed. Forward-compatibility: `MAP` ships EN form as `'skip'`. |
| `Abonnementsbetaling` | `Subscription Payment` | Not observed. Per CONTEXT.md `<deferred>`: only handled if Wave 0 surfaces it. Defer. |
| `Massabetaling` | `Mass Pay` | Not observed. Per CONTEXT.md `<deferred>`: only handled if Wave 0 surfaces it. Defer. |

The Wave 0 skeletons therefore ship with the NL entries observed in
the fixture PLUS the EN forward-compatible entries for the four
universally-filtered event types (Hold/Authorization/Reserve/Reversal).
The adapter raises `UnknownPaypalEventTypeException` for anything not
in the map — silent mis-classification is impossible.

---

*Wave 0 complete. Wave 1 picks up the redacted fixture + these
findings and implements the adapter; the IdempotencyContractTest
`paypal-csv` row REDs in the meantime as the contract for Wave 1 to
bring GREEN.*
