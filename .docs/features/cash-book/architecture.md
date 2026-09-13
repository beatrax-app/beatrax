# `CashBook` — architecture

`CashBook` is the hand-entry surface: `/cash`, where a reader types money that
never appeared on a statement — a coffee paid in coins, a market stall, a
neighbour paid back. It owns no table of its own. What it owns is the write
path that turns four typed fields into a row in
[`Ledger`](../ledger/architecture.md)'s canonical `transactions`, and the two
anchor rows such a row needs before it can exist: the reader's cash account,
and the synthetic import run every hand-typed entry is filed under.

## What this module is for

Every other row in the ledger arrives with a file behind it. A statement names
the account, the date, the amount and the currency; the import run records
which file it came from, and the row's position in that file is what tells two
identical coffees apart. A typed entry has none of that. Somebody has to invent
the account, invent the run, and decide what "the second identical coffee"
means when there is no file to count within — and that is the whole of this
module.

It exists apart from `Ledger` because `Ledger` owns the *store*, and apart from
[`Import`](../import/architecture.md) because `Import` owns *files*. A typed
entry has no file and the store takes no opinions, so the decisions in between
live here. The pay-off is that the entry is otherwise ordinary: it goes through
the same `RecordsTransactions` seam an imported row does, so it categorises,
resolves its counterparty, recur-detects, indexes for search, syncs to the
paired devices and counts toward the month exactly as a statement row does.
Nothing downstream branches on "was this typed".

What the module explicitly does NOT do:

