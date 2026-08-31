# An account is denominated by its statement, not by its reader

An account's `default_currency` is the money the account itself moves in. It is
what `/reconcile` labels the statement-balance field with, the denomination
`MoneyInput` parses a typed figure at, the currency
`AccountStartingBalanceQuery` reports the baseline in, and the line
`NetWorthQuery` groups the account under. Every one of those is a statement
about the *account*, and none of them is a statement about the person reading
the screen.

Every account-minting path in the importer used to stamp
`BaseCurrency::code()` — the reader's **reporting** currency — on it. Measured
on an Android phone: reporting currency JPY, a 230-row ASN statement whose every
row says EUR, imported through the upload wizard. The account came out
`default_currency = JPY` holding 229 euro rows, `/reconcile` asked for
"Statement balance (¥)", and `MoneyInput::tryToMinor()` then refused `2158.91`
outright — a yen has no minor unit — while `2158` would have been read as
¥2,158 rather than €21.58.

## Where the currency comes from

**The rows of the file, at their settled leg.** `SourceTransactionDto::$currency`
is the money the *merchant* charged; `$settledCurrency` is the money the
*account* moved by, and it is the column `AccountStartingBalanceQuery` and
`AccountBalanceQuery` compare `default_currency` against. A Google Play receipt
prices in USD and settles in EUR; the account is EUR.

A separately *declared* statement currency exists for the formats that publish
an opening/closing balance — `statement_summaries.closing_balance_currency`,
written by `StatementSummaryWriter`. It is not the source used here, for a
mechanical reason: `ImportPipeline::persistStatementMetadata()` only fires once
the run resolves to an account id, so on the very import that has to *create*
the account there is no summary yet. The rows are the earliest authoritative
signal, and for every format that declares one the two agree by construction —
`IcsPdfAdapter` derives both from `IcsPdfHeaderProfile::STATEMENT_CURRENCY`, and
`PaypalCsvAdapter` derives both from the same per-currency net.

`ImportPipeline` folds the answer onto the `UnknownIban` it already raises for
an account the file names and the ledger does not know, and every naming path
reads it from there:

| Path | Where the currency arrives |
|---|---|
| `PreviewWizard::nameAccount()` → `AccountNamer` | the matched `UnknownIban` in the cached preview |
| `PreviewWizard::saveIcsAccountName()` | `OwnAccountPrompt::statementCurrency()` on `'ICS-CARD'` |
| `PreviewWizard::savePaypalAccountName()` → `EnsurePaypalAccountAction` | the same, on `'PAYPAL'` |
| `PreviewWizard::saveGooglePlayAccountName()` → `EnsureGooglePlayAccountAction` | the same, on `'GOOGLE-PLAY'` |
| `ConnectBankStep` | `$unknown->statementCurrency` on each account the preview asks it to name |
| `ConnectCardStep` | folded across every PDF in the drop, one month per file |
| `ConnectPaypalStep` | the preview, which is why the wallet is now opened *after* the parse |

The `nameAccount()` value is never taken from the wire. The wizard already
re-validates the submitted IBAN against `$preview->accountsToName`; it now reads
the currency off the entry that match returned, so the answer comes from the
file the server parsed.

`AccountDenomination::forStatement()` is the one place the fallback lives.
Falling back to the reader's currency is correct — it is simply **last**.

## One file, many currencies

One account legitimately holds two denominations at once. A Revolut export or a
multi-balance PayPal wallet does exactly that, and
`Modules/Forecasting/tests/Feature/NetWorthReadsEachCurrencyOnAnAccountTest.php`
exists because a euro line and a dollar line on one account each have to convert
at their own rate rather than meeting as bare cents.

So the rule is **unanimity, or nothing**: one currency across every parsed row
on that IBAN is the account's denomination; two is no answer at all, and the
reader's reporting currency stands in. `null` is absorbing — once two rows have
disagreed, a third agreeing with the first does not undo it.

That is the rule `PaypalCsvAdapter` already applies to the balance it derives
for the same wallet — `count($netByCurrency) === 1` or no closing balance,
because `/reconcile` reads a balance as a target and one no row can close is
worse than none.

## The sites that legitimately use the reader's base

These are not the bug, and sweeping them would be:

- **`ManualEntryAnchors::accountIdFor()`** — a cash account is created
  because the reader typed an entry into the cash book by hand. There is no
  statement, and the money they are counting is their own. Its sibling
  `currencyFor()` reads the account's own `default_currency` first and
  only falls back to the base for an account that is not there.
- **`PromoteStagingToDomain::promoteAccounts()`** — reads
  `migration_staging_accounts.currency`, the currency the *imported product*
  declared for that account. It looks like a base-currency site and is already
  the statement's own answer.
- **`SettingsPage`** — displays `$baseCurrencyCode` for an account row whose
  `default_currency` is null. A display fallback, not a stored value.
- **`AccountDenomination::forStatement()` itself**, and every caller reaching it
  with `null`: a mixed-currency file, a file that read nothing, and
  `AccountNamer` called by anything that is not an import.
- **`BaseCurrency::code()` versus `forUser()`** is a standing repo decision:
  `code()` is the *reader's* display currency and is deliberately reader-scoped.

## Accounts already stamped wrong

`2026_08_29_000004_relabel_an_account_the_importer_denominated_in_the_readers_currency`
corrects the installs that already carry one. It is data-only and defensive —
no DDL, because SQLite does not roll DDL back and a migration that throws leaves
a phone unrecoverable — and it changes an account only when all of:

- the account has transactions, and every one of them settled in the same
  currency;
- that currency is not the account's `default_currency`;
- `opening_balance_minor` is null or zero.

The last condition is the one worth stating. Relabelling is exactly what
`SetAccountCurrency` does — nothing stored is rewritten, the baseline is
relabelled where it stands and rows keep the currency they were booked in — and
the one figure whose meaning would silently change is the opening balance the
reader **typed**. `starting_balance_minor` is not that figure: it is written
from a statement summary, so it is already in the statement's currency and the
relabel makes it agree. An account carrying a typed opening balance is left for
`/settings`, where `AccountCurrencyRelabelWarning` shows the reader the figures
before the change is made.

## Related

- [Minor units and zero-decimal currencies](../ledger/minor-units-and-zero-decimal-currencies.md)
  — why a wrong denomination is a factor-of-one-hundred defect and not a label
- [Changing an account's currency](../ledger/architecture.md#changing-an-accounts-currency)
  — the relabel semantics this migration follows
- [Import architecture](architecture.md#preview-wizard-inline-account-naming) —
  the four naming branches
