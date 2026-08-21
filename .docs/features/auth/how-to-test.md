# `Auth` — how to test

Practical recipes for exercising the `Auth` module in isolation.

## Unit tests

- **Location:** `Modules/Auth/tests/Unit/`
- **What they test:** the recovery-code generator's alphabet and group
  shape (`RecoveryCodeGeneratorTest`); the normaliser's whitespace +
  hyphen + case stripping (`RecoveryCodeNormalizerTest`); the schema
  reshape from the seeded Laravel `email`-driven users table to the
  username-driven one (`SchemaReshapeTest`); the model's casts +
  fillable + `BelongsToUser` composition (`UserRecoveryCodeTest`); the
  Fortify config rewiring (`FortifyConfigTest`).
- **Common stubs:** these are mostly pure-function tests on the recovery
  primitives — no stubs needed. The Fortify config test loads the bound
  config arrays through the container without booting an HTTP kernel.

## Feature tests

- **Location:** `Modules/Auth/tests/Feature/`
- **What they test:** end-to-end Livewire flows for every page
  (`LoginPageTest`, `SignupPageTest`, `ResetPasswordPageTest`,
  `ChangePasswordPageTest`, `RecoveryCodesDisplayTest`, `AddUserPageTest`,
  `ManageUserPageTest`); the `SignupAction` race-and-recovery code
  contract end-to-end (`SignupActionTest`); the recovery-code
  authenticator's match-and-stamp atomicity
  (`RecoveryCodeAuthenticatorTest`); the forced-password-change
  middleware's exempt-list (`ForcePasswordChangeMiddlewareTest`); the
  three console commands (`ResetPasswordCommandTest`,
  `GrantDevCommandTest`, `RegenerateRecoveryCodesCommandTest`); the
  cross-user 404 posture (`CrossUserIsolationTest`).
- **Setup:** every feature test uses `RefreshDatabase`. Tests that need a
  signed-in user typically run `SignupAction` first (to materialise the
  owner) and then sign in via Livewire's `actingAs($user)` helper.

## Contract / arch invariants

- `tests/Feature/CrossUserIsolationTest.php` — the cross-user 404 posture
  contract: any URL keyed by another user's id returns 404. Any new
  authenticated route that accepts a user-keyed parameter MUST be added
  to this test's data set.
- The repo-wide `tests/Contracts/BoundaryArchTest.php` enforces that no
  module outside `Modules\Auth\` imports `Modules\Auth\Internal\*`.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Auth/tests

# Just one feature
vendor/bin/pest Modules/Auth/tests/Feature/SignupActionTest.php

# Stop on first failure for a focused debug session
vendor/bin/pest Modules/Auth/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A new authenticated route returns 403 instead of 404 to a non-owner**
  — the route is missing the `developer` middleware alias or the action
  / Livewire `mount()` is throwing `AuthorizationException` instead of
  `NotFoundHttpException`. Pattern: always throw `NotFoundHttpException`
  for an owner-only surface. Verify with `CrossUserIsolationTest`.
- **A flagged user lands on a redirect loop after sign-in** — the route
  the user lands on is missing from the `ForcePasswordChangeMiddleware`
  exempt list. The exempt list is intentionally narrow (`change-password`
  and `logout`); the fix is to make the destination handle the forced-
  change posture itself, not to widen the exemption.
- **Recovery-code typed in mixed case fails to match** — confirm the
  normaliser is being called before the hash compare. Pattern:
  `$normalised = $normalizer->normalize($input)` then hash. A test that
  fails this way usually skipped the normaliser and hashed the raw input.
- **`SignupAction` returning 'Signup is closed' on the very first
  install** — the SQLite database file already carries a `users` row from
  a previous run. Run `php artisan migrate:fresh` to clear it.
- **Concurrent signups not racing as expected in tests** — the test must
  use the real SQLite (not `:memory:`) and drive two requests
  concurrently (e.g. via `pcntl_fork` in a Pest dataset or two parallel
  HTTP calls). The lock-promotion path is what serialises them; with
  `:memory:` SQLite the test runs serially and the race never appears.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Auth` module.

## Behavioral contracts

- **Signup is closed once the owner exists.** `FirstUserOnlyMiddleware`
  returns 404 from `/signup` the moment `users` has any row, and the
  inside-transaction count in `SignupAction` re-checks the same invariant
  even when two requests race. (`tests/Feature/SignupActionTest.php`,
  `tests/Feature/SignupPageTest.php`)
- **The first account is the owner.** `SignupAction` writes
  `is_developer = true` on the row it creates. Every later account
  created via `AddUserAction` is born with `is_developer = false`.
  (`tests/Feature/SignupActionTest.php`, `tests/Feature/AddUserPageTest.php`)
- **Signup hands the user exactly ten plaintext codes, exactly once.** The
  ten codes are persisted as bcrypt hashes only; the plaintext is
  returned by `SignupAction` and stashed in the session under
  `auth.signup.recovery_codes_plain` so `RecoveryCodesDisplay` can show
  them once and then forget. (`tests/Feature/RecoveryCodesDisplayTest.php`)
- **A recovery code is consumed atomically on first match.**
  `RecoveryCodeAuthenticator` performs the match-and-stamp in one update;
  reusing the same code raises a mismatch. The constant-time mismatch
  message never reveals whether the typed username existed.
  (`tests/Feature/RecoveryCodeAuthenticatorTest.php`,
  `tests/Feature/ResetPasswordPageTest.php`)
