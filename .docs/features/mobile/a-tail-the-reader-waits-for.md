# A tail the reader waits for

`Modules\Core\Public\Http\Middleware\AfterResponseMiddleware` exists so that work
which needs the app-lock key — and therefore needs a request, because a request
is the only process that holds one — is not paid in front of a page somebody is
looking at. Six middlewares extend it. Its promise is in its name, and on the
mobile runtime the name is wrong.

## What was measured

A Galaxy A51, `/data-devices`, from the runtime's own `PerfTiming ⏱️ WEBCLIENT`
lines:

| Visit | PHP time |
|---|---|
| first after a reinstall | **12,563 ms** |
| a later one | **249 ms** |
| first after an app-lock unlock | **5,034 ms** |

`/mobile/lock`, the data-free baseline, is 290 ms on the same device. The page
itself is not the cost: the seven Livewire components it mounts, timed against a
copy of that phone's own database on a Mac, total **68 ms over 61 queries and
4.5 ms of SQL**. The phone's op log gained **1,133 entries in the minute of the
5,034 ms request**, and `deferred_op_captures` was empty afterwards.

## Where the tail actually falls

The same probe against both entrypoints — a terminable middleware busy-working
300 ms, with the first byte of output stamped by an `ob_start` handler:

| Entrypoint | Response bytes | Tail |
|---|---|---|
| `public/index.php` | 291.6 ms | 292.3 → 592.3 ms |
| `Native\Mobile\Runtime` | 347.5 ms | 47.3 → 347.3 ms |

On the desktop and self-hosted roots the promise holds: `handleRequest()` sends
the response and then terminates, so the tail is 300 ms nobody waits for. On the
mobile root it is inverted. The response was ready at 46.1 ms and the first byte
left PHP at 347.5 ms — 301.4 ms of a 300 ms tail, paid inside the request.

Three serialisations stack up, none of them ours to change:

1. `Native\Mobile\Runtime::dispatch()` calls `$kernel->terminate()` and only
   **then** returns the response.
2. The shell bridge's eval body calls `dispatch()`, and only once that has
   returned does it `echo` the status line, the headers and `sendContent()`.
3. `php_embed_module.ub_write` is pointed at a thread-local buffer; the C layer
   reads it with `get_collected_output()` *after* `zend_eval_string` has
   returned, and hands the whole thing to `shouldInterceptRequest`, which the
   WebView is blocked on. There is no half-response to stream.

`Modules/Mobile/tests/Unit/TheReaderWaitsForEveryTerminableMiddlewareTest.php`
pins all three against the vendored shell sources, so a `composer update` that
changes the order is a failing test rather than a silent repair or a silent
regression.

## The budget

`Modules\Core\Public\Http\ResponseTailBudget` is how long the work behind one
response may run. `AfterResponseMiddleware` takes it as a constructor argument
every subclass has to pass, and opens it in `terminate()` — so a seventh
middleware cannot join the stack without one, and the six a request may carry
share a single wait rather than taking one each.

`ResponseTailTiming` names the two runtimes and the number each gets:

| Case | Which runtime | Milliseconds |
|---|---|---|
| `AfterTheResponse` | `public/index.php` — desktop and self-hosted | 2,000 |
| `InsideTheReadersWait` | `Native\Mobile\Runtime` — the phone | 100 |

The two numbers are not the same quantity. The mobile one is measured against
the page it is added to: 249 ms when nothing is owed, so a tail of 100 ms is
what the reader pays on top. The desktop one is a ceiling on how long one
request may hold the process, not a correction to anything measured — a whole
batch of the largest work there is measures **491.2 ms** (below), well inside
it.

Two properties the callers have to keep:

- **Ask between units, never before the first.** A tail that can be denied its
  first unit of work makes no progress on the request after it either, and the
  queue only grows. `DeferredOpCaptureDrain` replays one row's coordinates and
  *then* asks.
- **A budget nobody opened bounds nothing.** `spent()` is false until
  `openFor()` has been called with a request, so the same work reached from a
  console, from a queue worker or from a test calling it directly is bounded by
  whatever bounds it there.

## Which of the six need it

Only three of the six are registered on the mobile root at all —
`mobile-app/bootstrap/app.php` carries `CarriesPendingPairingFrames`,
`RunDeferredNotificationPasses` and `DrainsDeferredOpCaptures`. The other three
are on `bootstrap/app.php` only, where the response has already gone.

