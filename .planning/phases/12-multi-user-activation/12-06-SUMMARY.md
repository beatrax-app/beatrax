---
phase: 12-multi-user-activation
plan: 06
subsystem: auth
tags: [auth, recovery-codes, password-reset, cli, owner-resets-partner]
status: complete

requires:
  - phase: 12-04
    provides: recovery-code generation, RecoveryCodeGenerator, RecoveryCodeFormatter, RecoveryCodesDisplay
  - phase: 12-05
    provides: AddUserAction, RequireDeveloperMiddleware, ForcePasswordChangeMiddleware, developer-gated /settings/users routes
provides:
  - RecoveryCodeNormalizer — case-fold + strip a typed code to its bare comparison form
  - RecoveryCodeAuthenticator — username + code verification with single-use used_at stamping under a row lock
  - ResetPasswordAction — recovery-code-driven password reset that clears the forced-change flag
  - ResetPasswordPage (/reset-password) — guest Livewire reset form; closes CR-03 (dead login-page link)
  - ResetPasswordCommand (diederik:reset-password) — interactive CLI fallback, refuses non-interactive use
  - RegenerateRecoveryCodesAction — invalidate-then-reissue ten recovery codes
  - ManageUserPage (/settings/users/{username}) — owner sets a partner password and regenerates partner codes
affects: []

tech-stack:
  added: []
  patterns:
    - "Cross-user-safe verification: raw query-builder lockForUpdate over user_recovery_codes inside a transaction, stamping used_at on the matched row"
    - "Re-format a normalised bare recovery code into the stored hyphenated shape before Hasher::check (hash-shape round-trip)"
    - "system_alerts audit row written for every recovery-code attempt (warning on success, critical on failure)"
    - "Interactive-only artisan command: isInteractive() gate, no --password flag"

key-files:
  created:
    - Modules/Auth/Internal/Recovery/RecoveryCodeNormalizer.php
    - Modules/Auth/Internal/Recovery/RecoveryCodeAuthenticator.php
    - Modules/Auth/Public/Actions/ResetPasswordAction.php
    - Modules/Auth/Public/Actions/RegenerateRecoveryCodesAction.php
    - Modules/Auth/Internal/Console/ResetPasswordCommand.php
    - Modules/Auth/Internal/Http/Livewire/ResetPasswordPage.php
    - Modules/Auth/Internal/Http/Livewire/ManageUserPage.php
    - Modules/Auth/Resources/views/livewire/reset-password-page.blade.php
    - Modules/Auth/Resources/views/livewire/manage-user-page.blade.php
    - Modules/Auth/tests/Unit/RecoveryCodeNormalizerTest.php
    - Modules/Auth/tests/Feature/RecoveryCodeAuthenticatorTest.php
    - Modules/Auth/tests/Feature/ResetPasswordCommandTest.php
    - Modules/Auth/tests/Feature/ResetPasswordPageTest.php
    - Modules/Auth/tests/Feature/ManageUserPageTest.php
  modified:
    - Modules/Auth/Routes/console.php
    - Modules/Auth/Routes/web.php
    - Modules/Auth/Providers/AuthServiceProvider.php
    - Modules/Auth/Resources/views/livewire/recovery-codes-display.blade.php
    - Modules/Auth/tests/Feature/RecoveryCodesDisplayTest.php

key-decisions:
  - "SignupAction (and AddUserAction) bcrypt-hash the FORMATTED five-group hyphenated code string (AAAA-BBBB-CCCC-DDDD-EEEE) — confirmed by the round-trip test. RecoveryCodeAuthenticator therefore normalises the typed input to bare 20 chars then re-inserts a hyphen every 4 characters before Hasher::check."
  - "system_alerts uses kind=auth.recovery_code_consumed severity=warning on success and kind=auth.recovery_code_failed severity=critical on failure. UI-SPEC/D-13 wrote severity=error for failures, but the system_alerts trigger pair only permits info/warning/critical — error was mapped to critical."
  - "RecoveryCodeAuthenticator uses the raw query-builder (DatabaseManager::table) for the lockForUpdate code lookup + used_at update — the Eloquent builder chain tripped Larastan L10 staticMethod.dynamicCall; the raw builder mirrors the AcknowledgeSystemAlert / DismissDiscoveredSender precedent."
  - "diederik:reset-password refuses non-interactive use via $this->input->isInteractive(); there is no --password option (D-14). A --no-interaction invocation exits FAILURE without touching the password."
  - "WR-07: the recovery-codes display subhead was trimmed from '...They will not be shown again — only regenerated.' to '...They will not be shown again.' The signing-up owner cannot reach a self-regeneration path from this plan's surfaces (self-regen from Settings is Phase 16 scope); only the owner-manages-partner regenerate lands here, so the display must not promise a capability the user on that page cannot reach."

