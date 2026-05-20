---
phase: 12-multi-user-activation
plan: 05
subsystem: auth
tags: [auth, race-fix, force-password-change, add-user, partner, middleware, livewire]

requires:
  - phase: 12-04
    provides: First-user signup ceremony, SignupAction, RecoveryCodeGenerator, FirstUserOnlyMiddleware
provides:
  - SignupAction race guard hardened with an immediate-write-lock preamble (CR-01 closed)
  - Unique index on user_recovery_codes.code_hash + de-duplicated generated codes (WR-05 closed)
  - ForcePasswordChangeMiddleware — redirect-on-flag enforcement on every authenticated route (CR-02 closed)
  - ChangePasswordPage (/change-password) — forced-password-change Livewire page
  - AddUserAction — owner-creates-partner account action with recovery-code provisioning
  - RequireDeveloperMiddleware — is_developer route gate (404, never 403)
  - AddUserPage (/settings/users/new) — developer-gated owner-adds-partner page
affects: [12-06]

tech-stack:
  added: []
  patterns:
    - "No-op UPDATE preamble inside a transaction to promote a SQLite WAL connection to an immediate write lock"
    - "Distinct-code generation loop (regenerate-on-collision) backed by a unique code_hash index"
    - "final readonly middleware reading the CurrentUser contract, raising 404 (never 403) for unauthorised callers"
    - "developer middleware alias gating routes on is_developer"

key-files:
  created:
    - Modules/Auth/Database/Migrations/2026_05_20_000001_add_unique_index_to_user_recovery_codes.php
    - Modules/Auth/Internal/Http/Middleware/ForcePasswordChangeMiddleware.php
    - Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php
    - Modules/Auth/Internal/Http/Livewire/ChangePasswordPage.php
    - Modules/Auth/Internal/Http/Livewire/AddUserPage.php
    - Modules/Auth/Resources/views/livewire/change-password-page.blade.php
    - Modules/Auth/Resources/views/livewire/add-user-page.blade.php
    - Modules/Auth/Public/Actions/AddUserAction.php
    - Modules/Auth/tests/Feature/ForcePasswordChangeMiddlewareTest.php
    - Modules/Auth/tests/Feature/ChangePasswordPageTest.php
    - Modules/Auth/tests/Feature/AddUserPageTest.php
  modified:
    - Modules/Auth/Public/Actions/SignupAction.php
    - Modules/Auth/Routes/web.php
    - Modules/Auth/Providers/AuthServiceProvider.php
    - Modules/Core/Models/User.php
    - Modules/Auth/tests/Feature/SignupActionTest.php

key-decisions:
  - "SignupAction issues `UPDATE users SET id = id WHERE 0 = 1` before the count re-check — promotes the WAL connection to an immediate write lock so a concurrent signup blocks instead of reading a stale snapshot"
  - "ForcePasswordChangeMiddleware is pushed to the `auth` route group; it exempts the route names `auth.change-password` and `logout` so a flagged user is never trapped"
  - "AddUserAction provisions ten bcrypt-hashed recovery codes for the partner so the partner is never code-less; the codes are NOT shown to the owner"
  - "RequireDeveloperMiddleware is registered under the `developer` middleware alias and raises a 404 (never 403) for non-developer or unauthenticated callers"

patterns-established:
  - "Write-lock-promotion preamble: a zero-row UPDATE inside a transaction makes a SQLite WAL existence check deterministic under concurrency"
  - "404-not-403 developer gate: routes the partner must not learn about raise NotFoundHttpException, never AccessDeniedHttpException"

requirements-completed: [MULTI-02, MULTI-03, MULTI-04]

duration: ~50min
completed: 2026-05-20
---

# Phase 12 Plan 05: Multi-User Activation Gap-Closure Summary

**The signup race guard is now deterministic under SQLite WAL, the
forced-password-change flag is enforced by a real middleware, recovery-code
hashes are unique-indexed, and the owner can create a partner account from a
developer-gated page — closing code-review blockers CR-01, CR-02 and WR-05 and
shipping the second-user foundation Phase 12 needs.**

## Performance

- **Duration:** ~50 min
- **Tasks:** 2 (both TDD)
- **Files:** 16 (11 created, 5 modified)

## Accomplishments

### Task 1 — CR-01 + WR-05 + CR-02

