---
phase: 12-multi-user-activation
verified: 2026-05-20T17:46:00Z
status: gaps_found
score: 1/5 must-haves verified
overrides_applied: 0
gaps:
  - truth: "Two users can sign up, log in, and each see only their own transactions / chains / forecasts — verified by a cross-user 404-not-403 Pest test set covering every route registered in Modules/*/Routes/web.php"
    status: failed
    reason: "Five cross-user test files exist (Chains, DriftAlerts, Forecasting, Recurring, EmailScan) but Categorization (/uncategorized, /rules), Core (/, /settings), and the full Ledger transaction list (/transactions) have no dedicated cross-user isolation tests. The partner user has never been created in any test; every auth test uses a single user. The SC explicitly requires 'every route registered in Modules/*/Routes/web.php' — that is not met."
    artifacts:
      - path: "Modules/Categorization/tests/"
        issue: "No cross-user isolation test for /uncategorized or /rules routes"
      - path: "Modules/Core/tests/"
        issue: "No cross-user isolation test for / (dashboard) or /settings routes"
      - path: "Modules/Ledger/tests/"
        issue: "Has TransactionDetailReclassifyTest and TransactionDetailFxRateTest for /transactions/{id} but no cross-user test for GET /transactions (list) — data isolation through BelongsToUser not proven from a second-user perspective"
    missing:
      - "A cross-user 404 / data-isolation Pest test set that explicitly creates two users (owner + partner) and verifies every auth-gated route in every module's web.php"

  - truth: "The owner can reset a partner's password via the profile-selector UI (recovery-codes flow); the partner sees the new code without any SMTP dependency; php artisan diederik:reset-password CLI fallback works the same way"
    status: failed
    reason: "No /reset-password route registered. No ResetPasswordAction class. No diederik:reset-password artisan command. The login page renders a live link href='/reset-password' (login-page.blade.php:56) that resolves to 404. Recovery codes are write-only — they are issued and hashed but can never be redeemed. The 12-REVIEW.md CR-03 called this a blocker and it remains unresolved."
    artifacts:
      - path: "Modules/Auth/Public/Actions/"
        issue: "Only LoginAction, LogoutAction, SignupAction exist — ResetPasswordAction missing"
      - path: "Modules/Auth/Routes/web.php"
        issue: "No /reset-password route registered"
      - path: "Modules/Auth/Resources/views/livewire/login-page.blade.php"
        issue: "Line 56 renders a dead link href='/reset-password' pointing at a 404"
    missing:
      - "ResetPasswordAction.php (already in the noAuthFacadeOrHelper allow-list)"
      - "A /reset-password Livewire page that accepts username + recovery code + new password"
      - "Recovery-code redemption logic (reads code_hash, checks it, stamps used_at)"
      - "php artisan diederik:reset-password <username> command"
      - "Owner-resets-partner flow (referenced in SC-2 but no AddUserAction or partner profile UI)"

  - truth: "Per-user OAuth secrets live in a SQLite-encrypted oauth_secrets table keyed by user_id; the legacy single-file storage/app/secrets/imap.json is migrated in-place; OAuthSecretsRepository swap is transparent to every existing EmailScan consumer"
    status: failed
    reason: "The oauth_secrets table and OAuthSecret model exist and are correct (encrypted casts, BelongsToUser). However OAuthSecretsRepository in Modules/EmailScan/Public/Services/OAuthSecretsRepository.php still reads from the JSON file at storage/app/secrets/email-oauth.json (PATH_RELATIVE = 'app/secrets/email-oauth.json' confirmed on line 41). No swap to the DB table has occurred. The OAuthSecretsRepository constructor takes only a Filesystem — no CurrentUser or DatabaseManager dependency. EmailScan consumers currently read/write JSON, not the oauth_secrets table. No plan in phase 12 claimed MULTI-05."
    artifacts:
      - path: "Modules/EmailScan/Public/Services/OAuthSecretsRepository.php"
        issue: "Still reads from email-oauth.json. No user_id scope. No DB-backed implementation."
    missing:
      - "Rewrite OAuthSecretsRepository to use the oauth_secrets Eloquent model and CurrentUser dependency"
      - "Migration or script to rename email-oauth.json to email-oauth.json.pre-phase-12.bak (per D-18/D-19)"
      - "OAuthSecretsRepository bindings updated in EmailScanServiceProvider"
      - "Existing OAuthSecretsRepository tests updated to use DB-backed implementation"

  - truth: "The owner can switch profile to act as the partner (during debugging) via the app menu without a full logout/login dance — session lifecycle handled by Laravel session driver compatible with the upcoming NativePHP bundle"
    status: failed
    reason: "ImpersonateUserAction, EndImpersonationAction, and ImpersonationBannerMiddleware do not exist. No /impersonate route or app-menu entry. These three files are forward-declared in the noAuthFacadeOrHelper allow-list (BoundaryArchTest.php lines 1056-1061) but the actual files are absent. No plan in phase 12 claimed MULTI-06."
    artifacts:
      - path: "Modules/Auth/Public/Actions/ImpersonateUserAction.php"
        issue: "Missing — in allow-list but not created"
      - path: "Modules/Auth/Public/Actions/EndImpersonationAction.php"
        issue: "Missing — in allow-list but not created"
      - path: "Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php"
        issue: "Missing — in allow-list but not created"
    missing:
      - "ImpersonateUserAction with password-verify + Auth::loginUsingId + session stash"
      - "EndImpersonationAction restoring original_user_id from session"
      - "ImpersonationBannerMiddleware for persistent banner when acting as partner"
      - "Routes and app-menu wiring for the switch"
      - "Pest feature test invoking the action directly"

  - truth: "CR-01 (from 12-REVIEW.md): Signup race-condition guard does not hold under SQLite WAL — the count() inside the transaction does not acquire a write lock"
    status: failed
    reason: "SignupAction.php line 71 still uses db->connection()->table('users')->count() inside the transaction without a preceding write-lock statement. The code comment says 'Re-check inside the transaction' but SQLite WAL SELECT snapshots do not promote to a write lock. A concurrent signup can bypass this check. The 12-REVIEW.md CR-01 blocker is unresolved."
    artifacts:
      - path: "Modules/Auth/Public/Actions/SignupAction.php"
        issue: "Lines 68-75: count() inside transaction without write-lock promotion (no UPDATE ... WHERE 0=1 preamble)"
    missing:
      - "Force transaction into write mode before the count check (e.g. db->connection()->statement('UPDATE users SET id = id WHERE 0 = 1')) OR replace with a unique schema constraint that deterministically fails the second insert"

  - truth: "CR-02 (from 12-REVIEW.md): force_password_change_at_next_login is documented as enforced but no enforcement middleware exists"
    status: failed
    reason: "Modules/Core/Models/User.php docblock (line 18-19) states 'when force_password_change_at_next_login is set the user must replace their password before any other authenticated action proceeds.' No ForcePasswordChangeMiddleware exists. No /change-password route exists. The flag is stored but produces no authentication behavior. This is aspirational documentation shipping as current-state documentation, violating CLAUDE.md convention 'Docs describe current state, never history' — and the equivalent 'never aspirational'. The 12-REVIEW.md CR-02 blocker is unresolved."
    artifacts:
      - path: "Modules/Core/Models/User.php"
        issue: "Lines 18-19: docblock claims enforcement that does not exist"
    missing:
      - "Either: ForcePasswordChangeMiddleware redirecting flagged users to /change-password; OR rewrite User model docblock to remove the false 'must replace their password before any other authenticated action proceeds' claim"
