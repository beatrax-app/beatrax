# A PayPal fee is a row of its own

PayPal states three amounts on one line and the wallet moves by the third:

```
Bruto 100,00   Kosten -3,49   Netto 96,51   Saldo 96,51
```

`Netto = Bruto + Kosten`, and `Saldo` — PayPal's own running balance — steps by
`Netto`. `PaypalTransactionRollup` read `Bruto` and nothing else, so that line
became one ledger row of `+100,00`. The wallet had gained `96,51`.

`PaypalCsvAdapter` is the one adapter here whose closing balance is *summed*
rather than read off the file, and it sums the rows the rollup produced, so the
same 3,49 was missing again from `statement_summaries.closing_balance_minor`.
`/reconcile` reads that figure as a target the reader is asked to close by
toggling rows, and no row could close it.

Every fixture shipped before this change carries `Kosten 0,00` on every row —
all 86 of `paypal-sample-1.csv` included. A file whose fee is always nothing
cannot tell a reader of `Bruto` from a reader of `Netto`, which is why six
fixtures and four months of imports never saw it.

## What is booked now

The payment row still books `Bruto`, and the fee becomes a second row booking
`Kosten`. The two sum to `Netto`, so the account lands where `Saldo` says and
the fee is a row a reader can see, categorise and report on.

| | before | after |
|---|---|---|
| payment row | `+10000` | `+10000` |
| fee row | — | `-349` |
| account moved by | `+10000` | `+9651` |
| `closing_balance_minor` | sum of `Bruto` | sum of `Netto` |

Booking `Netto` on the payment row *and* emitting the fee row would take the fee
twice — `96,51 - 3,49 = 93,02`, a balance PayPal never held. There are only two
shapes that land on `Netto`: one row of `Netto`, or `Bruto` plus the fee. Only
the second leaves the fee visible, which is the whole point of the change.

That "the rows emitted from one source row sum to what the source says the
account moved by" is **A1-R21**, and it is anchored per row — on `Netto`, the
figure `Saldo` steps by — never on the statement's closing balance.
`PaypalCsvAdapter` *sums* that closing figure from the rows it emitted, so
checking it against them can never fail: it agreed with the rows throughout the
defect, while every one of those rows was overstated by its fee. The closing
balance came right here as a consequence of the per-row fix, not as the test of
it.

A1-R21 constrains the arithmetic and not the shape, which is why the sibling
decision for Revolut also satisfies it without looking anything like this one:
`GenericCsvAdapter::feeMinor()` folds a Revolut fee into the row's *settled*
leg, because a Revolut row is one movement whose native and settled halves
differ by the fee. Both land on what the account moved by. What the reader asked
for here is a fee they can categorise, and only a row can be categorised.

## What the two rows share

There is no column joining one transaction to another except
`pair_transaction_id`, which belongs to the two legs of a transfer, and
`chain_links`, which is a candidate-and-confirmation ledger for relationships
inferred *across* sources. A fee and the payment it was charged on are neither:
they are two readings of one line of one file, known at parse time with no
inference at all.

So they share what identifies that line:

- **`source_ref`** — both rows carry the payment's `Transactiereferentie`.
  `source_ref` names a source *event*, not a row; it is deliberately absent from
  the fingerprint tuple ([ledger architecture](../ledger/architecture.md)), and
  nothing in the app reads it expecting exactly one row back.
- **`raw_payload.fee_of`** — the same reference again, in a field no enrichment
  rewrites. `ApplyEnrichments` can upgrade a row's `source_ref` to a stronger
  one from a receipt, which would otherwise break the link above.
- **the counterparty, the day and the description** — the fee row carries the
  payment's counterparty and day, and its description is the fee column's own
  header followed by that counterparty: `Kosten / Google Cloud EMEA Limited`.

The fee row's `sourceRowIndex` is the payment's plus one. Both are built before
either is kept, so a fee cell that will not parse drops the payment with it
rather than booking half a pair, and the group still spends the single index the
loss is reported at.

## The fee is read off the movement, not off the fee column

`Kosten` is a label on a figure the row states twice over: `Netto - Bruto` is
the same number, and `Netto` is the one `Saldo` steps by. Anchoring on the
subtraction rather than on the label is what makes A1-R21 hold by construction —
the payment books `Bruto`, the fee books `Netto - Bruto`, and the pair sums to
`Netto` identically, whatever the fee column says.

That matters because the fee column has three ways of saying nothing, and
reading it directly booked the gross in all three:

