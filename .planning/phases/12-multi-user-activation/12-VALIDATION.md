---
phase: 12
slug: multi-user-activation
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-19
---

# Phase 12 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source: `.planning/phases/12-multi-user-activation/12-RESEARCH.md` §Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.x (PHPUnit 11 engine) + pest-plugin-arch + pest-plugin-laravel + spatie/pest-plugin-snapshots |
| **Config file** | `phpunit.xml` (project root) + `tests/Pest.php` |
| **Quick run command** | `pest --filter=<TestName>` |
| **Full suite command** | `pest --parallel` |
| **Estimated runtime** | ~45 seconds (full suite) |

---

## Sampling Rate

- **After every task commit:** Run quick test relevant to the slice (e.g. `pest Modules/Auth/tests/Unit/RecoveryCodeGeneratorTest.php`)
- **After every plan wave:** Run `pest --parallel --filter='Auth\\|Boundary\\|CrossUser\\|OAuthSecrets'`
- **Before `/gsd:verify-work`:** `pest --parallel` (full suite) + `composer analyse` (Larastan L10 strict) + `composer format:check`
- **Max feedback latency:** < 60 seconds

---

## Per-Task Verification Map

| Req ID | Behavior | Test Type | Automated Command | File Exists |
|--------|----------|-----------|-------------------|-------------|
| MULTI-01 | `CurrentUser` contract bound and resolvable | unit | `pest tests/Contracts/BoundaryArchTest.php --filter='noAuthFacadeOrHelper'` | ❌ Wave 0 |
| MULTI-01 | `Auth::user()` / `auth()` / `request()->user()` / `request()->session()` forbidden across all modules | arch | `pest tests/Contracts/BoundaryArchTest.php --filter='noAuthFacadeOrHelper'` | ❌ Wave 0 |
| MULTI-02 | `/login` Volt page renders + accepts valid credentials | feature | `pest Modules/Auth/tests/Feature/LoginPageTest.php` | ❌ Wave 0 |
| MULTI-02 | `/signup` returns 404 when `User::count() > 0` | feature | `pest Modules/Auth/tests/Feature/SignupPageTest.php --filter='returns 404 when first user already exists'` | ❌ Wave 0 |
| MULTI-02 | `/login` accepts `username` field, not `email` | feature | `pest Modules/Auth/tests/Feature/LoginPageTest.php --filter='username field'` | ❌ Wave 0 |
| MULTI-02 | Session driver = `database`; remember-me works | feature | `pest Modules/Auth/tests/Feature/LoginPageTest.php --filter='remember-me'` | ❌ Wave 0 |
| MULTI-03 | `BelongsToUser` global scope active on every domain model | arch + integration | `pest tests/Contracts/UserIdColumnArchTest.php` + new `BelongsToUserScopeTest.php` | ❌ Wave 0 |
| MULTI-03 | Cross-user 404-not-403 on every model-scoped route | feature (parameterized) | `pest Modules/Auth/tests/Feature/CrossUserIsolationTest.php` | ❌ Wave 0 |
| MULTI-04 | Recovery code generator produces D-11 format | unit | `pest Modules/Auth/tests/Unit/RecoveryCodeGeneratorTest.php` | ❌ Wave 0 |
| MULTI-04 | `/reset-password` accepts username + code + new password | feature | `pest Modules/Auth/tests/Feature/ResetPasswordTest.php` | ❌ Wave 0 |
| MULTI-04 | Recovery code one-time login + force-password-change flag set | feature | `pest Modules/Auth/tests/Feature/LoginPageTest.php --filter='recovery code login'` | ❌ Wave 0 |
| MULTI-04 | Used codes stamped `used_at`, not deleted | feature | `pest Modules/Auth/tests/Feature/RecoveryCodesTest.php --filter='used_at preserves audit chain'` | ❌ Wave 0 |
| MULTI-04 | Owner-resets-partner: button visible only if `is_developer = true` | feature | `pest Modules/Auth/tests/Feature/ManageUserPageTest.php --filter='developer-only'` | ❌ Wave 0 |
| MULTI-04 | `diederik:reset-password <username>` CLI works | feature | `pest Modules/Auth/tests/Feature/ConsoleCommandsTest.php --filter='reset password'` | ❌ Wave 0 |
| MULTI-04 | `diederik:regenerate-recovery-codes <username>` CLI works | feature | `pest Modules/Auth/tests/Feature/ConsoleCommandsTest.php --filter='regenerate recovery'` | ❌ Wave 0 |
| MULTI-05 | `oauth_secrets` table exists with correct shape + unique `(user_id, provider)` | integration | `pest Modules/EmailScan/tests/Unit/OAuthSecretsRepositoryTest.php --filter='schema'` | ❌ Wave 0 |
| MULTI-05 | `OAuthSecretsRepository::saveProviderClient` scopes to current user | feature | `pest Modules/EmailScan/tests/Unit/OAuthSecretsRepositoryTest.php --filter='per-user scoping'` | ❌ Wave 0 |
| MULTI-05 | Encrypted columns survive a roundtrip | feature | `pest Modules/EmailScan/tests/Unit/OAuthSecretsRepositoryTest.php --filter='encrypted cast'` | ❌ Wave 0 |
| MULTI-05 | Legacy `email-oauth.json` is renamed to `.bak` on migration | feature | `pest Modules/Auth/tests/Feature/LegacyJsonRenameTest.php` | ❌ Wave 0 |
| MULTI-05 | All existing EmailScan + Receipts tests still pass after rewire | feature (regression) | `pest Modules/EmailScan/tests Modules/Receipts/tests` | ✅ (existing suites) |
| MULTI-06 | `ImpersonateUserAction` requires correct developer password | feature | `pest Modules/Auth/tests/Feature/ImpersonationActionTest.php --filter='wrong password'` | ❌ Wave 0 |
| MULTI-06 | `ImpersonateUserAction` sets `auth.impersonating.original_user_id` session key | feature | `pest Modules/Auth/tests/Feature/ImpersonationActionTest.php --filter='session pivot'` | ❌ Wave 0 |
| MULTI-06 | `ImpersonationBannerMiddleware` renders banner when session key present | feature | `pest Modules/Auth/tests/Feature/ImpersonationBannerTest.php` | ❌ Wave 0 |
| MULTI-06 | `EndImpersonationAction` restores original user via `loginUsingId(original_user_id)` | feature | `pest Modules/Auth/tests/Feature/ImpersonationActionTest.php --filter='return to self'` | ❌ Wave 0 |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `Modules/Auth/` — entire module skeleton (provider, module.json, dirs)
- [ ] `Modules/Auth/tests/` — every test file listed in the table above
- [ ] `Modules/Auth/Database/Factories/UserRecoveryCodeFactory.php`
- [ ] `Modules/EmailScan/Models/OAuthSecret.php` + factory
- [ ] `tests/Contracts/BoundaryArchTest.php` — append `noAuthFacadeOrHelper` rule
- [ ] No framework install needed — Pest, plugins, all already present

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Recovery codes display + `.txt` download UX feels calm + readable on a real screen | MULTI-04 | Visual quality / readability cannot be asserted in code | Sign up first user, observe inline page, click "Download .txt", confirm filename `diederik-recovery-codes-<username>.txt` and one code per line |
| Owner reads recovery code aloud to partner (phone-readable D-11 alphabet) | MULTI-04 | Verifies the no-ambiguous-char alphabet works in spoken hand-off | Owner generates new codes for partner, reads one aloud, partner types it into `/reset-password` |
| Impersonation banner (warning amber, "Acting as X — Return to self") is dismissable cue | MULTI-06 | Visual quality / colour calibration is human-judgement | Trigger `ImpersonateUserAction` from tinker, log in, observe banner on every authenticated page |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
