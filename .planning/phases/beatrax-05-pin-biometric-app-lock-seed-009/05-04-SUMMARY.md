---
phase: 05-pin-biometric-app-lock-seed-009
plan: 04
subsystem: auth
tags: [app-lock, livewire, settings, pin, forgot-pin, tdd, D-11, D-21, D-23, LOCK-01, LOCK-04]

# Dependency graph
requires:
  - phase: 05-03
    provides: LockScreen, pin-pad partial, lock.js, AppLockMiddleware wired
  - phase: 05-02
    provides: AppLockProvisioner::enable(), PinVerificationService, auth routes
  - phase: 05-01
    provides: crypto primitives (PinHasher, AppLockKdf, AppLockKeyWrap), DB tables
provides:
  - AppLockProvisioner extended: changePin(), disable(), rewrapForNewPin()
  - AppLockSettingsSection Livewire component (enable/disable lock, set/change PIN, idle-timeout, D-23 modals)
  - app-lock-settings-section.blade.php view with exact UI-SPEC copy
  - settings-page.blade.php: mounts @livewire('auth.app-lock-settings-section')
  - auth.lock allow-list entry in CrossUserIsolationTest
affects: [05-05, 05-06, settings-page]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - AppLockProvisioner extended with three new methods using same sodium_memzero discipline as enable()
    - rewrapForNewPin() uses password_wrapped_key (D-21 recovery wrap) as the authoritative path
    - Livewire component: per-method service injection (Hasher, CurrentUser, AppLockProvisioner, DatabaseManager, Clock)
    - Account password used transiently via Hasher::check() + provisioner->enable(); cleared from props immediately
    - D-23 confirmation: disable + changePin require PIN via modal; idle-timeout is instant-apply (D-23 exempt)
    - Settings component pattern: mount() hydrates from user_app_lock_configs with updateOrInsert bootstrap

key-files:
  created:
    - Modules/Auth/Internal/Http/Livewire/AppLockSettingsSection.php
    - Modules/Auth/Resources/views/livewire/app-lock-settings-section.blade.php
    - Modules/Auth/tests/Feature/AppLockSettingsSectionTest.php
  modified:
    - Modules/Auth/Internal/Lock/AppLockProvisioner.php (added changePin, disable, rewrapForNewPin)
    - Modules/Auth/Providers/AuthServiceProvider.php (registered auth.app-lock-settings-section, removed TODO)
    - Modules/Core/Resources/views/livewire/settings-page.blade.php (added App Lock section card)
    - Modules/Auth/tests/Feature/ForgotPinFlowTest.php (replaced RED stub with full test suite, pint-fixed)
    - Modules/Auth/tests/Feature/CrossUserIsolationTest.php (added auth.lock allow-list entry)

key-decisions:
  - "rewrapForNewPin uses password_wrapped_key (not pin_wrapped_key) as the recovery path — only the password wrap survives a forgotten PIN"
  - "setPin() requires account password at enable time (D-21 recovery wrap); verified via Hasher::check, cleared from props immediately (T-05-17)"
  - "disable() and changePin() use per-method CurrentUser + AppLockProvisioner DI (no constructor DI — Livewire rule)"
  - "biometricEnrolled = false intentional stub; plan 05-05 wires enrollment; empty-state copy per UI-SPEC"
  - "auth.lock added to CrossUserIsolationTest allow-list (session-scoped, no foreign data rows)"
  - "Worktree test pattern: copy changed source files to main repo for Pest TestCase path resolution (established in 05-03)"

patterns-established:
  - "AppLockSettingsSection mirrors SettingsPage: mount() hydrate, per-method DI, instant-apply vs modal confirmation"
  - "D-23 pattern: modal boolean flags (confirmingDisable, confirmingChangePin) + per-action methods"

requirements-completed: [LOCK-01, LOCK-04]

# Metrics
duration: 12min
completed: 2026-06-11
---

# Phase 05 Plan 04: App Lock Settings Section Summary

**AppLockProvisioner extended with changePin/disable/rewrapForNewPin (sodium_memzero discipline, D-11/D-21 forgot-PIN recovery), AppLockSettingsSection Livewire component with PIN setup/change/disable and idle-timeout, D-23 confirmation modals, mounted on the Core settings page — ForgotPinFlowTest RED stub now GREEN**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-06-11T22:36:22Z
- **Completed:** 2026-06-11T22:48:00Z
- **Tasks:** 2 (+ additional CrossUserIsolationTest task)
- **Files created:** 3 (component, blade, test)
- **Files modified:** 5

## Accomplishments

### Task 1: Extend AppLockProvisioner

Extended `AppLockProvisioner` with three new methods that complete the PIN lifecycle:

- **`rewrapForNewPin(userId, accountPassword, newPin)`**: Recovers the data key via the password recovery wrap (D-21), then re-wraps it under the new PIN and updates `pin_hash` + `pin_wrapped_key`. The data key is identical before and after — no key loss (D-11). Wrong password returns false without mutation.
- **`changePin(userId, currentPin, newPin)`**: Verifies the current PIN hash first, then re-wraps the data key under the new PIN. Wrong current PIN returns false without mutation (D-23 asserted).
- **`disable(userId, pin)`**: Verifies PIN, then clears `lock_enabled`, `pin_hash`, `kdf_salt`, `pin_wrapped_key`, `password_wrapped_key`, `failed_attempts`, `locked_until`. Wrong PIN returns false without mutation.
- All three call `sodium_memzero()` on every derived wrap key and the data key (T-05-17 transient secret posture).
- `ForgotPinFlowTest` went from RED stub (1 failing assertion) to GREEN (4 tests, 30 assertions).

