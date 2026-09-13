# `CashBook` — how to test

Practical recipes for exercising the `/cash` surface and the hand-entry write
path in isolation.

## Unit tests

There are none, and the shape of the module is why. Every class in it either
reads or writes the database — the page, the action and the anchors alike — so
there is nothing a stub collaborator would leave worth asserting. The whole
suite is feature tests over `RefreshDatabase`.

## Feature tests

- **Location:** `Modules/CashBook/tests/Feature/` — seventeen files, eighty-one
  tests.
- **Setup:** `Modules/CashBook/tests/TestCase` extends the root `Tests\TestCase`
  and is wired to `RefreshDatabase` by the per-module map in the project root's
  `tests/Pest.php`. The module's own `tests/Pest.php` is inert and says so;
  Pest only auto-loads the root one.
- **The fixture:** a `User` created inline, and — where the test is about
  currency or scale — an `accounts` row of kind `cash` planted directly, with
  the synthetic IBAN `CASH` + the zero-padded user id when the test needs the
  relabel path to recognise it, and any other IBAN when it must not.
- **Driving the page:** `Livewire::actingAs($user)->test(CashBookPage::class)`,
  then `->set()` per field and `->call('add')`. `->viewData('entryCurrency')`
  and `->viewData('categories')` reach what the Blade was handed, which is how
  the label and the picker are asserted without parsing HTML.
- **Helper naming:** everything Pest loads shares one global namespace, so each
  file's helpers carry a prefix owned by that file (`yenCashUser`,
  `oneCashQuestionUser`, `pageMapSnapshot`). A generic name is a fatal at
  bootstrap the day two files land in the same shard.

## Contract / arch invariants

The module owns no `tests/Arch/` directory. Four root contract guards name it:

- `tests/Contracts/BoundaryArchTest.php` — pins `CashBookPage`'s one raw write
  to `transactions` and `ManualEntryAnchors`' one raw write to `accounts` in
  `crossModuleRawTableWrites`, and pins the three test files that import this
  module's `Internal\` namespace.
- `tests/Contracts/ASyncedColumnIsAnnouncedByItsWriterArchTest.php` — pins
  `accounts.name` as a column `ManualEntryAnchors` writes **without**
  announcing it to the peers, and carries the reason: the word is this device's
  own language, `users.locale` is device-local, and announcing it would have two
  devices overwrite each other's word on every manual entry.
- `tests/Contracts/AWriterTheColumnGuardCannotReadArchTest.php` — the sibling
  pin, for the same write, so a writer the column guard cannot read still has to
  carry a reason somewhere.
- `tests/Contracts/ADateFromOutsideIsRefusedNotNormalizedArchTest.php` — pins
  the literal `SafeDate::dayOrNull($this->date)` in `CashBookPage`, so the entry
  date stays *refused* rather than rolled forward. A day February does not have
  would otherwise book the entry in March under a form still reading the 29th.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/CashBook/tests

# One file
vendor/bin/pest Modules/CashBook/tests/Feature/AYenCashEntryIsBookedInYenTest.php

# Stop on first failure
vendor/bin/pest Modules/CashBook/tests --stop-on-failure
```

Never pipe a run through `| tail`: the shell reports the pipe's exit code, so a
red run reads as green.

The cross-module tests that exercise this module's code are:

```sh
vendor/bin/pest \
  Modules/Sync/tests/Feature/ManualEntryReachesOtherDevicesTest.php \
  Modules/Notifications/tests/Feature/AHandTypedCashEntryIsNotAnnouncedAsAnImportTest.php \
  Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php \
  Modules/Tax/tests/Feature/ThePickerCategoryListIsNotWireWritableTest.php \
  Modules/Ledger/tests/Feature/TheDemoDatasetHoldsAllocatableMoneyInACurrencyWithNoMinorUnitTest.php
