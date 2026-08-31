# What the demo zero-decimal account has to show

`DemoAccountsSeeder` opens **Japan Trip Cash** (`jpy-cash-demo-1`, kind `cash`,
JPY, opening balance ¥600,000) so the demo dataset can hold money in a currency
that has no minor unit. It is the companion page to
[minor units and zero-decimal currencies](minor-units-and-zero-decimal-currencies.md#why-jpy-is-seeded),
which says why JPY is in the dataset at all; this one says what each feature has
to have in it before a reader can tell a correct scale from a hardcoded one.

## Why a second yen account, when one already existed

The dataset already carried **Japan Trip Card** in JPY. It is an `ics_card`, and
`AccountKind::holdsSpendableBalance()` is false for every card kind, because a
card balance is what is *owed*: allocating into one makes the unallocated figure
negative by construction. `PotWriter::save()` refuses such an account outright
with `AccountCannotHoldPotsException`.

So until this account existed, the demo could not carry a single yen pot — and
with no yen pot there was no yen pot movement, no pot-funded yen goal, and no
allocated/unallocated arithmetic anywhere on the zero-decimal side. The
cash book has a second, unrelated requirement: it reads *one* account per
reader, the one of kind `cash`, and types its amount field at that account's own
scale. A demo without a cash account never rendered that field at all.

One account satisfies both: `cash` holds an allocatable balance **and** is what
the cash book resolves.

## The figures are the diagnostic

An amount only proves a scale when the wrong scale would be *visibly* wrong. The
seeded figures are chosen so a stray division by a hundred changes what the
reader sees rather than producing another plausible number.

| Row | Correct | What a ÷100 renders |
|---|---|---|
| Account opening balance | ¥600,000 | ¥6,000.00 — four digits where there were six |
| `Ryokan deposit` pot | ¥150,000 | ¥1,500.00 |
| `Day trips` pot | ¥1,500 | ¥15.00 |
| `Ryokan stay` goal target | ¥480,000 | ¥4,800.00 |
| `Shinkansen pass` goal target | ¥200,000 | ¥2,000.00 |

The two pots are a hundredfold apart on purpose. A surface that divides the
larger prints **the smaller pot's own figure**, so the page shows two rows
claiming the same money — which a reader notices without already suspecting a
scale bug. `¥100` beside `¥1.00` would not do that.

## Pots

`DemoPotsSeeder` opens both yen pots on the cash account and then exercises
every `PotMovementKind` on them, which the euro half of the dataset never did:

- **Fund** — ¥168,000 into `Ryokan deposit`, ¥13,500 into `Day trips`, both as
  the pot's initial amount.
- **Transfer out / transfer in** — ¥12,000 from `Day trips` to `Ryokan deposit`.
  This is the one pot operation that writes a *pair*: two rows naming each other
  through `counterpart_pot_id`, carved out of one account balance. Before this
  the two transfer kinds rendered nowhere in the demo.
- **Withdraw** — ¥30,000 out of `Ryokan deposit`.

Leaving ¥150,000 and ¥1,500 standing, against a real balance of ¥552,000
(¥600,000 opening, less ¥48,000 of cash entries) and ¥400,500 unallocated.

The movements run **only over pots the same run opened**. A second `demo:seed`
without `--reset` opens none, and replaying the moves against pots already
standing would double every figure under the headline balance.

The amounts are written at each pot's own scale — `'1250,00'` is a euro figure
with a Dutch decimal mark, `'168000'` is whole yen. `PotWriter` parses at the
pot's currency, so the two shapes are not interchangeable and a euro string on a
yen pot is refused rather than misread.

## Goals and goal contributions

A goal carries its own immutable `target_currency`, so a yen goal needs one
passed explicitly — `GoalWriter::save()` otherwise falls back to the reader's
base. Both funding routes are shown once in yen:

- **`Ryokan stay`** (¥480,000) is linked to the `Ryokan deposit` pot. A
  pot-linked goal takes its whole progress from that pot, so this is the
  pot-funded route: ¥150,000 of ¥480,000.
- **`Shinkansen pass`** (¥200,000) has no pot and is funded by an attributed
  credit — the ¥150,000 travel-card top-up on Japan Trip Card.
  `GoalContributionWriter` refuses a contribution on a pot-linked goal, so the
  two routes cannot be shown on one goal.

`DemoGoalsSeeder`'s `fundedBy` matches credits on **(type, amount, currency)**.
The currency is not optional: description is ciphertext at rest, so the amount is
what identifies the row, and a bare minor amount names a different sum in each
denomination. Without the code, a yen criterion would have claimed any euro row
carrying the same integer.

## Cash entry

`DemoCashEntriesSeeder` writes five outflows through
`RecordManualTransaction` — the same action the page calls — so each row carries
the fingerprint, the counterparty key and the `manual` source format a
hand-typed entry would. It never names the account: the action resolves the
reader's single `cash` account by kind, exactly as the page does.

| Days ago | Amount | Counterparty | Category |
|---|---|---|---|
| 52 | ¥28,000 | Yamada Denki | — |
| 38 | ¥2,300 | Tokyo Metro | `transport-public` |
| 24 | ¥12,800 | Kappabashi Dougu | — |
| 11 | ¥3,400 | Ichiran | `eating-out` |
| 3 | ¥1,500 | Lawson | `groceries` |

Every entry is an outflow. A cash wallet is filled by a withdrawal from another
account, which is a *transfer*, and the only directions this action writes are
income and expense — booking the float as income would put ¥600,000 into the
envelope grid's ready-to-assign pool, which `DemoEnvelopeBudgetsSeeder` is tuned
against. The float is the account's opening balance instead.

## What the account gives the rest of the app

- **Reconcile** — the account appears in the picker with a JPY statement field,
  and it carries a baseline (`starting_balance_minor`), which is what the
  difference is measured from; see
  [reconcile needs an anchor](reconcile-needs-an-anchor.md#the-arithmetic).
  Nothing pre-fills it: cash has no import and therefore no statement summary,
  which is a true absence rather than a kind that never has a source.
- **Forecast** — the projection runs per account in the account's own
  denomination, so this account and the trip card both produce yen points.
- **Calendar** — `cash` answers true to `AccountKind::holdsSpendableBalance()`,
  so the running balance now folds a zero-decimal account too.

## Envelope budgets are a different axis

An envelope assignment is denominated in the **reader's base currency**, not in
any account's, and the fold converts every account's spend into it. So a
zero-decimal *grid* would need a demo reader whose `base_currency` has no minor
unit — which would restate every other figure that reader sees, including the
euro accounts the rest of the demo is about.

What this account does give the grid is the crossing: yen spend on
`transport-public`, `eating-out` and `groceries` converted into a euro fold. That
crossing only happens while a rate for the pair exists; `CrossCurrencyTotal`
leaves out a line it cannot reach rather than counting it one to one, so a demo
with no JPY rate would silently drop the whole zero-decimal half of the spend.

## Keeping the amounts honest

The pot amounts are bounded by what the account is left holding, and the seed
order enforces it: cash entries are recorded before the pots, and each pot's
initial funding is checked against unallocated at the moment it is opened. If
the cash entries grow, re-derive the headroom:

```sql
select a.default_currency,
       a.starting_balance_minor + coalesce(sum(t.settled_amount_minor), 0) as real_minor
from accounts a
left join transactions t on t.account_id = a.id
where a.slug = 'jpy-cash-demo-1'
group by a.id;
```

`PotWriter` throws `InsufficientUnallocatedException` rather than seeding a
negative unallocated figure, so an over-large pot fails the seed run outright.