- **CR-01 (race guard):** `SignupAction`'s transaction body now issues
  `UPDATE users SET id = id WHERE 0 = 1` before the `User::count()` re-check.
  The no-op UPDATE matches zero rows but still acquires an immediate write
  lock, so a second concurrent signup blocks until the first commits and then
  observes the created user — the guard is no longer defeated by WAL's read
  snapshots.
- **WR-05 (unique code_hash):** new migration
  `2026_05_20_000001_add_unique_index_to_user_recovery_codes` adds a unique
  index `user_recovery_codes_code_hash_unique`. `SignupAction` now generates
  ten *distinct* plaintext codes (regenerate-on-collision) so the unique index
  is never the thing that rejects an insert.
- **CR-02 (force-password-change enforcement):** `ForcePasswordChangeMiddleware`
  (a `final readonly` class reading the `CurrentUser` contract) is pushed to
  the `auth` route group. When the authenticated user carries
  `force_password_change_at_next_login`, every request is redirected to
  `/change-password` except the route names `auth.change-password` and
  `logout`. `ChangePasswordPage` (the `/change-password` Livewire page) lets
  the user verify their current password and set a new 12+ character one; on
  success the new hash is written, the flag is cleared, and the user is sent
  to the dashboard. The `User` model docblock now describes the enforcement
  that the shipped middleware actually provides.

### Task 2 — owner-creates-partner foundation

- `AddUserAction` creates a partner account inside one transaction together
  with ten bcrypt-hashed recovery codes. The partner is born
  `is_developer = false` and `force_password_change_at_next_login = true`. A
  non-developer caller raises `NotFoundHttpException` (404, never 403); a
  duplicate username is translated to a `ValidationException` keyed `username`
  with the locked copy; a sub-12-character password raises a
  `ValidationException` keyed `password`.
- `RequireDeveloperMiddleware` gates routes on `is_developer`, raising a 404
  for any non-developer or unauthenticated caller. Registered under the
  `developer` middleware alias in `AuthServiceProvider::boot()`.
- `AddUserPage` Livewire component + blade serve `/settings/users/new` behind
  the `developer` gate, with the UI-SPEC copy verbatim. On success the owner
  sees the flash `User {username} created. ...`; the partner's recovery codes
  are never shown to the owner.

## Verification Details

- **Write-lock-promotion statement:** `UPDATE users SET id = id WHERE 0 = 1`,
  placed before the `count()` re-check inside `SignupAction`'s transaction.
- **Unique-index migration:** `2026_05_20_000001_add_unique_index_to_user_recovery_codes.php`
  — adds `user_recovery_codes_code_hash_unique`; confirmed present after
  `migrate:fresh`.
- **ForcePasswordChangeMiddleware exempt routes:** `auth.change-password` and
  `logout` (by route name).
- **Developer-gate middleware alias:** `developer` →
  `RequireDeveloperMiddleware`.
- **AddUserAction recovery-code decision:** the action generates and
  bcrypt-hashes ten distinct recovery codes for the partner so they always
  have a recovery path; the codes are not displayed to the owner — the partner
  sees them after their forced password change via Plan 12-06's surface.

## Task Commits

Each task followed RED → GREEN:

1. **Task 1: race guard, unique index, force-password-change**
   - `c937c04` test(12-05) — RED: race guard / unique code_hash / force-password-change tests
   - `cf6eb18` feat(12-05) — GREEN: SignupAction preamble, migration, ForcePasswordChangeMiddleware, ChangePasswordPage
2. **Task 2: owner-creates-partner surface**
   - `48e8c13` test(12-05) — RED: AddUserPage feature tests
   - `f034103` feat(12-05) — GREEN: AddUserAction, RequireDeveloperMiddleware, AddUserPage, routes, registrations

## Test Coverage

- `SignupActionTest` — extended with two cases: ten distinct generated codes,
  and a duplicate `code_hash` insert raising a `QueryException`.
- `ForcePasswordChangeMiddlewareTest` — 6 cases (flag-false pass-through,
  unauthenticated pass-through, redirect when flagged, no redirect on the
  change-password page, no redirect on logout, end-to-end dashboard redirect
  that stops once the flag clears).
- `ChangePasswordPageTest` — 5 cases (render copy, valid submit clears the
  flag, wrong current password, password mismatch, length error).
