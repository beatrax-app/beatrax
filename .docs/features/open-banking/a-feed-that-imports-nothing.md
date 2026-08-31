# A feed that imports nothing

A bank connection that fetched rows every day and filed none of them looked, to
the queue and to the reader, exactly like a connection with nothing new. It
repeated on every scheduled run with no failed job, no alert, and — on the one
screen that did say something — the word "error" for a run in which nothing had
errored.

## The chain, measured

`Modules/OpenBanking/tests/Feature/ASyncThatFiledNoneOfWhatItFetchedTest.php`
drives it end to end: a connection with a live consent, an adapter that yields
two rows for an account the ledger already has, encryption enabled for the user,
and the app-lock key withheld — the state a phone's background process is
permanently in, argued in
[a background task on the phone cannot hold the key](../mobile/background-sync-cannot-hold-the-key.md).

Before the change, one tick produced:

| Where | What it said |
|---|---|
| `transactions` | nothing written |
| `import_runs` | the run left unconfirmed |
| `open_banking_connections.last_attempt_status` | `error` |
| `open_banking_connections.last_successful_sync_at` | unchanged |
| the queue | processed — no exception, no `failed_jobs` row |
| `system_alerts` | nothing, after three ticks |
| **Sync now** | "No new transactions." |

The last line is the one that matters most: the sentence a quiet week earns was
being handed to a press that fetched two rows and filed neither.

The links, one at a time. `ImportPipeline::enrichRow()` catches
`SensitiveColumnKeyUnavailableException` and `BlindIndexKeyUnavailableException`
per row and returns a `PreviewRowStatus::Error` row carrying
`ImportFailureReason::AppLocked` — correct, and
[the refusal itself stays correct](../sync/sensitive-columns-at-rest.md#what-may-be-written-when-no-key-is-reachable).
Every row taking that path makes `PreviewHead::importableRowCount()` zero, so
`confirmRefusal()` answers `ConfirmRefusal::NothingImportable` and
`ConfirmImport` throws `ImportNotConfirmableException`. `OpenBankingSyncRunner`
marks that non-retryable, `OpenBankingSyncOutcome::retryableFailure()` therefore
returns null, and `SyncOpenBankingAccountJob::handle()` returns without throwing.

Non-retryable was the right call and still is: the next tick opens the same
window, the same rows come back, and the same keyless worker refuses them again.
Non-retryable is not the same as successful, and that is what the queue was
being told.

## What "nothing importable" could and could not distinguish

`OpenBankingFetchService::fetchAndConfirm()` already guarded the confirmer
behind `totalRows() > 0`, so a genuinely empty window never reached
`ConfirmImport` and never produced `NothingImportable` at all. Reaching that
refusal from the scheduler therefore already implied rows had arrived. What
could not be told apart was on the other side of the module boundary:
`ImportNotConfirmableException` carries a `Modules\Import\Internal\Enums\ConfirmRefusal`,
which `Modules\OpenBanking` may not import, so every refusal arrived as one
undifferentiated throwable and became `SyncAttemptStatus::Error`.

The fix does not widen the boundary. `fetchAndConfirm()` reads the same
arithmetic `PreviewHead::confirmRefusal()` derives its own answer from —
`totalRows() > 0 && importableRows() === 0`, both `ImportPreviewResult`
accessors — and returns `OpenBankingFetchResult::filedNothing()` instead of
calling a confirmer that would refuse. `preview()` classifies the same way, so
the button and the scheduler agree about what happened.

The refusal is still caught in `OpenBankingSyncRunner::recordFailure()`, and
that guard is not dead: `ConfirmImport` re-reads the head from the preview cache,
and a concurrent run resetting that cache between the two reads is a real race.

## What is now recorded, and what is announced

`SyncAttemptStatus::NothingImported` is its own case. It is not `Error` — the
connection, the consent and the page walk all worked — and it is not `Ok`,
because no money reached the ledger. `SyncAttemptStatus::failedIn()` already
treats anything other than `Ok` as a failed attempt, so the transparency panel
picks it up with no further gate, and reads
`transparency.reason_nothing_imported` instead of the bare `reason_error` that
sent a reader looking for a fault there is not.

Neither the freshness signal nor the cursor moves. `fetched_through_at` stays
where it was for the same reason a truncated walk leaves it alone — see
[the fetch cursor](fetch-cursor.md#the-cursor-is-what-was-committed-not-what-was-fetched)
— so the window stays open and the rows are re-offered to the first run that
can actually file them.

The connection row alone was not enough. It lives on a screen a reader has to
already suspect something to open, and the whole shape of this defect is that
they have no reason to suspect anything. `OpenBankingImportedNothing` is
dispatched and `RaiseOpenBankingNothingImportedAlert` turns it into a
`system_alerts` row of kind `open_banking_nothing_imported`, which the banner
renders on every screen. The row carries `rows_fetched` in metadata rather than
in the sentence: what the reader acts on is that none of them landed, and a
count beside a plural noun has to decline in twenty-six languages to earn its
place.

Only the unattended run announces. `runAndConfirm()` passes
`announceRefusal: true`; a **Sync now** press does not, because the reader is
already looking at the answer and a banner about the thing they just read is
noise.

## Why not a failed job

`$this->fail()` would put one `failed_jobs` row on disk per scheduled tick, for
as long as the condition lasts, on a table nobody opens — and on a phone the
condition lasts until the reader unlocks the app, which is exactly when the
queue is not what runs. The queue's vocabulary has two words and neither is the
right one. The state is recorded where a reader can see it instead.

## The two other things this uncovered

`AScheduledSyncNeverConfirmsARunItCouldNotFileTest` covers the sibling refusal:
every fetched row naming an account the ledger does not have. It reaches the
same `importableRows() === 0` and now records `NothingImported` too, which is
the more accurate name — nothing errored, the account is unnamed — and it now
raises the same alert. It was equally silent before, and the reader's first step
is the same either way: open the run and read the per-row reason.

The **Review import** link on the settings page was gated on
`$syncFlashTone === 'success'`. The truncated branch has always set
`syncReviewImportRunId`, and the blade has never rendered it: the two flashes
that most need a way into the per-row reasons were the two that could not show
one. The link is now keyed on the id alone.

## See also

- [`architecture.md`](architecture.md#sync-job-and-freshness-accounting) — the two-timestamp rule both entry points share.
- [Fetch cursor](fetch-cursor.md) — why a run that wrote nothing must not advance it.
- [A background task on the phone cannot hold the key](../mobile/background-sync-cannot-hold-the-key.md) — why the keyless state is permanent on a device.
