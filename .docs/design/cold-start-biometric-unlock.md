# Cold-start biometric unlock (mobile)

**Status:** the app-side path exists; the native enclave binding and its
on-device verification do not — see "What exists in code" and "What is not
built yet" below. Extends the LOCK-04 model.

**Decided (owner):** biometric is allowed to be a **full cryptographic root**
on mobile (see Decision 1).

---

## 1. Context — why cold-start biometric unlock does not exist today

The at-rest **data key (DK)** is the root secret that decrypts every
encrypted field. Its cryptographic root is the **PIN** (Argon2id KDF →
`pin_wrapped_key`). On the desktop/WebAuthn path, biometrics is *also* a real
unlock path, because enrollment persists a `biometric_wrap_secret` that wraps
the DK.

On **mobile**, biometric is only a **bool gate** over an *already-held* key
(`MobileLockScreen::biometricPrompt()` → on `true`, it re-reads the key via
`AppLockKeyService::release()`):

- Screen re-locks *within* a live session → Face ID lets you back in (the key
  is still session-held). ✅
- Cold start (app killed, or idle re-lock cleared the session key) → Face ID
  **cannot conjure the DK**; only the PIN re-derives it. This is by design
  (LOCK-04: PIN is the sole mobile root).

"Cold-start biometric unlock" = store a **biometric-gated wrap of the DK** in
the secure enclave so Face ID / Touch ID alone can recover the DK after a cold
start, with no PIN.

This builds directly on `SecureStorageKeyCustodian` (session-key custody in
the Keychain). That custodian holds the *unlocked* key; cold-start unlock adds
a *second, persistent, biometric-gated* entry that survives the session.

---

## Decision 1 — Biometric as a full cryptographic root (accepted)

Today's guarantee: *the DK cannot be recovered without knowledge of the PIN*
("something you know"). Promoting biometric to a root changes the guarantee to
*device + enrolled face/finger is sufficient* ("something you are / have").

Consequences to accept explicitly:

- A coerced/again-enrolled biometric, or an attacker who can add a fingerprint
  to an unlocked device, becomes a key-recovery path. This is mitigated by
  invalidate-on-enrollment-change (see §4), not eliminated.
- Security now depends on the OS secure enclave *and* on the access-control
  flags being correct. Getting those flags right is the whole ballgame (§2).

Recommendation regardless of "full root": keep a **PIN-required floor** — PIN
mandatory on first launch after install, after any OS biometry change, and
after N days (config, default 14). "Full root" then means "biometric alone
unlocks in the steady state," not "the PIN can be discarded." This is cheap
insurance and does not weaken the UX in practice.

---

## 2. What the installed plugins can bind to a biometric — the crux

The shipped native source in `mobile-app/vendor`:

| Plugin | File | What it actually does |
|---|---|---|
| SecureStorage (iOS) | `resources/ios/SecureStorageFunctions.swift:115` | Keychain item with `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`. **No** `SecAccessControl`, no `.biometryCurrentSet`, no `kSecUseAuthenticationContext`. → readable whenever the device is unlocked; **not** biometric-gated per read. |
| SecureStorage (Android) | `resources/android/SecureStorageFunctions.kt` | `EncryptedSharedPreferences` + `MasterKey(AES256_GCM)`. `MasterKey.Builder` does **not** call `setUserAuthenticationRequired(true)`. → key usable whenever the app runs; **not** auth-gated, no `BiometricPrompt` `CryptoObject`. |
| Biometrics | `Native\Mobile\Biometrics::prompt()` → `PendingBiometric` | Dispatches a `Completed` **event carrying a bool**. It is an authorization *signal*; it never wraps a `CryptoObject` and never binds key release to the enclave. |

**Conclusion:** As shipped, **neither plugin can cryptographically bind key
material to a biometric.** SecureStorage gives *device-unlock*-gated at-rest
storage; the biometrics plugin gives a *bypassable* bool. Combining "store DK
wrap in SecureStorage" + "gate retrieval behind `Biometrics::prompt()`" does
**not** produce enclave-bound key release — anything that can reach the PHP
path can read the wrap without the prompt.

That gives two implementation tiers:

### Tier A — enclave-bound

Requires native changes (fork the plugin or ship a small custom NativePHP
plugin):

