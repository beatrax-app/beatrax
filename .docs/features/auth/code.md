# `Auth` — code

The file-level map for the module.

## Directory layout

```
Modules/Auth/
├── Public/
│   └── Actions/
│       ├── SignupAction.php
│       ├── LoginAction.php
│       ├── LogoutAction.php
│       ├── AddUserAction.php
│       ├── ResetPasswordAction.php
│       └── RegenerateRecoveryCodesAction.php
├── Internal/
│   ├── Fortify/
│   │   └── FortifyServiceProvider.php
│   ├── Recovery/
│   │   ├── RecoveryCodeGenerator.php
│   │   ├── RecoveryCodeFormatter.php
│   │   ├── RecoveryCodeNormalizer.php
│   │   └── RecoveryCodeAuthenticator.php
│   ├── Http/
│   │   ├── Livewire/
│   │   │   ├── LoginPage.php
│   │   │   ├── SignupPage.php
│   │   │   ├── ResetPasswordPage.php
│   │   │   ├── ChangePasswordPage.php
│   │   │   ├── RecoveryCodesDisplay.php
│   │   │   ├── AddUserPage.php
│   │   │   └── ManageUserPage.php
│   │   └── Middleware/
│   │       ├── FirstUserOnlyMiddleware.php
│   │       ├── ForcePasswordChangeMiddleware.php
│   │       └── RequireDeveloperMiddleware.php
│   ├── Console/
│   │   ├── ResetPasswordCommand.php
│   │   ├── GrantDevCommand.php
│   │   └── RegenerateRecoveryCodesCommand.php
│   └── Listeners/
├── Models/
│   └── UserRecoveryCode.php
├── Database/
│   ├── Migrations/
│   ├── Seeders/
│   │   └── Demo/DemoRecoveryCodesSeeder.php
│   └── Factories/
│       └── UserRecoveryCodeFactory.php
├── Routes/
│   ├── web.php
│   └── console.php
├── Resources/
│   └── views/livewire/*.blade.php
├── Providers/
│   └── AuthServiceProvider.php
└── tests/
    ├── Feature/
    └── Unit/
```

The `User` Eloquent model itself lives in
[`Modules/Core/Models/User.php`](../core/code.md), not in this module —
core owns users, sessions, and the global `BelongsToUser` trait every
domain model uses.

## Public API

