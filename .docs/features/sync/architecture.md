# `Sync` — architecture

The `Sync` module implements local-first, end-to-end encrypted multi-device
synchronization: a CRDT-based op-log replicated over either a LAN-direct
WebSocket (Noise protocol handshake) or an opt-in zero-knowledge relay, with
per-user "Group Data Key" (GDK) at-rest encryption for sensitive columns and
a QR/word-code pairing ceremony that establishes device trust via a
human-verified safety number. Under the code-comment policy (ADR 0011) the
architecture and security-rationale prose for these classes lives here rather
than in their docblocks, and each such class carries an `@link` back-reference
to its section below.

## Clock and merge configuration

### Hybrid Logical Clock (`Internal\Clock\HybridLogicalClock`)

Implements the Kulkarni-Demirbas 2014 algorithm ("Logical Physical Clocks and
Consistent Snapshots in Globally Distributed Databases"). The HLC merges
wall-clock time with a logical Lamport counter so events are ordered causally
while remaining close to physical time. Two components are maintained:

- `$l` (physical time in milliseconds): the highest wall-clock or
  remote-entry timestamp seen so far.
- `$c` (counter): a monotone counter that increments only when `$l` cannot
  advance (i.e. when wall-clock has not moved forward).

Bound TRANSIENT (not singleton) in `SyncServiceProvider`: the HLC holds
mutable `$l`/`$c` state, and sharing a singleton would leak clock state
across unrelated callers — each resolve gets a fresh zero-state HLC.

### Merge rules registry (`Internal\Config\MergeRulesRegistry`)

Config-driven per-table, per-field CRDT merge strategy registry. Maps
(table -> field -> strategy config) for all user-editable tables. Adding a
new table for capture is one entry here plus hand-wired emission at the edit
site — no engine changes required.

Strategy keys: `lww` | `g_counter` | `or_set`. Unknown table/field defaults
to `lww`. Per-table special keys:

- `_delete_wins` (bool) — tombstone wins on equal-HLC tie (default true)
- `_create_required` (list) — NOT NULL columns required in CreateRow ops.
  Exactly the NOT-NULL-without-default columns, minus the two
  `OpLogEntryApplier::buildCreatePayload()` seeds itself (`id` from the op's pk,
  `user_id` from the session scope). BOTH directions are enforced by
  `MergeRulesRegistrySchemaGuardTest`: a name that is not such a column
  quarantines every create of that table, and a column MISSING from the list
  passes the completeness gate and then dies at the INSERT.

Notable per-table quirks:

- `envelope_settings.threshold_percent` and `saved_reports.pinned` carry a DB
  default and are nullable:false in the strategy map, but must stay OUT of
  `_create_required` — a column with a DB-level default must be allowed to
  arrive without a value on CREATE_ROW.
- `notifications.id` is the one exception: it is a non-autoincrement sha256
  string primary key computed by domain code before insert (never DB
  autoincrement), so it IS listed in `_create_required` — `OpLogReplayer`'s
  CREATE_ROW assembly never fills in a pk column on its own, and omitting a
  non-autoincrement string PK makes the row fail the `id` NOT NULL constraint.
  `notifications.state` is the opposite case:
  deliberately absent from the registry entirely, since it is locally derived
  by `NotificationStateMachine` and never synced.
- `envelope_moves`, `goal_contributions`, `pot_movements` and
  `recurring_series_occurrences` are append-only ledgers: a row exists or it
  does not, so each carries `_create_required` and `_delete_wins` and **no**
  strategy key at all. A SET op against one is meaningless, and
  `SyncCaptureListener` logs an `edit` on `goal_contributions` as an unknown
  mutation type rather than writing one.
- `system_alerts` rows with a NULL `user_id` are system-wide and belong to no
  one. The backfill scopes every table on `user_id`, so those rows are never
  captured — deliberately, since an operational alert is about the machine
  that raised it.

## At-rest encryption (Group Data Key)

Which columns are encrypted, which are knowingly left plaintext, and what an attacker with
the database file can still read: [Which columns are encrypted at
rest](sensitive-columns-at-rest.md).

### GDK epoch keyring (`Internal\Crypto\GdkEpoch`, `GdkKeyring`)

`GdkEpoch` is an immutable `{epochId, keyHex}` pair — one entry in a user's
GDK (Group Data Key) epoch keyring. `epochId` is **minted, not counted**:
`GdkEpochId::mint()` returns `random_int(1, 2^53 - 1)`, retried against the ids
the keyring already holds. Epoch ids therefore carry no order at all, and
nothing may reason from one being larger than another — see [Epoch ids are
minted, not
counted](device-removal-and-epoch-rotation.md#epoch-ids-are-minted-not-counted)
for why a counter was silent data loss. `keyHex` is the raw 32-byte symmetric AEAD key,
hex-encoded, for `sodium_crypto_aead_xchacha20poly1305_ietf_*` calls via
`OpLogFieldCrypto` / the Sync Public `SensitiveColumnCodec`. It must never be
persisted outside the keyring's own encrypted JSON file, mirroring
`DeviceIdentityDto`'s in-memory-only posture.

`GdkKeyring` is the immutable, append-only in-memory collection of a user's
GDK epochs that `GdkKeyringService` decrypts a keyring file into and
re-encrypts a keyring file from. Append-only by construction: `withEpoch()`
returns a new `GdkKeyring`, never mutating or discarding a prior epoch —
`OpLogRebuilder::rebuild()` replays the entire persisted op-log and must be
able to decrypt every historical epoch, forever.

### GdkEpochControlHandler — receiving a distributed epoch key

Validates, sealed-box-opens, and appends an inbound `GDK_EPOCH_WRAP` control
message to the local device's GDK keyring.

**Placement.** `SyncServiceProvider` forward-registers this class under
`Modules\Sync\Internal\Crypto\GdkEpochControlHandler` via a single-owner
forward-registration block, and that block is never edited to follow a class.
This class therefore lives in the `Crypto` namespace so the existing
forward-registration actually binds it, rather than adding a second, unwired
copy under `Transport`.

**Validation before crypto.** `PeerCatchUpExchanger::parseControlMessage()` is
reused verbatim for the generic "valid JSON object with a string `type`
field" envelope check before this class does any further type-checked field
extraction. No sodium call ever touches attacker-influenced bytes until every
field has been type-checked AND the `recipient_device_id` has been confirmed
to match this device's own identity.

**Security precondition: authenticated sender required.** The wrapped epoch
key travels as an anonymous `sodium_crypto_box_seal` — confidential but
unauthenticated: anyone who knows this device's X25519 public key can craft a
`GDK_EPOCH_WRAP` sealing an attacker-chosen key to it, and (with a
not-yet-present `epoch_id`) drive `GdkKeyringService::appendEpoch()`'s
unconditional `current_epoch` advance so future writes encrypt under the
attacker's key. The seal opening + `recipient_device_id` match do NOT by
themselves establish trust.

That hole is closed in the code: `GdkEpochControlHandler::senderRejection()`
verifies a detached Ed25519 signature over
`GdkEpochWrapSignature::signingMessage()` against the sender's STILL-CONFIRMED
`DeviceRegistryService::deviceKeys()` entry, BEFORE the sealed box is opened or
anything is appended. A forged wrap is refused whatever channel carried it, and
a sender this device does not yet confirm is deferred rather than refused,
because during pairing the wrap can outrun the registry row.

The authenticated channel is still a requirement, not an alternative:
`handle()` is called only with a `$json` envelope that arrived over the Noise
IK session in `SyncWebSocketHandler` (peer static key verified against the
confirmed-only `DeviceRegistryService::deviceX25519Keys()` before any blob is
exchanged), and the relay-mailbox drain in
`SyncWebSocketHandler::deliverGdkEpochWraps()` runs only after that handshake
succeeds. Do NOT wire a new caller that routes an unauthenticated (e.g. raw
relay-pushed) blob into this method.

**False-not-garbage.** `sodium_crypto_box_seal_open()`'s return is checked
with a strict `=== false` — never a truthy/`!$x` check — and a failure
REJECTS the message (log + return, no throw, no append). The recovered raw
key is appended to the local keyring under the LOCAL device's own KEK
(`GdkKeyringService::appendEpoch()` — never a wire-supplied key).

**Idempotency.** An `epoch_id` already present in the local keyring is never
re-appended — this both avoids a duplicate keyring entry and stops
`GdkKeyringService::appendEpoch()`'s unconditional `current_epoch` advance from
moving `current_epoch` back to a superseded epoch when a stale wrap is
redelivered or replayed. Note what carries that guarantee: NOT an ordering over
epoch ids, which are random and unordered, but the fact that a wrap for an
already-held epoch never reaches `appendEpoch()` at all. The only other way a
not-yet-held epoch can arrive out of order is a multi-epoch fan-out, and
`GdkRotationService::fanOutAllEpochsToDevice()` delivers the current epoch LAST
over an insertion-ordered mailbox for exactly that reason. It also covers a normal (non-import) desktop ADD-device
fan-out that self-collides: `PairingPeerErrands::fanOutEpochsToConfirmedPeers()`
fires on every confirmed peer, including a self-minting one that already
holds its own epoch 1 under a different key — this drops that collision with
a distinct warning (device/epoch ids only, never key material) rather than
silently discarding it.

**Graceful degradation when the app is locked.** `GdkKeyringService`'s
KEK-null guard (`\LogicException`) and `DeviceIdentityLoader::load()`'s "no
identity / app locked" `null` are both treated as "cannot process this
delivery right now" — logged and returned, never thrown onward. This mirrors
`OpLogReplayer`'s "headless daemon may have no unlocked session"
degrade-gracefully convention rather than tearing down the whole sync session
over one skippable delivery.

### GdkKeyringService

Generates/loads/appends/re-wraps the per-user GDK epoch keyring — mirrors
`DeviceIdentityService`'s encrypted-key-file idiom: a single JSON blob (the
list of `{epoch_id, key_hex}` pairs) encrypted as a whole via
`BackupEncryptor` under the app-lock KEK, staged through the sanctioned 0700
`sync/gdk` directory (never `sys_get_temp_dir()`), written atomically (stage
plaintext at 0600 -> encrypt to a `.tmp` sibling -> rename over the real
path). Every read/write hard-throws `\LogicException` when the KEK is null.

`stageFirstEpoch()`/`finalizeStagedEpoch()`/`discardStagedEpoch()` exist for
atomicity: the keyring file is encrypted to a `.tmp` sibling without renaming
into place, while `current_epoch` is written on the same `DatabaseManager`
connection as the caller's ambient SQL transaction, so a mid-migration
failure that rolls back `current_epoch` can never leave a finalized epoch-1
keyring file contradicting it on disk. `finalizeStagedEpoch()` never
`@unlink`s the staged file on rename failure, since that staged `.tmp` may be
the only surviving copy of the epoch key once `current_epoch` is committed.

### GdkRotationService — device removal and add-device fan-out

Device removal is trust revocation + forward-only GDK epoch rotation, in ONE
operation. A rotate-only or revoke-only implementation is an access-control
gap.

Order of operations for `rotateAndRevoke()`:

1. Revoke `device_registry` trust for the removed device FIRST (clear
   `confirmed_at`) — closes the Ed25519 gate before any new epoch key is even
   generated, so there is no window where the removed device is both
   still-trusted AND aware of the new epoch.
2. Generate a fresh GDK epoch key (forward-only — `appendEpoch()` never
   discards a prior epoch) and advance `sync_encryption_state.current_epoch`.
3. Wrap the new epoch to every REMAINING confirmed device's X25519 public key
   via `sodium_crypto_box_seal` and enqueue each opaque blob on the ZK-pure
   `RelayMailbox` for offline pickup.

Tolerates encryption not yet being enabled for the user by treating that as a
group-of-one bootstrap (the new epoch becomes epoch 1). Removing the last
remaining peer still rotates locally and enqueues zero wraps.

`buildGdkEpochWrap()`'s `sodium_crypto_box_seal` provides confidentiality but
NO sender authentication — anyone who knows a device's X25519 public key can
craft a wrap sealing an attacker-chosen epoch key to it. This wrap is safe to
trust on receipt ONLY when the delivery channel has independently
authenticated the sender as a CONFIRMED peer — in this codebase, the Noise IK
session in `SyncWebSocketHandler` (peer static key verified against the
confirmed-only `DeviceRegistryService::deviceX25519Keys()` before any blob is
exchanged). `GdkEpochControlHandler::handle()` MUST NOT be invoked for a wrap
that did not arrive over such a channel.

`fanOutAllEpochsToDevice()` is the ADD-device analog: wraps EVERY epoch
already in the keyring to a newly-confirmed device's public key, with no
rotation, revoke, or `appendEpoch()` — purely additive delivery so a desktop
can push its full epoch history to a newly-confirmed phone. Defense-in-depth
re-checks `confirmed_at IS NOT NULL` on the recipient row itself (independent
of the caller's own confirmed-branch gate) and refuses silently (zero wraps,
nothing logged that could leak trust state) for an unconfirmed, self, or
missing recipient.

### OpLogFieldCrypto, RewrapGdkOnPassphraseChange, SensitiveFieldRegistry

`OpLogFieldCrypto` is per-field XChaCha20-Poly1305 IETF AEAD encrypt/decrypt
with the epoch id bound as associated data — the AEAD sibling of
`Modules\Auth\Internal\Lock\AppLockKeyWrap`, sharing the same
base64(nonce||ciphertext) framing and "false not garbage" contract. The
`$associatedData` argument is the epoch-binding channel: callers pass a
canonical string embedding the epoch id, so relabeling the epoch tag on a
stored ciphertext invalidates the Poly1305 authentication tag — defense in
depth alongside the Ed25519 signature that already covers the whole op-log
entry. There are two such strings, and they are not interchangeable: op-log
entries bind their row with `"{table}:{pk}:{field}:{epochId}"`, projection
columns use the pk-less `"{table}:{field}:{epochId}"` built by
`SensitiveColumnCodec::associatedData()`. See
[sensitive columns at rest](sensitive-columns-at-rest.md).

`RewrapGdkOnPassphraseChange` re-wraps the GDK keyring whenever the app-lock
passphrase changes, consuming only `Modules\Auth`'s Public
`AppLockPassphraseChanged` event. `AppLockProvisioner::changePin()` dispatches
this synchronously after the PIN change is already persisted, so a re-wrap
failure here must never make `changePin()` throw — the GDK keyring and the
app-lock PIN wrap are independent, separately-recoverable concerns.

`SensitiveFieldRegistry` is the single source of truth enumerating which
`(table, field)` pairs require GDK-encryption at rest:
`transactions.{description,counterparty_name,counterparty_iban,raw_payload,note}`,
`counterparties.{display_name,merchant_name,iban}`, `tax_transaction_tags.note`,
`transaction_splits.note`, `notifications.{title,body,params,trigger_type}`.

Deliberately excluded: `transactions.amount_minor`, `settled_amount_minor`,
`fx_rate_used` — at least eleven query classes perform SQL-side SUM()/GROUP BY
over these columns, and SQLite cannot aggregate ciphertext. Deferred:
`counterparties.metadata`, `saved_reports.definition`.

Knowingly-accepted plaintext exceptions, each a deliberate decision rather
than an oversight:

- `migration_import_baseline.baseline_value` snapshots a plaintext value for
  `ThreeWayMergeResolver`'s three-way merge compare.
- `pending_enrichment_conflicts.{stored_value,incoming_value}` hold decrypted
  values of a held receipt-enrichment conflict until the user resolves the
  toast, so the toast never renders ciphertext.

### Sensitive column codec and encrypted-cast boundary

`Public\Services\SensitiveColumnCodec` is the projection-column codec used at
read/write hooks for sensitive fields: `associatedData()` is public and
static so other call sites (e.g. a raw SQL migration pass) can reproduce the
exact same AD independently without instantiating the full codec.
`encryptValue()`/`encryptAttrs()` pass through unchanged when encryption is
not currently usable (not enabled for this user, or the app-lock is locked).
`decryptValue()`/`decryptRow()` try EVERY epoch in the keyring (rotation-safe)
and return the raw stored value with `decrypted: false` — never throw — when
no epoch verifies (tampering, corruption, or a legacy plaintext value).

`Public\Casts\EncryptedJsonCast` is an Eloquent cast for
`transactions.raw_payload`: `set()` JSON-encodes and GDK-encrypts before
persisting so a `Model::save()` write path can never silently store plaintext
at rest (pass-through no-op for non-encryption users; `RecordTransactions`
bypasses this cast via a raw insert, so it never double-encrypts that path).
`get()` decrypts then `json_decode`s; a non-array decode of a non-empty,
non-decrypted value is treated as a genuine integrity failure
(tampered/corrupt/wrong-key ciphertext, not an empty field) and is logged via
a warning rather than silently returning null with no signal — still never
leaking ciphertext to the caller.

### Enable-time encryption migration support (`Public\Services\EncryptionMigrationSupport`)

Sync Public surface consumed by `Modules\Core\Public\Services\
EncryptionMigrationService`. The enable-time backup-first migration needs two
Internal Sync crypto capabilities no other Public class exposes:

1. First-epoch generation (`GdkKeyringService::stageFirstEpoch()` /
   `finalizeStagedEpoch()` — split in two, see the atomicity note below).
2. `op_log_entries.value` encryption under the exact per-entry AD
   `Modules\Sync\Internal\OpLog\OpLogWriter` itself binds
   (`"{table}:{pk}:{field}:{epochId}"`) — deliberately different from
   `SensitiveColumnCodec`'s pk-less projection AD, so it cannot be added to
   that class without conflating the two AD shapes it exists to keep distinct.

The project's custom PHPStan cross-module rule (`beatrax.boundary`, see
`app/PhpStan/Rules/BoundaryRule.php`) forbids Core from importing
`Modules\Sync\Internal\*` directly — this class is the minimal Public wrapper
that closes that gap while keeping every raw GDK key byte and the `GdkEpoch`
DTO itself fully inside the Sync module boundary. Callers across the boundary
only ever see plain integers (epoch ids) and ciphertext strings.

