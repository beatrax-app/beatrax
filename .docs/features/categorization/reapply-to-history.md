# Re-applying rules to existing transactions

Rules normally run once, at import. But a user who has just written a
rule wants it applied to the history they already have, and a user who
has just fixed a rule wants the damage undone. `ReapplyRulesJob` walks
every existing transaction for one user and re-runs the whole
match-and-apply pass over it.

Walking a user's entire ledger is the most destructive operation in the
module. This page is about the guards that make it safe to run twice, and
what a partial run leaves behind.

## What the walk covers

The row set is every transaction for the user **except split parents**:

    ->where('transactions.user_id', $userId)
    ->whereNotExists(/* a row in transaction_splits for this transaction */)

A split parent is excluded because its legs carry the categories;
re-categorising the parent would write a value that no read surface uses
and that contradicts its own legs. The legs themselves are not
transactions and are not visited.

Rows are read with `lazyById(500, ...)` ordered by `transactions.id` and
processed in chunks of 500. `lazyById` keys off the last id seen rather
than an OFFSET, so rows written while the walk is running cannot cause it
to skip or repeat a row. The chunk size is the same constant for the read
and the processing loop.

## Reconciled rows are skipped

Once per chunk, `TransactionStatusQuery::reconciledIdsAmong()` resolves
which of the 500 ids are reconciled, and `processRow()` skips those,
counting them into `reconciled_skipped`.

A reconciled transaction has been confirmed against a bank statement.
Rewriting its category after the fact would change a figure the user has
already signed off, so reconciliation is a hard stop for automated
writes. The check is batched per chunk rather than per row — one query
per 500 rows, not 500 queries.

## The KEK degradation

Before the walk starts, the job asks `AppLockKeyService::release()` for
the app-lock key encryption key:

    $hasKek = $appLockKeyService->release($session) !== null;

`counterparty_name` and `description` are encrypted at rest. Text
conditions match against those two fields, so without the KEK the job
cannot see plaintext and every text condition matches against ciphertext
— which is to say, matches nothing.

The job does **not** abort in that case. It logs a warning naming the
consequence and runs anyway, because amount and date conditions still
work correctly and a partial re-apply is more useful than none. The
important property for a reader: a run with no KEK is not a failed run,
it is a run whose text rules silently did nothing. If a user reports
"my merchant rule did not apply to history", this is the first thing to
check.

## Per-row fail-open

`processRow()` wraps the match-and-apply for a single row in a catch that
logs and increments `rows_errored`.

The failure this prevents is specific and it is not hypothetical: one
malformed condition value — a `date` condition whose stored value does
not parse — throws inside `CarbonImmutable::parse()` for **every** row in
the walk, because the same rule is evaluated against every row. Without
the per-row catch, one poisoned rule kills the entire queued job on the
first transaction and the user sees a run that never finishes. With it,
the poisoned rule contributes nothing and every other rule still applies.

`rows_errored` is carried in the progress payload rather than only in the
log, so the UI can show that a run completed with skipped rows instead of
reporting a clean success.

## Progress and uniqueness

Progress is a `ReapplyProgress` array written to the cache under
`progressCacheKey($userId)` after every chunk, and once more at the end
with `status` flipped to `done` and `finished_at` set. It carries
`checked`, `total`, `fields_updated`, `transactions_updated`,
`reconciled_skipped` and `rows_errored`.

The cache TTL is 3600 seconds — long enough to outlive the whole run
including retries, plus a reasonable gap for the UI to poll after
completion. A run that dies without ever writing `done` leaves its last
chunk's payload behind until the TTL expires; the UI reads that as a run
that stopped, not as one still in progress, because `finished_at` stays
null while `status` stays `running`.

The job is `ShouldBeUnique`, keyed per user, so a user hammering the
"re-apply" button cannot start two concurrent walks over the same rows.

## Re-running is a genuine no-op

Every write path reads before it writes, so a second run over unchanged
data reports zero changes rather than rewriting identical values.

That read is load-bearing in two places where it looks redundant:

- `writeCategory()` reads the current `category_id` first because
  `UpdatesTransactionCategory` has no write-only-on-change guard, **and
  because an UPDATE that sets a column to the value it already holds
  still reports one affected row on SQLite.** Without the pre-read, the
  affected-row count would report a change on every run, every run would
  dispatch a `TransactionMutated` event, and every run would push a
  no-op mutation into the op log for every matching transaction.
- `writeTaxTag()` reads the existing tag row first for the same reason,
  rather than trusting the upsert to be idempotent.

`writeTaxTag()` also re-reads and decrypts the existing note before
calling `TagTransaction::execute()`. That is not an optimisation:
`updateExisting()` rewrites note, category and year together the moment
any one of them is non-null, so passing a literal null for the note would
silently erase a note the user wrote by hand. See
[the tag write contract](../tax/tag-write-contract.md).

The note path has the mirror-image subtlety. Under `append` mode the
final text is only known after the write, so the value reported in
`dirtyFields` is re-read from the row rather than taken from the action
payload — and it must be decrypted first, because the op log encrypts on
write and would otherwise double-encrypt an already-encrypted value.

## Related pages

- [Rule evaluation order](rule-evaluation-order.md) — what the walk
  evaluates for each row.
- [Field provenance](field-provenance.md) — why a hand-corrected field
  survives the walk.
- [The tag write contract](../tax/tag-write-contract.md) — the
  full-payload upsert the tax-tag action goes through.
