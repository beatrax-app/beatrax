---
phase: 12-multi-user-activation
plan: 01
subsystem: auth
tags: [auth, module-skeleton, arch-test, di]
requires: []
provides:
  - "Modules/Auth/ module skeleton (manifest + service provider + directory tree)"
  - "noAuthFacadeOrHelper BoundaryArchTest invariant with the 11-path allow-list"
affects:
  - "tests/Contracts/BoundaryArchTest.php"
  - "composer.json autoload"
tech-stack:
  added: []
  patterns:
    - "nwidart/laravel-modules manifest at priority 1 (loads immediately after Core priority 0)"
    - "RecursiveIteratorIterator + comment-stripping + banned-pattern arch test with a per-file allow-list"
key-files:
  created:
    - Modules/Auth/module.json
    - Modules/Auth/Providers/AuthServiceProvider.php
    - Modules/Auth/Routes/web.php
    - Modules/Auth/Routes/console.php
    - Modules/Auth/Public/Actions/.gitkeep
    - Modules/Auth/Internal/Fortify/.gitkeep
    - Modules/Auth/Internal/Http/Livewire/.gitkeep
    - Modules/Auth/Internal/Http/Middleware/.gitkeep
    - Modules/Auth/Internal/Recovery/.gitkeep
    - Modules/Auth/Internal/Console/.gitkeep
    - Modules/Auth/Internal/Listeners/.gitkeep
    - Modules/Auth/Models/.gitkeep
    - Modules/Auth/Database/Migrations/.gitkeep
    - Modules/Auth/Resources/views/livewire/.gitkeep
    - Modules/Auth/Resources/views/partials/.gitkeep
    - Modules/Auth/tests/Feature/.gitkeep
    - Modules/Auth/tests/Unit/.gitkeep
  modified:
    - composer.json
    - tests/Contracts/BoundaryArchTest.php
decisions:
  - "Did not add a temporary Core FortifyServiceProvider allow-list entry — the legacy file does not call any banned symbol, so the entry would be dead config."
  - "Strip Blade comments in addition to PHP comments in the arch-test scan, because the broadened Modules/* scope includes .blade.php files."
metrics:
  duration: ~15m
  completed: 2026-05-20
requirements: [MULTI-01]
---

# Phase 12 Plan 01: Auth Module Skeleton + noAuthFacadeOrHelper Invariant Summary

Stood up the `Modules/Auth/` module skeleton (priority-1 manifest, service provider, directory tree, empty route files) and extended `tests/Contracts/BoundaryArchTest.php` with the `noAuthFacadeOrHelper` invariant pinned to an 11-path allow-list — so every later Phase 12 commit that smuggles an Auth facade / `auth()` / `session()` / `request()->user()` outside the sanctioned auth surface breaks CI immediately.

## What Was Built

### Task 1 — `Modules/Auth/` skeleton (commit `6b87c4f`)

