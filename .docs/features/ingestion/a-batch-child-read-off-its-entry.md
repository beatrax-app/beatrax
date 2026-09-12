# A batch child read off its entry

One `<Ntry>` carrying several `<TxDtls>` is a batch booking: the entry is the
total the bank posted, and each child is a payment of its own. Two of the facts
a child states for itself were taken from the entry above it instead.

## The direction no DTO carries

`genkgo/camt` builds every `EntryTransactionDetail` from the **entry's**
indicator. `Decoder/Entry::addTransactionDetails()` passes `$xmlEntry->CdtDbtInd`
into all three of `addCreditDebitIdentifier()`, `addAmountDetails()` and
`addAmount()`, so `TxDtls/CdtDbtInd` is read by nothing at all and
`EntryTransactionDetail::getCreditDebitIndicator()` answers with the batch
total's direction. Asking the child was indistinguishable from asking the entry:

```php
$cdi = $txDtls?->getCreditDebitIndicator() ?? $entry->getCreditDebitIndicator();
```

A `CRDT` child of €25.00 inside a `DBIT` batch was therefore booked **−2500**
where the file says **+2500** — a refund folded into the collection that
reimbursed it — and `extractCounterparty()`, which picks the creditor side for a
debit and the debtor side for a credit, read that child's counterparty off the
wrong side of its own `RltdPties`.

The only way to the element is the XML. `Camt053XmlReader::childDirections()`
loads the document a second time and walks it exactly as the decoder does —
`BkToCstmrStmt/Stmt` in document order, then each statement's `Ntry`, then the
**first** `NtryDtls` and its `TxDtls` children — recording each stated indicator
against the statement, entry and detail ordinal it sat at.
`Camt053Adapter::parse()` reads it back by the same three ordinals: its own
statement counter, the entry's `getIndex()` (which `Decoder/Record::addEntries()`
assigns per statement), and the key of the detail in `getTransactionDetails()`.

Three things that keying depends on, all of them true of the decoder and none of
them obvious:

- Every `<Stmt>` becomes a record, in document order
  (`Camt053/Decoder/Message::addRecords()`).
- Every `<Ntry>` becomes an entry whose `getIndex()` counts from zero **within
  its statement**, not across the file.
- Only the first `<NtryDtls>` of an entry contributes details, because the
  decoder reaches them through `$xmlEntry->NtryDtls->TxDtls`. A second
  `<NtryDtls>` — which ISO 20022 permits — contributes none, so this pass must
  skip it too or every ordinal after it is off by the children it added. What
  it costs in *money* is [a statement that did not check its own
  arithmetic](a-statement-that-did-not-check-its-own-arithmetic.md): the
  children that do arrive no longer add up to their entry, and the entry is
  booked whole instead of the part of it they describe.

The second pass runs **once per file, and only for a file that holds a batch** —
the already-decoded message answers that before any XML is touched. It builds a
second tree the size of the first, and the sniffer admits up to
`SourceFileCeilings::MAX_CAMT_ENTRIES` entries ([what a file expands
into](what-a-file-expands-into.md)), so an export of single entries pays nothing
for a fact it does not contain. An entry with a single `TxDtls` is skipped even
inside such a file: one child is the entry written out, and its direction is the
entry's.

Both readings sit on `Camt053XmlReader` rather than in the adapter because both
touch untrusted bytes, and the `libxml_set_external_entity_loader()` denial that
makes that safe belongs to neither of them alone.

## Booked and instructed are one movement's two legs

A child whose payment the bank converted states its amount twice:

| Element | What it is |
|---|---|
| `TxDtls/Amt` | The figure the **account** moved by, in the account's currency |
| `TxDtls/AmtDtls/TxAmt` | The **underlying transaction**, in the currency it was made in |

The adapter preferred the second and published no settled leg, so a child booked
at €92.50 against a $100.00 purchase landed as `-10000 USD` on a euro statement
with `settled_amount_minor` empty. Nothing downstream could put that right: every
balance, budget and forecast sums the **settled** leg
(`Ledger\Public\ValueObjects\TransactionAmount`), the entry's two children were
then denominated in two currencies and could not be added at all, and
`ImportPipeline::seenAgain()` denominates a new account by the one currency every
row settled in — the same failure a pounds PayPal wallet had, for the same
reason ([a PayPal wallet that is not in
euros](a-paypal-wallet-that-is-not-in-euros.md)).

They are not two candidates for one column. They are the native and the settled
leg of one movement, which is the pair `SourceTransactionDto` already carries and
which the ICS card reader already fills the same way (native in the row's own
currency, settled in the statement's). The child above now lands `-10000 USD`
native beside `-9250 EUR` settled, and `NormalizeStage` derives the rate between
them. A child that states only one of the two has one leg and no rate, which is
what a bank that converted nothing writes.

## Related

- [Ingestion architecture — CAMT.053](architecture.md#camt053)
- [A PayPal wallet that is not in euros](a-paypal-wallet-that-is-not-in-euros.md)
- [Minor units and zero-decimal currencies](../ledger/minor-units-and-zero-decimal-currencies.md)
