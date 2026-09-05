# Which columns are encrypted at rest, and why the rest are not

`SensitiveFieldRegistry::columns()` is the single list of `(table, field)` pairs that both
encryption hooks — the op-log writer and the projection-column codec — treat as content
requiring Group Data Key encryption at rest. Nothing else consults a second list, and a new
entry lands there only after an explicit scope decision.

The interesting content of that list is not what is on it. It is why the obvious "encrypt
everything" answer is wrong, and what the exceptions cost.

## The rule

A column is encrypted when it holds **content a human wrote or a bank sent** — free text,
names, IBANs, raw payloads. It is left in plaintext when the database itself has to reason
about the value.

Currently encrypted:

- `transactions.note`, `.description`, `.counterparty_name`, `.counterparty_iban`,
  `.raw_payload`
- `counterparties.display_name`, `.merchant_name`, `.iban`
- `tax_transaction_tags.note`, `transaction_splits.note`
- `notifications.title`, `.body`, `.params`, `.trigger_type`

## Asking whether a column is sealed

A caller that hands the codec a whole attribute array never has to ask: `encryptAttrs()`
consults `SensitiveFieldRegistry` per key and leaves the rest alone. A caller that seals ONE
named column does have to ask, because `encryptValue()` seals whatever it is given rather than
consulting the registry — and the answer is `SensitiveColumnCodec::isEncrypted($table, $field)`,
a Public passthrough to the same registry.

It exists because the alternative is a second copy of the list.
`Receipts\Public\Actions\ApplyReceiptConflictResolution` held one, under a comment saying it
mirrored this registry, and by the time anyone looked the mirror named two of the five
`transactions` columns registered here. It was harmless only by coincidence — a second copy
elsewhere intersected it at exactly those two — and the day a conflict was raised on
`counterparty_iban` it would have written that value in the clear for an encrypted reader, with
nothing failing and nothing logged. A copy of this list is the one shape that turns a silent
plaintext write into a matter of time.

## What may be written when no key is reachable

Nothing. A registered column must never receive plaintext while
`sync_encryption_state.current_epoch` is set, and `SensitiveColumnCodec::encryptValue()` /
`encryptAttrs()` now refuse rather than pass the value through.

The refusal exists because the pass-through was silent and provable. A live desktop's
`notifications` table held one column in two states for one user: thirteen rows sealed by the
enable-time sweep at 15:44, and six rows in the clear written at 16:00 by
`EmitBudgetNudgesJob` on the queue worker. A queue worker has no HTTP session, so
`AppLockKeyService::release()` returned null, so no epoch was reachable, so the codec returned
the title and body unchanged and `NotificationWriter` inserted them. The settings screen
reported encryption **On** throughout, nothing was logged, and no later pass ever re-read that
column to notice. This is not a Notifications defect — every module's background writers reach
the same path, and Notifications is only where it became visible.

### The two states that used to be one null

`tryCurrentEpoch()` returns null for two opposite reasons, and collapsing them is the whole
bug:

- **No `current_epoch`.** This user never enabled encryption. Every other column of theirs is
  plaintext too, and writing one more is correct — refusing would break an install behaving
  exactly as designed. The same live database's second user was in precisely this state, and
  their plaintext row is not a defect.
- **`current_epoch` set, no key reachable.** The ledger is sealed and this process simply
  cannot open it: the app-lock is withheld, or the keyring no longer opens under the key the
  session holds. Writing here is the leak.

`GdkKeyringService::hasCurrentEpoch()` answers the first question — a plain integer readable
with no key at all — and it is the one read both codecs now share, so they can never disagree
about whether a row is supposed to be sealed.

### Why refusing, rather than a background key source

`BlindIndexCodec` already made this decision and `SensitiveColumnCodec` simply never got it:
enrolled plus no key throws `BlindIndexKeyUnavailableException` with the reason "refusing to
write a plaintext matching key". `SensitiveColumnKeyUnavailableException` is the AEAD half of
the same rule. Another call site had already reached it independently:
`ReindexSearchCommand` composes `isEnabled()` with whether this process holds key material
for the user, partitions on the answer, and leaves the ones it cannot key untouched, naming
every skipped user in one error line. The established answer for background work that cannot
reach a key is **do less, and say so**.

Giving background writers a sanctioned key source is the alternative, and it is excluded by
the app-lock design rather than merely expensive. Only two durable copies of the data key
exist, both in one row of `user_app_lock_configs`, and
[nothing else on the machine can produce it](../auth/app-lock-data-key-lifetime.md).
`LockOnWindowHideOrClose` withholds the key on every desktop window hide or close, ungated by
`lock_enabled` — a closed window being unable to read the ledger is the design, not a gap in
it. A third copy reachable without the user present would make the lock cosmetic and would
put key material somewhere that page's invariant does not cover.

Deferring the write until a key exists was the third option. The codec cannot do it: it
transforms a value and does not own the write, so it has nowhere to hold one. The decision
between dropping and deferring belongs to the caller, and the throw is how the caller is given
it. In practice the split is already clean. Background writers carry regenerable content and
drop it — all eight `Persist*` notification listeners already catch `Throwable` and log, and
`OpLogEntryApplier` routes both of its projector calls to quarantine. Writers carrying content the user typed run inside
a request behind `AppLockMiddleware`, where the key is held and the refusal is unreachable; if
one is ever reached, failing the save is the right outcome, because storing the note in the
clear is not.

Dropping a nudge only costs a nudge while a later run exists to re-emit it. On a device whose
scheduler is a cold-started process — every phone, and every console on the desktop — no such
run does, and the cost is the feature. Which passes that applies to, and the seam that
re-derives them from an unlocked request instead, are on
[the scheduled passes that cannot write either](../mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either).

### It is no longer silent

The codec logs one warning per user per table per process before it throws, naming the fields
and never their values. It is deliberately one alarm rather than one per row: an op-log drain
over a sealed ledger would otherwise write a line per column per row into a daily log nobody
could then read. `warning`, not `critical` — a withheld key is the ordinary state of a closed
window, and the genuinely unrecoverable case already raises `auth.lock.key_material_stranded`
at critical from the Auth side.

### The rows already written in the clear

`EncryptionMigrationService::migrate()` early-returns once `current_epoch` is set, so the
enable-time sweep is once-only by construction; it is the only pass that has ever rewritten a
projection column, and it will not run again on an install that is already enabled. An install
that ran a background writer while locked therefore kept those rows readable on disk — the same
shape as the table the sweep used to miss entirely, arriving by a different door.

A live desktop holds exactly that: `notifications` for user 1 carries thirteen rows sealed by
the sweep at 15:44 and six rows in the clear stamped `2026-08-22 16:00:09`, title `Budget
nearly spent`, `trigger_type` `budget_nudge`. User 2, who never enabled encryption, has one
plaintext row that is not a defect.

The residue is a **closed set**. The codec now refuses, so no background writer can add to it;
`OpLogEntryApplier` routes both of its projector calls to quarantine rather than writing in the
clear; and `SensitiveColumnPredicateGuardTest` is what stands between a raw `update()` and a
registered column. What was missing was a pass to convert what is already there.

#### Why the enable-time sweep cannot simply be re-run

`projectionUpdatesForRow()` makes the enable-time sweep re-runnable on an install that has
never rotated, and not on one that has.
`EncryptionMigrationSupport::alreadyEncryptedProjectionValue()` verifies under the **current**
epoch only. `GdkRotationService::rotateAndRevoke()` appends an epoch and advances
`current_epoch`, and nothing re-encrypts the projection columns behind it — so on any device
that has ever removed a peer, correctly sealed rows sit under an epoch that is no longer
current. Driving a re-seal off that predicate would read them as plaintext and wrap the
ciphertext a second time: still recoverable by decrypting twice, but every affected column
would render blank, because one pass of the keyring returns base64 rather than text.

`PlaintextResidueSweep` therefore asks a different question. A value is residue only when
`SensitiveColumnCodec::decryptValue()` hands it **straight back** — every epoch in the keyring
failed to open it, *and* the codec did not blank it as ciphertext-shaped. That covers the
rotated case, and it errs towards leaving a value alone: an unopenable ciphertext (an epoch
this device lacks) is skipped rather than wrapped again.
`ResealSurvivesAnEpochRotationTest` pins both halves.

## Getting back inside the guarantee

Two things end up outside the encryption guarantee, and they are the same shape: content that
is on disk, readable or unreadable, with nothing that will ever bring it back in. `SealedLedgerRecovery`
is the one seam for both, because both need the same precondition — a reachable key — and
neither is achievable without one.

### The entries a locked desktop quarantines

`sync:serve` is a console daemon. It has no HTTP session and never will, so **every** peer
drain on a desktop runs with no app-lock key. `OpLogEntryVerifier` persists the entry to
`op_log_entries` first and only then tries to decrypt, so the authoritative log is complete;
the decrypt fails closed and the entry is quarantined as `gdk_decrypt_failed`. That is correct
— projecting it would mean writing the peer's IBAN in the clear — but `op_log_quarantine` is
audit-only, and until this change nothing on desktop ever replayed from it. `Modules/Mobile`'s
`InitialSyncPuller` was the only caller of the whole-log re-projection `HistoryReprojector` once
exposed; that method is gone, and the phone reaches the same bounded pass every other caller
takes, `HistoryReprojector::replayQuarantined()`.