- **iOS:** create the Keychain item with
  `SecAccessControlCreateWithFlags(nil, kSecAttrAccessibleWhenPasscodeSetThisDeviceOnly, [.biometryCurrentSet], …)` and read it with an `LAContext`. The
  enclave itself refuses to release the bytes without a fresh Face ID/Touch ID,
  and `.biometryCurrentSet` auto-invalidates the item if the enrolled set
  changes.
- **Android:** generate a Keystore **key pair** with
  `setUserAuthenticationRequired(true)` + `setInvalidatedByBiometricEnrollment(true)`, and unwrap through a `BiometricPrompt` `CryptoObject`. The Keystore
  refuses the private-key `Cipher` without a fresh biometric. A *secret* key
  would gate encryption too, and enrolment cannot pay that: the caller
  re-verifies the PIN for the live data key and `sodium_memzero`s it on the
  statement after the store, so a `Set()` that answered by event would be
  waiting on a key that no longer exists. Only the private half of an
  asymmetric key requires authentication, so `Set()` is synchronous and `Get()`
  is the one that prompts.

This needs a native shim — a Swift function, a Kotlin function and a PHP
facade — or an `accessControl: biometric` option upstream in
`nativephp/mobile-secure-storage`.

### Tier B — Pragmatic (no native changes). Weaker; document the limit

Store the DK wrap in the existing (device-unlock-gated) SecureStorage entry,
gate its *retrieval* behind `Biometrics::prompt()`. The bool is bypassable in
principle, but on a **locked, sandboxed, single-user device** the residual
threat is "someone holding the unlocked phone" — which the app already trusts
for the whole session. Acceptable as an interim if Tier A's native work is not
worth it yet. Must be labeled honestly in code + docs as "not enclave-bound."

**The choice is Tier A** — the entire security value of the feature is the
enclave binding. Tier B mostly buys UX, not security.

---

## 3. Key material — what goes in the biometric-gated entry

**Do not store the raw DK.** Mirror the desktop `biometric_wrap_secret`
design:

1. Generate a random 32-byte **biometric wrap secret** `BWS`.
2. `bioWrappedKey = AppLockKeyWrap::wrap(DK, BWS)` (existing XSalsa20-Poly1305).
3. Store the blob `BWS || bioWrappedKey` in the **biometric-gated** enclave
   entry (Tier A) — exactly the same blob format the desktop path already uses
   (`WebAuthnBiometricService`), so `extractDataKey()` is reusable verbatim.
4. On cold start: biometric unlocks the enclave → read blob → `extractDataKey`
   → DK → `LockStateManager::unlock()`.

Why a wrap secret instead of the raw DK: the DK is never duplicated as raw
bytes in a second place; the enclave holds only a wrapping secret + ciphertext,
matching the posture the codebase already reasons about. It also composes
cleanly with the per-device rekey (§4): a DK/epoch change just rewraps.

This is deliberately the **same primitive** the desktop biometric path uses —
no new crypto, only a new storage location + access-control policy.

---

## 4. Lifecycle — suggestions

| Event | Suggested behavior |
|---|---|
| **Enroll** (create the gated entry) | Opt-in, from the app-lock section of the settings screen. A fresh PIN entry is required, and it is that PIN which produces the DK being wrapped — nothing enrols from a key the session already holds. Write the `BWS \|\| bioWrappedKey` blob to the gated entry. |
| **PIN change** | The DK does not change on PIN change (only its PIN wrap does), so the biometric blob stays valid — **no rewrap needed**. (Confirm against `AppLockProvisioner` rewrap semantics before relying on this.) |
| **OS biometry change** (new finger/face enrolled) | Tier A: `.biometryCurrentSet` / `setInvalidatedByBiometricEnrollment(true)` **auto-invalidates** the entry. Detect the resulting read failure → fall back to PIN → re-enroll. This is the key anti-coercion property; do not use `.biometryAny`. |
| **Disable biometric unlock** | Delete the gated entry (`SecureStorage::delete`). |
| **Per-device rekey / epoch change** | A rekey changes the DK → the stored `bioWrappedKey` no longer unwraps to a usable key. Options: (a) rewrap eagerly on rekey while unlocked; (b) invalidate the entry on rekey and force one PIN unlock + re-enroll. Recommend (b) for simplicity and to keep rekey atomic — it degrades to "one PIN unlock after a rekey," which is rare. |
| **Remote device revocation** | The revoked device can no longer decrypt future epochs regardless; ensure the local gated entry is also deleted on a revocation signal so a recovered device can't cold-start into stale data. |
| **App uninstall / device restore** | `ThisDeviceOnly` + `WhenPasscodeSet` means the entry never migrates; a restored device simply falls back to PIN. Correct and desired. |

