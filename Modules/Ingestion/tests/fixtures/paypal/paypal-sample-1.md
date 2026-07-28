# PayPal CSV — empirical fixture record

`paypal-sample-1.csv` is the anonymised redaction of a real PayPal
"Activity Download" CSV export covering 2026-04-01 → 2026-05-15. The
raw `.csv` lives outside the git tree under
`local/paypal/raw-paypal-activity.csv` (gitignored). Only the redacted
fixture is committed.

The companion redaction script lives at `scripts/anonymize_paypal_csv.php`
and is re-runnable on any future export of the same shape.

## Source

Anonymised from `local/paypal/raw-paypal-activity.csv` via
`scripts/anonymize_paypal_csv.php` on 2026-05-15. The raw export was
generated through Activity → Statements → Custom report → "Activity
download" / "All transactions" on the user's personal PayPal account.

Run:

```sh
php scripts/anonymize_paypal_csv.php \
    local/paypal/raw-paypal-activity.csv \
    > Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv
```

The script is idempotent: re-running it on its own output produces
byte-identical bytes (every replacement is regex-driven on input shape
plus a deterministic counter map for IDs, never state-dependent
state).

## Redactions applied

| Class | Action |
|-------|--------|
| `Transactiereferentie` (Transaction ID) | Mapped via a deterministic two-pass counter to `O-<17-digit-counter>` (e.g. `O-00000000000000001`). The same real ID always maps to the same synthetic — parent / child rollup links survive. |
| `Reference Txn ID` | Resolved through the SAME counter map. References that point at a Transaction ID inside this file stay grouped; references that point at IDs outside this file (billing-agreement IDs, prior-period parents) get their own synthetic but become "orphan" children (per D-61). |
| `Van e-mailadres` | Cells with a non-empty email become `kaarthouder@example.test`. PayPal's NL export carries the MERCHANT's contact email in this column, not the cardholder's; redacting it defensively avoids leaking which functional addresses the user pays. |
| `Bankrekening` (counterparty IBAN) | Non-empty cells become `NL00ASNB0000000000` (the project-wide mod-97-valid synthetic IBAN; same form Phase 2's CAMT fixture uses). The user's funding-source columns happened to be empty in this export — no IBANs were actually redacted, but the regex is in place for the next export. |
| Free-text cells | Defensive scrub for stray emails / IBAN-shaped tokens. Skips the two Transaction ID columns to avoid mangling the deterministic counter map. |

**Preserved verbatim** (load-bearing for the parser's empirical tests):

- `Datum` (M/D/YYYY) on every row
- `Tijd` (HH:MM:SS, 24-hour) on every row
- `Tijdzone` (`Europe/Berlin` on every row in this export)
- `Omschrijving` event-type strings (the localised NL vocabulary — see
  "Event types observed" below)
- `Valuta` ISO codes (`EUR`, `USD`)
- `Bruto` / `Kosten` / `Netto` / `Saldo` / `Verzendkosten` / `Btw`
  monetary cells (NL locale — comma decimal, no thousands separator
  visible in this single-month export)
- `Naam` — the MERCHANT name on the parent payment rows. PayPal's NL
  export does NOT carry the cardholder name in this column; the
  cardholder is the implicit account-holder. Preserving the merchant
  string honours D-58's "merchant strings preserved verbatim" rule.
- `Naam bank` (empty for every row in this export — funding-source
  rows do not name a bank in PayPal's NL output)
- `Factuurreferentie` — the merchant's invoice-side reference token
  (e.g. `718803381922610912`, `charge1425100357`,
  `9ee900d8d4d0774b-AMS-…-create_payment_intent`,
  `Bg3AucVQWPX5DyvvSEZxiv`). Merchant data, not personal.

## Empirical column layout

| # | Header | Notes |
|---|--------|-------|
| 0 | `Datum` | M/D/YYYY (US-style numeric date, NOT Dutch dd-mm-yyyy) |
| 1 | `Tijd` | HH:MM:SS, 24-hour |
| 2 | `Tijdzone` | IANA zone, e.g. `Europe/Berlin` |
| 3 | `Omschrijving` | Localised NL event-type string (acts as the `Type` column other PayPal exports may name `Type`) |
| 4 | `Valuta` | ISO 4217 |
| 5 | `Bruto` | NL-locale signed decimal-comma amount (trailing space in header is verbatim) |
| 6 | `Kosten` | Always `"0,00"` in this export; trailing space verbatim |
| 7 | `Netto` | Gross minus Fee |
| 8 | `Saldo` | Per-row balance — resets to `"0,00"` after each parent-children group is fully settled |
| 9 | `Transactiereferentie` | Unique per row; the "Transaction ID" rollup key |
| 10 | `Van e-mailadres` | Merchant counterparty email (redacted) |
| 11 | `Naam` | Merchant counterparty name (preserved) |
| 12 | `Naam bank` | Counterparty bank name — empty in this export |
| 13 | `Bankrekening` | Counterparty IBAN — empty in this export |
| 14 | `Verzendkosten` | Shipping fees — `"0,00"` everywhere |
| 15 | `Btw` | VAT — `"0,00"` everywhere |
| 16 | `Factuurreferentie` | Merchant invoice reference — present on most rows |
| 17 | `Reference Txn ID` | Parent Transaction ID for child rows (empty for one Express Checkout root row in this export) |

The header row carries a UTF-8 BOM (`\xEF\xBB\xBF`). The redaction
script preserves the BOM exactly so downstream parsers see the same
byte-shape as a fresh PayPal export.

## Event types observed

PayPal's NL export uses Dutch event-type strings (the user's PayPal
account locale is NL). Five distinct values are present in this
export:

| Count | `Omschrijving` (NL) | Canonical action (D-62) | Notes |
|------:|--------------------|-------------------------|-------|
| 39 | `Vooraf goedgekeurde betaling – rekening betaald door gebruiker` | `parent` → `expense` | Pre-approved billing-agreement payment (subscription / recurring charge). The most common row in a recurring-subscription-heavy account. |
| 37 | `Bankstorting naar PP-rekening` | `child-fee` (funding-source) | Per-payment funding-source movement — the offsetting credit into the PayPal balance that settles the matched debit. NOT a separate transaction — folds into the parent's rawPayload manifest. |
| 4 | `Algemene kaartstorting` | `child-fee` (funding-source) | Card-funded payment — same as above but funded by a credit card on file instead of a bank account. Same canonical action: child funding-source. |
| 4 | `Algemene valutaomrekening` | `child-fx` | Currency conversion leg (D-63). USD payments produce a pair: one Algemene valutaomrekening EUR leg and one Algemene valutaomrekening USD leg, both sharing the Reference Txn ID of the USD parent. |
| 2 | `Express Checkout-betaling` | `parent` → `expense` | One-off Express Checkout payment (no billing agreement). |

**No Holds, Authorizations, Reserves, or Reversals** are present in
this export. The skipped-event-type set (D-62) is therefore
EMPTY for the Wave 0 fixture. If a future export surfaces any of
those, `PaypalCsvEventTypeMap::MAP['nl']` gets an entry mapping them
to `'skip'`.

## Row count + period

| Property | Value |
|----------|-------|
| Total rows | 86 |
| Distinct Transaction IDs | 86 (every row has its own ID) |
| Period start | 2026-04-01 |
| Period end | 2026-05-15 |
| Distinct days | 28 |
| Currencies | EUR (82 rows), USD (4 rows) |

## Parent-child chain shapes

The rollup walker (D-61) keys on `Transactiereferentie`; children
walk via `Reference Txn ID`.

| Property | Value |
|----------|-------|
| Rows with empty `Reference Txn ID` | 1 (the lone "root" parent: an Express Checkout payment whose Funding-Source row points BACK to it instead of vice versa — see "Express Checkout exception" below) |
| Rows whose Reference Txn ID points OUTSIDE this file (orphan) | 40 |
| Rows whose Reference Txn ID points to a Transaction ID inside this file | 45 |
| Parents (Transaction IDs that some child row references) | 41 |
| Parents with exactly 1 child | 39 |
| Parents with exactly 3 children | 2 (both USD Express Checkout chains — see "FX representation" below) |
| Currency-conversion rows | 4 — exactly two pairs, each pair sharing a Reference Txn ID with a USD parent |

**Orphans (40)** carry references like `B-0PP830545R8631912` (a
billing-agreement ID — PayPal's `B-` prefix). Billing-agreement IDs
are external references that never appear as a Transaction ID inside
any single Activity Download — they identify the long-running
billing agreement that authorised the parent payment, not a row in
this report. Per D-61 the adapter treats orphan-child rows as
standalone canonical rows under their own Transaction ID and bumps
`import_runs.extras.orphanChildCount`.

**Express Checkout exception:** one Express Checkout payment (Txn
`O-00000000000000066`, the 5/8/2026 Takeaway.com row) has an empty
Reference Txn ID, and its funding-source companion row's
`Reference Txn ID` points BACK to the parent. This is the "normal"
parent-child orientation for one-off Express Checkout payments. The
other Express Checkout payment (5/11/2026 Flink BV row) DOES have a
Reference Txn ID — pointing at a billing-agreement-like ID
(`O-00000000000000069`) that is itself an orphan in this export. So
the two Express Checkout rows demonstrate both orientations the
rollup walker has to tolerate.

## FX representation

USD payments produce a 3-children chain. Concretely the
5/19 Cloudflare row:

```
Parent:  O-...034 USD  -10.46  "Vooraf goedgekeurde betaling …"     Cloudflare Inc
Child 1: O-...033 EUR  +9.27   "Bankstorting naar PP-rekening"     (funding source: EUR credit into PayPal)
Child 2: O-...035 EUR  -9.27   "Algemene valutaomrekening"          (currency conversion: EUR leg out)
Child 3: O-...097 USD  +10.46  "Algemene valutaomrekening"          (currency conversion: USD leg in)
```

All four rows share the same `Factuurreferentie` value
(`9ee900d8d4d0774b-AMS-1776571439-betweenstacks.com-1-create_payment_intent`).
The parent's `Reference Txn ID` points OUTSIDE the file (an orphan
billing-agreement-like ID); the three child rows all reference the
parent's Transaction ID.

Per D-63 the canonical `SourceTransactionDto` for this group folds
into:

- `amountMinor = -1046`, `currency = 'USD'` (from the parent — the
  non-EUR leg is the native leg)
- `settledAmountMinor = -927`, `settledCurrency = 'EUR'` (from the
  EUR `Algemene valutaomrekening` child — the leg that landed in
  EUR)
- The Bankstorting child rides in `rawPayload.events` as the funding-
  source manifest entry; the USD `Algemene valutaomrekening` child
  rides in `rawPayload.events` as the FX-leg sibling. Phase 3 D-39
  derives `fxRateUsed` from `settled / native` at BigDecimal scale 8.

Two USD chains are present (Cloudflare-betweenstacks and
Cloudflare-happetite); both follow the same 4-row shape.

## Reconciliation gate (D-60 g)

This export has **no explicit opening / closing balance rows**. The
`Saldo` column shows a per-parent-group running balance that resets
to `"0,00"` after each parent-children group is fully settled
(PayPal's pre-approved payments and Express Checkout payments are
funded immediately by the source bank account or card on file, so the
PayPal balance never accumulates — every parent debit is matched by
an instant funding credit).

`sum(Netto)` over all rows:

- EUR: `0.00`
- USD: `0.00`

Both currencies net to zero across the full export — which is exactly
what we expect for a personal account that funds every payment from
an external source rather than holding a PayPal balance. The
reconciliation gate (D-60 g) therefore reports CLEAN (zero gap, since
the inferred opening + closing balances are both zero).

The Wave 1 adapter computes `opening = closing − sum(net)`. For this
export both halves are zero. If a future export surfaces an
accumulated balance (e.g. an incoming PayPal payment from another
PayPal user that wasn't immediately swept), the rollup walker MUST
NOT misclassify the unbalanced flow — Pitfall 3's reconciliation
check is the canary.

## source_ref availability

Every row has a unique `Transactiereferentie` value. The redacted
fixture replaces each real value with a deterministic synthetic
(`O-<17-digit-counter>`); the parent / child links survive because the
two-pass counter map applies to both the Transaction ID column and
the Reference Txn ID column. Per D-64 the canonical row's `source_ref`
is the parent's `Transactiereferentie`.

## Funding-source column (D-60 d)

No explicit "Funding Source" column. PayPal's NL Activity Download
expresses the funding source as a CHILD row (`Bankstorting naar
PP-rekening` for bank-funded payments / `Algemene kaartstorting` for
card-funded payments) whose `Reference Txn ID` points back at the
parent. The adapter must derive funding-source identity by walking
the parent's children for a `child-fee` of either funding-source
event type — D-65's rawPayload manifest carries the full child set
forward so Phase 5's chain resolver can use it without re-reading
the source CSV.

## Transfer-to-bank row shape (D-60 f)

This export contains **no `Transfer to bank` / PayPal → ASN sweep
rows**. The user's funding model in this period was pull-only
(bank → PayPal at payment time). The Layer-1 transfer-pair detector
will therefore never produce a paired transfer between PayPal and
ASN from this fixture. If a future export contains a sweep, the
fixture-record `.md` for that fixture will document the row shape
empirically (counterparty IBAN populated? memo line carrying the
IBAN?).

## Anti-patterns the fixture defends against

- **Naive single-pass ID replacement** would lose the parent/child
  link (Pitfall 5). The two-pass counter map prevents that.
- **Currency-direction guesswork** in the FX rollup would misclassify
  whichever USD leg ships "first" in the file as the native leg
  (Pitfall 2). The fixture has both USD chains present so the
  rollup walker's "EUR leg → settled, non-EUR leg → native"
  invariant is testable.
- **Filtering by event-type substring** instead of exact match would
  drop the long Dutch `Vooraf goedgekeurde betaling – …` string by
  accident. The fixture preserves the verbatim em-dash + space
  separator so the parser must handle the full sentence as a single
  key.