### Why the recovery is not a rebuild

`reproject()` was a full `OpLogRebuilder::rebuild()`: drop triggers, delete every op-created
row, replay the entire log, restore triggers, re-index FTS across every transaction. It looked
like the right shape for the case it was written for — a phone's initial import, where the
device has no projection and the whole log is new — and it was the wrong shape here, because on
desktop it would run after every single sync and hold SQLite's writer for seconds on a large
ledger.

It was the wrong shape there too. Loading the log alone costs about 645 bytes an entry, so the
step before the replay even begins took 124.5 MB at 130,000 entries and exhausted the phone's
128 MB ceiling at 200,000 — and what the gate actually needed was only the entries that
quarantined. Both devices now take the pass below; the argument for it did not change, only
which devices it was known to apply to.

`replayQuarantined()` replays only what the quarantine names. The safe unit is **not** the
failed entry: a strategy resolves over the set it is handed, so one op of a field makes that op
the LWW winner over a newer one already projected, one field of a `CreateRow` is discarded as
incomplete, a G-Counter sums the wrong per-device maxima, and a tombstone compares against a
truncated maximum. The safe unit is **every persisted entry for each `(table, pk)` a
quarantined entry touches** — `PersistedOpLogEntries::forRows()`. That set carries each field's
whole history, every field of a create, and the row's tombstone beside them, so every strategy
sees exactly what a full replay would give it.

Nothing else the rebuild does is needed. The delete exists to drop rows whose creating ops were
pruned, and skipping it is what makes this non-destructive; an insert whose duplicate-key
failure is swallowed, plus per-field updates, converge on their own. Triggers stay up, because they are up for the incremental drain
too. In fact the narrowed replay **is** the incremental drain — `OpLogReplayer::replay()`, built
the way `SyncWebSocketHandler` builds it, with the confirmed-device key map read for this user
explicitly rather than from the container's idea of who is signed in — reading its entries from
the durable log instead of off the wire. `LockedDrainRecoversOnceUnlockedTest` pins the
narrowing behaviourally with a row the quarantine never names: a whole-history replay puts the
logged value back over an out-of-band edit, and the narrowed one leaves it alone.

A full rebuild remains the right answer in two places and stays available for them: the mobile
initial import, and a projection damaged by something other than the op log, which only a
from-scratch reconstruction repairs.

### Telling "not yet openable" apart from "never openable here"

The narrowing alone does not stop the recurrence. An entry sealed under an epoch whose wrap
never reached this device fails on every attempt, so the quarantine keeps naming rows and the
pass keeps running — cheaper per pass, still forever, still recovering nothing.

`op_log_quarantine.gdk_epoch` is copied from the entry when the row is written, which makes the
question answerable without replaying anything: an entry is worth attempting when its epoch is
in the keyring this device actually holds, or when it carries no epoch at all — a null epoch is
a **refusal** rather than a failed decrypt, the codec declining to seal because no key was held,
and a key alone undoes it.

Nothing is discarded on that verdict. The entry stays in `op_log_entries`, the audit row stays
in `op_log_quarantine`, and the pass only declines to replay it **now** — the same distinction
`GdkWrapOutcome::Deferred` draws for the wrap itself, and for the same reason: a GDK epoch can
arrive after the frame that needed it. What reopens the question is
`sync_encryption_state.reprojected_keyring_fingerprint`, a content hash of the keyring file.
It needs no app-lock key to read, so it can gate the pass on a request that holds none, and it
changes whenever an epoch is appended, replaced or rewrapped. A pass that runs with a different
fingerprint than the one recorded ignores its own watermark and asks across all of history
again.

Both marks are stamped whenever a pass **ran**, not only when it replayed something. A pass that
looked and found only entries it has no key for has still answered the question for this
keyring, and leaving the marks behind is what made it ask again on every request.

### What the reader is told

A desktop that has been synced to but not opened holds the data in its op log and shows none of
it. Nothing is lost and it appears on the next unlocked request, but a screen that is briefly
behind and a sync that is broken look identical from the reader's side, so the state has a name.

`SyncBacklogState` sits beside `QuarantineReason` — the same rows, read for a different
question — and borrows `GdkWrapOutcome`'s vocabulary rather than inventing a second one:

- **`Deferred`** — received, decodable here, not yet written into the tables the screens read.
  It clears by itself on the next request. The notice exists so the gap does not read as loss.
- **`AwaitingKey`** — received, and this device holds no key for the epoch it was sealed under.
  Time does not clear this one, so it must not borrow the other's words: telling somebody to
  unlock where unlocking cannot help is worse than saying nothing. The remedy is a pairing one —
  open the app on the other device so the two can connect and the key can be sent again.

`AwaitingKey` outranks `Deferred` when both are present, because it is the half that will not
resolve on its own and reporting only the self-healing half would leave the stuck one invisible.
`clearsWithoutHelp()` is the single place that split is expressed, so a screen cannot get it
half right. `DevicesAndSyncSettingsSection` reads the state in `mount()`, after
`applyHeldKeyWraps()` — that drain is what installs a wrap this device was holding, and asking
first would report a wait the same mount had already ended.

### When it runs, and what was rejected

`RecoverSealedLedger` is a `web`-group middleware on the desktop root, and it does its work
after the response has been sent — in the `afterResponse()` hook its `AfterResponseMiddleware`
base calls from `terminate()`.

- **On unlock alone** is incomplete, not merely unavailable. `AppLockUnlocked` now exists and
  Sync already listens to it, so it could be hung there — but the desktop case that produces
  quarantine is a phone syncing while the window is *open* and the session is *already*
  unlocked, and no unlock event follows that. A request-time trigger covers both: the first
  request after an unlock carries the key, and so does every request after a drain.
- **On a schedule** is the trap. A queue or scheduler tick has no session, so it has no key,
  so it can do nothing — it is the same process that created the residue.
- **From the settings screen** leaves the fix behind a button for a leak the user cannot see,
  on a screen most people open once. `migrate()` is already called from there and from
  pairing, and that is precisely why the residue survived.
- **After a detected refusal** is a good signal for the *future* and useless for the *past*:
  a refusal means nothing was written. The rows already on disk predate the refusal.

It is registered on the desktop root only. The mobile root drives its own re-projection from
the import cursor, and a second one firing per poll would rebuild the whole history on every
tick of a running import.

### What it costs when there is nothing to do

A file hash and two indexed reads, before the keyring is touched:

- one row of `sync_encryption_state`, which answers "is this device enrolled at all" and
  carries all three marks;
- `HistoryReprojector::keyringFingerprint()`, a hash of a key file a few hundred bytes long,
  read without the app-lock key;
- one `EXISTS` against `op_log_quarantine`, which carries `(user_id, created_at DESC)`.

That last question is deliberately the cheap, epoch-blind one — "has anything arrived that no
pass has looked at". The exact question needs the keyring, and asking it here would make every
page load of an enrolled device decrypt a key file to learn there is nothing to do. Only if one
of the three says there may be work does the pass ask `SensitiveColumnCodec::canSeal()`, and a
request that arrives while the app is locked stops there having written nothing.

The re-seal is gated on a **digest of `PreMigrationSnapshot::PROJECTION_COLUMNS`** rather than a
boolean, stamped in `sync_encryption_state.resealed_columns_digest`. A boolean would cover the
install that has residue today and nothing else; the digest also re-sweeps when a release
registers a new column on an install that is **already** enabled, which is the one door
plaintext can still arrive through and the exact shape of the `notifications` bug above.
`finalizeMigration()` stamps it, because that pass has just swept every column it covers.

The replay is gated on the watermark `history_reprojected_at`, compared against
`op_log_quarantine.created_at` for the two reasons a key can undo —
`QuarantineReason::keyRecoverable()`, which is `gdk_decrypt_failed` and `strategy_error`. A
forged signature stays forged, and replaying history for one would only reach it again. The
watermark is stamped **after** the pass, not before: the pass re-quarantines whatever it still
cannot open, and a watermark taken first would read those rows back as new work on the very
next request.

The honest cost, per drain that quarantined something: one `EXISTS`, one keyring read, and a
replay of the rows the quarantine names — not of the log. It is paid in `terminate()`, so the
page the reader is looking at is already rendered. A device holding entries under an epoch it
will never receive costs the two reads and nothing more, on every request, until the keyring
changes.

### The re-seal writes through the codec, not around it

The sweep updates registered columns with `PreMigrationSnapshot::writeRowsById()` — the same raw
writer the enable-time sweep uses, and for the same reason `OpLogRebuilder` takes raw DB access:
routing it through each owning module's writer would emit a `Set` op for a change that is not
one. Every peer would receive "the note changed" carrying identical plaintext, the HLC ordering
would move, and a value a peer had since deleted could be resurrected. What must not be bypassed
is the codec, and it is not: every value goes through `SensitiveColumnCodec::encryptValue()`,
the same call the owning modules make, refusing under exactly the same condition. The marker
writes go through `EncryptionRecoveryMarkers` because `sync_encryption_state` is Sync's table.

