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

The Phase 1 deliverable: "see my ASN month" lives here. Every later
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
  - `TopCategoriesByPeriodQuery::for($user, $period)` — top
    categories within a period.
  - `TransactionListQuery::recent($user, $daysBack, $cursorId,
    $limit, $cursorPostedAt, $currency)` and
    `TransactionListQuery::fullHistory($user, $cursorId, $limit,
    $cursorPostedAt, $currency)` — the transactions list read,
    each returning a `TransactionListPage`. There is no
    `page()` taking a filter bag and a page number: the list is
    KEYSET-paginated, so the caller carries an opaque
    `(postedAt, id)` cursor forward rather than an offset, and
    the only filter the query itself applies is the display
    currency. The two methods differ only in the date floor —
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

Statement pre-fill sourcing by account kind:

- `asn` accounts — latest `statement_summaries.closing_balance_minor`
  / `closing_balance_date`.
- `ics_card` accounts — latest `card_statements.total_amount_minor` by
  `period_end` DESC. Never `open_balance_minor` — this table is
  read-only here; the sole legal mutator is Chains'
  `CardStatementStateMachine`.
- Any other kind (paypal, generic CSV, cash book) — no statement
  source; fields stay blank for manual entry.

IDOR: `$accountId` is a client-controllable, URL-bound property. Every
read re-validates account ownership by `user_id` before touching
`statement_summaries` / `card_statements` / `AccountBalanceQuery`, and
`ReconciliationWriter::completeReconcile()` re-scopes by `user_id`
again on the write side. A foreign `accountId` shows and does nothing.

## `/transactions/{transactionId}` — detail page

`Internal/Http/Livewire/TransactionDetail` renders the row's headline
metadata plus a conditional "Effective rate" row (only when
`fx_rate_used` is non-null), the Reclassify control, and the inline
split editor. DI-only: no constructor; service collaborators arrive as
parameters on `mount()`, `render()`, and action methods. Every query
carries an explicit `where('user_id', ...)` predicate; a foreign
transaction resolves to 404 in `mount()` before any data is exposed.

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

**Reconciled lock.** Every mutating action (`reclassify`,
`reclassifyCategory`, `saveNote`, `reassignCounterparty`,
`deleteTransaction`, `toggleLegTax`) reads the transaction's `status`
in the same user-scoped query used for its ownership check, and warns
(no write) when the row is `reconciled`. `unreconcile()` is the escape
hatch every warn-toast points to, delegating to
`ReconciliationWriter::unreconcile()` — the sole mutator that reverts
a `reconciled` row back to `cleared`.

**Category correction-divergence bridge.** `reclassifyCategory()`
reads the row's prior `auto_category_provenance` before invoking
`AssignsCategory`, then re-emits a Livewire-local
`correction-divergence:fire` event carrying the same fields as the
framework `CategorizationDiverged` event so the globally-mounted toast
surfaces the "Update rule / Keep current rule" choice within the same
request lifecycle. The dual-channel pattern keeps the framework event
reusable by non-UI consumers (audit-log, analytics) while the local
event drives the toast without a redirect or broadcaster.

**Split editor.** In-memory leg rows (session-local until `saveSplit()`
persists them — opening the editor or editing a field never touches
`transaction_splits`). `remainingMinor` is always server-truthful,
computed via the `Money` value object on every leg-amount change — no
client math. Removing a leg at exactly 2 remaining, or unsplitting,
routes through the same two-step confirm UI scoped to the surviving
leg's category; a never-persisted editor collapses purely in memory
(no mutator call, no op-log entry) since there is nothing to reverse.
Tax tagging is leg-aware and requires a persisted leg id.

**Savings-goal attribution.** `attributeToGoal()` / `removeGoalAttribution()`
write the `goal_contributions` pivot through Goals'
`GoalContributionWriter`, which re-asserts ownership of both the goal and
the transaction and no-ops silently on a foreign id. This is the one
mutating action on the page NOT behind the reconciled lock: it writes a
separate row and leaves the reconciled transaction untouched, and a
reconciled row is exactly the confirmed money a goal wants to count.

**Delete.** Emits a `delete` tombstone for the parent transaction plus
one `TransactionSplitMutated` delete tombstone per leg (read before the
delete, since the DB FK cascade removes the leg rows locally) — sync
convergence must not rely on the peer's replay connection having FK
cascade active; each deletion is an explicit, first-class op in the
log.

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
present: `'eur'` projects the settled-EUR pair (one line per row);
`'original'` projects the native pair with a settled-EUR secondary
line on foreign-currency rows.

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
current leg categories (enforced when the split is non-empty).

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
  `ApplyAutoCategoryStage`; the correction-divergence flow reads it to
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

