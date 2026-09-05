# A background task on the phone cannot hold the key

Two findings from one device session, on a Galaxy SM-S928B paired to a desktop
through the real pairing flow and carrying 8,365 pulled records. They are the
same constraint seen from two ends: the phone can only sync while somebody is
looking at it, and until this page's changes nothing anywhere said so.

## The chain, one link at a time

Enabling sync **requires an app lock**, and the copy on the switch states that
once it is on, "the app lock can no longer be turned off"
(`sync::devices.enable_sync_help`). Every device that can sync therefore has a
lock.

The device identity — the Ed25519 signing key and the X25519 static key the
Noise handshake needs — is sealed on disk under the app-lock key-encryption key.
`DeviceIdentityLoader::read()` asks `AppLockKeyService::release()` for that KEK,
and `AppLockKeyService` answers from the **session**: `LockStateManager` keeps a
handle in `beatrax_data_key`, which the platform `KeyCustodian` exchanges for
the real key in the iOS Keychain or the Android Keystore. No session, no handle;
no handle, no key. That is deliberate, and it is the property that makes an
un-opened phone worth nothing to somebody holding it.

`MobilePullCommand` ran under Android WorkManager every fifteen minutes. A
firing is a cold-started process: its own container, its own
`SessionFactory` → `container->make(Session::class)`, a `Store` with nothing in
it. `release()` returns null on every firing, for every user, forever.

From the device's own log, one line per tick, on a phone that was paired and
fully synced at the time:

```
21:12:15 MobileSyncTriggerService: no usable device identity — skipping tick.
21:12:15 sync:mobile-pull: no usable device identity — tick skipped cleanly. {"user_id":1}
21:27:19 (same)
21:42:23 (same)
```

Six skips, no successes.

### Why the test suite was green

`Modules/Mobile/tests/Feature/MobileBackgroundPullTest.php` unlocks
`app(Session::class)` and then calls `Artisan::call('sync:mobile-pull')`.
`session.store` is a container **singleton**, so the command's `SessionFactory`
handed back the very session the test had just unlocked. In-process, the command
holds a key; on a device, the process that unlocked and the process that ticks
are never the same one. The fixture was supplying the one thing the field never
provides. `it('reports a locked identity when the tick builds its own session…')`
in that same file now measures the device's arrangement instead.

## What the code does about it

`sync:mobile-pull` is no longer scheduled. `Modules/Mobile/Routes/console.php`
keeps only the bounded `queue:work` drain, and
`Modules\Core\Public\Scheduling\MobileBackgroundSchedule::impossibleOnDevice()`
declares `mobile.sync-pull` with the reason, so its absence from the manifest is
a stated decision rather than the shape twenty other tasks went missing in.
`Modules/Mobile/tests/Unit/ThePhoneSchedulesNoBackgroundSyncTest.php` fails if
anything schedules it again.

Three alternatives were weighed and rejected:

- **Cache the key, or a "remember this device" flag.** This is the whole point
  of the seal. A phone that can sync while locked is a phone whose data is
  readable while locked.
- **Move the burst to a queued job.** The phone's queue worker is a thread
  `MainActivity` starts, and `queue:work` builds its own session exactly the way
  WorkManager's process does. It hits the same wall one layer further down.
- **Sync from the `AppLockUnlocked` listener**, the way
  `Modules\Desktop\Internal\Listeners\StartSyncListenerOnEnable::handleUnlocked()`
  starts the desktop's listener. This is the only moment on a phone where a key
  exists without a user tap, and it is the right shape for a follow-up — but the
  burst would run inside the unlock request itself (the session is the only place
  the handle lives), and a LAN dial there is a stall on the one interaction that
  has to feel instant. It needs measuring on hardware before it ships.

## What the reader is told

The `/sync` screen now carries `mobile::sync.background_note`, in
twenty-six languages: syncing happens when the reader taps **Sync now**, and it
cannot run in the background because the app lock holds the only key.

The screen *before* it spent a release contradicting that.
`mobile::sync_complete.automatic_body`, the last line of setup, read "There is
no sync button to press". The three lines about syncing on that screen now carry
a `:action` placeholder which `SyncCompleteScreen::mount()` fills from
`mobile::sync.sync_now` — the very key that labels the button on the screen
after it. Neither file can be reworded without the other following, and
`TheSetupDoneScreenCannotDenyTheButtonTheNextScreenOffersTest` checks the
substitution lands in all twenty-six languages, not only the one somebody
reads.

## The Sync now button was a silent no-op

