# A statement that did not check its own arithmetic

CAMT.053 and MT940 both state what the account held before the first entry and
what it held after the last. That makes the file self-checking:

    opening + every entry = closing

Nothing computed it. The rows went one way into `transactions` and the two
balances went another way into `statement_summaries`, and the two figures were
never compared — so a dropped entry, a doubled one or a direction read backwards
left a ledger that disagreed with the bank by an amount no screen worked out.
`/reconcile` is a different question: it asks whether the *account* matches a
closing balance, days later, across every import and hand-entered row the
account has. It cannot say which file was misread, and it only ever runs if the
reader opens it.

Three ways a file was misread, all of them found by the sum above.

## An entry the bank has not booked

Every `<Ntry>` carries a `<Sts>`. `BOOK` is money the bank has posted; `PDNG` is
an authorisation it has not, and `INFO` is advice. **The closing balance counts
the booked ones only.** Every entry reached `SourceTransactionDto` regardless, so
a card authorisation still showing as pending was spent out of a balance that
still held it, and booked a second time on the statement that really settled it
— under a different booking date, so the import fingerprint saw two rows.

The CSV side of this module has always known the rule: the Revolut preset
carries `acceptedStates: ['COMPLETED']` and skips the rest. `Camt053Entries`
is the same answer for the ISO 20022 side. Only `PDNG` and `INFO` are dropped:
an entry that states no status at all is booked, because the element is optional
in the sub-versions that make it so and refusing those would drop whole
statements, and a proprietary status is not a claim that the money is not there.

## A batch whose children are not the entry

One `<Ntry>` carrying several `<TxDtls>` is a batch: the entry is the total the
bank posted and each child is a payment of its own, so **the children add up to
the entry**. Where they do not, the set is a partial reading of it, and the
entry is the figure the closing balance was computed from. Two shapes reached
the ledger:

- **A batch split across two `<NtryDtls>` blocks.** The decoder reaches details
  through `$xmlEntry->NtryDtls->TxDtls`, so only the first block contributes —
  a fact [a batch child read off its entry](a-batch-child-read-off-its-entry.md)
  records, because the direction pass has to skip the second block to keep its
  ordinals aligned. What that leaves is not a dropped *ordinal* but dropped
  *money*: a €60.00 entry detailed as 20 + 10 in the first block and 30 in the
  second was booked as two rows totalling €30.00.
- **A batch whose children state no amount.** `TxDtls/Amt` is optional, and a
  collection whose children carry only references and parties has none. Each
  child then fell through to the entry's own amount, so one €90.00 debit was
  booked three times — €270.00 out of an account that moved by €90.00.

`Camt053Entries::detailsToBook()` sums the children against the entry, each
child under the direction it states for itself, and hands back the children only
where the two agree. Where they do not — or where a child names only the
currency the payment was made in, which does not add to a euro sibling — the
entry is booked whole, once, exactly as an entry with no `<TxDtls>` at all
already was. Nothing is invented and nothing is spent: the per-child
counterparties are lost, which is the lesser of the two, because three
counterparties each charged the whole entry is money that was never moved.

## A line MT940 held back

`:86:` is optional. A `:61:` that never gets one is held and written out by
whatever comes next, and in a bulk delivery what comes next is the **first
`:61:` of the following statement** — by which time `:25:` and `:60F:` name
another account and another currency. The held line was written under both:
`:61:2604020402D10,00` from a euro statement came out as ¥1 000 on the account
below it, a hundred times the figure on an account it was never on.

This is the other half of [a second statement's rows took the first statement's
account](../../architecture/ingestion-pipeline.md#statement-metadata-side-channel):
the row fields follow the statement being read, and a held line's statement is
the one it was read in, not the one it is flushed in.
`Mt940StatementAccumulator` carries `pendingOwnIban` and `pendingCurrency`
beside `pendingTag61`, set together and read together.

## What the check says, and what it does not do

`StatementSelfCheck::differenceMinor()` answers `closing - (opening + net)` in
the minor units the statement is denominated in, and both parsers publish a
non-zero answer on the statement summary as `extras.statementDifferenceMinor`.

It answers nothing — and the key is absent — where there is nothing to check
against: a balance the file did not state, two balances in different currencies,
or an entry denominated in something the balances are not. So on a summary
carrying both balances, **an absent key means the statement added up**; a
present key means it did not, by that many minor units.

A difference is never corrected. The rows stay as the file wrote them and both
balances stay as the file stated them; the parser's job is to notice at the
time, not to make the file agree with itself. Both shipped bank fixtures —
`tests/fixtures/asn-camt053-sample-1.xml` (229 entries, €2 158,91 → €801,35) and
`tests/fixtures/asn-mt940-sample-1.sta` (12 entries, €1 000,00 → −€805,38) —
reconcile exactly, and a test pins that they still do.

**Still open:** a stated difference is recorded, not rendered. No screen reads
`extras.statementDifferenceMinor` yet, and `/reconcile` will still prefill its
target from a statement summary whose own arithmetic failed.