```

For the full suite, including the arch invariants above:

```sh
composer test
```

## Behavioural contracts, and the tests that hold them

- **A typed entry becomes an ordinary ledger row.** It carries a type, a signed
  minor amount, a currency, a counterparty and a category, and reaches the
  ledger through the same seam an import does.
  (`tests/Feature/CashBookPageTest.php`)
- **One cash account and one manual import run per reader, whatever the number
  of entries.** (`tests/Feature/CashBookPageTest.php`)
- **The entry is booked on the account the page named, at that account's
  currency, and the amount box is labelled and parsed at the same account's
  scale.** The resolution is ordered on `accounts.iban`, which two devices
  agree on, rather than on `accounts.id`, which they do not.
  (`tests/Feature/OneQuestionDecidesWhichCashAccountTest.php`,
  `tests/Feature/CashBookPageTest.php`)
- **The cash account the module mints is denominated in the OWNER's base
  currency**, not in the install default, so it is right on a path with no
  reader. (`tests/Feature/OneQuestionDecidesWhichCashAccountTest.php`)
- **A zero-decimal currency is typed whole.** "1250" against a ¥ label is
  ¥1,250 and not ¥125,000, the placeholder is that currency's own zero, the
  `inputmode` invites digits without a separator, and the unreadable-amount
  message names the decimals this currency actually takes.
  (`tests/Feature/AYenCashEntryIsBookedInYenTest.php`,
  `tests/Feature/TheAmountBoxInvitesTheShapeItAcceptsTest.php`)
- **The account carries the reader's own word for cash, and follows a language
  change.** The slug does not, so `unique(user_id, slug)` never churns; an
  account the reader named "Cash" themselves is never touched.
  (`tests/Feature/TheCashAccountIsNamedInTheReadersOwnWordTest.php`,
  `tests/Feature/TheCashAccountAlreadyNamedInEnglishIsRepairedTest.php`)
- **The cash account's slug comes from the ledger's own walk.** A reader whose
  id is 2 and who already owns an account named "Cash 2" does not lose the cash
  book to a `unique(user_id, slug)` collision.
  (`tests/Feature/CashAccountTakesItsSlugFromTheLedgerWalkTest.php`)
- **A back-dated entry is filed under the day it names, not the day it was
  typed**, so a December entry typed in January lands in the right tax year —
  and repeats of it still land.
  (`tests/Feature/ABackDatedEntryIsFiledUnderTheYearItNamesTest.php`)
- **Two identical entries are two rows.** The occurrence ordinal is read off
  the ledger, and each add that says it succeeded wrote one row.
  (`tests/Feature/CashBookPageTest.php`,
  `tests/Feature/EveryCashEntryStaysReachableTest.php`)
- **A typed counterparty is an identity the reports can group by**, and an
  entry named on nobody invents none.
  (`tests/Feature/ATypedCounterpartyOnAManualEntryIsAnIdentityTheReportCanGroupTest.php`)
- **The entry list is ranked by what both devices hold.** The 25-row page makes
  the ordering decide which entries are shown at all, so it ends on
  `NewestTransactionFirst::ACROSS_ACCOUNTS` rather than on `transactions.id`.
  (`tests/Feature/TheEntryListIsRankedTheSameOnEveryDeviceTest.php`)
- **Every entry stays reachable.** An entry added from a later page returns the
  reader to the head of the list; deleting the last entry on the last page does
  not strand them on an empty one; and a page number in the URL that no longer
  exists is answered with the last page that does.
  (`tests/Feature/EveryCashEntryStaysReachableTest.php`)
- **Delete asks first, and only about the entry it asked about.**
  `deletingEntryId` is `#[Locked]`, so a payload cannot answer its own
  confirmation by naming the same id in both halves.
  (`tests/Feature/TheEntryADeleteWasAskedAboutIsNotWireWritableTest.php`)
- **A reconciled entry is refused with a sentence in the reader's language, and
  a permitted delete reaches the paired devices** — the row, each leg of a
  split, and the tombstones that carry them.
  (`tests/Feature/CashBookDeleteReachesTheOpLogTest.php`)
- **The category picker never shows two options with the same label**, even
  when two groups are named alike, and it names the group in front of a leaf
  that has one.
  (`tests/Feature/TheCategoryPickerTellsTwoSameNamedCategoriesApartTest.php`,
  `tests/Feature/TheCashBookPickerTellsApartTwoCategoriesAtOnePathTest.php`)
- **A category id the reader does not own is dropped rather than attached.**
  (`tests/Feature/CashBookPageTest.php`)
- **The first entry on a clean install is not a crash**, and a write that did
  fail is answered with a sentence rather than with the statement, its bindings
  and the database's absolute path.
  (`tests/Feature/TheFirstCashEntryOnACleanInstallIsNotACrashTest.php`)
- **A page-number map replaced by a scalar is answered without a server
  fault.** (`tests/Feature/ThePageNumberMapIsNarrowedBeforeItIsIndexedTest.php`)

## Edge cases

- **Blank or unreadable amount** — the fields stay, and the message says which
  of the two it was. Blank keeps the prompt; `1.250` is called unreadable, not
  "not greater than zero", because it is ambiguous by a factor of a thousand.
- **An amount past the ceiling** — `MoneyInput::exceedsMax()` answers first, so
  the reader is told the figure is too large rather than unreadable.
- **A cleared or malformed date** — refused. `SafeDate::dayOrNull()` re-formats
  what it parsed and compares it to what arrived, so `2027-02-29`, `2026-1-5`,
  `2026` and `tomorrow` all fail. Nothing is normalised into a nearby day.
- **A direction that is not a direction** — a payload setting `direction` to
  anything `Direction::tryFrom()` refuses falls back to expense rather than
  throwing.
- **A foreign category id** — dropped. The attach only survives when the
  category is the reader's own or a shared global.
- **No cash account yet** — the first entry mints one. If minting fails, the
  action returns false, the page keeps the fields and says the entry was not
  recorded; raw SQL is not an answer a reader can act on.
- **Two adds racing to mint the singleton** — `findOrCreate()` re-selects on the
  unique violation, so the second add reuses the first's row rather than
  surfacing a 500.
- **Six identical entries typed in one second** — each claims the next free
  occurrence ordinal, retrying up to `MAX_ATTEMPTS`; if all five are taken the
  add reports false rather than toasting a success it did not have.