- `Modules/Auth/module.json` — registers `Auth` at `priority: 1`, alias `auth`, provider `Modules\Auth\Providers\AuthServiceProvider`.
- `Modules/Auth/Providers/AuthServiceProvider.php` — `final` class extending `Illuminate\Support\ServiceProvider`; `register()` is an empty placeholder; `boot(Dispatcher $events, LivewireManager $livewire)` calls `loadMigrationsFrom`, two `loadRoutesFrom`, and `loadViewsFrom(..., 'auth')`.
- `Modules/Auth/Routes/web.php` — empty `Route::middleware(['web'])->group(...)` placeholder.
- `Modules/Auth/Routes/console.php` — empty console-route file.
- 13 `.gitkeep` files materialising the `Internal/`, `Public/`, `Models/`, `Database/`, `Resources/`, and `tests/` directory tree in git.
- `composer.json` — `Modules\Auth\` added to `autoload.psr-4` and `Modules\Auth\Tests\` added to `autoload-dev.psr-4`, both in alphabetical position.

Verified: `php artisan module:list` shows `[Enabled] Auth ... Modules/Auth [1]`; the provider class autoloads; `ModulePrioritiesArchTest` stays green (Core 0 < Auth 1); no GSD codename leakage; Larastan L10 + Pint clean on the new PHP files.

### Task 2 — `noAuthFacadeOrHelper` invariant (commit `024c55e`)

- Appended an `it(...)` block to `tests/Contracts/BoundaryArchTest.php` that walks the entire `Modules/` tree, strips PHP + Blade comments, skips `tests/` and `Database/Migrations/` paths and the allow-list, and flags any banned symbol.
- Banned symbols: `Illuminate\Support\Facades\Auth`, `Auth::user(`, `Auth::id(`, `Auth::loginUsingId(`, the `auth(` / `session(` helpers (with `(?<![>:])` lookbehind), and `request()->user(` / `request()->session(`.

Verified: the new test passes; the full 37-test `BoundaryArchTest` suite passes; negative spot-check confirmed (`Auth::user()` in a temp `Modules/Core/Public/` file fails the test, temp file then removed); Pint clean.

## Allow-list Contents (D-24)

The `noAuthFacadeOrHelper` allow-list is a per-file precise array (never a glob) of 11 forward-declared paths — none exist yet; Plans 12-03 through 12-07 create them:

```
Modules/Auth/Public/Actions/LoginAction.php
Modules/Auth/Public/Actions/SignupAction.php
Modules/Auth/Public/Actions/LogoutAction.php
Modules/Auth/Public/Actions/ResetPasswordAction.php
Modules/Auth/Public/Actions/RegenerateRecoveryCodesAction.php
Modules/Auth/Public/Actions/ImpersonateUserAction.php
Modules/Auth/Public/Actions/EndImpersonationAction.php
Modules/Auth/Public/Actions/AddUserAction.php
Modules/Auth/Internal/Fortify/FortifyServiceProvider.php
Modules/Auth/Internal/Fortify/Authenticator.php
Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php
```

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Blade comments tripped the arch-test scan**
- **Found during:** Task 2
- **Issue:** The analog comment-stripping pre-pass only strips PHP `/* */` and `//` comments. Because the new invariant scans the entire `Modules/` tree (the analog only scanned `Modules/Core/Internal/Console/`), it now also reads `.blade.php` files. `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php` contains a Blade comment `{{-- ... via the session() global helper ... --}}` whose literal `session()` text tripped the helper pattern — a false positive.
- **Fix:** Extended the comment-stripping `preg_replace` to also remove `{{-- --}}` Blade comments. This honors the plan's "DI-only invariant applies to Blade views too" intent: real Blade code is still scanned, only descriptive Blade comments are stripped.
- **Files modified:** tests/Contracts/BoundaryArchTest.php
- **Commit:** 024c55e

### Plan-instruction adjustment

**2. Temporary Core FortifyServiceProvider allow-list entry NOT added**
- Task 2's `<action>` instructed adding `Modules/Core/Internal/Providers/FortifyServiceProvider.php` to the allow-list "because the codebase contains exactly one FortifyServiceProvider that uses `Auth::loginUsingId()`", with option (b) being "first inspect whether the legacy file actually calls these symbols".
- Inspection (option b) found the legacy `Modules/Core/Internal/Providers/FortifyServiceProvider.php` does **not** call `Auth::loginUsingId()` or any other banned symbol — it uses `Fortify::authenticateUsing()` (a library DSL, not a facade) and an injected `Hasher` contract. A whole-tree scan confirmed zero real offenders.
- Therefore the temporary entry would be dead config. It was omitted; the test passes clean with just the 11 Auth-module paths. Plan 12-03 has no temporary entry to remove.

## Known Stubs

None. The route files and `register()` placeholder are intentional skeleton scaffolding — later Phase 12 plans wire concrete routes, bindings, and Livewire components into these exact files. This is documented module structure, not a data stub.

## Notes for Later Plans

- `module:list` output format in this `nwidart/laravel-modules` version is `[Enabled] Auth ... Modules/Auth [1]`, not the pipe-table form the plan's verify command regex (`^\| Auth\s+\| true...`) expected. The substantive criteria — Auth enabled, priority 1, Core priority 0 — are met. Future verify commands should match the bracketed format.
- `modules_statuses.json` is git-ignored (`.gitignore` entry `/modules_statuses.json`); it was created locally so the file activator reports modules as enabled, but it is intentionally not committed — consistent with every other module in this repo.
- `bootstrap/cache/modules.php` is a tracked runtime cache committed in its cleared state; artisan runs repopulate it locally. It was reverted before each commit so only intentional skeleton changes are recorded.

## Self-Check: PASSED

- `Modules/Auth/module.json` — FOUND
- `Modules/Auth/Providers/AuthServiceProvider.php` — FOUND
- `Modules/Auth/Routes/web.php` — FOUND
- `tests/Contracts/BoundaryArchTest.php` contains `noAuthFacadeOrHelper` — FOUND
- Commit `6b87c4f` (Task 1) — FOUND
- Commit `024c55e` (Task 2) — FOUND
