# An id read after an insert

`insertGetId()` ends in `PDO::lastInsertId()`, which is **per connection, not
per table**. It answers "what was the last rowid this connection allocated",
and on the phones something else can allocate one in between.

## What allocates one in between

`Modules\Core\Internal\Listeners\ForgetNavCountsOnWrite` is registered on
`Illuminate\Database\Events\QueryExecuted`. `Illuminate\Database\Connection`
dispatches that event from inside `Connection::run()` — **after** the statement
executes and **before** `insertGetId()` reads the id back. The listener calls
`NavCountsService::bumpGeneration()`, which increments a cache key, and the
mobile builds run the cache **in the database**
(`mobile-app/bootstrap/app.php` sets `cache.default` to `database`), on the
same connection.

The increment is an `UPDATE` once the key exists. The first time it does not,
the fallback writes the key with `forever()`, and that is an `INSERT` into
`cache` — a new rowid, on the same connection, between the statement and the
read.

## What it cost

The cash book, on a clean install, on both an Android phone and an iPhone:

```
insert into "import_runs" (…)          -> import_runs rowid 1
  QueryExecuted -> bumpGeneration
    insert into "cache" (…)            -> cache rowid 2
lastInsertId()                         -> 2
insert or ignore into "transactions" (… "import_run_id" …) values (…, 2, …)
  -> SQLSTATE[23000] FOREIGN KEY constraint failed
```

The first entry a brand-new account ever made threw an unhandled
`QueryException`. iOS drew it as a full-screen native panel carrying the
database's absolute path and the whole statement. Retrying worked, because the
second pass found the import run by its match instead of creating one.

Desktop never saw it: `.env.bundled` keeps `CACHE_STORE=file`, so nothing
writes to the database from inside the event. A self-hosted server does see it
— `deploy/server/.env.example` sets `CACHE_STORE=database` on the same SQLite
file the app writes to.

## What has to be true for it to fire

It is not "any write on a device". Four things have to hold at once, and the
third is the narrow one:

1. The cache store is `database`, on the connection the write is using.
2. The statement is an `INSERT` into a table `NavCountsService::countedTables()`
   names, outside the migration window.
3. `nav-counts:generation` is **absent from the `cache` table at that instant**.
   `DatabaseStore::increment()` answers `false` only while the row is missing,
   and only then does the `forever()` fallback run — an upsert that inserts, and
   allocates a rowid. Once the key exists every bump is an `UPDATE` and
   allocates nothing. So the window is one statement per key lifetime: the first
   counted write after an install, after `cache:clear`, or after anything that
   empties the table.
4. The rowid that `cache` insert takes differs from the one the counted row just
   took. On a database where both tables are empty, both get rowid 1 and the
   wrong answer is accidentally the right number.

That is why a clean install can import a statement and come out correct: if any
earlier counted write already seeded the generation key — an onboarding step, a
demo seed, a receipt — condition 3 is spent and the import is safe. The probe
that discriminates is `select rowid, key from cache order by rowid` before the
write: if a key ending `nav-counts:generation` is already there, this cannot
fire on that statement, and whichever counted table wrote first is the one that
took it.

The rule below does not depend on any of that. None of the sites it names reads
`lastInsertId()` at all, so which listener happens to run, and in what order, is
no longer a thing the ledger's correctness rests on.

## The rule

**Read the id back by the criteria that identify the row, not from
`insertGetId()`, whenever the table is one `NavCountsService::countedTables()`
names.** Those are the tables whose write fires the bump:

`transactions`, `recurring_series`, `counterparties`, `drift_alerts`,
`envelope_assignments`, `import_runs`, `tax_transaction_tags`.

An `insertGetId()` into any other table is safe *today* — the listener matches
the quoted identifier in the statement, so `"transaction_splits"`,
`"envelope_moves"` and `"merchant_memories"` do not fire it. That safety is a
property of the badge list, not of the call site: **adding a badge for a table
turns every `insertGetId()` into it into this bug.** Add the read-back at the
same time.

