---
phase: 12-multi-user-activation
verified: 2026-05-20T21:30:00Z
status: passed
score: 5/5 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 1/5
  gaps_closed:
    - "Two users can sign up, log in, and each see only their own transactions / chains / forecasts — verified by a cross-user 404-not-403 Pest test set covering every route registered in Modules/*/Routes/web.php"
    - "The owner can reset a partner's password via the profile-selector UI (recovery-codes flow); the partner sees the new code without any SMTP dependency; php artisan diederik:reset-password CLI fallback works the same way"
    - "Per-user OAuth secrets live in a SQLite-encrypted oauth_secrets table keyed by user_id; the legacy single-file storage/app/secrets/imap.json is migrated/renamed in-place; OAuthSecretsRepository swap is transparent to every existing EmailScan consumer"
    - "The owner can switch profile to act as the partner via the app menu without a full logout/login dance — session lifecycle handled by a NativePHP-compatible session driver"
    - "CR-01 (signup race guard ineffective under SQLite WAL)"
    - "CR-02 (force_password_change_at_next_login is documented but not enforced)"
    - "CR-03 (recovery codes write-only, dead /reset-password link)"
  gaps_remaining: []
  regressions: []
---

# Phase 12: Multi-User Activation Verification Report

**Phase Goal:** Two users can sign up, log in, log out, and each sees only their own data; the codebase enforces this via the existing BelongsToUser global scope + a DI-friendly CurrentUserProvider contract, with Auth::user() / auth() / request()->user() forbidden by arch test across every module.
**Verified:** 2026-05-20T21:30:00Z
**Status:** passed
**Re-verification:** Yes — after gap closure by Plans 12-05, 12-06, 12-07, 12-08

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Two users can sign up, log in, and each see only their own data — every route covered by cross-user 404 Pest tests | VERIFIED | `CrossUserIsolationTest.php` — 10 tests pass; two users created in `beforeEach`; Categorization `/uncategorized` + `/rules`, Core `/` + `/settings`, and Ledger `GET /transactions` all covered; route-introspection guard asserts no uncovered auth GET route exists |
| 2 | Owner can reset partner password via recovery-codes flow + CLI fallback | VERIFIED | `/reset-password` route registered (`auth.reset-password`, guest group); `ResetPasswordAction.php` uses `RecoveryCodeAuthenticator`; `diederik:reset-password` command registered; `ManageUserPage` at `/settings/users/{username}` lets owner reset partner; login-page link resolves (no 404) |
| 3 | OAuth secrets in DB table; legacy JSON renamed; OAuthSecretsRepository swap transparent | VERIFIED | `OAuthSecretsRepository` constructor takes `DatabaseManager` + `CurrentUser` (no `Filesystem`); reads/writes `OAuthSecret` model scoped by `currentUser->id()` called fresh per method; migration `2026_05_20_000002_rename_legacy_email_oauth_json.php` renames to `.pre-phase-12.bak` idempotently; `EmitOAuthReauthRequiredAlert` fires once; cross-user isolation proven by `OAuthSecretsCrossUserTest` (3 tests pass) |
| 4 | BoundaryArchTest::noAuthFacadeOrHelper green — Auth::user() / auth() / request()->user() / request()->session() forbidden across every module | VERIFIED | Arch test passes 1/1 assertions; allow-list covers ImpersonateUserAction, EndImpersonationAction, ImpersonationBannerMiddleware, ResetPasswordAction, RegenerateRecoveryCodesAction |
| 5 | Owner can switch profile to act as partner via app menu — no full logout/login | VERIFIED | `ImpersonateUserAction.php` verifies developer password then calls `loginUsingId`; `EndImpersonationAction.php` restores from session; `ImpersonationBannerMiddleware` shares `impersonatingPartnerUsername` with view layer; `resources/views/layouts/app.blade.php` renders `@include('auth::partials.impersonation-banner')` inside `@isset($impersonatingPartnerUsername)`; POST `/impersonate` + POST `/impersonate/end` routes registered; SESSION_DRIVER=database (NativePHP-compatible) |

**Score:** 5/5 truths verified

