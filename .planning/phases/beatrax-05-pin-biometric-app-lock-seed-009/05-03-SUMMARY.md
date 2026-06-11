---
phase: 05-pin-biometric-app-lock-seed-009
plan: 03
subsystem: auth
tags: [app-lock, livewire, pin-pad, privacy-veil, alpine, broadcast-channel, idle-lock, tdd]

# Dependency graph
requires:
  - phase: 05-02
    provides: PinVerificationService, LockStateManager, AppLockMiddleware, auth.lock placeholder route
provides:
  - LockScreen Livewire component (/lock route, PIN entry, idle-lock dispatch, biometric stub)
  - pin-pad.blade.php partial (3-col grid, aria-labels, aria-live dot display, backoff label slot)
  - lock-screen.blade.php (calm-slate, app mark, PIN pad, biometric slot, Sign out)
  - resources/js/lock.js (Alpine beatraxLock store: veil, grace, BroadcastChannel, idle tracker)
  - #beatrax-veil element + window.beatraxIdleMs injection in app.blade.php
  - LockScreenTest.php (4 feature tests GREEN)
affects: [05-04, 05-05, 05-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Constructor-free Livewire component with DI params on action methods (mirrors ChangePasswordPage)
    - Livewire #[On] attribute for server-side event dispatch from client Alpine store
    - preg_match() === 1 for PHPStan level 10 boolean-safe regex match
    - Alpine.store init() lifecycle + BroadcastChannel for cross-tab lock coordination (D-05)
    - window.beatraxIdleMs injection pattern for per-session configuration (D-17)
    - Worktree test execution: physical file copies in main repo for Pest TestCase path resolution

key-files:
  created:
    - Modules/Auth/Internal/Http/Livewire/LockScreen.php
    - Modules/Auth/Resources/views/livewire/lock-screen.blade.php
    - Modules/Auth/Resources/views/livewire/partials/pin-pad.blade.php
    - Modules/Auth/tests/Feature/LockScreenTest.php
    - resources/js/lock.js
  modified:
    - Modules/Auth/Routes/web.php
    - Modules/Auth/Providers/AuthServiceProvider.php
    - resources/js/app.js
    - resources/views/layouts/app.blade.php

key-decisions:
  - "preg_match() === 1 (not !! or bare truthy) required by PHPStan level 10 booleanAnd/booleanNot rules"
  - "DatabaseManager injected via submit() DI params (not app() global) to satisfy larastanStrictRules.noGlobalLaravelFunction"
  - "Livewire::test() + $this->session() pattern instead of Livewire::actingAs()->withSession() — withSession() does not exist on LivewireManager"
  - "window.beatraxIdleMs defaults to 300000 ms (5 min); 05-04 will expose as per-user setting"
  - "Worktree test execution: main repo physical file copies required because Pest realpath() resolves symlinks and breaks TestCase path binding in tests/Pest.php"

patterns-established:
  - "PHP file copies to main repo for worktree test runs (Pest path resolution limitation)"
  - "Veil element placement: inside @auth block, before @endauth, after toast host"
  - "lock.js idle watch no-op: check typeof window.beatraxIdleMs === 'number' before starting interval"

requirements-completed: [LOCK-01, LOCK-03]

# Metrics
duration: 90min
completed: 2026-06-11
---

# Phase 05 Plan 03: Lock-Screen Surface + Privacy Veil Summary

**Calm-slate PIN pad (LockScreen Livewire page), privacy veil with 80ms drop (lock.js Alpine store), BroadcastChannel cross-tab sync, and idle-timeout server dispatch — delivering the user-visible LOCK-01 unlock experience and the client half of LOCK-03**

## Performance

- **Duration:** ~90 min
- **Started:** 2026-06-11T23:45:00Z
- **Completed:** 2026-06-12T01:15:00Z
- **Tasks:** 2 (TDD plan)
- **Files created:** 5 (LockScreen.php, lock-screen.blade.php, pin-pad.blade.php, LockScreenTest.php, lock.js)
- **Files modified:** 4 (web.php, AuthServiceProvider.php, app.js, app.blade.php)

## Accomplishments

### Task 1: LockScreen Livewire page + PIN pad blade + route swap (TDD RED/GREEN)

- **LockScreen** (final class extends Livewire\Component): public props `$pin`, `$flashMessage`, `$biometricAvailable`, `$biometricLabel`; mount() sets platform biometricLabel; pressDigit() / backspace() / clearPin() pad actions; submit() validates with PinVerificationService (redirects on success, flashes "Incorrect PIN. {N} attempts remaining." on failure); idleLock() #[On('idle-timeout-elapsed')] locks session + redirects; biometricPrompt() no-op stub for 05-05.
- **pin-pad.blade.php**: 3-col grid, 64px phone / 56px desktop targets, digits 1-9 + backspace + 0 + OK, aria-label per button (Accessibility contract), dot display with aria-live="polite", rose flash label with aria-live="assertive".
- **lock-screen.blade.php**: full-bleed bg-white dark:bg-slate-950, centered max-w-sm, safe-area padding, centered /icon.png 48x48, @include pin-pad, conditional biometric button, Sign out form (D-03 — exactly three actions).
- **Route swap**: `Route::get('/lock', LockScreen::class)->name('auth.lock')` (placeholder removed).
- **AuthServiceProvider**: `$livewire->component('auth.lock-screen', LockScreen::class)` registered; TODO comment removed.
- **LockScreenTest**: 4 tests GREEN (class exists, GET /lock → 200 + Sign out, correct PIN → redirect + session unlocked, wrong PIN → flash + pin reset).

### Task 2: Privacy veil + lock.js Alpine store

- **lock.js**: `Alpine.store('beatraxLock')` with `init()` lifecycle; showVeil/hideVeil toggle #beatrax-veil opacity + pointer-events + aria attributes; GRACE_MS=30000 grace timer; BroadcastChannel('beatrax:lock') for cross-tab coordination (D-05); IDLE_EVENTS set resets lastActivity; 10s interval dispatches 'idle-timeout-elapsed' to Livewire when idle >= beatraxIdleMs (D-17/T-05-13); no-ops when beatraxIdleMs absent.
- **app.js**: `import './lock.js'` added at top.
- **app.blade.php**: `#beatrax-veil` element (fixed inset-0 z-[9999], opacity-0 pointer-events-none default, transition-opacity duration-[80ms] motion-reduce:duration-0, /icon.png opacity-40); `window.beatraxIdleMs = 300000` injected inside @if($currentUser->isAuthenticated()) block.

## Task Commits

1. **Task 1 RED** — `660a0c7` (test — failing LockScreenTest)
2. **Task 1 GREEN** — `a139e50` (feat — LockScreen + blades + route + provider)
3. **Task 1 style fix** — `e077806` (style — Pint: ordered imports in LockScreenTest)
4. **Task 2** — `92632c8` (feat — lock.js + app.js + app.blade.php veil)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] preg_match() truthy usage fails PHPStan level 10**
- **Found during:** Task 1 — phpstan run after implementation
- **Issue:** PHPStan booleanAnd.rightNotBoolean and booleanNot.exprNotBoolean: `preg_match()` returns `int|false`, not `bool`. Using `preg_match(...)` in a boolean context fails strict mode.
- **Fix:** Changed to `preg_match(...) === 1` for the append guard and `preg_match(...) !== 1` for the validation guard.
- **Files modified:** `Modules/Auth/Internal/Http/Livewire/LockScreen.php`
- **Committed in:** `a139e50`