- **It has no `Public/` directory at all.** Nothing may import from it, and
  nothing does: the [module boundary map](../../architecture/module-boundaries.md)
  lists it as a leaf. Three test files reach into its `Internal\` namespace and
  all three are pinned in `tests/Contracts/BoundaryArchTest.php`.
- **It writes only income and expense.** There is no transfer direction. Filling
  a wallet is a movement between two of the reader's own accounts, which is
  [`Transfers`](../transfers/architecture.md)' subject; booking it as income here
  would put the float into the envelope grid's ready-to-assign pool.
- **It never edits an entry.** The page adds and deletes. Retyping, splitting,
  re-categorising and note-writing all happen on `Ledger`'s transaction detail,
  which reaches a typed row like any other.
- **It never deletes an imported row.** Every delete predicate repeats
  `source_format = 'manual'`, in both the read that decides and the write that
  acts.
- **It owns no schema.** Its one migration repairs data this module itself
  wrote; the tables it touches — `accounts`, `import_runs`, `transactions` —
  are all `Ledger`'s.

## Module boundary

There is no `Public/` surface, so the section a template asks for is empty on
purpose. `Internal/` holds three classes and one Livewire component:

- **`Internal/Http/Livewire/CashBookPage`** — the `/cash` screen. Form, list,
  pagination, the delete confirmation, and the category picker.
- **`Internal/Actions/RecordManualTransaction`** — the write. Composes one
  `CanonicalTransaction` and hands it to `Ledger`'s `RecordsTransactions`.
- **`Internal/Services/ManualEntryAnchors`** — the two anchor rows, and the one
  answer to "which cash account".
- **`Database/Seeders/Demo/DemoCashEntriesSeeder`** — five demo entries, routed
  through the same action rather than inserted.

Two arch invariants name this module specifically, both in
`tests/Contracts/BoundaryArchTest.php`'s `crossModuleRawTableWrites` list:
`CashBookPage` is pinned for one raw write to `transactions` (the delete), and
`ManualEntryAnchors` for one raw write to `accounts` (the relabel below).

## The two anchors

A `transactions` row has two NOT NULL foreign keys a typed entry has no source
for. `ManualEntryAnchors` mints both, once per reader, on the first entry:

- **The cash account** — one `accounts` row of kind `cash`. Its IBAN is
  `CASH` followed by the zero-padded reader id, a spelling this module writes
  and nothing else does; that is what later tells an account the app minted
  apart from one a person named "Cash" in the import wizard.
- **The manual import run** — one `import_runs` row whose `source_format` and
  `raw_file_path` are both `manual` and whose `sha256` is sixty-four zeroes.
  `import_runs` carries `unique(user_id, sha256)`, so the schema itself allows
  only one of these per reader.

Neither id is taken from `insertGetId()`. Both are read back by the match that
looked for them, for the reason in
[an id read after an insert](../core/an-id-read-after-an-insert.md): the
connection's last insert id is per connection rather than per table, and a
listener writing a `cache` row from inside this INSERT's own event moved it.

Both are minted with `findOrCreate`, which re-selects on a unique violation, so
two adds racing to create the singleton never surface as a 500.

## One question decides which cash account

The reader has exactly one cash account, and three things need to agree about
which one it is: the account the entry is **booked on**, the currency the amount
box is **labelled** in, and the scale the typed figure is **parsed** at. Label
it in euros and parse "1250" as €1250,00 while the write books ¥1250 and the
figure is a thousandfold wrong with nothing on screen saying so.

They agree because they are one question, asked in one place.
`ManualEntryAnchors::currencyForUser()` resolves the account exactly as
`ManualEntryAnchors::accountIdFor()` does and reads the currency off it;
`CashBookPage` carries no `kind = cash` query of its own. The resolution is
ordered on `accounts.iban` rather than on `accounts.id`, because `iban` is
`unique(user_id, iban)`, NOT NULL, and the same value on a paired device, while
`id` is a number each device counts for itself —
the same reasoning `Transfers`' `EarliestLegFirst` settles on.

Two cash accounts are unreachable today, and the ordering is a rule rather than
a repair. Enumerated against `AccountKind::Cash`: nine call sites create an
`accounts` row across the whole tree, and only two of them can write kind
`cash` — this module's own `findOrCreate`, which reuses any row the match
already finds, and `Ledger`'s `DemoAccountsSeeder`, which opens exactly one
(the yen wallet described in
[what the demo zero-decimal account has to show](../ledger/what-the-demo-zero-decimal-account-has-to-show.md#cash-entry)).
The import wizard writes `ics_card`, the onboarding steps write `bank` and
`ics_card`, the PayPal and Google Play actions write their own kinds, and
`Migration`'s `PromoteStagingToDomain` copies a staged kind that no parser ever
populates, so it is always `bank`. A peer's cash account cannot become a second
one either: `unique(user_id, iban)` and `unique(user_id, slug)` give the merge a
natural key, so an arriving create is recognised as the row this device already
holds rather than re-homed beside it.

## The account is named in the reader's own word

`accounts.name` is data, not a translation key — eight screens draw it
verbatim. So the first entry used to freeze the word "Cash" in English whatever
the reader was reading in, and switching language never moved it.

The name is therefore re-resolved on every entry, from
`import::payment_type.cash` — the word the app already ships in all 26 locales,
and the one on the payment-type chip every row this account carries. The
rewrite is a single statement whose whole predicate is in the `WHERE`: it
matches only on the synthetic `CASH…` IBAN, so an account a person named is
never reachable, and it excludes a row already carrying the reader's word, so a
no-op writes nothing. The slug stays language-neutral (`cash`, walked to
uniqueness by `AccountSlugResolver::resolveUnique()`), because re-slugging on
every language change would churn `unique(user_id, slug)` for no reader.
`Database/Migrations/2026_08_30_000003_relabel_the_cash_account_the_app_named_in_english.php`
did the same repair once for the rows already on disk.

## The amount box is typed at the account's own scale

The reader can relabel the cash account in `/settings` like any other, so the
amount field is labelled with that account's symbol and parsed at that
account's number of decimals — which is not two everywhere. The placeholder is
that currency's own zero, and `inputmode` is `numeric` on a zero-decimal
currency and `decimal` otherwise. The unreadable-amount message names the
decimals *this* currency takes and offers an example written with the reader's
own decimal mark, built from the machine-readable form so it is always
something the parser accepts. The background is
[minor units and zero-decimal currencies](../ledger/minor-units-and-zero-decimal-currencies.md#the-box-has-to-invite-the-shape-it-accepts).

An amount the parser could not read is reported as unreadable rather than as
too small: `1.250` is refused because it is ambiguous — grouped thousands or a
decimal, a thousandfold apart — and calling that "not greater than zero" sends
the reader to fix a figure that was never the problem. A blank field keeps the
prompt, because an amount not yet given is not an amount that was misread.

## What the write composes

`RecordManualTransaction` builds one `CanonicalTransaction` and hands it to
`Ledger`'s `RecordsTransactions`, the same seam the import pipeline ends on.
Four of its fields are decisions this module makes:

- **`postedAt` / `bookedAt`.** The day is the reader's, so a December entry
  typed in January is filed under the tax year it names. The *time* of day is
  the second it was typed, and that is deliberate: it is the only part of an
  entry two devices holding the same coffee disagree about, so it is what keeps
  one typed on the phone from merging into one typed on the desktop.
- **`occurrenceOrdinal`.** A typed entry has no file to count within, so the
  ordinal is read off the ledger: one past the highest already standing for the
  same account, day, instant, amount, currency and counterparty key. The
  reader saying "coffee" twice is two facts, and nothing but the ledger records
  that they already said it once. See
  [the occurrence ordinal](../../architecture/ingestion-pipeline.md#the-occurrence-ordinal).
- **`counterpartyName`.** An entry the reader named nobody on carries no
  counterparty, rather than one the app invents: a stand-in would be stored as
  data and would mint a counterparty row that spend analysis, triage and
  merchant matching would then read as somewhere the reader shops. A name that
  *was* typed goes through `ResolvesCounterparties::run()` once, so a typed row
  gets the same identity an imported one gets — see
  [the resolution chain](../counterparties/resolution-chain.md).
- **`status`.** `CanonicalTransaction` lands a manual row `uncleared` where a
  statement row lands `cleared`, because nothing has confirmed it against a
  bank.

The write returns a boolean rather than nothing. It used to end its retry loop
on a bare `return` and the page toasted "Cash entry added." either way: six
identical coffees on one day produced twelve of that sentence and six rows.
A false keeps the reader's fields on screen, because somebody told their entry
was not recorded, on a form that has just cleared itself, has to reconstruct
what they typed before they can try again.

## Deleting an entry

Delete is the one destructive action on the page, so it asks first — a confirm
strip in place, listed in
[which actions ask before they act](../../conventions/which-actions-ask-before-they-act.md).
The id it will act on is held in a `#[Locked]` property, because a Livewire
payload applies its property updates *before* its calls: without the lock, a
payload naming the same id in both halves satisfied its own confirmation.

