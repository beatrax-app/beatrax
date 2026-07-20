# Spike findings — biometric-gated enclave entry (Tier A)

**Question the spike answers:** can we gate *key release* on the secure
enclave (not a bypassable bool), and does the premium `mobile-secure-storage`
plugin already do it? **Verdict: Tier A is feasible, but needs a first-party
native plugin — the premium plugin does not gate on biometrics.**

Prototype lives in `mobile-app/nativephp-plugins/biometric-vault/` (iOS Swift +
Android Kotlin + PHP facade + manifest), modeled byte-for-byte on the installed
plugin's structure.

## What I confirmed from the installed plugin's source

- `mobile-secure-storage` iOS writes items with
  `kSecAttrAccessibleWhenUnlockedThisDeviceOnly` → readable whenever the device
  is unlocked. **No biometric gate.**
- `mobile-secure-storage` Android uses `EncryptedSharedPreferences` + `MasterKey`
  with **no** `setUserAuthenticationRequired` → usable whenever the app runs.
- `mobile-biometrics` `prompt()` dispatches a `Completed` event carrying a bool
  → an authorization signal, never a `CryptoObject`. Bypassable.

So combining "store in SecureStorage" + "gate on the bool" is **not**
enclave-bound. Tier A needs its own native access-control path — hence this
plugin.

## The core architectural finding: iOS is synchronous, Android is not

| | iOS | Android |
|---|---|---|
| Mechanism | `SecAccessControl(.biometryCurrentSet)` + `LAContext` on `SecItemCopyMatching` | Keystore key `setUserAuthenticationRequired(true)` + `BiometricPrompt(CryptoObject)` |
| Blocking? | **Yes** — the OS blocks the thread on the Face ID sheet, so `get()` returns the value inline | **No** — `BiometricPrompt` is async, needs a `FragmentActivity`, delivers via callback |
| Bridge shape | Synchronous `get()` → `{value, authenticated}` | `get()` → `{async:true}`, value arrives via a `BiometricVault.Recovered` **event** |
| Anti-coercion | `.biometryCurrentSet` invalidates on enrollment change | `setInvalidatedByBiometricEnrollment(true)` |
| Enroll (write) | plain keychain add (no prompt) | **also** needs a prompt (the encrypt Cipher is auth-gated) |

**This asymmetry is the thing S2 must design around.** The `BiometricKeyVault`
contract must be **async-shaped** (a `recover()` that resolves via event/promise)
so the same PHP call site works on both platforms — even though iOS *could* be
synchronous. Don't build a synchronous contract that only fits iOS.

Concretely for S2:
- `BiometricKeyVault::enroll(dataKey)` — store the wrap blob (iOS: inline;
  Android: prompt-then-store).
- `BiometricKeyVault::recover()` — dispatch the prompt; on success the DK-wrap
  comes back (iOS inline, Android via `BiometricVault.Recovered`); Livewire
  listens for the event on Android and completes the unlock in a second round
  trip. `MobileLockScreen::biometricPrompt()` already dispatches + waits in an
  event-friendly way, so this fits.
- `clear()` — delete the entry / Keystore alias.

## Status of the prototype code

- **iOS `BiometricVaultFunctions.swift`** — complete and, per standard Keychain
  patterns, correct: `SecAccessControlCreateWithFlags(.biometryCurrentSet)` on
  add, `kSecUseAuthenticationContext` on read, explicit `errSecUserCanceled` /
  `errSecAuthFailed` handling so a cancel is NOT mistaken for "no key". Needs an
  on-device compile + run.
- **Android `BiometricVaultFunctions.kt`** — the **Keystore key config is
  complete and correct** (the security-critical part). The `BiometricPrompt`
  wiring is a documented skeleton: it needs (a) the host `FragmentActivity`
  handle NativePHP exposes and (b) the plugin event-emit API, neither of which
  can be compiled/verified from here. This is the remaining native work S2
  budgets for.

## On-device verification steps (the actual proof — owner runs these)

1. Wire the plugin: add a path repository for
   `mobile-app/nativephp-plugins/biometric-vault` to `mobile-app/composer.json`,
   `composer require beatrax/mobile-biometric-vault:@dev`, then
   `php artisan native:plugin:register beatrax/mobile-biometric-vault`.
2. Build to a real device (`native:run` — simulator has no Secure Enclave/
   biometrics; must be physical, per the on-device testing notes).
3. iOS acceptance:
   - `BiometricVault::set('probe', 'hello')` → then `get('probe')` must present
     Face ID and return `hello` on success.
   - Cancel the Face ID sheet → `get()` returns `{canceled:true}`, NOT the value
     and NOT `missing`.
   - Enroll a new face/finger in iOS Settings → `get('probe')` must now fail
     (item invalidated) → proves `.biometryCurrentSet`.
4. Android acceptance: same three, via the `BiometricVault.Recovered` /
   `BiometricVault.Failed` events once the prompt wiring is completed.

**Gate for S2:** iOS acceptance passing = Tier A proven on the platform that
matters most; Android prompt wiring is the one remaining native task. If the
owner prefers not to maintain native code, the fallback is Tier B (bool-gated
over device-unlock storage) — weaker, documented in the design doc, no native.