### What re-sealing does not fix

It bounds **future** readability. It does not erase history. SQLite leaves the old page contents
in the freelist and in the write-ahead log, and this database runs in WAL mode with a 3.8 MB
`-wal` beside a 2.1 MB main file. A `VACUUM` would rewrite the main file and a checkpoint would
retire the WAL, but neither is performed here and neither reaches a backup, a Time Machine
snapshot, or an SSD's own remapped blocks. Anyone who had read access to the file while the row
was in the clear still has what they read. The pass converts what the ledger will hand out from
now on, and that is all it claims.

## Why money columns are not on the list

`transactions.amount_minor`, `settled_amount_minor` and `fx_rate_used` are deliberately
excluded. At least eleven query classes run SQL-side `SUM()` and `GROUP BY` over them, and
SQLite cannot aggregate ciphertext. Encrypting them would mean loading every row of a
multi-year ledger into PHP to add up a month's spending — the balance, the budget bars and
every report would stop being a query and start being a full-table decrypt.

That is the general shape of the boundary: a column the database aggregates, joins on, or
filters by cannot be random-nonce ciphertext, because ciphertext is different bytes every
time it is written.

## Why some notification columns stay plaintext

`notifications` carries an encrypted body but a plaintext skeleton, and the split is load
bearing:

- `id` is matched in deduplication `WHERE` clauses. It is also, unusually, a deterministic
  digest rather than an autoincrement surrogate — see
  [How a replay decides what the row should say](op-log-merge-rules.md).
- `user_id`, `created_at`, `read_at`, `dismissed_at` and `state` drive pruning and the unread
  count. Those run without the app-lock key held; encrypting them would make the badge on a
  locked app impossible to compute.

## The knowingly-accepted exceptions

Two columns hold decrypted values on purpose. Each is a reviewed decision, not an
oversight:

- **`migration_import_baseline.baseline_value`** snapshots a plaintext value so the
  three-way merge resolver can compare against it.
- **`pending_enrichment_conflicts.stored_value` / `.incoming_value`** hold decrypted values
  of a held receipt-enrichment conflict until the user resolves the prompt, so the prompt
  never renders ciphertext.

Deferred rather than decided: `counterparties.metadata` and `saved_reports.definition`.

## How the encryption is bound to its epoch

`OpLogFieldCrypto` is XChaCha20-Poly1305 IETF AEAD, framed as
`base64(nonce || ciphertext)`. The associated-data argument is the epoch-binding channel, and
it has **two shapes**, one per storage location. An op-log entry has a primary key to bind and
passes `"{table}:{pk}:{field}:{epochId}"` — `OpLogWriter` writes it, `OpLogEntryVerifier` checks
it. A projection column is the row, so there is no separate pk term to bind and it passes
`"{table}:{field}:{epochId}"`; `SensitiveColumnCodec::associatedData()` is the only place that
builds that one, and it is `public static` so a raw SQL pass can reproduce it without the codec.

Either way, relabelling the stored epoch tag invalidates the authentication tag. That is defence
in depth alongside the Ed25519 signature that already covers the whole op-log entry. The two
shapes are not interchangeable: hand the projection path a `{pk}` term and every decrypt returns
`false`.

Decryption returns `false` — never a throw, never garbage — on invalid base-64, a blob too
short to contain a ciphertext, or an authentication failure. Callers must use a strict
`=== false` check; `!$result` would treat a legitimately empty plaintext as a failure. A
false result routes to quarantine.

Reads are rotation-safe: the projection codec tries **every** epoch in the keyring, and
returns the raw stored value with `decrypted: false` when none verifies, so a legacy
plaintext value from before encryption was enabled still reads correctly. That is also how
the op-log backfiller distinguishes the two cases — a value handed back untouched is an
ordinary pre-encryption row, whereas the codec *blanking* it means it held ciphertext no
epoch in the keyring opens, and capturing that would put an unreadable value on the wire.

## The guard that catches a query written against ciphertext

A `WHERE counterparty_iban = ?` against an encrypted column does not fail. It returns no
rows, silently and forever, because the stored bytes are a fresh random-nonce ciphertext and
the predicate is plaintext. The same is true of an `ORDER BY` that sorts by ciphertext, a
join on one, or a raw `update()` that writes plaintext straight into an encrypted column.
None of these raise; they just quietly stop being right.

`SensitiveColumnPredicateGuardTest` is a source scan standing in for the type system that
would otherwise have caught it. It walks every production file under `Modules/` — skipping
`tests/`, `Database/` and `Resources/` — and looks for each bare column name from
`SensitiveFieldRegistry::columns()` appearing in one of the shapes that only makes sense
against plaintext: a `where`/`whereIn`, an `orderBy`/`groupBy`, a join `->on(...)`, a
`whereRaw(... LIKE ...)`, a raw `json_decode(...)`, or a `'column' =>` key inside an
`update()`/`insert()` array. A file that mentions any codec marker — `SensitiveColumnCodec`,
`decryptValue`, `encryptValue`, `encryptAttrs` — is assumed to be routing through the codec
and is skipped wholesale.

It is a substring scan, so it is coarse in both directions. It matches a *bare* column name,
which means `accounts.iban` trips on the registry's `counterparties.iban` entry even though
`accounts.iban` is plaintext and always has been. And the codec-marker check is per file, so
one decrypting read in a file exempts every other query in it. An AST rule modelled on
`app/PhpStan/Rules/BoundaryRule.php`, matching a `MethodCall` node with a sensitive string
literal argument, would be precise where this is not; it has not been built.

### Adding an allowlist entry

`sensitive-column-guard-allowlist.php` maps a repo-relative path to the reason that file is
safe. Three reasons are legitimate:

- a **different table** happens to share the bare column name, as with `accounts.iban`;
- the predicate is a `whereNull`/`whereNotNull` presence check, which works on ciphertext
  because it never compares the value;
- the file already decrypts by a route the marker list does not recognise.

Anything else is the bug the guard exists to find, and the fix is the query, not the list.
Write the reason so the next reader can re-derive the judgement without opening the file.

## The guard that catches a column rendered as ciphertext

The predicate guard above is a source scan, and this class of defect is not visible to one.
Three static designs were tried against the real bug and all three fail:

- scanning Blade for the registry's column names drowns in `title`, `body`, `note`,
  `description` and `params` — a Blade `$title` is a page title, and 28 files match that word
  alone;
- flagging a file that names a sensitive column and carries no codec marker gives a **false
  negative on the exact defect**, because the pre-fix `CounterpartyTriageQueue` already carried
  three markers: it decrypted transaction descriptions a few lines below the counterparty
  columns it missed;
- diffing selected columns against decrypted columns per file reports `selected=[]`, because
  the offending query selects `*` through the raw builder and calls `Model::hydrate()`, so the
  leaking columns are never named in the source.

`RenderedCiphertextGuardTest` is behavioural instead. It seeds recognisable plaintext, enables
encryption through `EncryptionMigrationService::migrate()` — the production enable path, not a
hand-rolled fixture — then renders twenty-two surfaces over the ciphertext that produces and
asserts that the exact bytes stored in the database do not appear in what reaches the browser.

Twelve are full HTTP renders: `/counterparties/triage`, `/counterparties`,
`/counterparties/{slug}`, `/community/mystery-merchants`, `/transactions`, `/transactions/{id}`,
`/uncategorized`, `/notifications`, `/recurring`, `/recurring/review`, `/recurring/series/{id}`,
`/tax`, `/reports`, `/cash`, `/calendar` and `/imports/{id}/preview`. Six are components with no
route of their own, mounted by alias so no module boundary is crossed: the ⌘K palette, the chain
drawer, the receipt-conflict toast, the rule-form modal, the rename-counterparty popover, and the
alias match preview on `/settings/aliases`.

For a Livewire component the assertion runs over `html()` **and** the public properties. A leaked
value reaches the browser inside the `wire:snapshot` payload whether or not the view prints it,
and the palette is a hidden container whose results live only there — checking the rendered HTML
alone would have called it clean.

The census is built from `SensitiveFieldRegistry::columns()` and
`SensitiveFieldRegistry::blindIndexColumns()`, so a column added to either accessor is covered on
every one of those surfaces for free, provided the fixture writes a row in its table.

Two surfaces are deliberately excluded, and the reason is the same for both: they render nothing
that comes from a registered column. The import preview's **rows** are parsed from the uploaded
file and held in `PreviewCache`; `TaxSummaryCard` renders totals. Covering either would add a
case that cannot fail. The import preview is in the list above for a different reason, below.

Two things about it are load bearing and easy to remove by accident.

**The precondition.** Decrypting plaintext is a documented no-op, so a fixture that quietly
failed to encrypt would let a completely broken read path pass on every screen at once. The
first case reads every registered column back out and requires `decrypted: true` before a
single screen is rendered.