---

# Phase 12: Multi-User Activation Verification Report

**Phase Goal:** Two users can sign up, log in, log out, and each sees only their own data; the codebase enforces this via the existing BelongsToUser global scope + a DI-friendly CurrentUserProvider contract, with Auth::user() / auth() / request()->user() forbidden by arch test across every module.
**Verified:** 2026-05-20T17:46:00Z
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Two users can sign up, log in, and each see only their own data — every route covered by cross-user 404 Pest tests | FAILED | 5 modules have cross-user tests; Categorization, Core, and Ledger list route do not. No test ever creates two users. |
| 2 | Owner can reset partner password via recovery-codes flow + CLI fallback | FAILED | No /reset-password route, no ResetPasswordAction, no diederik:reset-password command. Login page has a live dead link. |
| 3 | OAuth secrets in DB table; legacy JSON migrated; OAuthSecretsRepository swap transparent | FAILED | oauth_secrets table exists with correct schema and encrypted casts, but OAuthSecretsRepository still reads from email-oauth.json. No swap. |
| 4 | BoundaryArchTest::noAuthFacadeOrHelper green — Auth::user() / auth() / request()->user() / request()->session() forbidden across every module | VERIFIED | Test passes (1/1 assertions). Auth actions are in allow-list. CurrentUserService uses AuthFactory contract correctly. |
| 5 | Owner can switch profile to act as partner via app menu — no full logout/login | FAILED | ImpersonateUserAction, EndImpersonationAction, ImpersonationBannerMiddleware all missing. No route, no menu. |

**Score:** 1/5 truths verified

### Code Review Blockers (from 12-REVIEW.md — unresolved)

