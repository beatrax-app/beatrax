# `Ledger` — architecture

The `Ledger` module is the canonical store: it owns the
`transactions` / `accounts` / `categories` / `merchants` /
`merchant_memories` / `import_runs` / `currencies` /
`statement_summaries` tables and the SOLE sanctioned writers for
each. Every other module reads from these tables through Public
queries and writes through Public action contracts; no module
reaches into `Modules\Ledger\Internal` or runs raw INSERTs on the
canonical tables.

## What this module is for

"See my ASN month" lives here. Every downstream
module — `Chains`, `Recurring`, `Forecasting`, `DriftAlerts`,
`Counterparties` — reads from this module's tables; every adapter in
`Ingestion` and every parser in `Import` ultimately funnels through
`RecordsTransactions` here. The cross-cutting design lives in the
[data-model architecture topic](https://github.com/beatrax-app/spec/blob/main/20-architecture/data-model.md);
this page describes the module's surface.

Two columns this module owns do not mean what they look like, and both
have their own page. `*_minor` amounts are integers rendered by exactly
one class ([money representation](money-formatting.md)), and
`categories.name` holds canonical English for a default category while
the reader sees a per-locale translation
([category display names](category-display-names.md)). Read the second
before writing any query that matches, sorts, or groups on a category
name.

How many minor units make one major unit is the currency's own business
rather than a constant: JPY has no subdivision, so `1000` is ¥1,000 and
not ¥10.00. Every ÷100 in a query, a parse, a chart coordinate or an
input placeholder answers to
[minor units and zero-decimal currencies](minor-units-and-zero-decimal-currencies.md).

Every balance this module reports starts from an account's baseline, and where
that baseline comes from decides whether `/reconcile` can reach zero at all —
[reconcile needs an anchor](reconcile-needs-an-anchor.md).

The "this period at a glance" query is the dashboard's load-bearing
read: aggregate totals across the user's period-start-day window
plus per-currency tiles plus the top categories. It runs in a single
read against indexed columns and surfaces the user's primary daily
question: "what did I spend, where did the money come from, and
what's pending?".

What the module explicitly does NOT do:

- It never categorises a transaction. The category is supplied by
  `Categorization` via `UpdatesTransactionCategory`; this module is
  the sole writer but it does not decide what to write.
- It never resolves counterparties. `Counterparties` resolves; this
  module persists the FK.
- It never speaks IMAP or PDF or CSV. `Ingestion` parses;
  `Import` orchestrates; this module persists.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `RecordsTransactions::__invoke(iterable<CanonicalTransaction>
    $canonical, User $user, bool $captureForSync = true):
    RecordResult` — the single sanctioned writer for
    `transactions`. Idempotent on the v3 fingerprint. It takes a
    BATCH, not one row: the argument is deliberately `iterable`
    so a lazy generator is never forced into memory, and the
    implementation buffers it into chunks committed one
    transaction at a time. `$captureForSync` is the import
    path's opt-out — that caller captures the run, its accounts
    and its transactions itself, parents first, and capturing
    here as well wrote every imported row to the op log twice.
  - `UpdatesTransactionCategory::__invoke($transactionId,
    $categoryId, $user): int` — the single sanctioned writer for
    `transactions.category_id`. Returns affected row count.
  - `RecordsStatementSummary::__invoke(User $user,
    StatementSummaryData $data): void` — the single sanctioned
    writer for `statement_summaries`. Receipts module raises
    this. Idempotent on `(user_id, import_run_id)`: a second
    call upserts, so a re-preview refreshes the metadata
    instead of leaving a stale row behind. CSV imports never
    reach it.
- **Actions/**
  - `RecordTransactions` (impl. `RecordsTransactions`).
  - `UpdateTransactionCategory` (impl. `UpdatesTransactionCategory`).
- **DTOs/**
  - `CanonicalTransaction` — the universal in-flight row shape
    every module passes around (with chainable `with*` setters
    for stage-by-stage enrichment).
  - `RecordResult` — `(insertedCount, deduppedCount,
    enrichedCount)`.
  - `Period`, `PerCurrencyTile`, `DashboardSummary`,
    `TransactionRowDto`, `TransactionListPage`,
    `TopCategoryRow`, `StatementSummaryData`.
- **ValueObjects/**
  - `Money` — domain-shaped wrapper around `brick/money` with the
    project's rounding semantics.
- **Exceptions/**
  - `MoneyColumnMissingException` — raised by `Money` reads when a
    money column is missing from a query result, instead of a
    silent zero.
- **Services/**
  - `FingerprintComposer::compose(...)` — v3 fingerprint
    deterministic compose. Singleton.
  - `PeriodQuery::current($user)` / `previous($user)` — the
    `users.period_start_day`-aware period resolver. Transient
    binding (depends on per-request CurrentUser).
  - `ThisPeriodAtAGlanceQuery::for($user)` — dashboard
    aggregate.
  - `PopulatedPeriodQuery::latestWithRecords($user, $inView)` —
    the period to offer a reader stranded on an empty one, or
    null when there is nowhere to send them.
  - `TopCategoriesByPeriodQuery::for($user, $period)` — top
    categories within a period.
  - `TransactionListQuery::recent($user, $daysBack, $cursorId,
    $limit, $cursorPostedAt, $currency)` and
    `TransactionListQuery::fullHistory($user, $cursorId, $limit,
    $cursorPostedAt, $currency)` — the transactions list read,
    each returning a `TransactionListPage`. There is no
    `page()` taking a filter bag and a page number: the list is
    KEYSET-paginated, so the caller carries an opaque
    `(postedAt, id)` cursor forward rather than an offset. The
    query filters nothing at all: `$currency` picks which amount
    each row projects, not which rows come back — a non-null
    value selects the settled pair over the native one. The two
    methods differ only in the date floor —
    `recent()` cuts at `$daysBack`, `fullHistory()` does not
    cut at all.
  - `StatementSummaryWriter` (impl. `RecordsStatementSummary`).

`Internal/` houses the implementation:

- **Internal/Services/FingerprintRederiveService** — re-derives
  every fingerprint to the v3 algorithm.
- **Internal/Console/RederiveFingerprintsCommand** —
  `beatrax:rederive-fingerprints` artisan command.
- **Internal/Http/Livewire/TransactionsList** — the
  `/transactions` page.
- **Internal/Http/Livewire/TransactionDetail** — the per-row
  detail view.

## Key services + events

- `RecordTransactions::__invoke($canonical, $user,
  $captureForSync)` — INSERT ON CONFLICT on the v3 fingerprint,
  per row, inside a transaction per chunk. Returns a
  `RecordResult` carrying two counts, `inserted` and
  `duplicates`; the enriched count the confirm screen renders
  is NOT from here — `ConfirmImport` gets it from
  `AppliesEnrichments`, which runs in its own transaction after
  the recorder has finished. A `TransactionImported` event
  fires per inserted row after that row's chunk commits, and
  one `TransactionBatchImported` fires once per call.
- `UpdateTransactionCategory::__invoke($txId, $catId, $user)` —
  scoped by `(id, user_id)`; returns affected count. Categorization's
  `AssignCategory` delegates here.
- `FingerprintComposer::compose(...)` — deterministic inputs
  (`normalized_counterparty`, `posted_at`, `settled_at`,
  `amount_minor`, `account_id`, `source_format`). v3 includes
  every input; v2 dropped `account_id` and was re-derived via
  migration.
- `PeriodQuery` — resolves the user's current and previous
  period given `users.period_start_day`. Transient binding to
  pick up the live `CurrentUser` per request.
- `ThisPeriodAtAGlanceQuery` — single-read dashboard aggregate.
  Returns a `DashboardSummary` DTO with per-currency tiles +
  totals.
- `StatementSummaryWriter` — Receipts raises a statement summary
  (the PayPal `statement_summary_total`, the ICS statement
  period totals); this module's writer persists.

The module raises no events; it persists in response to the
upstream pipeline.

## Data flow

The persistence path through the pipeline:

```
ImportPipeline.confirm
  → ConfirmImport (Import)
       → RecordsTransactions::__invoke(cachedRows, user, false)
            → per chunk of rows, in one transaction:
                 INSERT INTO transactions (...) ON CONFLICT(fingerprint)
                 DO UPDATE (enrichment via enriched_from append)
            → after each chunk commits: TransactionImported per row
            → return RecordResult(inserted, duplicates) for the batch

Categorization (manual reclassify)
  → AssignCategory (Categorization)
       → UpdatesTransactionCategory::__invoke (Ledger)
            → UPDATE transactions SET category_id = ?
              WHERE id = ? AND user_id = ?

Receipts (statement-summary write)
  → RecordReceipt (Receipts)
       → RecordsStatementSummary::record (Ledger)
            → INSERT INTO statement_summaries
```

The dashboard read:

```
Dashboard SFC mount
  → PeriodQuery::current($user)
  → ThisPeriodAtAGlanceQuery::for($user)
       → single SELECT aggregating across the period
       → DashboardSummary { perCurrency: [...], total: Money }
  → TopCategoriesByPeriodQuery::for($user, $period)
       → indexed read against (user_id, posted_at, category_id)
  → render
```

## Demo data seeding

`Database/Seeders/Demo/DemoTransactionsSeeder` builds a deterministic,
believable 90-day transaction slate for both demo users — the dataset
the README screenshots derive from. Every merchant name, amount, and
cadence is chosen so a contributor opening the demo install sees
recognisable Dutch retail activity rather than Faker-generated noise.

Mechanics:

- One `import_runs` row per (user, account) pair, stamped
  `source_format = 'demo'`. The marker is the wipe boundary that
  `DemoSeedCommand::resetDemoData()` walks; production user data is
  never targeted.
- Per merchant, an explicit cadence and amount table is defined so the
  dataset is reproducible bit-for-bit between runs. No `rand()` /
  `Faker` — deterministic input produces deterministic fingerprints,
  which lets a second `php artisan demo:seed` run pass the composite
  UNIQUE index without an OR-IGNORE escape.
- Every row is built as a `CanonicalTransaction` and hashed through the
  production `FingerprintComposer` so demo rows are indistinguishable
  from imported rows at the fingerprint layer (the resolver /
  categorizer / chains modules read the same shape regardless of
  origin).
- A handful of PayPal rows are USD-denominated with a realistic
  EUR/USD cross rate so the multi-currency surfaces have data to
  render.
- Transfer pairs (the monthly ASN→PayPal top-up that funds online
  spending) are linked via `pair_transaction_id` after both legs land,
  mirroring the production Layer-1 pair detector.
- The dataset covers every case of `TransactionType` (expense,
  income, transfer_out, transfer_in, fee, refund, adjustment) and
  every value of `PaymentType` (pin, online, transfer, direct_debit,
  cash, fee, refund, unknown) with at least two rows each, so the chip
  strips on `/transactions` and `/community/mystery-merchants` render
  with full diversity on a fresh demo install.

### Three subscriptions step price, and three do not

A drift alert is a claim that a recurring charge **changed price**, so
the demo ledger has to contain the change. It did not: Spotify,
Netflix, Sport City and KPN were each charged the same amount in all
three months, and the prior price on all four shipped alerts was the
one figure no transaction corroborated. A reader who opened the
transaction list found every charge identical.

`seedMonthlySeries()` takes an optional `priorAmountMinor` +
`priorMonths` pair: the oldest `priorMonths` charges are billed at the
old price and the rest at `amountMinor`. Three subscriptions carry one:

| Subscription | Charges, oldest first | Alert |
|---|---|---|
| Spotify Premium | 9.99, 9.99, **10.99** | open — the step is on the newest charge, so it is the fresh one |
| Netflix | 13.99, **14.99**, 14.99 | acknowledged — the demo's `recurring_series_transitions` row already asserted this exact step |
| Sport City | 22.50, **25.00**, 25.00 | dismissed as cancelled — a gym rise is what a reader cancels over |

**KPN is charged EUR 45.00 in all three months on purpose.** It is the
drift-eligible series with no alert, and it is what stops the demo
teaching that `/drift` lists a reader's subscriptions rather than
filters them. NRC (snoozed) and NordVPN (rejected) are refused by the
evaluator on state, so they are not that control. Three alerts is also
the floor: `DemoSeederTest` requires `open`, `acknowledged` and
`dismissed_cancelled` to each carry a row.

Nothing downstream restates those figures. `DemoRecurringSeeder` reads
each occurrence's amount off its own charge, and `DemoDriftAlertsSeeder`
takes prior, latest, delta, currency and `detected_at` from the newest
adjacent pair of occurrences whose amounts differ — so a series with a
flat history gets no alert at all rather than an invented one.
`TheDemoLedgerNeverContainedTheStepItsAlertsClaimedTest` re-runs the
shipped `DriftEvaluator` over the seeded occurrences, rewound to the
moment each step landed, and demands the same row back.

The window anchor is a calendar-month boundary rather than a rolling
cursor: `subMonthsNoOverflow($span - 1)->startOfMonth()` for the start
and `endOfMonth()` for the end. A rolling `today->subDays(89)` cursor
previously clipped the oldest month's monthly-cadence rows (salary,
rent, settlements) whenever their day-of-month fell before the cursor,
and plain `subMonths()` overflows on end-of-month run dates (e.g. "May
31 − 3 months" lands in March, skipping February), collapsing two
`monthsBack` offsets onto the same calendar month and losing the
duplicate to `insertOrIgnore`. Anchoring to calendar-month boundaries
keeps the row count deterministic on every run date.

## `/reconcile` — account reconciliation

`Internal/Http/Livewire/ReconcilePage` is the standalone account-
reconciliation surface (there is no account-detail page in the app, so
this is its own top-level route rather than a tab on one). The user
picks an account, confirms/edits a statement balance + date, and
watches the cleared balance converge on that target. A non-zero
difference is flagged read-only — this flow never fabricates a
balancing transaction. Confirming a matched reconcile calls
`ReconciliationWriter::completeReconcile()`, which bulk-locks the
account's cleared rows up to the statement date to `reconciled`.

The on-screen difference, the confirm-button disabled gate, and
`confirmReconcile()`'s own match check all bound the cleared balance
by the same `posted_at <= statementDate` window that
`completeReconcile()` locks — using the unbounded total would flag a
spurious discrepancy for a past statement date whenever a cleared row
posts after it.

**The statement figure is read and written at the account's own scale.**
A printed statement carries one denomination, the account's, and the page
already labels the field and the difference with that currency's symbol —
so the pre-fill formats and the typed target parses at that currency's
minor-unit count, never the repo-wide hundredth. A yen has no minor unit:
parsed at a hundredth, a reader typing the `-5000` their statement showed
was told the account was `-JPY4,950.00` out, and no amount of toggling
could ever close it.

**Without a statement date the screen answers nothing.** The date field is
`x-core::date-input`, whose calendar carries a Clear button, and Clear writes
the empty string back through `wire:model.live`. Every figure here is bounded
by `posted_at <= statementDate`, so with no readable date there is no window
to sum over: `render()` passes `clearedBalanceMinor` as **null** rather than
zero, the panel draws `—` for it, and the difference pill reads
`pill.choose_date`. Reporting zero was a figure printed under "cleared
balance" for an account that holds money, and the null difference reached the
view's `$fmt(int $minor)` closure, which is a 500 on the page itself.

**A refusal is dropped as soon as its cause is.** `confirmReconcile()` writes
`$error` above the panel, and the panel recomputes on every keystroke while
the line did not — so a reader who closed the gap read "does not match the
cleared balance yet" above a pill saying matched. A component-wide `updated()`
hook clears it, and `$error` is `#[Locked]`: nothing in the view binds it, so
a payload that could set it would put the app's own error styling around a
sentence the app never wrote.

Statement pre-fill sourcing by account kind:

- `asn` accounts — latest `statement_summaries.closing_balance_minor`
  / `closing_balance_date`.
- `ics_card` accounts — latest `card_statements.total_amount_minor` by
  `period_end` DESC. Never `open_balance_minor` — this table is
  read-only here; the sole legal mutator is Chains'
  `CardStatementStateMachine`.
- Any other kind (paypal, generic CSV, cash book) — no statement
  source; fields stay blank for manual entry.

**Both pre-fills take confirmed import runs only.** `ImportPipeline` writes a
statement summary while it is building the *preview*, so a file the reader
discarded leaves one behind. The summary branch joins `import_runs` for that
reason; the card branch needs the same predicate because
`CardStatementUpserter` promotes **every** ICS summary it can see into
`card_statements` — `upsertForUser()`, the healing pass at the top of
`ResolveChainLinksJob`, has no run-status filter at all, and that job runs on
the next confirm of any unrelated import. The card join is a LEFT join
tolerating a NULL `import_run_id`, because that column is `nullOnDelete` and a
statement that outlived its run is history the ledger already holds.
`ReconcileDoesNotPrefillFromADiscardedCardStatementTest` pins both halves.
Chains itself still promotes the discarded summary into `card_statements`,
where `IcsSettlementResolver` can match a real bank settlement against it —
that is a Chains defect, not a reconcile one, and it is open.

IDOR: `$accountId` is a client-controllable, URL-bound property. Every
read re-validates account ownership by `user_id` before touching
`statement_summaries` / `card_statements` / `AccountBalanceQuery`, and
`ReconciliationWriter::completeReconcile()` re-scopes by `user_id`
again on the write side. A foreign `accountId` shows and does nothing.

**A completed reconcile records nothing but the rows.** There is no
reconciliation table: the statement balance and date the reader typed live on
the Livewire component for the length of the request and are never persisted,
and completing writes `status` and `updated_at` on the matched rows and
nothing else. That is what makes un-reconciling one row from the detail page
safe for this page — `clearedBalanceAsOf()` counts `cleared` **and**
`reconciled`, so a row moving between the two does not shift the balance this
page matches against, and the difference still reads zero afterwards. The
unlocked row simply becomes a candidate again the next time Complete runs.

**The screen states the reconcile the account has already had.** Because
nothing is persisted about the reconcile itself, the page held the fact only in
the rows — and drew it nowhere. An account reconciled through 14/05 rendered
byte-identical to one that had never been reconciled: the same matched pill,
the same enabled Complete. `reconciledThrough()` reads the day back off the
locked rows — `max(posted_at)` over this account's `reconciled` ones,
deliberately unbounded by the statement date on screen, since it states what
the account *is* rather than what the field currently says — and the view draws
it as `pill.reconciled_through` beside the account select. It sits with the
account rather than in the difference cell because it is a property of the
account, not an outcome of this statement's arithmetic.

Because nothing persists the statement date, that day can only **under-state**:
a reconcile run to 14/05 whose latest cleared row posted on the 12th reports the
12th. That is the direction to err in — the pill may not name a day the ledger
has no locked row to stand behind, the same way `zeroIsReachableByToggling()`
over-states reachability rather than calling a closable gap unclosable.

**Complete is offered only when it has something to lock.** `lockableCount()`
asks `completeReconcile()`'s own candidate question — `cleared` rows with
`posted_at <= statementDate` — so the disabled gate is now `! $isMatched ||
$lockableCount === 0`, and that predicate is the fourth thing bound by the same
window as the difference, the match check and the write. A matched target over
an empty candidate set used to leave Complete standing as the enabled primary
action for a write whose only possible answer was
`toast.nothing_to_lock`; the reader learned that by pressing it. The reason now
renders as `complete_unavailable` **above** the button row, because a
`disabled` control is out of the tab order and the sentence has to be reachable
before the control it explains — the button points at it with
`aria-describedby`.

The gate is on the candidate count, never on "has this account been reconciled
before". Both legitimate repeats keep working: a later statement brings rows
past the last locked day, and a row unlocked from the detail page becomes a
candidate again under the same statement date. `confirmReconcile()` keeps its
`nothing_to_lock` toast regardless — the method stays a public endpoint that a
forged or raced call can reach, and it still owes that call a truthful answer.

## `/transactions/{transactionId}` — detail page

`Internal/Http/Livewire/TransactionDetail` renders the row's headline
metadata plus a conditional "Effective rate" row (only when
`fx_rate_used` is non-null), the Reclassify control, and the inline
split editor. DI-only: no constructor; service collaborators arrive as
parameters on `mount()`, `render()`, and action methods. Every query
carries an explicit `where('user_id', ...)` predicate; a foreign
transaction resolves to 404 in `mount()` before any data is exposed.

**Reconciled and locked.** The panel that carries the escape hatch, drawn
directly under the header — beside the lock badge that announces the state —
and only while `clearedStatus` is `reconciled`. It states what the lock
covers and offers one action, `startUnreconcile()`, which swaps itself for an
`x-core::confirm-strip` asking `cancelUnreconcile` / `unreconcile`.

It asks because there is no single-row inverse: `completeReconcile()` locks a
whole account up to a statement date and only when the statement balance
matches, so getting one row back means re-running that. It asks *lightly* —
the inline strip rather than `wire:confirm`'s blocking dialog — because
nothing is lost: `unreconcile()` writes one column, and every amount,
category, note, split and tax tag on the row survives untouched. The question
therefore names the effect ("this unlocks the transaction for editing") and
the way back, never a loss. See
[Which actions ask before they act](../../conventions/which-actions-ask-before-they-act.md).

**Reclassify.** A single-click type override that atomically breaks
the `pair_transaction_id` relationship on both sides when the new type
is non-transfer (transfer-to-transfer reclassifies preserve the pair —
re-pairing is the import-time listener's job). Reclassifying a split
transaction to a non-splittable type would strand its legs — the
read-side category-spend union has no type filter, so orphaned legs
would keep counting as spend while the parent drops from the unsplit
roll-up. The split is collapsed first, through the sole mutator, so
leg-delete tombstones emit before the type leaves the splittable set;
the first leg's (user-scoped) category becomes the surviving category.

**Reconciled lock.** Every mutating action (`reclassify`, `saveNote`,
`reassignCounterparty`, `deleteTransaction`, `toggleLegTax`,
`saveSplit`) reads the transaction's `status`
in the same user-scoped query used for its ownership check, and warns
(no write) when the row is `reconciled`. The comparison itself is made in
exactly one place, `TransactionStatusQuery` — `isReconciled()` for a caller
that only needs the answer, and the static `locksEdits()` for one that
already holds the row. The check used to be spelled out at every call site;
the Tax tag/untag actions did not spell it out at all, so the rule engine and a
replay wrote a tax tag onto a reconciled row. `unreconcile()` is the escape
hatch every warn-toast points to, delegating to
`ReconciliationWriter::unreconcile()`, which hands the column to
`TransactionStatusWriter` — the sole mutator that reverts a
`reconciled` row back to `cleared`. It is reached from the **Reconciled
and locked** panel described above; before that panel existed it was reachable
from nothing at all, so the toast named an escape hatch the page did not draw.

**No category mutator of its own.** The page changes a category
through the split editor, which is headed "Category" and shows the
current one — `openSplitEditor()` then `saveSplit()`, routed at
`SaveTransactionSplit`. It also carried a `reclassifyCategory()`
action that called `AssignsCategory` directly and re-emitted a
Livewire-local `correction-divergence:fire` event for a toast mounted
in `layouts.app`. No control ever called it: the Reclassify section
binds `reclassifyType`, which is the transaction *type*. It was the
only dispatcher of that event, so the toast it fed has been retired
along with it — see
[One surface for the divergence conversation](../categorization/architecture.md#one-surface-for-the-divergence-conversation)
for what serves that conversation now.

**Split editor.** In-memory leg rows (session-local until `saveSplit()`
persists them — opening the editor or editing a field never touches
`transaction_splits`). `remainingMinor` is always server-truthful,
computed via the `Money` value object on every leg-amount change — no
client math. Removing a leg at exactly 2 remaining, or unsplitting,
routes through the same two-step confirm UI scoped to the surviving
leg's category; a never-persisted editor collapses purely in memory
(no mutator call, no op-log entry) since there is nothing to reverse.
Tax tagging is leg-aware and requires a persisted leg id.

Every leg amount is formatted and parsed at the PARENT's
`settled_currency`, carried on the `#[Locked] $splitCurrency` property
the editor's two entry points set from the parent row. The repo-wide
hundredth prefilled a JPY 1,000 charge as `10,00` under a total line
reading `¥1.000` two rows above it, and refused `600` + `400` as
over-allocated by ¥99,000 — a yen split could only be typed by writing
`6,00` and `4,00`.

**Savings-goal attribution.** `attributeToGoal()` / `removeGoalAttribution()`
write the `goal_contributions` pivot through Goals'
`GoalContributionWriter`, which re-asserts ownership of both the goal and
the transaction and no-ops silently on a foreign id. This is the one
mutating action on the page NOT behind the reconciled lock: it writes a
separate row and leaves the reconciled transaction untouched, and a
reconciled row is exactly the confirmed money a goal wants to count.

**Delete.** `DeletesTransaction` / `Public\Actions\DeleteTransaction`, the
same contract-behind-an-action shape the other four mutators use. The row,
its leg rows, its search-index shadow and the retype of a transfer's
surviving partner are one `DB::transaction`, and every event is dispatched
only after it commits: the parent's `delete` tombstone, one
`TransactionSplitMutated` delete tombstone per leg (read before the delete,
since the DB FK cascade removes the leg rows locally — sync convergence must
not rely on the peer's replay connection having FK cascade active), and an
`edit` for the survivor when one was retyped. Each deletion is an explicit,
first-class op in the log.

Deleting one leg of a transfer pair retypes the other: a `transfer_out`
whose partner is gone becomes `income`, a `transfer_in` becomes `expense`.
That rule has one home, `Transfers`' `UnpairsTransferLegs`, which the delete
action and the Sync merge replay both call. It used to exist only in the
replay path, so on a single-device install the survivor kept its transfer
type with a null pair and the dashboard went on netting it out.

**Sensitive-column decrypt.** The note, leg notes, headline
`counterparty_name`, and the counterparty picker's `display_name` are
read-side decrypted via `SensitiveColumnCodec` (pass-through no-op when
encryption isn't enabled for the user) and assigned back onto the
in-memory model/array only — never re-saved as plaintext. The
counterparty picker decrypts before re-sorting in PHP, since the DB-level
`ORDER BY` would otherwise sort by ciphertext.

## `/transactions` — list page

`Internal/Http/Livewire/TransactionsList` defaults to a 90-day recent
window; a "Show full history" toggle widens the query to every
persisted row. Pagination is cursor-based via `TransactionListQuery` —
the cursor is a `(posted_at, id)` pair, so rows sharing a `posted_at`
value never silently drop between pages. `loadMore()` reads the next
cursor from the server-side Livewire snapshot rather than accepting it
as a browser-supplied parameter, since the snapshot is encrypted /
HMAC-verified by Livewire and cannot be tampered with by the browser.

`$currency` is URL-bound and falls back to the user's
`default_currency_view` preference when no `?currency=` parameter is
present: `'eur_only'` projects the settled pair (one line per row);
`'original'` projects the native pair with a settled secondary line on
rows whose two pairs differ.

The segmented control over it is labelled **"Settled amount"** and
**"Original amount"** (`ledger::list.currency_eur` /
`currency_original`, group name `currency_aria`), never with a currency
code. It filters nothing, so a label naming a currency is a promise
about every visible row that one account denominated outside the base
currency falsifies — which is what shipped, and what
[the invariants page](../../conventions/invariants-from-shipped-failures.md)
records. The `eur_only` token behind the first option is a stored value
in `users.default_currency_view` and in `?currency=`; it is frozen for
that reason and says nothing about what the option is called.

**Phone infinite scroll.** `$accumulatedRows` stores a flat scalar
array of rows accumulated across all `loadMore()` calls, since `Money`
objects are not Livewire-dehydratable. The phone card-list blade loops
this accumulated set; the desktop table iterates `$page->rows`
directly. An `$appendedCursorIds` guard set (keyed by cursor id, `0`
for the first page) prevents the same page being double-appended on a
re-render that does not advance the cursor. `MAX_ACCUMULATED_ROWS`
(500, ~200KB JSON-encoded, well below Livewire 4's 4MB snapshot limit)
trims the oldest rows from the front when exceeded, resetting the guard
to only the new tail row — otherwise a user scrolling years of full
history would eventually corrupt the component state.

**Search mode.** When `isSearchActive()` returns true (any search-mode
property is non-default), `render()` branches to `SearchQuery::search()`
and searches all history regardless of the `$fullHistory` toggle.
Highlight/snippet data from `SearchRowDto` is intentionally not stored
in `$accumulatedRows` (it would bloat the snapshot and go stale across
renders) — it is re-fetched from `SearchQuery` on each render via a
per-render `$searchRows` map. Clearing search restores the prior
`$fullHistory` toggle state via `$preSearchFullHistory`.

**Split legs.** `legsFor()` batch-loads `transaction_splits` for a page
of transaction ids in one query, keyed by `transaction_id`. Every id is
already user-scoped by the list query that produced it, so joining on
`transaction_id` alone cannot leak another user's legs. Split detection
downstream is by leg-row presence only (>= 2 legs), never `category_id`
nullity — a split parent may carry a vestigial non-null `category_id`.

## `RecordTransactions` — the batch writer

`Public/Actions/RecordTransactions` persists a batch of canonical
transactions in bounded, independently-committing chunks (`CHUNK_SIZE`
= 500) rather than one whole-file-atomic transaction — a full-year
import must not run as one unbounded in-memory DB transaction in the
web request. The guarantee is idempotent + resumable rather than
all-or-nothing: a row that fails the type-validation pre-check rolls
back only its own chunk, and rows whose fingerprint already exists are
silently dropped by `insertOrIgnore` (the DB-layer idempotency proof),
so re-running the same source after a partial failure only lands the
not-yet-stored remainder.

Every row must carry a non-null `userId` — SQLite treats NULL as
distinct in UNIQUE indexes, so a `user_id = NULL` row would slip past
the composite UNIQUE on re-import. The de-dup fingerprint is always
composed from the plaintext DTO, never from the possibly-encrypted
attributes about to be written, so re-import idempotency is identical
whether or not encryption is enabled. Sensitive content columns
(description, counterparty_name, counterparty_iban, raw_payload) are
encrypted under the current GDK epoch before the row touches disk
(pass-through no-op when encryption isn't enabled); amount columns are
never touched so SQL `SUM()`/`GROUP BY` keeps working.

A chunk reads its own writes back in **one** statement, not one per row:
the loop collects the fingerprint of every row `insertOrIgnore` reported
as written, and a single `whereIn('fingerprint', …)` per owner — the
fingerprint is unique per user, never globally — rehydrates them, still
inside the chunk transaction, where uncommitted rows are visible to this
connection alone. The models come back in the order the chunk wrote them,
because the listeners downstream run in that order, and a fingerprint the
read cannot find still raises `ModelNotFoundException` rather than
shortening the batch in silence.

For every row `insertOrIgnore` actually persists, a
`TransactionImported` event dispatches synchronously once that row's
chunk has committed, so cross-module listeners (e.g. transfer-pair
detection) never act on rows a rollback took away — duplicates never
produce an event. A batch-altitude `TransactionBatchImported` event
dispatches exactly once per call, after every chunk has committed, only
when at least one row landed.

## `SaveTransactionSplit` — the sole split mutator

`Public/Actions/SaveTransactionSplit` is the sole mutator of
`transaction_splits`. `save()` re-checks the sum-to-parent `Money`
invariant inside the DB write transaction against a freshly re-read
parent `settled_amount_minor` (TOCTOU-safe, mirroring
`PotWriter::fund()`), and throws `SplitSumMismatchException` on
mismatch. Every leg's `category_id` is visibility-guarded
(`whereNull('user_id')->orWhere('user_id', $user->id)`) before persist.

**PK-preserving diff, never delete-all+reinsert.** The existing/incoming
leg sets are reconciled by id: a leg matched by id is UPDATEd in place
(preserving its primary key); an unmatched incoming leg is INSERTed; an
existing leg absent from the incoming set is DELETEd (tombstoned). Full
rows (not just ids) are re-read so the edit branch can compute a
genuine per-field dirty diff — the per-`(table, pk, field)` LWW sync
merge only converges correctly under two independent offline edits of
the same leg if each device's op-log Set carries only the fields it
actually changed. Re-dispatching every field unconditionally would let
one device's unchanged echo of a field silently clobber the other
device's real edit to that same field once HLC-ordered — a whole-row-
wins bug masquerading as field-level LWW.

**Note encryption + dirty-diff ordering.** The DB row stores the note
as ciphertext; the dirty-diff and dispatched-event payload always use
the plaintext value, so the op-log's own encrypt-on-write never
double-encrypts. Diffing against the old row's note requires decrypting
it first — comparing against fresh ciphertext (a new random nonce every
encryption) would never equal the old value and every note would look
dirty on every save. An empty string and `null` are canonicalised to
the same value before comparison and before write, so notes never
ping-pong between `''` and `null` across devices under LWW.

Every mutation dispatches its `TransactionSplitMutated`/
`TransactionMutated` event only after the DB transaction commits, never
from inside the open transaction closure.

`unsplit()` deletes every leg row and restores a single `category_id`
on the parent; the surviving category must be one of the split's
current leg categories (enforced when the split is non-empty). That
write also stamps `field_provenance['category_id'] = 'manual'`, in the
same transaction. No rule ever writes a leg category — the re-apply job
excludes split parents outright — so a surviving leg category is always
one the reader picked, and `'manual'` is the only value
`RuleApplier::applyAtReapply()` skips. Without the stamp, a reader who
picked the survivor in the unsplit dialog had that choice silently
overwritten by the next "re-apply to history", while the same choice made
through Reclassify (which goes through `AssignCategory`, and does stamp)
survived it.

## `CanonicalTransaction` field semantics

`Public/Dto/CanonicalTransaction` is the persistence-ready shape one
transaction row takes as it flows through Ingestion/Import; only
`RecordTransactions` persists it.

- `counterparty_normalized` is never null: `CounterpartyKey` substitutes
  a sentinel when the counterparty name is empty, so the composite
  UNIQUE on `transactions` catches duplicates even without a `source_ref`.
  For a user with at-rest encryption enabled it holds a keyed one-way digest
  of the normalised name rather than the name — equality and uniqueness
  survive, the merchant does not. See
  [Which columns are encrypted at rest](../sync/sensitive-columns-at-rest.md).
- `autoCategoryProvenance` is a nullable `{source: 'rule'|'memory',
  rule_id?, memory_id?, category_id}` shape stamped by
  `ApplyAutoCategoryStage`; `CategorizationProvenancePanel` reads it to
  know whether a suggestion came from an explicit rule or learned
  merchant memory.
- `paymentType` is the classifier stage's resolved enum value, mirrored
  by a DB-layer trigger that rejects any value outside the enum's
  cases; defaults to null so legacy call sites (fingerprint rederive,
  fixtures, `NormalizeStage`'s first pass) compile unchanged.
- `counterpartyId` is the FK the resolver stamps after upserting the
  matching `Counterparty`; stays null for the self-account branch and
  pathological no-name/no-IBAN/no-description rows. Intentionally
  excluded from the fingerprint tuple so re-resolving a row against an
  updated counterparty model never invalidates a historical fingerprint.
- `note` mirrors `transactions.note`; stamped only by
  `RuleApplier::applyAtImport()` when a firing rule carries a `note`
  action (both `set` and `append` resolve to the payload text outright
  since there is no prior stored note at import time). Null for every
  other ingestion path.

## Events and shared traits

**`TransactionBatchImported`** is dispatched exactly once per
`RecordTransactions::__invoke()` call, after every chunk has committed
— the opposite altitude to `TransactionImported`, which fires per
inserted row once that row's own chunk has committed. Not dispatched at all
when `insertedCount === 0` (a full-duplicate re-import has nothing to
announce). Dispatched outside any open DB transaction, satisfying the
emit-after-commit contract for free. `sourceFormats` is the distinct,
sorted set of `CanonicalTransaction::$sourceFormat` values across the
inserted rows — a list, not a single scalar, because a batch can
legitimately mix formats (e.g. a manual reconciliation batch landing
alongside receipt-derived rows); listeners route on whitelist
membership against this list rather than a single value.

**`HandlesClearedStatus`** is the reusable Livewire trait for the
cleared/uncleared/reconciled badge + toggle, mirroring
`HandlesTaxTagging`'s shape: a batch-load helper for per-row rendering
plus an `#[On(...)]`-wired toggle action so the badge works uniformly
from any component that mixes the trait in. Security: `clearedStatusFor()`
scopes its one query by `user_id` before `whereIn('id', ...)`;
`toggleClearedStatus()` reads and writes scoped by `id` + `user_id` (a
foreign/unknown id resolves to a silent no-op), and the next status
value is computed server-side and validated against
`Transaction::STATUSES` before the write — the client never supplies
the target string. A `reconciled` current status short-circuits with a
toast and no write, before any read of the "next" value.

## `AccountKind` — which kinds hold money

Three roll-ups sum a reader's accounts and each asks a slightly different
question, so for a while each kept its own list of kinds. They drifted, and one
of them handed the reader back money their bank had already paid out.
`AccountKind` now answers all three, and every exclusion is decided once.

**`mirrorsAnotherAccount()`** — `paypal_funding` and `google_play`. A row on
either restates a movement the paying account already carries:

- `paypal_funding` names a transfer that is posted on **both** real accounts it
  sits between. Nothing in the app ever writes an account of this kind — the
  resolver that owns the name writes `chain_links.kind = 'paypal_funding'`, a
  relationship between two transactions, not an account. The kind is the
  account-side vestige of that idea.
- `google_play` is the synthetic account a parsed Play receipt lands on, because
  Google publishes receipts and no statement. The purchase itself was charged to
  a card or a wallet, which carries it as `GOOGLE*WORKSPACE` or `Google Payment
  Ireland Ltd.` — both shapes are in this repo's own fixtures. `ChainLinkKind::
  FundedByCardHint` exists precisely to name that pairing, and naming it does not
  remove either row.

A Play account is also **only ever debited**: `GooglePlayReceiptMatcher` negates
every amount and skips a refund rather than crediting one. Its balance is a
cumulative spend tally, not a holding and not a debt, so a total that counted it
would walk downwards for as long as the reader kept buying with nothing on the
other side to walk it back. That is why the exclusion is not conditional on the
funding leg having been imported.

**`isLiability()`** — `ics_card`. A card balance is what is **owed**. It belongs
in the position and not in the cash.

**`holdsSpendableBalance()`** — everything that is neither, so `bank`, `paypal`
and `cash`. Money the reader holds and can spend.

| Consumer | Question | Predicate |
|---|---|---|
| `Forecasting::NetWorthQuery` | What do I hold and owe? | not `mirrorsAnotherAccount()` |
| `Reports::NetWorthSeriesQuery` | The same, plotted over time | not `mirrorsAnotherAccount()` |
| `Calendar::AccountResolver` | What can I spend, today and ahead? | `holdsSpendableBalance()` |
| `Forecasting::ForecastHighlightsQuery` | How low does my cash get in 30 days? | `holdsSpendableBalance()` |
| `Pots::PotBalanceQuery` / `PotWriter` | What can a pot be carved out of? | `holdsSpendableBalance()` |

The "lowest projected balance" tile sits on the cash side and not the position
side, though it is a Forecasting surface and its neighbour on the same page is
not: `ForecastChartView`'s aggregate curve is net worth over time and keeps the
card, while the tile is a forward-cash line that the reader reads as "how low do
I get". `BufferFloor::forKind()` had already reached that conclusion for the
shortfall count printed beneath the figure — it gives an `ics_card` no floor at
all, because a card is below zero every day of the horizon. The figure above it
raced the card anyway, and a card always wins.

The net-worth pair and the calendar **legitimately** differ by the liability, and
that difference must not be flattened: a credit-card debt belongs in net worth
while being unspendable, and a forward balance line that summed it would subtract
the settlement once when the charge posted and again when the bank paid it. They
must never differ by a mirror, because a mirror is a second copy of one movement
and no total may count it.

Both net-worth reads exclude with `whereNotIn`, never `whereIn`. A `kind` string
this build has never heard of is far likelier to be an account the reader holds
than a mirror of one, so the unknown case has to fall inside the total rather
than vanish from it.

## `AccountBalanceQuery` — caveats shared by all four methods

`currentBalance()`, `currentBalanceAsOf()`, `clearedBalance()`, and
`clearedBalanceAsOf()` all return an `AccountBalance` — a **line per
currency**, never one int. Each opens on the account's starting balance
and adds `settled_amount_minor` (never the native `amount_minor`) scoped
by `(account_id, user_id)`, grouped by `settled_currency` — see
[the baseline section below](#accountstartingbalancequery--the-baseline-every-balance-starts-from)
for what the baseline is and how its date bounds the sum. All four
share three caveats:

- **Information disclosure guard**: the explicit `where('user_id', ...)`
  ensures a foreign `account_id` returns the caller's own (empty)
  balance, never another user's transactions — and, since the baseline
  read is scoped the same way, none of the owner's starting balance
  either. An unreadable account has no lines at all, which is not the
  same answer as zero in some assumed currency.
- **The settled pair, not the native one**: `settled_amount_minor` is
  the row as the ACCOUNT holds it — an ICS account's USD Google Play
  charge carries its dollar figure in `amount_minor` and the euro one
  here — so a bank that converts on the reader's behalf lands one
  currency and one line.
- **One account, several currencies**: a bank that does *not* convert
  lands several. The Revolut CSV preset carries a `currencyHeader`, so
  `settled_currency` varies row to row and one account genuinely holds
  euro beside dollar. Summing across them produced 328885 for an account
  holding €3,509.85 and −$221.00, and the dashboard printed it as a euro
  net worth. The baseline opens the line of the account's own
  `default_currency`, at zero when there is no baseline, so an account
  with no rows still names the currency it is denominated in.

Consumers decide what to do with the set, and each states which
currency it answers in:

| Caller | Rule |
| --- | --- |
| `Forecasting`'s `NetWorthQuery` | every line, each converted at its own rate; a line with no rate is listed and left out of the total (`balancesWithoutRate`), never counted at par |
| `Reports`' `NetWorthSeriesQuery` | the same, at each bucket's `asOf` date |
| `Pots`' `PotBalanceQuery` | `default_currency` — pots and `pot_movements` are denominated in it, so only that line can be allocated |
| `/reconcile` | `default_currency` — a printed statement carries one denomination, and it is the account's own |
| `Forecasting`'s `BalanceAnchorResolver` / `ForecastQuery` | `default_currency` — a projection runs in one currency |

`BalanceAnchorResolver` calls `currentBalanceAsOf()` for every non-card
account, so the pot reconciliation header, the net-worth line, and the
forecast's opening balance are one number rather than three that happen
to agree. The calendar's past-day line adds the same column bucketed by
the same `settled_currency`
([balance aggregation](../calendar/architecture.md#balance-aggregation));
while it re-derived each foreign row from `amount_minor` at today's rate
its yesterday sat €1.46 above this figure and its line stepped at today.

`clearedBalance()` additionally restricts to `cleared`/`reconciled`
rows (excluding `uncleared` manual cash-book entries not yet confirmed
against a statement). `clearedBalanceAsOf()` further bounds by
`posted_at <= $asOf` so `/reconcile`'s "matched" check uses the same
window `ReconciliationWriter::completeReconcile()` locks — the
unbounded `clearedBalance()` would count rows posted after the
statement date that the write correctly leaves untouched.

The baseline belongs in the cleared figures too, not only in
`currentBalance()`: `/reconcile` compares against the balance a bank
printed on a statement, and that number counts the whole life of the
account, not only the part Beatrax has imported. A cleared balance that
started at zero could only ever match by coincidence.

## `BaseCurrency` — the reader's reporting currency

`BaseCurrency` (`Ledger\Public\Services`) is the one place the currency
a roll-up renders in resolves. `/settings` writes the reader's choice to
`users.base_currency`; about a hundred call sites across twenty-two
Blade templates format money through this service, and none of them pass
a user, so the service is what has to know who is reading.

Three entry points, and which one a caller wants is a real decision:

- `forUser(User $user)` — the reader's choice, for code that already
  holds the user: background jobs, and every query that takes a `User`
  argument. It reads the model attribute and issues no query.
- `code()` — the same answer for the reader of the current request. It
  resolves `CurrentUser` and delegates to `forUser()`.
- `installDefault()` — `config('currency.base')`, what an install ships
  with. Not a reader's answer, and named so a caller has to mean it.

`code()` fails closed on the split `Core\Public\Scopes\UserScope`
already draws. A **web** request with no authenticated reader gets a
`NotAuthenticatedException` rather than a guessed code, because the
figure standing next to the sign is somebody's real total and a wrong
sign over a right number is worse than no page. The **console** — the
install bootstrap, queue workers, the test suite — has no reader to have
a preference and takes `installDefault()`. Guest chrome that legitimately
has no reader, such as the chart-axis currency the login shell stamps on
`<html data-base-currency>`, asks for `installDefault()` outright.

A background job acting *for* a reader must carry that reader rather than
land in the console branch: either bind them to the guard for the
duration (`Position\Internal\Jobs\EmitPositionDigestJob` and
`Budgets\Internal\Jobs\EmitBudgetNudgesJob` both do, and
`Core\Internal\Listeners\ClearGuardBetweenJobs` unbinds between jobs),
or call `forUser()` with the user the job was queued for.

`users.base_currency` is nullable with no backfill and no DB default —
`User`'s Eloquent `$attributes` owns `EUR` for rows created through the
model, and two competing defaults would drift — so every user row older
than the column carries NULL. That is not an error state: it is a reader
who has never opened the picker, and `installDefault()` is the answer.
Reading the column raw instead of through `forUser()` handed NULL to
`Money::ofMinor()` and to `ExchangeRateService::convertToBase()`, which
is a `TypeError` on exactly the oldest installs.

The service is bound `scoped()`, not `singleton()`: one render reaches it
once per money figure, so a fresh instance per call site is waste, and
the queue worker drops scoped instances between jobs so the next job's
reader is resolved afresh rather than frozen from the first.

**Resolving is not converting.** `BaseCurrency` answers *which* code a
figure should be labelled in; turning an amount into it is `FX`'s job,
and an amount with no rate available is excluded from the total and named
rather than added one-to-one (`NetWorthQuery` is the reference).

## Changing an account's currency

`accounts.default_currency` is the account's own denomination (`B1-R17`).
Every creation site writes it from `BaseCurrency->code()` — the reader's
own reporting currency, which `/settings` lets them change — and
`/settings` also carries a per-account picker beside the opening-balance
editor so the reader can correct an individual account:
`Ledger\Public\Http\Livewire\AccountCurrencyEditor`, over
`Ledger\Internal\Actions\SetAccountCurrency`. The offered set is the
`currencies` reference table, the same one the base-currency picker
reads — not `Ledger\Public\Enums\Currency`, which names only the codes
the code itself writes as literals.

**The change relabels; it never converts.** `settled_amount_minor` and
`settled_currency` are what the account was actually debited, and no
write touches them. What moves is which line the account reports:

- The baseline is relabelled where it stands. `AccountStartingBalanceQuery`
  denominates `opening_balance_minor` / `starting_balance_minor` in
  `default_currency`, so the same integer opens a different line after
  the change — 12345 read as EUR before is 12345 read as USD after.
- Transaction rows keep the `settled_currency` they were booked in, so a
  row the account no longer names stays present as its own line.
- Every consumer in the table above that answers
  `->in($account->default_currency)` — pots, `/reconcile`, the forecast
  anchor — therefore reads a different line. An account whose rows are
  all USD starts reporting its real position once it is relabelled to
  USD; one relabelled to a currency it holds no rows in reports **zero**
  for it, which is what `AccountBalance::in()` answers for a line that
  does not exist. It is never a converted guess.

Because that is a meaning change and not a correction, the Action raises
`AccountCurrencyRelabelWarning` rather than writing, on the same
warn-do-not-block shape as `Forecasting`'s
`OpeningBalanceDivergenceWarning`: the banner states what will move,
lists the lines the account currently holds, and offers "change anyway"
or "keep the current code". It stays silent for an account with nothing
to misread — no transactions and no non-zero baseline — which is the
only case where the two labels describe the same position.

**The picker needs no migration.** `default_currency` is `char(3) NOT NULL`
with a database default of `EUR` since `create_accounts_table`, so every
existing row already carries a value and the column's shape does not
change. The picker writes the column that was always there.

One thing the change does *not* do: reproject the forecast.
`ForecastQuery` reads a stored `forecast_runs` row whose anchor came
from `BalanceAnchorResolver`, so a relabelled account's projection is
stale until the next run — exactly as it is after an import adds rows,
which has no reprojection trigger either. The four other balance
surfaces resolve `default_currency` live and are correct immediately.

## `AccountStartingBalanceQuery` — the baseline every balance starts from

`accounts.starting_balance_minor` / `starting_balance_date` is the
Ledger-owned, auto-detected position the imported history begins from
([A9](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a9-starting-balances.md)).
It is written by the demo seeder, by the statement-summary backfill, and
by the wizard's starting-balance card. It is **not**
`accounts.opening_balance_minor` / `opening_balance_as_of_date`, which is
Forecasting's manual override on the same row, written by
`SetAccountOpeningBalance` from the Settings editor. Both pairs exist on
purpose and both are read by `AccountStartingBalanceQuery`, which prefers
the override: it is the only figure the reader entered deliberately, so a
number they typed outranks one an import inferred. A third
`opening_balance_minor` lives on `statement_summaries` and is the source
the backfill reads, not a balance anyone displays.

`Public/Services/AccountStartingBalanceQuery` is the single reader. The
rule it encodes is:

```
balance = starting_balance_minor + SUM(transactions bounded below by starting_balance_date)
```

- **A NULL date means the baseline precedes all history**, so no lower
  bound applies and every row counts on top of it. This is the common
  shape: the demo seeder writes an amount with no date at all.
- **A non-NULL date bounds the sum at `posted_at >= starting_balance_date`.**
  The baseline is the position *before* that day's rows, so a row posted
  exactly on the date lands on top of it. Using `>` would lose that row;
  dropping the bound entirely would count everything before the baseline
  date twice, since the baseline already holds it.
- **The bound applies to the baseline's own currency only.** The amount is
  denominated in the account's `default_currency` and says what the account
  held *in that one*, so a row settled in another currency has no baseline
  covering it and is counted wherever it is posted. Both spellings of the
  bound carry this: `AccountBalanceQuery::sumFromBaseline()` in PHP and
  `AT_OR_AFTER_BASELINE_SQL` in SQL. It was missing from the SQL one, so a
  Revolut account with a euro baseline and a dollar row posted before its
  date read EUR3,509.85 on the calendar's past-day line against the
  EUR3,509.85 and -USD221.00 the accounts page reported for the same day.
- **A date with no amount is not a baseline.** The reader returns the
  absent shape rather than honouring a bound that would drop earlier rows
  and add nothing back.

`forAccount()` returns `minorUnits` / `currency` / `date` — never a bare
int, because the amount is denominated in the account's
`default_currency` and is meaningless without it. An account with no
baseline amount still reports that currency, at zero: `AccountBalanceQuery`
opens the account's own line from it, and a blank currency there would
leave a rowless account with no line to name.

`bucketedByDefaultCurrency()` exists for the one caller that cannot reach
a per-account date in PHP: `Calendar`'s `DailyBalanceAggregator` groups
across many accounts in a single query and buckets by *transaction*
currency, while each baseline belongs in the bucket of its own account's
`default_currency`. That query joins `accounts` and applies
`AT_OR_AFTER_BASELINE_SQL`, the one spelling of the lower bound for a
grouped read; the join is a LEFT JOIN so a transaction whose account row
is missing keeps counting exactly as it did before, rather than silently
vanishing from the line.

Consumers, all of which were separately re-implementing "the money on
this account" and all of which start from the baseline now:
`AccountBalanceQuery` (and through it `Reports`' `NetWorthSeriesQuery`,
`Pots`' `PotBalanceQuery`, `/reconcile`, and — via
`currentBalanceAsOf()` — `Forecasting`'s `BalanceAnchorResolver`), and
`Calendar`'s `DailyBalanceAggregator`. The ICS-card anchors are
deliberately excluded: a card takes its statement or zero, because
summing would double-count the billing events the projection re-emits.

## `FieldProvenanceWriter` — race-safe manual-vs-rule provenance

`Public/Services/FieldProvenanceWriter` reads/writes
`transactions.field_provenance`, a generic per-field manual-vs-rule
provenance map (`{"<logical field>": "manual" | "rule"}`, canonical
keys `category_id`, `note`, `counterparty_id`, `tax_tag`) consumed by
the re-apply-rules manual-edit guard: a field the user has hand-edited
must never be silently overwritten by a rule re-application. No writer
ever stamps a third `"import"` state: an absent key already means "not
manually set", so the contract is two-state.

Every stamp is a single DB-side `json_set` UPDATE, never a PHP
read-modify-write — two concurrent stamps to different keys both
survive because SQLite's per-row write serialization means the DB, not
PHP, owns the merge. Every write and read is scoped by
`(id, user_id)`; a foreign or missing transaction id is a silent no-op
(0 rows affected) or empty-array read, never a leak.

## `FingerprintComposer` — the v3 dedup algorithm

`Public/Services/FingerprintComposer` produces the canonical sha256
fingerprint of a `CanonicalTransaction` — the second-layer idempotency
guard behind the composite UNIQUE index on
`transactions(user_id, account_id, posted_at, booked_at, amount_minor,
currency, counterparty_normalized)`. The tuple is prefixed with
`user_id` so the same row imported under two different users hashes to
two different fingerprints — without that prefix the UNIQUE index
would reject the second user's row as a "duplicate" of the first.

`normalize()` collapses a raw counterparty name into the stable string
used inside the fingerprint tuple: lowercased, NFD-stripped of
combining marks, non-alphanumeric runs collapsed to single spaces,
whitespace-collapsed, trimmed and truncated to 80 UTF-8 characters. It is
the *text* normaliser only — `CounterpartyKey` is what turns its output into
the value the column stores, and for an encrypted user that is a keyed
digest. `compose()` treats the result as an opaque tuple member either way,
which is why a fingerprint re-derived from the stored column stays stable: a
hash of a hash is still deterministic, and needs no key.

`composeTuple()` takes the same seven values read straight from a row, for
the enable-time sweep that rewrites `counterparty_normalized` and must
rewrite `fingerprint` in the same statement.
`booked_at` carries second-resolution so two same-day same-merchant
same-amount entries posted seconds apart never collide. `source_ref`
is intentionally absent from the tuple: the same real-world transaction
surfaces in CSV and CAMT.053 exports with different reference values,
and the fingerprint must equate those.

Second-resolution is enough for an import, whose rows carry their own
times, and it is the *only* thing separating two hand-entered ones: a cash
entry is stamped with the clock's second on the day the reader named, so six
€2.50 coffees typed in a row are six writes of one tuple. `CashBook`'s
`RecordManualTransaction` therefore asks the ledger which second this exact
entry last occupied — same user, account, posted day, amount, currency and
counterparty — and books at the one after it, clamped inside the day so an
entry added before midnight is never nudged into the next tax year. It used
to walk five seconds forward from *now* and give up, silently, on the sixth
identical entry; the walk now starts past the collision instead of into it,
and the action returns whether a row was written so the page can say so
rather than toast "Cash entry added." over nothing. Two identical coffees on
one day are two facts, and a dedup rule that cannot tell them apart is
answering a question about imports with an answer about typing.

`NORMALIZATION_VERSION` is bumped whenever the tuple shape or
`normalize()`'s output changes; a stored row with a lower version
stamp signals "re-derive before comparing against the current
algorithm" — re-derive existing rows via the
`beatrax:rederive-fingerprints` artisan command when bumping.

## `ReconciliationWriter` — the terminal reconcile write path

`Public/Services/ReconciliationWriter` is the reconcile flow's own
vocabulary: an account, and the balance date the statement was printed
for. It vouches for the account — every client-supplied id is
re-validated as user-owned before any write — and hands the column
itself to
[`TransactionStatusWriter`](#transactionstatuswriter--the-one-writer-of-transactionsstatus).
`completeReconcile()` bulk-transitions an account's `cleared`
transactions posted on or before the statement date to `reconciled`;
`unreconcile()` reverts a single row back to `cleared`.

## `TransactionStatusWriter` — the one writer of `transactions.status`

`Public/Services/TransactionStatusWriter` mirrors `EnvelopeWriter`'s
shape: one DB transaction per operation, events dispatched only after
commit. It is the only thing in the tree that transitions the column,
and `BoundaryArchTest::noOtherTransactionStatusMutator` is what keeps
that true.

**Why one writer.** A `reconciled` row is the reader's own assertion
that Beatrax and a bank statement agree, so every mutator on the page
refuses one. That refusal was worth nothing while three places wrote
the column and none delegated: the migration importer re-stamped the
staged flag straight onto a row the reader had reconciled by hand, and
nothing on screen said so. The lock is only as strong as the narrowest
door into the column.

**The graph lives on the enum.** `ClearedStatus::allowedNext()` draws
the edges — `uncleared ↔ cleared`, `cleared → reconciled`, and
`reconciled → cleared` as the only exit — and `canTransitionTo()`
reads off it rather than restating it. Nothing reaches `reconciled`
from `uncleared`: a row nobody confirmed against a statement cannot be
asserted as checked against one. The writer's private `write()` asks
the graph before it asks the database, so a caller cannot name an edge
nobody drew.

**Four callers, four vocabularies.** `reconcileClearedUpTo()` is the
bulk lock behind `completeReconcile()`. `unreconcile()` is the escape
hatch every locked-row refusal points at. `toggleCleared()` is the
badge's tap, and it refuses a `reconciled` row rather than taking the
un-reconcile edge the graph allows — leaving that state is a decision
made on the detail page, never a side effect of a tap.
`restateFromSource()` is an importer adopting the flag its source
carries; a `reconciled` row refuses, and the run reports the refusal as
an unmapped item rather than swallowing it.

**The applier is deliberately not routed here.** An arriving sync op
writes the column generically, under the merge registry that declares
it mergeable. Re-deriving that decision on this side would make the two
devices disagree about what the merge decided, so
`OpLogEntryApplier` is pinned as the one admitted second writer.

**CRDT correctness**: a bulk status transition is never represented as
a single synthetic sync event — every transitioned row gets its own
`TransactionMutated('edit', ['status' => 'reconciled'])`, dispatched in
a loop after the transaction commits.

**Race safety in `reconcileClearedUpTo()`.** The transitioned id set is
captured as an explicit SELECT before the UPDATE, inside the same
transaction — never re-derived afterwards by matching
`updated_at = $reconciledAt`, since two calls landing in the same
wall-clock second (or sharing a frozen Clock) would stamp the same
timestamp and an after-the-fact re-select could not distinguish rows
this call transitioned from rows a prior call already locked (inflating
the reported count and re-dispatching events for already-reconciled
rows). The UPDATE re-asserts `status = 'cleared'` because a deferred
SQLite transaction takes its write lock at the UPDATE, not the SELECT —
a concurrent writer could flip a candidate between the two; if the
affected count disagrees with the candidate list, the transitioned set
is re-derived from the candidates the UPDATE actually stamped (safe to
match on `updated_at = $reconciledAt` there, since the `whereIn`
confines it to rows that were `cleared` at SELECT time).

**Race safety in `unreconcile()`.** The pre-read is not a lock — a row
could flip away from `reconciled` between that read and the UPDATE
(TOCTOU). Re-asserting `status = 'reconciled'` on the UPDATE itself
closes the window: if the row no longer qualifies, the UPDATE matches
zero rows, and the event dispatch is gated on that affected-row count
so a lost race never fires a spurious event.

## `TransactionType` — direction is not the question anomaly asks

Two questions get asked of a transaction's type, and for a while one method
answered both.

**`direction()`** answers *which way did the money move* — the one signs,
totals and same-side baselines depend on. `transfer_out` genuinely lowers a
balance, so it is `Direction::Expense`, and moving it would corrupt every
figure the app prints. That mapping is not negotiable.

**`isExternalMovement()`** answers *is this something the reader did with
someone else* — the question anomaly detection, "unusual charges" and
first-time-merchant logic are actually asking. `expense`, `income`, `fee` and
`refund` are yes; a fee is charged by a bank and a surprise one is exactly
what a reader wants flagged. `transfer_out` and `transfer_in` are the two
halves of one move between two of the reader's own accounts, and `adjustment`
is written only where an import found an amount of zero — a reconciliation
against nobody. All three are no.

Answering both from `direction()` alone put every internal transfer inside the
expense population twice over. A €225.00 card settlement was opened as a
`large` unusual charge and the reader's own card issuer was reported as a
`first_time` merchant, on both legs of the move; and every transfer sat in the
baseline the *real* charges were judged against, so a month of savings
transfers quietly raised the bar a genuine anomaly had to clear.

`isExternalMovement()` is a `match` over the cases with **no `default` arm**,
so a type added later cannot inherit an answer — it raises until someone
states one. `externalMovementValuesFor(Direction)` derives the scan set from
both predicates together, and there is deliberately no "every type facing this
direction" sibling for a caller to reach for by mistake; a rollup asking about
money movement wants [`MoneyFlow`](#moneyflow--the-one-definition-of-spend-income-and-net)
instead.

| Consumer | Question | Reads |
|---|---|---|
| `Anomaly::AnomalyEvaluator` | Is this row worth judging at all? | `isExternalMovementOf()` |
| `Anomaly`'s three detectors | What is the baseline drawn from? | `externalMovementValuesFor()` |
| `Recurring::TransactionSeriesMembershipQuery` | Does this row face the way its series does? | `directionOf()` |
| `Transfers::TransferPairer` / `PairUnlinker` | Does this row have a second leg? | `transferValues()` / `isTransfer()` |

`isExternalMovementOf()` coerces an unreadable `type` to **true**, the opposite
default from `directionOf()`'s `Expense`, and for the same underlying reason:
each falls back to what the caller did before the method existed. Judging a
row of unknown shape is recoverable — the reader dismisses an alert — while
abstaining on it is silent.

## `MoneyFlow` — the one definition of spend, income and net

`Public/Enums/MoneyFlow` answers a single question: which
`transactions.type` values each of the three rollups counts. `Spend` is
`expense` + `refund`, `Income` is `income` alone, and `Net` is all three.

A refund reverses an expense, so it belongs in spend with the sign it
already carries rather than in income: counted as income, `income -
spend` and `net` would disagree about it. `transfer_in`/`transfer_out`
are the two halves of one internal move and appear in no rollup;
`fee`/`adjustment` are disclosed beside a total rather than folded into
one (see `Reports`' `ReportMetric::disclosedTypes()`).

Three surfaces read this: `SpendByCategoryQuery` (and through it the
dashboard's "Top spending"), `ThisPeriodAtAGlanceQuery`'s
inflow/outflow/net, and `Reports`' `ReportMetric`, which now delegates
here rather than listing the types again.

They did not agree. The dashboard counted `income`/`expense` only, so a
EUR100.00 purchase with a EUR30.00 refund against it in the same month
read Out EUR100.00, In EUR0.00, Net -EUR100.00 — for a month in which
EUR70.00 left — and the refund appeared on no dashboard tile at all,
while the Reports row for the same category read EUR70.00. Restating
the rule in a comment in each reader is what let them drift; the comment
in `SpendByCategoryQuery` asserted the agreement that was not there.

## `SpendByCategoryQuery` — the split-aware spend read model

`Public/Services/SpendByCategoryQuery` selects the types
[`MoneyFlow::Spend`](#moneyflow--the-one-definition-of-spend-income-and-net)
names — never the amount's sign, and no sign filter of its own, because a
refund is signed the other way and is exactly what reduces the total. A
`transfer_out` to the reader's own card is negative and is not money
spent; selecting on the sign put one in the dashboard's "Top spending" as
EUR325.00 and made "this month vs last" read EUR2,818.11 against the
EUR2,459.11 the OUT tile on the same page gave for the same month.

It is also the single place that
decides how a split transaction's spend is attributed: a split parent
contributes zero directly to any category total — only its
`transaction_splits` legs do — while an unsplit transaction still rolls
up via its own `category_id`. "Is this split?" is answered only by
leg-row presence, never by `category_id` nullity (a split parent may
carry a vestigial non-null `category_id`).

**Broken-split fail-safe.** A parent rolls up via its own `category_id`
whenever its legs do not sum to its `settled_amount_minor` — one
correlated-subquery predicate covers all three cases: no legs (SUM is
NULL/0, never equals a non-zero parent, so the parent counts exactly
as unsplit behaviour always did); a valid split (SUM equals the
parent, so the parent is excluded and legs count); and a broken split
(SUM disagrees, e.g. a per-leg LWW replay left one surviving leg) —
falling back to the parent's own category rather than silently
attributing partial legs. The legs branch mirrors this with the
opposite sense: legs are only attributed when the split is internally
consistent, so the two branches never double-count nor drop spend.

Every method it exposes is keyed by currency, and none of them takes a
reporting currency to filter on. `forUserAndPeriodByCurrency()` groups by
`(category_id, currency)` for one period; `forUserAndSpanByCurrencyPerDay()`
adds `posted_at` so the envelope fold pays two queries for a whole walk
rather than two per period. `includeUncategorized` decides whether unsplit
rows with a null `category_id` roll up under id 0 (the dashboard's trend
wants them, so its total stays whole; the envelope fold does not, since
uncategorized spend matches no envelope).

There used to be a third method taking a single `$currency` and filtering
`settled_currency` on it. Handing it the reader's DISPLAY currency is the
one thing that must never happen: it drops every row settled elsewhere
instead of converting it, and the trend card read EUR1,602.45 under an OUT
tile reading EUR1,608.74 one card away — the difference being a single
JPY1,000 row. Converting is
[`ConvertedSpendByCategory`](#convertedspendbycategory--the-one-conversion-of-category-spend)'s
job, and the filtering method is gone so no new caller can repeat it.

All of them compute in the codebase's established "group in SQL, merge in
PHP" style rather than a raw SQL UNION, and all carry the same type
filter.

## `ThisPeriodAtAGlanceQuery` — the dashboard composer

`Public/Services/ThisPeriodAtAGlanceQuery::for()` builds the single
`DashboardSummary` payload for the "this period at a glance" home view:
inflow/outflow/net (integer SUM over the period window, scoped to one
display currency), `topCategories` (delegated to
`TopCategoriesByPeriodQuery`), `recentTransactions` (delegated to
`TransactionListQuery::recent`, which the display currency switches to
the settled projection rather than filtering, so every dashboard panel
agrees on the currency in view without losing rows),
`uncategorizedCount` (lifetime count driving the nav badge), and
`isFirstRun` (true when the user has zero transactions across all
time — the route handler redirects to `/imports/new` until then).

**Subtractive income rule.** Inflow/outflow filter by
`transactions.type`, never by amount sign — a `transfer_in` row carries
a positive amount but is an internal move between own accounts and
must not inflate the income tile (symmetric on the expense side for
`transfer_out`); fees and adjustments are likewise excluded. Which types
each of the three sums counts comes from
[`MoneyFlow`](#moneyflow--the-one-definition-of-spend-income-and-net), so
a refund lands in outflow and net with the sign it carries and reduces
both. `incomeForPeriod()` is the one canonical "subtractive income,
transfers excluded" definition in the codebase (`for()` calls it
internally, and `Modules\Budgets\CarryoverQuery` reuses it as its income
source) — do not add a second `WHERE type = 'income'` anywhere else.

**Currency scoping.** Money totals aggregate `settled_amount_minor`
grouped by `settled_currency`, then convert each bucket through
`CrossCurrencyTotal` — never summed across currencies, and never filtered
down to one of them. A currency the rate table cannot reach is left out of
the figure and named in `DashboardSummary::$unconvertedCurrencies`, which
the Net tile renders beneath itself. `net` is subtracted after conversion
rather than converted itself, so it cannot miss the two tiles above it by
a cent. Money is composed only at the DTO boundary (`Money::ofMinor`); the
SQL layer stays integer-pure to keep the query under the 50ms budget on 1k
rows. The raw query builder is used directly rather than the Eloquent
Builder because `phpstan-strict-rules`' `staticMethod.dynamicCall` rule
forbids calls like `Builder::count()`/`Builder::orderByDesc()`.

**`forByCurrency()`** returns one tile-row per distinct
`settled_currency` present in the period with non-zero activity
(either inflow or outflow), ordered alphabetically so the tile stack is
deterministic; zero-activity currencies are omitted by the `HAVING`
clause. It applies the same type filter as `for()` so original-currency
mode never double-counts internal transfers.

**`nextIcsSettlement()`** hands the whole read to
`CardStatementQuery::forecastTileForUser()`, in the module that owns
`card_statements`. It returns the most-recent `open`/`partially_settled`
row joined to an `ics_card` account, and the amount is the open balance
**less the credits carried into that statement**, floored at zero — not
the raw open balance, for
[the reason the write side gives](../chains/card-statement-lifecycle.md#credits-between-statements).
`dueDate = StatementDueDate::of(due_date, period_end)`: the day the
issuer printed where the statement printed one, and `period_end +
StatementDueDate::GRACE_DAYS` where it did not (no cadence inference). Returns null when no such statement exists, which
the Blade reads as "hide the tile entirely" (no "—" placeholder).

Composed here instead, off a second query, it deducted no credits: a
statement of €1,450.00 carrying a €200.00 credit was €1,250.00 on the
forecast highlights and €1,450.00 on the position tile, both dated the
same day. The date rule was already shared through one constant; the
amount rule now is too. The WHERE filters on `card_statements.user_id`
before any account join, so a forged user_id cannot leak another user's
statement.

**`emailScanHealth()`** returns up to three connected-inbox lines (in
`created_at` order) plus an overall status. A line is `'reauth'` when
that inbox needs reauth and `'healthy'` when it scanned successfully
within 24 hours; otherwise it is `'stale'` where a scan is scheduled
and fell behind, and `'unscheduled'` on a device that schedules none —
which is a phone, permanently and by design, so the amber dot there
flagged the ordinary condition as a fault nothing could clear. The
platform question goes through
`InboxScanSchedule::runsOnThisDevice()`, which asks
`MobileBackgroundSchedule::desktopOnly()` rather than the platform
alone, so the day a phone gains the scan the answer follows the
schedule (see
[a device that schedules no scan cannot be behind one](../email-scan/architecture.md#a-device-that-schedules-no-scan-cannot-be-behind-one)).
The overall status is the highest rank any line reached: reauth
outranks stale outranks unscheduled outranks healthy. `'unscheduled'`
sits above `'healthy'` because an unscanned inbox is not one the tile
can vouch for, and below `'stale'` because nothing was late. Returns
null when zero inboxes are connected. The `LEFT
JOIN` on `inbox_scan_state` preserves rows whose scan-state hasn't been
inserted yet (a transient window after the OAuth callback lands but
before the background fetcher stamps the row); such rows render with
`lastScanAt = null` treated as `'idle'`, matching `InboxQuery::makeDto()`.

## `PopulatedPeriodQuery` — where the records actually are

`Public/Services/PopulatedPeriodQuery::latestWithRecords($user,
$inView)` answers one question for the dashboard's empty state: is there
a period worth offering this reader, and which one. It returns the
`Period` the reader's own most recent transaction falls in, or `null`
when there is nowhere to go.

`null` covers two different readers, and telling them apart is the whole
point. A period that already holds records needs no offer. An install
with nothing imported needs the import path — offering it a jump to
nothing would be the same screen of zeros with a button on it. The
`EXISTS` over the period window separates the first; a `MAX(posted_at)`
that comes back empty separates the second.

**One read, not a walk.** The target is derived from a single
`MAX(posted_at)` on the `(user_id, posted_at)` index, never by stepping
`PeriodQuery::previous()` and asking again until something answers. A
reader whose last import was two years ago would pay two dozen
round-trips for that walk, on a phone, to reach a screen that exists to
say the walk is unnecessary. The `EXISTS` is asked first and is itself a
bounded range scan, so a populated period — the common case — costs one
query and the `MAX` is never issued.

**The period is the reader's own.** The date the `MAX` returns is taken
back to a period through
`PeriodQuery::containingDateForDay($postedAt, $user->period_start_day)`,
not `containing()` and not calendar-month arithmetic. On a 25th-to-24th
calendar, 17 April belongs to the period that opened on 25 March; a
month-based answer would land the reader on the empty period beside
their records and read as a control that does not work.

**Both directions, one destination.** The offer is the LATEST populated
period whether the reader arrived from the future (a February–April
statement viewed in August) or from the past (paged back before their
earliest record). One target rather than "the nearest populated period
in the direction you were heading": the reader who paged backwards is
lost in the same way as the reader who never moved, and a control whose
destination depends on how you got there is a control you cannot learn.

**Scoping.** Both reads filter `transactions.user_id` directly. This is
not only about reading another household member's rows — the offer's
mere presence or absence dates their transactions, so an unscoped
`EXISTS` leaks by staying silent just as an unscoped `MAX` leaks by
speaking. `AnEmptyPeriodSaysWhereTheRecordsAreTest` pins both halves.

## `TopCategoriesByPeriodQuery` — breadcrumb category tree walk

`Public/Services/TopCategoriesByPeriodQuery` delegates spend
aggregation and conversion to `Internal/Services/ConvertedSpendByCategory`
(which returns an unordered map of *signed* net spend), then hands that map
to `Public/Support/OutwardSpend`, which is where the ordering, the limit,
the whole and the share all come from. A category whose refunds outran its
spending is not spending: it is not ranked, it is not in the denominator,
and it is not what an empty ranking means. `percentageOfTotal` is each row's
share of the panel's own ranked total (not the user's overall outflow), so
it sums to ~1.0 for non-empty results and can never leave (0, 1].

`for()` therefore answers a `Public/Dto/TopCategories`, not a bare list:
`rows` is the ranking and `refunded`/`refundedCategoryCount` are what the
narrowing left out, so the card can name it the way the report donut names
`undrawnMinor`. `TopCategoryRow::barWidth()` answers for its own bar, which
is what keeps the clamp out of the template.

The breadcrumb itself is not this class's: it injects
`Public/Services/CategoryAncestry`, which `Reports`'
`CategorySpendQuery` renders the same breadcrumb from. `Public`
because Reports is a second module; a service rather than a `Support`
helper because it holds a `DatabaseManager`.

`CategoryAncestry::load()` loads the requested categories plus their
entire parent chain into one id-keyed map so `fullPath()` can resolve
the breadcrumb without per-row queries. The visibility predicate
(`user_id IS NULL OR user_id = $userId`) applies at every level of the
walk — a `parent_id` pointing cross-tenant terminates the chain at the
filtered-out parent rather than leaking a foreign user's category name
into the breadcrumb. The `$attempted` set tracks every id already
queried (regardless of whether it came back) so the grandparent of a
filtered-out parent is never re-enqueued, avoiding an extra empty
SELECT per visibility miss. An empty id list short-circuits before the
connection is resolved.

`fullPath()` guards against accidental parent cycles (Eloquent does
not enforce acyclicity) with both a `visited` set and a hard depth cap
(`MAX_PARENT_DEPTH`), so corrupt data can never spin the walk forever.
Both properties are pinned by
[`CategoryAncestryTest`](../../../Modules/Ledger/tests/Feature/CategoryAncestryTest.php).

## `ConvertedSpendByCategory` — the one conversion of category spend

`Internal/Services/ConvertedSpendByCategory` is the single place category
spend crosses currencies. It reads `SpendByCategoryQuery`'s
currency-keyed map, converts each currency's parts, and returns a
`ConvertedCategorySpend`: the map re-keyed by category id in the reader's
display currency, plus the codes no rate reached. Two readers share it —
`TopCategoriesByPeriodQuery` for the dashboard's "Top spending" and
`CategorySpendTrendQuery` for "this month vs last" — so the two panels on
one screen cannot answer the same question differently.

`CategorySpendTrendQuery` asks for it with `includeUncategorized: true`
and its total is therefore the same money the OUT tile counts, converted
the same way. It unions the codes from both of its periods into
`SpendTrend::$unconvertedCurrencies`, because a currency only last period
held would otherwise go unnamed beside a figure that leaves it out, and
`hasComparison()` stays true on that alone — spend nothing but an unpriced
currency and both totals are zero, which used to hide the card and with it
the only notice that anything had been left out.

### Conversion is grouped by currency, not by category

Spend arrives bucketed by the currency each row settled in, and reading
only the buckets already in the reporting currency showed a reader whose
accounts are denominated elsewhere no spend at all. So every bucket is
converted — but the grouping matters. Converting each *category's* slice
on its own rounds once per category, and the rounding is not free: the
dashboard's own rows drifted a cent away from the "Out" tile directly
above them (rows summing to EUR 1,132.21 under a tile reading EUR
1,132.22), and the same category read `Groceries EUR 105.04` here and
`EUR 105.05` on `/reports`.

The query therefore groups by **currency** first and converts each
currency's whole subtotal once through
`CrossCurrencyTotal::distribute()`, which hands the difference between
that subtotal and the sum of the separately-converted parts back to the
parts, largest magnitude first and ties by position. `Reports`'
`CurrencyModeApplier` calls the same method for the same reason, so the
two surfaces cannot answer the same question differently — a second
implementation of the redistribution is exactly how the drift came back
the first time.

A currency the rate table cannot reach yields `null` and is left out
rather than counted at one to one, which is the same choice the tile
above these rows makes about it — and, like the tile, the figure that
leaves it out names it rather than simply being short.

## `TransactionStatusQuery` — cross-module reconciled-lock check

`Public/Services/TransactionStatusQuery` exposes a transaction's
reconciled-lock status to other modules without reaching into Ledger
internals or the Eloquent `Transaction` model — e.g. Tax enforces the
reconciled lock on its cross-module tax-tag write paths through this
seam. Every read carries an explicit `where('user_id', ...)` guard so a
foreign transaction id resolves to the caller's own (empty) result — a
cross-user id reads as "not reconciled" rather than leaking existence.
`reconciledIdsAmong()` is the batch-path sibling of `isReconciled()`,
letting callers filter locked rows out of a bulk mutation in one query.

## `TransactionListQuery` — paginated list read

`Public/Services/TransactionListQuery` powers the dashboard's "recent
transactions" panel and `/transactions`, via two entry points:
`recent($user, daysBack=90)` filters to `posted_at >= today - daysBack`
using the injected Clock (deterministic under `setTestNow()`);
`fullHistory($user)` returns every row, which the page switches to on
"Show full history".

**Currency projection.** Both entry points accept an optional
`$currency` argument. It is a PROJECTION, not a filter: no row is
dropped for being settled in another currency — `TheGlanceCountsALedgerNotDenominatedInTheReaderCurrencyTest` pins that, and the sibling
top-categories query converts such a row rather than hiding it. What the
argument changes is which pair is rendered: it projects the settled pair
as `display_minor`/`display_currency`, keeping a EUR view coherent when
the native pair is foreign (e.g. a USD Google Play charge settled to
EUR). A row settled in a third currency is therefore still listed, in
its own currency — `ThisPeriodAtAGlanceQuery` names those in
`unconvertedCurrencies` and the list does not yet say the same thing.
When `$currency` is null, the
native pair projects instead, and a `secondary_minor`/
`secondary_currency` pair is additionally selected so the row DTO can
carry the settled-EUR Money alongside the native Money — the Blade
renders a two-line stack only for true FX rows (native currency
differs from settled currency); EUR-native rows collapse to one line.
In EUR-only mode the secondary pair is omitted entirely, since the
rendered amount already is the settled-EUR figure.

**Cursor pagination** selects `limit + 1` rows and trims the last one
off to compute `hasMore`. The cursor is a `(posted_at, id)` pair,
compared via SQLite's row-value tuple compare (`WHERE (posted_at, id) <
(?, ?)`) — the pair, not `id` alone, is required because rows inserted
out of chronological order can share a `posted_at` value, which a
single-column cursor would silently drop. A legacy single-id cursor
(no `cursorPostedAt` supplied) falls back to a plain `id <` filter for
backwards compatibility.

The sort is the other half of that contract, so it lives with it:
`TransactionCursor::orderNewestFirst($query)` is the single owner of
`ORDER BY posted_at DESC, id DESC`, and the four queries that page on
this cursor — `TransactionListQuery`, `SearchQuery`,
`UncategorizedTriageQuery` and `FtsCandidateResolver` — all call it
rather than spelling the pair out. A query that breaks the tie the
other way pages past rows the comparison then skips.

**The date a row shows is the date it is sorted by.** The row DTO field
is `postedAt`, carrying `Fmt::shortDate(posted_at)` — the same column
the cursor above orders and pages on. It used to carry `booked_at`,
which is equal to `posted_at` from every adapter except `IcsPdfAdapter`
and is a day later on every row of a real card statement, so the list
printed a sequence that stepped back up wherever two sources shared a
`posted_at`. `TriageRow` and `SearchRowDto` name the same field for the
same reason. `booked_at` reaches a screen in one place now: the detail
page draws a `Booked :date` line under the posted date, and only when
the two name different days. See [A list sorted by a column it does not
show](../../conventions/invariants-from-shipped-failures.md#a-list-sorted-by-a-column-it-does-not-show).

**Counterparty slug.** An empty (not null) slug is treated as "no
slug" so the Blade falls back to plain text instead of generating a
dead-end `/counterparties/` URL — self-account rows are the only
documented producer of an empty slug (the resolver intentionally writes
no counterparties row for them).

## Guarded write actions

`ReassignCounterparty` and `UpdateTransactionCategory` are pure guarded
writes: no event dispatch, no provenance stamp — the caller
(`TransactionDetail`, the categorization rule engine) owns emitting
`TransactionMutated` and stamping provenance via
`FieldProvenanceWriter`. Both share the same guard order: reconciled
lock (`status === 'reconciled'` -> 0, no write), target-row ownership
(cross-user or unknown id -> 0, silent no-op), then write-only-on-change
(current value already equals the target -> 0, no redundant write or
spurious event upstream).
