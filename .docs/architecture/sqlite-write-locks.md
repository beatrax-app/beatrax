# SQLite write locks, and the transaction mode that decides who waits

Beatrax runs several processes against one SQLite file. On the desktop that is
the app server, the queue worker, the sync relay and the scheduler; on mobile it
is the app and its worker. WAL lets them read while one of them writes, which is
why `journal_mode = WAL` is set. What WAL does not do is decide what happens
when two of them want to write at the same time.

That decision is `transaction_mode`, and getting it wrong costs jobs.

## The failure it fixes

A queued job was lost. `queue.failed` recorded a `database is locked` raised
inside Laravel's `markJobAsReserved`, on an otherwise idle queue, with
`attempts: 1` against the job's `$tries = 3`. It never ran and it never retried.

`busy_timeout` was already thirty seconds. It did not help, and it could not
have.

## Why `busy_timeout` does not cover this

`busy_timeout` tells SQLite how long to keep retrying a statement that is
blocked. It works for a writer that arrives while another writer holds the lock:
it waits, the lock frees, it proceeds.

It does **not** work for a transaction that upgrades from reading to writing.

A `BEGIN DEFERRED` transaction — SQLite's default, and PDO's — takes no lock at
all. It takes a read lock at its first `SELECT` and tries to take the write lock
at its first `INSERT`/`UPDATE`. If another connection committed a write in
between, that upgrade fails with `SQLITE_BUSY` **immediately**, ignoring
`busy_timeout` entirely. SQLite does this deliberately: the blocked transaction
is already holding a read lock, so waiting could deadlock against the writer it
is waiting for. Refusing at once is the only safe answer available to it.

The application sees `database is locked` from a statement that has no obvious
contention around it, after a wait of zero seconds.

## Why the queue hits it exactly

`DatabaseQueue::pop()` is the shape the hazard is named after:

```php
return $this->database->transaction(function () use ($queue) {
    if ($job = $this->getNextAvailableJob($queue)) {   // SELECT — read lock
        return $this->marshalJob($queue, $job);         // UPDATE — upgrade
    }
});
```

Read, then write, in one transaction. Every worker tick does it. Anything else
that writes between the `SELECT` and the `UPDATE` — a sync catch-up, an import
committing rows, the scheduler — turns that tick into a lost reservation.

## What is set, and why

`config/database.php` sets `'transaction_mode' => 'IMMEDIATE'` on the writable
SQLite connection. `BEGIN IMMEDIATE` takes the write lock up front, before any
statement runs. There is no upgrade to be refused, so a contended transaction
*blocks* — and blocking is where `busy_timeout` applies. Thirty seconds of
waiting replaces an instant failure.

Laravel honours the setting in `SQLiteConnection::executeBeginTransactionStatement()`,
which requires PHP 8.4 or newer. Beatrax is on PHP 8.5.

The cost is real but small: in WAL there is only ever one writer, so taking the
lock at `BEGIN` rather than at the first write serialises transactions that were
already going to serialise. It widens the window in which a write transaction
holds the lock to include its own reads, which is why long read-then-write
transactions are worth avoiding on their own merits.

### `readonly_select` is the exception

The `readonly_select` connection sets `'transaction_mode' => 'DEFERRED'`
explicitly. It runs with `PRAGMA query_only = 1` and can never write, so asking
for the write lock would queue a read behind every writer for nothing.

## How to tell if this regresses

`ATransactionThatReadsThenWritesDoesNotLoseToALatecomerTest` holds a transaction
open on one connection, writes and commits on a second, and then writes on the
first. Under `DEFERRED` the first transaction — the one that started earlier —
is the one that fails. Under `IMMEDIATE` it is the one that completes. That
ordering property is the whole of what this setting buys.

## Related

- [Creating the SQLite file before the container boots](sqlite-file-precreation.md)
  — the other substrate concern, and where the pragmas are actually applied.
- `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php` — applies
  `journal_mode`, `synchronous`, `busy_timeout`, `foreign_keys` and `temp_store`
  on `ConnectionEstablished`. `transaction_mode` is not a pragma and is not
  applied there; Laravel reads it from the connection config.
