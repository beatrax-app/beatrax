---
phase: 12
plan: 08
subsystem: auth
tags: [auth, impersonation, profile-switch, cross-user-isolation, arch-test]
requires:
  - "12-05: partner-account creation (two users now possible)"
  - "12-07: per-user OAuth secrets (CurrentUser reads the live guard)"
provides:
  - "ImpersonateUserAction — password-verified profile switch"
  - "EndImpersonationAction — restore-self"
  - "ImpersonationResult — static-factory result DTO"
  - "ImpersonationBannerMiddleware — persistent amber banner on the auth group"
  - "POST /impersonate (developer-gated) + POST /impersonate/end (auth)"
  - "CrossUserIsolationTest — two-user 404-not-403 matrix over every auth route"
affects:
  - "resources/views/layouts/app.blade.php — renders the impersonation banner"
  - "Modules/Auth/Providers/AuthServiceProvider.php — banner middleware on the auth group"
tech-stack:
  added: []
  patterns:
    - "session-attribute pivot for impersonation (original_user_id stash)"
    - "route-table introspection as a cross-user-isolation regression guard"
key-files:
  created:
    - Modules/Auth/Public/Dto/ImpersonationResult.php
    - Modules/Auth/Public/Actions/ImpersonateUserAction.php
    - Modules/Auth/Public/Actions/EndImpersonationAction.php
    - Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php
    - Modules/Auth/Resources/views/partials/impersonation-banner.blade.php
    - Modules/Auth/tests/Feature/ImpersonationActionTest.php
    - Modules/Auth/tests/Feature/ImpersonationBannerTest.php
    - Modules/Auth/tests/Feature/CrossUserIsolationTest.php
  modified:
    - Modules/Auth/Routes/web.php
    - Modules/Auth/Providers/AuthServiceProvider.php
    - resources/views/layouts/app.blade.php
decisions:
  - "Banner middleware shares the acting username before next() so the page view has it at render time"
  - "Impersonation audit rows are queried withoutGlobalScopes in tests — the alert is owned by the developer but the guard resolves to the partner after the swap"
metrics:
  duration: ~40m
  completed: 2026-05-20
  tasks: 2
  files: 11
---

# Phase 12 Plan 08: Multi-User Activation Gap Closure Summary

Closes the last two unmet Phase 12 success criteria: password-verified profile
switching (MULTI-06) and a two-user cross-user 404-not-403 isolation matrix over
every auth-gated route (MULTI-03).

## What was built

### Task 1 — Profile switching / impersonation (MULTI-06)

- **`ImpersonateUserAction`** verifies the caller's own password via
  `Hasher::check` *before* the guard swap. It refuses non-developers
  (`not_allowed`), self-targets and missing targets (`invalid_target`), and a
  second switch while one is already active (`not_allowed` — no nesting). On a
  wrong password it returns `wrong_password` and never touches the guard. On
  success it stashes the original identity and calls `loginUsingId` on a
  `StatefulGuard`-typed local.
- **`EndImpersonationAction`** reads the stashed `original_user_id`, swaps the
  guard back, and clears both session keys. A no-op `not_allowed` when no switch
  is active.
- **`ImpersonationResult`** — private-constructor DTO with `success()`,
  `wrongPassword()`, `notAllowed()`, `invalidTarget()` factories and
  `isSuccess()`.
- **`ImpersonationBannerMiddleware`** — `final readonly`, pushed onto the `auth`
  middleware group. While the pivot key is set it shares
  `impersonatingPartnerUsername` (the *acting* user's username, read from the
  active guard via `CurrentUser`) **before** `next()` so the page view renders
  the banner.
- **Banner partial + layout** — amber `role="alert"` strip with the verbatim
  copy `Acting as {username} — Return to self`; the link POSTs to
  `auth.impersonate.end`. Added at the top of the `@auth` block in
  `layouts/app.blade.php`, above `core.top-nav`.