### Task 2: AppLockSettingsSection + Settings Page Mount

- **`AppLockSettingsSection.php`**: Final Livewire component with `mount()` hydrating from `user_app_lock_configs` (updateOrInsert bootstrap). Props: `lockEnabled`, `biometricEnrolled` (display stub), `idleTimeoutMinutes`, `newPin`, `confirmPin`, `currentPin`, `accountPassword`, modal flags.
  - `setPin()`: validates PIN ≥4 digits, confirms match, verifies account password via `Hasher::check()`, calls `provisioner->enable()`. Account password cleared immediately after use (T-05-17).
  - `setIdleTimeout()`: instant-apply write of `idle_timeout_minutes` (D-23 exempt, no PIN required).
  - `disable()`: requires current PIN — delegates to `provisioner->disable()`, flashes "Incorrect PIN." on wrong PIN.
  - `confirmChangePin()` / `changePin()`: D-23 modal flow; validates new PIN + confirmation; delegates to `provisioner->changePin()`.
- **`app-lock-settings-section.blade.php`**: Contains exact UI-SPEC copy:
  - Toggle description: "Replaces daily sign-in with a PIN. Sessions stay active for 30 days."
  - Idle label: "Auto-lock after"
  - Biometric empty-state: "Biometric unlock is not available on this device."
  - Disable modal CTAs: "Disable lock" / "Keep app lock"
  - Change PIN modal CTAs: "Change PIN" / "Keep PIN"
- **`AuthServiceProvider`**: registers `auth.app-lock-settings-section`, removes 05-02 TODO comment.
- **`settings-page.blade.php`**: adds App Lock section card with `@livewire('auth.app-lock-settings-section')`.
- **`AppLockSettingsSectionTest`**: 6 GREEN feature tests covering enable flow, short-PIN rejection, mismatch rejection, idle-timeout persistence, wrong-PIN disable rejection, correct-PIN disable.

### Additional: CrossUserIsolationTest allow-list

Added `auth.lock` to `ISOLATION_ROUTE_ALLOW_LIST` with justification comment. The guard test still fails on the 14 pre-existing uncovered routes — that is expected and not this plan's responsibility.

## Task Commits

1. **Task 1: AppLockProvisioner extensions + ForgotPinFlowTest GREEN** — `df90b3d`
2. **Task 2: AppLockSettingsSection + blade + settings mount + CrossUserIsolation** — `5ab9092`

## Deviations from Plan

### Auto-applied patterns

**1. [Rule 2 - Missing Critical] Account-password verification at enable time**
- **Found during:** Task 2 implementation
- **Issue:** The plan's action notes said "add a $accountPassword prop validated as required at enable time; verify it via Hasher->check against $user->password before calling enable()". Implemented exactly as described — this was planned, not an auto-fix.
- **Files modified:** `AppLockSettingsSection.php`

**2. [Rule 3 - Blocking] Worktree test execution: main repo file copies**
- **Found during:** Task 1 test verification
- **Issue:** Pest's `realpath()` resolves symlinks, causing TestCase path binding to fail when running tests from the worktree. The worktree has no vendor dir; the main repo's autoloader maps `Modules\Auth\` to the main repo path.
- **Fix:** Followed the established 05-03 pattern: copy changed source files to the main repo before running tests, sync pint fixes back to worktree. This is documented in 05-03-SUMMARY as "Worktree test execution: main repo physical file copies".
- **Note:** The `tests-autoload-prepend.php` helper file created during investigation was not committed (not needed once the copy pattern was applied).

## Known Stubs

| File | Stub | Reason |
|------|------|--------|
| `AppLockSettingsSection.php` | `biometricEnrolled = false` | Biometric enrollment UI is wired by plan 05-05; this plan provides the display-only slot and the empty-state copy per UI-SPEC |
| `app-lock-settings-section.blade.php` | Biometric row shows "Biometric unlock is not available on this device." | Intentional empty-state per UI-SPEC §3c — plan 05-05 replaces this with the enrollment toggle |

The stubs do not prevent this plan's goal from being achieved. LOCK-01 (user can enable the lock from settings) is fully functional. The biometric slot is a known forward reference to 05-05.

## Threat Flags

No new threat surfaces introduced beyond those in the plan's threat model (T-05-15 through T-05-18).

## Self-Check: PASSED

Created files exist:
- `Modules/Auth/Internal/Http/Livewire/AppLockSettingsSection.php` — FOUND
- `Modules/Auth/Resources/views/livewire/app-lock-settings-section.blade.php` — FOUND
- `Modules/Auth/tests/Feature/AppLockSettingsSectionTest.php` — FOUND

Commits exist:
- `df90b3d` — feat(05-04): extend AppLockProvisioner — FOUND
- `5ab9092` — feat(05-04): AppLockSettingsSection component — FOUND

Tests: 10/10 passed (ForgotPinFlowTest 4/4 + AppLockSettingsSectionTest 6/6)
PHPStan: Modules/Auth — No errors (level 10)
Pint: All modified PHP files — PASSED