patterns-established:
  - "Recovery-code redemption core: normalise typed input, re-format to the hashed shape, compare against unused rows held under lockForUpdate inside one transaction, stamp used_at on the match — single-use is enforced by the row lock."

requirements-completed: [MULTI-04]

duration: ~70min
completed: 2026-05-20
---

# Phase 12 Plan 06: Recovery-Code Redemption Gap-Closure Summary

**Recovery codes are now redeemable end-to-end: a user who forgot their
password can recover at `/reset-password` or via the
`diederik:reset-password` CLI, and the owner can reset the partner's
password and regenerate the partner's codes from
`/settings/users/{username}` — closing CR-03 (the live login-page
`/reset-password` link that used to 404) and WR-07 (display copy
promising an unreachable regeneration capability).**

## Performance

- **Duration:** ~70 min
- **Tasks:** 2 (both TDD)
- **Files:** 19 (14 created, 5 modified)

## Accomplishments

### Task 1 — verification core + CLI fallback

- `RecoveryCodeNormalizer` — folds a typed code to uppercase and strips
  every character outside the phone-readable `A-HJKMNP-Z2-9` alphabet, so
  a code typed lowercase or with stray hyphens/spaces still compares
  equal.
- `RecoveryCodeAuthenticator` — `verify()` finds the user by normalised
  username, re-formats the normalised input into the hashed hyphenated
  shape, queries the unused `user_recovery_codes` rows under
  `lockForUpdate()` inside a transaction, stamps `used_at` on the
  matching row (single-use), and writes a `system_alerts` audit row for
  every attempt. Returns the `User` on a match, `null` on any failure —
  never throws.
- `ResetPasswordAction` — validates the password length, delegates to the
  authenticator, throws `ValidationException` keyed `code` on a null
  return, and writes the new password hash while clearing
  `force_password_change_at_next_login`. Guard-free.
- `ResetPasswordCommand` (`diederik:reset-password <username>`) —
  interactive CLI fallback: refuses non-interactive use, prompts twice
  through hidden input, validates match + 12-char minimum, sets the new
  password and the forced-change flag.

### Task 2 — reset page + owner-resets-partner surface

- `RegenerateRecoveryCodesAction` — authorises the caller (developer OR
  self), stamps `used_at` on every unused target-user code, then issues
  ten fresh distinct bcrypt-hashed codes, returning the ten plaintext
  codes for a one-time display. A non-developer caller for another user
  raises `NotFoundHttpException`.
- `ResetPasswordPage` (`/reset-password`) — guest Livewire form: username
  + recovery code + new password → redeem → redirect to `/login` with the
  flash `Password updated. Sign in with your new password.`
- `ManageUserPage` (`/settings/users/{username}`) — developer-gated;
  resolves the partner in `mount()` (404 for an unknown username or a
  non-developer caller), offers "set new password" and "regenerate
  recovery codes" with the ten new codes shown inline + a `.txt`
  download.
- Routes `auth.reset-password` and `auth.users.manage` registered; the
  login-page `/reset-password` link now resolves.

## Verification Details

- **Hashed code-string shape:** FORMATTED — `SignupAction` and
  `AddUserAction` pass the generator's hyphenated `AAAA-BBBB-CCCC-DDDD-EEEE`
  string straight to `$hasher->make()`. The authenticator re-formats the
  normalised bare input into that identical shape before `Hasher::check`;
  a round-trip test (sign up, redeem) guards against a shape regression.
- **system_alerts kind + severity:** `auth.recovery_code_consumed` /
  `warning` on success; `auth.recovery_code_failed` / `critical` on
  failure. Messages: `Recovery code used by {username}.` and
  `Failed recovery code attempt for {username}.`
