# Reconcile needs an anchor

Why an imported statement could show a difference no reader could clear, and
what now sets the account's baseline.

## The arithmetic

`/reconcile` shows one number:

```text
difference = statement target − clearedBalanceAsOf(account, statement date)
```

and `clearedBalanceAsOf` is `AccountBalanceQuery::sumFromBaseline()`: the
account's **baseline** plus every cleared row posted on or before the date.

The baseline comes from `AccountStartingBalanceQuery::forAccount()`, which
prefers `accounts.opening_balance_minor` (the figure a reader typed in
Settings) and otherwise takes `accounts.starting_balance_minor` (the figure an
import detected). With neither, the baseline is **zero**.

## Only onboarding ever set it

`accounts.starting_balance_*` had exactly two production writers:

- `Modules/Onboarding/Internal/Http/Livewire/Steps/FirstImportStep.php`, the
  wizard's commit, gated by `StartingBalanceRule`.
- `Modules/Ledger/Internal/Services/BackfillStartingBalanceFromStatementSummaries`,
  wired only to the `2026_05_27_000002` data migration — a one-shot that has
  already run on every live install and never runs again.

No account-creation site set it: not `AccountNamer`, not `ConnectBankStep`,
not `ConnectCardStep`, not `EnsurePaypalAccountAction`. An account created by a
plain import through `PreviewWizard` therefore kept a NULL baseline forever,
even though its statement carried an opening balance and the import had already
written that figure to `statement_summaries.opening_balance_minor`.

The result on the ICS regression fixture:

```text
statement target (closing) : −€1,416.50
cleared rows               : −€809.54
difference                 : −€606.96
```

and −€606.96 is precisely the statement's own `Vorig openstaand saldo`. The
difference *was* the missing anchor.

## What was chosen, and why

**The anchor is written at import-confirm time, from the statement summary the
run produced.** `BackfillStartingBalanceFromStatementSummaries` now also
implements `AnchorsStartingBalanceFromStatements`, and `ConfirmImport` calls
`anchorForUser()` post-commit, immediately beside the card-statement upsert and
outside the same inserted/enriched gate.

The alternatives were considered and rejected:

- **Derive it at reconcile time.** The page's difference, the disabled state of
  the Complete button and `confirmReconcile()`'s own re-check are three
  independent computations that must agree exactly. A derived baseline is a
  fourth place for them to diverge, and the code already carries a comment
  saying all three must agree on the same window.
- **Ask the reader to confirm it.** That is the onboarding wizard, and it
  already exists. Repeating it on every plain import turns a statement upload
  into a form. The reader keeps the final word regardless: a Settings
  `opening_balance_minor` outranks the detected figure, and the anchoring pass
  skips any account whose pair is already set, so a confirmed value is never
  overwritten.

Reusing the existing service rather than writing a second derivation matters:
it is already idempotent, already scoped by `user_id`, already writes the date
column date-only, and is already pinned by `AccountStartingBalanceMigrationTest`.

**The candidate query joins `import_runs` and takes only `confirmed` runs.**
`ImportPipeline` writes the statement summary while it is building the
*preview*, so a file the reader discarded — or previewed and walked away from —
leaves a summary row behind. Without the join, the next confirm of any
unrelated import anchored the account from that abandoned statement, which is a
permanent opening balance taken from a file that never entered the ledger.
`DiscardedStatementDoesNotAnchorTest` pins both leaks and the confirmed case
that must still anchor.

`StartingBalanceRule` is deliberately not in this path. It guards the *Livewire
payload* route — an untrusted string arriving from the browser — and it lives
in `Modules/Onboarding/Internal`, which Ledger cannot import. The value here has
a different provenance: it is read by our own statement parser and written by a
user-scoped query builder.

## A summed balance is not a balance anyone read

A statement summary is written by two kinds of adapter. CAMT.053, MT940 and the
ICS card PDF **read** their opening balance off the source: the file states what
the account held before its first row, and that figure is the anchor the account
was missing. The PayPal adapter **derives** its figures instead — a PayPal
Activity export carries no balance rows at all, so the adapter sums the rows it
just yielded and publishes the total as the closing balance.

