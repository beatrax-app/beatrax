---
phase: 05-pin-biometric-app-lock-seed-009
plan: "06"
subsystem: Desktop/Auth
tags: [app-lock, desktop, nativephp, biometric, touch-id, dev-console, lock-04, tdd]

# Dependency graph
dependency_graph:
  requires: [05-01, 05-02, 05-03, 05-04, 05-05]
  provides: [LOCK-03, LOCK-04]
  affects:
    - Modules/Desktop/Internal/Listeners
    - Modules/Desktop/Internal/Native
    - Modules/Desktop/Providers
    - Modules/Auth/Internal/Http/Livewire
    - Modules/Auth/Providers
    - Modules/DevMode/Resources/views

# Tech tracking
tech_stack:
  added: []
  patterns:
    - LockOnWindowHideOrClose: object $event type hint accepts both WindowHidden and WindowClosed via one handle() method (module boundary preserved — no NativePHP import in listener body)
    - NativeBiometricUnlock: isAvailable() guards nativephp-internal.running before touching System facade (safe no-op in CI/web)
    - DesktopKeyCustodian: System::encrypt/decrypt for OS keychain custody; canEncrypt() guard degrades gracefully to identity transform
    - AppLockKeyProbe: constructor-free Livewire component (method injection in render/lock); computes fingerprint in render() without a public property to avoid Livewire snapshot exposure (T-05-26)
    - TDD: RED stub in prior plan (LockOnWindowHiddenTest) turned GREEN by new listener; NEW test (AppLockKeyProbeTest) created RED→GREEN in same plan

# Key files
key_files:
  created:
    - Modules/Desktop/Internal/Listeners/LockOnWindowHideOrClose.php
    - Modules/Desktop/Internal/Native/NativeBiometricUnlock.php
    - Modules/Desktop/Internal/Native/DesktopKeyCustodian.php
    - Modules/Auth/Internal/Http/Livewire/AppLockKeyProbe.php
    - Modules/Auth/Resources/views/livewire/app-lock-key-probe.blade.php
    - Modules/Auth/tests/Feature/AppLockKeyProbeTest.php
  modified:
    - Modules/Desktop/Providers/DesktopServiceProvider.php
    - Modules/Auth/Providers/AuthServiceProvider.php
    - Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php
    - phpstan.neon

# Decisions
decisions:
  - "LockOnWindowHideOrClose uses object $event parameter to accept both WindowHidden and WindowClosed without importing NativePHP event classes — the NativePHP imports stay in DesktopServiceProvider (the registration site)"
  - "AppLockKeyProbe uses no constructor DI — Livewire's factory calls new Component() with no args; AppLockSettingsSection and LockScreen established the method-injection pattern"
  - "DesktopKeyCustodian.store/read omit null-guards on System::encrypt/decrypt because the facade @method declares string (non-nullable); a PHPStan-appeasement null check would create an identical.alwaysFalse error"
  - "AppLockKeyProbeTest verified by temporarily copying to main repo — worktree tests that use Livewire::test() cannot be fully run from the worktree context because Pest->extend()->in() bindings don't apply via artisan test from a worktree subdirectory; post-merge run confirms GREEN"

# Metrics
metrics:
  duration: "~40 min"
  completed: "2026-06-11"
  tasks_completed: "3 (Task 4 is human-verify checkpoint)"
  files_changed: 9
---

# Phase 05 Plan 06: Desktop Lock Triggers + LOCK-04 Proof Summary

Desktop window hide/close immediate-lock listener (D-06), macOS Touch ID native path (NativeBiometricUnlock), OS keychain custody (DesktopKeyCustodian), and the LOCK-04 dev-console probe (AppLockKeyProbe) proving the key-release gate end-to-end — Tasks 1–3 complete; Task 4 awaiting human verification.

## What Was Built

### Task 1 — LockOnWindowHideOrClose + DesktopServiceProvider wiring (commit 023c059)

`LockOnWindowHideOrClose` is a final listener that accepts `object $event` so both `WindowHidden` and `WindowClosed` route through the same `handle()` method. It injects only `AppLockKeyService` (Auth module Public contract) and `Session` — never any `Auth\Internal` class (T-05-28). `handle()` calls `keyService->withhold(session)` immediately, no grace delay (D-06, T-05-24).

Both events are registered in `DesktopServiceProvider::boot()` inside the `nativephp-internal.running` gate alongside the existing OS-notification subscriptions.

`LockOnWindowHiddenTest.php` (05-01 RED stub) is now GREEN: 2 tests, 4 assertions. The listener does not call any NativePHP facade, so no phpstan.neon allow-list entry is needed for it.

### Task 2 — NativeBiometricUnlock + DesktopKeyCustodian + phpstan allowlist (commit b1a4c8c)

`NativeBiometricUnlock` (final class, `Desktop\Internal\Native`) wraps `System::canPromptTouchID()` and `System::promptTouchID()`. `isAvailable()` returns false unless the NativePHP bundle is running AND the OS supports Touch ID — safe no-op in web/CI. `prompt()` returns the `System::promptTouchID()` bool; the caller (Auth module) is responsible for releasing the data key only on a `true` result (T-05-25). No `Auth\*` import in this class.

`DesktopKeyCustodian` (final class, `Desktop\Internal\Native`) wraps `System::encrypt/decrypt` (Electron safeStorage / OS keychain) for the unwrapped key at rest while unlocked. `store()` and `read()` both degrade gracefully when `System::canEncrypt()` is false — the Auth module's encrypted-session custody applies unchanged (D-20 fallback).

