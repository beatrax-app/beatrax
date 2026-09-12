# Device identity and its key file

A device's identity is two keypairs and a UUID. The Ed25519 pair signs op-log entries; the
X25519 pair receives sealed epoch keys and establishes transport sessions. Both secret keys
live in one file, `sync/identity/<user_id>.enc`, encrypted under the app-lock KEK. The
database holds public keys only.

## Enabling sync twice must not mint a second identity

`generateAndPersist()` looks for an existing key file first, and restores from it rather than
replacing it.

This is the invariant the whole log depends on. Every op-log entry is signed by the identity
that wrote it, and peers verify against the public key in `device_registry`. Mint a fresh
keypair and the entire local history becomes unverifiable — not deleted, but silently
unusable: the device can no longer hand its own data to anybody, and a peer that already
trusted the old key now sees a stranger.

Switching sync off and on again is an ordinary thing for a user to do, which is what makes
this reachable rather than theoretical.

The restore path re-establishes the `device_registry` self row keyed on `device_id`, so a
surviving row is refreshed rather than duplicated. It sets `confirmed_at`, because
`deviceKeys()` hands peers only confirmed devices — an unconfirmed self row would hide this
device's own entries from the very peers it is trying to sync with.

"Refreshed rather than duplicated" is `device_registry_user_device_idx`, not the existence
test in front of it. Where two restores overlap the loser catches the constraint violation
and applies its update instead of inserting: it names the same device and carries the same
public halves, so converging on the winner's row is the whole intent, and an insert that
succeeded would be a second self row — which is a second identity to every peer reading
`deviceKeys()`. See [a check another writer can
invalidate](../../conventions/a-check-another-writer-can-invalidate.md).

## Separate keypairs, not derived ones

The X25519 pair is generated independently with `crypto_kx_keypair()`, never derived from the
Ed25519 signing key. Reusing one key across two primitives is a standard crypto anti-pattern,
and the two keys have genuinely different exposure: the Ed25519 public key is what the safety
number authenticates, while the X25519 public key is what a relay would try to substitute.
See [Pairing two devices](pairing-handshake.md) for how the confirm signature ties them
together.

Raw keypair buffers are zeroed as soon as the hex form is extracted, and the KEK is zeroed in
a `finally` on every path.

## Four states, not two

`DeviceIdentityLoader::load()` returns `null` for three very different situations: sync was
never enabled for this user (no key file), the app is locked (no KEK to decrypt with), or
the key file is there and does not open under the key this device holds. Callers that merely
need "is there a usable identity right now" can treat all three the same.

A caller that would *mint* on null cannot. `exists()` answers the file question **without**
needing the KEK, precisely so a locked device is never mistaken for a fresh one and
overwritten. `state()` names all four — `Absent`, `Locked`, `Unreadable`, `Usable` — for the
callers that must tell the user which one it is, or must refuse to write.

