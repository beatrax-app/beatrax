# The opening-balance suggestion, and what it is derived from

`/settings` lets a reader type an opening balance for an account. When the
figure they type is far from what the app computes, `SetAccountOpeningBalance`
raises `OpeningBalanceDivergenceWarning` and the editor offers two buttons:
**Use my number** and **Use Beatrax's number**. The second writes the computed
figure straight into the box.

That makes the computed figure load-bearing in a way an ordinary read is not.
The opening balance outranks the import-detected baseline everywhere a balance
is read — net worth, `/reconcile`, pots, the calendar and the forecast all open
on it — so one click on a wrong suggestion moves every one of them at once. On
one install it took ASN Bank from **€6,604.64 to €3,612.14**.

## What it was, and the three things wrong with it

```php
(int) $db->table('transactions')
    ->where('user_id', $user->id)
    ->where('account_id', $accountId)
    ->where('booked_at', '<=', $asOf->endOfDay())
    ->sum('amount_minor');
```

- **`amount_minor` is the native figure.** It is what the counterparty
  charged, in the currency they charged it in. Summing it over an account
  holding a dollar line and a euro line adds dollar cents to euro cents, which
  is the arithmetic [`AccountBalance`](../ledger/architecture.md) exists to
  prevent. `settled_amount_minor` grouped by `settled_currency` is the row as
  the **account** holds it.
- **`booked_at` is not the bound any balance uses.** Every balance sum in this
  app bounds on `posted_at`, and so does the calendar's past-day line. A row
  booked at 23:30 and posted the next morning is not on the account yet;
  bounding on `booked_at` counted it.
- **The account's baseline was missing entirely.** An account whose history
  starts at an imported or wizard-confirmed `starting_balance_minor` holds that
  figure plus its rows. Dropping it understated the position by the whole
  baseline.

## What it is now

`SetAccountOpeningBalance::positionOn()` derives the position the same way
`AccountBalanceQuery::currentBalanceAsOf` does, in the account's own
denomination:

    starting_balance_minor
      + Σ settled_amount_minor
        where settled_currency = accounts.default_currency
          and posted_at between starting_balance_date and the as-of date

`TheSuggestedOpeningBalanceIsThePositionTest` pins it against
`AccountBalanceQuery` directly, so the suggestion and the four surfaces it
would move cannot drift apart.

## Why it reads `starting_balance_minor` and never `opening_balance_minor`

`AccountStartingBalanceQuery` prefers the reader's override over the
import-detected baseline, which is correct for every ordinary balance read and
wrong here: the override is the very figure being checked. Deriving the
comparison from it would make the warning agree with whatever was last saved,
however wrong — a reader who mistyped €1 would be offered €1 plus their rows
back as the app's independent answer. The check reads the import-detected
baseline alone, so it stays an independent derivation.

## Compute it, or withhold it

The suggestion is **computed correctly rather than withheld**, because the
multi-currency case is computable: `accounts.opening_balance_minor`
is a single integer denominated in `accounts.default_currency` by construction
(`AccountStartingBalanceQuery::forAccount` returns it under that code), and the
account's other currency lines are not what the column is for — the same reason
`BalanceAnchorResolver` opens a projection on one line and leaves the rest out.

There is one case with no correct answer, and it is withheld rather than
guessed: an account naming **no** currency has nothing to state the figure in.
`positionOn()` returns `null`, the divergence check returns without raising,
and the banner — and with it the one-click button — never appears.