**2. [Rule 2 - Missing] app() global function forbidden by larastanStrictRules.noGlobalLaravelFunction**
- **Found during:** Task 1 — phpstan run
- **Issue:** `remainingAttempts()` used `app(DatabaseManager::class)` to get the DB manager without a constructor (Livewire constructor-free pattern). PHPStan level 10 reports this as a global function violation.
- **Fix:** Added `DatabaseManager $db` as a DI parameter to `submit()` and threaded it through to `remainingAttempts(int $userId, DatabaseManager $db)`.
- **Files modified:** `Modules/Auth/Internal/Http/Livewire/LockScreen.php`
- **Committed in:** `a139e50`

**3. [Rule 1 - Bug] Livewire test helper API mismatch: withSession() does not exist on LivewireManager**
- **Found during:** Task 1 — test run after GREEN implementation
- **Issue:** Initial test used `Livewire::actingAs($user)->withSession([...])` — but `Livewire::actingAs()` returns `LivewireManager`, which has no `withSession()` method.
- **Fix:** Changed to `$this->actingAs($user)` + `$this->session([...])` before `Livewire::test(LockScreen::class)`, which is the correct Pest TestCase pattern.
- **Files modified:** `Modules/Auth/tests/Feature/LockScreenTest.php`
- **Committed in:** `a139e50`

---

**Total deviations:** 3 auto-fixed (Rules 1 and 2)
**Impact on plan:** All corrections required for PHPStan level 10 compliance and test correctness. No scope creep.

## Infrastructure Note

**Worktree test execution pattern:** Pest resolves `realpath()` of test files — symlinks from the main repo to worktree files resolve to the worktree path, which doesn't match the `->in()` binding in `tests/Pest.php`. Solution: copy new files physically to the main repo's matching paths for test runs. These copies are not committed to the main branch (they come from the worktree merge). This mirrors the autoload-wrapper pattern documented in 05-01.

## Known Stubs

- `LockScreen::biometricPrompt()`: no-op method stub — plan 05-05 implements WebAuthn assertion flow. The button renders only when `$biometricAvailable === true` (default false), so it's unreachable in practice until 05-05 sets the flag from enrolled credentials.
- `window.beatraxIdleMs = 300000`: hardcoded 5-minute default — plan 05-04 will expose this as a per-user setting in AppLockSettingsSection.
- `LockScreen::mount()`: `$biometricLabel` hardcoded to 'Use Touch ID' — plan 05-05 will derive from the enrolled credential's platform.

## Threat Model Coverage

| Threat | Mitigation Implemented |
|--------|----------------------|
| T-05-11 OS screenshot of financial data | #beatrax-veil drops in ≤80ms on visibilitychange/blur; pointer-events set synchronously; motion-reduce:duration-0 keeps it instant |
| T-05-12 PIN digits cached by OS/password manager | PIN rendered as bullet dots in aria-live region, not `<input type="password">`; no autocomplete; digits never leave component until submit() |
| T-05-13 Idle client timer trusted as authority | Idle dispatch only asks server to lock via Livewire event; AppLockMiddleware + last_activity_at remain authoritative (D-17) |

## Self-Check: PASSED

All 9 created/modified files verified present on disk. All 4 task commits (660a0c7, a139e50, e077806, 92632c8) verified in git log. All 4 LockScreenTest assertions pass.