`Absent` is the one of the four that the file cannot answer on its own: it means "never
enabled" only where the registry agrees, and [a restored
database](#a-self-row-and-no-key-file-is-a-restored-database) is where it does not.

## The key file outlives the database that holds its key

The key file is on the filesystem; the KEK that opens it is wrapped in
`user_app_lock_configs`, in the database. The two have independent lifetimes, and only the
app-lock side of that is guarded:

- `AppLockProvisioner::enable()` mints a new random data key only where nothing is encrypted
  under an existing one, and `disable()` refuses while at-rest encryption is active, so
  switching the lock off and on again cannot leave `sync/identity/<id>.enc` and
  `sync/gdk/<id>.enc` wrapped under a key nothing holds. See
  [the app-lock data key's lifetime](../auth/app-lock-data-key-lifetime.md).
- Restoring a backup, or replacing the database for any other reason, strands the key files
  from the other direction, with nothing to guard it — and can equally *restore* a KEK that
  opens a file which did not open a moment ago.
- `AppLockProvisioner::rewrapForNewPin()`, which is what the "Forgot your PIN?" reset calls,
  re-wraps the **same** data key and is safe. So is `changePin()`, which additionally
  dispatches `AppLockPassphraseChanged` so the keyring is re-wrapped.

An unopenable key file is therefore an ordinary state of a real install, not a corruption. It
is read on a settings mount and on a poll tick, so it must never be an exception: an escape
there is a 500 on Data & devices, which is the only route to pairing.

## An unopenable key file is retired, never deleted

Two things must not happen to it, and both are tempting:

- **Deleting it on sight.** A page render must not destroy key material. The database that
  wraps the KEK may still be restored, and that is the whole recovery path for a file this
  device cannot currently open.
- **Minting over it.** That is what a bare `null` from `load()` would cause the next
  "enable sync" to do, silently. `generateAndPersist()` refuses instead, with
  `DeviceIdentityUnreadableException`.

So the settings section names the state, and offers one explicit action — and only when no
`device_registry` self row exists, i.e. when nothing was ever registered under the old
identity. `retireUnreadableIdentity()` **renames** the file to
`<id>.enc.unreadable-<timestamp>-<random>` and the ordinary enable path mints beside it. Every
reader looks for the exact `<id>.enc` name, so the retired sibling is invisible to all of
them, and a support session can still put it back.

With a self row present the notice appears without the action. Retiring an identity peers
were told about, and a history signed under it, needs the registry row and every pairing
retired with it, which is the operation the next section adds and this one is not.

## A self row and no key-file is a restored database

The key file is the one thing a backup may not carry: the private halves never leave the
device that generated them, so an encrypted backup is a copy of the database and the GDK
keyring (`PortableKeyMaterial` names exactly what travels) and nothing else. Restore that
onto a fresh install and `device_registry` arrives holding the old machine's self row, with
no `sync/identity/<id>.enc` anywhere on the machine.

Neither half of the app could see that, because neither half asked both questions:

- `DevicesAndSyncSettingsSection` read the **registry** and rendered sync as enabled.
- `OpCaptureSinkFactory` read the **key-file** and chose `SyncOffOpSink`, whose premise is
  that a device with no key-file has never synced and owes no peer anything.

So the screen said sync was on while every local write was logged at debug and dropped, with
both routes out of it shut: the enable toggle is disabled when the screen reads enabled, and
the retire-and-replace action above is offered only when it does not. The measurable half is
that a peer already holds these rows, so the backfill that repairs a never-synced device
repairs nothing here — a create naming a row the receiver holds fills only the columns it
never received, and an edit or a delete made in that window is gone for good.

The requirement that governs this state is `E2-R24`, and it was written alongside the fix
rather than before it. The commit that shipped the fix cites `E1-R1` — *every local mutation
MUST be captured* — because the governance gate refuses an identifier that does not yet exist
on `spec@main`, and `E2-R24`'s own page had not merged when that trailer was written. `E1-R1`
is the half the dropped writes violated; `E2-R24` is the requirement, and everything after it
here cites that.

`DeviceSyncStanding` is the question asked in one place now, and it has four answers rather
than two:

| Key-file | Self row | Standing | What it means |
| --- | --- | --- | --- |
| no | no | `NeverEnabled` | Nothing has ever synced here. Writes are dropped, as before. |
| no | yes | `IdentityMissing` | A restored database. Writes are **deferred**, and the screen says so. |
| yes | no | `RegistrationMissing` | The row this device can put back; the ordinary enable does it. |
| yes | yes | `Enabled` | Sync is on. |

Deferring is what the state is owed rather than a courtesy: `deferred_op_captures` holds
coordinates and never values, so it needs no key, and the drain replays them on the first
request that can sign. The reader does not have to press anything for that to happen — the
holding starts the moment the standing is read, and the repair is what ends it.

### What the repair retires, and what it must not

`repairRestoredSyncIdentity()` calls `retireSelfRegistration()` and then the ordinary enable.
The retirement sets `is_self` to `0` on the restored row and touches nothing else:

- **`is_self` has to go.** It carries no uniqueness constraint, and ten readers take
  `where('is_self', 1)->value(...)` — a second self row is a second answer to every one of
  them, in insertion order rather than by anything meaningful.
- **`confirmed_at` has to stay.** The restored op log is signed by the old machine's
  `device_id`, and a rebuild verifies it against `signatureVerificationKeys()`, which is
  confirmed-only. Clearing it would quarantine the whole restored ledger the next time
  anybody rebuilt, silently and much later.

That leaves a row that is confirmed and is not a device, and the two readings need separating
by something other than `confirmed_at`. `self_retired_at` is that something: a stamp written
by the retirement and read by `stillADevice()`, which the three readers the demotion put the
row in front of pass their query through.

| Reader | Retired row | Why |
| --- | --- | --- |
| `confirmedDevices()` | excluded | the device list; the machine is gone |
| `otherDeviceNames()` | excluded | nine callers meaning "my peers" — the delete-account warning, the phone's `hasPeers`, the LAN address pick, the manual-address field, the status surface, notification preferences |
| `peersOwedEpochs()` | excluded | it would otherwise be fanned a wrap into a mailbox nothing ever collects |
| `deviceKeys()`, `signatureVerificationKeys()`, `retainedDeviceKeys()`, `authorIdsWithAKeyOnFile()` | **kept** | this is the history-verification set, and it is the whole reason `confirmed_at` stays |

The line is drawn at what the demotion changed and nothing else. `is_self` was the filter on
all three excluded readers, so taking it off is what put the row in front of them; the key maps
never filtered on `is_self` and so read exactly as they did before the repair existed. In
particular `deviceX25519Keys()` still names the retired machine, so its Noise static key still
admits a handshake — true before any of this and unchanged by it. Whether a restore should also
shut the transport to the machine it was restored from is a separate question, and a real one.

The column is device-local for free: `device_registry` is declared uncovered in
`SyncCoverageIsDeclaredTest` — *trust is established by the ceremony, not by an op that arrives
claiming it* — so nothing on this table syncs, and a marker meaning "this is the machine THIS
install was restored from" could not be right on a peer anyway.

`retireSelfRegistration()` refuses outright where a key-file exists. A demotion cannot be
undone without the old `device_id`, and the one state it is for is the state where nothing on
this machine can answer for that row at all.

The order inside the repair is load-bearing for the same reason. A retirement the mint does
not follow leaves a device with no self row and no key-file — `NeverEnabled`, owing nothing,
dropping what it writes, which is the state this page exists to end. So every gate the enable
refuses on is asked *before* anything is retired. What remains is a mint that throws after its
gates passed: the reader gets the failure copy, the ordinary enable is offered and works, and
the window in which writes are dropped is one the reader is standing in rather than an
unbounded silence.

### The listener that answered and refused

`DeviceRegistryService::hasLocalDevice()` decides whether the desktop binds a sync port and a
relay port at boot, and its own callers say what it is for: *without a sync identity no peer
can dial in.* It read the registry row. A restored desktop therefore came up, answered, and
refused every handshake it was offered — which the phone reports as a network fault rather
than as the crypto failure it is.

A keyless daemon at boot is **not** itself the fault. The app-lock is engaged at launch, so the
daemon is spawned with no credentials on purpose and `StartSyncListenerOnEnable::handleUnlocked`
hands them over the moment the reader unlocks. What a restored desktop has is no key-file for
that handover to ever find, so the daemon stays keyless for the life of the process. The gate
now asks both halves, and the repair's `DeviceSyncEnabled` is what starts the listener properly
once an identity exists.

### The fourth reader, which was not a listener at all

Enumerating the *question* rather than the symbol found one more, and it is the same silent
loss rather than a visible refusal. `ImportSyncCapture::oweABackfill()` asked
`DeviceIdentityLoader::exists()` — the capture sink's old predicate — under a comment carrying
the capture sink's old premise: *a device that never enabled sync is left alone: it owes no
peer anything.*

An import on a restored device therefore committed, owed nothing, and reached no peer. That
path cannot fall back on the deferred queue: it captures rows by id in a dependency order, and
re-deriving that order later **is** the walk, which is why a keyless import opens a backfill
instead of queueing coordinates. With the backfill never opened there was no second pass at
all. It asks the standing now.

`MobileImportBootstrap` reads the row alone and is right to: it is a retry guard on a database
the same request minted, and asking the key-file there would mint beside a self row naming
another `device_id` — the thing `restoreSelfRow()` exists to prevent. It carries a comment
saying so, because it reads exactly like the others.

## Staging plaintext secrets

Both the identity file and the GDK keyring are written and read through
`Modules\Sync\Internal\Identity\SealedJsonFile`, which owns both directions. It is one
class rather than a pattern each caller retypes because the details below are the whole point
of it: a recipe whose value is that *every* step happens is exactly the kind that must not be
copied, and it had been copied three times — twice for reading, twice for writing — before one
of the copies was found to be missing the lock-down entirely.

- The temp file is created **inside the same 0700 directory** as the encrypted file — never
  `sys_get_temp_dir()`, which is world-traversable (`/tmp` at mode 1777).
- Its name carries `bin2hex(random_bytes(8))`. A fixed `.tmp` path let two concurrent
  stage-and-write operations, or a stale file from a crashed run, collide and silently
  overwrite each other.
- It is chmod'ed to `0600` immediately, and a failed chmod unlinks the file and throws rather
  than leaving readable secrets behind. The file encryptor's own path-based API renames its
  internal staging file into place at the process umask default, so the lock-down has to
  happen after it, before the plaintext is ever read back.
- File writes and reads are `@`-suppressed so the `=== false` check decides. Unsuppressed, a
  failure raises `E_WARNING`, which Laravel's error handler converts to an `ErrorException`
  before the comparison runs — so the guard never fired and the caller saw a type it was not
  looking for.
- The **sealed** destination is staged too, not just the plaintext. `writeSealed()` encrypts to
  a randomized `.tmp` sibling and renames it over the live path, exactly as
  `GdkKeyringService::writeKeyringFile()` does. `BackupEncryptor::encryptWithKey()` opens its
  destination `'wb'`, so sealing straight onto `<id>.enc` truncated the live file first: a write
  cut short left a key-file that decrypts to nothing, which reads back as
  `DeviceIdentityState::Unreadable` — and `generateAndPersist()` refuses to overwrite one of
  those, deliberately, so a device interrupted on its first run could never mint an identity
  again without the reader finding the retire action in settings.
- On any failure the staged sibling is unlinked before the exception leaves. That is the
  opposite of the keyring's deferred finalize, which keeps its `.tmp` on a rename failure
  because it is the only copy of a key: here the caller still holds the plaintext and will mint
  again, so debris beside the live path is exposure and nothing else.

## Why the KEK is not passphrase-hardened

The keyring and identity files are encrypted with `encryptWithKey()`, not the password-based
path. The KEK is 256 random bits, not a passphrase, so key-stretching buys nothing — and it
cost roughly 500 ms on every keyring read. `GdkKeyringService` opportunistically re-writes a
keyring it finds stored at password-hardening cost, once, using a memory-limit **threshold**
rather than an equality check on the current setting, so tuning the write cost can never turn
into a re-write loop.

Decrypted keyrings are memoised per process, keyed by user **and a fingerprint of the KEK
itself** — never by user alone. A rotated or re-wrapped key yields a different cache entry
rather than resolving to a stale keyring, and a withheld key never reaches the cache at all.
The cache exists because the projection codec calls `loadKeyring()` once per decrypted value:
a 164-row page paid 164 key derivations, minutes of libsodium, which blew
`max_execution_time` and wedged the single-threaded desktop server for every other request.

## Device names are exchanged, so they must not leak the hostname

`DeviceNameDetector` returns a neutral OS-family label — `Mac`, `PC`, `Linux` — or whatever a
platform source supplies. Never `php_uname('n')`: a hostname is very often the user's real
name, and this value is stored in `device_registry.name` and sent to peers.

It is a bare `Mac`, not `This device (Mac)`, for the same reason. The name travels, so the
qualifier read as the handset the user was holding. The self badge in the UI already marks
which row is this device.

## A passphrase change re-wraps, and must never fail the change

`RewrapGdkOnPassphraseChange` handles `AppLockPassphraseChanged`, which is dispatched
synchronously **after** the new PIN is already persisted. A re-wrap failure therefore cannot
be allowed to throw: it would leave the app-lock configuration half-updated over a problem in
a separate, independently recoverable store.

It swallows and logs instead — and raises a critical `SystemAlert`, because a silently failed
re-wrap leaves the epoch keys unopenable for a single-device user, which is unrecoverable
data loss with no other signal. The alert write is itself wrapped, so a database failure
there cannot re-break the committed passphrase change.

## See also

- [Pairing two devices without trusting the network](pairing-handshake.md).
- [Removing a device: revoke, rotate, fan out](device-removal-and-epoch-rotation.md).
- [Sync architecture](architecture.md).
