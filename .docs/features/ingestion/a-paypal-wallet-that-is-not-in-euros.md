# A PayPal wallet that is not in euros

A PayPal wallet holds its balance in whatever currency the account was opened
in, and pays merchants in whatever currency they price in. Those are two
different facts about one payment, and the export states both.

`PaypalTransactionRollup` used to read only one of them. Both branches of its
FX fold gated on the literal `EUR`:

```php
if ($childCurrency === Currency::Eur->value && $nativeCurrency !== Currency::Eur->value) {
```

Read plainly, that says *the euro side is the balance side*. For a wallet whose
balance is in pounds neither branch fires, and the settled leg is never filled
in. Measured on `paypal-gbp-wallet.csv` against the euro control
`paypal-sample-1.csv`, both carrying the same $10.46 Cloudflare purchase:

| | native | settled | statement closes at |
|---|---|---|---|
| euro wallet | `-1046 USD` | `-927 EUR` | `0 EUR` |
| pounds wallet, before | `-1046 USD` | `null` | nothing |
| pounds wallet, after | `-1046 USD` | `-805 GBP` | `0 GBP` |

## What the missing leg cost, which is more than the leg

`settledCurrency` is not decoration. Three things read it, and all three failed
together:

- `PaypalCsvAdapter` sums the settled leg per currency and publishes a closing
  balance only when one currency covers the file. With the purchase falling back
  to its native dollars the file named two, so **no closing balance was
  published at all** and `/reconcile` — which filters on a non-null
  `closing_balance_minor` — offered the wallet no target.
- The funding leg that paid for the purchase still landed in pounds, so the
  wallet's own total came out `+805` where the truth is `0`: a wallet that
  starts and ends empty appeared to have gained the price of the thing it
  bought.
- `ImportPipeline::seenAgain()` denominates a new account by the one currency
  every row settled in. Two currencies is no answer, so
  `AccountDenomination::forStatement(null)` stamped the **reader's** reporting
  currency on the wallet — the defect
  [an account is denominated by its statement](../import/an-account-is-denominated-by-its-statement.md)
  exists to prevent, reappearing through a different door.

## Where the balance currency comes from

**The file, and only the file.** A conversion pair restates one payment in two
denominations, and both rows are in the export:

```
Parent:  O-…034 USD  -10.46  "Vooraf goedgekeurde betaling …"   Cloudflare Inc
Child 1: O-…035 GBP   -8.05  "Algemene valutaomrekening"        the balance leg
Child 2: O-…097 USD  +10.46  "Algemene valutaomrekening"        the parent restated
```

The leg carrying the parent's own currency is the parent restated. That marks
the parent as the merchant's leg, which leaves the leg in the other currency as
the balance the wallet moved by. No currency literal is involved, and every
device reading those bytes reaches the same answer.

The three sources that were rejected, and why:

| Rejected | Why |
|---|---|
| `accounts.default_currency` | The wallet account is *created from* this import ([the naming table](../import/an-account-is-denominated-by-its-statement.md#where-the-currency-comes-from)), so on the run that matters there is no row to read. It is also relabellable per install, so two synced devices would fold the same file differently. |
| `BaseCurrency::code()` from the import context | The reader's **reporting** currency, deliberately reader-scoped. A Japanese-reporting household and a euro-reporting one would parse one CSV into two different ledgers. |
| A rate from the file | PayPal's Activity Download ships no rate column — 18 columns, none of them a `Wisselkoers`. `Rate::between()` derives the pair's own ratio afterwards, which is arithmetic on two figures the file states, not a rate fetched from anywhere. |

## The two cases a single pair cannot settle

**A wallet that really holds two balances.** Nothing stops one wallet paying one
purchase out of euros and the next out of pounds. Each payment is folded against
its own pair, so no file-wide answer is needed and none is imposed.

**A pair a month boundary cut in half.** PayPal books a purchase late on the last
day of a month and converts it the next morning, so the parent keeps one leg and
the other lands in the following file ([PayPal funding
legs](../import/paypal-funding-legs.md#a-statement-cut-at-a-month-boundary)).
Half a pair does not say which side the balance is on. The rest of the file
does: the currency every complete pair in the file agrees on stands in, and where
complete pairs disagree — the two-balance wallet again — nothing stands in and
the parent's own currency is left holding the payment, which is what the adapter
did before there was a pair to read.

A file that names no currency anywhere at all keeps euros as its last resort.
That is not a statement about wallets; it is what is left when there is nothing
to read.

## Related

- [Ingestion architecture — PayPal CSV](architecture.md#paypal-csv)
- [An account is denominated by its statement](../import/an-account-is-denominated-by-its-statement.md)
- [PayPal funding legs](../import/paypal-funding-legs.md)
- [Minor units and zero-decimal currencies](../ledger/minor-units-and-zero-decimal-currencies.md)