### Code Review Blockers (from 12-REVIEW.md — all resolved)

| # | Blocker | Status | Evidence |
|---|---------|--------|----------|
| CR-01 | Signup race guard ineffective under SQLite WAL (count() inside tx does not acquire write lock) | RESOLVED | `SignupAction.php:78` — `$this->db->connection()->statement('UPDATE users SET id = id WHERE 0 = 1')` before the count re-check promotes to immediate write lock |
| CR-02 | force_password_change_at_next_login flag documented as enforced but no middleware existed | RESOLVED | `ForcePasswordChangeMiddleware.php` — `final readonly class`; reads `$this->currentUser->user()->force_password_change_at_next_login`; redirects to `auth.change-password` except on `['auth.change-password', 'logout']`; registered on `auth` group via `$router->pushMiddlewareToGroup('auth', ForcePasswordChangeMiddleware::class)` in `AuthServiceProvider`; User model docblock now says "a request middleware redirects the user to the change-password page until they replace their password" |
| CR-03 | Recovery codes write-only — /reset-password link dead, no redemption path | RESOLVED | `ResetPasswordPage.php` + `/reset-password` (guest, named `auth.reset-password`) registered; `RecoveryCodeAuthenticator` verifies username + code with `lockForUpdate()` and stamps `used_at`; `login-page.blade.php:56` link `href="/reset-password"` resolves |

### D-08 Deferral Assessment (one-time login leg)