## `AccountBalanceQuery` — caveats shared by all three methods

`currentBalance()`, `clearedBalance()`, and `clearedBalanceAsOf()` all
open on the account's starting balance and add `settled_amount_minor`
(never the native `amount_minor`) scoped by `(account_id, user_id)` on top
of it — see
[the baseline section below](#accountstartingbalancequery--the-baseline-every-balance-starts-from)
for what the baseline is and how its date bounds the sum. All three
share two caveats:

- **Information disclosure guard**: the explicit `where('user_id', ...)`
  ensures a foreign `account_id` returns the caller's own (empty)
  balance, never another user's transactions — and, since the baseline
  read is scoped the same way, none of the owner's starting balance
  either.
- **Single currency, by the settled pair**: the sum has no currency
  filter and does not need one. It adds `settled_amount_minor`, which is
  the row as the ACCOUNT was debited — an ICS account's USD Google Play
  charge carries its dollar figure in `amount_minor` and the euro one
  here — so an account holding several transaction currencies still
  totals in its own. The baseline is added in the account's
  `default_currency` on the same footing. `BalanceAnchorResolver`'s
  fallback sums the same column, so the pot reconciliation header, the
  net-worth figure and the forecast anchor stay consistent.

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

## `AccountStartingBalanceQuery` — the baseline every balance starts from

`accounts.starting_balance_minor` / `starting_balance_date` is the
Ledger-owned, auto-detected position the imported history begins from
([A9](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a9-starting-balances.md)).
It is written by the demo seeder, by the statement-summary backfill, and
by the wizard's starting-balance card. It is **not**
`accounts.opening_balance_minor` / `opening_balance_as_of_date`, which is
Forecasting's manual override on the same row and is read by
`BalanceAnchorResolver::fromUserInputOpeningBalance()`; both pairs exist
on purpose and neither substitutes for the other. A third
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
- **A date with no amount is not a baseline.** The reader returns the
  absent shape rather than honouring a bound that would drop earlier rows
  and add nothing back.

`forAccount()` returns `minorUnits` / `currency` / `date` — never a bare
int, because the amount is denominated in the account's
`default_currency` and is meaningless without it.

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
`Pots`' `PotBalanceQuery`, and `/reconcile`), `Calendar`'s
`DailyBalanceAggregator`, and `Forecasting`'s
`BalanceAnchorResolver::fromTransactionsSum()`. The ICS-card zero anchor
is deliberately excluded: a card with no anchor takes zero because
summing would double-count the billing events the projection re-emits.

## `FieldProvenanceWriter` — race-safe manual-vs-rule provenance

`Public/Services/FieldProvenanceWriter` reads/writes
`transactions.field_provenance`, a generic per-field manual-vs-rule
provenance map (`{"<logical field>": "manual" | "rule"}`, canonical
keys `category_id`, `note`, `counterparty_id`, `tax_tag`) consumed by
the re-apply-rules manual-edit guard: a field the user has hand-edited
must never be silently overwritten by a rule re-application. A third
`"import"` state was originally documented but is never stamped by any
writer — an absent key already means "not manually set", so the
two-state contract is the actual one.

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

`NORMALIZATION_VERSION` is bumped whenever the tuple shape or
`normalize()`'s output changes; a stored row with a lower version
stamp signals "re-derive before comparing against the current
algorithm" — re-derive existing rows via the
`beatrax:rederive-fingerprints` artisan command when bumping.

## `ReconciliationWriter` — the terminal reconcile write path

`Public/Services/ReconciliationWriter` mirrors `EnvelopeWriter`'s
shape: one DB transaction per operation, events dispatched only after
commit, every client-supplied id re-validated as user-owned before any
write. `completeReconcile()` bulk-transitions an account's `cleared`
transactions posted on or before the statement date to `reconciled`;
`unreconcile()` reverts a single row back to `cleared`.

**CRDT correctness**: a bulk status transition is never represented as
a single synthetic sync event — every transitioned row gets its own
`TransactionMutated('edit', ['status' => 'reconciled'])`, dispatched in
a loop after the transaction commits.

**Race safety in `completeReconcile()`.** The transitioned id set is
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

## `SpendByCategoryQuery` — the split-aware spend read model

`Public/Services/SpendByCategoryQuery` is the single place that
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

Two methods cover the two shapes existing call sites need: a
single-currency map for `TopCategoriesByPeriodQuery`/
`CategorySpendTrendQuery`, and an all-currencies-keyed map reproducing
`BudgetProgressQuery`'s `(category_id, currency)` grouping (which
always excludes uncategorized spend, since it cannot match a budget).
Both compute in the codebase's established "group in SQL, merge in
PHP" style rather than a raw SQL UNION.

