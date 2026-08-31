# Capturing the history that predates sync

Op-log capture is event-driven: a write happens, a listener turns it into ops. A device used
for two years before anybody paired it therefore had an *empty* log, and handed its first peer
nothing at all — the phone sat on "0 of 0 records" while the desktop held the whole ledger.

`OpLogBackfiller` closes that gap by walking every covered table and writing each existing row
into the log as a `CREATE_ROW` op. `PreSyncHistoryCapture` runs it at the two moments it can
matter: sync being switched on (`BackfillOpLogOnSyncEnabled` on `DeviceSyncEnabled`) and a
pairing completing (`PairingFlowModal::enterSuccessStep`).

## Why it cannot simply run to completion

Every captured row costs roughly thirty-four op-log entries, and every entry costs an Ed25519
signature, an INSERT, an `updateOrInsert` on `hlc_clock_state` and a `current_epoch` lookup per
sensitive field. Measured on a real ledger that is about **34 rows a second**.

Both callers are **web requests**, and the desktop bundle launches PHP with
`-d max_execution_time=120`. `CoreServiceProvider` lifts that ceiling only
`if ($this->app->runningInConsole())`, which neither caller is. So the walk has about 120
seconds, or roughly four thousand transactions — two or three years of one ordinary account.

That would be survivable if hitting the ceiling cost only the unfinished part. It did not. The
capture ran inside **one** `$connection->transaction(...)` around the whole walk, so:

```
N=500 captured=503 in 14.64s  (op_log_entries=17039)   ~34 transactions/s

limit set to 12s, 1500 transactions:
FatalError: Maximum execution time of 12 seconds exceeded
op_log_entries after the fatal: 16184   <- uncommitted, inside the dead transaction
$ sqlite3 dev-to.sqlite "select count(*) from op_log_entries;"  -> 0
```

**Zero rows persisted**, and nothing said so: a max-execution-time fatal is not a `Throwable`,
so `PreSyncHistoryCapture`'s `catch` never ran. There was no resume either, so every retry
restarted from nothing and died at the same point. The class existed to prevent "0 of 0
records" and, on a ledger large enough to need it, produced exactly that.

## One transaction per chunk, and a cursor that survives the request

The walk now commits as it goes. Each chunk of `CHUNK` (200) rows is its own transaction, so a
process that dies takes at most one chunk with it. A single transaction is still what keeps this
fast — thousands of individual per-entry commits turn a second of work into minutes of fsync on
SQLite — but the unit is a chunk, not the whole ledger.

`sync_backfill_state` holds one row per user: how far the walk got (`cursor_table`,
`cursor_pk`), how much it has captured, and whether it has finished (`completed_at`). The
cursor advance is written **inside the same transaction** as the entries that chunk produced,
so the two can never name different amounts of captured history.

`BackfillBudget` bounds one slice by two numbers, because they answer different questions:

| Bound | Value | What it is for |
| --- | --- | --- |
| Rows written | 400 | The expensive half — each row is ~34 signatures. Checked between chunks, so it is the granularity the walk can stop at. |
| Wall clock | 5s | Everything else, including a slice that walks mostly-already-captured rows and writes almost nothing. This is the bound that keeps a slice inside the 120s ceiling. |

The row budget is spent on rows **written**, not rows walked: skipping a row a previous slice
already captured costs two indexed reads and no signature, and the deadline is what bounds that
half.

### Resumability does not rest on the cursor alone

`backfill()` was already idempotent row-wise — a row that already carries a verifiable
`CREATE_ROW` op is skipped — so a restart from the top would be *correct* without any cursor at
all. The cursor is there because correct is not enough: without it, every slice re-walks every
table it has already finished, and the cost of capturing a large ledger grows with the square of
its size. A table the current insertion order no longer names restarts the walk rather than
being skipped past, because a silent skip is a lost table and a repeat is merely cheap.

## The driver is a request, because nothing else holds the key

Signing needs the device identity, which needs the app-lock KEK, which lives in an unlocked
session. That rules out both of the obvious places to put long work:

- **A queued job** runs in a worker with no session. It could not sign a single entry.
- **The sync daemon** is headless for the same reason; it is deliberately identity-free on the
  paths it does run.

So the only thing that can carry the rest of a walk is another request.
`ResumesPreSyncCapture` is an `AfterResponseMiddleware` on the `web` group — the same bargain
`CarriesPendingPairingFrames` makes one file over, and for the same reason. It runs after the
response, so a reader waiting for a page never pays for it; it reads
`sync_backfill_state` first and takes the throttle marker second, so with nothing owed — which
is nearly always — the whole cost is one covered lookup on a table holding at most one row per
user.

`capture()` and `resume()` are separate on purpose. `capture()` opens a walk; `resume()` only
continues one somebody already asked for. The driver runs on every request, so a driver that
could *start* a capture would mean every install backfilling itself whether or not sync had ever
been enabled.

At one slice every two seconds, a four-thousand-row ledger finishes inside a few minutes of
ordinary use, in the background, with each slice durable the moment it commits.

## A failed slice is retired, not retried forever

If a slice throws — the real case is `UnreadableColumnException`, a sensitive column no epoch in
this keyring opens — the capture is **closed** and logged at error level rather than left owed.
A driver that runs on every request would otherwise reach the same failing chunk every couple of
seconds for as long as the install exists, and the condition it is failing on is permanent.

Nothing is lost by that: the chunks committed before the failure stay committed, and both
callers of `capture()` are user actions — enabling sync, completing a pairing — that reopen the
walk when they happen again, resuming from the cursor rather than from the top.

A **locked app is not that case**, and the two are deliberately told apart. With the app-lock
engaged there is no signing key, so no `OpLogWriter` can be built at all — that is a reason to
come back, not a verdict on any row. The slice returns having done nothing and leaves the walk
owed, and the first request after an unlock picks it up. Retiring on it would mean locking the
screen mid-capture abandoned the capture until the next pairing.

## Rows the walk does not take

- **Device-local tables** (`categorization_rules`, `rule_conditions`, `rule_actions`) are never
  captured. Rules stay on the device that authored them, and the rules screen says so.
- **`users`** is captured as `Set` ops against the reader's own row rather than as a create — a
  peer already has a user row and would refuse a second.
- A row whose sensitive column cannot be decrypted is **refused**, not blanked: shipping the
  blank would erase the value on every peer, permanently, because the log is the source of truth.

## See also

- [How a replay decides what the row should say](op-log-merge-rules.md) — what happens to these
  ops on the receiving side.
- [Sensitive columns at rest](sensitive-columns-at-rest.md) — why a captured value has to be
  decrypted before it is written into the log.
- [The peer session, from connect to close](peer-session-lifecycle.md) — how the captured
  history reaches a peer.
- [Sync architecture](architecture.md).
