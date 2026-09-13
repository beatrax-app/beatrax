# `CashBook` — code

The file-level map for the module. Thirty-four PHP files, of which
twenty-six are the translation catalogue.

## Directory layout

```
Modules/CashBook/
├── Internal/
│   ├── Actions/
│   │   └── RecordManualTransaction.php
│   ├── Http/Livewire/
│   │   └── CashBookPage.php
│   └── Services/
│       └── ManualEntryAnchors.php
├── Database/
│   ├── Migrations/
│   │   └── 2026_08_30_000003_relabel_the_cash_account_the_app_named_in_english.php
│   └── Seeders/Demo/
│       └── DemoCashEntriesSeeder.php
├── Providers/
│   └── CashBookServiceProvider.php
├── Resources/
│   ├── lang/<26 locales>/cash-book.php
│   └── views/livewire/cash-book-page.blade.php
├── Routes/
│   └── web.php
├── module.json
└── tests/
    ├── Feature/
    ├── Pest.php
    └── TestCase.php
```

No `Public/`, no `Models/`, no `Database/Factories/`, no `Internal/Jobs/` and
no `Internal/Listeners/`. The absences are the design: the module exports
nothing, owns no table, and does all its work inside the request that typed the
entry.

## Public API

None. There is no `Public/` directory, so no other module may import anything
from here, and none does. The module is a leaf on the
[boundary map](../../architecture/module-boundaries.md).

The three crossings that exist are all from *tests*, and all pinned in
`tests/Contracts/BoundaryArchTest.php`'s `pinnedCrossModuleInternalImports`:

- `Modules/Notifications/tests/Feature/AHandTypedCashEntryIsNotAnnouncedAsAnImportTest.php`
  → `CashBookPage`
- `Modules/Sync/tests/Feature/ManualEntryReachesOtherDevicesTest.php`
  → `RecordManualTransaction`
- `Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php` → `CashBookPage`

## Internal services

- **`Internal/Services/ManualEntryAnchors`** — the two rows a hand-entered
  transaction hangs on, and the one answer to which cash account.
  - `ManualEntryAnchors::accountIdFor()` — mint-or-reuse the reader's cash
    account, then re-resolve its name into the reader's language. Returns the
    id read back by the match, never `insertGetId()`'s.
  - `ManualEntryAnchors::runIdFor()` — mint-or-reuse the synthetic `manual`
    import run. Its `sha256` is sixty-four zeroes, and `import_runs` carries
    `unique(user_id, sha256)`, so only one can exist per reader.
  - `ManualEntryAnchors::currencyFor()` — the currency of an account the caller
    already holds the id of. Used by the write.
  - `ManualEntryAnchors::currencyForUser()` — the same answer for a caller
    holding no id, so the page's label and the write's booking currency come
    from one resolution rather than two queries that happen to agree. Used by
    the page.
  - `ManualEntryAnchors::currencyFor()` and the mint both fall back to
    `BaseCurrency::forUser()`, not `BaseCurrency::code()`: this writes a stored
    column rather than rendering a display figure, and `code()` answers with
    the install default wherever there is no reader.
  - Private: `findOrCreate()` (re-selects on a unique violation), `existingId()`
    (the one ordered read behind it), `cashAccountMatch()`, `ownIban()`,
    `readersWord()`, `relabelIfItIsTheAppsOwn()`.
  - Bound as a singleton in the provider; `final readonly`.

- **`Internal/Actions/RecordManualTransaction`** — composes one
  `CanonicalTransaction` and hands it to `Ledger`'s `RecordsTransactions`.
  Returns `bool`: false means nothing was written, which the page turns into a
  sentence rather than a toast that lies.
  - `RecordManualTransaction::__invoke()` takes the user, direction, a positive
    minor amount, the day, the counterparty, and an optional category and
    description. Seven parameters, which is the analyser ceiling.
  - Private `enteredAt()` — the reader's day plus the second they typed it.
  - Private `nextFreeOrdinal()` — reads the occurrence ordinal off the ledger.
  - Retries up to `MAX_ATTEMPTS` occurrence ordinals; the counterparty is
    resolved on the first attempt only, because the retries differ in nothing
    but which occurrence they claim and the resolver's upsert is a write.

- **`Internal/Http/Livewire/CashBookPage`** — the `/cash` screen, mounted both
  by the route and as a Livewire component alias.
  - `CashBookPage::add()` — validate, write, clear the fields, reset to page 1,
    toast.
  - `CashBookPage::confirmDelete()` / `CashBookPage::cancelDelete()` /
    `CashBookPage::delete()` — the two-step delete.
  - `CashBookPage::render()` — the paginated manual-entry list, the category
    picker, the tax-tag state, and the entry currency.
  - `CashBookPage::updatedPaginators()` — narrows `WithPagination`'s untyped
    public `$paginators` map on every write, because a payload could otherwise
    replace it with a scalar that `getPage()` then indexes.
  - `CashBookPage::mount()` — seeds the date field with today.
  - Private `manualEntriesQuery()`, `amountError()`, `amountExample()`,
    `ownedCategoryId()`.
  - Traits: `CoercesScalars` (the `toInt` / `toString` narrowing used on raw
    rows), `DispatchesToast`, `HandlesTaxTagging` (`Tax`'s per-row tag popover),
    `WithPagination`.

## Models + migrations

The module owns no Eloquent model. Every table it touches is
[`Ledger`](../ledger/code.md)'s: `accounts`, `import_runs`, `transactions`.

One migration, and it repairs data this module itself wrote:

- `2026_08_30_000003_relabel_the_cash_account_the_app_named_in_english.php` —
  rewrites `accounts.name` from the frozen English `Cash` to the reader's own
  word, for rows the cash book minted. It recognises those rows by the
  synthetic `CASH…` IBAN, skips a reader who has chosen no language (the
  absence of an answer is not an answer to guess at), and is not reversed:
  putting the English back would re-freeze the account in a language the reader
  never chose. The literals are frozen copies of what the writer wrote rather
  than imports of its constants, because what it has to recognise is the
  wording already on disk.

## Resources

- `Resources/views/livewire/cash-book-page.blade.php` — one view. It draws two
  layouts from the same collection: `.card-list-item` rows under `.phone-only`
  and a bordered list under `.desktop-only`. Money is formatted through
  `Money::ofMinor()` with each row's own `settled_currency`, falling back to
  `BaseCurrency::value()` only for a row that lost one.
- `Resources/lang/<locale>/cash-book.php` — 31 keys in 26 locales, at full
  parity. The plural arm `errors.amount_unreadable` goes through
  `Lang::choice()` because the decimal count is a number a language may
  inflect for.

## Provider wiring

`CashBookServiceProvider::register()`:

- Singletons `ManualEntryAnchors`.

`CashBookServiceProvider::boot()`:

- `loadModuleResources('cashbook')` — the `LoadsModuleResources` concern, which
  registers this module's migrations, routes, views and translations under the
  `cashbook` namespace in one call.
- Registers the Livewire alias `cashbook.cash-book-page` → `CashBookPage`, so a
  neighbour's Blade can mount the page by name. `RecordManualTransaction`
  resolves through ordinary constructor injection and needs no binding.

`Routes/web.php` declares one route: `GET /cash`, named `cashbook.index`,
behind the `web` and `auth` middleware. `Core`'s `Destination` enum carries the
matching case, which is what the navigation and the notification deep link
resolve through.