- **Actions/**
  - `SignupAction` — first-user signup ceremony. Returns
    `['user' => User, 'codesPlain' => list<string>]` and logs the user in
    through the active guard.
  - `LoginAction` — username + password sign-in. Centralised so the route,
    the Livewire page, and any future API surface share one validation +
    rate-limit posture.
  - `LogoutAction` — sign-out. Symmetric counterpart to `LoginAction`.
  - `AddUserAction` — owner-creates-partner. Caller must be a developer;
    a non-developer caller raises `NotFoundHttpException`. Returns the
    created partner `User`.
  - `ResetPasswordAction` — recovery-code-driven password reset. Takes
    `(username, code, newPassword)`; throws `ValidationException` keyed
    `code` on mismatch (generic message; does not reveal whether the
    username existed).
  - `RegenerateRecoveryCodesAction` — invalidates the target user's unused
    codes and issues ten fresh ones. Two call paths: a user regenerates
    their own; the owner regenerates a partner's.

There are no DTOs, contracts, or events in `Public/` — the action surface
is the contract. The `UserInstalled` event the module raises lives in
[`Modules/Core/Public/Events`](../core/code.md) because it is part of
the cross-module "a user just appeared" surface.

## Internal services

- `Internal/Fortify/FortifyServiceProvider` — rewires the upstream
  `LaravelFortify` defaults: the credential field is `username`, all
  authentication views resolve to the module's Livewire pages, the
  `LoginResponse` honours an `intended` URL, and the `username` validation
  rule normalises to lowercase + trims whitespace.
- `Internal/Recovery/RecoveryCodeGenerator` — twenty characters drawn from
  a 31-character phone-readable alphabet (excludes I, L, O, 0, 1),
  formatted as five hyphen-separated groups of four. Every character is
  drawn with `random_int` (~99 bits of entropy per code).
- `Internal/Recovery/RecoveryCodeNormalizer` — strips whitespace, hyphens,
  and case from user input so the same code typed in any common shape
  hashes identically.
- `Internal/Recovery/RecoveryCodeFormatter` — the inverse of the
  normaliser, used when displaying stored or generated codes to humans.
- `Internal/Recovery/RecoveryCodeAuthenticator` — the sole sanctioned
  reader of `user_recovery_codes`. Normalises, hashes, finds the unused
  row, stamps `used_at` in one update, returns the user. Returns `null`
  on any mismatch (the action layer maps that to a constant-time
  validation message).
- `Internal/Http/Middleware/FirstUserOnlyMiddleware` — gates `/signup`.
  Returns 404 once `users` table has any row, so the signup surface
  disappears the moment the owner exists.
- `Internal/Http/Middleware/ForcePasswordChangeMiddleware` — pushed onto
  the `auth` middleware group. Redirects any authenticated user whose
  `force_password_change_at_next_login` is true to `/change-password`,
  exempting only that page and `/logout` so a flagged user can never get
  trapped.
- `Internal/Http/Middleware/RequireDeveloperMiddleware` — aliased
  `developer`; gates the owner-only routes.
- `Internal/Http/Livewire/ManageUserPage` — owner-resets-partner. Writes
  the partner row inline with `force_password_change_at_next_login = true`
  so the partner picks their own password on next sign-in.
- `Internal/Console/ResetPasswordCommand` — the `diederik:reset-password`
  CLI escape hatch. The user's last-resort recovery path when every
  recovery code is lost. See [ADR 0010](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0010-recovery-codes-no-smtp.md).

## Models + migrations

- `Models/UserRecoveryCode` — maps to `user_recovery_codes`. Uses
  `BelongsToUser` (see [ADR 0008](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0008-multi-user-belongstouser.md)).
  Auto-managed timestamps are disabled: the table writes its own
  `created_at` via a SQLite `useCurrent()` default, the only post-insert
  mutation is the `used_at` stamp, and there is no `updated_at` column.
  Casts: `used_at` and `created_at` as `immutable_datetime`.

Migrations:

- `2026_05_19_000001_drop_email_add_username_to_users_table.php` — drops
  Laravel's seeded `email` column and adds the username-driven shape.
- `2026_05_19_000002_add_is_developer_to_users_table.php` — adds the
  owner/partner discriminator.
- `2026_05_19_000003_add_force_password_change_to_users_table.php` —
  adds the forced-change flag.
- `2026_05_19_000004_create_user_recovery_codes_table.php` — creates the
  recovery-code audit chain.
- `2026_05_19_000005_create_oauth_secrets_table.php` — per-user OAuth
  credentials for connected email providers. Two columns
  (`client_secret`, `tokens_blob`) carry ciphertext; the consuming
  Eloquent model in [`EmailScan`](../email-scan/code.md) applies the
  `encrypted` cast.
- `2026_05_20_000001_add_unique_index_to_user_recovery_codes.php` — unique
  index on `code_hash` so a collision-on-insert raises a
  `QueryException`.
- `2026_05_20_000002_rename_legacy_email_oauth_json.php` — rename of an
  earlier per-user JSON column.

## Provider wiring

`AuthServiceProvider::register()`:

- Registers `Internal/Fortify/FortifyServiceProvider` so Fortify's
  pipeline is in place before any route resolves.
- Singletons every Public action and every recovery-code class so the
  same instance services every request (they are stateless aside from
  injected collaborators).

`AuthServiceProvider::boot()`:

- Loads the module's migrations, web/console routes, and views.
- Registers the three console commands when running in CLI.
- Aliases two middlewares: `first-user-only` and `developer`.
- Pushes `ForcePasswordChangeMiddleware` onto the `auth` middleware
  group, then prepends Laravel's `Authenticate` middleware onto the same
  group so the group still rejects guests before the forced-change guard
  runs.
- Registers the seven Livewire components under the `auth.*` namespace.
