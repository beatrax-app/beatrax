# The app-lock data key, and the data that cannot outlive it

The app lock is not only a screen gate. The PIN and the account password each wrap one
32-byte **data key**, and that key is the root of everything this install encrypts at rest:

- `sync/gdk/<user_id>.enc` — the GDK keyring, holding every epoch key and the blind-index
  key. Every encrypted column in the database is encrypted under an epoch key from that file.
- `sync/identity/<user_id>.enc` — this device's Ed25519 and X25519 secret keys.
- The per-device biometric wraps and the cold-start enclave blob, which wrap the same key
  again.

Only two copies of the data key are durable, and both live in one row of
`user_app_lock_configs`: `pin_wrapped_key` and `password_wrapped_key`, under a shared
`kdf_salt`. Nothing else on the machine can produce it.

So the invariant is short, and it is a data-safety one:

> **The data key must outlive every byte encrypted under it.** No supported action may leave
> encrypted data on disk with no wrap that still opens its key.

## Why this is a real path and not a theoretical one

Switching a setting off and on again is an ordinary thing for a user to do. Through the
settings screen alone, this sequence used to destroy data:

1. App lock on, at-rest encryption on. Notes, transaction descriptions, counterparty names
   and IBANs are ciphertext; both key files are wrapped under the data key.
2. Disable the app lock. `disable()` clears `kdf_salt`, `pin_wrapped_key` and
   `password_wrapped_key` — every durable copy of the key, in one statement.
3. Set a PIN again. `enable()` minted a fresh random key.

The database was intact throughout. The key that opened it was not. And the failure is
**silent**: `SensitiveColumnCodec` swallows the decryption failure by design, because a
locked phone holding synced rows it has no key for is an ordinary state. Every encrypted
column simply renders empty. For a single-device user there is no peer to re-sync from, so
the content is gone, and nothing anywhere says so.

## The guard is on the destructive step, not only the visible one

Minting in `enable()` is where the loss becomes permanent, but it is not where the key dies.
`disable()` is. By the time `enable()` runs, every wrap is already gone and there is nothing
left to recover — so a guard there can only refuse, never repair. Both are therefore in
place, and they are different in kind:

- **`disable()` refuses** while at-rest encryption is active for the user, returning
  `AppLockDisableResult::EncryptedDataDependsOnIt`. The PIN is verified first, so a wrong PIN
  still answers "incorrect PIN"; the refusal is only ever shown to someone who proved they
  could have gone through with it. Nothing is written.
- **`enable()` cannot mint over encrypted data.** Where at-rest encryption is active it takes
  the *existing* key or nothing: it unwraps `password_wrapped_key` with the account password
  the setup form already collects, and refuses if that will not open. There is no branch that
  mints while encrypted data exists.

`enable()` also delays deleting the biometric credentials until it holds a key, so a refusal
leaves the enrolments it would otherwise have destroyed on the way past.

## The wrap the row does not hold

Both wraps the invariant above names live in `user_app_lock_configs`, and both actions clear
their own. The **cold-start vault** holds a third one and lives outside the row: on the
desktop it is a safeStorage-encrypted file under `secrets/`, and `isEnrolled()` answers from
that file rather than from any column. `enable()` and `disable()` reset
`cold_start_biometric_enrolled` and delete the WebAuthn rows, and for a long time neither
touched the file — so a wrap of the data key survived the disable that destroyed every other
copy of it, and a later enable found the file still there.

That second half is the part with no error message. `LockScreen::submit()` enrols the vault
only when nothing is enrolled, so a stale file means the re-enabled lock never re-enrols,
`cold_start_biometric_enrolled` never goes back to true, and the native-unlock option is
simply not offered again — on a machine where it worked the day before.

`AppLockProvisioner::enable()` and `disable()` therefore call `ColdStartVault::forget()`
themselves, beside the WebAuthn delete each already did. It sits there rather than in the
callers so that every way in is covered by construction — the settings screen, and
`MobileLockGateway::enableAppLock()`, the first-run mobile import, which carried no forget of
its own and was safe only because the mobile vault happens to answer `isEnrolled()` from the
very column `enable()` resets. Change either side of that coincidence and the mobile path
breaks exactly as silently as the desktop one did.

That placement was not available at first, and the obstacle is worth naming because it is
easy to re-create. `MobileColdStartVault` took `MobileLockGateway` whole — for two
single-column reads of `cold_start_biometric_enrolled` — and the gateway is built from the
provisioner, so a provisioner that named the vault back closed a loop the container answered
by recursing until the process ran out of memory. `ColdStartEnrolmentFlag`
(`Auth/Public/Services/`) is that column and nothing else, and is what the vault takes now;
the gateway keeps `markColdStartEnrolled()` and `isColdStartEnrolled()` and delegates to the
same collaborator, so nothing that called it had to change.

## Why "turn the lock off" is not offered instead of refused

The alternative shape — keep `password_wrapped_key` through the disable, and re-prime the
session from the account password at every sign-in — is friendlier and was rejected on
evidence. `LockOnWindowHideOrClose` withholds the key on every desktop window hide or close,
ungated by `lock_enabled`, and with the lock off there is no unlock screen to put it back.
The first time the user closed the window, every encrypted column would go blank until the
next sign-in — which, on a 30-day session, can be weeks away. That trades one silent-blank
path for another.

The permanence is inherited, not invented here. At-rest encryption is a one-way migration:
`EncryptionMigrationService` has no decrypt-back path, and the pre-migration snapshot is
deleted on success. Once the data is encrypted it stays encrypted, so the key it needs has to
stay too — and the app lock is where that key lives.