Not a singleton (bound via `bind()`, mirrors `HybridLogicalClock`/
`SyncSession`'s "holds mutable state -> transient" precedent): an instance
caches the primed epoch's raw key material for the duration of one migration
pass so the encrypt*() methods never re-derive the KEK or re-decrypt the
keyring file per row.

**Staged epoch generation atomicity.** `stageFirstEpoch()` encrypts the
epoch-1 keyring file to a `.tmp` sibling and writes
`sync_encryption_state.current_epoch` (participating in the caller's ambient
SQL transaction) but does NOT rename the file into place. The caller must
call `finalizeStagedEpoch()` after that transaction commits (or
`discardStagedEpoch()` if it rolls back) — this closes a file-vs-DB atomicity
window a prior version of this class had, where the keyring file was
finalized (renamed into place) inside the transaction, so a later chunk
throw would roll back `current_epoch` while the epoch-1 file persisted on
disk.

`hasUsableCurrentEpoch()` exists because a recorded `current_epoch` does not
prove the keyring file was finalized: a crash in the commit-then-finalize
window can strand the key as a lingering `.tmp`. `EncryptionMigrationService::
migrate()` uses this to distinguish "genuinely enabled" from "recorded-enabled
but stranded" before reporting success.

### GDK delivery/reprojection gateways

`Public\Services\GdkEpochDeliveryGateway` is the thin Public entry point for
an inbound `GDK_EPOCH_WRAP` control message: it never throws on a
malformed/foreign/tampered blob (the underlying handler logs and returns).
Security precondition: `$json` MUST have arrived over a channel that has
already authenticated the sender as a CONFIRMED peer — never wire this to an
unauthenticated source (see `GdkEpochControlHandler` above for the full
authenticated-channel requirement).

`Public\Services\HistoryReprojector` re-projects the entries a device could not
open when they arrived, against the CURRENT (possibly newly-populated) GDK
keyring. `replayQuarantined()` is the whole of that: the rows the quarantine
names, widened to every op of each row, through the same replayer the live drain
uses. Idempotent, and both callers still gate it behind their own cursor.

It used to also carry `reproject()`, a straight `OpLogRebuilder::rebuild()` over
the whole persisted log. That was the mobile setup gate's re-projection, and it
was measured fatal on a phone at 200,000 entries — see
[the initial-sync gate](../mobile/mobile-initial-sync-gate.md#four-measured-costs-on-the-sync-path).

## Op-log and CRDT replication

### Op-log replayer (`Internal\Merge\OpLogReplayer`)

Production LWW/CRDT op-log replayer with quarantine-never-throw,
config-driven strategy dispatch via `MergeRulesRegistry`, and full security
guards:

- **User-id filter before apply:** entries whose `userId !== $userId` are
  quarantined (`cross_user`) before any DB write.
- **`WHERE user_id = $userId` on every DB write:** even if the filter above
  were bypassed, no cross-user row would be touched.
- **Ed25519 gate:** entries with no device key or a failing signature are
  quarantined (`missing_device_key` / `forged_signature`). "No device key" is
  asked of the confirmed map FIRST and then of
  `DeviceRegistryService::retainedDeviceKeys()`, which also holds the key of a
  device the user has REMOVED. Removal clears `confirmed_at`, which shuts the
  Noise transport to that device, so retention admits nothing; it only keeps the
  history that device already wrote verifiable. An entry that is byte-identical
  to one `op_log_entries` already holds is accepted even when no key at all
  remains — a rebuild deletes every row a CreateRow op created before replaying
  them back, so refusing them there destroyed the data outright.
- **Table allow-list gate:** only tables registered in `MergeRulesRegistry`
  may be written via op-log replay, closing a full trust-store takeover via
  an arbitrary wire-supplied table name.

Rejected ops (`cross_user`, `missing_device_key`, `forged_signature`,
`unknown_table`, `strategy_error`, `incomplete_create_row`,
`missing_reference`, `gdk_decrypt_failed`) write a structured row to
`op_log_quarantine` and replay continues — deterministic, exceptions never
propagate. A CREATE_ROW is written with a plain `insert()`, never
`insertOrIgnore`: OR IGNORE silences a NOT NULL violation as well as a
duplicate, so a create whose ops straddled a frame boundary wrote no row,
raised no quarantine, logged nothing and returned success.
`CreateRowInsertFailure` classifies the refusal — a duplicate is the idempotent
re-apply and stays silent, a NOT NULL violation is `incomplete_create_row`, a
foreign key is `missing_reference`. Verified
entries are persisted to `op_log_entries` (upsert-by-identity) so the log is
durable, ciphertext value unchanged even when GDK-tagged sensitive fields
are decrypted separately for strategy resolution.

`decodeValue()` is unconditional `json_decode()` with no raw-string
fallback: SQL NULL is the tombstone/clear sentinel, and the JSON string
`"null"` decodes to the PHP string `"null"`, never PHP null. It is public so
`AlwaysJsonWireContractTest` can verify this contract directly.

After the merge transaction commits, `SearchIndexWriterContract::
upsertForTransaction()`/`deleteForTransaction()` refresh FTS5 for every
touched/tombstoned `transactions` row, OUTSIDE the merge transaction (FTS5
cannot participate in a SQLite transaction that also writes to base
tables) — an FTS hiccup never breaks merge determinism.

A pair-link cascade reclassifies an orphaned transfer partner after its
sibling is tombstoned (`ON DELETE SET NULL` on `pair_transaction_id`),
persisting a deterministically re-derived compensating op under
`OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID`, which the signature gate
allow-lists since these ops are produced only by the replayer itself and
reproduced identically on rebuild.

Its constructor is fed the confirmed-only device-key map via
`SyncServiceProvider`'s binding, which resolves `DeviceRegistryService::
deviceKeys()` at bind time keyed to the current authenticated user — the
production wiring for the empty map tests use when constructing
`OpLogReplayer` directly with their own throwaway map.

### CRDT merge strategies (`Internal\Merge\Strategies\*`)

`LwwPerFieldStrategy` (default for standard mutable fields) selects the
entry with the highest HLC and decodes its value; a PHP null value is the
explicit clear/tombstone sentinel, writing SQL NULL.

`GCounterStrategy` (grow-only counter, e.g. `merchant_memories.occurrence_count`)
computes `result = SUM of MAX(value) per device_id` — each device stores its
running total as the op value, not a delta, so re-replaying the same ops
yields the same sum. A non-int decoded value throws rather than silently
coercing to 0, which would lower a device's contribution with no signal.

`OrSetStrategy` (Observed-Remove Set, e.g. `merchant_aliases.merged_from`)
collects all added `{v, tag}` pairs and removed tags across entries, then
returns elements whose tag is in the add-set but NOT in the remove-set — an
element removed on any device is excluded as long as the remove op
references the element's original add tag.

Its one producer is `Import\Public\Actions\MergeMerchantAliases`, and the
column and the wire carry DIFFERENT shapes on purpose: the column holds the
resolved element list `[{v, tag}, …]` (which is what a peer's replay projects
into it), while the op value is the OR-Set delta envelope
`{added: [{v, tag}, …], removed: []}`. A plain list on the wire throws
`Malformed OR-Set value` and quarantines the whole merge. `added` carries the
WHOLE set rather than only the newly absorbed elements, because the replayer
resolves a field from the ops in one batch alone — a delta op arriving by itself
would project a set missing every earlier merge. Tags are derived from the
element's own content, so the union stays idempotent and two devices merging the
same aliases mint the same element.

### Op-log entry, type, rebuilder, writer

`Internal\OpLog\OpLogEntry` is the immutable, signed field-level change DTO;
`$value` is `?string` (JSON-serialised) so the class passes PHPStan strict
mode, and `$gdkEpoch` tags a GDK-encrypted value with the decrypting epoch —
deliberately excluded from `signingPayload()` since epoch authenticity is
carried via the AEAD associated data, a separate channel from the Ed25519
signature. `OpType` enumerates `Set`/`DeleteTombstone`/`CreateRow`; never
pass a free-form `op_type` string to the replayer — add a case here first.

`OpLogWriter` persists entries to `op_log_entries`: always-JSON-encodes the
raw PHP value, signs each entry via `DeviceKeySigner`, ticks the HLC and
persists the updated clock state in the same DB transaction, and restores
the HLC high-water mark from `hlc_clock_state` on construction so a restart
or wall-clock rewind cannot lower the logical clock. The RECEIVE half lives in
`Internal\Clock\RemoteClockAdvance`, which `OpLogReplayer::replay()` calls with
every verified entry: it runs `HybridLogicalClock::receive()` against the
highest remote HLC in the batch and persists the result to `hlc_clock_state`.
`OpLogWriter` is bound transient, so the next write this device makes starts
from what it has just heard. Without it `receive()` was fed only this device's
own persisted state and was dead as a causality mechanism — a peer an hour ahead
won every later merge here, permanently. Before a sensitive
field's value reaches `op_log_entries` (and therefore the wire — this is the
"doubles as transport encryption" boundary), it is encrypted under the
CURRENT GDK epoch and tagged with `gdk_epoch`; when GDK encryption is not
yet enabled or the app-lock is locked, the write falls back to plaintext
with a null `gdk_epoch`.

`OpLogRebuilder` is the trigger-safe deterministic full-rebuild path
(device onboarding / disaster recovery): drop covered-table triggers,
delete only rows the op-log can recreate (rows with a `create_row` op —
anything predating capture has none, so deleting everything would leave it
unrecoverable), replay the full persisted
op-log via the same production `OpLogReplayer`, then restore triggers — all
inside one DB transaction so any failure rolls everything back. Deletion
runs in FK-safe order (children before parents). An in-process maintenance
lock (per userId) guards against concurrent rebuilds; this is sufficient
for single-user/single-writer SQLite.

`Internal\OpLog\OpLogReplayer` is an earlier, simpler LWW-only replayer
preceding the production `Internal\Merge\OpLogReplayer` (which adds
config-driven strategy dispatch, quarantine, and GDK decrypt-before-strategy).

`OpLogBackfiller` and `PreSyncHistoryCapture` write the rows that predate sync
itself into the log, in slices that commit as they go and resume across
requests: [Capturing the history that predates
sync](pre-sync-history-capture.md).

### When two devices name one row (`Internal\Merge\AlreadyPresentCreate`)

A `create_row` whose insert the database refuses as already present is one of
three different things, and `AlreadyPresentCreate` tells them apart — leaning on
`PeerRowAliases` for the first and `CreateRowCollision` for the third.

**The row is here under another id.** Each device seeds its own reference data,
so a peer's tax category arrives as id 109 and matches local row 13 on
`(user_id, name)`. `PeerRowAliases::remember()` reads every non-primary unique
index off the schema, finds the local twin, and records the pair in
`op_log_row_aliases`. `translate()` then rewrites every foreign key naming 109
to 13 on the way in, and `resolvePk()` routes the peer's later `set` ops to the
row this device actually holds. Without the alias, nineteen tax tags naming 109
failed their foreign key.

**The row is here and this is the same op again.** The ordinary idempotent
replay. Nothing to do.

**The row is here but it is a different row.** Nine covered tables mint an
autoincrement primary key and declare no natural unique key, so two devices
writing while apart both take the next id and hand it to unrelated rows. The
insert is refused by the primary key, there is no natural twin to alias, and
for a long time the arriving row was simply dropped — two devices then held
different money at one id with an empty quarantine on both.

`CreateRowCollision::contradicts()` separates the second case from the third.
It calls it a collision when the op's `created_at` disagrees with the stored
row's **and** at least one other column disagrees as well. Both halves are
needed, and each was chosen against a real op log of 320 create groups:

- Comparing the whole payload flags 32 rows that are the same row — a create
  replayed after its row was edited is stale in the edited column by design.
- Comparing only `created_at` misses collisions where the stored row's is
  null, which is how rows written by peers on older builds landed.
- The conjunction flagged 4 groups, and all four were real collisions.

Sensitive columns are excluded from the comparison: the payload is re-sealed
for this device before the insert, so a fresh nonce makes the ciphertext differ
from the stored value on every replay. `id` and `user_id` are seeded by the
applier from the op envelope rather than the wire, and `updated_at` moves
whenever anything about the row does.

A collision is recorded in `op_log_quarantine` with reason
`primary_key_collision` and the create is not applied. It is deliberately not
in `QuarantineReason::recoverable()`: the id is taken by another row, and no
op arriving later frees it.

### A split leg that would overfill its transaction (`Internal\Merge\SplitOverfillGate`)

`SaveTransactionSplit` requires a transaction's legs to add up to it **exactly**.
The applier had no such rule, and that only became visible once every leg
carried a `split_uuid` of its own: before, two devices that split the same
transaction while apart minted leg ids from their own autoincrements, so whether
the two sets collided at all depended on how far each sequence had run. With an
identity per leg they stop colliding, and **both sets land** — a desktop split of
50/30 and a phone split of 40/40 left one 80,00 charge showing four legs adding
to 160,00, with an empty quarantine on both devices.

The gate refuses a create whose leg would carry the transaction's legs past the
transaction, and records it as `split_would_overfill_transaction`. Two things it
must not do, each with a test of its own:

- **Refuse a peer's split when this device has not split the transaction.** The
  legs then fit, and they apply.
- **Refuse a leg it has already applied.** The row's own id is excluded from
  what is already there, so the idempotent re-apply stays idempotent.

This **reports** the conflict rather than resolving it. Two devices that both
split one transaction still disagree: each keeps its own legs and the peer's
arrive quarantined with a reason. Resolving it needs a rule about the *set* —
one save's legs are one value, and the later save replaces the earlier entirely
— which is a separate decision.

### A row that arrives without a birth time (`Internal\Merge\SuppliedCreationTime`)

`buildCreatePayload()` writes only the columns the create names, so a row from a
build whose capture did not name `created_at` landed with none — and the device
that later re-captures that row sends the null on, so it spreads.

A null timestamp is not "unknown", it is "sorts wrong forever".
`EnvelopeBalanceQuery::recentMovesFor()` orders `created_at DESC` with a limit of
ten, and SQLite sorts nulls **last** under `DESC`: a move made today sat below
one made a week earlier, and past ten moves in a category it left the page
entirely while the money it moved still counted. `PotRowLoader` orders the same
way.

`SuppliedCreationTime` seeds `created_at` and `updated_at` from the create's own
earliest HLC, whose high half is a wall clock in milliseconds. That is not the
birth time the writing device knew — it is the earliest moment this device can
*prove* the row existed, which is honest and, unlike a null, orderable. A create
that carries its own timestamp keeps it.

The rows already stored are repaired by
`2026_09_03_000001_give_a_synced_row_back_its_birth_time`, which reads
`min(hlc_l)` of each undated row's create ops.

### Which id a covered table gives a new row

A table whose only identity is an autoincrement cannot tell one device's row
from another's, and the section above is what happens when that goes wrong.
Three answers make a table safe, and every covered table needs one:

| Answer | When it is right |
| --- | --- |
| A **natural unique index** | The row has a key the schema already declares, so `PeerRowAliases` finds the local twin and reconciles the two ids. |
| A **derived id** (`DerivedRowId::for()`) | Every device computes the same identity for the row, so both land on one id without exchanging a message. |
| A **minted id** (`DeviceMintedRowId::mint()`) | No two devices could compute the same identity, so a random 63-bit value keeps them apart. |

The test between the last two is not whether the row has columns that identify
it, but **whether those columns are the same on every device**. A foreign key
usually is not: a parent reconciled through `op_log_row_aliases` is held under a
*different* id on each side, so an id derived from that key differs per device
and derives nothing. `forecast_scenario_mutations` and
`migration_import_baseline` are minted for exactly that reason, while
`anomaly_suppression_rules` is derived because the alert it hangs off already
has a derived id.

Deriving where a row is an **event** is the worse mistake, not the safer one: two
deposits of the same amount into one pot on one day are two deposits, and an id
folded from their content gives them one row — losing money instead of
duplicating it. `pot_movements`, `goals` and `saved_reports` are minted for that
reason.

`ACoveredTableTellsTwoDevicesRowsApartTest` enforces this: a covered table with
an autoincrement, no other unique index and no id scheme fails it. Two groups
are named there rather than quietly passing — the three rule tables, which never
travel in either direction, and `transaction_splits`, which needs a decision
about the *set* of legs rather than one row's id.

Both id kinds run past 2<sup>53</sup>, so these ids reach the browser **quoted**
and come back through `DerivedRowId::fromWire()`.
`ADerivedIdNeverReachesTheBrowserAsANumberArchTest` covers both, and is
deliberately coarse twice over. It flags every bare id in **every** module that
ships blades, small autoincrements included: a blade cannot tell the two apart
by eye, and a minted id is a value that travels — a goal id is rendered in a
Ledger blade, and Tax and DevMode mint nothing at all yet write these ids into
wire attributes. And it reads two shapes, because there are two ways an id
reaches a wire attribute: echoed straight into a `wire:`, `x-on:` or `@`
attribute, and concatenated into the call string a blade hands to a mounted
component that renders it into a `wire:click` of its own. Every
`x-core::confirm-strip` works the second way, which is how Goals, Pots and
Reports came to quote the button that ASKS the question and leave the button
that ANSWERS it bare.

What it counts as an id is the name the expression ends on, and three ways of
writing the same value used to slip past that: `$txId` (the anchor admitted no
`$`), `$m['id']` (the anchor had to end on the letters, and a subscript closes
on `']`) and `(int) $accountId` (a cast in front, which only `$entry->id` ever
survived because `->` gave it a second way in). A ternary between two ids would
still slip; nothing writes one.

### Capture listener (`Internal\Listeners\SyncCaptureListener`)

Routes each module's `*Mutated` events to the `OpLogWriter`. Wired in
`SyncServiceProvider::boot()` via a `class_exists()`-guarded
`events->listen()` call.

**Emit-after-commit contract:** `OpLogWriter::writeEntry()` opens its OWN DB
transaction (op insert + HLC clock-state upsert). Emit sites MUST dispatch a
mutation event only AFTER the originating write transaction has COMMITTED —
never from inside an open transaction. If a mutation event were dispatched
mid-transaction, the writer's transaction would degrade into a savepoint of
the outer one: an outer rollback would then discard the op insert while the
in-memory HLC tick had already advanced, breaking the op's
atomicity-vs-outer-rollback guarantee.

**Never-throw contract:** the entire handler body is wrapped in
`try/catch(\Throwable)`. A capture failure is logged but NEVER propagated —
a broken op-log write must never abort or roll back the user's originating
save action. The user's data is always written first; the op-log is a
secondary concern.

**Lazy `OpLogWriter` resolution:** `OpLogWriter` requires runtime device
credentials (deviceId, userId, secretKey, publicKey) that are only available
once the device identity is configured. The writer is resolved from the
container lazily inside each handler, so if the container cannot resolve it
(no device creds bound yet, or in test contexts that don't set up Sync), the
`BindingResolutionException` is caught and the listener returns normally.

**Routing by `mutationType`:** `'edit'` -> one `OpLogWriter::writeSet()` per
dirty field; `'delete'` -> `OpLogWriter::writeDelete()`; `'create'` ->
`OpLogWriter::writeCreateRow()`; unknown -> logged and ignored.

### Public mutation events (`Public\Events\*Mutated`)

`TransactionMutated`, `TransactionSplitMutated`, `EnvelopeAssignmentMutated`,
`EnvelopeMoveMutated`, `EnvelopeSettingMutated`, `SavedReportMutated`, and
`NotificationMutated` are dispatched for every user-driven mutation to their
respective table. `SyncCaptureListener`'s handlers hard-code the target
table name per event type, so each table gets its own dedicated event class
rather than a generic one — this avoids silently mis-capturing (e.g.) an
envelope-assignment mutation as a `transactions` op-log row. All are
synchronous dispatch (do NOT implement `ShouldQueue`).

Each event carries `mutationType` (`'create'|'edit'|'delete'`, or
`'create'|'edit'` only for notifications, which have no delete path yet) and
`dirtyFields` (changed field => new value, empty for deletes).

Primary-key stability varies by event and matters for LWW convergence:

- `TransactionSplitMutated.splitId` is the leg's STABLE
  `transaction_splits.id` — the SAME id across an edit, since
  `SaveTransactionSplit`'s PK-preserving diff never regenerates a surviving
  leg's id.
- `EnvelopeAssignmentMutated.assignmentId` /
  `EnvelopeSettingMutated.settingId` / `SavedReportMutated.reportId` are
  LOCAL autoincrement surrogates, stable across the common
  create-once-then-edit lifecycle on the origin device. Known convergence
  limitation: two devices independently
  creating the FIRST row for the same natural key while offline mint
  DISTINCT local pks, which collide on the natural-key UNIQUE constraint at
  replay — resolved by the replayer's natural-key upsert/quarantine, not by
  any pk-stability property of the event.
- `NotificationMutated.notificationId` is a deterministic sha256 hex digest
  computed from `(user_id, trigger_type, subject_key, occurrence)` — two
  devices independently deriving the same tuple compute the SAME pk and
  converge on one row with zero coordination, which is why it needs no
  natural-key upsert/quarantine special-case. Only `read_at`/`dismissed_at`
  are ever synced fields; `state` is deliberately excluded from
  `MergeRulesRegistry`'s `notifications` block.
- `EnvelopeMoveMutated.moveId`: a move writes a paired debit/credit row in
  one DB transaction; each row dispatches its own event with
  `mutationType: 'create'` so both rows are individually captured. Undo
  hard-deletes both paired rows and dispatches `'delete'` for EACH row's own
  pk (a tombstone, not a reversing pair) — `moveId` is the stable pk of ONE
  row in the pair, never the pair as a whole.

## Device identity and pairing

### Device identity (`Internal\Identity\*`)

On enable-sync, `DeviceIdentityService` generates a long-term Ed25519 signing
keypair and a SEPARATE X25519 key-agreement keypair (never derived from the
signing key — key reuse across primitives is a crypto anti-pattern), writes
them to a key-file encrypted with `BackupEncryptor` (Argon2id +
XChaCha20-Poly1305) under the app-lock KEK, and inserts the self-row into
`device_registry` (public keys only — no secret key ever reaches the DB).
Generation hard-throws when the KEK is null, service-level defense-in-depth
alongside the UI gate.

`DeviceIdentityDto` is the in-memory shape of the decrypted key-file,
round-tripping the on-disk JSON shape (`v`, `device_id`, `user_id`,
`ed25519_secret_key_hex`, `ed25519_public_key_hex`, `x25519_secret_key_hex`,
`x25519_public_key_hex`, `created_at`). `DeviceIdentityLoader` decrypts and
loads it, returning null when sync was never enabled or the app is locked.

`DeviceNameDetector` produces a neutral OS-family device name (a bare `Mac`,
`PC` or `Linux`) — never `php_uname('n')` — since the default is stored in
`device_registry.name` and exchanged with paired peers before the user has a
chance to rename it.

`SecureTempFile` is the shared "stage plaintext secret material at 0600"
helper used at the device identity and GDK keyring encrypt/decrypt
boundaries, since `BackupEncryptor`'s file-path API has no permission
handling of its own. A silently-swallowed chmod failure would leave secret
key material world-readable with no signal, so every method here throws (and
removes the file) rather than continuing past a chmod failure.

### Device registry (`Public\Services\DeviceRegistryService`)

Public façade over the trusted-device registry (`device_registry` table).
This is the ONLY input `OpLogReplayer` accepts for its `$deviceKeys` map —
the `deviceKeys()`/`deviceX25519Keys()` queries MUST filter `confirmed_at IS
NOT NULL`: an unconfirmed device key reaching the replayer is a forged-op
vector, since the device was never safety-number verified. Every query is
also scoped to `user_id` so no cross-user key material leaks.

`localDeviceId()` and `otherDeviceNames()` are the sanctioned crossing points
for `Modules\Notifications`, which may not reach
`Modules\Sync\Internal\Identity` directly — both expose only public,
non-secret projections of the registry.

**Stamps are Zulu, and the column is sorted as text.** `paired_at`,
`confirmed_at`, `created_at`, `updated_at` and `last_seen_at` are TEXT, and
`confirmedDevices()` orders by `paired_at` — so SQLite compares them
byte-wise, not as instants. A stamp written at a local offset reads by its own
hour digits: `2026-06-15T20:30:00+02:00` sorts *after* the `2026-06-15T19:00:00Z`
row it actually predates, and the device list silently reorders. Every writer
therefore goes through `Instant::zulu()`, the same producer
`pairing_tokens.expires_at` and `relay_mailbox` use, and
`2026_08_22_000002_rewrite_sync_stamps_as_zulu` rewrote the rows an earlier
version had already stored at an offset. Rewritten, not deleted as the
`pairing_tokens` sweep beside it does: a registry row is the permanent trust
anchor, and dropping one un-pairs both devices and costs a fresh
safety-number ceremony. `restoreSelfRow()` re-stamps the `created_at` it reads
back out of the device key-file for the same reason — a key-file minted before
this change still carries the offset form, and switching sync off and on is
what copies it back onto a migrated row.

`tests/Contracts/TextTimestampsAreZuluArchTest` holds the rule open for the
next table: it derives the columns from the migrated schema rather than a
list, so any table that stores a `*_at` as TEXT is covered the moment its
migration lands.

### Pairing internals (`Internal\Pairing\*`)

`Bip39WordList` bundles the canonical BIP39 English wordlist (2048 entries)
as a class constant, not a Composer dependency, since it is small, stable,
and security-relevant — `SafetyNumberDeriver` maps a SHA-256 digest through
`index % 2048` into these words.

`SafetyNumberDeriver` derives a word-based safety-number from two public
keys: both peers compute the SAME 6-word number because the two keys are
binary-sorted before hashing, making the derivation order-independent and
deterministic. 6 words x 11 bits ~= 66 bits of safety-number space.

`WordCodeEncoder` is the first-class typed fallback to the QR: encodes the
pairing token as a human-typeable base-32 word-code (RFC 4648 alphabet,
omitting ambiguous 0/O/1/I glyphs), chunked `XXXX-XXXX-XXXX-XXXX`.

`QrPayloadBuilder` builds a `beatrax://pair` URI carrying the issuing
device's public identity + a single-use token, rendered as an inline SVG QR
(server-side SSR, no JS QR library). Optionally appends `&relay=<endpoint>`
and `&rtok=<token>` so a fresh phone can auto-configure its own transport
before the cross-device handshake needs one.

`PairingOfferService` answers the one question a typed word-code leaves
open. The QR carries the initiator's device id and both public keys; the
word-code carries the 16-byte token and nothing else, so a fresh responder
has no local `pairing_tokens` row for `acceptToken()` to bind against. Given
the token, this looks up the row by `sha256(token)` — the table stores the
hash, never the token — requires it to be `pending`/`awaiting_confirm` and
unexpired, and returns the initiator's `device_id`, `ed25519`, `x25519` and
display `name`. Nothing else. In particular **no relay endpoint, token or
pin**: the QR may bootstrap a relay because a camera is an out-of-band
channel the network cannot touch, whereas this answer travels the very
network an attacker would be sitting on. Every refusal — unknown token,
expired token, another user's token, a ceremony already finished — is the
same bare 404, so probing the endpoint teaches nothing.

`PairingOfferRateLimiter` is the per-source-IP fixed-window throttle in
front of it (10 per minute). The token is 128 bits of `random_bytes(16)`, so
brute force was never the concern; the limit is defence in depth and stops
the endpoint being a cheap probe for whether a pairing is in flight.

`LanPairingOfferFetcher` is the responder half: it decodes the typed
word-code, browses `_beatrax-sync._tcp` via the container's `PeerDiscovery`
(`CachedPeerDiscovery` in front of `MulticastMdnsQuery`), and asks
each discovered peer for its offer until one holds the token. Discovery
authenticates nothing and neither does the fetch — an mDNS answer can be
spoofed by anyone on the network, so what comes back is a candidate
identity, and the mandatory both-sides safety-number comparison remains the
sole gate establishing who the peer is, exactly as on the QR path. The
fetched identity is fed to `PairingGateway::seedResponderToken()` and the
flow then continues through the unchanged `acceptToken()` ceremony. Neither
the token nor the offer body is ever logged.

Both typed-code surfaces drive it — `MobilePairingScan` and
`PairingFlowModal` alike. The lookup belongs to the entry point rather than
to a platform: a device that RECEIVES a code holds a token and no keys
whichever kind of device it is, and the desktop arm skipping it made every
code issued on another machine read as expired
([the failure](pairing-handshake.md#both-clients-have-to-ask)). It has no
counterpart on the showing side of a phone, because nothing answers an offer
request a phone would have to serve
([why](pairing-handshake.md#a-phone-can-only-be-scanned)).

`PairingFrame` defines the two cross-device pairing handshake frame types
`PairingFrameCourier` carries between devices:
`PAIR_RESPONDER_ACCEPT` (public identity fields only) and `PAIR_CONFIRM`
(a device id pair + an Ed25519 signature, never the secret key). Neither
frame type carries any trust decision — every trust gate lives exclusively
inside `PairingTokenService`.

`PairingState` names the lifecycle a token moves through — `pending ->
awaiting_confirm -> confirmed`, falling to `expired` at any point its TTL
elapses — and `PairingTokenService` owns every transition between those
states. `PairingStateMachine` holds the one predicate the CONFIRMED
transition turns on: the trust gate is reachable only once BOTH the
initiator and responder have confirmed the safety-number on their own
screens.

`PairingTokenService` issues and validates single-use, short-expiry pairing
tokens and owns every trust decision (see the class for the full lifecycle,
the cross-device `seedFromInitiator()`/`applyResponderAccept()`/
`applyPeerConfirm()` counterparts, and the both-confirm admission gate).

`PairingFrameCourier` carries `PairingFrame`s to the peer so the both-confirm
ceremony can reach a genuinely separate physical device. It tries three roads
in order: the LAN through `LanPairingFrameCourier`, then the zero-knowledge
relay, then `PairingPeerOutbox`, which holds the frame for the peer to collect;
it throws only when all three are shut. It NEVER decides trust; it dispatches
drained frames verbatim to `PairingTokenService`'s cross-device apply methods.
The relay itself sees only routing metadata + opaque blobs.

### Cross-module pairing gateway (`Public\Services\PairingGateway`)

Narrow Public seam letting a non-Sync module's own pairing-entry surface
drive the same `PairingTokenService::accept()`/`confirm()` trust boundary
`Internal\Http\Livewire\PairingFlowModal` already uses, without reaching
into `Modules\Sync\Internal\*` directly (the project's custom PHPStan
`beatrax.boundary` rule has no per-file ignore mechanism).

Added so `Modules\Mobile\Internal\Pairing\QrScanBridge` and
`Modules\Mobile\Internal\Http\Livewire\MobilePairingScan` can reuse the exact
decode-then-accept-then-derive-safety-words shape `PairingFlowModal::
submitCode()` implements, plus its both-confirm gate. Every method on this
gateway is a thin pass-through or re-composition of the existing Internal
collaborators (`PairingTokenService`, `WordCodeEncoder`, `DeviceIdentityLoader`,
`SafetyNumberDeriver`). No new trust decision is introduced anywhere in this
class: `PairingTokenService::accept()`/`confirm()` remain the sole points
where a device is admitted to `device_registry` (both-confirm gate).

`enableSyncIdentityWithoutEpoch()` enables sync identity WITHOUT minting a
GDK epoch — identity only, for the mobile "import from another device"
fresh-device bootstrap. The import path defers epoch acquisition entirely to
the desktop's delivered epochs (`GdkEpochControlHandler::handle()` over the
authenticated LAN session): calling `EncryptionMigrationService::migrate()`
here (or anywhere on the import path before pairing confirms) would self-mint
a colliding local epoch 1 and permanently strand every desktop epoch-1 entry
in `gdk_decrypt_failed` quarantine (the control handler's idempotency guard
silently drops an already-present epoch id). This method must not be
extended to touch the GDK keyring or `EncryptionMigrationService`.

`deliverAllEpochsToDevice()` fans out every epoch in the GDK keyring, sealed
to the new device's confirmed X25519 public key, over the zero-knowledge
relay mailbox — the add-device analog of the existing device-removal
fan-out. Two trust properties matter here:

- **Channel authentication precondition:** reuses the same
  `sodium_crypto_box_seal` delivery primitive the epoch-wrap builder already
  uses — confidential but unauthenticated on its own. Safe only because the
  recipient must already be a CONFIRMED `device_registry` row (re-checked
  inside the fan-out), and confirmation is gated behind the both-screen
  safety-number ceremony, an out-of-band-verified trust anchor independent
  of this wrap's own confidentiality property. The ceremony authenticates the
  Ed25519 IDENTITY key; the recipient's X25519 SEALING key — the key this
  seal is actually encrypted to — arrives UNSIGNED in the relayed accept
  frame, so it is tied back to that identity by binding both devices' X25519
  keys into the Ed25519-signed `PAIR_CONFIRM` message
  (`PairingFrame::confirmSigningMessage`). Without that binding a malicious
  relay could keep the responder's Ed25519 (so the safety words still match on
  both screens) while swapping its X25519, and receive every epoch sealed to a
  key it controls; the binding makes such a swap fail the confirm signature
  before the row is ever admitted.
- **Trust-gate ordering:** callers must reach this method only from the
  `state === CONFIRMED` branch of the both-confirm transition — never
  speculatively, never on a pending/awaiting/expired/rejected token. The
  fan-out independently re-verifies `confirmed_at` on the recipient row
  (defense-in-depth) and refuses otherwise.

`configureRelayFromQr()`'s untrusted-endpoint handling: `$endpoint` arrives
from an untrusted scanned QR. It never persists an insecure (`http://`)
endpoint (`RelayClient` later refuses that scheme, which would durably brick
all relay-backed sync), and only bootstraps a FRESH device — never clobbers
an existing working relay, since a crafted/wrong QR must not redirect a
device's relay. It only overwrites the auth token when the QR actually
carries one, so a token-less relay endpoint cannot wipe an existing token.

`acceptToken()`'s QR path: the QR's `token` query parameter
(`QrPayloadBuilder::buildUri()`) is the same raw hex `WordCodeEncoder::
decode()` returns for a typed word-code — no base32 round-trip applies here,
since the QR carries the token directly, whereas the word-code base32-encodes
it purely so a human can type it.

## Transport

### Transport framing and cryptography (`Internal\Transport\Frame\TransportFramer`, `Internal\Signing\DeviceKeySigner`, `Internal\Transport\Noise\*`)

`TransportFramer` is a length-prefixed binary codec for `OpLogEntry` batches:
`[4 bytes uint32 LE payload length][JSON array of op-log entries]`. Maximum
frame payload 65,536 bytes; maximum 1,024 ops per frame; oversized frames are
rejected on both encode and decode. The signature field round-trips exactly
so the receiver can verify it via `DeviceKeySigner::verify()` after
decryption — transport encryption is additive to op-log signatures, not a
replacement.

`DeviceKeySigner` is the Ed25519 signer/verifier for per-device-signed
op-log entries, using `sodium_crypto_sign_detached`/
`sodium_crypto_sign_verify_detached` directly (the same pattern as
`ElectronUpdateChannel::verifyManifest()`). `verify()` returns false (never
throws) on any malformed input.

**Noise protocol implementation** (`NoiseCipherState`, `NoiseSymmetricState`,
`NoiseHandshakeState`, `NoiseSession`) implements Noise_IK and Noise_XX over
X25519 + ChaChaPoly (12-byte-nonce IETF variant, not XChaCha) + BLAKE2b:

- `NoiseCipherState` (§4) wraps ChaCha20-Poly1305 with a monotonically
  incrementing nonce counter tracked as two 32-bit unsigned words (PHP
  integers are 64-bit signed, so this avoids overflow before the true Noise
  MAXNONCE). Decryption throws on AEAD MAC failure — never silently returns
  false in a transport context.
- `NoiseSymmetricState` (§5) maintains the running chaining key and
  handshake hash using BLAKE2b-based HKDF approximation. `split()` derives
  the final two `NoiseCipherState` objects (initiator send key, responder
  send key).
- `NoiseHandshakeState` implements the IK (2-message, reconnecting paired
  devices) and XX (3-message, first connect / key rotation) patterns as a
  token-sequence state machine. Static keys MUST be X25519 keypairs, never
  Ed25519. `setEphemeralKeypair()` is a test-only seam that throws outside
  `APP_ENV=testing/local` since a static/injected ephemeral destroys forward
  secrecy.
- `NoiseSession` wraps the two directional `NoiseCipherState` instances
  after a completed handshake. `peerStaticPublicKey()` returns the remote
  device's X25519 public key revealed during the handshake; the caller MUST
  verify it against `DeviceRegistryService::deviceX25519Keys()` before
  trusting any frames, and MUST close the connection on any decrypt()
  failure (the session is non-resumable).

### mDNS discovery (`Internal\Transport\Discovery\*`)

Layered discovery protocol: Level 1 mDNS (dns-sd on macOS, avahi on Linux)
advertises/browses `_beatrax-sync._tcp` with a `did={deviceId}` TXT record;
Level 2 falls back to manually-configured host:port entries; Level 3 (no
peer found either way) signals the caller to fall back to the ZK relay.

Security: an advertiser whose `did=` TXT record matches no confirmed entry in
`DeviceRegistryService::deviceKeys($userId)` is dropped BEFORE any TCP
connection attempt. That is an optimization, not a trust boundary: the Noise
handshake is the real auth gate, and the pre-filter only avoids wasted
handshakes against rogue advertisers.

`DiscoveredPeer` intentionally emits plaintext `ws://` (not `wss://`) —
transport TLS is deliberately absent on the LAN-direct path since the Noise
IK/XX handshake already provides end-to-end confidentiality, integrity, and
peer authentication; a WebSocket-layer TLS cert would add no security over
Noise and would require a LAN PKI the project does not have. `isConnectable()`
filters out peers with an unresolved host/port (dns-sd browse without a `-L`
resolve step yields these).

`LocatesSystemBinary` is a shared trait for locating a system binary in
standard paths, used by `MdnsAdvertiser` to find `dns-sd` or
`avahi-publish-service`.

No `sharkydog/mdns` dependency — advertising shells out via Symfony Process,
already in the dependency tree, and browsing speaks the wire format directly.

`MulticastMdnsQuery` speaks mDNS on the wire instead of shelling out, because
a phone has neither `dns-sd` nor `avahi`. It sends one PTR question to
`224.0.0.251:5353` from an ephemeral port with the unicast-response bit set —
receiving the multicast reply would need a group join, which Android gates
behind a multicast lock — and accumulates the answers through
`MdnsResponseParser` into an `MdnsInstanceTable`. It applies no registry
filter of its own: the fresh device using it has no confirmed peers yet, which
is the whole reason it is browsing.

#### `PeerDiscovery::reach()` — silence with a reason attached

`browse()` returning `[]` carries two opposite meanings: no peer is
advertising, or this runtime never got to ask. `reach()` separates them,
returning a `Modules\Sync\Public\Enums\LanDiscoveryReach`:

| Case | Means | `silenceMeansNoPeers()` |
|---|---|---|
| `Available` | the question reached the network | `true` |
| `Unsupported` | the question never left this device | `false` |

It describes the **last** browse, and before any browse the runtime's own
verdict. `MulticastMdnsQuery` starts each browse from that verdict — iOS
without `com.apple.developer.networking.multicast` is `Unsupported`, every
other runtime is `Available` — and lowers it to `Unsupported` when the socket
could not be opened or the question could not be put on the wire. It never
raises it, because a send the kernel accepted proves nothing on a platform
that drops the datagram afterwards.

`CachedPeerDiscovery` delegates `reach()` rather than caching it beside the
peers: a cache hit skips the browse, so the inner query's verdict is still the
one belonging to the browse those peers came from.

`PairingGateway::lanDiscoveryReach()` is the cross-module read of the same
value, sitting beside `discoverInitiatorOnLan()` whose `NoPeerReached` is the
outcome it explains, so a screen reporting "nothing answered" asks the transport
what that silence meant instead of naming a platform.

#### `BonjourBridgeQuery` — the same contract over the platform's own browser

A second `PeerDiscovery` that asks the mobile shell to browse Bonjour, instead
of sending the datagram itself, through the `NativeBridge` seam
(`NativePhpBridge` in production, calling `nativephp_can` / `nativephp_call`).
Its `reach()` is `Unsupported` unless the shell registered a `Discovery.Browse`
bridge function, and a browse the native side refuses is likewise an inability
rather than an empty network.

`SyncServiceProvider::discovery()` binds whichever road can look: the Bonjour
browser where the shell provides one, the multicast query everywhere else. When
neither can look the multicast query is still bound — something has to answer
the caller — and it says so through `reach()`.

**The iOS half of this is not built.** No `Discovery.Browse` bridge function
exists on either platform, so `BonjourBridgeQuery` is never selected today.
See [the iOS discovery page](../mobile/ios-lan-discovery-entitlement.md).

### Zero-knowledge relay (`Commands\RelayServeCommand`, `Internal\Transport\Relay\*`)

The relay exposes the ZK relay mailbox over three HTTP routes:

- `POST /relay/deliver` — accept a Noise ciphertext blob (open submission)
- `GET /relay/drain` — return pending blobs for the authenticated device
- `DELETE /relay/drain/{id}` — mark a blob as delivered (confirmed drain)

**Zero-knowledge invariant.** The relay contains zero `sodium_*` calls.
Blobs are stored and forwarded verbatim — the relay never decrypts,
inspects, or JSON-decodes blob content. All cryptographic operations happen
end-to-end between devices, inside the Noise session; the relay operator
learns only: sender_did, recipient_did, blob size, and delivery timestamp.

**Drain authorization.** `GET /relay/drain` and `DELETE /relay/drain/{id}`
require a bearer token in the Authorization header bound to the mailbox
being accessed. The credential is the draining device's OWN per-instance
drain secret — 32 random bytes minted on first use by
`RelayConfig::deviceDrainSecret()` and persisted at
`secretsPath()/sync-relay-drain-secret.json` — not a value derived from
anything the relay or its peers hold.

The relay side is `RelayDrainRegistry`, a trust-on-first-use store: the first
token ever presented for a device id is recorded as `did → sha256(token)` and
accepted, and every later drain or confirm for that id must present a token
whose digest `hash_equals` the stored one. A token scoped to device A
therefore cannot drain or confirm-delete device B's mailbox.

An earlier scheme derived each device's token as
`HMAC-SHA256(RelayConfig::authToken(), recipient_did)`. It is gone: the relay
auth token travels in the pairing QR, so every peer that had ever paired could
recompute any device's drain token. The residual weakness of the registry is
narrower but real — an attacker who registers a victim's device id BEFORE the
victim ever drains wins the slot. See
[the relay endpoint authorization page](relay-endpoint-authorization.md).

ZK is preserved: the relay stores only a hash of a bearer token against a
device id (routing metadata it already sees) — it never reads blobs, never
learns a user_id, and never has device key material.

Authorization is enforced at the endpoint (`RelayServeCommand`), not inside
`RelayMailbox` (a pure ZK store). This separation keeps `RelayMailbox` testable
without auth scaffolding and makes the authorization policy explicit and
auditable in one file.

Deliver (`POST /relay/deliver`) is open — anyone can drop a ciphertext blob into
any mailbox. The recipient's per-device drain token is the only thing that
gates retrieval.

**Resource-exhaustion guards on the open deliver path.** Because delivery is
intentionally unauthenticated, `handleDeliver()` bounds the abuse surface
instead of gating it:

- Blob size is capped server-side at `RelayClient::MAX_BLOB_BYTES` (mirrors the
  client-side cap so a caller that skips `RelayClient` entirely cannot smuggle
  an oversized blob past it) → 413 when exceeded.
- `sender_did` / `recipient_did` must match a bounded, safe character set
  (`RelayServeCommand::DID_PATTERN`) → 422 when malformed or oversized.
- Each recipient mailbox is capped at `MAX_PENDING_PER_RECIPIENT` undelivered
  rows → 429 when full, preventing unbounded SQLite growth for the 30-day
  undelivered TTL.

`GET /relay/drain` additionally caps the page size at `DRAIN_PAGE_SIZE` so a
single drain response cannot force the draining device to buffer an unbounded
backlog in memory.

**Deployment.** On the desktop the relay is started BY the application:
`Modules\Desktop\Internal\Native\RelayListenerProcess::startIfEnabled()`
provisions a local endpoint and spawns `relay:serve` as a persistent
`ChildProcess` for any install where some account has enabled sync
(`DeviceRegistryService::hasLocalDevice()`). That is not an accident of
configuration: pairing frames travel ONLY over the relay courier, so with no
relay the handshake has nowhere to deliver — the desktop never receives the
phone's accept and the phone polls forever.

What IS opt-in is a REMOTE relay: `RelayConfig`'s endpoint URL defaults to
absent, and self-hosters point it at their own `php artisan relay:serve`. The
locally-spawned one is the offline-buffering and pairing fallback beside the
LAN-direct path (`sync:serve`).

**Event-loop lifecycle.** `handle()` calls `\Amp\trapSignal([SIGTERM,
SIGINT])`, which suspends the current fiber until one of those signals
arrives. On shutdown: stop the HTTP server, return `self::SUCCESS`.

**Client side (`RelayConfig`, `RelayMailbox`, `RelayClient`).** `RelayConfig`
reads/writes the relay endpoint URL (non-secret, plain JSON at
`sync/relay.json`, default absent — opt-in) and the auth token (secret, plain
JSON at `secretsPath()/sync-relay-token.json`, chmod 600, never `.env`).
`isInsecure()` flags non-https URLs so the UI can warn the user (the relay is
ZK regardless of TLS, but an `http://` endpoint leaks ciphertext sizes and
metadata to a network eavesdropper). `deviceDrainSecret()` mints this device's
own drain credential on first call — 32 random bytes — and persists it to a
third secret file, `secretsPath()/sync-relay-drain-secret.json`, under the same
`mkdir 0700 → write → chmod 0600` discipline as the auth token. It derives
nothing: the relay learns the secret by seeing it, once.

`RelayMailbox` is the zero-knowledge ciphertext mailbox: stores and routes
opaque Noise ciphertext blobs addressed by recipient `device_id`. Hard ZK
invariant — MUST NEVER call `sodium_*`/`json_decode()` on the blob, read/write
any `user_id` column, or inspect blob content. GC policy: delivered blobs
expire 7 days after delivery, undelivered blobs 30 days after creation, both
compared as lexical UTC Zulu ISO8601 strings. `Instant::zulu()` is the
sole producer for this column and for `pairing_tokens.expires_at`: it converts
to UTC *and* asserts the shape in one call, so a write that would corrupt the
GC ordering throws instead of landing. Inlining `->toIso8601String()` is the
tempting simplification and the wrong one — it emits the local offset.

`RelayClient` is the HTTP client moving opaque Noise ciphertext between this
device and the configured relay endpoint (`POST /relay/deliver`,
`GET /relay/drain`, `DELETE /relay/drain/{id}`). `deliver()`/`drain()` throw
when the configured endpoint uses plain HTTP. `MAX_BLOB_BYTES` mirrors
`RelayServeCommand`'s server-side cap exactly, so a caller bypassing this
client can't smuggle an oversized blob past it.

### Peer catch-up, session, and WebSocket handler

`PeerCatchUpExchanger` implements the HLC-watermark catch-up protocol
between two syncing peers: initiator and responder exchange
`CATCH_UP_REQUEST`/`CATCH_UP_RESPONSE` with a PER-PEER watermark — how far this
device has consumed of THAT peer's stream, kept in `sync_peer_catch_up_state`
and advanced by `SyncSession::receiveOps()` from what the peer actually
delivered (`PeerCatchUpWatermarks`). It is not this device's own
`hlc_clock_state`: that is its last LOCAL write and says nothing about the peer,
so every peer op older than this device's last edit fell below the watermark and
was never asked for. Each side responds with any `op_log_entries` newer than the
peer's watermark, then
both switch to `CATCH_UP_COMPLETE` and live-stream mode. Ops are batched into
`TransportFramer` frames of <= 64KB / <= 1024 ops each (large syncs, e.g.
first onboarding, span many frames). All queries are `user_id`-scoped. Op
signature re-verification is the caller's (`SyncSession`'s) responsibility —
this class only reads from `op_log_entries`.

`SyncSession` is the per-peer transport session: Noise auth gate + additive
Ed25519 op verification. `authenticate()` verifies the peer's revealed
X25519 static key against `DeviceRegistryService::deviceX25519Keys()`
(confirmed-only, user-scoped); on success writes `sync_sessions`
(`status='active'`) and updates `device_registry.last_seen_at`. Senders pack
their own frames and call `encrypt()`; a `sendOps()` that framed a whole batch
in one `TransportFramer::encode()` call was removed, because it carried none of
the overflow batching or oversized-entry skipping `PeerCatchUpExchanger` does
and would have reintroduced the wedge that skipping exists to prevent.
`receiveOps()` Noise-decrypts,
decodes, verifies each entry's Ed25519 signature (additive — Noise
authenticates the socket, but entries may be forwarded via relay or replayed
from disk, so the transport channel is not the signing boundary), then
replays verified entries via `OpLogReplayer`. NOT a singleton — each
connection gets a new `SyncSession` since its crypto state is mutable.

`SyncWebSocketHandler` is the amphp WebSocket handler driving the full sync
session lifecycle: (1) Noise IK handshake (responder side), (2) `SyncSession`
auth gate, (3) bilateral `PeerCatchUpExchanger` exchange, (4) live-streaming
loop. Host-agnostic: no Electron/NativePHP-only imports, runs identically
from the `sync:serve` artisan daemon or a NativePHP ChildProcess.

DoS hardening: every `receive()` — pre-auth handshake, catch-up, and the live
loop — is bounded by a `TimeoutCancellation` so a stalled or slow-loris peer
cannot pin a fiber indefinitely. The catch-up frame loop is additionally
bounded by `MAX_CATCHUP_FRAMES` so a confirmed-but-malicious peer cannot
declare an unbounded `frame_count` and stream frames forever. A peer that
exceeds either bound has its connection closed.

`deliverGdkEpochWraps()` delivers pending sealed-box GDK epoch wraps over the
already-authenticated live Noise session (outbound to the peer, inbound via
`GdkEpochControlHandler`) as a fixed-point optimization appended immediately
after catch-up completes — both directions read from the SAME local
`relay_mailbox` table `GdkRotationService::rotateAndRevoke()` always enqueues
into regardless of whether the recipient is online, so this purely avoids
making an already-connected peer wait for a separate relay round-trip. Both
directions degrade gracefully (skip, never throw) when a dependency is
unavailable (no `RelayMailbox`/`GdkEpochControlHandler` binding, no unlocked
app-lock session, or an empty `localDeviceId`), mirroring `OpLogReplayer`'s
"headless daemon may have no unlocked session" convention rather than
tearing down the whole sync session over one skippable delivery.

### LAN-direct sync listener (`Commands\SyncServeCommand`)

Boots the amphp WebSocket server on `0.0.0.0:{port}`, starts mDNS advertisement
so peers on the LAN can discover this device, and shuts down cleanly on
SIGTERM/SIGINT via `\Amp\trapSignal()`.

**Host-agnostic design.** Runnable from two hosts:

- Primary: NativePHP `ChildProcess` (auto-started, persistent, auto-restarted)
- Fallback: standalone launchd daemon or manual `php artisan sync:serve`

No import of any Electron/NativePHP-only API — the Noise/WS transport core
(`SyncWebSocketHandler`) runs identically from either host.

**Event-loop lifecycle.** `handle()` calls `\Amp\trapSignal([SIGTERM,
SIGINT])`, which suspends the current fiber until one of those signals
arrives. The `SocketHttpServer` runs in the background (revolt event-loop).
On signal receipt: stop the WS server and the mDNS advertiser, then return
`self::SUCCESS`.

**SyncWebSocketHandler wiring.** `SyncWebSocketHandler` is injected via the
service container — the binding in `SyncServiceProvider` provides the device
credentials resolved from `DeviceIdentityLoader` at container resolve-time.
When no identity is available (fresh install, app locked), the injected
handler carries empty placeholder credentials and closes all incoming
connections at the auth gate. The NativePHP `persistent:true` host
auto-restarts the command after setup completes.

**Pairing-offer route.** `PairingOfferRequestHandler` is mounted in front of
the `Websocket` handler and serves exactly one route, `GET /pair/offer`,
delegating every other request to `Websocket::handleRequest()`. It is a
hand-written `RequestHandler` rather than `amphp/http-server-router`, which is
not a dependency of this project and is not worth becoming one for a single
path. The lookup is scoped to `SyncWebSocketHandler::localUserId()` — the user
the daemon was spawned for — and answers from `PairingOfferService` behind
`PairingOfferRateLimiter`.

## Status and UI surfaces

### Sync status service (`Public\Services\SyncStatusService`)

Reads `sync_sessions` rows to compute per-peer statuses, an aggregate overall
status (`'all_synced'|'syncing'|'offline'|'error'|'unknown'`, prioritized
error > syncing > offline/all_synced > unknown), and a human-relative
"last synced" string. The overall-status priority order and the "closed
row still counts as all_synced" rule exist so a peer that finished syncing
and then disconnected does not get mis-reported as offline/error.

### Devices & Sync settings section (`Internal\Http\Livewire\DevicesAndSyncSettingsSection`)

Mounted into the Core settings page below the App lock section. Per-method DI
throughout (never constructor DI), mirroring `AppLockSettingsSection`'s
pattern so the component stays PHPStan-clean at level 10.

Responsibilities: surface the enable-sync toggle gated on an app-lock being
configured (with no app-lock, the toggle is blocked and flashes a message —
the UI half of a defense-in-depth pair with `DeviceIdentityService`'s
service-level hard-throw); on enable, generate + persist the device identity
(lazy, explicit opt-in — no key-file exists until sync is turned on) and show
the self device; list confirmed devices with inline rename (no revoke/remove
action beyond view/rename/verify at the row level); open the pairing-flow
modal; configure the relay endpoint URL (default none — LAN-direct
out-of-box; a non-HTTPS URL surfaces a warning; writes are gated behind the
same app-lock requirement).

Cross-module boundary: app-lock state is read via the Auth public service
`AppLockClientConfig` — never by querying `user_app_lock_configs` directly.
Device rows are read via the Sync public `DeviceRegistryService` and written
only through user-scoped `where('user_id', ...)` queries. The authenticated
user is always read from `CurrentUser` — never a request-supplied id.

### Pairing-flow modal (`Internal\Http\Livewire\PairingFlowModal`)

A step-based flow inside the Devices & Sync section's Flux modal:
`choose_direction -> show_code -> confirm -> success` (this device shows) or
`choose_direction -> enter_code -> confirm -> success` (this device types).
The second arm is desktop-only: on a mobile runtime `enterACode()` redirects
out of the modal to `route('mobile.pair')`, so `enter_code` is never reached
there. That screen has a camera and this modal has never had one, which made
the modal's own offer to scan true only over there. Per-method DI throughout.

`submitCode()` recovers the initiator's identity through
`PairingGateway::discoverInitiatorOnLan()`, seeds a local row from it, accepts
onto that row, and sends the `PAIR_RESPONDER_ACCEPT` the issuing device needs
to leave its own show-code step. Each `PairingOfferLookup` ending gets its own
line — `code_not_accepted`, `no_peer_answered`, `no_peer_search`,
`rate_limited` — because a single "invalid or has expired" was false for
three of the four
([the failure](pairing-handshake.md#both-clients-have-to-ask)).

The showing arm is the one the modal branches on platform for. A typed code is
only resolvable against a device advertising the sync service, which no phone
does, so `showMyCode()` mints no word code on a mobile runtime and the step
renders `scan_on_other` — the QR alone — in place of the word code and
`enter_on_other`
([why](pairing-handshake.md#a-phone-can-only-be-scanned)).

`Public\Enums\PairingWizardStep` is the one spelling of those step names, shared
with the phone's own pairing screen. The `$step` property itself stays a
`string`: it is an unlocked public Livewire property, so it is rehydrated
straight from the client payload with no enum coercion, and typing it would
turn a crafted step into a 500 rather than a fallback. Every reader goes
through a private `currentStep()` that resolves it with `tryFrom()`, and the
view is handed the resolved enum under a different name (`$wizardStep`),
because view data cannot shadow a same-named public property.

Trust gate: the safety-number is derived independently on BOTH peers from
BOTH stored public keys; `device_registry.confirmed_at` is set ONLY after
`bothConfirmed()` — `confirmMatch()` routes through
`PairingTokenService::confirm()`, which owns the both-confirm transition. An
unconfirmed/forged device therefore never enters
`DeviceRegistryService::deviceKeys()`. Every `pairing_tokens`/
`device_registry` read is user-scoped to the `CurrentUser` id — a user-A
token can never be consumed under user B.

Scope boundary: identity exchange + safety-number trust only.

### Sync-health panel and status surface (`SyncHealthPage`, `SyncStatusSection`)

`SyncHealthPage` is a minimal read-only sync-health panel accessible from the
Dev Console at `/dev/sync-health`, showing the quarantined-op count (last 7
days) + a recent-skip table. Always filters `op_log_quarantine` by `user_id`
— there is no `BelongsToUser` global scope on this table in queue/console
context.

`SyncStatusSection` is the sync-status surface for the "Devices & sync"
settings section: an overall "all devices up to date - synced Nm ago" line
(or error/offline state) and a per-peer list with online/offline dot,
last-seen relative time, and explicit error states (can't reach peer, relay
unreachable, handshake failure). Reads peer status exclusively via the public
`SyncStatusService` — never queries `sync_sessions` directly.

## Service provider (`Providers\SyncServiceProvider`)

Single-owner provider for the Sync module: this is the only file the module's
wiring ever needs to touch. Bindings for classes that may not exist yet
are guarded with `class_exists()`/`interface_exists()` and referenced by
runtime-built FQCN strings (not `use` imports / `::class`) so PHPStan stays
clean at every intermediate state — the class is wired automatically the
moment it exists on disk, without ever needing to re-edit this provider.

`HybridLogicalClock` is bound TRANSIENT, not singleton (see Clock section
above). The same "holds mutable state -> transient" convention governs
`SyncSession` (per-peer, constructed by the WS handler) and
`EncryptionMigrationSupport` (caches one primed epoch for the duration of a
single migration pass).

`GdkRewrapContract` is an INTERFACE, not a class — `class_exists()` always
returns false for interfaces (PHP semantics), so its wiring guard uses
`interface_exists()` instead.

Noise state machine classes (`NoiseHandshakeState`, etc.) are NOT registered
as singletons at all: they hold mutable crypto state and must be constructed
fresh per call; callers instantiate them directly with no DI container
resolution.

`SyncWebSocketHandler` is bound as a factory (not a singleton) so the
container can resolve it from `SyncServeCommand`'s constructor without
requiring runtime device credentials at bind time. The factory supplies
empty placeholder credentials; the handler's own auth gate
(`SyncSession::authenticate`) rejects all peers when `userId=0` (no confirmed
devices exist for that id). In production, the NativePHP ChildProcess host
resolves real credentials via `DeviceIdentityLoader` before starting
`sync:serve`; the daemon exits non-zero if credentials are unavailable, and
NativePHP auto-restarts it.

## A row a parent owns is deleted by the application, not the database

Every foreign key is classified: either the parent **owns** the child, or the
child outlives it. `DependentRowCascade` (`Modules/Sync/Public/Services`) holds
that classification and walks it depth-first, deleting owned rows and returning
one `EntityMutated` delete per row that carries merge rules. Callers dispatch
those after their transaction commits.

No foreign key declares `ON DELETE CASCADE`. A cascade removes the child and
tells nothing: no tombstone is written, the child's create op stays live in the
op log, and the peer either resurrects the row or quarantines it forever. One
real occurrence of that was found in the field — a `recurring_series_occurrences`
row quarantined twice as `missing_reference` because the transaction that owned
it had been deleted on the other device and the occurrence's create op outlived
it.

Dropping the clause leaves `NO ACTION`, so the database now **refuses** a parent
delete while an owned row is still there. That is deliberate: a missed delete
path fails loudly instead of losing a row silently. `user_id` is the exception
to the registry — it stays discovered from the live schema by
`UserScopedDataPurge`, which clears children with no `user_id` of their own
before it sweeps.

Two tests hold the line: every foreign key must be classified as owning or not
owning, and no foreign key may declare a cascade
(`Modules/Sync/tests/Feature/EveryForeignKeySaysWhoOwnsTheRowTest.php`).

## Capture must cover every table the merge registry declares

`MergeRulesRegistry` names the tables sync knows how to merge. Capture is a
separate, explicit list: each writer dispatches a mutation event and
`SyncCaptureListener` turns it into op-log entries. Nothing reconciles the two
lists at runtime, and they drifted — 25 tables had merge rules while 9 had
capture.

A table with merge rules and no capture is worse than one with neither. It
ships in the pairing snapshot, so both devices start identical, and then every
later edit stays on the device that made it. The two devices agree about
history and disagree about the present, with nothing on screen saying so. A
goal created on a paired phone was still only on that phone.

`SyncCaptureCoverageTest` now holds the two lists against each other. It fails
when a syncable table has no capture, when a captured table has no merge rules
(the peer would quarantine every op), and when a table on the known-gaps
backlog gains capture without being struck off — so the backlog can only
shrink.

Two ways to capture, both explicit at the write site:

- A bespoke event (`TransactionMutated`, `GoalMutated`, …) where the entity
  needs its own shape or several handlers.
- `EntityMutated`, which carries its table name, for writers that need nothing
  more than create/edit/delete. This is not discovery — the writer still
  dispatches by hand; it only avoids one event class per table.

An edit dispatches one op per changed column rather than a whole row, so two
devices editing different fields of the same row both keep their change.

### Deliberately not captured

`migration_import_baseline` and `migration_source_map` are rewritten wholesale
by a migration run and never edited by hand; a partial replay of one would
describe a state neither device was ever in.

### Ten more tables brought under merge rules

`pot_movements`, `user_preferences`, `recurring_series`,
`recurring_series_occurrences`, `chain_links`, `anomaly_alerts`,
`drift_alerts`, `system_alerts` and `forecast_scenarios` now carry merge rules,
insertion-order placement and owner scoping, so a paired device receives them
in the backfill instead of an empty table. `pot_movements` was the sharp end:
`pots` synced without it, so every pot on the phone read EUR 0,00 while the
page promised the balances add up to the real account balance.

`forecast_scenario_mutations` closed the tenth gap. `forecast_scenarios` is
only the named container; the mutations are what the scenario actually changes,
so a synced scenario arrived as an empty what-if. It carries its own `user_id`
and needs no `PARENT_SCOPE` entry, but both ids it names are owner-scoped and
sit in `RowOwnership::ownedReferences('forecast_scenario_mutations')`:
`forecast_scenario_id`, and `target_series_id`, which is deliberately not
FK-constrained because the series
lives in another module — so that check is the only thing standing between a
replayed mutation and another household member's series.

### Capture at the write paths that needed no new identity

- `pot_movements` — `PotWriter` dispatches `EntityMutated` for every movement
  it inserts, so a fund, withdrawal or transfer made after pairing reaches the
  peer.
- `user_preferences` — `Core\Public\Services\UserPreferenceWriter` is the
  single write seam. Four Livewire components each ran their own
  `UserPreference::updateOrCreate`, which would have meant the same dispatch
  copied four times and forgotten by the fifth caller.
- `forecast_scenarios` and `forecast_scenario_mutations` — create, rename and
  delete, plus add, edit and remove for the mutations, all in
  `Forecasting\Public\Actions`. Deleting a scenario emits one tombstone: the
  mutations cascade away behind it on the peer exactly as they do locally.

### The settings row: the table that is not all one thing

`users` was the last covered gap and the one no table-level sweep could see: it
has no `user_id` column because its own primary key IS the owner, so every
"can this table be attributed to a user?" check skipped it and every capture
audit read it as out of scope. Meanwhile `period_start_day` keys
`envelope_assignments.period_start`, and `EnvelopePeriodRekeyer` re-keys and
*syncs* those rows — so moving the budget day on the desktop left the phone
asking `PeriodQuery` for a window no synced row was keyed to, and its whole
budget read as zero.

Three things had to be true at once:

- **Scope by identity, not by pk.** `RowOwnership::isSelfScoped()` bounds a
  `users` write to `id = <session user>`, and the applier drops the wire pk for
  such a table: two devices mint different autoincrements for one reader, so
  the origin's id names nobody on the peer.
- **Neither created nor removed from the wire.** A create is refused because
  the row cannot be attributed to a parent; a tombstone is skipped outright. A
  peer may edit the reader's settings; it may never mint or remove the reader.
- **A per-column decision, enforced.** The row mixes the reader's settings with
  this device's `password`, `theme` and developer gate. The registry carries
  `DEVICE_LOCAL_COLUMNS` and `ASKED_OF_EVERY_JOINER` (`country_code`) beside
  the field map, the capture listener drops both before writing an op, and
  `EveryUserColumnIsPlacedTest` fails when a new column lands in none of the
  three. A table-level guard is what let `period_start_day` be added silently.

The backfill carries the row as Sets rather than a create, since the peer
already has a `users` row of its own; the two capture sites are
`Core\Public\Actions\WriteUserPreference` (which both writes and announces)
and `SettingsPage::save()`, which saves the model and calls `announce()` with
the columns that came back dirty.

### A captured table can still have an uncaptured writer

`SyncCaptureCoverageTest` asks whether a table has *a* capture site, which is
the right question for a table with one write seam and the wrong one for a
table with two. `counterparties` passed it from the day the resolver announced
its upserts, while `/counterparties/triage` — accept, hand-label, ignore —
called `save()` and dispatched nothing. There is no observer on the model, so
a reader who triaged forty unknowns on the desktop found forty unchanged rows
on the phone, with no error anywhere to say so.

The fix is the same one `user_preferences` got: one write seam per table, not
one dispatch per call site.
`Counterparties\Internal\Actions\LabelCounterparty` owns all three triage
writes and announces each with `EntityMutated`. When adding capture, count the
writers of the table, not the dispatches.

### `system_alerts` travels only where it is owned

`system_alerts` captures in part, and the split is the point. Six probe write
sites across Core and Desktop raise rows with a NULL `user_id`: a corrupt
backup, a stale backup, SQLite out of WAL mode, a crashed worker, an available
update. Those describe the machine that noticed, not the account, and the peer
raises its own from its own probes — so they stay uncaptured, which is also why
the `user_id`-scoped backfill has never carried them.

The three that are owned do travel:
`Core\Public\Services\SystemAlertWriter::raiseForUser()` is the only way one
is written, and it takes a non-nullable owner so the rule is enforced by the
signature rather than remembered at each call site. Its callers are the two
EmailScan re-consent listeners and the OpenBanking one — a lapsed mail token or
bank consent is a fact about the account, and the user re-grants it on whichever
device is to hand.

Acknowledging is the one user action on this table, and it is a SET, so it can
only travel for a row the peer already holds. `captureAcknowledgement()` drops a
system-wide row for that reason and captures an owned one, from both paths that
acknowledge: the banner's button via `AcknowledgeSystemAlert`, and the OAuth
callback that clears the re-consent banner when the user finishes reconnecting.

### `anomaly_alerts` gets an id both devices compute

A detector-written table cannot be captured while its primary key is an
autoincrement. Both devices run the same detector, so each mints its own id for
the same logical row; the idempotency UNIQUE then drops whichever create lands
second, and the losing device's later SETs name a pk it does not hold.

`anomaly_alerts` is the first of the five out of that hole. Its id is now
`Core\Public\Support\DerivedRowId::for('anomaly_alerts', [user_id,
transaction_id])` — the columns `anomaly_alerts_uniq` already names, folded
through sha256 into a positive 63-bit integer. Sixty-three and not sixty-four
because SQLite's `INTEGER` and PHP's `int` are both signed: a set top bit would
read back as a negative id.

The tuple works because neither half of it moves. An alert is opened against one
charge and stays against it, and the transaction's own id means the same thing
on both devices because transactions already sync with their primary key
preserved. Two devices therefore compute the same number, the second create
collides harmlessly on the primary key — `CreateRowInsertFailure::AlreadyPresent`
— and a later acknowledge lands on a row that exists. `AnomalyAlertConvergenceTest` replays exactly that: two independent
creates plus one SET, ending as one row in the acknowledged state.

Capture itself is two dispatch sites, because the table has only two writers.
`AnomalyEvaluator` emits the create. `AnomalyAlertStateMachine` emits the edit,
and it is the sole legal mutator of `state` — every acknowledge, snooze,
dismissal and revival already routes through it, so one dispatch covers all of
them.

Keeping the id off the autoincrement had one consequence worth writing down: a
derived id does not ascend with insertion, so `AnomalyAlertQuery` could no
longer order or page on it. That list has always meant to read newest-first, and
it has an index for it, so it now orders `detected_at DESC` with the id only
breaking ties, and the cursor carries both halves.

### Capture for the last five detector-driven tables

`chain_links`, `recurring_series`, `recurring_series_occurrences`,
`drift_alerts` and `savings_insight_dismissals` were the rest of that hole. They
had merge rules and travelled in the backfill, and then never moved again: an
edit made after pairing stayed on the device that made it.

Each now derives its id the same way `anomaly_alerts` does, and
`uncapturedBacklog()` is empty.

- **`chain_links`** — `(user_id, from_transaction_id, to_transaction_id, kind)`.
  The table has no UNIQUE of its own, so `ChainLinkInsertHelper`'s own dedupe
  guard was already the only statement of what makes a link the same link; the
  id is now that same tuple. The NULL endpoint is carried as a NULL rather than
  folded to zero, because a hint row and a resolved row off one transaction
  differ by exactly that column. Nothing in the tuple moves: `kind` is never
  rewritten, and a hint keeps its NULL endpoint until it is deleted whole.
- **`recurring_series`** — `(user_id, direction, cluster_counterparty_key,
  latest_currency)`. Not the table's UNIQUE: that names `cluster_key`, which
  encodes the cadence band and which `SeriesRefresher` rewrites in place the
  moment a subscription slips from monthly to quarterly. The counterparty key is
  what the detector itself falls back to when that happens — it is what
  `rec_series_cluster_cp_key_idx` exists for — and both detectors group their
  transactions by exactly (counterparty key, currency), so one merchant in one
  currency is one series on every device. `cluster_key` travels as an ordinary
  mergeable column instead.
- **`recurring_series_occurrences`** — `(recurring_series_id, transaction_id)`,
  its own UNIQUE, once the series id above converges.
- **`drift_alerts`** — `(recurring_series_id, latest_occurrence_id)`, its own
  UNIQUE. Both halves are frozen at detection.
- **`savings_insight_dismissals`** — `(user_id, insight_key)`, its own UNIQUE.
  The key is `"{kind}:{recurring_series_id}"`, so it converges only because the
  series id does.

The same consequence as `anomaly_alerts` followed, in two more places. A derived
id does not ascend with insertion, so `DriftAlertQuery` now orders
`detected_at DESC` with the id breaking ties, and `RecurringSeriesProjector`'s
newest-first sort orders `created_at DESC` the same way; both resolve the
cursor's sort value from the cursor row rather than comparing ids. The
`chain_links` read models already led on `created_at` or `confidence`, so they
needed nothing.

Deriving those ids exposed a defect it did not cause: `OpLogReplayer::parentsFirst()`
asked the container for `CoveredTableOrder`, and `Container::bound()` answers
false for a concrete class nobody registered — so the lookup returned null and
both that pass and `childrenFirst()` silently left the ops in the order they
arrived. Any child create whose parent's clock ran later was refused by its own
foreign key and quarantined. The replayer builds the collaborator itself now,
the way `OpLogRebuilder` always has.
