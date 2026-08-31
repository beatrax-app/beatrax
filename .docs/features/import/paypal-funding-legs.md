# PayPal funding legs

How a PayPal Activity Download describes one purchase, and why the row that
funds it is a movement in its own right.

## The shape on the wire

A reader whose PayPal balance is zero pays for something and PayPal settles it
in the same breath. The CSV records that as two rows sharing a
`Reference Txn ID`:

| Row | `Omschrijving` | `Bruto` | `Reference Txn ID` |
| --- | --- | --- | --- |
| parent | `Express Checkout-betaling` | `-12,49` | (a billing-agreement id) |
| funding leg | `Algemene kaartstorting` | `12,49` | the parent's `Transactiereferentie` |

A currency conversion adds a third and fourth row, `Algemene
valutaomrekening`, one per denomination, both pointing at the same parent.

`Bruto` over the whole file sums to zero for a wallet that starts and ends
empty. That is the property that hid the defect below: read on its own, the
PayPal statement balances either way.

## Parent, child, and why a funding leg is neither ornament nor fee

`PaypalCsvEventTypeMap` gives every event type a `PaypalEventAction`:

- **`Parent`** — owns a canonical transaction and therefore carries a
  `TransactionType`.
- **`ChildFx`** — the two legs of a conversion. They do not move money; they
  restate the parent's amount in a second denomination, and
  `PaypalTransactionRollup` folds them into the parent's `settledAmountMinor`.
- **`Skip`** — holds, authorisations and reserves, which never settle.

The two per-purchase funding legs, `Bankstorting naar PP-rekening` and
`Algemene kaartstorting`, were once classified as children. They are not.
A funding leg is the reader's own money leaving their bank and arriving in
PayPal — the same movement the bank statement records as a direct debit. Folded
into the parent it contributed nothing at all, because only `ChildFx` children
change the parent's amounts. The leg vanished, and the bank-side debit was left
with nothing to pair against, so its euros were counted a second time. On the
regression fixture pair that made net worth `-€1,108.16` where the truth is
`-€554.08`, exactly double the €554.08 of funding.

Both are now `Parent` with `TransactionType::TransferIn`, so `TransferPairer`
matches each against the bank-side `transfer_out` through the
`known_counterparty_ibans` alias bridge and the pair nets to zero.

`Bankstorting` (no suffix), `General Withdrawal` and `Transfer to bank` are a
different thing again: standalone top-ups the reader initiated, not attached to
any purchase. They were already parents and stay in step with
`PaypalFundingResolver::FUNDING_EVENT_TYPES`.

## The regression fixtures

`Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` alone cannot see
this bug, because the wallet nets to zero. The bank side is what proves it:

- `tests/fixtures/asn-paypal-funding-2026-04-05.csv` — 41 ASN direct debits,
  one per funding leg in the PayPal fixture, same day and same amount, drawn by
  PayPal's Luxembourg IBAN so the alias bridge resolves them. The two files are
  the same 41 movements seen from both sides, and they total `-€554.08`.

`Modules/Transfers/tests/Feature/APaypalFundingLegIsNotCountedTwiceTest.php`
imports both and asserts the net worth, the leg count and that nothing is left
unpaired.

## A statement cut at a month boundary

PayPal books a purchase late on the last day of a month and converts the
currency the next morning. Split into monthly statements, the conversion legs
land in the following file pointing at a parent that is not in it.

`PaypalTransactionRollup::partitionParents` promotes such an orphan to a
parent, which sends a child event type into
`PaypalCsvEventTypeMap::transactionType()`. That used to raise
`MissingPaypalTransactionTypeMapException` — an internal-inconsistency signal —
and the pipeline's catch-all turned it into `row_unreadable` with no detail at
all. The reader was told a row could not be read and given nothing to do about
it.

It now raises `OrphanedPaypalChildRowException`, which implements
`MessageNamesNoUserData` because its message names PayPal's own vocabulary and
nothing of the reader's. `ImportPipeline::reasonFor()` maps it to
`ImportFailureReason::RowBelongsToAnotherStatement`, so the results screen names
the row and says the other statement holds its parent.

The FX pair itself is still not carried across the boundary: the parent in the
earlier file keeps its native amount with no settled leg. Doing better needs
the walker to read transactions already in the ledger, which it deliberately
cannot — it is a pure function of one file.

Fixtures: `paypal-month-boundary-april.csv` and `paypal-month-boundary-may.csv`,
pinned by
`Modules/Import/tests/Feature/AStatementSplitAtAMonthBoundaryNamesWhatItDroppedTest.php`.

## No row error is left without a detail

`ImportPipeline::safeDetail()` returns the exception's message only when the
exception declares it names no user data. It used to return `null` otherwise,
which is how a row could fail with nothing under it. It now falls back to
`import::preview.errors.row_unreadable_detail`, which carries the exception's
class name — neither the message nor the reader's id, and the one thing worth
quoting in an issue.