Its opening balance is then zero, and that zero is not a measurement. It is what
"nothing precedes the first row in this file" spells as an integer. The wallet
held whatever it held on that day; the export does not say.

`PaypalCsvStartingBalanceDetector::detect()` has always refused to offer that
figure, and its comment gives the reason: the reader would confirm it and lose
their real balance. The anchoring path reached the same zero by a different
door — it does not offer anything, it writes — so the refusal the detector
makes on the reader's behalf was bypassed by the one writer that never asks.

**What distinguishes the two is a property of the summary, not of the format.**
Filtering the candidate query on `import_runs.source_format` would have worked
today and rotted the moment a second adapter summed its own figures, because
nothing would have made anyone add it to the list.
`statement_summaries.balances_derived_from_rows` records the fact itself: the
adapter that built the summary says which kind it built, the writer persists it
beside the figures it qualifies, and the candidate query takes only the
summaries that read theirs off the source.

The column defaults to false, which is the right answer for every row written
before it existed bar one, and the migration that adds it labels the existing
PayPal-sourced rows from the run that wrote them — PayPal being the only adapter
deriving anything on the day the column arrived. Every later format answers for
itself at the adapter.

`AWalletAnchoredAtZeroNobodyWasAskedAboutTest` pins both directions: a
single-currency, fully readable PayPal import leaves the wallet unanchored while
still recording the summary it declines to anchor on, and CAMT.053 and MT940
imports still anchor the figures their files stated.

## Two clocks: the period start is not the earliest row

A statement's period start and its rows' transaction dates can be different
clocks. The ICS fixture used to open its cycle on 2026-01-16 — the earliest
**boekdatum** — while reporting a cash withdrawal transacted on 2026-01-15, a
row the €606.96 opening balance does not contain because it appears on *this*
statement. Anchoring on the period start therefore excluded €62.40 that the
closing figure counts, and left the reconcile €62.40 short of zero.

`anchorDate()` takes the earlier of the summary's `opening_balance_date` and the
earliest `posted_at` among the transactions of that same import run, so the
anchor always precedes every row the statement brought.

