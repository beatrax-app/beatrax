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
retired with it — more than one settings button may decide.

## Staging plaintext secrets

Both the identity file and the GDK keyring are written and read through the same pattern, and
the details are the point:

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