Decision D-08 specified two things a recovery code authorizes: (1) password-reset via `/reset-password`, and (2) a one-time login via `/login`. Only leg 1 is implemented. The background context documents this as an intentional deferral: a shared `RecoveryCodeAuthenticator` was built so a future plan can wire the login path. SC-2 asks about "the owner can reset a partner's password via the profile-selector UI (recovery-codes flow)" — that is leg 1, and it is fully delivered. SC-2 is satisfied; the one-time-login leg is a deferred enhancement, not a gap against SC-2.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Auth/Public/Actions/SignupAction.php` | Atomic first-user signup with write-lock preamble | VERIFIED | `UPDATE users SET id = id WHERE 0 = 1` write-lock promotion on line 78; de-dup loop for 10 codes |
| `Modules/Auth/Internal/Http/Middleware/ForcePasswordChangeMiddleware.php` | Redirect-on-flag enforcement | VERIFIED | `final readonly class`; reads `CurrentUser` contract; redirects to `auth.change-password` |
| `Modules/Auth/Internal/Http/Livewire/ChangePasswordPage.php` | Forced-password-change Livewire page | VERIFIED | File exists; renders `Set a new password`; clears flag on success |
| `Modules/Auth/Public/Actions/AddUserAction.php` | Owner-creates-partner action | VERIFIED | `final class`; is_developer check throws `NotFoundHttpException`; creates partner with `force_password_change_at_next_login = true` |
| `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php` | 404-not-403 developer gate | VERIFIED | `final readonly class`; throws `NotFoundHttpException` for non-developers |
| `Modules/Auth/Internal/Http/Livewire/AddUserPage.php` + blade | Owner-adds-partner page | VERIFIED | File exists; `Add a user` heading; `Set initial password` button |
| `Modules/Auth/Database/Migrations/2026_05_20_000001_add_unique_index_to_user_recovery_codes.php` | Unique index on code_hash | VERIFIED | File exists; `$t->unique('code_hash', ...)` in up() |
| `Modules/Auth/Internal/Recovery/RecoveryCodeNormalizer.php` | Normalize typed code to bare uppercase | VERIFIED | `final class`; strips chars outside `[A-NP-Z2-9]` |
| `Modules/Auth/Internal/Recovery/RecoveryCodeAuthenticator.php` | Username + code verification with used_at stamping | VERIFIED | `lockForUpdate()` on line 72; `used_at` stamped on line 83 |
| `Modules/Auth/Public/Actions/ResetPasswordAction.php` | Recovery-code-driven password reset | VERIFIED | `final class`; calls `$this->authenticator->verify()`; updates password + clears flag |
| `Modules/Auth/Public/Actions/RegenerateRecoveryCodesAction.php` | Invalidate-then-reissue 10 codes | VERIFIED | `final class`; stamps `used_at` on all unused codes; inserts 10 fresh rows |
| `Modules/Auth/Internal/Http/Livewire/ResetPasswordPage.php` + blade | `/reset-password` Livewire page | VERIFIED | File exists; `Reset your password` heading; `Save new password` button; inline help copy |
| `Modules/Auth/Internal/Http/Livewire/ManageUserPage.php` + blade | `/settings/users/{username}` developer manage page | VERIFIED | File exists; `Manage` heading; `Set new password for this user` + `Regenerate recovery codes for this user` sections |
| `Modules/Auth/Internal/Console/ResetPasswordCommand.php` | `diederik:reset-password` CLI command | VERIFIED | `class ResetPasswordCommand extends Command`; `signature = 'diederik:reset-password {username}'`; refuses non-interactive use |
| `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` | DB-backed per-user OAuth repo | VERIFIED | Constructor: `DatabaseManager` + `CurrentUser`; `providerRow()` scopes by `currentUser->id()` called fresh |
| `Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php` | One-way rename of legacy JSON file | VERIFIED | `LEGACY_RELATIVE = 'app/secrets/email-oauth.json'`; `BACKUP_RELATIVE = '...pre-phase-12.bak'`; idempotent; `down()` is no-op |
| `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php` | First-boot re-authorize warning | VERIFIED | Wired in `EmailScanServiceProvider`; fires `oauth.reauth_required` / `warning`; de-duped |
| `Modules/Auth/Public/Actions/ImpersonateUserAction.php` | Password-verified profile-switch action | VERIFIED | `final class`; `Hasher::check` before swap; `loginUsingId` via `StatefulGuard`; session keys stashed |
| `Modules/Auth/Public/Actions/EndImpersonationAction.php` | Restore-self action | VERIFIED | `final class`; reads `auth.impersonating.original_user_id`; restores guard; clears keys |
| `Modules/Auth/Public/Dto/ImpersonationResult.php` | Static-factory result DTO | VERIFIED | `final class`; `success()`, `wrongPassword()`, `notAllowed()`, `invalidTarget()` factories |
| `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php` | Persistent impersonation banner middleware | VERIFIED | `final readonly class`; shares `impersonatingPartnerUsername` when session key present |
| `Modules/Auth/Resources/views/partials/impersonation-banner.blade.php` | Amber banner partial | VERIFIED | `role="alert"`; `bg-amber-50`; `Acting as ... — Return to self` |
| `resources/views/layouts/app.blade.php` | Banner rendered in layout | VERIFIED | `@isset($impersonatingPartnerUsername)` on line 13; `@include('auth::partials.impersonation-banner', ...)` |
| `Modules/Auth/tests/Feature/CrossUserIsolationTest.php` | Two-user 404-not-403 isolation matrix | VERIFIED | 10 tests pass; covers Categorization, Core, Ledger-list gaps; route-introspection regression guard |
| `tests/Contracts/BoundaryArchTest.php` noAuthFacadeOrHelper | Auth facade/helper ban | VERIFIED | 1/1 assertion passes; all new allow-list entries present |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `ForcePasswordChangeMiddleware` | `users.force_password_change_at_next_login` | `CurrentUser->user()` flag read | WIRED | Redirects to `auth.change-password` unless route exempted |
| `ForcePasswordChangeMiddleware` | `auth` middleware group | `AuthServiceProvider::pushMiddlewareToGroup` | WIRED | Registered on line 87 of `AuthServiceProvider.php` |
| `ImpersonationBannerMiddleware` | `resources/views/layouts/app.blade.php` | `View::share('impersonatingPartnerUsername')` + `@isset` | WIRED | Layout lines 13-15 render banner when variable present |
| `ResetPasswordPage` | `ResetPasswordAction` | Livewire action-method DI | WIRED | `submit(ResetPasswordAction $reset, UrlGenerator $urls)` |
| `ResetPasswordAction` | `RecoveryCodeAuthenticator` | Constructor DI | WIRED | `RecoveryCodeAuthenticator $authenticator` in constructor |
| `RecoveryCodeAuthenticator` | `user_recovery_codes.used_at` | `lockForUpdate()` + `forceFill` / `update()` | WIRED | Lines 72-83 in `RecoveryCodeAuthenticator.php` |
| `/reset-password` route | `ResetPasswordPage` | Guest group, named `auth.reset-password` | WIRED | `Modules/Auth/Routes/web.php:25` |
| `OAuthSecretsRepository` | `oauth_secrets` table | `OAuthSecret` Eloquent model scoped by `currentUser->id()` | WIRED | `providerRow()` method; `currentUser->id()` called fresh |
| `ImpersonateUserAction` | `Auth::loginUsingId` | `StatefulGuard`-typed local via `AuthManager::guard()` | WIRED | Lines 84-86 of `ImpersonateUserAction.php` |
| `EndImpersonationAction` | session `auth.impersonating.*` keys | `Session::forget()` after guard restore | WIRED | Lines 51-52 of `EndImpersonationAction.php` |
| `CrossUserIsolationTest` | every Modules/*/Routes/web.php auth route | Route-table introspection + two-user `beforeEach` | WIRED | 24 assertions; route-introspection guard passes |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `RecoveryCodesDisplay` | `$codes` | `session->get('auth.signup.recovery_codes_plain')` | Yes — set by `SignupAction` post-commit | FLOWING |
| `OAuthSecretsRepository` | client credentials / tokens | `OAuthSecret::query()->where('user_id', ...)` | Yes — Eloquent with encrypted cast | FLOWING |
| `ManageUserPage` | `$regeneratedCodes` | `RegenerateRecoveryCodesAction::__invoke()` return | Yes — 10 plaintext codes from CSPRNG | FLOWING |
| `ImpersonationBannerMiddleware` | `impersonatingPartnerUsername` | `CurrentUser::user()->username` via active guard | Yes — live guard after `loginUsingId` swap | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| `noAuthFacadeOrHelper` arch test | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter=noAuthFacadeOrHelper` | 1 passed (1 assertion) | PASS |
| GET /reset-password resolves | Route registered in guest group as `auth.reset-password` | Route present; `ResetPasswordPageTest` confirms 200 | PASS |
| Cross-user isolation test | `vendor/bin/pest Modules/Auth/tests/Feature/CrossUserIsolationTest.php` | 10 passed (24 assertions) | PASS |
| Impersonation action tests | `vendor/bin/pest Modules/Auth/tests/Feature/ImpersonationActionTest.php ImpersonationBannerTest.php` | 28 passed (80 assertions, combined run) | PASS |
| OAuth cross-user isolation | `vendor/bin/pest Modules/EmailScan/tests/Feature/OAuthSecretsCrossUserTest.php` | 3 passed | PASS |
| Legacy JSON rename migration | `vendor/bin/pest Modules/EmailScan/tests/Feature/OAuthLegacyMigrationTest.php` | 6 passed | PASS |
| `diederik:reset-password` command | `ResetPasswordCommandTest.php` — 5 cases including non-interactive refusal | 5 passed | PASS |
| `diederik:reset-password` registered | `ResetPasswordCommand::class` in `AuthServiceProvider::boot()` `$this->commands([...])` | Registered | PASS |
| ForcePasswordChange enforced end-to-end | `ForcePasswordChangeMiddlewareTest.php` + redirect-on-flag test | All pass | PASS |
| Full Auth + EmailScan + BoundaryArch suite | `vendor/bin/pest Modules/Auth/tests/ Modules/EmailScan/tests/ tests/Contracts/BoundaryArchTest.php` | 409 passed (4737 assertions) | PASS |

