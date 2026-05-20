---
phase: 12-multi-user-activation
reviewed: 2026-05-20T00:00:00Z
depth: standard
files_reviewed: 33
files_reviewed_list:
  - Modules/Auth/Database/Factories/UserRecoveryCodeFactory.php
  - Modules/Auth/Database/Migrations/2026_05_19_000001_drop_email_add_username_to_users_table.php
  - Modules/Auth/Database/Migrations/2026_05_19_000002_add_is_developer_to_users_table.php
  - Modules/Auth/Database/Migrations/2026_05_19_000003_add_force_password_change_to_users_table.php
  - Modules/Auth/Database/Migrations/2026_05_19_000004_create_user_recovery_codes_table.php
  - Modules/Auth/Database/Migrations/2026_05_19_000005_create_oauth_secrets_table.php
  - Modules/Auth/Internal/Fortify/FortifyServiceProvider.php
  - Modules/Auth/Internal/Http/Livewire/LoginPage.php
  - Modules/Auth/Internal/Http/Livewire/RecoveryCodesDisplay.php
  - Modules/Auth/Internal/Http/Livewire/SignupPage.php
  - Modules/Auth/Internal/Http/Middleware/FirstUserOnlyMiddleware.php
  - Modules/Auth/Internal/Recovery/RecoveryCodeFormatter.php
  - Modules/Auth/Internal/Recovery/RecoveryCodeGenerator.php
  - Modules/Auth/Models/UserRecoveryCode.php
  - Modules/Auth/Providers/AuthServiceProvider.php
  - Modules/Auth/Public/Actions/LoginAction.php
  - Modules/Auth/Public/Actions/LogoutAction.php
  - Modules/Auth/Public/Actions/SignupAction.php
  - Modules/Auth/Resources/views/livewire/login-page.blade.php
  - Modules/Auth/Resources/views/livewire/recovery-codes-display.blade.php
  - Modules/Auth/Resources/views/livewire/signup-page.blade.php
  - Modules/Auth/Routes/web.php
  - Modules/Auth/Routes/console.php
  - Modules/Core/Models/User.php
  - Modules/Core/Providers/CoreServiceProvider.php
  - Modules/Core/Internal/Console/InstallCommand.php
  - Modules/Core/Internal/Http/Livewire/TopNav.php
  - Modules/Core/Resources/views/livewire/top-nav.blade.php
  - Modules/EmailScan/Database/Factories/OAuthSecretFactory.php
  - Modules/EmailScan/Models/OAuthSecret.php
  - bootstrap/providers.php
  - config/fortify.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Contracts/UserIdColumnArchTest.php
findings:
  critical: 3
  warning: 7
  info: 5
  total: 15
status: issues_found
---

# Phase 12: Code Review Report

**Reviewed:** 2026-05-20T00:00:00Z
**Depth:** standard
**Files Reviewed:** 33
**Status:** issues_found

## Summary

Phase 12 introduces the `Modules/Auth/` bounded module: username-based
authentication via Fortify, a first-user signup ceremony issuing ten
single-use recovery codes, and a reshaped `users` schema (email dropped,
username added). The recovery-code generator uses a CSPRNG correctly, the
signup transaction re-checks `User::count()` inside the transaction, and
the encrypted `oauth_secrets` casts are wired soundly.

However the submission ships with three blocker-grade defects: the recovery
codes are hashed with **bcrypt's 72-byte / NUL-truncation contract being
relied on for a 24-character secret** (acceptable) but the **recovery-code
verification path does not exist yet while the codes are issued and the
`/reset-password` link is live** — and more seriously the signup
race-condition guard is defeated by SQLite's transaction isolation, the
recovery-code `code_hash` collision/uniqueness is unprotected, and the
`force_password_change_at_next_login` flag is written but never enforced by
any middleware, leaving a documented security control inert. Seven warnings
and five info items follow.

## Critical Issues

### CR-01: Signup race-condition guard does not hold under SQLite's default isolation

