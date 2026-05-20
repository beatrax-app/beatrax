---
phase: 12-multi-user-activation
plan: 03
subsystem: auth
tags: [auth, fortify, login, livewire, session]

requires:
  - phase: 12-01
    provides: Modules/Auth module skeleton + noAuthFacadeOrHelper allow-list
  - phase: 12-02
    provides: users.username schema reshape + reshaped User model
provides:
  - Username-based Fortify authentication pipeline (no rate limiter, no email features)
  - Working /login Livewire page + /logout route
  - LoginAction + LogoutAction on the noAuthFacadeOrHelper allow-list
  - FortifyServiceProvider relocated into Modules/Auth/Internal/Fortify
affects: [12-04, 12-05, 12-07]

tech-stack:
  added: []
  patterns:
    - "Username-normalised Fortify authenticator closure (strtolower + trim)"
    - "Constructor-free Livewire page delegating to a Public action via method DI"
    - "StatefulGuard-typed local variable to satisfy Larastan on guard login/logout"

key-files:
  created:
    - Modules/Auth/Internal/Fortify/FortifyServiceProvider.php
    - Modules/Auth/Public/Actions/LoginAction.php
    - Modules/Auth/Public/Actions/LogoutAction.php
    - Modules/Auth/Internal/Http/Livewire/LoginPage.php
    - Modules/Auth/Resources/views/livewire/login-page.blade.php
    - Modules/Auth/tests/Unit/FortifyConfigTest.php
    - Modules/Auth/tests/Feature/LoginPageTest.php
  modified:
    - Modules/Auth/Providers/AuthServiceProvider.php
    - Modules/Auth/Routes/web.php
    - Modules/Core/Providers/CoreServiceProvider.php
    - config/fortify.php
    - phpunit.xml

key-decisions:
  - "Fortify limiters.login set to null so no named login limiter is referenced after the rate-limiter was dropped"
  - "Helper link points at the literal /reset-password URL, not route('password.reset'), since that named route lands in Plan 12-05"
  - "phpunit.xml SESSION_DRIVER changed array -> database so the testing environment exercises the real session driver"
  - "Guard login/logout calls go through a StatefulGuard-typed local variable to keep Larastan L10 clean"

patterns-established:
  - "Username Fortify closure: normalise then where('username') then Hasher::check, generic null on miss"
  - "Livewire sign-in page: constructor-free, action-method DI, password cleared after submit"

requirements-completed: [MULTI-02]

duration: ~50min
completed: 2026-05-20
---

# Phase 12 Plan 03: Username-Based Login Surface Summary

**The auth subsystem is ON: the Fortify provider now lives in the Auth module, authenticates against `users.username` with no rate limiter and no email features, and a manually-seeded user can sign in through the `/login` Livewire page and sign out through `/logout`.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-05-20T18:26:00Z
- **Completed:** 2026-05-20T19:15:00Z
- **Tasks:** 2 (both TDD)
- **Files modified:** 12 (7 created, 5 modified)

## Accomplishments

- Relocated `FortifyServiceProvider` from `Modules/Core/Internal/Providers/` to `Modules/Auth/Internal/Fortify/`, rewritten for `username` login and with the rate limiter removed.
- `config/fortify.php` features array emptied; the `username` key flipped from `email` to `username`; the `login` limiter set to `null`.
- Shipped `LoginAction` (sole credential-checked sign-in write path) and `LogoutAction` (sign-out with session invalidation + token regeneration).
- Shipped the `LoginPage` Livewire component + its calm sign-in Blade template per UI-SPEC, and the `/login` + `/logout` routes.
- Deleted the legacy Core `FortifyServiceProvider`; `CoreServiceProvider` no longer registers it.

## Task Commits

Each task followed RED -> GREEN:

1. **Task 1: Move + rewrite FortifyServiceProvider**
   - `59b056f` test(12-03) — RED: Fortify config + closure assertions
   - `0f74ebe` feat(12-03) — GREEN: provider relocated, username closure, empty features