## Four states, named so a screen can say which

`AppLockProvisioner::keyState()` answers with `AppLockKeyState`:

| State | Meaning |
|---|---|
| `Absent` | No wrap holds a data key, and nothing on this device needs one. A first-time enable mints, which is correct. |
| `Held` | The config row still wraps the data key, and both wraps open. |
| `RecoveryUnreadable` | The PIN wrap opens; the account-password wrap does not, because that password was replaced. |
| `Stranded` | Data is encrypted under a key no wrap here still holds. |

There is deliberately no `Locked` case. This reads the durable wraps in the database, which a
locked session still has; session availability is `AppLockKeyService::release()`'s question,
not this one.

`Stranded` is the app-lock side of the same accident
[the device identity key file names `Unreadable`](../sync/device-identity-key-files.md): there,
a key file survives the database that wrapped its key; here, encrypted data survives the wrap
that held it. Both are ordinary states of a real install rather than corruption, and both are
reported rather than repaired on sight.

## An install can already be stranded, and it is told

A user who disabled the app lock before this guard existed is in exactly that state:
`sync_encryption_state.current_epoch` set, and no app-lock wrap left. It is fully visible from
the Auth side alone — at-rest encryption enabled while the lock is off — and it is reported
twice:

- **On sign-in.** `primeSessionAfterLogin()` raises a critical
  `auth.lock.key_material_stranded` system alert, once per unacknowledged row so a daily
  sign-in is not a daily alert. Sign-in is the moment the app would otherwise come up blank
  and say nothing.
- **On the settings screen.** `AppLockSettingsSection::mount()` puts the same explanation on
  screen, and `setPin()` refuses before asking for a password no answer to which would change
  the outcome.

Nothing tries to repair it. Minting a fresh key would not help: `current_epoch` still points
at an epoch whose key sits in a keyring nobody can open, so the old columns stay unreadable
and no new sensitive write can be sealed either. The only real recovery is a peer that still
holds the key, which is what the copy tells the user to do.

## What was always safe, and stays safe

Neither PIN-recovery path touches the data key:

- `rewrapForNewPin()` — the "Forgot your PIN?" reset — unwraps the **same** data key with the
  account password and re-wraps it under the new PIN.
- `changePin()` does the same from the current PIN, and additionally dispatches
  `AppLockPassphraseChanged` so the GDK keyring is re-wrapped.

Both rotate a wrapping key, never the wrapped one. The advice the reset screen gives is
sound; it was disable-then-enable that was not.

## The recovery wrap is built from the password, so a password change has to carry it

`password_wrapped_key` wraps the data key under `KDF(account password, salt)`. Change the
account password and that blob stops opening — silently, because nothing reads it until the
day a forgotten PIN needs it. That is the worst possible moment to find out, and the advice
the reset screen gives at that point (disable and set the lock up again) needs the PIN the
reader came there without, and is refused outright once at-rest encryption is on.

There are exactly three ways the stored password changes, and only one of them holds the old
password at the moment it runs:

| Path | Holds the old password | What it does |
|---|---|---|
| `ChangePasswordPage::submit()` | Yes | `rewrapRecoveryKey()` — unwraps with the old, re-wraps under the new, **same salt** (the PIN wrap shares it). |
| `ResetPasswordAction` (recovery code) | No | `markRecoveryWrapStale()`. |
| `ManageUserPage::setPartnerPassword()` | No | `markRecoveryWrapStale()` against the partner. |

The forced password change that `setPartnerPassword()` schedules cannot repair it either: the
partner's "current password" at that point is the temporary one, not the one the wrap was
built from.

### Stamped, not cleared

`markRecoveryWrapStale()` writes `password_wrap_stale_at` and leaves the blob alone. It still
opens under the password it was built from, so clearing it would foreclose the one thing that
could still read it — the same reasoning that makes an unopenable identity key file
[retired rather than deleted](../sync/device-identity-key-files.md). The stamp is what makes
the state nameable: `keyState()` reports `RecoveryUnreadable`, and a critical
`auth.lock.recovery_wrap_stale` alert is raised once per unacknowledged row.

`RecoveryUnreadable` is a fourth case rather than a shade of `Held` because the difference
only shows up on the day it matters. The PIN wrap still opens; there is simply nothing behind
it. Folding the two together is exactly the silence being fixed.

### Re-linking is the repair, and it costs both credentials

`relinkRecoveryWrap()` takes the PIN and the account password together: the PIN produces the
data key, the account password becomes its new wrap. No other moment holds both — a sign-in
has the password but not the key, a PIN unlock has the key but not the password — so the
settings screen asks for them at once, in a row that renders only while the wrap is stale.

## The permanence is stated where it is agreed to

Because the lock can never come off once at-rest encryption is on, that consequence belongs
in front of the irreversible step, not at the disable button that later refuses. It is said
in both places encryption starts:

- The optional single-device offer has a confirm step, so the line joins the honest-disclosure
  block there beside the amounts and search-index carve-outs — the register that block already
  established, rather than a second danger alert competing with the no-recovery warning.
- Enabling sync auto-activates encryption with **no** confirm step by design, so the sync
  toggle's own description carries the same sentence. That description is the only thing its
  reader sees before the switch.

`DevicesAndSyncEncryptionUiTest` pins both, the same way it pins the other disclosures.

## See also

- [Auth architecture](architecture.md) — the key-wrapping model and session custody.
- [Device identity and its key file](../sync/device-identity-key-files.md) — the same
  lifetime problem from the file's side.
- [Which columns are encrypted at rest](../sync/sensitive-columns-at-rest.md) — what is lost
  when the keyring cannot be opened.