- **CLI non-interactive refusal:** `$this->input->isInteractive()` gate —
  a `--no-interaction` invocation exits `FAILURE` and changes nothing;
  there is no `--password` option.
- **Recovery-codes route names:** `auth.reset-password` (`GET /reset-password`,
  guest) and `auth.users.manage` (`GET /settings/users/{username}`,
  developer-gated).
- **WR-07 copy decision:** trimmed "— only regenerated" from the
  recovery-codes display subhead. This plan ships only the
  owner-manages-partner regeneration surface; the signing-up owner has no
  self-regeneration path reachable from this plan, so the display must
  not promise one.

## D-08 Path (2) Scope Note

Locked decision D-08 says a recovery code authorises **two** things:
(1) password reset via `/reset-password`, and (2) **one-time login** via
`/login` (typing username + recovery code signs the user in and sets
`force_password_change_at_next_login = true`).

**This plan implements path (1) only.** Path (2) — one-time login at
`/login` — is **not** in scope of plan 12-06: the plan's `<objective>`,
`<tasks>`, `must_haves`, and `<threat_model>` describe only the
`/reset-password` page, the CLI, and the owner-resets-partner surface;
the login page is referenced solely as the dead-link target that the new
route resolves. Path (2) is a **documented deferral** — `RecoveryCodeAuthenticator`
was deliberately built as the shared verification core D-08 calls for, so
a future plan can wire a one-time-login leg into `LoginPage` /
`LoginAction` by reusing `verify()` without touching the redemption
mechanics. No `/login` change was made here.

## Deviations from Plan

### Adjustments

**1. [Rule 3 — Blocking] RecoveryCodeAuthenticator uses the raw query
builder for the locked code lookup**
- The interface block sketched an Eloquent `UserRecoveryCode::query()
  ->where(...)->whereNull(...)->lockForUpdate()->get()` chain. At Larastan
  L10 that chain raised `staticMethod.dynamicCall` on `whereNull` /
  `lockForUpdate`. Switched to the raw `DatabaseManager::table(
  'user_recovery_codes')` builder for the lookup and the `used_at`
  update — the same pattern `AcknowledgeSystemAlert` and
  `DismissDiscoveredSender` already use for locked reads. No behaviour
  change; single-use semantics are unaffected.
- **Files:** `Modules/Auth/Internal/Recovery/RecoveryCodeAuthenticator.php`
- **Commit:** `7b3b616`

**2. [Rule 1 — Bug] ManageUserPageTest assertion drops the BelongsToUser
global scope**
- One regenerate test acts as the owner and then read the partner's
  `user_recovery_codes` rows via `UserRecoveryCode::query()`. The
  BelongsToUser global scope filtered the read to the owner's `user_id`,
  yielding zero rows for a partner-owned query. Fixed the test to use
  `UserRecoveryCode::withoutGlobalScopes()` for that cross-user
  assertion. Production code (the raw-builder action) was always
  correct — this was a test-only scoping bug.
- **Files:** `Modules/Auth/tests/Feature/ManageUserPageTest.php`
- **Commit:** `d3bbb2c`

**3. [Rule 1 — Bug] RecoveryCodesDisplayTest copy assertion updated for
WR-07**
- The WR-07 copy fix trimmed the recovery-codes display subhead; the
  existing `RecoveryCodesDisplayTest` still asserted the old "— only
  regenerated" wording. Updated the assertion to the corrected copy.
- **Files:** `Modules/Auth/tests/Feature/RecoveryCodesDisplayTest.php`
- **Commit:** `d3bbb2c`

## Authentication Gates

None — no auth gate or credential prompt was encountered during
execution.

## Known Stubs

None. Every surface is wired end-to-end: `/reset-password` redeems a real
code and redirects to `/login`; `diederik:reset-password` updates a real
password row; `/settings/users/{username}` sets a real partner password
and regenerates real codes shown inline with a working `.txt` download.

## Threat Model Compliance

- T-12-06-01 (recovery-code brute force) — accepted per D-12: local-only
  deployment + bcrypt cost; failed attempts audited to `system_alerts`.
- T-12-06-02 (recovery-code replay) — mitigated: the unused-code lookup
  runs under `lockForUpdate()` inside the same transaction that stamps
  `used_at`; a concurrent reuse blocks on the row lock.