Note on full-suite `artisan test` run: a pre-existing function name collision (`rpSeries()` defined in both `Modules/Recurring/tests/Feature/RecurringPageTest.php` and `Modules/Forecasting/tests/Unit/RangeProjectorTest.php`) causes a PHP fatal error when both suites are loaded in a single process. Both files predated Phase 12 and were only updated in Phase 12 to swap `email` fixtures for `username` — the collision itself was not introduced by Phase 12. The individual module suites run cleanly; the collision is a test-infrastructure issue outside Phase 12's scope.

### Requirements Coverage

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|-------------|-------------|--------|---------|
| MULTI-01 | 12-01, 12-02 | CurrentUserProvider DI contract + arch test forbidding Auth facade / helpers | SATISFIED | `noAuthFacadeOrHelper` arch test green; `CurrentUserService` bound in `CoreServiceProvider`; all module consumers use the `CurrentUser` contract |
| MULTI-02 | 12-03, 12-04 | Fortify login / signup / logout / session lifecycle in Volt+Flux UI | SATISFIED | `/login`, `/logout`, `/signup`, `/recovery-codes`, `/change-password` all functional; `SESSION_DRIVER=database`; remember-me wired |
| MULTI-03 | 12-08 | BelongsToUser scope + cross-user 404-not-403 on every route | SATISFIED | `CrossUserIsolationTest` — 10 tests; covers previously-uncovered Categorization + Core + Ledger-list routes; route-introspection regression guard |
| MULTI-04 | 12-06 | Recovery-code password reset + owner-resets-partner + CLI | SATISFIED | `/reset-password` page; `ResetPasswordAction`; `RecoveryCodeAuthenticator` with `lockForUpdate` + `used_at`; `diederik:reset-password` CLI; `ManageUserPage` for owner-resets-partner |
| MULTI-05 | 12-07 | Per-user OAuth secrets migration — JSON replaced by SQLite-encrypted `oauth_secrets` table | SATISFIED | `OAuthSecretsRepository` rewired to `OAuthSecret` Eloquent model scoped by `currentUser->id()`; legacy file renamed to `.pre-phase-12.bak` by migration; `EmitOAuthReauthRequiredAlert` de-duped warning |
| MULTI-06 | 12-08 | Profile selector + quick-switch via app menu | SATISFIED | `ImpersonateUserAction` + `EndImpersonationAction` + `ImpersonationBannerMiddleware` + `impersonation-banner.blade.php` + POST `/impersonate` + POST `/impersonate/end` routes; amber banner renders on every authenticated page while impersonating |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| None | — | — | — | All previously-flagged blockers and warnings resolved |