`MobileSyncTriggerService::syncOnce()` has always returned `?bool` — `null` for
a skipped tick, `true`/`false` for an attempt that ran and whether any transport
was reached. `SyncScreen::syncNow()` called it and discarded the return value.
On the device that meant a tap produced no data movement, no log line and no
change on screen: the status area went on reading "Syncing…" and the peer's last
seen time did not move. A reader could not tell a successful sync from a skipped
one from a failed dial.

`?bool` was also too narrow to say anything useful. It folded four different
answers into `null`. `Modules\Mobile\Internal\Sync\SyncAttemptOutcome` names all
six:

| Case | What happened | What the reader is told |
|---|---|---|
| `Synced` | A transport completed — LAN, relay, or both | Synced with your other device. |
| `Unreachable` | Every attempted transport failed | Could not reach your other device. |
| `Locked` | The identity is sealed and this session holds no key | Unlock the app to sync. |
| `NotEnabled` | No identity key-file: sync was never turned on here | Sync is not set up on this device yet. |
| `Unreadable` | The key-file will not open under this device's key | Pair again to resume syncing. |
| `PausedOnCellular` | The pause-on-cellular gate is on and the link is expensive | Paused while on mobile data. |

`Locked`, `NotEnabled` and `Unreadable` are the three states
`DeviceIdentityLoader` already distinguishes; `loadWithState()` returns the
`DeviceIdentityState` alongside the identity so the caller gets both from one
decrypt rather than reading the sealed file twice.

`syncOnce()` is unchanged and still returns `?bool` — `InitialSyncPuller` reads
it that way, and the setup gate's own `SyncBlockedReason` copy is a separate
vocabulary aimed at a screen that is still filling up. `attempt()` is the same
work with the answer intact.

`Unreadable` earns its own case rather than folding into `Locked` for one
reason: a key-file that will not open is a state an **unlocked** reader can be
in, after a restored or replaced database. Telling that reader to unlock names a
cause they have already ruled out.

## The address the button dialled was a guess

The discarded return value was the smaller half. The press did not merely fail
to report — it never opened a socket.

`PeerLanAddress` answered with a bare host and nothing else — the value
`fromRelayEndpoint()` still parses out of **this device's own relay endpoint
URL**. `RelayEndpointHost::host()` is null whenever no relay was ever
configured, which is the ordinary state of a LAN-only pairing.
`SyncScreen::syncNow()` passed that null straight into the burst: the LAN leg is
skipped on a null host, the relay leg returns immediately when
`RelayConfig::isConfigured()` is false, and neither writes a log line. The port
came from a third place again — `SyncPorts::lan()` — so host and port did not
even describe the same machine.

Measured on the same Galaxy S23, after a first sync that pulled 8,365 records:

- The phone authored 62 ops of its own — 18 `transaction_splits`, 10
  `tax_transaction_tags`, 5 `envelope_assignments`, 2 `transactions`. Capture
  works.
- The desktop's op log held 17,237 entries and **none** of those 62.
- `lsof` on the desktop's port 51337 across a 25-second press: **zero** TCP
  connections from the phone.
- `device_registry` on the phone held `last_lan_host = 192.168.178.119`,
  `last_lan_port = 51337` — live and accepting connections. Nothing read the
  columns.

So on a LAN-paired device with no relay, sync stopped dead in both directions
after the initial pull, and every surface stayed quiet about it.

`PeerLanAddress` now answers with an address rather than a host, and takes it
from `PeerLanAddressBook` — the registry columns the pairing admitter and every
successful browse already write — falling back to the relay endpoint only when
nothing was ever reached. That fallback is not vestigial: iOS LAN discovery does
not work at all (see [ios-lan-discovery-entitlement.md](ios-lan-discovery-entitlement.md)),
so the endpoint the QR carried is the only address such a device will ever hold.

Two methods, because the two callers want different costs. `recall()` never
browses: `SetupProgressScreen` polls every two seconds and a browse costs
`ProtocolTimings::BROWSE_SECONDS` in full, and the pull behind it already
browses when nothing is remembered. `locate()` does browse, because
`SyncScreen::syncNow()` is a press somebody is waiting on.

`PeerLanAddressBook::forget()` existed, documented the exact failure it prevents,
and had no caller: a remembered address that stops answering was retried by
every later attempt forever. `syncNow()` calls it when a dial it actually made
came back `Unreachable`, so the next press browses for where the desktop went.

## The scheduled passes that cannot write either

Sync was the first thing found behind this wall. It is not the only one. The
same phone, the same session, one more door along — its own log during a feature
test:

```
local.ERROR: PersistBudgetNudge: failed to persist budget nudge
  {"reason":"Modules\\Sync\\Public\\Exceptions\\SensitiveColumnKeyUnavailableException",
   "categoryId":23,"userId":1}
  … five of these, one per category …
local.INFO: queue.processed {"job":"Modules\\Budgets\\Internal\\Jobs\\EmitBudgetNudgesJob"}
```