- **Re-running `demo:seed` without `--reset`** — `DemoCashEntriesSeeder` checks
  for an existing manual row first, because the action writes a random
  `source_ref` per call and nothing about a second run would collide with the
  first.
- **A reconciled entry** — refused, with the reconciliation notice. The page
  never deletes a row `TransactionStatusQuery::locksEdits()` guards.
- **An imported row** — unreachable from this page. The `source_format` filter
  is repeated in the read that decides and in the write that acts.

## Cross-module collaborators

**Depends on:**

- [`Ledger`](../ledger/how-to-test.md) — `RecordsTransactions` (the write
  seam), `CanonicalTransaction`, `Direction`, `TransactionType`, `AccountKind`,
  `BaseCurrency`, `AccountSlugResolver`, `FingerprintComposer`,
  `CounterpartyKey`, `TransactionStatusQuery`, `CategoryPathName`,
  `NewestTransactionFirst`, `Money`, `MoneyInput`. Every table it writes is Ledger's.
- [`Core`](../core/how-to-test.md) — `User`, `Clock`, `CurrentUser`, `Lang`,
  `Brand`, `Locale`, `SafeDate`, `SafeExceptionContext`, `DerivedRowId`,
  `LocaleCollator`, `CoercesScalars`, `DispatchesToast`, `LoadsModuleResources`,
  and `Destination` for the route name.
- [`Import`](../import/how-to-test.md) — `SyntheticSourceFormat` (the `manual`
  source format and the run's `raw_file_path`) and `PaymentType` (the `cash`
  chip, which is also where the account's name comes from).
- [`Counterparties`](../counterparties/how-to-test.md) —
  `ResolvesCounterparties`, run once per entry.
- [`Sync`](../sync/architecture.md) — `TransactionMutated`,
  `DependentRowCascade`, `SensitiveColumnCodec` (the list decrypts
  `counterparty_name` for display).
- [`Search`](../search/architecture.md) — `SearchIndexWriterContract`, to drop
  the deleted row's index document.
- [`Tax`](../tax/architecture.md) — `HandlesTaxTagging` and `TaxTagQuery`, for
  the per-row tax badge and its popover.

**Depended on by:** nothing. The module exports no `Public/` surface. What
reaches its rows reaches them through the ledger, not through this module:
`Notifications` recognises a batch wholly of `manual` rows and titles it "Cash
book updated" with a deep link to `Destination::CashBook`; `Ledger`'s
transaction detail edits a typed row like any other; `Reports`, `Search`,
`Recurring` and the dashboard read it as an ordinary transaction.

## Configuration + feature flags

- **No config key and no feature flag.** The page is always available to an
  authenticated reader.
- `users.base_currency` — the fallback denomination for the cash account the
  module mints, read through `BaseCurrency::forUser()`. Once the account
  exists, its own `default_currency` is what the amount box is typed at, and
  the reader can change that in `/settings` like any other account's.
- `users.locale` — decides the word the cash account is named with, and is
  device-local by design. This is why the name is not announced to the peers.
- `CashBookPage::ENTRIES_PER_PAGE` — 25 rows per page, a class constant rather
  than a setting.
- `RecordManualTransaction::MAX_ATTEMPTS` — how many occurrence ordinals one
  add will try before reporting that it wrote nothing.

## Common debugging recipes

- **"That entry was not recorded."** — the action returned false. Either an
  anchor could not be minted (look for a `QueryException` on `accounts` or
  `import_runs`) or every one of `MAX_ATTEMPTS` occurrence ordinals was taken.
  `CashBookPage::add()` logs the exception through `SafeExceptionContext` and
  never shows it, so the log is where the statement is.
- **The amount box shows the wrong symbol** — read
  `accounts.default_currency` for the reader's row of kind `cash`. The label,
  the parser and the write all come from `ManualEntryAnchors::currencyForUser()`
  and `ManualEntryAnchors::currencyFor()`, so they cannot disagree; a wrong
  symbol means the account itself is denominated wrongly, which
  [an account is denominated by its statement](../import/an-account-is-denominated-by-its-statement.md)
  covers.
- **A figure looks a hundredfold out** — check `MoneyInput::decimalPlaces()`
  for that currency before suspecting the parser. The demo's yen wallet exists
  precisely so a stray ÷100 is visible rather than plausible.
- **The account is named in the wrong language** — the name is re-resolved on
  every entry from `import::payment_type.cash`, and deliberately *not* synced.
  Two devices reading in two languages will each show their own word for the
  same account, and that is intended.
- **A deleted entry comes back after a sync** — the delete must have gone
  through `CashBookPage::delete()`, which writes tombstones through
  `DependentRowCascade` and dispatches `TransactionMutated`. A row removed with
  raw SQL announces nothing, and the peer's history restores it.
- **Two devices list the same entries in a different order** — they should not
  any more. The list ranks on `NewestTransactionFirst::ACROSS_ACCOUNTS`; if a
  divergence survives, one of that clause's columns disagrees between the
  devices, which is a sync question rather than a cash-book one.
- **The category picker shows a duplicate label** — that is
  `CategoryPathName::distinct()`'s subject, not this page's; see
  [category display names](../ledger/category-display-names.md).