## Where the rule is applied

`Modules\Core\Public\Support\IdReadBack` is that read: `of()` for a row that
must exist, `orNull()` for a lookup that may legitimately find nothing.

| Site | Table | Wrote through | Read back by |
|---|---|---|---|
| `Modules/CashBook/Internal/Actions/RecordManualTransaction.php` | `accounts`, `import_runs` | query builder | the `findOrCreate` match |
| `Modules/Budgets/Public/Services/EnvelopeWriter.php` | `envelope_assignments` | query builder | user, category, period start |
| `Modules/Budgets/Public/Services/EnvelopePeriodRekeyer.php` | `envelope_assignments` | query builder | user, category, period start |
| `Modules/Migration/Internal/Pipeline/PromoteStagingToDomain.php` | `import_runs` | query builder | user, source format |
| `Modules/Import/Public/Actions/RunImport.php` | `import_runs` | `ImportRun::create()` | user, sha256 |
| `Modules/Receipts/Internal/ReceiptLedgerBridge.php` | `import_runs` | `ImportRun::firstOrCreate()` | user, sha256 |
| `Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php` | `counterparties` | `Counterparty::firstOrCreate()` | user, slug |
| `Modules/Ledger/Database/Seeders/Demo/DemoTransactionsSeeder.php` | `import_runs` | `ImportRun::updateOrCreate()` | user, sha256 |
| `Modules/Ledger/Database/Seeders/Demo/DemoTransferPairsSeeder.php` | `import_runs` | `ImportRun::updateOrCreate()` | user, sha256 |

The bottom five reach `insertGetId()` **through Eloquent**, which is the half a
grep for `insertGetId(` cannot see. `Model::performInsert()` ends in
`insertAndSetId()`, so `create()`, `firstOrCreate()`, `updateOrCreate()`, a
relation's `create()` and a `save()` on a new model all read the id the same
way. A write through any of them to a counted table is this bug.

A write that sets its own primary key is not: `DriftEvaluator`, the two
recurring-series detectors and the sync applier all name the id in the payload,
and `RecordTransactions` inserts with `insertOrIgnore` and then reads the row
back by its fingerprint.

## A read-back that finds nothing

It is an error, never a 0. The four sites disagreed about this: three coerced
the miss and carried on — one of them memoised, so every promoted row of a
life's ledger filed itself under `import_run_id = 0`, and two handed a primary
key of 0 to a sync op, which on the peer replays against whatever row holds it.

`Modules\Core\Public\Exceptions\IdReadBackFailedException` names the table and
nothing else, so it is safe to log and safe to show. Every site raises that one
type. The three envelope and promotion sites raise it inside a transaction, so
the refusal leaves the table exactly as it was; the `import_runs` sites raise it
after their row has committed, which is what the next attempt adopts through the
`(user_id, sha256)` UNIQUE rather than inserting beside.

Where a reader is waiting, the page answers with a sentence: `/budgets` toasts
`core::errors.not_saved`, and `/settings` puts the period start day back and
shows `core::settings.errors.period_move_failed` — moving that day means nothing
without the rekey it aborted.

The two `envelope_assignments` sites hand their id to an
`EnvelopeAssignmentMutated` sync op, so a rowid belonging to `cache` would have
replayed against a stranger's row on the peer rather than failing loudly.

## The other half

A `QueryException` reaching a reader is a defect of its own whatever caused it:
its message is the statement, its bindings — which here are the entry the
reader had just typed — and the database's path.
`Modules\Core\Public\Support\SafeExceptionContext` is the only shape that goes
in a log, and a page catching one answers with a translated sentence. The cash
book uses `cashbook::cash-book.errors.not_recorded`, which already says the
entry was not recorded and keeps the reader's fields so they can try again.
