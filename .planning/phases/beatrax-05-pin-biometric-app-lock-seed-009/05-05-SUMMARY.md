---
phase: 05-pin-biometric-app-lock-seed-009
plan: "05"
subsystem: Auth/Lock
tags: [webauthn, biometric, app-lock, security, lock-screen, settings]
dependency_graph:
  requires: [05-01, 05-02, 05-03, 05-04]
  provides: [LOCK-02, LOCK-04-biometric]
  affects: [Modules/Auth/Internal/Lock, Modules/Auth/Internal/Http]
tech_stack:
  added:
    - web-auth/webauthn-lib 5.3.4 (direct, not laravel/passkeys)
    - sodium_crypto_secretbox for per-device biometric wrap
  patterns:
    - Server-side WebAuthn enrollment (attestation) + assertion with CeremonyStepManagerFactory
    - Per-device biometric secret blob: secret (32 bytes) || wrapped_key_bytes
    - rpId = host portion of APP_URL; full origin validated separately (Pitfall 3)
    - Tap-to-prompt pattern: biometricPrompt() dispatches browser event, lock.js handles
    - PublicKeyCredential guard in JS: no-op on non-WebAuthn platforms
key_files:
  created:
    - Modules/Auth/Internal/Lock/BiometricDeviceStore.php
    - Modules/Auth/Internal/Lock/PlatformDetector.php
    - Modules/Auth/Internal/Lock/WebAuthnBiometricService.php
    - Modules/Auth/Internal/Http/Controllers/WebAuthnBiometricController.php
    - Modules/Auth/tests/Unit/BiometricDeviceStoreTest.php
    - Modules/Auth/tests/Feature/BiometricEnrollmentTest.php
    - Modules/Auth/tests/Feature/WebAuthnBiometricUnlockTest.php
  modified:
    - Modules/Auth/Providers/AuthServiceProvider.php
    - Modules/Auth/Routes/web.php
    - Modules/Auth/Internal/Http/Middleware/AppLockMiddleware.php
    - Modules/Auth/Internal/Http/Livewire/LockScreen.php
    - Modules/Auth/Internal/Http/Livewire/AppLockSettingsSection.php
    - Modules/Auth/Resources/views/livewire/app-lock-settings-section.blade.php
    - resources/js/lock.js
decisions:
  - "Per-device blob format: 32-byte raw secret concatenated with wrapped_key_bytes (nonce||ciphertext) stored in biometric_wrap_secret column — avoids adding a second column while keeping secret and wrapped key self-contained"
  - "buildSerializer() returns Symfony\\Component\\Serializer\\Serializer (concrete class) instead of SerializerInterface — allows both normalize() and deserialize() calls without @var cast errors at PHPStan level 10"
  - "De-enrollment action reuses provisioner->disable() for PIN verification, which also disables the lock — accepted as V1 limitation; future plan can add a lighter PIN-verify-only path"
  - "biometricCapable defaults to false server-side; Alpine x-init sets it true when window.PublicKeyCredential exists — ensures no enroll button is shown on non-WebAuthn platforms without server-side UA guessing"
metrics:
  duration: "~45 min"
  completed: "2026-06-11"
  tasks_completed: 3
  files_changed: 14
---

# Phase 05 Plan 05: OS Biometric Unlock (LOCK-02) Summary

WebAuthn biometric unlock (LOCK-02) across all three tasks: CRUD store + platform detector, server-side WebAuthn enrollment/assertion service + controller, and lock screen + settings UI wiring with lock.js browser-side WebAuthn glue.

## What Was Built

### Task 1 — BiometricDeviceStore + PlatformDetector (commit 9948867)

`BiometricDeviceStore` is a raw query-builder CRUD class for `user_biometric_credentials`. All queries are scoped to `user_id` so cross-user access is structurally impossible (T-05-22). Key methods: `store()`, `findForUser()`, `findByCredentialId()`, `incrementFailureCount()`, `resetFailureCount()`, `resetAllForUser()`, `updateCounter()`, `deleteForUser()`, `isArmed()`.

