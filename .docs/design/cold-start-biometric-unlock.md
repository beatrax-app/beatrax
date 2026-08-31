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
- **Android:** generate a Keystore key with
  `setUserAuthenticationRequired(true)` + `setInvalidatedByBiometricEnrollment(true)`, and unwrap through a `BiometricPrompt` `CryptoObject`. The Keystore
  refuses the `Cipher` without a fresh biometric.

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
| **Enroll** (create the gated entry) | Opt-in, from the mobile lock/settings screen, only while unlocked (DK in hand). Require a fresh PIN entry immediately before enrollment (proves knowledge, sets the PIN floor). Write the `BWS \|\| bioWrappedKey` blob to the gated entry. |
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
- `MobileLockScreen::biometricPrompt()` — cold-start path: on a held key →
  straight through; else `vault->recover()` → `admitDataKey()` → redirect;
  missing/canceled/unavailable fall through to the PIN pad. Async (Android)
  handled by the event, see below.

## What is not built yet

1. **Android async recover** — the `BiometricVault.Recovered` event handler in
   `MobileLockScreen` (the vault returns `pendingAsync` on Android), and the
   Kotlin `BiometricPrompt` wiring behind it.
2. **On-device verification** — the Tier A round-trip on a physical device,
   proving the enclave gates the read; the plugin is registered by path repo +
   `native:plugin:register`.
3. **Enrollment UX + PIN floor** — a settings toggle that calls
   `BiometricKeyVault::enroll($userId, $dataKey)` while unlocked after a fresh PIN entry;
   the "PIN mandatory after biometry change / every N days" cadence.
4. **Lifecycle hooks** — `clear()` on disable, on PIN reset re-enroll, and on
   rekey/revocation (invalidate-and-re-enroll).

## Decisions (owner, locked)

- **Tier A — enclave-bound.** The secure enclave enforces key release
  (SecAccessControl / CryptoObject); Tier B (bool-gated) is rejected.
- **PIN floor kept.** Biometric unlocks in the steady state, but the PIN is
  mandatory on first launch after install, after any OS biometry change, and
  every ~14 days (configurable). Not biometric-only-forever.
- **First-party plugin.** Keep `beatrax/mobile-biometric-vault`; do not fork
  the premium plugin or block on an upstream PR. Swap to an official one later
  if NativePHP ships biometric access control.