Five refusals and then `processed`. `SensitiveFieldRegistry::columns()` seals
`notifications.title`, `.body`, `.params` and `.trigger_type`;
`SensitiveColumnCodec` refuses to write a sealed column with no key, which is
correct and stays correct — see
[sensitive-columns-at-rest.md](../sync/sensitive-columns-at-rest.md#what-may-be-written-when-no-key-is-reachable).
The refusal is not the defect. What the refusal was resting on is.

### The sentence that was true on a desktop and false on a phone

That page argued the split as already clean: "Background writers carry
regenerable content and drop it — all eight `Persist*` notification listeners
already catch `Throwable` and log, so a refused nudge costs one nudge and
**re-emits on the next run**."

There is no such run. Every later tick is another cold-started process with
another empty session. On a device whose ledger is sealed, the loss is not one
nudge — it is the feature, permanently, and the only trace is a line that reads
like a bug in Notifications. Nor is this only the phone's problem: `sync:serve`
and the queue worker are consoles on the desktop too, and the desktop's own
scheduler tick is a third. It is only on the phone that no other process exists.

### Which of the thirteen are actually affected

`MobileBackgroundSchedule::requiredOnDevice()` names thirteen commands.
Following each one's call chain to a `SensitiveColumnCodec` write gives **six**,
not the seven a first reading suggests:

| Command | Reaches a sealed write | What is lost |
|---|---|---|
| `budgets:emit-nudges` | `notifications.*` | the nudge, and nothing else shows it |
| `notifications:daily-triggers` | `notifications.*` ×3 | payment reminders, position digest, savings prompts |
| `forecasting:project` | `notifications.*` | the shortfall notification; the projection itself lands |
| `recurring:detect` | `notifications.*` | the drift and shortfall notifications; the series lands |
| `open-banking:sync-due` | `counterparties.*`, `transactions.*`, then `notifications.*` | the import |
| `receipts:scan-drop-folder` | `transactions.*`, through `RecordReceipt` then `ReceiptLedgerBridge` | the import |

`receipts:scan-drop-folder` is the newest of them: it was a `Schedule::call()`
closure no device manifest carried until the settings screen was found promising
a five-minute scan on a phone. Like `open-banking:sync-due` it only dispatches,
so which worker drains the job is what decides whether a key is in reach.

And five that a reading of the registry would wrongly convict:
`drift-alerts:revive-snoozes` and `anomaly:revive-snoozes` transition a row and
dispatch `EntityMutated` only — `DriftAlertOpened` comes from `DriftEvaluator`
and never from the state machine; `anomaly:safety-net-sweep` writes
`anomaly_alerts` and dispatches `AnomalyAlertOpened`, which has no listener in
the tree; `notifications:prune` keys solely on the always-plaintext `created_at`;
`migration:sweep-abandoned` only deletes, and `migration_runs` and its per-run
staging tables are registered in no merge rule, so the cascade emits no
`EntityMutated` for them and nothing has to be sealed or captured.
`db:backup`, `fx:refresh-rates` and `forecasting`'s own projection rows
touch no registered column at all.

The first two rows are the ones with **no other surface**. A drift alert and a
forecast shortfall both leave a row the app already renders on its own screen,
so a reader whose notification was refused can still find them. A budget nudge, a
payment reminder, a digest and a savings prompt exist only as the notification.

### Re-deriving, not buffering

Holding the refused draft until a key turns up is the obvious repair and it is
the leak wearing a different hat: a rendered title and body parked in a pending
table is the plaintext the seal exists to prevent, one table over. Nothing is
kept.

Instead the pass is re-run. `NotificationWriter` derives the row id from
`(user, trigger, subject, occurrence)` and inserts with `insertOrIgnore`, so
running an emitter again writes exactly what the keyless run would have written
and nothing twice. `Modules\Notifications\Internal\Enums\DeferredNotificationPass`
names the two passes whose entire output is notification content —
`budget-nudges` and `daily-triggers` — and `DeferredNotificationPasses` is the
seam:

- **The gate is asked before the pass reads anything.**
  `deferIfKeyless()` answers `EncryptionMigrationService::isEnabled()` **and**
  `SensitiveColumnCodec::canSeal()`, the same pair `StripAsnDescriptionDelimiters`
  asks. That ordering is the privacy property, not an optimisation: the mark then
  records that a keyless process was asked to run, which is true of every enrolled
  user on every tick. A mark made *after* the content query would instead record
  that this user crossed a budget threshold — putting in the clear the very thing
  sealing `notifications.trigger_type` hides.
- **The mark is content-free and disposable.** One cache key per user per pass,
  held for `DailyLocalWindow::claimTtlSeconds()` — two days, and taken from the
  window rather than written again here, because the two spans are one quantity
  and a comment asserting they matched was all that had held them together. The
  scheduler re-makes the mark hourly for nudges and daily for the triggers, so a
  cleared cache costs an interval rather than a notification, and there is no
  new table to keep out of `MergeRulesRegistry`.
- **The replay runs where the key is.**
  `Modules\Notifications\Internal\Http\Middleware\RunDeferredNotificationPasses`
  is an `AfterResponseMiddleware` on the `web` group of **both** roots. An
  unlocked request is the only process on a phone that holds the app-lock key.
- **The passes run in-process, not on the queue.** `queue:work` builds its own
  session exactly the way WorkManager's process does, so a pass queued from a
  request would be refused one layer further down — the same wall this page opens
  with. `BudgetNudgeDispatch::forUserNow()` and the `forUserNow()` twins on
  `PaymentReminderDispatch`, `PositionDigestDispatch` and `SavingsPromptDispatch`
  are `dispatchSync`.

### Why the unlock still feels instant

The unlock is both the one interaction that has to be immediate and the first
request that can run any of this, so the two would collide on every open. They do
not, because the work is terminate-time: the response is already sent.

The cost of a request with nothing to do is one cache read per pass — two. The
keyring is opened only once a mark says something is waiting on it, so an install
that never enabled encryption never builds the codec's graph at all. This is the
same shape, and the same argument, as
[`RecoverSealedLedger`](../sync/sensitive-columns-at-rest.md#getting-back-inside-the-guarantee),
which re-seals rows a keyless writer left readable; this one re-derives the
content a keyless writer was refused outright.

`notifications:daily-triggers` consumes its once-a-day `DailyLocalWindow` claim
even when it can seal nothing, and that is deliberate: the claim gates the
*command*, and the replay is per user and does not go through it. Giving the
replay a claim of its own would only add a second key to keep in step for work
that is already idempotent.

### Change capture was behind the same wall

`SyncCaptureListener` resolved `OpLogWriter` lazily and logged the refusal at
debug — 4,925 lines in one real log, every one a mutation no peer ever received.
The repair is the same shape as this section's and is argued on its own page:
[A mutation a keyless process cannot
sign](../sync/a-mutation-a-keyless-process-cannot-sign.md).

### What is not fixed here, and why not

`forecasting:project`, `recurring:detect` and `open-banking:sync-due` are left
alone on purpose. Gating them the way the two notification passes are gated would
suppress work that needs no key — the projection, the series, the import — to
protect a notification at the end of it. The repair for those is a reconciliation
from the row that *did* land, computing the deterministic notification id and
emitting only what is missing, and it needs its own decision about which alerts
still deserve a notification days later. `open-banking:sync-due` carried a second
defect of its own besides — a sync that fetched rows, filed none of them and
reported success, forever, with no failed job to show for it. That one is fixed:
see [A feed that imports nothing](../open-banking/a-feed-that-imports-nothing.md).
The notification at the end of its import is still on the list above.

### The outcome is nameable now

`NotificationWriter::write()` returned a row id whether or not a row was written,
so no caller could tell a withheld notification from a duplicate, and the eight
`Persist*` listeners could only report the refusal as an ERROR from inside a job
that then reported `processed`. It returns `NotificationWriteResult` —
`Written`, `Duplicate` or `Deferred` — and converts the refusal itself into
`Deferred` with one warning per user per process, the same de-duplication the
codec keeps and for the same reason. `PersistBudgetNudge`'s `catch (Throwable)`
now means what it says: a genuine failure.

### What the reader is told

`notifications::settings.background_note`, in twenty-six languages, sits under
"What to notify me about" on the notification settings screen — and only for a
reader whose ledger is actually sealed, because an install with plaintext columns
has a scheduler that writes these fine and would be told about a limit it does
not have. It says Beatrax prepares these while it is open, and that it cannot in
the background because the app lock holds the only key.

The wait it then names is per platform, because it is not the same wait. The
replay runs in `RunDeferredNotificationPasses`, `web` middleware on **both**
roots: a phone reader is told anything due arrives the next time they open the
app, and a desktop reader — already inside the app that replays it — is told it
is picked up as they carry on using it. Encryption alone was the wrong gate for
that half of the sentence; it named an open-the-app step a desktop reader is
past. `NotificationsSettingsSection` reads `UserDataPathService::platform()`
beside the encryption check and the blade picks `background_note_phone` over its
desktop twin.