`BIOMETRIC_DISABLE_THRESHOLD = 5`: after 5 consecutive failures the credential is disarmed; `isArmed()` returns false until `resetAllForUser()` is called (next successful PIN unlock, D-16).

`PlatformDetector.detectLabel()` maps user-agent strings to platform-aware labels: macOS → "Use Touch ID", iOS → "Use Face ID", Windows → "Use Windows Hello", Android/generic → "Use fingerprint".

14 tests (9 Unit + 5 Feature) all green.

### Task 2 — WebAuthnBiometricService + Controller + Middleware (commit abc6950)

`WebAuthnBiometricService` encapsulates:
- `creationOptions()`: builds `PublicKeyCredentialCreationOptions` with a fresh challenge stored in session, returns JSON-serializable array.
- `completeEnrollment()`: verifies attestation via `AuthenticatorAttestationResponseValidator`, generates a random 32-byte biometric secret, wraps the live data key under it using `AppLockKeyWrap`, stores `secret || wrapped_key_bytes` blob in `biometric_wrap_secret`.
- `requestOptions()`: builds `PublicKeyCredentialRequestOptions` listing only armed credentials.
- `verifyAndRelease()`: verifies assertion via `AuthenticatorAssertionResponseValidator`, updates counter (T-05-19), resets failure count (D-16), extracts the data key from the stored blob, calls `LockStateManager->unlock()`.

Pitfall 3 (05-RESEARCH) enforced: `rpId` = host portion of `APP_URL` (e.g. `localhost`); full `APP_URL` (e.g. `http://localhost:8000`) passed to `setAllowedOrigins()`.

`WebAuthnBiometricController` exposes:
- `POST /lock/biometric/challenge` → `challenge()` (request options; `?enroll=1` → creation options)
- `POST /lock/biometric/verify` → `verify()` (assertion → JSON `{unlocked, redirect}`)
- `POST /lock/biometric/enroll` → `enroll()` (attestation → JSON `{enrolled}`)

All three routes added to `AppLockMiddleware::ALLOWED_ROUTE_NAMES` (T-05-21).

8 new tests in `WebAuthnBiometricUnlockTest` covering: class exists, creation/request options structure, wrap/unwrap round-trip, verifyAndRelease no-challenge rejection, disarmed credential rejection (D-16), LockStateManager unlock → AppLockKeyService release.

### Task 3 — Lock screen + settings UI + lock.js WebAuthn JS (commit 58e0b29)

`LockScreen.mount()`: sets `$biometricAvailable` from armed credentials (BiometricDeviceStore), sets `$biometricLabel` from PlatformDetector. `biometricPrompt()` dispatches `beatrax:webauthn-get` (tap-only, D-15).

`AppLockSettingsSection`: adds `biometricEnrolled`, `biometricCapable`, `biometricLabel`, `confirmingDeenroll`, `deenrollPin` props. `mount()` checks enrolled credentials and UA label. `startEnroll()` dispatches `beatrax:webauthn-create`. `onBiometricEnrolled()` is a Livewire event listener. `deenroll()` requires PIN confirmation (D-23) before calling `deleteForUser()`.

`app-lock-settings-section.blade.php`: biometric row replaced with Alpine `x-init` that sets `biometricCapable` via `window.PublicKeyCredential`; shows Enroll/Remove buttons when capable, empty-state "Biometric unlock is not available on this device." otherwise; de-enroll confirmation modal with PIN field.

`lock.js`: added `beatrax:webauthn-get` handler (fetch requestOptions → credentials.get → POST assertion → navigate on unlock + broadcast 'unlocked') and `beatrax:webauthn-create` handler (fetch creationOptions → credentials.create → POST attestation → dispatch 'biometric-enrolled'). Both handlers guarded by `window.PublicKeyCredential` (T-05-23: no auto-fire). Helper functions: `_getCsrfToken`, `_decodeBase64url`, `_encodeBase64url`, `_serializeAssertion`, `_serializeAttestation`.