- `AddUserPageTest` — 11 cases (partner flagged + non-developer, username
  lowercasing, recovery-code provisioning, duplicate username, short password,
  404 for a non-developer caller, page render for a developer, 404 route gate,
  unauthenticated 404, successful submit flash, password-mismatch flash).
- All 76 Auth-module tests pass (3721 assertions); `BoundaryArchTest`
  `noAuthFacadeOrHelper` and `UserIdColumnArchTest` both green; Larastan L10
  strict + Pint clean on every new/changed file.

## Deviations from Plan

### Adjustments

**1. [Rule 1 — Bug] `/settings/users/new` unauthenticated test asserts 404, not a login redirect**
- The plan's Task 2 `<behavior>` block expected an unauthenticated visitor to
  `/settings/users/new` to be redirected to `/login` by the `auth` middleware.
  Verified against the project's actual behavior: every existing `auth`-only
  route (`/settings`, `/`) returns a framework error rather than a 302 redirect
  for an unauthenticated request — the project's `Authenticate` middleware
  throws rather than redirecting to a named login route. `RequireDeveloperMiddleware`
  deliberately raises a 404 for any non-developer caller (unauthenticated
  included), which is the correct 404-not-403 security posture: the route never
  reveals it exists to a non-owner. The test was adjusted to assert the
  deterministic, correct outcome (`assertNotFound()`) rather than a redirect the
  project's auth stack does not produce. No production-code change — the
  `developer` gate's 404 is the intended behavior.
- **Files:** `Modules/Auth/tests/Feature/AddUserPageTest.php`
- **Commit:** `f034103`

**2. [Rule 3 — Blocking] Larastan L10 type adjustments in `SignupAction`**
- The distinct-code `while` loop makes `$codesPlain` a `non-empty-list<string>`;
  Larastan L10 flagged the `@var` tag (`list` not a subtype of `non-empty-list`)
  and a `foreach` value-variable name collision with the `while` loop's `$code`.
  Fixed by tightening the `@var` to `non-empty-list<string>` and renaming the
  `foreach` variable to `$plainCode`. No behavior change.
- **Files:** `Modules/Auth/Public/Actions/SignupAction.php`
- **Commit:** `cf6eb18`

## Authentication Gates

None — no auth gate or credential prompt was encountered during execution.

## Known Stubs

None. Every surface is wired end-to-end: the race guard is exercised by tests,
the migration runs in `migrate:fresh`, the force-password-change middleware
redirects and the `/change-password` page clears the flag, and
`/settings/users/new` creates a real partner row with recovery codes.

## Threat Model Compliance

- T-12-05-01 (concurrent /signup race) — mitigated: write-lock preamble.
- T-12-05-02 (/settings/users/new accessed by a non-developer) — mitigated:
  `RequireDeveloperMiddleware` + `AddUserAction`'s defensive `is_developer`
  check both raise 404.
- T-12-05-03 (flagged user skipping the forced change) — mitigated:
  `ForcePasswordChangeMiddleware` on the whole `auth` group.
- T-12-05-04 (weak partner password) — mitigated: 12-char minimum in both
  `AddUserAction` and `ChangePasswordPage`.
- T-12-05-05 (duplicate recovery codes) — mitigated: unique `code_hash` index
  + de-dup loop.
- T-12-05-06 (username enumeration on partner creation) — accepted per plan.
- T-12-05-07 (username case-folding bypass) — mitigated: `strtolower(trim(...))`
  in `AddUserAction`.
- T-12-05-SC (package installs) — no new dependencies; gate not triggered.

## TDD Gate Compliance

Both tasks followed RED → GREEN. Gate commits in order:
- `c937c04` test(12-05) — RED (Task 1)
- `cf6eb18` feat(12-05) — GREEN (Task 1)
- `48e8c13` test(12-05) — RED (Task 2)
- `f034103` feat(12-05) — GREEN (Task 2)

## Self-Check: PASSED

- All 11 created files exist on disk — FOUND.
- Commits `c937c04`, `cf6eb18`, `48e8c13`, `f034103` — all present in `git log`.
- `php artisan migrate:fresh` exits 0; the unique index
  `user_recovery_codes_code_hash_unique` is present.

---
*Phase: 12-multi-user-activation*
*Completed: 2026-05-20*