**File:** `Modules/Auth/Public/Actions/SignupAction.php:68-75`
**Issue:** The docblock and the inline comment claim that re-checking
`table('users')->count()` *inside* the transaction makes two concurrent
signups safe — "the second observes the first user and aborts." This is
false for the project's database. SQLite (the mandated store, see CLAUDE.md)
in WAL mode gives `SELECT` statements a *read* snapshot; a plain
`->count()` does not acquire a write lock and does not promote the
transaction to `BEGIN IMMEDIATE`. Two transactions can both run the count
before either has inserted a row, both see `0`, and both proceed to
`User::create()`. The first `INSERT` upgrades that transaction to a write
lock; the second then either (a) also commits — producing two users — if it
ran on a separate connection that already had its snapshot, or (b) fails
with `SQLITE_BUSY` rather than the friendly `ValidationException`. The guard
gives a false sense of safety. A correct guard must either rely on a `UNIQUE`
constraint that makes the second insert fail deterministically, or take an
explicit write lock first.
**Fix:** The signup ceremony creates "the first user" — the genuine
invariant is *at most one row in `users` when signup is permitted*. Enforce
it at the schema level rather than with an application count. The simplest
robust fix: add a partial/whole unique guarantee, or perform the existence
check with a locking read. For SQLite, force the transaction into write mode
up front:

```php
$result = $this->db->connection()->transaction(function () use ($username, $password): array {
    // Force an immediate write lock so a concurrent signup cannot also
    // pass the existence check on a stale read snapshot.
    $this->db->connection()->statement('UPDATE users SET id = id WHERE 0 = 1');

    if ($this->db->connection()->table('users')->count() > 0) {
        throw ValidationException::withMessages([
            'signup' => 'Signup is closed on this device.',
        ]);
    }
    // ... create user + codes
});
```

Or, preferably, drop the count entirely and catch the unique-constraint
violation from a deterministic schema guarantee. The current code must not
ship while claiming concurrency safety it does not provide.

### CR-02: `force_password_change_at_next_login` is a documented security control with no enforcement