| # | Blocker | Status |
|---|---------|--------|
| CR-01 | Signup race guard ineffective under SQLite WAL (count() inside tx does not acquire write lock) | UNRESOLVED |
| CR-02 | force_password_change_at_next_login flag is documented as enforced but no middleware exists | UNRESOLVED |
| CR-03 | Recovery codes are write-only — /reset-password link is dead, no redemption path | UNRESOLVED (same as SC-2 failure) |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Auth/module.json` | Priority-1 module manifest | VERIFIED | Priority 1, Auth alias, correct provider |
| `Modules/Auth/Providers/AuthServiceProvider.php` | Service provider with DI bindings | VERIFIED | Binds all four plan actions + Livewire components |
| `Modules/Auth/Internal/Fortify/FortifyServiceProvider.php` | Username-based Fortify pipeline | VERIFIED | Authenticates by username, no rate limiter, empty features |
| `Modules/Auth/Public/Actions/LoginAction.php` | DI-based credential sign-in | VERIFIED | Constructor DI, bcrypt check, generic failure |
| `Modules/Auth/Public/Actions/LogoutAction.php` | Session-invalidating sign-out | VERIFIED | guard()->logout() + session invalidate + regenerateToken |
| `Modules/Auth/Public/Actions/SignupAction.php` | Atomic first-user signup | STUB | Works but race guard is false safety (CR-01) |
| `Modules/Auth/Public/Actions/ResetPasswordAction.php` | Recovery-code password reset | MISSING | Not created |
| `Modules/Auth/Public/Actions/ImpersonateUserAction.php` | Profile-switch action | MISSING | In allow-list but not created |
| `Modules/Auth/Public/Actions/EndImpersonationAction.php` | Restore-self action | MISSING | In allow-list but not created |
| `Modules/Auth/Public/Actions/AddUserAction.php` | Owner creates partner account | MISSING | In allow-list but not created |
| `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php` | Persistent impersonation banner | MISSING | In allow-list but not created |
| `Modules/Auth/Database/Migrations/...` (5 files) | Schema reshape + new tables | VERIFIED | All 5 migrations present and correct |
| `Modules/Auth/Models/UserRecoveryCode.php` | BelongsToUser recovery code model | VERIFIED | BelongsToUser, encrypted-cast-free, no updated_at |
| `Modules/EmailScan/Models/OAuthSecret.php` | Encrypted-cast OAuthSecret model | VERIFIED | BelongsToUser, encrypted casts on client_secret + tokens_blob |
| `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` | DB-backed per-user repo | STUB | Table and model exist but repo still reads JSON file |
| `tests/Contracts/BoundaryArchTest.php` noAuthFacadeOrHelper | Auth facade/helper ban | VERIFIED | Green, 11-path allow-list, Blade comment stripping |
| `php artisan diederik:reset-password` | CLI password reset fallback | MISSING | Command not registered |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| LoginPage | LoginAction | Livewire action-method DI | WIRED | submit(LoginAction $login, ...) |
| LoginAction | users.username | Eloquent where('username') | WIRED | strtolower + where('username') + hasher->check |
| SignupAction | user_recovery_codes | DB transaction | WIRED | 10 bcrypt-hashed rows inserted atomically |
| RecoveryCodesDisplay | auth.signup.recovery_codes_plain session | session->get() in mount/download/render | WIRED | reads fresh from session each request |
| OAuthSecretsRepository | email-oauth.json | Filesystem read | WIRED (wrong target) | Should be wired to oauth_secrets table but is not |
| ImpersonateUserAction | Auth::loginUsingId | allow-listed guard call | NOT_WIRED | Action does not exist |
| /reset-password route | ResetPasswordAction | route handler | NOT_WIRED | Route and action both missing |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `RecoveryCodesDisplay` | `$codes` (from `codesFromSession()`) | `session->get('auth.signup.recovery_codes_plain')` | Yes — set by SignupAction post-commit | FLOWING |
| `LoginPage` | `$flashMessage` | `LoginAction::__invoke` return bool | Yes — driven by bcrypt check | FLOWING |
| `OAuthSecretsRepository` | client credentials | `email-oauth.json` via Filesystem | Yes (JSON) but WRONG source — should be oauth_secrets table | HOLLOW — wired to wrong backend |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| noAuthFacadeOrHelper arch test passes | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter=noAuthFacadeOrHelper` | 1 passed (1 assertion) | PASS |
| GET /login returns 200 | Route registered as `GET /login LoginPage::class` | Route exists in route list | PASS |
| GET /signup returns 200 (fresh DB) | Route exists behind FirstUserOnlyMiddleware | Route exists | PASS |
| GET /reset-password returns route | No /reset-password route registered | Route not found | FAIL |
| `diederik:reset-password` command exists | `php artisan list` | Not listed | FAIL |

### Requirements Coverage

