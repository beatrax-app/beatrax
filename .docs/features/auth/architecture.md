# `Auth` — architecture

The `Auth` module owns every credential the app holds for a human: usernames,
password hashes, single-use recovery codes, and the OAuth secrets connected
inboxes write through. It hosts the sign-in / sign-up / change-password /
reset-password Livewire surface, the recovery-code display, and the owner's
"manage users" view that creates partner accounts.

## What this module is for

A `diederik` install is local-only — no SMTP, no third-party identity
provider, no password-reset email link to fall back on. The user therefore
needs an authentication surface that closes that loop on its own:
twelve-character passwords, ten single-use recovery codes printed once at
signup, and a CLI escape hatch (`diederik:reset-password`) when the user
loses every code and is locked out of the machine. The trade-offs are
captured in [ADR 0010](../../adr/0010-recovery-codes-no-smtp.md).

The module also owns the asymmetric "owner / partner" model. The first
account created on a device is the owner: their `is_developer` flag is set
true at signup. The owner adds a partner via `/settings/users/new`, picks
the partner's initial password, and the partner is forced to change it on
first sign-in via the `force_password_change_at_next_login` flag. The same
owner can reset the partner's password from `/settings/users/{id}`; the
partner cannot reset the owner. The multi-user data scoping that backs
that asymmetry — every domain row carries `user_id` — is described in
[ADR 0008](../../adr/0008-multi-user-belongstouser.md).

What the module explicitly does NOT do: it never runs the
"send the user an email link" flow (no SMTP is in scope, see
[ADR 0010](../../adr/0010-recovery-codes-no-smtp.md)); it never reaches
into another module's tables (a partner has no automatic data — every
module's `BelongsToUser` rows are owner-scoped by default); and it never
exposes a cross-user surface (a probe for another user's resources returns
404, not 403, so the existence of the partner stays hidden).

## Module boundary

`Public/` exports six action classes that are the only sanctioned write
paths into the auth corpus:

- **Actions/** — `SignupAction`, `LoginAction`, `LogoutAction`,
  `AddUserAction`, `ResetPasswordAction`, `RegenerateRecoveryCodesAction`.
  Each is constructor-injectable, single-purpose, and runs the relevant
  database mutations inside one transaction.

`Internal/` is everything else:

- **Internal/Fortify/** — the project's `FortifyServiceProvider`, which
  rewires the upstream Fortify pipeline so the credential field is
  `username` (not `email`) and the only authentication views the framework
  resolves are the module's Livewire pages.
- **Internal/Recovery/** — the four classes implementing the recovery-code
  ceremony: `RecoveryCodeGenerator` (cryptographic PRNG over a 31-character
  phone-readable alphabet), `RecoveryCodeFormatter`, `RecoveryCodeNormalizer`,
  `RecoveryCodeAuthenticator` (the single sanctioned consume-on-success
  path).
- **Internal/Http/Livewire/** — the seven Livewire pages (`LoginPage`,
  `SignupPage`, `ResetPasswordPage`, `ChangePasswordPage`,
  `RecoveryCodesDisplay`, `AddUserPage`, `ManageUserPage`).
- **Internal/Http/Middleware/** — `FirstUserOnlyMiddleware` (gates the
  `/signup` route so it disappears the moment the owner exists),
  `ForcePasswordChangeMiddleware` (pushed onto the `auth` middleware
  group; redirects any authenticated user whose
  `force_password_change_at_next_login` is true to `/change-password`,
  exempting only that page and `/logout`), `RequireDeveloperMiddleware`
  (gates the owner-only routes like `/settings/users/new`).
- **Internal/Console/** — three artisan commands: `ResetPasswordCommand`
  (the CLI escape hatch), `GrantDevCommand`, `RegenerateRecoveryCodesCommand`.

The cross-user 404 posture is enforced as a contract test
(`tests/Feature/CrossUserIsolationTest.php`); any new authenticated surface
must keep that posture.

## Key services + events

- `SignupAction` — creates the first user + ten hashed recovery codes in
  one transaction. Promotes the connection to a write lock before the
  existence check so concurrent first-launch signups serialise rather than
  race. Dispatches `UserInstalled` after commit so first-install listeners
  (e.g. the default-category seeder in
  [`Categorization`](../categorization/architecture.md)) run identically
  whether the install ceremony is the GUI signup or the
  `beatrax:install` console path.
- `AddUserAction` — owner-creates-partner. Asserts the caller's
  `is_developer` flag and throws `NotFoundHttpException` (not 403) when it
  is false, so the probing surface stays hidden.
- `ResetPasswordAction` — recovery-code-driven password reset. Verifies
  the username + code through `RecoveryCodeAuthenticator` (which marks the
  code consumed atomically), writes the new password hash, and clears the
  forced-change flag. Never logs the user in; the reset flow ends at
  `/login`.
- `RecoveryCodeAuthenticator` — the sole sanctioned reader of
  `user_recovery_codes`. Hashes the typed code, finds the matching unused
  row, stamps `used_at` in one update, and returns the user. Constant-time
  message ("That username and recovery code do not match…") regardless of
  whether the username existed.

The module listens for nothing — every reaction it needs (the default
category seed, the wizard first-step priming) lives in a downstream
listener for `UserInstalled`, which the module dispatches.

## Data flow

The signup ceremony, walked end-to-end:

```
POST /signup
  → SignupPage::register()
    → SignupAction()
      ├─ TX BEGIN
      ├─ UPDATE users SET id=id WHERE 0=1   (write-lock promotion)
      ├─ SELECT count FROM users            (must be zero)
      ├─ INSERT users (is_developer=1, force_password_change=0)
      ├─ generate 10 distinct recovery codes
      ├─ INSERT × 10 user_recovery_codes
      └─ TX COMMIT
    → Dispatch UserInstalled(userId)        (after commit)
    → Guard::login($user)
    → session.put('auth.signup.recovery_codes_plain', $codesPlain)
  → redirect /recovery-codes (display once)
```

The owner-resets-partner flow:

```
GET /settings/users/{username}             (developer-gated)
ManageUserPage::mount → assert is_developer, else 404
POST ManageUserPage::setPartnerPassword
  → write partner row inline:
       password = new hash
       force_password_change_at_next_login = true   ← owner-driven case
  → on next partner sign-in, ForcePasswordChangeMiddleware
    redirects to /change-password
```

(The recovery-code-driven `ResetPasswordAction` is a separate path —
`/reset-password`, taken by a user who has lost their password but holds a
recovery code. It clears the forced-change flag because the user has just
chosen the password they want.)