## `ThisPeriodAtAGlanceQuery` — the dashboard composer

`Public/Services/ThisPeriodAtAGlanceQuery::for()` builds the single
`DashboardSummary` payload for the "this period at a glance" home view:
inflow/outflow/net (integer SUM over the period window, scoped to one
display currency), `topCategories` (delegated to
`TopCategoriesByPeriodQuery`), `recentTransactions` (delegated to
`TransactionListQuery::recent`, filtered to the same display currency
so every dashboard panel agrees on the currency in view),
`uncategorizedCount` (lifetime count driving the nav badge), and
`isFirstRun` (true when the user has zero transactions across all
time — the route handler redirects to `/imports/new` until then).

**Subtractive income rule.** Inflow/outflow filter by
`transactions.type`, never by amount sign — a `transfer_in` row carries
a positive amount but is an internal move between own accounts and
must not inflate the income tile (symmetric on the expense side for
`transfer_out`); refunds, fees, and adjustments are likewise excluded.
`incomeForPeriod()` is the one canonical "subtractive income, transfers
excluded" definition in the codebase (`for()` calls it internally, and
`Modules\Budgets\CarryoverQuery` reuses it as its income source) — do
not add a second `WHERE type = 'income'` anywhere else.

**Currency scoping.** Money totals aggregate `settled_amount_minor`
filtered by `settled_currency = $displayCurrency` — multi-currency
users see a single-currency total rather than a silently summed mix.
Money is composed only at the DTO boundary (`Money::ofMinor`); the SQL
layer stays integer-pure to keep the query under the 50ms budget on 1k
rows. The raw query builder is used directly rather than the Eloquent
Builder because `phpstan-strict-rules`' `staticMethod.dynamicCall` rule
forbids calls like `Builder::count()`/`Builder::orderByDesc()`.

**`forByCurrency()`** returns one tile-row per distinct
`settled_currency` present in the period with non-zero activity
(either inflow or outflow), ordered alphabetically so the tile stack is
deterministic; zero-activity currencies are omitted by the `HAVING`
clause. It applies the same type filter as `for()` so original-currency
mode never double-counts internal transfers.

**`nextIcsSettlement()`** returns the most-recent `open`/
`partially_settled` `card_statements` row joined to an `ics_card`
account: `amount = open_balance_minor` (the open balance is the
forecast — no cadence inference), `dueDate = period_end + 5 calendar
days` (constant forecast lag). Returns null when no such statement
exists, which the Blade reads as "hide the tile entirely" (no "—"
placeholder). The WHERE filters on `card_statements.user_id` before any
account join, so a forged user_id cannot leak another user's statement.

**`emailScanHealth()`** returns up to three connected-inbox lines (in
`created_at` order) plus an overall status: `'reauth'` when any inbox
needs reauth, `'stale'` when no inbox needs reauth but at least one
hasn't scanned successfully within 24 hours (or never), `'healthy'`
otherwise. Returns null when zero inboxes are connected. The `LEFT
JOIN` on `inbox_scan_state` preserves rows whose scan-state hasn't been
inserted yet (a transient window after the OAuth callback lands but
before the background fetcher stamps the row); such rows render with
`lastScanAt = null` treated as `'idle'`, matching `InboxQuery::makeDto()`.

## `TopCategoriesByPeriodQuery` — breadcrumb category tree walk

`Public/Services/TopCategoriesByPeriodQuery` delegates spend
aggregation to `SpendByCategoryQuery::forUserAndPeriod()` (which
returns an unordered map), then re-applies DESC-by-spend ordering and
the limit in PHP. `percentageOfTotal` is each row's share of the
panel's own total (not the user's overall outflow), so it sums to
~1.0 for non-empty results.

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
`$currency` filter. When supplied, the query restricts to rows whose
`settled_currency` matches and projects the settled pair as the
rendered amount (`display_minor`/`display_currency`), keeping a EUR
view coherent even when the native pair is a foreign currency (e.g. a
USD Google Play charge settled to EUR). When `$currency` is null, the
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

**Counterparty slug.** An empty (not null) slug is treated as "no
slug" so the Blade falls back to plain text instead of generating a
dead-end `/counterparties/` URL — self-account rows are the only
documented producer of an empty slug today (the resolver intentionally
writes no counterparties row for them), but a future GC-orphan edge
could also surface one.

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