2. **Task 2: LoginAction + LogoutAction + LoginPage + routes**
   - `1022e42` test(12-03) — RED: 8 LoginPageTest cases
   - `641923b` feat(12-03) — GREEN: actions, Livewire page, Blade, routes

## Files Created/Modified

- `Modules/Auth/Internal/Fortify/FortifyServiceProvider.php` — Fortify wiring: username authenticator closure, throttle-free pipeline.
- `Modules/Auth/Public/Actions/LoginAction.php` — normalises username, verifies bcrypt hash, logs the user in via the guard.
- `Modules/Auth/Public/Actions/LogoutAction.php` — guard logout + `session()->invalidate()` + `regenerateToken()`.
- `Modules/Auth/Internal/Http/Livewire/LoginPage.php` — constructor-free Livewire page; `submit()` delegates to `LoginAction`.
- `Modules/Auth/Resources/views/livewire/login-page.blade.php` — single-root sign-in form per UI-SPEC copy + chrome.
- `Modules/Auth/Routes/web.php` — `/login` (guest group) + `/logout` (auth group).
- `Modules/Auth/Providers/AuthServiceProvider.php` — registers the Fortify provider, binds the actions, registers the Livewire component.
- `Modules/Core/Providers/CoreServiceProvider.php` — legacy Fortify provider registration removed.
- `config/fortify.php` — empty features, `username => username`, `login` limiter nulled.
- `phpunit.xml` — `SESSION_DRIVER` set to `database`.
- `Modules/Auth/tests/Unit/FortifyConfigTest.php` — 6 cases locking the Fortify wiring.
- `Modules/Auth/tests/Feature/LoginPageTest.php` — 8 cases covering render, sign-in, errors, case-insensitivity, remember-me, logout.

## LoginPageTest Results — all 8 cases pass

1. renders the sign-in page with the correct copy
2. keeps the login page publicly accessible
3. signs in with valid credentials (redirects to the dashboard)
4. shows the generic error on a wrong password
5. shows the same generic error for an unknown username (no enumeration)
6. resolves the username case-insensitively (`Alice` -> `alice`)
7. sets the `remember_web_*` cookie when remember-me is checked
8. signs the user out via the `/logout` route

`FortifyConfigTest` (6 cases) and `BoundaryArchTest::noAuthFacadeOrHelper` are also green. Larastan L10 strict + Pint are clean on every changed file.

## Decisions Made

- **Fortify `login` limiter set to `null`.** The rate limiter was dropped (D-12), so leaving `limiters.login => 'login'` would point at a named limiter that no longer exists. Setting it `null` removes the dangling reference.
- **Helper link uses the literal `/reset-password` URL.** `route('password.reset')` is not registered until Plan 12-05; a named-route reference would throw. The literal URL keeps the page rendering today and resolves to the correct route once 12-05 lands.
- **The Core `FortifyServiceProvider` allow-list entry was never present.** Plan 12-01 deliberately omitted it (the legacy file used no banned symbol), so Task 1 had no temporary entry to remove. The allow-list already carried the real `Modules/Auth/Internal/Fortify/FortifyServiceProvider.php` path.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Larastan flagged the guard `login`/`logout` calls**
- **Found during:** Task 2 (LoginAction + LogoutAction)
- **Issue:** `$this->auth->guard()->login(...)` / `->logout()` produced `staticMethod.dynamicCall` errors under Larastan L10 — `AuthManager::guard()` resolves to a type whose `login`/`logout` are not visible as plain instance calls.
- **Fix:** Assigned `$this->auth->guard()` to a `StatefulGuard`-typed local variable (`/** @var StatefulGuard $guard */`) before calling `login`/`logout`. `StatefulGuard` declares both methods.
- **Files modified:** Modules/Auth/Public/Actions/LoginAction.php, Modules/Auth/Public/Actions/LogoutAction.php
- **Verification:** `phpstan analyse` exits 0 on both files.
- **Committed in:** `641923b` (Task 2 commit)

