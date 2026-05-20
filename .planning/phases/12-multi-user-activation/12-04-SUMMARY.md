---
phase: 12-multi-user-activation
plan: 04
subsystem: auth
tags: [auth, signup, recovery-codes, first-user, livewire]
status: complete
requires:
  - phase: 12-01
    provides: Modules/Auth module skeleton + noAuthFacadeOrHelper allow-list
  - phase: 12-02
    provides: users.username schema + user_recovery_codes table + UserRecoveryCode model
  - phase: 12-03
    provides: username-based Fortify login surface + LoginAction
provides:
  - First-user signup ceremony (/signup) gated to User::count() === 0
  - SignupAction — atomic first-user creation with 10 hashed recovery codes
  - RecoveryCodeGenerator — CSPRNG phone-readable code generator
  - RecoveryCodeFormatter — plaintext .txt builder for downloadable codes
  - FirstUserOnlyMiddleware — 404 gate once a user exists
  - RecoveryCodesDisplay (/recovery-codes) — one-time inline display ceremony
affects: [12-05]
tech-stack:
  added: []
  patterns:
    - "CSPRNG recovery-code generator with a 31-char ambiguous-free alphabet"
    - "Transaction body re-checks User::count() === 0 for concurrent-signup race protection"
    - "Livewire streamDownload action returning a StreamedResponse"
    - "Plaintext secrets read fresh from the session each request, never on a public Livewire property"
key-files:
  created:
    - Modules/Auth/Internal/Recovery/RecoveryCodeGenerator.php
    - Modules/Auth/Internal/Recovery/RecoveryCodeFormatter.php
    - Modules/Auth/Public/Actions/SignupAction.php
    - Modules/Auth/Internal/Http/Middleware/FirstUserOnlyMiddleware.php
    - Modules/Auth/Internal/Http/Livewire/SignupPage.php
    - Modules/Auth/Internal/Http/Livewire/RecoveryCodesDisplay.php
    - Modules/Auth/Resources/views/livewire/signup-page.blade.php
    - Modules/Auth/Resources/views/livewire/recovery-codes-display.blade.php
    - Modules/Auth/tests/Unit/RecoveryCodeGeneratorTest.php
    - Modules/Auth/tests/Feature/SignupActionTest.php
    - Modules/Auth/tests/Feature/SignupPageTest.php
    - Modules/Auth/tests/Feature/RecoveryCodesDisplayTest.php
  modified:
    - Modules/Auth/Providers/AuthServiceProvider.php
    - Modules/Auth/Routes/web.php
decisions:
  - "RecoveryCodesDisplay reads codes fresh from the session each request rather than caching on a protected property — Livewire does not rehydrate protected properties, so a cached value would be empty on the separate download request"
  - "SignupPage extracts the first ValidationException message via an explicit type-narrowing helper rather than reset()/casts, to stay Larastan L10 clean"
  - "Added a dedicated SignupActionTest feature suite — the plan's Task 1 behavior block requires SignupAction + middleware coverage but only named the generator test file"
metrics:
  duration: ~55m
  completed: 2026-05-20
  tasks: 2
  files: 14
requirements-completed: [MULTI-02, MULTI-04]
---

# Phase 12 Plan 04: First-User Signup Ceremony Summary

**A fresh install can now bootstrap its owner: `/signup` creates the first
account inside a race-protected transaction with ten bcrypt-hashed recovery
codes, auto-logs the new developer in, and walks them through a one-time
recovery-code display ceremony — code grid, `.txt` download, checkbox gate —
before landing on the dashboard.**

## Performance

- **Duration:** ~55 min
- **Tasks:** 2 (both TDD)
- **Files:** 14 (12 created, 2 modified)

## Accomplishments