---

## 5. Fallback UX — options

The PIN pad stays rendered underneath in all options (as it is today); these
differ in what happens *around* biometric failure.

- **Option 1 — Silent fallback (recommended).** Auto-invoke biometric on
  mount; on cancel/fail/lockout/invalidation, do nothing visible — the user
  just uses the PIN pad already on screen. Matches today's
  `MobileLockScreen` behavior exactly; lowest surprise.
- **Option 2 — Explicit re-enroll prompt on invalidation.** Distinguish "user
  cancelled" (silent) from "entry invalidated by biometry change / rekey"
  (show a one-line banner: "Biometric unlock was reset — unlock with your PIN
  to re-enable"). More transparent about *why* Face ID stopped working;
  slightly more code + copy.
- **Option 3 — Biometric-first, PIN-on-tap.** Hide the PIN pad behind a
  "Use PIN instead" affordance and lead with biometric. Cleaner steady-state
  UI, but hides the always-available fallback — worse for the cold-start /
  lockout cases this feature is *about*. Not recommended for a finance app.

Recommendation: **Option 1** for the mechanism, plus the **Option 2** banner
*only* for the invalidation case (so a reset biometric isn't silently
mysterious). Skip Option 3.

---

## The transient slot and what bounds it

Android's `Get` cannot answer inline, so the prompt callback decrypts the blob,
parks it in a process-global slot inside `BiometricVaultFunctions`, and raises
`BiometricVault.Recovered`. Between those two moments a live wrap of the data
key sits in the app's memory with nothing holding it. Four things take it out
again, and none of them is sufficient alone:

| Bound | What it covers |
|---|---|
| Consume on read | `PollRecovered` empties the slot on the read that claims it, and drops it on a read that does not. |
| Slot-name match | The blob is stashed with the entry name it came out of, and released only to a poll naming that same name. |
| Deadline | `Handler.postDelayed` drops an unclaimed blob a few seconds after the dispatch; cancelled the moment it is claimed. |
| Stand-down | `CancelPrompt` takes the prompt down *and* drops the slot, on a PIN unlock and on every app lock. |
| `ON_STOP` | A backstop for a real backgrounding, no longer the guarantee. |

**Why the slot name has to make the return trip.** The wrap secret lives inside
the blob (`BiometricKeyBlobCodec`), so any well-formed blob unwraps to some
valid data key — `recoveredFrom()` cannot fail closed on one belonging to
somebody else. `BiometricKeyVault::slot()` puts the owning user id in the entry
name on the way out; `completePendingRecover(int $userId)` supplies the same
name on the way back, and a mismatch releases nothing. Household trust makes
the confidentiality half tolerable; the integrity half is not, because the
receiving session would go on to write rows encrypted under a key that is not
its own.

**Why the poll comes before the gates.** `onColdStartRecovered()` drains the
slot first and judges afterwards. The enclave released the blob before the
method ran, so a gate that returns without consuming leaves a live data key
resident with nothing left that has to happen — a later dispatch of the same
event claims it, and the PIN unlock that stamped `last_pin_unlock_at` in the
meantime has opened the very gate that refused it.

**Why `ON_STOP` is not enough.** A translucent activity, split-screen focus
loss and picture-in-picture all deliver `ON_PAUSE` and no `ON_STOP`, and an
idle re-lock happens with the app still in the foreground, where no lifecycle
edge fires at all. `ON_PAUSE` is deliberately *not* an edge here: on the OEMs
where the biometric sheet is its own activity it would drop the blob the prompt
just released. The deadline is what bounds every case instead.

**Why the observer is keyed on the activity.** `BiometricVaultFunctions` is a
Kotlin `object` and the process outlives its activity. A boolean latch left the
observer attached to a DESTROYED `LifecycleRegistry` and refused to attach the
live activity's, so `ON_STOP` became a no-op for the rest of the process; the
guard compares the `LifecycleOwner` instead, through a weak reference so the
dead activity is not held.

## One Keystore pair per slot

The Android entry is an envelope: a per-write AES-GCM content key, wrapped by
the public half of a Keystore RSA pair whose private half is the part
`setUserAuthenticationRequired(true)` gates. That pair is **per slot**
(`beatrax.biometric.vault.wrap.v3.` + the entry name), for two reasons:

- One shared alias made every reader's enrolment one another's. `forget()`
  deleted the single alias wholesale, so one reader's biometry change orphaned
  the others' entries — and their next `Get` did not answer `missing`, because
  the entry row was still there: the read path minted a replacement pair,
  prompted against it, and failed on a blob nothing could open, forever.
- The read path no longer mints. `Get` answers `missing` when the alias is
  absent and drops the entry with it, because a blob whose pair is gone is not
  an authentication that failed — it is nothing left to authenticate against.

`Delete` removes the pair as well as the row, and the pair goes first: a
deletion that cannot remove it leaves the entry in place and reports the key as
still held, rather than stranding a gated pair nothing points at.

**The envelope's associated data is the alias.** `updateAAD` on both `seal` and
`open` binds the ciphertext to the alias entitled to open it, which names the
format generation and the slot. There is no data-key epoch the Kotlin side can
compute for itself — the app's epoch counter is the sync keyring's, not the
app-lock data key's, and at cold start the app is locked. The rollback an epoch
would have caught is caught by the pair being deleted with the entry it sealed:
a blob captured before a rekey has no private key left to unwrap its content
key. The stored string's layout is unchanged; entries written by an earlier
build read as `missing` and are re-enrolled from a PIN unlock.

## Threat-model delta (summary)

| Property | Today (PIN root) | After cold-start unlock (Tier A) |
|---|---|---|
| DK recovery without PIN | Impossible | Possible via enrolled biometric (by design) |
| Stolen locked device | Safe (PIN needed) | Safe (enclave needs live biometric; PIN floor on biometry change) |
| Coerced re-enrollment | N/A | Mitigated by `.biometryCurrentSet` auto-invalidation, not eliminated |
| Attacker with unlocked device + PHP access | Cannot get DK from cold start | Tier A: still cannot (enclave-gated). Tier B: **can** (bool bypass) |

---

## What exists in code

The app-side path (fallback and logic paths; the enclave gate itself is
on-device UAT):

- `Modules/Auth/Public/Services/BiometricKeyBlobCodec` — wraps/unwraps the data
  key into the `BWS || wrapped-key` blob (reuses `AppLockKeyWrap`; the same
  primitive as the desktop path). A tampered, short or wrong-secret blob fails
  closed.
- `Modules/Mobile/Internal/Identity/BiometricKeyVault` — enroll / recover /
  clear over the enclave-gated entry, seam-testable; maps native outcomes to
  `BiometricRecoverResult` (recovered / pendingAsync / canceled / missing /
  unavailable). The slot name
  carries the owning user id and every method takes it as an argument: read
  from the session instead, one account's `store()` overwrote the other's key
  and a console or job caller threw rather than clearing.
- `Modules/Auth/Public/Services/AppLockKeyService::admitDataKey()` — the
  authorized admit point; provenance (a real enclave recover) is the trust gate.
- `Modules/Auth/Internal/Lock/ColdStartEnroller` — the one way an entry is ever
  created, on every platform. It takes a PIN, verifies it to obtain the DK,
  wraps that DK into `ColdStartVault`, zeroes the released copy, and marks the
  enrolment flag the lock screens read. An empty or wrong PIN, an unavailable
  vault or a refused write all leave nothing enrolled and the flag down.
  It has exactly one caller: the Enroll button on `AppLockSettingsSection`,
  which opens a PIN confirmation exactly as de-enrolling does. Enrolment is
  opt-in, and this is the only place the reader can opt in.
  `LockScreen::submit()` used to re-arm an empty vault with the PIN it had just
  verified, which was convenient and was not the ask: a correct PIN is proof of
  identity, not a request to enrol a fingerprint, and a reader who turned the
  lock off and on again found biometric unlock back on without choosing it.
  Turning the app lock off still forgets the entry — that is the security
  property, and it is unchanged — so what a disable/enable cycle now costs is
  one visit to the settings control.
  There were two enrolment controls before there was one: the PIN-gated one was
  mounted by no screen, and the one the phone actually rendered armed the vault
  from the session's own key and asked for nothing at all.
  `tests/Contracts/ANativeEnrolmentTakesAFreshPinArchTest.php` derives both
  halves rather than pinning them — that `ColdStartVault::enroll()` is reached
  from exactly one place outside the vault implementations, and that the place
  spends a PIN on the very key it stores.
- `MobileLockScreen::biometricPrompt()` — cold-start path: on a held key →
  straight through; else `vault->recover()` → `admitDataKey()` → redirect;
  missing/canceled/unavailable fall through to the PIN pad. Async (Android)
  handled by the event, see below.
- `MobileLockScreen::onColdStartRecovered()` — the Android leg. It drains the
  native slot with `completePendingRecover($userId)` *before* re-checking the
  enrolment flag and the PIN floor, so a refusal leaves nothing behind.
- `Modules/Mobile/Internal/Identity/StandTheBiometricCeremonyDownOnLock` —
  listens for `AppLockLocked`, which `LockStateManager::lock()` announces from
  the funnel every road to a lock passes through, and calls
  `BiometricKeyVault::cancelPrompt()`. The session drops its own handle on the
  data key there; nothing else dropped what the enclave had already released.
- `BiometricVault.IsAvailable` — the capability probe, one bridge function per
  platform behind `BiometricKeyVault::platformCanStore()`. It answers what the
  device can do right now and why, not which operating system it is running:
  `available`, `none_enrolled`, `no_hardware`, `hardware_unavailable`, plus
  `security_update_required` on Android / `no_passcode` on iOS, and
  `unreadable` when the bridge answered nothing. The refusal is written to the
  log at debug; a phone with nothing enrolled is a state of the world, not a
  fault, and the reader is shown the PIN pad rather than an affordance that
  cannot work.

## What is not built yet

1. **On-device verification, iOS** — the Tier A round-trip on an iPhone; the
   plugin is registered by path repo + `native:plugin:register`. Enrolment on an
   iPhone 12 mini returns `Keychain save failed (-25293)`, which is
   `errSecNotAvailable`.

   **Android was verified** (Galaxy A51, Android 13, 2026-09-09) on the build
   that preceded the per-slot Keystore pair, the envelope's associated data and
   the slot's deadline. Those three change what the enclave half does, so the
   round-trip below is the shape to re-run rather than a result that still
   stands. What it showed then: enrolment
   writes, a fingerprint releases the blob, and the app unlocks. The log carries
   the whole chain, and the replay case is what makes the rest of it mean
   something — the same `PollRecovered` call that unlocks on a full slot does
   nothing on a consumed one:

   | | native dispatch | `PollRecovered` | outcome |
   |---|---|---|---|
   | after a real fingerprint | `BiometricVault.Recovered` | yes | unlocked |
   | signal replayed from JS, no prompt | none | yes | stayed locked |

   The event payload is `{}` — the blob travels over the PHP bridge, never in
   the event.

Android async recover was item 1 of this list and is now in the tree: the Kotlin
`BiometricPrompt` wiring, `BiometricVault.PollRecovered` declared in
`nativephp.json` and consuming its slot on read, and `MobileLockScreen`
listening on `native:BiometricVault.Recovered`. That prefix is the correction
the wiring exposed — `NativeActionCoordinator.dispatch()` calls
`window.Livewire.dispatch("native:" + event, payload)`, so the bare
`cold-start-recovered` the handler used to listen for was a channel the device
could not raise, and nothing could notice while nothing raised anything.

Items 3 and 4 of this list — the enrollment control with the PIN floor, and the
`clear()` lifecycle hooks — were written after it and are in the tree: the
biometric row on `AppLockSettingsSection` behind `ColdStartEnroller`,
`MobileLockGateway::pinFloorDue()` with a 14-day floor, and
`ColdStartVault::forget()`. The list said otherwise for long enough to be worth
this paragraph.

## Decisions (owner, locked)

- **Tier A — enclave-bound.** The secure enclave enforces key release
  (SecAccessControl / CryptoObject); Tier B (bool-gated) is rejected.
- **PIN floor kept.** Biometric unlocks in the steady state, but the PIN is
  mandatory on first launch after install, after any OS biometry change, and
  every ~14 days (configurable). Not biometric-only-forever.
- **First-party plugin.** Keep `beatrax/mobile-biometric-vault`; do not fork
  the premium plugin or block on an upstream PR. Swap to an official one later
  if NativePHP ships biometric access control.
