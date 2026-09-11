# A purged connection two services still hold

`DatabaseManager::purge()` reads as "close this connection". What it does is
narrower: it calls `disconnect()` on the `Connection` object and drops it from
the manager's own map. Every service that already resolved that object keeps it.

That is not a leak that fades. Laravel repairs the orphan, and the repair is the
defect:

```php
// Illuminate\Database\DatabaseManager::__construct()
$this->reconnector = function ($connection) {
    $connection->setPdo(
        $this->reconnect($connection->getNameWithReadWriteType())->getRawPdo()
    );
};
```

The orphan's next query finds `$this->pdo === null`, calls that reconnector, and
is handed **the live connection's PDO**. `DatabaseManager::reconnect()` fires
`ConnectionEstablished` on the way, and `SqliteOptimizationsProvider` answers it
with `PRAGMA` statements — which resolve the lazy PDO into a real handle before
`getRawPdo()` reads it. So the orphan does not get a handle of its own. It gets
the same one.

`Connection::setPdo()` also resets `$this->transactions` to `0`. From that
moment there are two `Connection` objects over one SQLite handle, and only one
of them is counting.

## The two faces

Which symptom you get depends on whether the orphan still holds a PDO when the
live connection opens a transaction.

**It does.** The orphan's `transaction()` sees `transactions === 0`, takes the
branch that issues a bare `BEGIN`, and SQLite refuses:
`SQLSTATE[HY000]: General error: 1 cannot start a transaction within a transaction`.
Error **1**, not the error **5** two separate handles would produce — which is
what identifies the handle as shared rather than merely contended.

**It does not.** `reconnectIfMissingConnection()` runs the reconnector, whose
first act is `DatabaseManager::reconnect()` → `disconnect()` on the *live*
connection. That drops the PDO holding an open transaction, and every statement
written inside it is gone. No exception, no log line.

## The rule

Anything that purges a connection the application is still running on has to
retire the services built over it in the same breath.
`Modules\Core\Public\Services\LiveConnectionPurge` is that seam, and the cache is
what it retires: cache stores are built while providers boot, so they exist
before any purge can be anticipated, and `DatabaseStore::increment()` opens a
transaction of its own — which is the call that turns a shared handle into a
refused write. Session and queue drivers resolve per request, after the boot-time
purge has run, so they are not exposed to it.

The other half of the rule is what rides the statement. `QueryExecuted` is
dispatched from `Connection::logQuery()`, after the statement succeeded and
outside the try that turns a driver error into a `QueryException` — so a
listener there runs inside a transaction it cannot see, and whatever it raises
is reported as somebody else's statement failing. It must contain its own
failures, and must not reach anything that opens a transaction of its own.

Both halves are held by
[`tests/Contracts/ARiderOnEveryStatementCannotOpenATransactionArchTest.php`](../../../tests/Contracts/ARiderOnEveryStatementCannotOpenATransactionArchTest.php),
which pins the set of `QueryExecuted` listeners and the set of connection
purges. A purge over a name nothing is built over is legitimate and is pinned
there with its reason — `RestoreEncryptedBackup` configures and drops a private
`_restore_verify` connection inside one method to run a single `PRAGMA`.

## What it cost

Measured on a Galaxy A51 on 2026-09-11. The phone's cache store had been built
over the connection `mobile-app/bootstrap/app.php` purges at `booted()`, so
`NavCountsService::bumpGeneration()` — called by `ForgetNavCountsOnWrite` on
every insert, update and delete touching a counted table — could never complete.

- `cache` held `nav-counts:0:1` and **no** `nav-counts:generation` row at all:
  `increment()` had not once returned on that device, so the badge generation
  was never seeded and never bumped.
- A "Sync now" answered HTTP 500. `op_log_entries` took the 367 ops the desktop
  sent (9,982 → 10,349) and `transactions` stayed at 152: the replay's
  transaction rolled back whole.
- `op_log_quarantine` went 69 → 115. The 19 `RehomedCreate` warnings logged
  minutes earlier carried `"reason":"PDOException","sqlstate":""` — the same
  refusal, swallowed by a `catch (Throwable)` and recorded as a primary-key
  collision the row never had.

## See also

- [Sync: what runs inside the replay transaction](../sync/op-log-merge-rules.md#what-runs-inside-the-transaction-and-what-cannot)
- [The mobile root's own bootstrap](../mobile/architecture.md#the-mobile-roots-own-bootstrap)