ICS no longer needs that guard: its period is derived from `posted_at` at the
adapter now, so the two clocks name the same day and `anchorDate()` returns the
period start unchanged ([a period derived from one column and tested on
another](../../conventions/invariants-from-shipped-failures.md#a-period-derived-from-one-column-and-tested-on-another)).
The guard stays because it was never only about ICS: CAMT.053 and MT940 read
their period off the file's own header rather than deriving it, and a row whose
value date precedes the window the bank stated is a shape only the file can
answer for.

## Two columns, two shapes

`transactions.posted_at` is a `date`, written bare as `2026-04-17`.
`accounts.starting_balance_date` is also a `date`, but the model cast it
`immutable_date` at the time, and Laravel's `fromDateTime()` persisted that as
`2026-04-17 00:00:00`.

Compared as stored, `'2026-04-17' >= '2026-04-17 00:00:00'` is **false** — the
shorter string is a prefix of the longer one and sorts below it. Every row on
the anchor day silently dropped out of any sum bounded by the raw predicate.

`AccountStartingBalanceQuery::AT_OR_AFTER_BASELINE_SQL` now wraps both sides in
`date()`, the same treatment `IcsSettlementResolver` already applies to
`card_statements.period_start`. Its three consumers —
`DailyBalanceAggregator::cumulativeBalanceBefore()`,
`DailyBalanceAggregator::dailyDeltasBetween()` and
`BookedFutureRowQuery::window()` — are covered by the fix without change.

This hid because every fixture exercising the raw predicate inserted its
accounts through the **query builder**, which stores the bare date, while the
fixtures that used `Account::create()` only ever exercised the PHP path, which
normalises through `SafeDate`. `TheBaselineBoundaryDaySurvivesADatetimeAnchorTest`
is the one that writes the account the way the app does and then reads it the
way the raw predicate does.

The divergence itself has since been closed at the source: every DATE column now
casts through `Modules\Core\Public\Casts\DateOnlyCast`, which stores ten
characters whatever a writer hands it, and a data migration trimmed the rows
written before it — see
[A DATE column carrying a time](../../conventions/invariants-from-shipped-failures.md#a-date-column-carrying-a-time).
`AT_OR_AFTER_BASELINE_SQL` keeps its `date()` on both sides regardless. A peer
replaying an op-log entry minted before the repair writes that entry's payload
back through the query builder, so the long shape can still arrive from a device
that has been offline.

## A date with no amount behind it

`AccountStartingBalanceQuery::forAccount()` has always refused the combination:
a date without an amount is not a baseline, because honouring its lower bound
drops every earlier row and adds nothing back. That is A9-R14 read the other
way round — an account with no entered balance anchors at zero, which means from
the beginning, not from a day nobody put a figure against.

The rule was spelled twice more and both copies said the opposite.
`EFFECTIVE_DATE_SQL` chose `starting_balance_date` whenever
`opening_balance_minor` was null, whatever `starting_balance_minor` held, so
`AT_OR_AFTER_BASELINE_SQL` bounded on an orphaned date — in the calendar's
past-day line and in `BookedFutureRowQuery`. And
`SetAccountOpeningBalance::positionOn()` read the amount as zero when it was
absent and then applied the date anyway, so the figure the divergence warning
compares the reader's entry against was short by everything posted before it.

The pair is reachable in that state without any writer here producing it.
`starting_balance_minor` and `starting_balance_date` are two independently
merged synced columns — `MergeRulesRegistry` lists them as separate nullable
fields on `accounts` — so a device that clears the amount leaves the other
device's date standing on the merged row. The registry knows some pairs must
travel together and says so for `categories.name_is_default`; it says nothing
for this one.

`AccountStartingBalanceQuery::baselineDate()` is now the single statement of the
rule and both PHP readers call it. The SQL grew a third branch and its comment
names the same rule, which is as close as a query string gets to sharing one.

## A statement is only this account's balance in this account's currency

`statement_summaries` records `opening_balance_currency` and
`closing_balance_currency` beside the two figures, because a statement is
printed in one currency and the account it is matched to is denominated in
another column entirely (`accounts.default_currency`). The match itself is made
on the IBAN, and an IBAN can carry more than one line.

All three readers of those figures took the account's denomination and never
the statement's, so a statement printed in dollars

- anchored a euro account at its own integer, through
  `BackfillStartingBalanceFromStatementSummaries`, and every balance, net-worth
  point, forecast anchor and reconcile difference downstream inherited it;
- was offered in the import wizard as that account's starting balance, through
  `StatementSummaryStartingBalanceDetector`;
- prefilled the reconcile box, through `ReconcilePage`, under the euro sign.

`Modules\Ledger\Public\Support\StatementDenomination` is the one predicate all
three now narrow with: the row is used only where its own currency column is the
account's, or is null — rows written before the column existed carry null and
were the account's own. `card_statements` keeps one `currency` for the whole
statement and the reconcile prefill asks the same question of it.

`AFigureIsReadWithTheCurrencyColumnBesideItArchTest` derives the
`<prefix>_minor` / `<prefix>_currency` pairs from the migrations rather than
listing them, so a table that grows one later is covered without the guard being
edited.

## The advice must not name a route that does not exist

The panel showed one sentence for every non-zero difference: toggle cleared rows
on the transactions list until the difference reaches zero.

On an account anchored at zero that advice was wrong twice over. Every row was
already cleared, so the only available move was to *un*-clear one — which makes
the cleared balance less negative and the difference **more** negative. And zero
was not in range at all: toggling can only move the cleared balance across the
span between "every negative row counted" and "every positive row counted", and
the target sat outside it.

`ReconcilePage::zeroIsReachableByToggling()` now computes that span in one query
and the panel picks between three messages: the toggle advice when a subset
really can reach the target, `unreachable_no_baseline_html` when the account has
no baseline at all — which names the real cause and links to Settings — and
`unreachable` otherwise. Locked rows are counted into the span deliberately:
over-stating what the reader can reach means the panel can never call a closable
gap unclosable, which is the only direction of this answer that could mislead.