- T-12-06-03 (username enumeration via reset error) — mitigated: the
  wrong-code error copy is identical whether the username exists or the
  code is wrong; `RecoveryCodeAuthenticator::verify` returns `null` for
  both cases without distinguishing them.
- T-12-06-04 (non-developer reaching /settings/users/{username}) —
  mitigated: `RequireDeveloperMiddleware` (the `developer` route group)
  plus `ManageUserPage::mount`'s defensive `is_developer` check both
  raise 404; an unknown username also raises 404.
- T-12-06-05 (recovery-code use not logged) — mitigated: every
  `verify()` writes a `system_alerts` audit row.
- T-12-06-06 (weak password via reset/CLI) — mitigated:
  `ResetPasswordAction` and `ResetPasswordCommand` both enforce the
  12-character minimum.
- T-12-06-07 (scripted CLI reset) — mitigated: `diederik:reset-password`
  refuses non-interactive use; no `--password` flag.
- T-12-06-08 (regenerated codes leaking via the Livewire snapshot) —
  accepted per the 12-04 precedent: the ten plaintext codes sit on the
  `regeneratedCodes` public property for the single render so the owner
  can read and download them, the same bounded window the signup
  ceremony accepts.
- T-12-06-09 (hash-shape mismatch) — mitigated: the authenticator
  re-formats the normalised input into the exact shape SignupAction
  hashed; a round-trip test (issue via signup, redeem via the
  authenticator) catches a regression.
- T-12-06-SC (package installs) — no new dependencies; gate not
  triggered.

## TDD Gate Compliance

Both tasks followed RED → GREEN. Gate commits in order:
- `bd7b2cc` test(12-06) — RED (Task 1)
- `7b3b616` feat(12-06) — GREEN (Task 1)
- `2dc02fd` test(12-06) — RED (Task 2)
- `d3bbb2c` feat(12-06) — GREEN (Task 2)

## Task Commits

1. **Task 1: verification core + CLI**
   - `bd7b2cc` test(12-06) — RED: normalizer, authenticator, CLI tests
   - `7b3b616` feat(12-06) — GREEN: RecoveryCodeNormalizer,
     RecoveryCodeAuthenticator, ResetPasswordAction, ResetPasswordCommand,
     provider registration
2. **Task 2: reset page + owner-resets-partner surface**
   - `2dc02fd` test(12-06) — RED: ResetPasswordPage + ManageUserPage tests
   - `d3bbb2c` feat(12-06) — GREEN: RegenerateRecoveryCodesAction,
     ResetPasswordPage, ManageUserPage, blades, routes, registrations,
     WR-07 copy fix

## Test Coverage

- `RecoveryCodeNormalizerTest` — 3 cases (case-fold + strip,
  alphabet-only filtering, empty result).
- `RecoveryCodeAuthenticatorTest` — 8 cases (valid redeem + used_at
  stamp, single-use rejection, lowercase/hyphen-free acceptance,
  unknown-username null, wrong-code null, success + failure
  `system_alerts` rows, hash-shape round-trip).
- `ResetPasswordCommandTest` — 5 cases (valid interactive run, unknown
  username, short password, confirmation mismatch, non-interactive
  refusal).
- `ResetPasswordPageTest` — 7 cases (render copy, route name, redeem +
  redirect, success flash, wrong-code copy, mismatch copy, code not
  consumed on a pre-verification failure).
- `ManageUserPageTest` — 7 cases (regenerate invalidates + reissues, 404
  for a non-developer caller, page render, route 404s for non-developer /
  unknown username, set partner password + force flag, inline
  regenerated-code display).
- All 105 Auth-module tests pass (3789 assertions); the 37-test
  `BoundaryArchTest` suite passes; `composer analyse` (Larastan L10
  strict) and `composer format:check` (Pint) both clean.

## Self-Check: PASSED

- All 14 created files exist on disk — FOUND.
- Commits `bd7b2cc`, `7b3b616`, `2dc02fd`, `d3bbb2c` — all present in
  `git log`.
- `php artisan list | grep diederik:reset-password` matches;
  `php artisan route:list` shows `auth.reset-password` and
  `auth.users.manage`.

---
*Phase: 12-multi-user-activation*
*Completed: 2026-05-20*