No `TBD`, `FIXME`, `XXX` markers found in any file modified by Plans 12-05 through 12-08. No `.planning/`, `PLAN.md`, `RESEARCH.md`, or `D-NN` references in any production source or test file modified by the gap-closure plans.

### Human Verification Required

None — all success criteria are verifiable from the codebase and test output.

## Gaps Summary

No gaps remain. All five ROADMAP success criteria for Phase 12 are met:

1. **SC-1 / MULTI-03** — `CrossUserIsolationTest` covers every auth-gated GET route including the three previously-uncovered routes (Categorization, Core, Ledger list); route-introspection regression guard is active.

2. **SC-2 / MULTI-04** — Recovery-code redemption end-to-end: `/reset-password` page + `RecoveryCodeAuthenticator` (single-use, `lockForUpdate`) + `ManageUserPage` owner-resets-partner surface + `diederik:reset-password` CLI. The one-time-login leg of D-08 is a documented deferral (shared `RecoveryCodeAuthenticator` built for future wiring) that does not affect SC-2's scope.

3. **SC-3 / MULTI-05** — `OAuthSecretsRepository` reads and writes `oauth_secrets` SQLite table per the current user's id; legacy `email-oauth.json` renamed to `.pre-phase-12.bak` by idempotent migration; first-boot re-authorize alert fires once. Per CONTEXT.md D-18/D-19, the rename (not data-migration) is the accepted policy — this is verified.

4. **SC-4 / MULTI-01** — `BoundaryArchTest::noAuthFacadeOrHelper` passes; all new actions that legitimately touch the guard are on the allow-list by name.

5. **SC-5 / MULTI-06** — `ImpersonateUserAction` password-verifies before the guard swap; amber banner renders on every authenticated page; `EndImpersonationAction` restores the original user; POST `/impersonate` (developer-gated) and POST `/impersonate/end` routes exist.

Code-review blockers CR-01, CR-02, and CR-03 are all resolved.

---

_Verified: 2026-05-20T21:30:00Z_
_Verifier: Claude (gsd-verifier)_