A reconciled entry is refused with a sentence rather than deleted, through
`TransactionStatusQuery::locksEdits()`. A permitted delete removes the
dependents first — `DependentRowCascade` walks the eight columns that name
`transactions.id`, each carrying its own tombstone, so a peer replaying the
delete never has to be assumed to have foreign-key cascade turned on — then the
row, then the search index, which holds a deliberate plaintext shadow of the
encrypted counterparty name with no foreign key, no cascade and no trigger.
`TransactionMutated` and the cascade's own events go out last.

## Data flow

```
/cash  →  CashBookPage::render()
            ManualEntryAnchors::currencyForUser($user)   ← labels + parses
            paginated read of source_format = 'manual'

add  →  CashBookPage::add()
          MoneyInput::tryToPositiveMinor($amount, $currency)
          SafeDate::dayOrNull($date)
          ownedCategoryId()                    ← own or global category only
             ↓
        RecordManualTransaction::__invoke()
          ManualEntryAnchors::accountIdFor()   ← mint-or-reuse the cash account
          ManualEntryAnchors::runIdFor()       ← mint-or-reuse the manual run
          ManualEntryAnchors::currencyFor()    ← off the resolved account
          CounterpartyKey::forName()
          nextFreeOrdinal()                    ← read off the ledger
             ↓
        Ledger::RecordsTransactions            ← the seam imports end on
             ↓
        categorisation · counterparty resolution · recurring detection
        search indexing · sync capture · notification coalescing

delete  →  confirmDelete()  →  delete()
             status gate → DependentRowCascade → row → search index → events
```
