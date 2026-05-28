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