**The positive half.** The absence assertion cannot see a value a view masks, truncates or
reformats first — the triage card puts the IBAN through a mask that prints six characters of
it, which is exactly why the shipped bug read `7F · ·· HUX5 ···· ···· ==` rather than a
recognisable blob. Each screen therefore also asserts the plaintext it exists to show, in the
form it shows it. That half doubles as the check that the fixture still reaches the screen,
since an empty state passes an absence assertion perfectly.

The blind index rides along in the same census. All **three** of its columns do —
`transactions.counterparty_normalized`, `merchants.normalized_name`, and
`recurring_series.cluster_counterparty_key`, which
`CounterpartyKeyBackfill::convertSeriesClusterKeys()` converts — **both** halves of it, an
expense row's counterparty matching key under `DOMAIN` and an income row's payer IBAN under
`DOMAIN_IBAN`, the domain chosen per row from the series' direction — and which is the one
`CounterpartyKeyHasOneProducerTest` omits from its write markers. They are keyed digests rather
than AEAD and are deliberately absent from `SensitiveFieldRegistry`, so a guard reading that
list alone has no opinion about a digest on a screen — which is the same defect wearing a
different mechanism.

`CounterpartyKey::NONE` — `_no_counterparty` — is in the census beside the digests. The sweep
stores it verbatim on purpose, because it records the *absence* of a counterparty rather than
naming one, which is the right call for the two guards that compare against it. It is still a
machine token in a column the reader must never be shown, and it is the one blind-index value
`looksDerived()` answers `false` for, so a rule written as "reject what looks derived" lets it
straight through. That is not hypothetical: `MerchantDisplayName::forStoredKey()` returns null
only when `looksDerived()`, so a cluster of un-named expenses writes `detected_name =
'_no_counterparty'` and the recurring review screen renders it.