- `RecoveryCodeGenerator` — emits codes from the 31-character phone-readable
  alphabet `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (excludes I, L, O, 0, 1) as five
  hyphenated groups of four, each character drawn with `random_int` (CSPRNG).
- `RecoveryCodeFormatter` — `format()` joins codes with `\n` (no header, no
  trailing newline); `filenameFor()` builds `diederik-recovery-codes-<lowercase
  username>.txt`.
- `SignupAction` — atomic first-user signup: opens a DB transaction, re-checks
  `User::count() === 0` *inside* the transaction, inserts the `User` row
  (`is_developer = true`, `force_password_change_at_next_login = false`),
  generates and bcrypt-hashes 10 recovery codes into `user_recovery_codes`,
  then (post-commit) auto-logs-in via the guard and stashes the plaintext
  codes under the session key `auth.signup.recovery_codes_plain`.
- `FirstUserOnlyMiddleware` — throws `NotFoundHttpException` once any user
  exists; registered as the `first-user-only` route alias.
- `SignupPage` Livewire form + Blade — UI-SPEC copy verbatim, password-match
  check, delegates to `SignupAction`, redirects to the recovery-codes display.
- `RecoveryCodesDisplay` Livewire component + Blade — one-time 5×2 monospace
  code grid, `.txt` stream download, checkbox-gated `Continue to diederik`
  button, session key forgotten on completion so codes can never reappear.
- Routes: `/signup` (guest + `first-user-only`), `/recovery-codes` (auth).

## Verification Details

- **Recovery-code alphabet:** `ABCDEFGHJKMNPQRSTUVWXYZ23456789` — 31 chars,
  excludes the visually ambiguous I/L/O/0/1.
- **Code verification regex:** `/^[A-NP-Z2-9]{4}(-[A-NP-Z2-9]{4}){4}$/` — five
  groups of four, hyphenated. 1000 generated codes all matched and were unique.
- **Transaction-safety check:** `SignupAction` re-runs
  `db->connection()->table('users')->count()` *inside* the transaction body
  before inserting any row. A `count()` taken outside the transaction can read
  a stale value before a concurrent signup commits; the inside re-check throws
  a `ValidationException` keyed `signup` so the second concurrent signup loses.
- **Route 404 gate:** `FirstUserOnlyMiddleware` runs before the `SignupPage`
  controller; `GET /signup` returns 200 only on an empty DB and 404 once a
  user exists (a prober cannot distinguish "never existed" from "now closed").
- **Stream-download headers:** `Content-Type: text/plain; charset=UTF-8`,
  filename pattern `diederik-recovery-codes-<lowercase-username>.txt`, body is
  exactly 10 lines — one code per line, no header, no trailing newline.

## Task Commits

Each task followed RED → GREEN:

1. **Task 1: generator, formatter, signup action, middleware**
   - `e3e08f8` test(12-04) — RED: generator/formatter + SignupAction + middleware
   - `06e81a0` feat(12-04) — GREEN: RecoveryCodeGenerator, RecoveryCodeFormatter,
     SignupAction, FirstUserOnlyMiddleware, provider bindings
2. **Task 2: signup page, recovery-codes display, routes**
   - `8c370f7` test(12-04) — RED: SignupPage + RecoveryCodesDisplay feature tests
   - `62ce94d` feat(12-04) — GREEN: Livewire components, Blade, routes, registrations

## Test Coverage

- `RecoveryCodeGeneratorTest` — 5 cases (pattern, 1000-uniqueness, ambiguous-char
  exclusion, formatter format + filename).
- `SignupActionTest` — 9 cases (atomic creation, hashed storage, lowercasing,
  session stash, count-gate, second-signup rejection, password length,
  middleware pass-through + 404).
- `SignupPageTest` — 7 cases (render, 404 gate, submit, mismatched/short
  passwords, developer flag, lowercase).
- `RecoveryCodesDisplayTest` — 6 cases (10-code render, 404 without signup,
  `.txt` stream, checkbox gate, completion + session clear, no-redirect when
  unconfirmed).
- All 27 plan tests + the 37-test `BoundaryArchTest` suite pass (64 total).
- `noAuthFacadeOrHelper` arch test green; Larastan L10 strict + Pint clean on
  every new/modified file.

## Deviations from Plan

### Adjustments

**1. Added a dedicated `SignupActionTest` feature suite (Rule 2 — missing
critical coverage)**
- The plan's Task 1 `<behavior>` block specifies SignupAction and
  FirstUserOnlyMiddleware test cases, but the Task 1 `<files>` list only named
  `RecoveryCodeGeneratorTest.php`. SignupAction touches the DB so it cannot
  live in a `Unit` test. Created `Modules/Auth/tests/Feature/SignupActionTest.php`
  to cover the named behavior contract (atomicity, race-protection, validation,
  middleware). No scope change — purely the test surface the plan described.

**2. `RecoveryCodesDisplay` reads codes fresh from the session (Rule 1 — bug
fix during Task 2 GREEN)**
- The plan's interface cached the codes on a `protected array $codes` populated
  in `mount()`. Livewire does not rehydrate protected properties between
  requests, so on the separate `download` request `$this->codes` was empty and
  the streamed file had a single blank line. Fixed by reading the codes fresh
  from the session in `mount()`, `download()`, and `render()` via a private
  `codesFromSession()` helper. This preserves the threat-model intent
  (T-12-04-02 — plaintext never on a public/serialised property) and is in fact
  stronger: the codes are never held on the component instance at all.
- **Commit:** `62ce94d`.

**3. `SignupPage` first-error extraction hardened for Larastan (Rule 3 —
blocking)**
- `ValidationException::errors()` is typed as a bare `array` in the framework,
  so `reset()` + `(string)` casts produced `cast.string` / `return.type` /
  `assign.propertyType` errors at Larastan L10. Replaced with an explicit
  type-narrowing `firstErrorMessage()` helper (`is_array` + `is_string`
  guards). No behavior change.
- **Commit:** `62ce94d`.

## Issues Encountered

- **Worktree verification environment was bare.** No `vendor/`, `.env`, SQLite
  file, or Vite build. Resolved by `composer install`, copying `.env.example`
  to `.env` + `key:generate`, creating `database/database.sqlite`, and copying
  `public/build/` from the main repo (all four are gitignored — none
  committed). Environment setup, not a code change.

## Pre-existing Breakage (out of scope, flagged)

The wave-1/2 context noted ~162 v1.0 test fixtures and three Core callsites
(`InstallCommand`, `TopNav`, `login-form.blade.php`) still referencing the
dropped `users.email`. This plan owns the signup ceremony only and did not
touch the fixture corpus or those Core callsites — they remain flagged for a
later plan. The project's `composer test` (`pest --parallel`) still hits the
pre-existing `rpSeries()` redeclaration fatal from overlapping phpunit.xml
testsuites; targeted non-parallel `vendor/bin/pest` runs were used for all
self-checks per the wave context guidance.

## Known Stubs

None. The signup ceremony is fully wired end-to-end: route → form → action →
recovery-code generation → display → download → checkbox gate → dashboard.

## Threat Model Compliance

- T-12-04-01 (concurrent /signup race) — mitigated: `SignupAction` re-checks
  `User::count() === 0` inside the transaction; the second signup throws a
  `ValidationException` (proven by `it rejects a second signup`).
- T-12-04-02 (codes leaking via Livewire snapshot) — mitigated: codes are never
  held on a public/serialised property; read fresh from the session each
  request.
- T-12-04-03 (codes leaking via Telescope/logs) — mitigated: plaintext codes
  are never logged; no `system_alerts` row carries them.
- T-12-04-04 (username case folding) — mitigated: `SignupAction` applies
  `strtolower(trim(...))` (proven by `it lowercases the username`).
- T-12-04-05 (weak password accepted) — mitigated: 12-character minimum
  enforced with the UI-SPEC copy `Use at least 12 characters.`.
- T-12-04-06 (predictable codes) — mitigated: `RecoveryCodeGenerator` uses
  `random_int` (CSPRNG); `Str::password()` is absent (grep-verified 0).
- T-12-04-07 (first-user-becomes-developer) — accepted: `is_developer = true`
  is set inside the transaction and visible on the created row.
- T-12-04-08 (/signup reveals first-user presence) — mitigated:
  `FirstUserOnlyMiddleware` returns 404 (not 403) once a user exists.
- T-12-04-09 / T-12-04-10 — accepted per the plan (local-only deployment;
  session-driver plaintext stash for the ~30-second ceremony).

## TDD Gate Compliance

Both tasks followed RED → GREEN. Gate commits in order:
- `e3e08f8` test(12-04) — RED (Task 1)
- `06e81a0` feat(12-04) — GREEN (Task 1)
- `8c370f7` test(12-04) — RED (Task 2)
- `62ce94d` feat(12-04) — GREEN (Task 2)

## Self-Check: PASSED

- All 12 created files exist on disk — FOUND.
- Commits `e3e08f8`, `06e81a0`, `8c370f7`, `62ce94d` — all present in `git log`.
- `php artisan migrate:fresh` exits 0; `/signup`, `/recovery-codes`, `/login`
  routes all registered.

---
*Phase: 12-multi-user-activation*
*Completed: 2026-05-20*
