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
`cursor_pk`), how much it has captured, how many consecutive slices captured nothing
(`failed_slices`), and whether it has finished (`completed_at`). The cursor advance is written
**inside the same transaction** as the entries that chunk produced, so the two can never name
different amounts of captured history.

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

## A failed slice leaves the walk owed

A slice that throws used to be **closed** — `completed_at` stamped, the walk retired — on the
reasoning that the real failure is `UnreadableColumnException`, a permanent verdict a driver
running on every request would otherwise re-reach every couple of seconds forever.

Measured on two paired devices, that reasoning cost a table. The walk ran for thirteen seconds
while the relay served the joining phone's drains out of the same SQLite file, took a
`DeadlockException`, and the catch stamped `completed_at` for that same second. The state row
named `anomaly_alerts` — position 36 of the 39-table insertion order — and the two tables after
it were never walked. `anomaly_suppression_rules` was empty. `tax_transaction_tags` held
seventeen rows, produced **zero** op-log entries, and reached no peer. Quarantine was 0 on both
devices, because nothing was ever refused: nothing was ever offered.

Three things were wrong with one failure ending a walk, and each is now separate:

- **A failure is not a completion.** The catch records a *failed slice* rather than a finish.
  `completed_at` is written in exactly one place — a walk that reached the end of the order with
  nothing left behind. A capture the state row calls finished cannot be retried, which is what
  turns a three-second lock into a permanent hole.
- **A table that will not walk costs its own rows, not the ones after it.** The per-table catch
  in `backfill()` reports the table and carries on down the order, so a leaf like
  `tax_transaction_tags` — which a topological order puts *last*, because its parents settle
  last — no longer depends on every table above it being readable.
- **A chunk refused for a lock is a wait, not a verdict.** The chunk transaction is opened with
  `LOCK_ATTEMPTS` attempts, so Laravel re-runs it for a concurrency error and only a concurrency
  error. The budget is spent outside that transaction: a chunk the engine made us re-run wrote
  its rows once and is charged once.

A walk still cannot be worked forever. `sync_backfill_state.failed_slices` counts consecutive
slices that captured nothing; past `BackfillProgress::MAX_FAILED_SLICES` the walk **stalls** —
still owed, never finished, no longer picked up by the request tail. A slice that captures rows
clears the count; a slice that captures none does not, because a table no keyring can read would
otherwise be retried until the install is deleted.

Stalled is not complete, and the ceiling only ends anything if the thing it bounds cannot reset
it. `open()` therefore leaves *any* unfinished walk alone — stalled as well as in flight — where
it once read only `isOpen()`, which made stalled and never-started the same answer and reopened
the first as freely as the second. That is the whole of what `DeliversOwedEpochs` could reach:
it opens a capture on the tail of every request it is allowed to tick, so a peer permanently
owed its epochs cleared the count every few seconds and eight fruitless slices apart was a
ceiling nothing ever arrived at.

The two entries are now named for who is behind them. `capture()` is the user-action entry —
sync switched on, a pairing confirmed — and clears the stall before it opens, because the reader
doing something is information the count cannot hold: a pairing delivers the very epoch the walk
could not read. `owe()` opens a walk and works none, which is all the request tail ever needed
(`ResumesPreSyncCapture` finishes it), and it revives nothing.

A **locked app is not any of those cases**. With the app-lock engaged there is no signing key, so
no `OpLogWriter` can be built at all — a reason to come back, not a verdict on any row, and not a
failed slice either. The slice returns having done nothing and the first request after an unlock
picks it up.

## Silence is not a report

A table that produced no entries reads exactly like a table with nothing to produce. That is the
whole of why seventeen rows left one device, reached no peer, and were noticed by nobody: every
surface that could have spoken was counting something that matched. Row counts matched. The op
log was byte-identical across 8,481 records. Quarantine was 0. The loss was visible only through
`transaction_search_docs.search_body`, a plaintext shadow that happens to hold the tax note.

So the walk counts both sides before it is allowed to finish. `shortfall()` asks, for every
covered table that is neither device-local nor the reader's own row, how many rows this user has
and how many of them carry a `CREATE_ROW` op **this device authored** — the same test the walk
skips a row on, so the audit can never call a row covered that the walk would still re-capture.

- Nothing short: the walk closes, and logs `every covered table is in the log` with the number of
  tables audited and `uncovered: 0`. That line is the positive control. A clean pass that logs
  nothing is indistinguishable from a pass that never ran, which is the reading this defect
  survived on.
- Anything short: the walk does **not** close. The tables and their two counts are logged at
  error level, a failed slice is recorded, and the cursor is rewound to the top of the order —
  the missed table can sit anywhere in it, and a cursor pointing past it would never reach it
  again. Row-wise idempotence makes the re-walk two indexed reads per row already captured.

## Announced once, whichever announcer arrives first

Two things announce a row this device already holds, and they do not know about
each other. The walk on this page reads the table. `DeferredOpCaptureDrain`
replays a coordinate a keyless process left behind — the daily `recurring:detect`
and `anomaly:detect` runs create rows at 00:00 holding no signing key, and the
coordinate waits for the first request that can sign
([a mutation a keyless process cannot
sign](a-mutation-a-keyless-process-cannot-sign.md)). Where the walk reaches the
row first, the drain used to announce it a second time.

Measured on the paired desktop: the scheduler created 52 rows at 00:00:08, the
walk announced them between 16:24:05 and 16:26:51, and the drain announced every
one of them again at 18:16:59 — **438 create field-ops across `anomaly_alerts`,
`recurring_series` and `recurring_series_occurrences`, which was the whole of
what that tick wrote.** 436 of the install's 12,445 op-log entries were repeats
of a create the same device had already published.

None of that reaches a peer as anything. A create naming a row the receiver
holds is answered by `SplitCreateTail`, which fills only the columns the stored
row never received and refuses to talk over one that carries anything else —
so a repeat can change nothing there by construction, and suppressing it can
lose nothing either.

`AnnouncedCreates` is the one place the question is asked, and all three
announcers ask it: the walk's chunk, `captureRowsById()`, and the drain. It
counts only the creates a **self** device signed, for the same reason the audit
does — an op signed by a former peer is coverage here and unverifiable there.

A column the earlier create never carried is the one thing still owed, and the
drain announces it as a `Set` rather than as a second create. That is the same
column a tail-fill would have reached, and a `Set` merges through the field
strategy instead of depending on the receiver's row still being blank.

## A walk is not a repair for a device a peer already knows

"Switching sync on backfills the whole database anyway" is why `SyncOffOpSink`
is allowed to drop a mutation, and it holds for the device that sentence is
about: a peer holding none of these rows takes every create the walk writes.

It does not hold for a device whose peer already holds them. A create naming a
row the receiver has is answered by `SplitCreateTail`, which fills only the
columns that row never received — so a walk cannot carry an edit, and it has no
way at all to carry a delete. That is why a [restored
database](device-identity-key-files.md#a-self-row-and-no-key-file-is-a-restored-database)
defers its writes instead of leaning on the walk: what it owes is the changes
made since the backup, and the walk announces the rows, not the changes.

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
- [A mutation a keyless process cannot sign](a-mutation-a-keyless-process-cannot-sign.md) — the
  other announcer, and what it owes.
- [Sync architecture](architecture.md).
