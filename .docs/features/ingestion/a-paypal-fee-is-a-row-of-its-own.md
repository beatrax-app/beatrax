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

## Sign, currency, and zero

- **Sign is read, never derived.** `Kosten` is already signed: negative when
  PayPal takes a fee, positive when it gives one back on a refund. Deriving the
  sign from the payment's would put `+3,49` on a credit and `-1,25` on a
  refunded fee; taking `abs()` would get the refund wrong the other way.
- **Currency is the row's own `Valuta`**, not the payment DTO's. The FX fold can
  rewrite a payment's native leg to the currency it settled in, and PayPal
  states `Kosten` in neither — it states it beside the cell, in `Valuta`.
- **Zero emits nothing.** `Kosten 0,00` is what the column reads on every row of
  a wallet that only ever spends, and a movement of nothing is not a movement.
- **An absent `Kosten` column emits nothing**, which is deliberately unlike the
  absent `Bruto` column two lines above it. There, the figure the row is *about*
  is missing and the export is refused; here, a figure the row may legitimately
  not have is.

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