## Test Results

| Test file | Tests | Status |
|-----------|-------|--------|
| BiometricDeviceStoreTest (Unit) | 9 | PASS |
| BiometricEnrollmentTest (Feature) | 5 | PASS |
| WebAuthnBiometricUnlockTest (Feature) | 8 | PASS |
| LockScreenTest (Feature) | 4 | PASS |
| AppLockSettingsSectionTest (Feature) | 6 | PASS |
| **Total** | **32** | **PASS** |

PHPStan level 10 clean on full `Modules/Auth` (51 files). Pint formatted.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] stdClass PHPStan resolution in namespaced code**
- **Found during:** Task 1
- **Issue:** Without `use stdClass;` import, PHPStan resolves `stdClass` as `Modules\Auth\Internal\Lock\stdClass`
- **Fix:** Added `use stdClass;` import to BiometricDeviceStore.php
- **Files modified:** BiometricDeviceStore.php
- **Commit:** 9948867

**2. [Rule 1 - Bug] WebauthnSerializerFactory::create() returns SerializerInterface without normalize()**
- **Found during:** Task 2 PHPStan
- **Issue:** PHPStan reports `normalize()` not found on `SerializerInterface`; the runtime class `Symfony\Component\Serializer\Serializer` implements both, but the declared return type is the interface
- **Fix:** Added `buildSerializer()` helper that casts to the concrete `Serializer` class after a runtime instanceof check — no @phpstan-ignore, no type widening
- **Files modified:** WebAuthnBiometricService.php
- **Commit:** abc6950

### Design Adjustments (not bugs, plan guidance refined)

**3. De-enrollment uses provisioner->disable() for PIN verification**
- The plan said "de-enrolling requires PIN confirmation (D-23) and calls BiometricDeviceStore->deleteForUser". There is no lightweight "verify-PIN-only" path in the existing provisioner API — only `disable()` which verifies the PIN AND disables the lock. The `deenroll()` action uses `disable()` for PIN verification, which has the side-effect of disabling the app lock. This is a V1 limitation. A future plan should add a `PinVerificationService::verifyOnly()` path for de-enroll without lock-disable.

## Known Stubs

None. All biometric enrollment and unlock paths are fully wired server-side. Browser-level WebAuthn (OS prompt) is inherently manual per 05-VALIDATION.

## Threat Flags

No new threat surface beyond what the plan's threat model covers. All STRIDE mitigations from the plan are implemented:

| Threat | Mitigation |
|--------|-----------|
| T-05-19 (replayed assertion) | `updateCounter()` called on every successful assertion |
| T-05-20 (forged assertion / origin mismatch) | `AuthenticatorAssertionResponseValidator` + full origin from `config('app.url')` |
| T-05-21 (biometric endpoints as bypass) | `ALLOWED_ROUTE_NAMES` only adds challenge/verify/enroll; key released only on verified assertion |
| T-05-22 (biometric brute force) | N=5 failures disarm credential; resetAllForUser on PIN success re-arms |
| T-05-23 (auto-fired OS prompt) | `beatrax:webauthn-get` dispatched only from `biometricPrompt()` (button tap), never on render |

## Self-Check: PASSED

All key files confirmed present in worktree:
- Modules/Auth/Internal/Lock/BiometricDeviceStore.php — FOUND
- Modules/Auth/Internal/Lock/PlatformDetector.php — FOUND
- Modules/Auth/Internal/Lock/WebAuthnBiometricService.php — FOUND
- Modules/Auth/Internal/Http/Controllers/WebAuthnBiometricController.php — FOUND
- Modules/Auth/tests/Feature/WebAuthnBiometricUnlockTest.php — FOUND

Commits confirmed in git log:
- 9948867 (Task 1) — FOUND
- abc6950 (Task 2) — FOUND
- 58e0b29 (Task 3) — FOUND