| Middleware | On the mobile root | What one tail can spend | Bounded |
|---|---|---|---|
| `DrainsDeferredOpCaptures` | yes | 400 coordinates — **491.2 ms** measured on a Mac for one tick, 1.23 ms a coordinate | **yes** |
| `RunDeferredNotificationPasses` | yes | at most three passes, each unsplittable | no — see below |
| `CarriesPendingPairingFrames` | yes | one browse and one relay round trip | no — see below |
| `ResumesPreSyncCapture` | no | one `OpLogBackfiller` slice, `CHUNK = 200` | n/a |
| `DeliversOwedEpochs` | no | a fan-out over the peers owed, a handful of rows | n/a |
| `RecoverSealedLedger` | no | an unbounded residue sweep and quarantine replay | n/a |

**`RunDeferredNotificationPasses`** is left alone on evidence rather than on
principle. The phone's own `cache` table held `sync:deferred-op-capture-drain:1`
and no `beatrax:deferred-notification-pass:` key at all during the measured
session, so it ran its idle path — one cache read per pass, three — on every one
of those requests and contributed nothing to the 12,563 ms. Its loop is at most
three units and each unit is a whole re-derivation that cannot be halved, so a
budget could only ever defer two of three. It holds a budget, by constructor,
for the day a pass is measured expensive.

**`CarriesPendingPairingFrames`** is deliberately not bounded. Its cost is a
network wait — `ProtocolTimings::BROWSE_SECONDS` is 2.0 — rather than work that
can stop between units, and it only runs at all while a pairing handshake is
live, throttled to one tick per three seconds. A shared budget the drain had
already spent would starve a ceremony the reader is watching a progress screen
for, which is the opposite of what this page is about.

## What the bound may not do

Move the work, never lose it. The drain's existing properties carry the whole
argument and none of them changed:

- A coordinate is retired **in the same transaction as the op it produced**, so
  a row that stops being drained mid-batch is either announced and retired or
  neither.
- `DeferredOpCaptures::pending()` reads in insertion order, which is capture
  order, so the remainder of a stopped batch is the same queue the next request
  reads from the same end. A create still precedes the sets that followed it.
- A create the pre-sync walk already announced is dropped in favour of a `Set`
  for the columns it did not carry, so an announcement split across more
  requests cannot become an announcement made twice
  ([announced once, whichever announcer arrives first](../sync/pre-sync-history-capture.md#announced-once-whichever-announcer-arrives-first)).

### What it costs to spread it

`TailSweepInterval::SECONDS` is 2, so the drain takes at most one tick every two
seconds however many requests arrive between them. The 1,133-entry backlog above
used to clear in three ticks — three page loads frozen for seconds each — and now
clears over roughly a minute of ordinary use with nothing on screen changing.
That is the trade this page is making, and it is the same one
[the pre-sync capture](../sync/pre-sync-history-capture.md) already made for the
walk: work that only advances as fast as requests arrive, rather than work a
reader is held up by.

The queue it drains is capped at `DeferredOpCaptures::MAX_PENDING_ENTRIES`
(10,000), past which the device owes a whole-database walk instead. Draining in
smaller ticks leaves a backlog standing for longer and therefore reaches that cap
on a device that keeps being locked before it clears — which is not a loss: the
walk is the cheaper description of what such a device owes and reaches the same
peer state.

`Modules/Sync/tests/Feature/ARequestThatPaysForTheWholeBacklogTest.php` holds
both halves: one request with a spent budget announces exactly one row of a
60-row backlog and leaves the other 59 owed, and the requests after it announce
every one of the 60 exactly once, counted field by field. Without the budget
consult the same first request announces **50** of them.

## What this does not repair

`SealedLedgerRecovery::recover()` — the residue sweep and the quarantine replay
— does reach a phone, and not through `RecoverSealedLedger`, which the mobile
root does not register. `MobileSyncTriggerService::attempt()` calls it from
`recoverHeldEntries()`, which is the **Sync now** tap: the handle phase of a
Livewire request, in front of its response rather than behind it. The
measurement in
[sensitive-columns-at-rest.md](../sync/sensitive-columns-at-rest.md#getting-back-inside-the-guarantee)
is stated for the root where it is a middleware; on a phone it is a button press
somebody is waiting on, and it is not covered by anything on this page.