| the row says | booked before | the wallet moved by |
|---|---:|---:|
| no `Kosten` column at all, `Bruto 100,00`, `Netto 96,51` | `+10000` | `+9651` |
| `Kosten` blank, `Bruto 50,00`, `Netto 48,25` | `+5000` | `+4825` |
| `Kosten 0,00`, `Bruto -20,00`, `Netto -20,60` | `-2000` | `-2060` |

None of the three is malformed enough to refuse and none was reported: the
import came back clean and the wallet was short. `Kosten` is still read, but
only where the row states no readable `Netto` to anchor on — and an unreadable
`Kosten` there still refuses the payment, because a fee the file states and the
parser cannot read is not a fee of zero.

Reading `Netto` first also recovers a payment that used to be lost outright: a
row whose `Kosten` cell is unreadable but whose `Netto` is fine used to drop
whole, because the fee column was the only thing consulted.

### Sign, currency, and zero

- **Sign is neither read nor guessed — it falls out of the subtraction.** A
  `Netto` of `-38,75` against a `Bruto` of `-40,00` gives `+1,25`, the fee
  PayPal handed back, without anyone deciding which way a refunded fee points.
  Where the `Kosten` fallback is used the column is already signed. Deriving the
  sign from the *payment's* would put `+3,49` on a credit and `-1,25` on a
  refunded fee; `abs()` gets the refund wrong the other way.
- **Currency is the row's own `Valuta`**, not the payment DTO's, and all three
  figures are parsed at it. The FX fold can rewrite a payment's native leg to
  the currency it settled in, and PayPal states none of these beside that leg.
- **Zero emits nothing.** A `Netto` equal to its `Bruto` is a row PayPal charged
  nothing on, and a movement of nothing is not a movement. That is the common
  case, and it is now the *measured* common case rather than a column read.
- **A row that states neither a readable `Netto` nor a `Kosten` emits nothing**,
  which is deliberately unlike the absent `Bruto` column. There the figure the
  row is *about* is missing and the export is refused; here the row says nothing
  about a fee, and nothing is what it gets.

## A fee on a converted payment

A conversion pair restates the payment in a second denomination. Nothing in the
file restates the fee, and deriving it at the rate those two legs imply would be
inventing a rate — which **B10-R11** forbids outright: where no rate is to be
had, the caller falls back to the original currency rather than producing one.
The pair the file publishes is that payment's whole rate and says nothing about
any other figure on the line.

So the fee row keeps the denomination PayPal wrote it in and carries no settled
leg. On a wallet whose payment converted, that leaves the file naming two
denominations, and the adapter then publishes **no** closing balance — its
existing rule, and the right one: `/reconcile` treats a closing balance as an
instruction, and an instruction the reader cannot carry out is worse than none.

`paypal-fee-on-a-converted-payment.csv` pins that; `paypal-fee-wallet.csv` pins
everything above it.

## Re-importing the same export

Both rows dedupe the way every other row does. The fingerprint tuple is
`(user, account, posted_at, booked_at, amount_minor, currency,
counterparty_normalized, occurrence_ordinal)`:

- A fee row differs from its payment in `amount_minor`, so the two never collide
  however alike the rest of the line is.
- Two payments charged the same fee on one day to one counterparty produce two
  fee rows alike in every one of those columns but the ordinal, and
  `OccurrenceOrdinals` counts over the file rather than over the ledger — so the
  second import numbers them 0 and 1 exactly as the first did, and
  `FingerprintStage` recognises both.

A second import of `paypal-fee-wallet.csv` therefore inserts nothing and counts
all eleven rows as duplicates.

## Why `Kosten` is not in the language signature

`PaypalCsvLanguageProfile::LANGUAGE_SIGNATURES` gates which files are a PayPal
export at all, and `Bruto` is in it because a file without it read every payment
as zero. The obvious next step — add `Kosten` beside it — was measured and
rejected. It is wrong in both directions at once:

- It **refuses a correct file**. An export with no `Kosten` column whose `Netto`
  equals its `Bruto` on every row states no fee, sums to its own movement, and
  satisfies A1-R21 exactly. The signature cannot see that; it sees a missing
  header and refuses the import.
- It **admits the broken ones**. A blank cell and a `0,00` that disagrees with
  `Netto` both ship the column, so the signature passes them through booking the
  gross — which is two of the three failures above.

The column list was never what went wrong. A header gate answers "is this the
right kind of file"; the harm here is arithmetic, and arithmetic is what the
`Netto` anchor checks.
