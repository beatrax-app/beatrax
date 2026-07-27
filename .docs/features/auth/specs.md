# `Auth` — specs

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
- **Passwords are at least twelve characters.** Every write path
  (`SignupAction`, `AddUserAction`, `ResetPasswordAction`,
  `ChangePasswordPage`, `ManageUserPage::setPartnerPassword`) rejects
  shorter passwords with a `ValidationException` or inline message.
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
  (see [`Categorization` specs](../categorization/specs.md)) so the
  retry is safe.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/specs.md) — `User` model, `Clock` contract,
    `BelongsToUser` trait, `CurrentUser` contract, `UserInstalled`
    event (which `Auth` raises but `Core` owns the class for).
- **Depended on by**
  - [`Categorization`](../categorization/specs.md) — listens for
    `UserInstalled` to seed the default category tree.
  - [`Onboarding`](../onboarding/specs.md) — listens for
    `UserInstalled` to prime the wizard's first-launch state.
  - [`EmailScan`](../email-scan/specs.md) — reads the
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