- **The owner can reset the partner; the partner cannot reset the owner.**
  Every owner-only surface (`ManageUserPage`, `AddUserPage`,
  `AddUserAction`) returns 404 to a non-developer caller — never 403, so
  the surface stays hidden from probes. (`tests/Feature/AddUserPageTest.php`,
  `tests/Feature/ManageUserPageTest.php`)
- **Owner-driven password resets force the partner to choose their own
  password on next sign-in.** `ManageUserPage::setPartnerPassword` writes
  `force_password_change_at_next_login = true`; the recovery-code-driven
  `ResetPasswordAction` writes it false because the user just chose the
  password they want. (`tests/Feature/ManageUserPageTest.php`,
  `tests/Feature/ForcePasswordChangeMiddlewareTest.php`)
- **No authenticated user is ever trapped behind the forced-change
  guard.** `ForcePasswordChangeMiddleware` exempts the
  `/change-password` page and `/logout` so a flagged user can always
  either fulfil the change or sign out.
  (`tests/Feature/ForcePasswordChangeMiddlewareTest.php`)
- **Cross-user reads / writes return 404, not 403.** A logged-in user
  probing any URL keyed by another user's id receives 404; the existence
  of partner accounts is never revealed.
  (`tests/Feature/CrossUserIsolationTest.php`)
- **The CLI escape hatch (`diederik:reset-password`) is the only path
  that bypasses the recovery-code requirement.** It is operator-only —
  it requires shell access to the SQLite file's host. See
  [ADR 0010](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0010-recovery-codes-no-smtp.md).
  (`tests/Feature/ResetPasswordCommandTest.php`)
- **Passwords are at least `PasswordPolicy::MINIMUM_LENGTH` characters.**
  Every write path (`SignupAction`, `AddUserAction`, `ResetPasswordAction`,
  `ResetPasswordCommand`, `ChangePasswordPage`,
  `ManageUserPage::setPartnerPassword`, and Mobile's
  `MobileImportBootstrap`) measures against that one constant and rejects
  shorter passwords with a `ValidationException` or inline message. The
  live checklist renders the same constant, so the browser cannot tick a
  rule the server refuses. (`SignupPageTest`, `MobileImportBootstrapTest`)
- **Recovery codes are distinct within a user's batch.** Both
  `SignupAction` and `AddUserAction` loop the generator until ten
  distinct values exist before inserting, so the unique `code_hash`
  index never rejects the batch insert.

## Edge cases

- **Concurrent first-launch signups** — `SignupAction` issues a no-op
  `UPDATE users SET id = id WHERE 0 = 1` before the existence check to
  promote the SQLite connection to a write lock. Without the lock two
  concurrent signups would both read a stale zero-count snapshot under
  WAL and both insert; with the lock the second blocks until the first
  commits and then observes the row and aborts.
- **Duplicate partner username** — `AddUserAction` catches the unique-
  constraint `QueryException` and rethrows as
  `ValidationException` keyed `username` with a friendly message.
- **Recovery-code collision in a single batch** — the generator loop
  draws a new code if it duplicates one already in the in-memory list.
  The unique `code_hash` DB index would also reject the second insert,
  but the loop short-circuits before the round-trip.
- **Reusing a consumed recovery code** — `RecoveryCodeAuthenticator`
  filters on `used_at IS NULL`, so a previously-stamped row is invisible
  and the constant-time mismatch message fires.
- **Probing for a partner that doesn't exist** — `ManageUserPage::mount`
  raises 404, identical to the response for "exists but caller is not
  the owner". Username enumeration is therefore blocked at the route
  level.
- **`UserInstalled` listener throws after the transaction commits** —
  the user row is durable; the listener exception bubbles up to the
  request handler but the install is still consistent. The
  `Categorization` default-category seeder is idempotent
  (see [`Categorization` specs](../categorization/how-to-test.md)) so the
  retry is safe.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `User` model, `Clock` contract,
    `BelongsToUser` trait, `CurrentUser` contract, `UserInstalled`
    event (which `Auth` raises but `Core` owns the class for).
- **Depended on by**
  - [`Categorization`](../categorization/how-to-test.md) — listens for
    `UserInstalled` to seed the default category tree.
  - [`Onboarding`](../onboarding/how-to-test.md) — listens for
    `UserInstalled` to prime the wizard's first-launch state.
  - [`EmailScan`](../email-scan/how-to-test.md) — reads the
    `oauth_secrets` table that `Auth`'s migration creates.
  - The shared layout — calls `CurrentUserService` from `Core`, which is
    populated by `LoginAction` / `LogoutAction`.

The arch invariants in [module-boundaries](../../architecture/module-boundaries.md)
forbid any other module from importing `Modules\Auth\Internal\*`.

## Configuration + feature flags

- `users.is_developer` — the per-user owner/partner discriminator.
  Toggleable from the CLI via `diederik:grant-dev` for operator break-glass.
- `users.force_password_change_at_next_login` — the per-user forced-change
  flag the middleware respects.
- No environment config keys; the module has no behaviour that varies by
  runtime (`BEATRAX_RUNTIME=local` vs `BEATRAX_RUNTIME=app`). The
  `diederik:reset-password` CLI is gated only by shell-access posture.