Both classes are added to the `Native\Desktop\Facades\(Menu|Window|System|Notification|App)` allow-list paths block in `phpstan.neon`. Both registered as singletons in `DesktopServiceProvider::register()`. PHPStan level 10 clean; Pint clean.

### Task 3 — AppLockKeyProbe + dev-overview mount + AppLockKeyProbeTest (commits b91ac72, 36a0f88)

`AppLockKeyProbe` (final `Livewire\Component`, no constructor) uses method injection in `render(AppLockKeyService, Session)` and `lock(AppLockKeyService, Session)`. `render()` calls `AppLockKeyService->release(session)` — if non-null, status is `'released'` and a fingerprint is computed as `substr(hash('sha256', $key), 0, 8)`; if null, status is `'withheld'` and fingerprint is null. The raw key is never passed to the view — only the fingerprint string (T-05-26, assertDontSee verified).

D-22 is documented inline: the lock gates per-session UI release only; background queue/scheduler workers retain the data key independently.

`app-lock-key-probe.blade.php`: theme-locked dark panel (`#0b1220` bg, JetBrains Mono), emerald `released` label + fingerprint, rose `withheld` label, "Lock now" + "Refresh" buttons, D-22 note for developers.

Registered as `auth.app-lock-key-probe` in `AuthServiceProvider::boot()`. Mounted via `@livewire('auth.app-lock-key-probe')` on `dev-overview-page.blade.php` (developer-gated surface, T-05-27).

`AppLockKeyProbeTest`: 6 tests, 14 assertions — all GREEN (verified by temporary copy to main repo per worktree PSR-4 constraint).

## Test Results

| Test file | Tests | Status |
|-----------|-------|--------|
| LockOnWindowHiddenTest (Feature, Desktop) | 2 | PASS (was RED stub 05-01) |
| AppLockKeyProbeTest (Feature, Auth) | 6 | PASS (new in 05-06) |
| Desktop\Feature\* (regression check) | 65 | PASS |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] AppLockKeyProbe used constructor injection (Livewire incompatible)**
- **Found during:** Task 3 GREEN testing
- **Issue:** Livewire's Factory calls `new Component()` with no arguments — constructor DI is unsupported; test threw `ArgumentCountError: Too few arguments to function AppLockKeyProbe::__construct()`
- **Fix:** Removed constructor, moved `AppLockKeyService` and `Session` to method parameters on `render()` and `lock()` — matching the established pattern in `LockScreen` and `AppLockSettingsSection`
- **Files modified:** `Modules/Auth/Internal/Http/Livewire/AppLockKeyProbe.php`
- **Commit:** 36a0f88

**2. [Rule 1 - Bug] PHPStan identical.alwaysFalse in DesktopKeyCustodian null guards**
- **Found during:** Task 2 PHPStan
- **Issue:** `System::encrypt/decrypt` facade declares `string` return type (non-nullable); comparing against `null` → `identical.alwaysFalse`
- **Fix:** Removed the `=== null` branches, kept only `=== ''` empty-string guard as the degradation signal
- **Files modified:** `Modules/Desktop/Internal/Native/DesktopKeyCustodian.php`
- **Commit:** b1a4c8c

### Infrastructure Note (not a plan deviation)

Running Livewire feature tests from a git worktree with the `Pest->extend()->in()` binding pattern doesn't apply correctly because `artisan test --filter` doesn't load the `tests/Pest.php` bootstrap file discovery properly from a worktree subdirectory path. Resolution: verified tests by temporarily copying the new files to the main repo and running `vendor/bin/pest` from the main repo root. Post-merge run via `vendor/bin/pest Modules/Auth/tests/Feature/AppLockKeyProbeTest.php` is the authoritative verification.

## Task 4: Awaiting Human Verification

Task 4 is a `type="checkpoint:human-verify"` task. Tasks 1–3 are complete and committed. Task 4 requires manual OS-integration verification that cannot be automated.

## Known Stubs

None. All implementation paths are fully wired.

## Threat Flags

No new threat surface beyond what the plan's threat model covers. All STRIDE mitigations implemented:

| Threat | Mitigation |
|--------|-----------|
| T-05-24 (window snapshot) | WindowHidden + WindowClosed → immediate withhold(), no grace |
| T-05-25 (Touch ID bypass) | NativeBiometricUnlock returns bool only; key release stays in Auth |
| T-05-26 (probe key disclosure) | AppLockKeyProbeTest assertDontSee($rawKey); fingerprint only |
| T-05-27 (probe on non-dev surface) | Mounted only on dev-overview-page behind `developer` middleware |
| T-05-28 (Desktop Internal access) | LockOnWindowHideOrClose imports only `AppLockKeyService` Public contract |

## Self-Check: PASSED

All created files verified present in worktree:
- Modules/Desktop/Internal/Listeners/LockOnWindowHideOrClose.php — FOUND
- Modules/Desktop/Internal/Native/NativeBiometricUnlock.php — FOUND
- Modules/Desktop/Internal/Native/DesktopKeyCustodian.php — FOUND
- Modules/Auth/Internal/Http/Livewire/AppLockKeyProbe.php — FOUND
- Modules/Auth/Resources/views/livewire/app-lock-key-probe.blade.php — FOUND
- Modules/Auth/tests/Feature/AppLockKeyProbeTest.php — FOUND

Commits confirmed in git log:
- 023c059 (Task 1) — FOUND
- b1a4c8c (Task 2) — FOUND
- b91ac72 (Task 3 RED) — FOUND
- 36a0f88 (Task 3 GREEN) — FOUND

---
*Phase: 05-pin-biometric-app-lock-seed-009*
*Plan: 06*
*Completed (Tasks 1-3): 2026-06-11*
*Task 4: Awaiting human verification*