**2. [Rule 3 - Blocking] phpunit.xml SESSION_DRIVER was `array`**
- **Found during:** Task 1 (Fortify config test)
- **Issue:** The plan's behavior test asserts `config('session.driver') === 'database'` in the testing environment, but `phpunit.xml` pinned `SESSION_DRIVER=array`, contradicting D-26.
- **Fix:** Changed the `phpunit.xml` env entry to `database`. The testing DB is `:memory:` with `RefreshDatabase`, so the `sessions` table is available.
- **Files modified:** phpunit.xml
- **Verification:** `FortifyConfigTest` "uses the database session driver" passes; the full Auth suites stay green.
- **Committed in:** `59b056f` (Task 1 RED commit)

---

**Total deviations:** 2 auto-fixed (both Rule 3 - Blocking).
**Impact on plan:** Both fixes were required for the plan's own acceptance criteria (Larastan-clean code; D-26 session driver). No scope creep.

## Issues Encountered

- **Worktree verification environment was bare.** The worktree shipped without `vendor/`, `.env`, the SQLite file, or the Vite build. Resolved by running `composer install`, copying `.env.example` to `.env` + `key:generate`, creating `database/database.sqlite`, and copying the main repo's `public/build/` (all four are gitignored — none committed). This is environment setup, not a code change.
- **Livewire 4 requires a single-root component view.** The first Blade draft wrapped the markup in `@section('content')`, which left the component output empty and tripped `RootTagMissingFromViewException`. Rewrote the template as a single root `<div>` (matching the `RulesPage` analog); Livewire's `$view->extends('layouts.app')` injects it into the layout's `content` yield.

## Known Stubs

None. The `/reset-password` helper link is a forward reference to Plan 12-05's route, not a stub — the link target is a real (future) page, and the literal URL is intentional per the decision above.

## Pre-existing Breakage (out of scope, flagged for later)

The wave-1 context noted ~162 v1.0 test fixtures and Core callsites still referencing the dropped `users.email`. This plan owns the username-based login surface only; it did not migrate the unrelated fixture corpus. The four Core callsites flagged by Plan 12-02 (`InstallCommand`, `TopNav`, `login-form.blade.php`, and the now-deleted Core `FortifyServiceProvider`) are partially addressed — the Core `FortifyServiceProvider` is gone; `InstallCommand`, `TopNav`, and `login-form.blade.php` belong to the signup/UI wiring of later plans (12-04+) and remain on `users.email`. They are out of scope for the login surface and are flagged here for those plans.

## Threat Model Compliance

- T-12-03-01 (generic login error) — mitigated: verbatim `Username or password is incorrect.`; wrong-password and unknown-user tests assert the identical string.
- T-12-03-02 (username case folding) — mitigated: both `LoginAction` and the Fortify closure `strtolower(trim(...))`; case-insensitive test passes.
- T-12-03-03 (session fixation) — mitigated: `LogoutAction` calls `session()->invalidate()` + `regenerateToken()`; Fortify's `PrepareAuthenticatedSession` regenerates on login.
- T-12-03-04 (rate limiter removed) — accepted per D-12; local-only deployment, bcrypt cost is the defence.
- T-12-03-05 (Auth facade smuggling) — mitigated: `noAuthFacadeOrHelper` arch test green.
- T-12-03-06 (password echoed in the Livewire snapshot) — mitigated: `submit()` resets `$this->password = ''` after the action call.
- T-12-03-07 / T-12-03-08 — mitigated: no JS surface added; `config('fortify.features')` asserted empty.

## Next Phase Readiness

- The auth baseline is live: signup (12-04), recovery-code reset (12-05), and impersonation (12-07) all build on this login surface.
- `route('password.reset')` must be registered by Plan 12-05; the `/login` helper link's literal URL will then resolve to it.

## Self-Check: PASSED

- All 7 created files exist on disk — FOUND.
- `Modules/Core/Internal/Providers/FortifyServiceProvider.php` — DELETED (confirmed absent).
- Commits `59b056f`, `0f74ebe`, `1022e42`, `641923b` — all present in `git log`.

---
*Phase: 12-multi-user-activation*
*Completed: 2026-05-20*