- **Routes** — `POST /impersonate` (developer-gated) and `POST /impersonate/end`
  (plain `auth`), registered and confirmed via `php artisan route:list`.

**Impersonation session keys:** `auth.impersonating.original_user_id` and
`auth.impersonating.original_username`.

**Audit:** every start writes `auth.impersonation_started` (warning), every end
`auth.impersonation_ended` (warning), every failed password
`auth.impersonation_failed` (critical) — `info`/`warning`/`critical` only, never
`error`.

### Task 2 — Cross-user 404-not-403 isolation matrix (MULTI-03)

`CrossUserIsolationTest` is the first Phase 12 test to create two users at once
(owner + partner). It enforces:

1. **Model-scoped routes** — `/transactions/{id}` and `/recurring/series/{id}`
   return HTTP 404, explicitly asserted *never* 403, when probed cross-user.
2. **List routes the verifier flagged** — Categorization `/uncategorized` +
   `/rules`, Core `/` + `/settings`, Ledger `GET /transactions` — each has a
   cross-user data-bleed assertion (the owner's merchant / rule token never
   appears in the partner's response).
3. **Positive impersonation check** — while the owner impersonates the partner,
   `/transactions` shows the partner's rows and not the owner's, proving reads
   route through the partner's `user_id` scope.
4. **Route-introspection regression guard** — iterates the router's GET routes
   carrying the `auth` middleware and fails the suite if any route name is
   neither in `ISOLATION_ROUTE_COVERED` nor in the allow-list.

**Route-coverage allow-list** (`ISOLATION_ROUTE_ALLOW_LIST`) — auth/account
plumbing surfaces that carry no foreign user data: `logout`,
`auth.change-password`, `auth.recovery-codes-display`, `auth.users.create`,
`auth.users.manage`, `auth.impersonate`, `auth.impersonate.end`.

## Deviations from Plan

None — plan executed as written. The three impersonation files were already
forward-declared on the `noAuthFacadeOrHelper` allow-list, so no arch-test edit
was needed (confirmed: the invariant passes unchanged).

## Cross-user leak findings

No cross-user data leak was discovered. Every probed route isolates correctly:
model-scoped routes 404, list routes show only the acting user's rows, and the
impersonation guard swap routes reads through the impersonated user's scope. No
follow-up bug to flag.

## All five Phase 12 ROADMAP success criteria

After this plan all five are met: username signup/login/logout, recovery-code
password reset, per-user OAuth secrets, profile switching (this plan, MULTI-06),
and cross-user 404-not-403 isolation over every route (this plan, MULTI-03).

## Tests

- `ImpersonationActionTest` — 9 tests (guard swap, audit rows, wrong password,
  non-developer, self-target, nesting refusal, end-restore, end-no-op, the two
  route probes).
- `ImpersonationBannerTest` — 2 tests (banner renders while impersonating,
  absent otherwise).
- `CrossUserIsolationTest` — 10 tests (two-user creation, model-scoped 404s,
  list-route isolation, impersonation positive check, route-introspection guard).
- Regression: `tests/Contracts/BoundaryArchTest.php` (37 passed),
  `tests/Contracts/UserIdColumnArchTest.php` (1 passed), full
  `Modules/Auth/tests/Feature` suite (101 passed).
- `composer analyse` (Larastan L10): 523 files, no errors. Pint: clean.

> Test note: `composer test` (`pest --parallel`) hits a pre-existing unrelated
> `rpSeries()` redeclaration fatal, so targeted non-parallel `vendor/bin/pest`
> commands were used for self-checks, per the plan's guidance.

## Self-Check: PASSED

- Files verified present: `ImpersonationResult.php`, `ImpersonateUserAction.php`,
  `EndImpersonationAction.php`, `ImpersonationBannerMiddleware.php`,
  `impersonation-banner.blade.php`, `ImpersonationActionTest.php`,
  `ImpersonationBannerTest.php`, `CrossUserIsolationTest.php`.
- Commits verified in git log: `2b630d8` (Task 1), `ab6a6fe` (Task 2).