| Requirement | Source Plan(s) | Description | Status | Evidence |
|-------------|---------------|-------------|--------|---------|
| MULTI-01 | 12-01, 12-02 | CurrentUserProvider DI contract bound in Modules/Auth/ + arch test | PARTIAL | noAuthFacadeOrHelper green; but no CurrentUserProvider interface created in Modules/Auth/ — binding remains in CoreServiceProvider. The arch-test half is complete; the contract-binding half is not. |
| MULTI-02 | 12-03, 12-04 | Fortify login / signup / logout / session lifecycle | DELIVERED | /login, /logout, /signup, /recovery-codes all functional. Session driver = database. Remember-me wired. 14 passing feature tests. |
| MULTI-03 | 12-02 (schema) | BelongsToUser scope + cross-user 404-not-403 on every route | PARTIAL | BelongsToUser on all per-user models. 5 modules have cross-user tests. Categorization, Core, and Ledger list are uncovered. "Every route" is not met. |
| MULTI-04 | 12-04 (partial) | Recovery-code password reset + owner-resets-partner + CLI | PARTIAL | 10 codes generated at signup, bcrypt-hashed, stored. But no redemption path (/reset-password 404, no ResetPasswordAction, no diederik:reset-password command). Issue-but-no-redeem is CR-03. |
| MULTI-05 | No plan | Per-user OAuth secrets table + OAuthSecretsRepository swap | NOT STARTED | oauth_secrets table and OAuthSecret model created (12-02). OAuthSecretsRepository still reads JSON. No plan claimed this requirement. |
| MULTI-06 | No plan | Profile selector + quick-switch via app menu | NOT STARTED | ImpersonateUserAction, EndImpersonationAction, ImpersonationBannerMiddleware all absent. No plan claimed this requirement. |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `Modules/Core/Models/User.php` | 18-19 | Docblock claims `force_password_change_at_next_login` forces redirect before other actions — no such enforcement exists | BLOCKER | False documentation promises security behavior the code does not implement (CR-02 unresolved) |
| `Modules/Auth/Resources/views/livewire/login-page.blade.php` | 56 | `href="/reset-password"` dead link — no route registered | BLOCKER | User follows recovery link and hits 404; effectively locked out if password forgotten (CR-03) |
| `Modules/Auth/Public/Actions/SignupAction.php` | 69-75 | `count()` inside SQLite transaction without write-lock promotion; false concurrency safety | BLOCKER | Under SQLite WAL two concurrent signups can both pass the count check and create duplicate owner accounts (CR-01) |
| `Modules/Auth/Resources/views/livewire/recovery-codes-display.blade.php` | 5 | "They will not be shown again — only regenerated" — no regeneration route exists | WARNING | Promises a capability that does not exist (WR-07 from review) |
| `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` | 41 | `PATH_RELATIVE = 'app/secrets/email-oauth.json'` — MULTI-05 not started | BLOCKER | OAuthSecretsRepository reads JSON, not the oauth_secrets table; per-user isolation not enforced for OAuth credentials |

### Human Verification Required

None identified — all gaps are code-verifiable.

## Gaps Summary

Phase 12 delivered exactly what Plans 12-01 through 12-04 scoped: the Auth module skeleton, the schema reshape, the username-based Fortify login/logout pipeline, and the first-user signup ceremony with recovery-code generation. Those four plans pass their own acceptance criteria and the test suite is green (2058 passed, 6 skipped).

However, the phase goal requires TWO users, and the codebase delivers infrastructure for ONE. The six success criteria require work across seven dimensions that the four executed plans did not address:

1. **Recovery-code redemption (SC-2 / MULTI-04 partial):** Codes are issued but the /reset-password route, ResetPasswordAction, and CLI command are entirely missing. The 12-REVIEW.md CR-03 blocker stands.

2. **OAuthSecretsRepository swap (SC-3 / MULTI-05 not started):** The oauth_secrets table was built in Plan 12-02, but no plan connected OAuthSecretsRepository to it. The JSON-backed singleton is still the live implementation. MULTI-05 has no delivering plan.

3. **Cross-user test coverage (SC-1 / MULTI-03 partial):** Five of ten modules with auth routes have cross-user isolation tests. Categorization, Core, and the Ledger transaction list are unverified. The SC requires "every route."

4. **Profile switching / impersonation (SC-5 / MULTI-06 not started):** ImpersonateUserAction and companions are forward-declared in the allow-list but not implemented. MULTI-06 has no delivering plan.

5. **Unresolved code review blockers:** CR-01 (SQLite write-lock gap), CR-02 (false documentation of force_password_change enforcement), and CR-03 (dead /reset-password link) were surfaced in 12-REVIEW.md and none have been addressed.

The root cause is that Plans 12-05 through 12-07 (recovery/reset, OAuth migration, and impersonation/second-user) were planned but never executed. The phase is approximately 40-45% complete against its goal.

---

_Verified: 2026-05-20T17:46:00Z_
_Verifier: Claude (gsd-verifier)_