**File:** `Modules/Auth/Database/Migrations/2026_05_19_000003_add_force_password_change_to_users_table.php:11-19`, `Modules/Core/Models/User.php:18-19`, `Modules/Auth/Public/Actions/LoginAction.php:28-44`
**Issue:** The migration docblock states the flag, "when true ... the user
must set a new password before any other authenticated action is allowed."
The model docblock repeats it. No code in this phase enforces it. `LoginAction`
logs the user in via `$guard->login($user, $rememberMe)` and returns `true`
with zero inspection of `force_password_change_at_next_login`; no middleware
in `Modules/Auth/Routes/web.php` or registered globally checks the flag. A
user flagged for a forced password change is granted a fully authenticated
session and unrestricted access to every authenticated route. This is an
authentication-control bypass: the control exists in the schema and the
documentation but is inert. Either the enforcement middleware must land in
this phase, or the column/docblocks must not claim an enforced behaviour
that does not exist (CLAUDE.md: "Docs describe current state, never
history" — and never aspirational behaviour either).
**Fix:** Add a `ForcePasswordChangeMiddleware` (registered on the `auth`
middleware group, exempting the password-update route) that redirects to a
password-reset page when the flag is set; OR if enforcement is genuinely a
later plan, strip the "must ... before any other authenticated action is
allowed" claim from the migration and model docblocks so they describe only
what the code does today (stores a boolean).

### CR-03: Recovery codes are issued and a recovery link is live, but no verification path exists — codes can never be redeemed

**File:** `Modules/Auth/Public/Actions/SignupAction.php:86-97`, `Modules/Auth/Resources/views/livewire/login-page.blade.php:54-61`, `Modules/Auth/Routes/web.php`
**Issue:** Signup persists ten hashed `user_recovery_codes` rows and shows
the plaintext once. The login page renders a live link
`<a href="/reset-password">Lost your password? Use a recovery code.</a>`.
There is no `/reset-password` route in `Modules/Auth/Routes/web.php`, no
controller/Livewire component, and no action that consumes a
`UserRecoveryCode` (no code reads `code_hash`, checks it, or stamps
`used_at`). A user who loses their password and follows the documented
recovery affordance hits a 404. The recovery codes — the project's *only*
password-reset mechanism (`config/fortify.php` comment: "password reset uses
recovery codes") — are write-only. For a single-user local app where the
owner has no other way back in, this is a data-loss / lockout risk: forget
the password and the database is unrecoverable despite the user having dutifully
saved their codes.
**Fix:** Either land the recovery-code redemption flow (`ResetPasswordAction`
is already allow-listed in `tests/Contracts/BoundaryArchTest.php:1054`,
showing it was scoped) in this phase, or remove the dead `/reset-password`
link from `login-page.blade.php` until the redemption path exists, so the UI
does not promise a recovery route that 404s. Shipping issued-but-unredeemable
codes plus a broken recovery link is not an acceptable end-state for a
vertical slice.

## Warnings

### WR-01: Recovery-code download is exposed on a GET route reachable by any authenticated user, leaking the codes after the ceremony window if the session key lingers

**File:** `Modules/Auth/Internal/Http/Livewire/RecoveryCodesDisplay.php:50-64`, `Modules/Auth/Routes/web.php:29`
**Issue:** `RecoveryCodesDisplay` reads the plaintext codes from session key
`auth.signup.recovery_codes_plain`. The key is only forgotten in
`continueAfterSave()`, which requires the confirmation checkbox. A user who
reaches the page and navigates away *without* ticking the box (closes the
tab, hits back) leaves the plaintext recovery codes sitting in the session
indefinitely. Anyone with that session — including the same browser later,
or session-fixation/-theft scenarios — can revisit `/recovery-codes` and
re-download the full set. The docblock claims "completing the ceremony
forgets the session key so the codes can never be shown again," but the
only path that forgets the key is the *happy* path.
**Fix:** Forget the session key the first time the page is rendered after the
codes have been displayed (e.g. stash a one-shot flag, or forget on `mount()`
after copying into a request-scoped variable), or expire the key with a short
TTL. At minimum, `download()` should not be re-runnable after the ceremony
is complete.

### WR-02: `download()` mutates component state on a method that returns a streamed response — `downloadShown` flag is unreliable

**File:** `Modules/Auth/Internal/Http/Livewire/RecoveryCodesDisplay.php:50-64`
**Issue:** `download()` sets `$this->downloadShown = true` and then returns a
`StreamedResponse`. When a Livewire action returns a response object,
Livewire short-circuits and streams the file; the component is not
re-rendered and the mutated `downloadShown` property is not guaranteed to be
flushed back to the browser snapshot in a way that updates the
"Saved as ..." text. The blade at `recovery-codes-display.blade.php:25`
gates that text on `$downloadShown`, so the confirmation message may never
appear even though the download succeeded — a confusing UX, and the property
write is effectively dead code.
**Fix:** Drive the "downloaded" affordance from a client-side event or accept
that the message will not render and remove the `$downloadShown` property and
its blade branch. Do not rely on property mutation surviving a response-
returning action.

### WR-03: `created_at` passed to `UserRecoveryCode::create()` is silently dropped — column relies on the SQLite default instead

**File:** `Modules/Auth/Public/Actions/SignupAction.php:91-96`, `Modules/Auth/Models/UserRecoveryCode.php:42-55`
**Issue:** `SignupAction` passes `'created_at' => $now` into
`UserRecoveryCode::query()->create([...])`, but `created_at` is not in the
model's `$fillable` (`UserRecoveryCode.php:42-46` lists only `user_id`,
`code_hash`, `used_at`). With `$timestamps = false`, Eloquent does not
auto-manage it either. The `created_at` key is therefore stripped by
mass-assignment guarding and the column is populated only by the migration's
`useCurrent()` default. The injected `Clock` value is ignored. This works by
accident, but it means the `Clock` abstraction is bypassed (tests that freeze
the clock will not freeze `created_at`), and the explicit `$now` argument is
misleading dead input.
**Fix:** Either add `created_at` to `$fillable` so the injected clock value is
honoured, or drop `'created_at' => $now` from the `create()` payload and the
`$now` variable, and document that the column is DB-defaulted.

### WR-04: `user_recovery_codes.user_id` and `oauth_secrets.user_id` are nullable, allowing orphan credential rows

**File:** `Modules/Auth/Database/Migrations/2026_05_19_000004_create_user_recovery_codes_table.php:29`, `Modules/Auth/Database/Migrations/2026_05_19_000005_create_oauth_secrets_table.php:34`
**Issue:** Both tables declare `foreignId('user_id')->nullable()`. A recovery
code or an OAuth secret with a null owner is meaningless — a recovery code
must belong to the user it can reset, an OAuth secret must belong to the user
whose inbox it authorises. The nullability is presumably to satisfy the
`UserIdColumnArchTest` ("every domain table has a *nullable* user_id column"),
but that arch rule is about multi-user *readiness* for domain substrate, not
a licence to permit orphaned auth credentials. A nullable `user_id` on a
credentials table is a security smell: a row with `user_id IS NULL` escapes
the `BelongsToUser` global user scope and could be read across users.
**Fix:** If the arch test forces nullability, document the exemption
explicitly; otherwise make `user_id` non-nullable on these two tables — a
recovery code or OAuth secret with no owner is never valid. Confirm the
`BelongsToUser` `UserScope` behaviour for `NULL` user_id rows does not leak
them.

### WR-05: No uniqueness or collision guard on `user_recovery_codes.code_hash`; duplicate plaintext codes can be issued

**File:** `Modules/Auth/Internal/Recovery/RecoveryCodeGenerator.php:27-41`, `Modules/Auth/Public/Actions/SignupAction.php:86-97`
**Issue:** `SignupAction` generates ten codes in a loop with no check that the
ten plaintext values are distinct. With ~99 bits of entropy a collision is
astronomically unlikely, so this is not a correctness blocker — but the
ceremony presents the user with a printed list and a `.txt` file; two
identical lines would be confusing and would silently reduce the effective
code count. More importantly, when redemption lands, a non-unique `code_hash`
column means a redeemed-code lookup could match the wrong row. There is no
`unique` index on `code_hash`.
**Fix:** De-duplicate the generated set in the loop (regenerate on collision),
and add a `unique` index on `code_hash` in the migration so the redemption
path can rely on at-most-one match.

### WR-06: `FirstUserOnlyMiddleware` count check is a TOCTOU window against `SignupAction`'s own check

**File:** `Modules/Auth/Internal/Http/Middleware/FirstUserOnlyMiddleware.php:27-37`, `Modules/Auth/Routes/web.php:17-19`
**Issue:** The middleware gates `GET /signup` on `users()->count() > 0`, then
`SignupAction` re-checks inside the transaction. The middleware only guards
the `GET` that renders the form — the actual `submit` runs through the
Livewire component endpoint (`/livewire/update`), which is **not** behind
`FirstUserOnlyMiddleware`. So the middleware does not actually gate the write
path at all; it only hides the form page. A client that already has the
Livewire component snapshot can POST a signup after a user exists, and only
`SignupAction`'s (flawed, see CR-01) transaction check stands between that
and a second user. The middleware gives the impression of a guarded write
surface that it does not provide.
**Fix:** This is acceptable *if* `SignupAction`'s guard is made correct
(CR-01). Document that the middleware is a UX gate only and that the real
guard is the action's transactional/constraint check — and ensure that check
is sound.

### WR-07: `RecoveryCodesDisplay` is on the `auth` route group — codes are gated by being logged in, but the page 404s for any logged-in user post-ceremony

**File:** `Modules/Auth/Routes/web.php:22-29`, `Modules/Auth/Internal/Http/Livewire/RecoveryCodesDisplay.php:96-106`
**Issue:** `/recovery-codes` sits behind `auth`. After signup the user *is*
logged in, so the ceremony works. But the page throws `NotFoundHttpException`
whenever the session key is absent. Combined with WR-01 (key only cleared on
the happy path), the behaviour is inconsistent: a user who completes the
ceremony correctly gets a 404 if they navigate back; a user who abandons it
keeps the codes live. The 404-on-missing-key is reasonable, but the codes
have no "regenerate" entry point in this phase (the blade text at
`recovery-codes-display.blade.php:5` promises codes are "only regenerated" —
another route that does not exist, mirroring CR-03).
**Fix:** Ensure the "only regenerated" copy is not shown until a regeneration
route exists, or land `RegenerateRecoveryCodesAction` (already allow-listed
in `BoundaryArchTest.php:1055`). Keep the 404 behaviour but make the session-
key lifecycle consistent per WR-01.

## Info

### IN-01: `LoginPage::submit()` accepts `UrlGenerator $urls` but `LoginAction` does the auth work — unused-looking parameter is actually fine, but `$rememberMe` default-true contradicts a "calm/private" local app

**File:** `Modules/Auth/Internal/Http/Livewire/LoginPage.php:34`, `Modules/Auth/Resources/views/livewire/login-page.blade.php:36`
**Issue:** `rememberMe` defaults to `true`. For a local-only financial app on
a shared family machine (the project explicitly anticipates a partner), a
default-on persistent login means the next person at the keyboard is silently
authenticated as the previous user. This is a deliberate UX choice, not a
bug, but worth flagging given the multi-user direction of this very phase.
**Fix:** Consider defaulting `rememberMe` to `false`, or leave as-is if the
single-machine threat model accepts it. No code change required.

### IN-02: `FortifyServiceProvider` registers an authenticator pipeline that omits throttling — only acceptable because of the local-only constraint

**File:** `Modules/Auth/Internal/Fortify/FortifyServiceProvider.php:62-65`
**Issue:** The pipeline drops `RedirectIfTwoFactorAuthenticatable` and any
throttle/`EnsureLoginIsNotThrottled` middleware. The docblock justifies this
("local-only, single-machine"). `config/fortify.php` also sets
`limiters.login => null`. Consistent and documented; flagged only so a future
multi-machine deployment revisits it.
**Fix:** None for v1. Revisit if the app is ever exposed beyond localhost.

### IN-03: `FortifyServiceProvider` and `LoginAction` duplicate the same username-normalisation + credential-check logic

**File:** `Modules/Auth/Internal/Fortify/FortifyServiceProvider.php:42-60`, `Modules/Auth/Public/Actions/LoginAction.php:28-44`
**Issue:** The `Fortify::authenticateUsing` closure and `LoginAction::__invoke`
contain byte-for-byte the same normalise-lookup-hashcheck logic. Given the
Auth module routes login through `LoginPage` -> `LoginAction` (not Fortify's
own login route — `config/fortify.php` `features => []`), the Fortify closure
may be dead in practice. Two copies of an auth primitive drift over time.
**Fix:** Have `LoginAction` be the single credential verifier and have the
Fortify closure delegate to it, or confirm the Fortify closure is reachable
and consolidate the normalisation into one private helper.

### IN-04: `RecoveryCodeGenerator` entropy comment is slightly overstated

**File:** `Modules/Auth/Internal/Recovery/RecoveryCodeGenerator.php:9-17`
**Issue:** The docblock claims "roughly 99 bits of entropy per code." 20
characters from a 31-symbol alphabet is `20 * log2(31)` ≈ `20 * 4.954` ≈
`99.1` bits — accurate. Minor: the comment says "twenty characters" but the
emitted string is 24 characters including four hyphens; a careless reader may
size a DB column wrong. Not a bug.
**Fix:** Clarify "twenty alphabet characters in a 24-character hyphenated
string."

### IN-05: `OAuthSecretFactory` uses `array_rand` (non-CSPRNG) for provider selection — acceptable in a test factory

**File:** `Modules/EmailScan/Database/Factories/OAuthSecretFactory.php:31-32`
**Issue:** `array_rand` is not cryptographically secure. This is a test
fixture factory, so it is out of scope as a security finding — flagged only
for completeness. The recovery-code generator correctly uses `random_int`.
**Fix:** None required; test code.

---

_Reviewed: 2026-05-20T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