The same census run also asserts the crypto layer's own vocabulary is absent —
`BlindIndexCodec`, `SensitiveColumnCodec`, `OpLogFieldCrypto`, a `Modules\Sync\` namespace
fragment. That is a different shape from a leaked value and it has a live instance:
`ImportPipeline` catches `Throwable` around the normalise stage and puts `$e->getMessage()`
into the preview row, so `BlindIndexKeyUnavailableException` is printed to the reader once per
row, naming an internal class and their own user id where "unlock the app and try again"
belongs. The same file already routes its *log* message through `MessageNamesNoUserData`; the
preview row does not, and a screen needs the stricter rule of the two.

### What the guard renders with, and the branch it used to leave unreached

Most of what the guard renders is rendered **unlocked**: the fixture calls
`AppLockTestHarness::unlock()`, migrates, and does not withhold the key again. That is the right
setting for the question those cases ask, because a reader that skipped the codec leaks whether or
not a key is held.

For a long time that was the only setting, and it left the other branch of
`SensitiveColumnCodec` unrendered. When no epoch opens a registered
column, `safeUndecrypted()` substitutes the **empty string** for anything shaped like ciphertext,
precisely so base64 cannot reach a reader. Two ordinary states reach it: a session holding no
app-lock key, and a keyring file that no longer opens under the key the session does hold. Neither
throws, and neither is visible in `sync_encryption_state`, which records only the epoch pointer.

#### The shape test is not evidence on its own

`looksLikeCiphertext()` asks whether a value is drawn from the base64 alphabet and decodes to at
least 40 bytes — the floor for a 24-byte nonce plus a 16-byte tag. Read as a *test for ciphertext*
that is sound, because every ciphertext this codec writes passes it. Read as a *decision to
destroy a value*, it is a coin toss: any 54 characters or more of letters and digits, with no
space or punctuation and a length divisible by four, decode as base64 too.

That is not a contrived string. `AbonnementSpotifyPremiumFamilyPlanMaandelijkseIncassoRotterdam`
is the kind of run-together token banks really export into a description, and a cash entry typed
on an iPhone with a 300-character counterparty was blanked on the spot — the row intact in the
device's own SQLite, the span drawn for it empty and 0px tall. Encryption was switched off on that
install, which is the default, so the value that got destroyed could not have been ciphertext at
all.

The read path now asks the question the write path already asked. `encryptValue()` stores
plaintext for exactly one user — the one who never enabled encryption — and refuses rather than
writing in the clear for anyone else. So for a user with no `current_epoch`, whatever is stored
**is** the plaintext, and there is nothing for a shape test to decide. `undecryptable()` blanks a
value only when `everEnrolled()` confirms the ledger is genuinely sealed; the shape test runs
first purely because it is free and never misses real ciphertext, which keeps the extra
`sync_encryption_state` read off every ordinary row.

A keyring that loaded is itself proof of enrolment, so the no-matching-epoch path inside
`decryptWithKeyring()` still decides on shape alone. That is the case the guard was written for
and the one that must not change: encryption really is on, this device cannot open the value, and
base64 on the screen is what that used to look like.

So every reader of a registered column has a third case besides "plaintext" and "decrypted": a
value that is present, empty, and not the user's. `/notifications` found this the hard way.
`NotificationCopy::typeChip()` indexed a nine-entry map with the decrypted `trigger_type` and
threw `InvalidArgumentException` on the empty string, so one sealed row returned 500 for the whole
inbox while every other screen returned 200 with quietly blank fields. The lookup is total now and
falls back to a neutral chip, the row renders `notifications::row.unreadable` in place of a blank
title, and `NotificationQuery` logs one warning per page naming the row ids — a sealed inbox
should be discoverable, not merely quiet. A column whose plaintext is a **lookup key** needs that
treatment; a column whose plaintext is prose degrades to an empty string on its own.

### How a reader is told, rather than made to guess

The third case is now a fact the codec hands over rather than one each reader infers.
`decryptRow()` returns a [`DecryptedRow`](../../../Modules/Sync/Public/Dto/DecryptedRow.php): the
column values, readable by name exactly as the plain array was, plus `isUnreadable(field)` and
`hasUnreadable()` naming the registered columns this codec had to blank. A column is named there
when no epoch opened it **and** the codec actually replaced its value with the empty string — a
legacy plaintext row passes straight through and is not "unreadable", and neither is a value the
enrolment check above handed back. The flag reports the blanking itself rather than re-deriving it
from the shape test, because since that check the two no longer agree.

That distinction cannot be recovered from the value. `transactions.note` legitimately holds the
empty string, and so does a `note` no key in this keyring opens; read off the value alone they are
one state. The inference only ever worked for columns whose plaintext is never empty, and nothing
enforced that: `NotificationQuery` carried a comment explaining that an empty `trigger_type` was
its one signal that a row was sealed, because there was no flag to read. It now asks
`hasUnreadable()`, `DeepLinkResolver` asks `isUnreadable('params')` instead of testing the decoded
JSON for emptiness, and `CounterpartyProfileQuery` answers `null` — absent — for a sealed `iban` or
`merchant_name` rather than presenting a blank field as the stored one.

The branch is now rendered too. A second arm re-unlocks the session under a **different** key
after the migration, so the keyring file no longer opens under the key the session holds — the
stranded state, reached without withholding the key, which matters because a withheld key would
be turned away by `AppLockMiddleware` before any screen rendered. It walks the same fifteen full
page routes and asserts each still answers 200 and still leaks no stored value. Reintroducing the
non-total `typeChip()` lookup reproduces the shipped failure exactly and only there:
`/notifications: 500`. A companion case proves the fixture really is stranded, by reading a
registered column back and requiring `['value' => '', 'decrypted' => false]` — without it, "200
with blank fields" would be indistinguishable from a fixture that quietly stayed readable.

### Why the two column lists stay separate

`SensitiveFieldRegistry::columns()` is not a description, it is an instruction: `encryptAttrs()`,
`decryptRow()`, `OpLogValueProjector` and the enable-time sweep all *act* on every entry. Adding a
blind-index column to it would put AEAD over the one column the database has to match on, which is
the failure this whole design exists to avoid. So the two lists must not merge.

They should still be reachable together, because the readers that want both are guards, audits and
the question "could a human read this value". The shape that satisfies both is a **third accessor**
beside `columns()` and `knowinglyPlaintext()`, and it now exists: `blindIndexColumns()` returns
`array<string, list<string>>` — the three columns, each mapped to **every** domain its rows may
derive under — and `blindIndexSentinel()` names the one value they hold in the clear. The value
is a list rather than a single domain because `recurring_series.cluster_counterparty_key`
genuinely holds two, and a guard that compared a column against one string would be wrong for
every income row. `columns()` is unchanged and is still the only thing the codec consults. A
guard composes the two explicitly rather than one list quietly acquiring a second meaning.

The render guard reads both accessors and pins that they stay disjoint, which fails loudly if a
blind-index column is ever added to the registry — the change that would put AEAD over a column
the database matches on, and leave the ledger silently failing to deduplicate.

### What the enable-time sweep reaches, and how it came to miss a table

`notifications` was in `SensitiveFieldRegistry` and **not** in
`PreMigrationSnapshot::PROJECTION_COLUMNS`, and `EncryptionMigrationService` named its four
tables in a literal list of its own. So a user who already had notification rows when they
turned encryption on kept those rows in plaintext on disk indefinitely, while the UI reported
encryption **On**. New notifications were fine — `NotificationWriter` encrypts on write — and
the op-log arm covered `op_log_entries` for every registered field. Only the existing projection
rows for that table were never rewritten, which is why nothing ever looked wrong.

`notifications` is now in `PROJECTION_COLUMNS`, and — more to the point — the sweep, the
pre-migration snapshot and the rollback restore all iterate `array_keys(PROJECTION_COLUMNS)`
instead of each carrying its own copy of the table list. Three lists that had to agree, and did
not, are now one. `EncryptionSweepCoversEveryRegisteredColumnTest` seeds a plaintext
notification before `migrate()` and asserts it comes out sealed.

The narrower claim still stands and is worth keeping: adding a column to the registry covers a
not-yet-enabled user *only when that column's table is in `PROJECTION_COLUMNS`*. A new table
needs an entry there in the same change, or its history is left in the clear.

## The identity columns that are still plaintext, and what it would take to fix them

`accounts.iban`, `accounts.name`, `accounts.slug`, `counterparties.slug` and
`transactions.counterparty_normalized` are all readable with no key on a live desktop database
while the UI reports encryption **On**. `counterparty_normalized` was the worst of them —
sixteen cleartext merchant names beside sixteen ciphertext `counterparty_name` values saying
the same thing — and it is the one that has since been fixed, by the keyed blind index
described in the next section. The rest are still plaintext.

Every one of those columns is a **matching key**, and that is why none of them can take the
AEAD treatment the rest of the list gets. Random-nonce ciphertext is different bytes every
time it is written, so an equality predicate against it returns no rows, a UNIQUE index over
it never collides, and a route parameter built from it resolves to nothing.

- **`accounts.iban`** backs eleven production equality predicates, including
  `EloquentAccountResolver` (the import-time statement-to-account match), `TransferPairer`,
  `ClassifyTransactionType`, `CounterpartyResolverService::resolveSelfAccount()`, and four
  separate duplicate-account existence checks. It also carries `unique(user_id, iban)` and
  feeds two `sha256` chain signatures. Encrypting it turns every import into "unknown IBAN",
  turns every own-transfer into a merchant, and makes the duplicate-account guard a no-op.
  The column is also `string(34)`, too narrow for `base64(nonce || ciphertext)`.
- **`counterparties.slug`** is the URL segment of `/counterparties/{slug}` and the key
  `CounterpartySlugResolver`'s collision walk predicates on. Encrypt it and every candidate
  reads "free", every counterparty takes the base slug, and the UNIQUE index that would have
  caught it never fires.
- **`categories.slug`** additionally has global rows with `user_id IS NULL`, and the codec
  keys on a user. There is no key to encrypt them under.
- **`accounts.slug`** carries `unique(user_id, slug)` and `AccountSlugResolver::isTaken()`
  walks collisions with `where('slug', …)->exists()`. Same shape as `counterparties.slug`:
  ciphertext reads every candidate as free and the UNIQUE index never fires.
- **`accounts.name`** is the only one of the five with no equality predicate. It is
  encryptable in isolation, at the cost of ten `ORDER BY` sites that would silently sort by
  ciphertext bytes, roughly fifteen display sites across forty-four files that reach
  `table('accounts')`, a three-way merge that compares against a plaintext baseline, and a
  `PROJECTION_COLUMNS` entry (`accounts` has none today, so a registry entry alone would not
  even sweep an existing user). The pattern to follow is `CounterpartyIndexQuery`, which
  orders by `id` in SQL and `usort()`s after decrypting.

  **But encrypting it in isolation is incoherent, not merely expensive.** `accounts.slug` is
  `Str::slug()` of `accounts.name` and provably cannot be sealed, so a sealed `name` leaves a
  readable copy of itself one column over — exactly the objection that stands against
  `counterparties.display_name` sitting beside a plaintext `counterparties.slug`. `accounts.name`
  and `accounts.slug` move together or not at all, and moving them together means a blind index,
  not an allowlist entry.

### The decision is recorded, not merely absent

`SensitiveFieldRegistry::knowinglyPlaintext()` lists these columns with a one-line reason
each, so "not on the encrypted list" stops reading the same as "nobody looked". The four
`accounts`/`counterparties` identity columns are joined there by the five readable columns argued
at length further down this page — `merchants.name`, `recurring_series.detected_name`,
`transaction_search_docs.search_body`, `known_counterparty_ibans.real_iban` — because the registry
is where an engineer looks first, and a column argued only in prose read from there as one nobody
had looked at. `known_counterparty_ibans.real_iban` is the newest of them and the least obvious:
it is a **counterparty's** IBAN in cleartext, `string(34)`, carrying `unique(user_id, real_iban)`,
predicated on by every IBAN-matching resolver arm, and now read by the enable-time sweep to
recover the IBAN half of a chain-link signature. Three tests in
`SensitiveColumnPredicateGuardTest` hold the two lists honest against each other: they must be
disjoint, every column an allowlist reason leans on must appear in `knowinglyPlaintext()`, and
no allowlist reason may cite a column the registry has since started encrypting.

### The keyed blind index in `counterparty_normalized`

For a user with at-rest encryption enabled, `transactions.counterparty_normalized` holds
`HMAC-SHA256(blind-index key, "beatrax-blind-index:v1|counterparty-normalized|{userId}|
{normalised name}")` rendered as 64 lowercase hex characters, which fits the column's
`varchar(80)`. Equality and uniqueness survive exactly; the readable name does not.

#### Why the same merchant occupies two columns

`transactions.counterparty_name` and `transactions.counterparty_normalized` hold the same
merchant, under two different constructions, because they answer two different questions.

`counterparty_name` is **AEAD** — XChaCha20-Poly1305 with a fresh random nonce. It is
reversible with the key, which is what a screen and a Levenshtein comparison need, and it is
different bytes on every write, which is what makes it useless as a matching key: an equality
predicate never matches, a `UNIQUE` index never collides, and a `GROUP BY` puts every
occurrence in its own bucket. That is the general reason a column the database has to *reason
about* cannot be sealed.

`counterparty_normalized` is a **blind index** — a keyed one-way digest. It is deterministic,
so the database can compare, group and enforce uniqueness on it exactly as it did on the
plaintext, and it is irreversible, so holding the file buys nothing without the key. What it
cannot do is come back: nothing recovers a name from it, which is why every consumer that
needs readable text reads the AEAD column instead.

The digest is bound to the derivation domain, the user id, and the normalised name — and to
nothing else. Not the row, not the epoch, not the write. That is deliberate and it is the
whole difference from AEAD: two rows for one merchant must produce identical bytes, or the
index that dedups them stops working.

`BlindIndexCodec` is the keyed primitive. `CounterpartyKey` is the only thing that produces a
value for this column. Six sites call it: three production —
`Import\Public\Pipeline\NormalizeStage`, `CashBook`'s `RecordManualTransaction`, and
`Migration`'s `PromoteStagingToDomain` — and three demo seeders, which matter for the guard's
scope even though they are not on any user's path. A user who has never enabled encryption gets the
plaintext normalised name back unchanged, because every other column of theirs is plaintext
too and keying only this one would buy nothing.

The `_no_counterparty` sentinel is stored verbatim. It records the absence of a counterparty
rather than naming one, so keying it would conceal nothing while costing the two guards that
compare a stored value against it (`RuleEvaluator`, `MerchantMemoryWriter`).

`merchants.normalized_name` moves with it. `RuleEvaluator` and `MerchantMemoryQuery` compare
the two columns directly, so they share one derivation domain. No production code inserts a
`merchants` row — they arrive by op-log replay, carrying whatever the originating device
stored — so only the enable-time sweep and the demo seeder had to change.
`recurring_series.cluster_counterparty_key` is a copy of the same value on the expense path
and follows for free.

#### The four constraints this design has to satisfy

**There is no rotation-stable key.** True of epochs, and the reason the blind-index key is not
one. It is separate key material in the same KEK-wrapped keyring file, minted once beside the
first epoch and never rotated. `GdkRotationService::rotateAndRevoke()` appends epochs and does
not touch it. The consequence is stated plainly below.

**The key is not held at every write.** This dissolved once the writes were separated into the
ones that *compute* the value and the ones that only *copy* it. Only three production sites
compute, and every one of them is an authenticated Livewire request behind `AppLockMiddleware`:
the import wizard, the cash book, and the migration importer. There is no headless import path.
(Three demo seeders also compute; they run from dev mode, which is a request too.) Op-log
replay never computes — `OpLogValueProjector::reencryptForProjection()` passes a non-sensitive
column through unchanged, so the digest the originating device computed is what the peer
stores, and the sync daemon needs no key at all. That is also why this column is deliberately
**not** added to `SensitiveFieldRegistry`: it is already keyed, and AEAD over it would make it
unmatchable.

Where a value must be computed and the key is not held, `BlindIndexCodec::derive()` throws
`BlindIndexKeyUnavailableException` rather than passing the plaintext through. That is the
whole point: this column sits inside `transactions_fingerprint_uq`, and one statement stored
under two lock states would make a re-import a second ledger.

The neighbouring failure is deliberately a different type in a different place. A key that IS
held but is not valid hex throws `BlindIndexKeyMalformedException`, which lives in Sync's
`Internal\Exceptions` rather than beside its sibling on the public surface.
`BlindIndexKeyUnavailableException` is public because `ImportPipeline` acts on it — it maps to
the "the app is locked" preview row, and unlocking is a thing the reader can do. Nothing
unlocks invalid hex, so no caller outside Sync has a different response to it. Both extend
`RuntimeException`, so a caller catching the base type still catches both.

**The fingerprint is re-derived from the stored column.** Solvable purely by ordering, and it
was. `FingerprintComposer::compose()` treats `counterpartyNormalized` as an opaque tuple
member, and both re-derivers — `FingerprintRederiveService::buildCanonicalFromRow()` and
`Migration`'s `EntityChangeApplier` — echo the stored value back rather than re-normalising a
name. A hash of a hash is still stable, so as long as the key is applied at canonicalisation
every downstream derivation stays deterministic and needs no key.

**Some consumers need plaintext, not equality.** They now read the plaintext where it actually
lives. `counterparty_name` is encrypted but decryptable, and that is the source both of them
use:

- `PaypalFundingResolver::fuzzyMatch()` selects and decrypts `counterparty_name` for the
  Levenshtein term, and keeps the stored key for `signatureHash()` so earlier evidence still
  matches. Two digests of one merchant spelled two ways are as far apart as two unrelated
  merchants; the similarity would have collapsed below `FUZZY_MIN_CONFIDENCE` and the whole
  arm would have gone silently dead.
- The recurring detectors write a *displayed* name. `MerchantDisplayName::forStoredKey()`
  answers null when the key is a digest and neither `merchants.name` nor the decrypted
  `counterparty_name` knows a name, and the detector defers the series to the next sweep
  rather than putting a digest on the review screen. That is the same choice
  `DetectRecurringSeriesJob` already makes for undecryptable IBANs.

`IcsSettlementResolver` needed nothing: all four of its uses are equality on both sides.

#### Where the key lives, and why it cannot live anywhere else

The blind-index key is 32 random bytes in the keyring file, wrapped under the app-lock KEK,
alongside the epochs. It is never written to the database and never leaves that file except as
a sealed, signed wrap addressed to a confirmed peer.

The requirement that decides this is that the key must be **secret**. Merchant names are
low-entropy: an attacker holding the database file and the key could HMAC a dictionary of Dutch
merchants and match every row, and the exercise would be theatre. So the key cannot sit in
plaintext beside the data, which rules out a device-held key in a file the sync daemon can read
without a passphrase — the daemon's ability to read it is exactly the attacker's ability to read
it, because the threat model is someone holding the disk.

That pulls against "available at every write" only if every write must compute. It need not, and
does not. The two requirements are reconcilable precisely because the computing writes are the
interactive ones. Nothing is deferred and nothing is quarantined; the write that cannot compute
is refused, and there is no production path that reaches it.

#### Fan-out, and why it is not a new message type

A joining device receives the key through the existing `GDK_EPOCH_WRAP` channel with a
`key_role` of `blind_index` and an `epoch_id` of `0`, sent by
`GdkRotationService::fanOutAllEpochsToDevice()` after the epoch loop.
`GdkEpochWrapSignature::signingMessage()` appends the role term only when it is not the default,
so an epoch wrap signs byte-identically to one a build without roles produced, and such a build
rejects a role-bearing wrap on signature rather than adopting a blind-index key as an epoch.
`SyncWebSocketHandler`'s live-push filter keys on the message type, which is unchanged, so the
wrap rides the same session.

#### What happens when two devices hold different keys

Two facts decide it, and both sides have both: whether **this** device holds rows keyed under
the key it has, and whether the **sender** does. The second travels on the wrap as
`sender_holds_keyed_rows`, bound into the Ed25519 signature, so flipping it in transit breaks
verification rather than changing which key the recipient keeps.

| local keyed | sender keyed | outcome |
| --- | --- | --- |
| — (no local key at all) | either | adopt |
| yes | yes | **keep local, log an error, and hold the wrap** — neither can give way without orphaning its own digests |
| yes | no | keep local; the peer runs this same branch inverted over the wrap this device sent it, and adopts |
| no | yes | adopt; the side with rows is the side with something to lose |
| no | no | lowest key hex wins — an order both sides compute identically |

**Six of the eight orderings converge after one exchange, and two do not.** The two that do not
are the `yes | yes` row, taken from either side; that is a deliberate refusal rather than a gap,
and it is the residual described at the end of this section. Every other row reaches the same
answer whichever wrap lands first, because adoption changes only the stored key — it does not
change either side's keyed-rows answer, so both decision functions are evaluated over identical
inputs regardless of interleaving. Re-delivery is stable for the same reason: once B has adopted
`K_A`, a redelivered A-wrap compares `K_A` against `K_A` and short-circuits on `hash_equals`.

The last row is why a tie-break is needed at all: two devices that both enrol before either has
imported would otherwise **swap** keys and stay diverged, because each builds its wrap from its
own keyring before the other's arrives.

##### Both sides send

The table above is only true of a **symmetric** exchange, and the exchange that shipped was
asymmetric. `fanOutAllEpochsToDevice()` had exactly one production caller — `PairingFlowModal`,
rendered only from the desktop and web settings section — so a phone received a wrap and never
sent one. Three of the four rows of a desktop-and-phone household therefore diverged, one of them
with nothing logged on either device, and the `no | no` row was decided by which side happened to
be the receiver rather than by the tie-break.

That is closed on both legs. `MobilePairingScan` fans out to every confirmed peer at the same
both-confirm transition the desktop does, and `LanSyncClient` pushes what that fan-out queued over
the same authenticated session it reads the desktop's wraps on — announced with its own
`GDK_EPOCH_PUSH` header, acknowledged with `GDK_EPOCH_ACK`, symmetric in both directions.
`BlindIndexExchangeIsBidirectionalTest` pins the send path and the fan-out caller, because the
premise is a property of the *topology* and a symmetric test harness would assume it away.

A phone can hold the keyed ledger — phones import statements — so "the desktop's key wins" was
never an available shortcut. It would orphan the ledger of a phone-first household exactly as
surely as the bug it replaced.

The keyed question is asked of the **data**, not of a marker, and it is asked as a question about
**authorship** rather than about shape. `LocallyKeyedRowsProbe::holdsRowsKeyedUnder()` unions two
proofs, either of which is sufficient: an op-log entry this device itself signed carrying a
digest, and a stored digest this device's current key still reproduces from the plaintext beside
it (`BlindIndexProvenance::reproducesAStoredDigest()`, implemented in the ledger because only the
ledger knows how each column normalises its plaintext before hashing).

Measuring shape alone is not enough, which is why `BlindIndexCodec::looksDerived()` is not the
answer here: a digest-shaped value proves only that *somebody* keyed the row, and a peer's
replayed rows look exactly like a device's own. Authorship cannot be replayed.

A marker cannot answer it either: a device that enables sync on an empty
ledger and imports afterwards sweeps **zero** rows — every row it then writes is keyed at write
time and so was never convertible — yet its whole ledger is under its own key. Reading a
"sweep ran" marker as "device holds keyed keys" left that device silently handing its key away
to any peer that later paired with it. `BlindIndexKeyDeliveryTest` walks that exact sequence:
enable sync empty, import, pair.

`sync_encryption_state.counterparty_key_backfilled_at` still exists and is still set
unconditionally, but it now answers only one question — has the one-time sweep run — which is
what stops it rescanning three tables on every screen mount.

The genuine residual is the `yes | yes` row: two devices that each enrolled, each imported, and
only then paired. Both keep their own key and both log an error. Nothing re-derives one side
automatically; the recovery is to re-derive from the decrypted `counterparty_name`, which
`MerchantDisplayName::fromTransactions()` shows is readable.

That refusal returns `GdkWrapOutcome::Retained` rather than `Applied`, and the difference is the
whole point: `Retained` leaves the mailbox row alone. The wrap is the only copy of the peer's
index key, and it is exactly what a re-derivation would need, so retiring it would delete the
material that resolves the conflict. It expires on the mailbox's own TTL like anything else that
is never consumed.

#### What else the sweep had to move, and what it deliberately does not

Three columns beyond the obvious one, each because something compares against it:

- **`merchants.normalized_name`**, joined directly to `transactions.counterparty_normalized`.
- **`recurring_series.cluster_counterparty_key`**, in **both** directions. An expense series
  stores the counterparty matching key; an **income** series stores a *decrypted IBAN*, which
  the detector reads back out of the AEAD-sealed `transactions.counterparty_iban` to compute.
  Written verbatim, that put the salary payer, the benefits agency and the pension provider
  back in the clear one table over from the ciphertext protecting them. It now derives under a
  second domain, `counterparty-iban`, kept separate so a merchant whose normalised name
  happened to equal an IBAN string could not match that payer. The sweep tells the two apart by
  the row's **`direction`** first — an expense series' cluster key is always a matching key, so
  only an income row is shape-tested at all — and the shape test normalises whitespace and case
  before it matches. Case cannot carry that argument any more, and it never could: nothing on the
  import path normalises an IBAN, so a statement printing `NL22 INGB 0006 5432 10` stored it
  spaced, and a case-sensitive bare-uppercase regex classified it as a *name*, keyed it under the
  wrong domain, and silently duplicated the salary series. The value hashed is
  `CounterpartyKey::normalizeIban()`, the one spelling convention the live detector uses too.
- **`chain_links.evidence->signature_hash`**, which is `sha256(matching key|funding IBAN)` and
  is what `ConfirmChainLink` counts confirmed links on. Left alone, every link confirmed before
  encryption stopped matching what the resolver computes after it, and the three-link
  auto-promotion counter silently reset. The IBAN half is not on every arm's evidence, so it is
  recovered from three sources in order: the blob's own `matched_iban`, then `accounts.iban`,
  then `known_counterparty_ibans.real_iban`. The third is there because the alias arm hashes a
  **counterparty's** IBAN, which `accounts` never holds — trying the user's own account IBANs
  alone left every `Confirmed` link that arm had ever written orphaned, and a test pinned that
  gap as intended. A hash none of the three reproduces was not built by any arm and is left
  alone. This pass runs **first**, while the transactions it reads still hold plaintext.

- **`recurring_series.cluster_key`**, recomposed in the **same statement** as
  `cluster_counterparty_key`, for the same reason `transactions.fingerprint` moves with
  `counterparty_normalized`: the cluster key is composed *over* the counterparty key, so the two
  move together or the series splits. Left behind, a swept income row read
  `cluster_counterparty_key = <64 hex>` beside `cluster_key = income::nl91rde0987654321::eur::monthly`
  — the payer IBAN in cleartext, in the indexed half of `rec_series_uniq`, and in
  `MergeRulesRegistry`'s `_create_required`, so it synced in the clear as well. Healing through
  `SeriesRefresher` is not enough on its own: both detectors return early for `Rejected` and
  `Snoozed` series, and a series whose transactions fall outside
  `recurring_detection_window_months` is never revisited.

`recurring_series.detected_name` is deliberately **not** swept. It is a display column, and the
name it already holds is readable and correct.

#### The sweep that converts existing rows

`EncryptionMigrationService` runs `CounterpartyKeyBackfill` as the last step inside the
enable-time transaction, and once more for a user who already had `current_epoch` set before
this column existed, on the first unlocked call to `migrate()`.

It rewrites `transactions.fingerprint` in the same statement as
`transactions.counterparty_normalized`. It has to: the fingerprint is a SHA-256 over a tuple
containing that column, so a row converted without it would no longer match its own re-import,
and `FingerprintStage` would classify the statement as new. The date fields go back through
`CarbonImmutable` before reaching the tuple so the result is byte-identical to what a fresh
import composes. `CounterpartyBlindIndexTest` pins both halves and fails on either alone.

Every pass is chunked by id. The sweep runs inside the enable-time transaction, so a whole-table
load would hold one SQLite writer lock across a ledger the size of a life — on a single-threaded
desktop server that wedges every other request, and one `max_execution_time` rolls the enable
back. `E4-R12` requires bounded batches inside one outer transaction; the batches are bounded,
the transaction stays outer, and resumability across a crash remains all-or-nothing by design.

The tables the sweep visits come from `PreMigrationSnapshot::PROJECTION_COLUMNS`, as do the
snapshot and the rollback restore — see [what the sweep
reaches](#what-the-enable-time-sweep-reaches-and-how-it-came-to-miss-a-table).

#### The guard that keeps one producer

`CounterpartyKeyHasOneProducerTest` scans every production file under `Modules/` for a supplied
value for `transactions.counterparty_normalized` or `merchants.normalized_name`, and fails
unless the file names `CounterpartyKey` or is pinned as a pass-through with a written reason.
It is the same substring technique as `SensitiveColumnPredicateGuardTest`, and it is coarse in
the same direction: a file that mentions `CounterpartyKey` anywhere is assumed to be routing
through it.

It exists because a second producer fails silently. Nothing raises; the column simply holds two
forms of one merchant inside `transactions_fingerprint_uq`, and the statement that produced the
first re-imports as a second ledger.

#### Where the key lives on a phone, and the one condition that argument rests on

The custody argument above holds only while the app-lock KEK is reachable through the passphrase
alone. On the desktop it is: `SecretShield` binds to `SafeStorageSecretShield`, so the biometric
wrap blob is machine-bound in the OS keychain. **Everywhere else it binds to
`PassthroughSecretShield`, which is the identity function**, and
`user_biometric_credentials.biometric_wrap_secret` stores the unwrapping key concatenated with
the wrapped key, in the same SQLite file as the ledger. A row of that shape on a phone would
hand a disk-image attacker the KEK, then the keyring, then this blind-index key, and the
low-entropy dictionary attack this design exists to prevent.

No such row should exist on a phone. Mobile biometrics are the **secure enclave**, not WebAuthn
— the spec's platform matrix says so — and `AppLockSettingsSection::startEnroll()` is
double-gated: it enrols against `ColdStartVault` when the mobile vault plugin is present, and
refuses outright when `nativephp-internal.running` is true. Neither gate ever reaches
`dispatch('beatrax:webauthn-create')` on a correctly built device.

Both of those gates are **behavioural, not structural**, and they were the only ones. The three
`/lock/biometric/*` routes are registered unconditionally, the JS listener ships in the mobile
bundle, and the Auth settings component renders on the phone's sync screen. A build without the
vault plugin and without `NATIVEPHP_RUNNING` fell straight through — and so, by construction, did
the **self-hosted web deployment** this project supports: there `nativephp-internal.running` is
never true and `ColdStartVault` is the null implementation, so enrolment was not a gap to close
but the default path, writing `secret || wrapped_key` into the SQLite file with the identity
function as its shield.

There is now a third gate, and it is structural: `WebAuthnBiometricController` refuses the
creation challenge and `EnrolBiometricCredential` refuses the enrolment itself, both unless the
bound `SecretShield` reports `protectsAtRest()`. The two answer with the same payload, because the
controller maps every enrolment outcome through one place. That is a capability on the contract rather than a platform test, so it covers
every route into enrolment however it is reached, and it fails closed on a shield that only *looks*
like one — `SafeStorageSecretShield` answers it by round-tripping random bytes through the
custodian rather than by returning `true`, because Electron's `safeStorage` is unavailable on a
desktop with no keyring and the custodian silently returns the plaintext there. What a self-hosted
reader sees is a localised sentence explaining why, not a button that does nothing.

`F3-R33` (operating-system key custody registered but not wired) and `E4-R23` (unwired OS key
custody must be documented as outstanding rather than implied to work) are still open, and this
paragraph is still that documentation — but the failure mode has inverted. The absent mobile
custody now means a phone *cannot enrol*, rather than enrolling into cleartext. The symmetric fix
remains a mobile `SecretShield` over `Native\Mobile\Facades\SecureStorage`, the seam
`SecureStorageKeyCustodian` already uses for the session data key.

One residual has no gate at all, because it predates one: nothing re-shields a
`biometric_wrap_secret` written before `SafeStorageSecretShield` existed, and
`SafeStorageSecretShield::reveal()` reads such a row back transparently. No tagged release
contains enrolment without the shield — both landed inside `2.0.0-probe.4` and neither is on any
`v1.x` tag — so the exposure is confined to desktop builds made from the branch between them.
Closing it needs a way to tell an unshielded blob from a shielded one whose keychain rotated,
which the custodian's null return cannot do; a `shielded_at` column, or a version prefix on the
blob, plus de-enrolment of anything unclassifiable.

#### What this does not fix

- **The full-text search index still holds the merchant name in the clear.**
  `transaction_search_docs.search_body` is `transactions.counterparty_name` +
  `transactions.description` + `tax_transaction_tags.note` — three AEAD-sealed columns, decrypted
  at write time so FTS5 can tokenise them, one row per transaction in the same file. The note is
  the *tax* note specifically; `transactions.note` and `transaction_splits.note` are sealed and
  never enter the index.
  `SELECT search_body FROM transaction_search_docs` recovers exactly what the blind index was
  meant to hide. [ADR-0018](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0018-amounts-plaintext-at-rest.md) records that shadow as knowingly accepted and
  names an encrypted-search design as the revisit; until that exists, this change removes one
  of two plaintext copies, not the leak. **The UI copy must not claim otherwise** — and that is
  now a test rather than an instruction: `DevicesAndSyncEncryptionUiTest` derives the cleartext
  column set from `SearchIndexWriter` itself and fails if the permanent status row stops naming
  any of them. It had to, because the row said "notes and transaction descriptions are encrypted"
  while carving out merchant names only, and an assertion that checks only what *is* present
  cannot catch an under-disclosure.
- **`merchants.name` is a readable merchant name, and the join hands it to every transaction.**
  `SELECT t.id, m.name FROM transactions t JOIN merchants m ON m.normalized_name =
  t.counterparty_normalized AND m.user_id = t.user_id` recovers the merchant for every row the
  user has ever categorised, with no key — which is most of what the AEAD on
  `counterparty_name` was protecting. `MerchantDisplayName::fromMerchants()` performs exactly
  that join by design, so it is not hypothetical. It is **not encrypted**, and the reason is
  the same one that keeps `normalized_name` out of `columns()`: the two columns are one row,
  and the name is what the recurring review screen and the triage list render. Encrypting it is
  possible — it has no equality predicate — at the cost of every display site decrypting, and
  it is the single highest-value remaining column. It is recorded in `knowinglyPlaintext()` with
  that reason, so the registry says "argued and accepted" rather than nothing at all.
  `recurring_series.detected_name` is the same shape and the same decision.
- `counterparties.slug` stays readable because it is a URL segment.
- `ClusterKeyComposer` truncates each part to 60 characters, so `cluster_key` carries 240 bits
  of the digest rather than 256. Collision-free in practice, but a `cluster_key` can no longer
  be read back to the digest that produced it. The sweep composes the same 60 characters — the
  composition is mirrored in `CounterpartyKeyBackfill` rather than shared, because Ledger cannot
  import `Recurring\Internal`, and `ClusterKeySurvivesTheSweepTest` pins a swept row against what
  `ClusterKeyComposer` would have produced byte for byte.
- `SeriesEntryPlacer`'s cluster-key-to-slug fallback only ever fired for single-token merchants
  where the normalised key happened to equal the slug. It can no longer fire for an encrypted
  user; those calendar entries lose their counterparty deep link and resolve through the
  occurrence link or not at all.
- A device removed from the group keeps the blind-index key, because it is never rotated. It can
  therefore confirm whether a given merchant appears in a database file it later obtains. It
  already held every one of those names in plaintext while it was trusted, and
  [ADR-0015](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0015-multi-master-p2p-sync.md) states that revocation is not a defence against a device that
  was trusted while it was listening.

### One thing that did not need encryption

`accounts.slug` used to end with the last six or eight characters of the IBAN, appended so two
accounts under one user could not land on the same `unique(user_id, slug)` row. That is the
rule `counterparties` already keeps — its migration says the slug is the kebab-cased display
name "and nothing else" because it reaches a URL — and `PrivacyDefaultsTest` enforces it there.
`AccountSlugResolver` now derives an account slug from the account name alone and separates
collisions with a numeric suffix, and
`2026_08_21_000002_strip_the_iban_tail_from_account_slugs` re-slugs the rows the old
generators wrote. It matches only the exact shape they produced — the name slug, a hyphen, and
a run that really is the tail of that row's own structurally valid IBAN — so `-ics-card`,
`-paypal`, `cash-7` and a plain `-2` are left alone. `accounts.name`, `.slug` and `.iban` are
all plaintext, so the migration needs no key and runs on a locked device.

### The re-key that a schema migration must never attempt

Adding a column to `SensitiveFieldRegistry` covers new writes and covers a user who has not
enabled encryption yet, because `EncryptionMigrationService::migrate()` sweeps
`PreMigrationSnapshot::PROJECTION_COLUMNS` at enable time. It does **not** cover a user who
already has `current_epoch` set: that method returns early, so their existing rows keep the
plaintext. Re-keying them needs the app-lock KEK, and a Laravel migration never holds one.
The safe shape is the one `migrate()` already uses — when the KEK is unavailable, return and
leave the data untouched, to be retried on the next unlock; never write over a value that
could not be read first.

### The allowlist entry that would quietly become a lie

`sensitive-column-guard-allowlist.php` exempts six files on the stated grounds that their
`where('iban', ...)` targets `accounts.iban`, "a plaintext column SensitiveFieldRegistry never
lists". The guard skips allowlisted files *before* scanning. The moment `accounts.iban` is
added to the registry, those six exemptions silently cover the six most dangerous predicates
in the codebase, and the allowlist honesty check cannot detect it — it only greps reasons for
`broken`/`TODO`/`FIXME`. Any change that lists `accounts.iban` must delete those six entries
in the same commit.

That is now enforced rather than merely written down. `SensitiveColumnPredicateGuardTest` pulls
the `{table}.{column}` pairs back out of each allowlist *reason* and fails if any of them has
entered `SensitiveFieldRegistry::columns()`, with an in-memory negative probe pinning that the
check really does catch all six. Promoting `accounts.iban` without deleting the exemptions goes
red on two tests, not zero.

### What the status row must disclose

`/data-devices` used to caption the status row *"Your data is secured with your app-lock
passphrase."* — one clause, no qualification, next to a database file whose `accounts.iban`,
`accounts.name`, both `slug` columns and `transaction_search_docs.search_body` are readable with
no key at all. A privacy-motivated reader reasonably concluded their merchant history was
unreadable. It was not.

`sync::devices.encrypted_at_rest_scope` replaces it — a **new key**, so no locale can be left
behind still rendering the old promise, and the retired `encrypted_at_rest_help` is gone from all
26. It names both halves: what the passphrase covers, and that amounts, dates, the reader's own
account name and IBAN, and some merchant names elsewhere in the file are not covered. The
enable-encryption modal already made that disclosure; the status row is the surface a reader
actually returns to, so it has to make it too. Pinned by `DevicesAndSyncEncryptionUiTest`, which
asserts the row names what is *not* covered and asserts the unqualified sentence is absent.

## A backup of the database alone is a backup of ciphertext

The keys that open every column on the list above live in
`storage/app/sync/gdk/{userId}.enc`, wrapped under the app-lock data key. That file is
**beside** the database, not inside it, and `sync_encryption_state.current_epoch` — the
pointer that says the rows are sealed — is inside it.

That split is what makes the encrypted archive from Settings → Data & backup a special
case. `VACUUM INTO` copies the database and nothing else, so an archive restored anywhere
the keyring is not carries a `current_epoch` naming an epoch no keyring holds a key for.
`GdkKeyringService::readKeyringFile()` returns an empty keyring for a file that does not
exist, `currentEpoch()` then raises `KeyringStateException::missingKeyForEpoch`, and
`SensitiveColumnCodec::decryptRow()` blanks every sealed column and marks it unreadable.
Nothing errors at restore time. The restore reports success.

The install where the keyring is not there is not a corner: the mobile
`MobileRestoreFromBackup` screen is gated to a **fresh install**, which is by definition a
device with no keyring at all. That is the same outcome as the update that once wiped
`storage/app` — [a database full of ciphertext with no key](../core/durable-user-data-paths.md),
reached by a different road.

So the archive carries the keyring. `Modules\Core\Internal\Backup\BackupKeyMaterial`
writes every `sync/gdk/*.enc` on disk into a `beatrax_backup_keyring` table inside the
snapshot before it is encrypted, and lifts them back out on restore — before the swap, so a
failure there leaves the live database untouched — then **drops the table**, so the live
database never holds key material a query could read. A keyring already on the restoring
machine is renamed to `{path}.pre-restore-{stamp}` rather than overwritten: it may hold an
epoch the incoming database does not name, and a restore is not the moment to find that out.
An archive written before the table existed simply carries none, and still restores.

`Modules\Sync\Public\Services\PortableKeyMaterial` is the one spelling of that path.
Three copies of `sync/gdk/{userId}.enc` existed as string literals before it, and a keyring
written where nothing looks for it is indistinguishable from no keyring at all.

### What deliberately does not travel

The **device identity** (`sync/identity/`) does not. It names one device to the group, and a
restore that installed it would put two devices on the network claiming to be the same peer.
A second device joins by pairing, which is what the /sync screen offers; the archive is the
single-device recovery path.

The cost of carrying the keyring is stated rather than assumed: an archive plus its
passphrase plus the app-lock PIN now opens the sealed columns, where before the archive alone
could never open them whatever the PIN. The PIN's wrapped key was already in the archive —
`user_app_lock_configs` is a table like any other — so what changed is that the last link is
present. Against that, without it the archive is not a backup of the ledger at all, only of
the half of it the database can reason about.

## See also

- [Removing a device: revoke, rotate, fan out](device-removal-and-epoch-rotation.md) — where
  epoch keys come from and why they are never discarded.
- [Sync architecture](architecture.md) — the class-by-class map of the codec, casts and
  enable-time migration.
- [Getting a group data key epoch onto every device](gdk-epoch-wrap-delivery.md) — the channel
  the blind-index key is delivered on, and what each delivery outcome does to the carrier.
- [Search architecture](../search/architecture.md) — the plaintext shadow named above, from the
  side that writes it.
- [Recurring detection under encryption](../recurring/detection-encryption-posture.md) — what the
  detectors read, and what they may write beside a keyed column.
- [A background task on the phone cannot hold the key](../mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either)
  — the five scheduled commands this refusal stops, and where their content is re-derived.
