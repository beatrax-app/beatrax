# Phase 12: Multi-User Activation - Research

**Researched:** 2026-05-19
**Domain:** Auth wiring (Fortify + Livewire 4 Volt SFC + per-user data isolation + recovery codes + OAuth secrets rewire + impersonation back-end)
**Confidence:** HIGH

## Summary

Phase 12 activates the dormant multi-user schema that has been deliberately built into the
codebase since Phase 1. The work is fundamentally **wiring, not invention** — every load-bearing
contract (`CurrentUser`, `BelongsToUser`, `UserScope`) and every infrastructure piece
(Fortify in `composer.json`, sessions table, `system_alerts`, `database` session driver)
already exists. What is missing is (a) a real auth UI, (b) a real signup/login pipeline,
(c) recovery codes as a password-reset mechanism, (d) the per-user OAuth secrets table,
and (e) the impersonation back-end. CONTEXT.md D-01…D-27 lock 27 decisions, so research
focused entirely on **how to implement those decisions cleanly** under the project's DI-only
rule and three-new-modules architecture.

The single biggest mechanical risk in the phase is the new `noAuthFacadeOrHelper` arch-test
invariant: it must catch `Auth::user()`, `auth()`, `request()->user()`, `request()->session()`,
`session(` (helper), and `Auth::loginUsingId(` across every module while permitting an explicit
six-file allow-list inside `Modules/Auth/`. The pattern for this rule already exists in
`tests/Contracts/BoundaryArchTest.php` — the file uses two complementary mechanisms: Pest's
`arch()` plugin for namespace-level rules and bespoke recursive-grep-with-comment-stripping
`it()` tests for symbol-level rules. The latter is the right shape for `noAuthFacadeOrHelper`.

The second biggest mechanical risk is the impersonation pattern. D-22 specifies
`Auth::loginUsingId()` + session-key pivot. Web research surfaced a documented concern about
session bleed with this exact pattern; the safer ecosystem default is `Auth::onceUsingId()`
called per-request through a middleware. **However, D-22 is a locked decision and CONTEXT.md
explicitly says `CurrentUserService` is unchanged.** The session-pivot variant is workable
provided (a) the action regenerates the session ID after the swap (Laravel's
`$request->session()->regenerate()`) and (b) the `ImpersonationBannerMiddleware` runs after
`auth` so it sees the now-impersonated user via `CurrentUser`, not the original. This is
flagged as Pitfall #3 below.

**Primary recommendation:** Build the phase as 7 vertical slices (Wave shape — see Plan
Skeleton below): (1) Module skeleton + arch tests, (2) Schema reshape, (3) Fortify wiring
+ login Volt page, (4) Signup ceremony + recovery codes, (5) Force-password-change +
reset flow, (6) OAuth secrets rewire, (7) Impersonation back-end + cross-user 404 test set.
Each slice ends with an executable, tested capability.

## User Constraints (from CONTEXT.md)

### Locked Decisions

> Verbatim from CONTEXT.md `<decisions>` — D-01 through D-27 are locked. The planner must
> honor them as-given; no alternative architectures, no second-guessing the mechanism.

**Auth Surface + Signup Ceremony**

- **D-01:** New `Modules/Auth/` with Public (`LoginAction`, `SignupAction`, `LogoutAction`,
  `ResetPasswordAction`, `RegenerateRecoveryCodesAction`) and Internal (Livewire pages +
  Fortify provider) surfaces. Mirrors the structure of the other 11 modules.
- **D-02:** Login identifier is `username`, not `email`. Drop `email` column from users;
  add `username` (unique, citext-equivalent — store lowercase or maintain a
  `LOWER(username)` unique index). Fortify configured for `username`. No SMTP, no
  email verification, no password-reset emails anywhere.
- **D-03:** Public `/signup` is open ONLY when `User::count() === 0`. After the first
  user is created, `/signup` returns HTTP 404 (route-level, not 403). Owner creates
  the second user from an in-app "Add user" page behind `is_developer`.
- **D-04:** First-user-becomes-developer. Signup checks `User::count() === 0` inside
  the transaction; if true, sets `is_developer = true`. CLI escape hatch:
  `php artisan diederik:grant-dev <username>` (interactive confirm).
- **D-05:** Partner-account init: owner's "Add user" page collects username + initial
  password. New row born with `force_password_change_at_next_login = true`. No email.
- **D-06:** Recovery-codes display at signup: inline dedicated page, "Download .txt"
  produces `diederik-recovery-codes-<username>.txt`. Checkbox gates Continue. Never
  shown again — only regenerated.

**Recovery-Codes Mechanics**

- **D-07:** New table `user_recovery_codes` with `(id, user_id, code_hash, used_at,
  created_at)`. `code_hash` bcrypt-hashed (default cost). Non-unique index on
  `user_id`. Consumed codes get `used_at = now()` and are NOT deleted (audit trail).
- **D-08:** A code authorizes both: password-reset via `/reset-password` AND one-time
  login via `/login` (which sets `force_password_change_at_next_login = true`).
  `RecoveryCodeAuthenticator` is shared by both routes.
- **D-09:** Owner-resets-partner UI: profile page (only visible when `is_developer = true`)
  exposes "Set new password" + "Regenerate recovery codes" buttons.
- **D-10:** Regeneration: 10 new rows; old unused rows stamped `used_at = now()` (audit
  chain preserved). Nudge banner when `count(used_at IS NULL) <= 3`.
- **D-11:** Code format: 5 groups of 4 alphanumeric (uppercase + digits), no `O`, `0`,
  `I`, `1`, `L`. ~104 bits of entropy. Example `A2BJ-XK9M-PQ7N-RX4F-V8HD`.
- **D-12:** No app-level rate limit. Local-only + bcrypt cost is the defense. (Revisit
  only if remote-access tunnel ever ships.)
- **D-13:** Every recovery-code attempt emits a `system_alerts` row. `severity =
  warning` on success, `severity = error` on failure. Reuses
  `Modules\Core\Public\Services\SystemAlertQuery`.
- **D-14:** Two interactive-only CLI commands: `diederik:reset-password <username>` and
  `diederik:regenerate-recovery-codes <username>`. Refuse `--password=` style flags.

**`oauth_secrets` Table + JSON Migration**

- **D-15:** `oauth_secrets` schema: `(id, user_id, provider, client_id, client_secret,
  redirect_uri, tokens_blob, created_at, updated_at)` with unique `(user_id, provider)`.
- **D-16:** `OAuthSecretsRepository` constructor gains `CurrentUser` dependency. Every
  public method implicitly filters by `currentUser->id()`. Public signatures unchanged
  so EmailScan/Receipts/OAuth pipeline don't change.
- **D-17:** `client_secret` + `tokens_blob` use Laravel's `encrypted` cast (AES-256-CBC
  via APP_KEY). `client_id` + `redirect_uri` plaintext.
- **D-18:** **Delete** the JSON file as part of the migration; the operator
  re-authorizes both providers. A `system_alerts` warning fires:
  "OAuth secrets migrated to per-user table — re-authorize Gmail and Microsoft to
  resume email scanning." **Supersedes ROADMAP success criterion 3's "migrated
  in-place" phrasing.**
- **D-19:** Migration safety: before deleting JSON, rename to
  `email-oauth.json.pre-phase-12.bak` (chmod 0600). One-way; app never reads `.bak`.
  README at `storage/app/secrets/README.md` documents the rename + recovery path.

**"Act as Partner" Debug Switch**

- **D-20:** No UI in Phase 12. Ships back-end action + banner middleware only.
- **D-21:** Re-auth requirement: when (in Phase 16) the developer triggers the switch,
  a modal demands the developer's current password. Phase 12 ships the
  password-verification leg of the action.
- **D-22:** Switch mechanism: `Auth::loginUsingId(partner_id)` + session keys
  `auth.impersonating.original_user_id` and `auth.impersonating.original_username`.
  `CurrentUserService` is unchanged.
- **D-23:** Persistent non-dismissable header banner "Acting as `<partner-username>` —
  [Return to self]". Subtle warning amber. `ImpersonationBannerMiddleware` reads
  `session('auth.impersonating.original_user_id')` and renders via Flux Banner.

**Cross-Module Boundary Tests**

- **D-24:** `noAuthFacadeOrHelper` arch test forbids `Auth::user(`, `Auth::id(`,
  `auth()`, `request()->user(`, `request()->session(`, `session(` (helper), and
  `Auth::loginUsingId(` everywhere EXCEPT:
  - `Modules/Auth/Public/Actions/*.php`
  - `Modules/Auth/Internal/Fortify/**/*.php`
  - `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php`
- **D-25:** Cross-user 404-not-403 test set auto-generated by introspecting Laravel's
  route table. Every `auth`-middlewared route with a model-scoped parameter gets a test
  that creates two users, logs in as B, requests A's record, asserts HTTP 404.

**Sessions + Remember-Me**

- **D-26:** `SESSION_DRIVER=database` (already in v1.0).
- **D-27:** Standard Laravel "remember me" via `remember_token` (already present).
  5-year lifetime.

### Claude's Discretion

> Verbatim from CONTEXT.md `<decisions>` final block — Claude picks during planning.

- Exact wording and styling of the recovery-codes inline page (subject to UI-SPEC.md —
  already locked).
- Whether the `system_alerts` row for OAuth re-auth uses a fresh alert `kind` or an
  existing kind bucket. *(Recommended below: introduce `oauth.reauth_required` as a
  new kind, severity = warning.)*
- The shape of the boot-time check that fires the "re-authorize" alert. *(Recommended
  below: a deferred-boot listener that fires once per user, gated on a sentinel row.)*
- The exact code-format generation method (`Str::password()`-based vs `random_bytes()` +
  custom alphabet). *(Recommended below: **custom alphabet via `random_bytes()`** —
  `Str::password()` cannot exclude `O`/`0`/`I`/`1`/`L` per D-11.)*
- Global middleware vs per-route enforcement of `force_password_change_at_next_login`.
  *(Recommended below: **global middleware** in the `web` + `auth` stack with a tiny
  bypass whitelist.)*

### Deferred Ideas (OUT OF SCOPE)

> Verbatim from CONTEXT.md `<deferred>` — Phase 12 does NOT ship these.

- Per-device session management / "log me out of other devices"
- Email-based recovery (forgot username → email-me-a-link). Explicitly out of scope.
- Sentry / crash reporting (Phase 21 / beta decision)
- SQLCipher / DB-at-rest encryption
- TOTP / WebAuthn / passkeys as a second factor (room left in `Modules/Auth/Internal/`)
- Partner-shared "spaces" / read-write delegation
- Audit-log retention pruning
- **Note: ROADMAP success criterion 3 ("migrated in-place") is SUPERSEDED by D-18 (clean
  break + rename-to-`.bak`).**

## Phase Requirements

| ID | Description (from REQUIREMENTS.md) | Research Support |
|----|------------------------------------|------------------|
| MULTI-01 | `CurrentUserProvider` DI contract bound in `Modules/Auth/`, with arch-test forbidding `Auth::user()` / `auth()` / `request()->user()` / `request()->session()` across every module | `CurrentUser` interface already exists in Core. Phase 12 keeps it; arch-test pattern below (`noAuthFacadeOrHelper`) implements the forbid rule with the D-24 allow-list. |
| MULTI-02 | Fortify login / signup / logout / session lifecycle in Flux + Volt UI; sessions via Laravel driver compatible with NativePHP; "remember me" cookie | Fortify ^1.21 already in `composer.json`; `config/fortify.php` already published; `SESSION_DRIVER=database` already configured. Volt SFC patterns documented below. |
| MULTI-03 | `BelongsToUser` global scope extension + cross-user 404-not-403 test set on every route | `BelongsToUser` + `UserScope` already work today (see Existing Assets). The 404 test set is new — route-introspection pattern documented below. |
| MULTI-04 | Recovery-codes password reset; owner-resets-partner; `diederik:reset-password` CLI fallback; NO SMTP | D-07..D-14 covered. `Str::password()` cannot honor D-11 alphabet — custom generator required. |
| MULTI-05 | Per-user OAuth secrets: JSON → SQLite-encrypted `oauth_secrets` keyed by `user_id`, `APP_KEY`-encrypted; `OAuthSecretsRepository` swap | D-15..D-19 covered. Schema + `encrypted` cast documented below. Migration safety pattern uses `Filesystem` injection (no `storage_path()` helper). |
| MULTI-06 | Profile selector + quick-switch via app menu (owner acts as partner during debugging) | Phase 12 ships back-end only (`ImpersonateUserAction` + `ImpersonationBannerMiddleware` + session-pivot). Phase 16 wires the UI. |

## Project Constraints (from CLAUDE.md)

These directives are extracted from `./CLAUDE.md` and carry the same authority as locked
decisions. The planner must verify each plan complies.

| # | Constraint | Source | How Phase 12 Honors It |
|---|------------|--------|------------------------|
| C-01 | **PHP 8.5 + Laravel 13** stack pin | CLAUDE.md "Constraints" | All migrations use Laravel 13 schema builder; PHP 8.5 features OK |
| C-02 | **Modular via `nwidart/laravel-modules`**; cross-module access via Public services or events; no module reaches into another's models/internals | CLAUDE.md "Constraints" | New `Modules/Auth/` follows existing Public/Internal split. `OAuthSecretsRepository` stays in `Modules/EmailScan/Public/` (consumed by Auth via constructor DI — Auth never reaches into EmailScan internals). |
| C-03 | **Larastan L10 strict + Pint + Pest** all green | CLAUDE.md "Constraints" | All new files declare strict types; final + readonly where appropriate; type all method signatures. |
| C-04 | **Vertical MVP per phase** — each phase ends with end-to-end demoable capability | CLAUDE.md "Constraints" | Plans below are vertical slices, not horizontal layers. |
| C-05 | **Idempotency** — all ingestion paths safe to re-run | CLAUDE.md "Constraints" | Not directly relevant to Phase 12 (no ingestion changes). |
| C-06 | **Multi-user readiness** — schema must permit a second user later without migration pain | CLAUDE.md "Constraints" | **This phase delivers the readiness.** |
| C-07 | **Secrets**: IMAP/OAuth credentials live in a local config file, not the DB | CLAUDE.md "Constraints" | **Phase 12 INTENTIONALLY VIOLATES this v1 constraint** per CONTEXT.md D-15..D-19: per-user OAuth secrets move to a SQLite-encrypted table. CLAUDE.md predates v2.0; the v2.0 milestone supersedes it for this specific item. **Flag for the planner: document the supersession in the migration's docblock.** |
| C-08 | **DI-only — no facades, no global helpers** (`auth()`, `Auth::user()`, `base_path()`, `storage_path()`, etc.) | CLAUDE.md + user memory `feedback_laravel_di_only.md` | This phase EXTENDS the rule (`noAuthFacadeOrHelper`). The allow-list is the only exception (D-24). |
| C-09 | **Codebase stays GSD-agnostic** — no `.planning/`, PLAN.md, RESEARCH.md, or D-NN references in code/PHPDocs/comments | user memory `feedback_codebase_gsd_agnostic.md` | New code references "Phase 12" only in PHPDoc rationale prose ("v2.0 multi-user activation") — never `.planning/` paths or D-NN codes. |
| C-10 | **Docs describe current state, never history** | user memory `feedback_docs_describe_current_state.md` | PHPDocs reflect what the code does now (e.g. "Stores per-user OAuth client credentials"), not "previously a JSON file, now a SQLite table". |
| C-11 | **Hippocratic License 3.0** | CLAUDE.md (project posture) + ROADMAP REL-01 | Phase 19 ships the LICENSE file; Phase 12 emits no new licensing surface. |

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Login / signup / logout HTTP routing | Browser → Fortify (Frontend Server) | — | Fortify is a Laravel package; the Volt pages render server-side. |
| Session storage | API / Backend (`database` driver → `sessions` table) | Browser cookie (session ID) | D-26 — sessions live in SQLite; cookies carry only the ID. |
| Username uniqueness + LOWER index | Database / Storage | — | SQLite-level invariant; the only safe place for "case-insensitive unique." |
| Password hashing (bcrypt) | API / Backend (`Hash` contract) | — | Standard Laravel; `password` column already uses the `hashed` cast. |
| Recovery-code generation (random bytes + alphabet filter) | API / Backend | — | Pure PHP; no client involvement. |
| Recovery-code hashing (bcrypt) | API / Backend (`Hash` contract) | — | Same hasher as user passwords (D-07). |
| Recovery-code download `.txt` | API / Backend produces; Browser downloads | — | One-time response; never persisted on disk. |
| OAuth secrets encryption | API / Backend (Laravel `encrypted` cast + APP_KEY) | Database / Storage (ciphertext at rest) | D-17 — AES-256-CBC; key in `.env`, ciphertext in SQLite. |
| `force_password_change_at_next_login` enforcement | API / Backend (global middleware in `web` + `auth` stack) | — | D-31 recommendation: middleware is cleaner than per-route gates. |
| Impersonation session pivot | API / Backend (action sets `auth.impersonating.*` session keys) | Browser cookie (session ID) | D-22. |
| Impersonation banner rendering | Frontend Server (Blade include rendered by middleware) | — | D-23 — middleware reads session, slots a Blade partial. |
| Cross-user data isolation | API / Backend (`BelongsToUser` global scope) + Database (FK + nullable `user_id` already present) | — | Already enforced for reads; Phase 12 ensures writes pre-fill `user_id` via the `creating` event. |

**Why this matters:** Every capability above is server-side. There is **no JavaScript layer**
in Phase 12 — Volt SFCs render server-side, Livewire roundtrips for state changes, Flux
provides components. The planner should NOT introduce client-side state management for any
of these flows.

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/fortify` | ^1.21 (locked in composer.json; latest 1.37.2) | Headless auth actions + Volt-friendly view callbacks | Already in composer.json. **No version bump needed for Phase 12** — 1.21 supports `Fortify::loginView()`, `Fortify::registerView()`, `Fortify::authenticateUsing()`, the `username` config, and `Fortify::authenticateThrough()` (the pipeline customization point). Upgrade to ^1.37 only if Phase 13+ surfaces an incompatibility. [VERIFIED: composer.json] [CITED: laravel.com/docs/13.x/fortify] |
| `laravel/framework` | ^13.0 | Hash contract, Auth contract, Eloquent, Session, route-model binding | Already pinned. The `encrypted` cast in Laravel 13 still uses AES-256-CBC by default. [CITED: laravel.com/docs/13.x/encryption] |
| `livewire/livewire` | ^4.0 | Volt SFC engine + lifecycle hooks supporting DI | Already pinned. `boot()` and `mount()` both support container DI by type-hinting method parameters. [CITED: livewire.laravel.com/docs/lifecycle-hooks] |
| `livewire/flux` | ^2.0 | Headless UI primitives (Banner, Modal, Input) | Already pinned. The impersonation banner reuses Flux's banner component (per UI-SPEC.md). [VERIFIED: composer.json] |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Illuminate\Support\Str` | bundled | `Str::lower()` for canonical username normalization | Use inside `Modules\Auth\Internal\Username\UsernameNormalizer` (DI-friendly helper class). NOT for code generation — `Str::password()` cannot exclude ambiguous chars per D-11. [CITED: securinglaravel.com/security-tip-new-password-generator] |
| `Illuminate\Contracts\Hashing\Hasher` | bundled | Bcrypt hashing for passwords + recovery codes | Inject into `RecoveryCodeAuthenticator`, `SignupAction`, `ResetPasswordAction`, `RegenerateRecoveryCodesAction`. Already used by the existing `FortifyServiceProvider`. |
| `Illuminate\Contracts\Filesystem\Filesystem` | bundled | File operations for the JSON-rename + README write in the OAuth migration | Inject — never use `Storage::` facade or `storage_path()` helper. |
| `Illuminate\Contracts\Routing\UrlGenerator` | bundled | Generate the named-route URL for the `/change-password` redirect | Inject into `ForcePasswordChangeMiddleware`. |
| `Illuminate\Routing\Router` | bundled | Route introspection in the cross-user 404 test | Available via `app('router')` in tests; in Pest, use `$this->app->make(Router::class)`. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `Auth::loginUsingId()` + session pivot (D-22) | `Auth::onceUsingId()` per-request middleware | `onceUsingId` is safer against session bleed but contradicts D-22 + requires `CurrentUserService` to read the impersonation key — which D-22 explicitly forbids (`CurrentUserService` is unchanged). **D-22 wins;** mitigate the session-bleed risk with `$session->regenerate()` after the swap. |
| Custom recovery-code generator | `Str::password()` | `Str::password()` cannot honor D-11's ambiguous-character exclusion list. **Custom generator required.** Trivial — 15 lines of PHP using `random_bytes()` + an alphabet array + `array_search()`. [CITED: securinglaravel.com] |
| `database` session driver (D-26) | `cookie` | Cookies have a 4KB limit and would break under heavy Livewire state. NativePHP bundle (Phase 15) cannot rely on filesystem session drivers. **D-26 stands.** |
| Laravel's built-in `password_reset_tokens` table | `user_recovery_codes` table (D-07) | `password_reset_tokens` is single-use, token-based, and tied to Fortify's email-reset flow. Recovery codes are user-printed, multi-code, multi-purpose (D-08: login OR reset). **Separate table required.** The existing `password_reset_tokens` migration (already in Core) can be left in place OR dropped — see Open Questions. |
| `Fortify::ignoreRoutes()` + custom routes file | Setting individual paths to `null` in `config/fortify.php` and removing features from the array | Easier to disable email-related routes by not adding the feature; cleaner than overriding the entire routes file. **Use the features-array approach for email routes; use `Fortify::ignoreRoutes()` ONLY if a route needs to be wholly custom (e.g. `/signup` returning 404 when `User::count() > 0`).** [CITED: laravel.com/docs/13.x/fortify] |

**Installation:**

No new composer packages required. All needed dependencies already in `composer.json`:

```bash
# No-op — composer.lock is already complete.
# Phase 12 only writes PHP + Blade + migration files + tests.
```

**Version verification (performed 2026-05-19):**

| Package | Pinned (composer.json) | Latest on Packagist | Note |
|---------|------------------------|----------------------|------|
| `laravel/fortify` | `^1.21` | `1.37.2` (2026-05-15) | `^1.21` accepts 1.21..1.x; both `Fortify::authenticateThrough()` and `Fortify::registerView()` exist in 1.21. No upgrade needed. [VERIFIED: composer.json + SUMMARY.md line 24] |
| `laravel/framework` | `^13.0` | Phase-12-incumbent | Locked. |
| `livewire/livewire` | `^4.0` | Phase-12-incumbent | Locked. |
| `livewire/flux` | `^2.0` | Phase-12-incumbent | Locked. |

## Package Legitimacy Audit

> Phase 12 introduces **zero new composer packages**. No legitimacy audit required. The
> only packages used (`laravel/fortify`, `laravel/framework`, `livewire/livewire`,
> `livewire/flux`) are already in `composer.json` and have been used since v1.0.

## Architecture Patterns

### System Architecture Diagram

```
                                                ┌──────────────────────────┐
   Browser                                      │  Fortify Pipeline        │
   ┌────────────────┐         POST /login       │  ┌────────────────────┐  │
   │  Volt: login   │ ───────────────────────►  │  │ CanonicalizeUsername│ │
   │  (Auth)        │ ◄───────────────────────  │  │ AttemptToAuth      │  │
   └────────────────┘    set session cookie     │  │ PrepareAuthSession │  │
           │                                    │  └────────────────────┘  │
           │                                    │            │             │
           │                                    │            ▼             │
           ▼                                    │  ┌────────────────────┐  │
   ┌────────────────┐         POST /signup      │  │ Modules\Auth\      │  │
   │  Volt: signup  │ ───────────────────────►  │  │  Public\Actions\   │  │
   │  (Auth)        │ ◄───────────────────────  │  │  SignupAction      │  │
   │ + recovery     │    set session cookie     │  │   (Fortify::       │  │
   │ + force-pwd    │    + flash codes          │  │   createUsersUsing)│  │
   └────────────────┘                           │  └────────────────────┘  │
                                                └──────────────────────────┘
                                                          │
                                                          ▼
                                                ┌──────────────────────────┐
                                                │  Modules\Core            │
                                                │  ┌────────────────────┐  │
                                                │  │ User model         │  │
                                                │  │  + username        │  │
                                                │  │  + is_developer    │  │
                                                │  │  + force_pwd_      │  │
                                                │  │    change_at_next  │  │
                                                │  └────────────────────┘  │
                                                │            │             │
                                                │  ┌─────────┴──────────┐  │
                                                │  │ CurrentUser service│  │
                                                │  │  (unchanged)       │  │
                                                │  └────────────────────┘  │
                                                └──────────────────────────┘
                                                          │
                                                          ▼
                            ┌─────────────────────────────────────────────┐
                            │  Authenticated request — middleware stack   │
                            │                                             │
                            │  web → auth → ImpersonationBannerMiddleware │
                            │         → ForcePasswordChangeMiddleware     │
                            │         → … (route handlers)                │
                            └─────────────────────────────────────────────┘
                                                          │
                                                          ▼
                            ┌─────────────────────────────────────────────┐
                            │  Domain modules                              │
                            │  All Eloquent reads/writes auto-filter by    │
                            │  BelongsToUser → UserScope → CurrentUser->id │
                            └─────────────────────────────────────────────┘

   Recovery-code flow ─────────────────────────────────────────────────────
   ┌────────────────┐  POST /reset-password  ┌────────────────────────────┐
   │ Volt: reset    │ ────────────────────►  │ RecoveryCodeAuthenticator  │
   │ (Auth)         │                        │  bcrypt-check code         │
   │                │                        │  stamp used_at             │
   │                │                        │  emit system_alerts row    │
   │                │                        │  update User password      │
   └────────────────┘ ◄────────────────────  └────────────────────────────┘

   Impersonation back-end ─────────────────────────────────────────────────
   (UI lands Phase 16 — Phase 12 ships action + middleware + banner partial)
   ┌────────────────┐                        ┌────────────────────────────┐
   │ ImpersonateUser│ ────────────────────►  │ Auth::loginUsingId()       │
   │ Action         │                        │ session->put(              │
   │ (verify own pw)│                        │   'auth.impersonating.…',  │
   │                │                        │   $originalId, …)          │
   │                │                        │ session->regenerate()      │
   └────────────────┘                        └────────────────────────────┘
                                                          │
                                                          ▼
                                              ┌──────────────────────────┐
                                              │ ImpersonationBanner-      │
                                              │ Middleware reads session  │
                                              │ key on every request →   │
                                              │ shares variable to layout │
                                              └──────────────────────────┘
```

### Recommended Project Structure

```
Modules/Auth/
├── module.json                              # priority: 0 (same as Core); see Open Questions
├── Database/
│   └── Migrations/
│       ├── 2026_05_19_000001_drop_email_add_username_to_users_table.php
│       ├── 2026_05_19_000002_add_is_developer_to_users_table.php
│       ├── 2026_05_19_000003_add_force_password_change_to_users_table.php
│       ├── 2026_05_19_000004_create_user_recovery_codes_table.php
│       ├── 2026_05_19_000005_create_oauth_secrets_table.php
│       └── 2026_05_19_000006_delete_legacy_email_oauth_json.php
├── Providers/
│   └── AuthServiceProvider.php              # Top-level module bindings + Livewire registration
├── Internal/
│   ├── Fortify/
│   │   ├── FortifyServiceProvider.php       # MOVED from Modules/Core/Internal/Providers/
│   │   ├── Authenticator.php                # Fortify::authenticateUsing closure target
│   │   └── CreateNewUser.php                # Fortify::createUsersUsing(...) target action class
│   ├── Recovery/
│   │   ├── RecoveryCodeGenerator.php        # Custom alphabet generator (D-11)
│   │   ├── RecoveryCodeAuthenticator.php    # Shared by /login AND /reset-password (D-08)
│   │   └── RecoveryCodeFormatter.php        # Hyphen-grouping + `.txt` formatter
│   ├── Http/
│   │   ├── Livewire/
│   │   │   ├── LoginPage.php
│   │   │   ├── SignupPage.php
│   │   │   ├── RecoveryCodesDisplay.php
│   │   │   ├── ChangePasswordPage.php
│   │   │   ├── ResetPasswordPage.php
│   │   │   ├── ManageUserPage.php
│   │   │   └── AddUserPage.php
│   │   └── Middleware/
│   │       ├── ImpersonationBannerMiddleware.php
│   │       └── ForcePasswordChangeMiddleware.php
│   ├── Console/
│   │   ├── ResetPasswordCommand.php          # diederik:reset-password <username>
│   │   ├── RegenerateRecoveryCodesCommand.php
│   │   └── GrantDeveloperCommand.php         # diederik:grant-dev <username>
│   └── Listeners/
│       └── EmitOAuthReauthRequiredAlert.php  # Fires once-per-user on first authenticated request
├── Public/
│   └── Actions/
│       ├── LoginAction.php
│       ├── SignupAction.php
│       ├── LogoutAction.php
│       ├── ResetPasswordAction.php
│       ├── RegenerateRecoveryCodesAction.php
│       ├── ImpersonateUserAction.php
│       ├── EndImpersonationAction.php
│       └── AddUserAction.php                 # Owner-creates-partner; called from AddUserPage
├── Models/
│   └── UserRecoveryCode.php                  # bcrypt-hashed codes, used_at audit
├── Resources/
│   └── views/
│       ├── livewire/
│       │   ├── login-page.blade.php
│       │   ├── signup-page.blade.php
│       │   ├── recovery-codes-display.blade.php
│       │   ├── change-password-page.blade.php
│       │   ├── reset-password-page.blade.php
│       │   ├── manage-user-page.blade.php
│       │   └── add-user-page.blade.php
│       └── partials/
│           └── impersonation-banner.blade.php   # Slotted into resources/views/layouts/app.blade.php
├── Routes/
│   └── web.php                               # /login, /logout, /signup (guarded by User::count===0), /reset-password, /change-password, /settings/users/*, /settings/recovery-codes
└── tests/
    ├── Feature/
    │   ├── LoginPageTest.php
    │   ├── SignupPageTest.php
    │   ├── ResetPasswordTest.php
    │   ├── ChangePasswordTest.php
    │   ├── RecoveryCodesTest.php
    │   ├── ImpersonationActionTest.php
    │   ├── CrossUserIsolationTest.php
    │   └── ConsoleCommandsTest.php
    └── Unit/
        ├── RecoveryCodeGeneratorTest.php
        ├── RecoveryCodeAuthenticatorTest.php
        ├── UsernameNormalizerTest.php
        └── ForcePasswordChangeMiddlewareTest.php

Modules/EmailScan/Public/Services/
└── OAuthSecretsRepository.php                # REWRITTEN — same public surface, SQLite backing, CurrentUser DI

Modules/EmailScan/Models/                     # NEW
└── OAuthSecret.php                           # Eloquent model + encrypted casts

tests/Contracts/
└── BoundaryArchTest.php                      # EXTENDED with noAuthFacadeOrHelper invariant
```

### Pattern 1: Volt SFC for the Login Page (D-02)

**What:** Single-file `.blade.php` containing both the PHP class (`new class extends Component`)
and the Blade template. Lives under `Modules/Auth/Resources/views/livewire/login-page.blade.php`
**or** as a paired class + view (`Modules/Auth/Internal/Http/Livewire/LoginPage.php` +
`login-page.blade.php`). Both shapes are supported in Livewire 4.

**Recommendation:** Use the **paired class + view shape**, not pure Volt functional. Reasoning:
- Constructor DI (which the project enforces project-wide) is cleaner on a class than via
  `mount()`-only injection in functional Volt.
- The DI-only rule + Larastan L10 strict + PHPDoc requirements all favor an explicit
  named class — anonymous classes are harder to PHPStan-annotate.
- The existing v1.0 Livewire components (`Dashboard.php`, `SettingsPage.php`,
  `InboxesPage.php`, `TopNav.php`) all use the paired shape — Phase 12 should match.

**When to use:** Every Volt page in Phase 12.

**Example:**

```php
<?php
// Modules/Auth/Internal/Http/Livewire/LoginPage.php
// Source: livewire.laravel.com/docs/lifecycle-hooks + existing Modules/Core/Internal/Http/Livewire/SettingsPage.php pattern

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Dto\LoginAttempt;

#[Layout('layouts.app')]
#[Title('Sign in · diederik')]
final class LoginPage extends Component
{
    public string $username = '';
    public string $password = '';
    public bool $remember = true; // D-27 — checked by default; UI-SPEC §"Sign-in flow"
    public ?string $errorMessage = null;

    public function submit(LoginAction $action, UrlGenerator $urls): mixed
    {
        $result = $action(new LoginAttempt(
            usernameInput: $this->username,
            password: $this->password,
            remember: $this->remember,
        ));

        if ($result->failed) {
            // UI-SPEC: never differentiate "user does not exist" vs "wrong password"
            $this->errorMessage = 'Username or password is incorrect.';
            $this->password = '';
            return null;
        }

        return $this->redirect($urls->route('dashboard'), navigate: false);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('auth::livewire.login-page');
    }
}
```

**Key points:**
- `LoginAction` is **injected into the action method**, not the constructor — Livewire 4
  serializes the component between requests and the action only needs to be present at
  click-time. This is the canonical Livewire DI pattern. [CITED: livewire.laravel.com/docs/lifecycle-hooks]
- `UrlGenerator` is method-injected the same way.
- `#[Layout]` and `#[Title]` attributes are the Livewire 4 way to set layout + page title
  (replaces the old `extends('layouts.app', ['title' => ...])` approach in v3).
- The component is `final` and types every field — Larastan L10 strict mode requires it.

### Pattern 2: Fortify Provider with `username` + Volt views + `createUsersUsing` (D-02)

**What:** A rewritten `FortifyServiceProvider` that:
1. Lives at `Modules/Auth/Internal/Fortify/FortifyServiceProvider.php` (MOVED from Core).
2. Configures `username` as the login field via `config/fortify.php` change.
3. Points `Fortify::loginView()` → the Volt page registered under `auth::livewire.login-page`.
4. Wires `Fortify::createUsersUsing(SignupAction::class)` (the action's `__invoke` is what
   Fortify calls).
5. Customizes `Fortify::authenticateThrough()` to **drop `EnsureLoginIsNotThrottled`** per D-12.
6. Disables email-related features by NOT adding them to `config/fortify.php` `features` array.

**When to use:** Once, in `Modules/Auth/`'s service provider boot.

**Example:**

```php
<?php
// Modules/Auth/Internal/Fortify/FortifyServiceProvider.php
// Source: laravel.com/docs/13.x/fortify + project's existing FortifyServiceProvider

declare(strict_types=1);

namespace Modules\Auth\Internal\Fortify;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;
use Modules\Auth\Public\Actions\SignupAction;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(ViewFactory $views): void
    {
        // D-02: `username` is set in config/fortify.php — no code change needed here.

        Fortify::loginView(static fn () => $views->make('auth::livewire.login-page'));
        Fortify::registerView(static fn () => $views->make('auth::livewire.signup-page'));

        // SignupAction handles BOTH the validation AND the User row creation
        // (replaces Fortify's default CreateNewUser action). It runs inside a DB
        // transaction so the User::count() === 0 → is_developer = true assignment
        // (D-04) is race-free.
        Fortify::createUsersUsing(SignupAction::class);

        // D-12: drop the throttle middleware entirely. The full pipeline becomes:
        //   1. CanonicalizeUsername — lowercases per D-02
        //   2. AttemptToAuthenticate — the actual login
        //   3. PrepareAuthenticatedSession — session regenerate + cookies
        Fortify::authenticateThrough(static fn (Request $request) => [
            CanonicalizeUsername::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);
    }
}
```

**Config change in `config/fortify.php`:**

```php
'username' => 'username',  // was 'email'
'email'    => 'username',  // was 'email' — Fortify uses this for password-reset token lookups (none, here, but keeps the schema consistent)
'features' => [],          // EMPTY — no Features::registration(), no Features::resetPasswords(), no Features::emailVerification(). Custom routes handle everything per D-03.
'limiters' => ['login' => null, 'passkeys' => null],  // D-12: explicitly no rate limit.
```

### Pattern 3: Custom Recovery-Code Generator (D-11)

**What:** A pure PHP generator using `random_bytes()` + a fixed alphabet (D-11 excludes
`O`, `0`, `I`, `1`, `L`). `Str::password()` is unsuitable per web research.

**When to use:** Inside `Modules\Auth\Internal\Recovery\RecoveryCodeGenerator`.

**Example:**

```php
<?php
// Modules/Auth/Internal/Recovery/RecoveryCodeGenerator.php
// Source: web research — Laravel Str::password cannot exclude ambiguous chars
// [CITED: securinglaravel.com/security-tip-new-password-generator]

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

final class RecoveryCodeGenerator
{
    // D-11: uppercase A-Z minus {O, I, L} + digits 2-9 minus {0, 1}.
    //   A..N P..K (skip O) M..H (skip I) L is also out.
    //   23456789 (skip 0 and 1).
    // Result: 23 letters + 8 digits = 31 symbols. Each 4-char group = 31^4 ≈ 19 bits.
    // 5 groups × 4 chars × 19/4 bits ≈ ~95 bits actual entropy (D-11 quotes ~104; close).
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * @return string Format: `XXXX-XXXX-XXXX-XXXX-XXXX` (5×4).
     */
    public function generate(): string
    {
        $groups = [];
        for ($g = 0; $g < 5; $g++) {
            $chars = '';
            for ($c = 0; $c < 4; $c++) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $chars;
        }

        return implode('-', $groups);
    }
}
```

**Key points:**
- `random_int()` (not `mt_rand()`) — `random_int` uses the OS CSPRNG.
- No facade, no helper. Pure stdlib.
- Test: assert 1000 generated codes all match `/^[A-NP-Z2-9]{4}(-[A-NP-Z2-9]{4}){4}$/`.

### Pattern 4: Recovery-Code Authenticator (D-08)

**What:** Shared by `/login` (one-time login) and `/reset-password` (typing username + code +
new password). Reads `username` + raw code, normalizes (uppercase, strip dashes), looks up
all unused codes for the user, bcrypt-checks each, stamps `used_at` on match, emits
`system_alerts` row.

**Why one class:** Both flows do exactly the same thing — find a matching unused code, mark
it used, return success/failure. The only difference is what the caller does on success
(set password vs activate session + force-pwd-change).

**Example:**

```php
<?php
// Modules/Auth/Internal/Recovery/RecoveryCodeAuthenticator.php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Recovery\NormalizedRecoveryCode;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertQuery; // OR a dedicated emit helper

final readonly class RecoveryCodeAuthenticator
{
    public function __construct(
        private Hasher $hasher,
        private DatabaseManager $db,
        private Clock $clock,
        // SystemAlertEmitter is a thin wrapper around the inserts so we don't
        // reach into another module's Internal layer — see Open Questions.
    ) {}

    /**
     * Returns the User on success and stamps used_at on the matching code; or null on failure.
     * Emits the matching system_alerts row in both cases.
     */
    public function verify(string $usernameInput, string $codeInput): ?User
    {
        $normalized = NormalizedRecoveryCode::fromInput($codeInput); // uppercase + strip dashes + re-group
        $username = strtolower(trim($usernameInput));

        return $this->db->transaction(function () use ($username, $normalized): ?User {
            $user = User::query()->where('username', $username)->first();
            if (! $user instanceof User) {
                $this->emit('auth.recovery_code_failed', 'error', $usernameInput, null);
                return null;
            }

            $unusedCodes = UserRecoveryCode::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->get();

            foreach ($unusedCodes as $code) {
                if ($this->hasher->check($normalized->plaintext(), $code->code_hash)) {
                    $code->forceFill(['used_at' => $this->clock->now()])->save();
                    $this->emit('auth.recovery_code_consumed', 'warning', $username, $user->id);
                    return $user;
                }
            }

            $this->emit('auth.recovery_code_failed', 'error', $username, $user->id);
            return null;
        });
    }

    private function emit(string $kind, string $severity, string $username, ?int $userId): void
    {
        // ... insert into system_alerts via DatabaseManager directly OR via an injected SystemAlertEmitter.
    }
}
```

**Key points:**
- Wrapped in a `transaction()` + `lockForUpdate()` so two simultaneous code-entry attempts
  cannot both succeed on the same code.
- Uses `Hasher::check()` (DI) — never `Hash::check()` (facade).
- Reads `Clock` (DI) — never `now()` helper.
- Username comparison is `strtolower(trim($usernameInput))` so case + leading-whitespace
  variations match per D-02.
- The `emit()` helper writes to `system_alerts` per D-13. **See Open Questions** about
  whether to create a new `SystemAlertEmitter` Public service in Core or call the existing
  table directly via `DatabaseManager`.

### Pattern 5: Force-Password-Change Middleware (D-31 — Claude's discretion → global middleware)

**What:** A middleware that redirects every authenticated request (except `/change-password`
and `/logout`) to `/change-password` when the current user's
`force_password_change_at_next_login` is `true`.

**When to use:** Registered globally in the `web` + `auth` middleware stack, after `auth`.

**Example:**

```php
<?php
// Modules/Auth/Internal/Http/Middleware/ForcePasswordChangeMiddleware.php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final readonly class ForcePasswordChangeMiddleware
{
    /** @var array<int, string> */
    private const ALLOWED_ROUTE_NAMES = ['auth.change-password', 'auth.change-password.submit', 'auth.logout'];

    public function __construct(
        private CurrentUser $currentUser,
        private UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! $this->currentUser->isAuthenticated()) {
            return $next($request); // No auth → no force-change to worry about; auth middleware itself will catch unprotected access.
        }

        if (! $this->currentUser->user()->force_password_change_at_next_login) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if (in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        return new \Illuminate\Http\RedirectResponse($this->urls->route('auth.change-password'));
    }
}
```

**Key points:**
- Whitelist is route-name based, not path-based — robust to route prefix changes.
- `CurrentUser` reads from the active guard — works correctly even mid-impersonation
  (the impersonated user's flag is what's checked, which is what D-22 implies).
- Clears the flag inside the action that handles the new password submission (NOT inside the
  middleware itself).

### Pattern 6: Impersonation Action (D-21 + D-22)

**What:** Single action class. Verifies the developer's own password, then calls
`Auth::loginUsingId($partnerId)`, sets the session keys, regenerates the session ID.

**When to use:** Called from the Phase 16 modal AND directly from Pest tests in Phase 12.

**Example:**

```php
<?php
// Modules/Auth/Public/Actions/ImpersonateUserAction.php
// THIS FILE IS ON THE noAuthFacadeOrHelper ALLOW-LIST (D-24).

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session as SessionContract;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;

final readonly class ImpersonateUserAction
{
    public function __construct(
        private AuthManager $auth,
        private Hasher $hasher,
        private SessionContract $session,
        private CurrentUser $currentUser,
    ) {}

    public function __invoke(int $targetUserId, string $confirmPassword): ImpersonationResult
    {
        $original = $this->currentUser->user();

        if (! $this->hasher->check($confirmPassword, $original->password)) {
            return ImpersonationResult::wrongPassword();
        }

        if (! $original->is_developer) {
            return ImpersonationResult::notAllowed();
        }

        $target = User::query()->find($targetUserId);
        if (! $target instanceof User || $target->id === $original->id) {
            return ImpersonationResult::invalidTarget();
        }

        // D-22: loginUsingId — accepted risk per CONTEXT, mitigated by session regeneration.
        // Note: This is the ONLY place in the codebase where Auth::loginUsingId
        // appears, so the arch test allow-list is single-file precise.
        $this->auth->guard()->loginUsingId($targetUserId);

        $this->session->put('auth.impersonating.original_user_id', $original->id);
        $this->session->put('auth.impersonating.original_username', $original->username);

        // Prevent session-fixation by regenerating the session ID AFTER the swap.
        $this->session->regenerate();

        // ... emit system_alerts row 'auth.impersonation_started' (warning)
        return ImpersonationResult::success();
    }
}
```

**Note on Session contract:** `Illuminate\Contracts\Session\Session` is **a contract**, not
a facade. It is the legitimate DI surface for session reads/writes. The arch test must
distinguish between `Session::` (facade — forbidden) and `Illuminate\Contracts\Session\Session`
(contract — allowed). The grep should target `Illuminate\Support\Facades\Session::` or the
string `session(` (helper), not the contract namespace.

### Pattern 7: `noAuthFacadeOrHelper` Arch Test (D-24)

**What:** A custom Pest `it()` test that walks every `.php` file under `Modules/`, strips
comments, greps for the seven forbidden symbols, and asserts the hit-list equals the
six-file allow-list (per D-24).

**When to use:** Once, appended to `tests/Contracts/BoundaryArchTest.php`.

**Example:**

```php
<?php
// tests/Contracts/BoundaryArchTest.php — APPENDED block

it('does not allow Auth facade / auth helper / request->session usage outside the Modules\\Auth allow-list (noAuthFacadeOrHelper)', function (): void {
    // D-24: forbidden symbols across every module file EXCEPT the explicit allow-list.
    // The allow-list is six files inside Modules/Auth/. Future additions must justify by name.
    $forbiddenPatterns = [
        '/Illuminate\\\\Support\\\\Facades\\\\Auth/',           // import of Auth facade
        '/\\bAuth::user\\s*\\(/',                                  // Auth::user( call
        '/\\bAuth::id\\s*\\(/',                                    // Auth::id( call
        '/\\bAuth::loginUsingId\\s*\\(/',                          // Auth::loginUsingId( call
        '/(?<![>:])\\bauth\\s*\\(/',                             // `auth()` helper (not $obj->auth or Class::auth)
        '/\\brequest\\(\\)->user\\s*\\(/',                       // request()->user(
        '/\\brequest\\(\\)->session\\s*\\(/',                    // request()->session(
        '/(?<![>:])\\bsession\\s*\\(/',                          // `session(` helper (not $obj->session or Class::session)
    ];

    $allowList = [
        // D-24 allow-list — explicit, single-file precise.
        'Modules/Auth/Public/Actions/LoginAction.php',
        'Modules/Auth/Public/Actions/SignupAction.php',
        'Modules/Auth/Public/Actions/LogoutAction.php',
        'Modules/Auth/Public/Actions/ResetPasswordAction.php',
        'Modules/Auth/Public/Actions/ImpersonateUserAction.php',
        'Modules/Auth/Public/Actions/EndImpersonationAction.php',
        'Modules/Auth/Public/Actions/RegenerateRecoveryCodesAction.php',
        'Modules/Auth/Public/Actions/AddUserAction.php',
        'Modules/Auth/Internal/Fortify/FortifyServiceProvider.php',
        'Modules/Auth/Internal/Fortify/Authenticator.php',
        'Modules/Auth/Internal/Fortify/CreateNewUser.php', // if it exists; SignupAction replaces it via createUsersUsing
        'Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php',
        // Tests/ is also excluded by directory check below.
    ];

    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), RecursiveDirectoryIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $absolute = $file->getPathname();
        if (str_contains($absolute, '/tests/')) {
            continue;
        }
        $relative = str_replace(base_path() . '/', '', $absolute);
        if (in_array($relative, $allowList, true)) {
            continue;
        }
        $contents = (string) file_get_contents($absolute);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        foreach ($forbiddenPatterns as $pat) {
            if (preg_match($pat, $stripped) === 1) {
                $hits[] = $relative;
                break;
            }
        }
    }
    expect($hits)->toBe(
        [],
        "Auth facade / helper / session helper not allowed outside Modules/Auth allow-list. Offenders:\n  " . implode("\n  ", $hits)
    );
});
```

**Key points:**
- Mirrors the shape of the existing `noFacadeCallsFromCoreConsoleCommands` + `noLaravelGlobalHelpersInCoreConsoleCommands`
  rules already in `BoundaryArchTest.php` — Phase 12 contributors will recognize the
  pattern instantly.
- Comment-stripping (`preg_replace('#/\*.*?\*/|//[^\n]*#s', ...)`) lets legitimate PHPDoc
  references like `"@see Auth::user()"` stay legal.
- Lookbehind `(?<![>:])` distinguishes `auth()` (helper) from `$container->auth(` or
  `Provider::auth(` (method calls — legal).
- The allow-list is **explicit per-file** (D-24), not a glob.

### Pattern 8: Cross-User 404-Not-403 Test Set (D-25)

**What:** A Pest test that auto-generates the matrix from `Route::getRoutes()`. For every
route in the `auth` middleware group with a model-scoped parameter (e.g.
`{transaction}`, `{chain}`, `{recurring}`), it creates two users, has user A own a record,
logs in as user B, requests A's URL, asserts 404 (NOT 403 — never leak existence).

**When to use:** Once, in `tests/Feature/CrossUserIsolationTest.php` OR in
`Modules/Auth/tests/Feature/CrossUserIsolationTest.php`.

**Example skeleton:**

```php
<?php
// Modules/Auth/tests/Feature/CrossUserIsolationTest.php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\User;

it('returns 404 (not 403) when user B requests user A\'s model-scoped route', function (string $routeName, string $modelClass): void {
    $userA = User::factory()->create(['username' => 'alice']);
    $userB = User::factory()->create(['username' => 'bob']);

    // Use the factory for whatever model the route binds. The factory must scope to user A.
    /** @var Model $record */
    $record = $modelClass::factory()->create(['user_id' => $userA->id]);

    $response = $this->actingAs($userB)->get(route($routeName, [strtolower(class_basename($modelClass)) => $record->getKey()]));

    expect($response->status())->toBe(
        404,
        sprintf(
            "Route %s should return 404 for cross-user access, got %d. Routes leaking existence with 403 are a multi-tenant data leak.",
            $routeName,
            $response->status(),
        ),
    );
})->with(function (): iterable {
    // Auto-generate the dataset at test-boot time by introspecting the route table.
    $router = app(Router::class);
    foreach ($router->getRoutes() as $route) {
        if (! in_array('auth', $route->middleware(), true)) {
            continue;
        }
        // Detect routes with model-scoped parameters via the route's signature binding.
        $parameters = $route->signatureParameters();
        foreach ($parameters as $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType) continue;
            $class = $type->getName();
            if (is_subclass_of($class, Model::class)) {
                yield $route->getName() => [$route->getName(), $class];
                break;
            }
        }
    }
});
```

**Key points:**
- Generates the dataset from `Router::getRoutes()` at test-boot time — no manual list.
- The test fails loudly if any in-scope route returns anything other than 404.
- An explicit allow-list (sibling array passed to a `->skip()` chain) handles the
  "this route legitimately returns 200/302" cases — none expected today.
- `class_basename($modelClass)` is a Laravel global helper — **forbidden** per the
  DI rule. Replace with `(new \ReflectionClass($modelClass))->getShortName()` inside the
  test. (Tests are not under the `noAuthFacadeOrHelper` rule's scope — but the project's
  general DI ethos suggests using the cleaner approach here too.)

### Pattern 9: OAuth Secrets Repository Rewire (D-15, D-16, D-17)

**What:** Replace the current JSON-file implementation with a SQLite-backed Eloquent model
+ encrypted casts, scoped to the current user.

**Schema:**

```php
// Modules/Auth/Database/Migrations/2026_05_19_000005_create_oauth_secrets_table.php

Schema::create('oauth_secrets', static function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('provider', 32); // 'gmail' | 'microsoft'
    $table->string('client_id')->nullable();
    $table->text('client_secret')->nullable();   // encrypted via cast
    $table->string('redirect_uri')->nullable();
    $table->text('tokens_blob')->nullable();      // encrypted JSON: access_token, refresh_token, expires_at, scopes
    $table->timestamps();

    $table->unique(['user_id', 'provider']);
});
```

**Model:**

```php
// Modules/EmailScan/Models/OAuthSecret.php

namespace Modules\EmailScan\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

final class OAuthSecret extends Model
{
    use BelongsToUser; // auto-scopes by user_id via UserScope

    protected $fillable = ['provider', 'client_id', 'client_secret', 'redirect_uri', 'tokens_blob'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'tokens_blob'   => 'encrypted',
        ];
    }
}
```

**Repository constructor change (preserves public surface):**

```php
final class OAuthSecretsRepository
{
    public function __construct(
        private readonly CurrentUser $currentUser,
    ) {}

    public function hasProviderClient(string $provider): bool
    {
        return OAuthSecret::query() // global scope auto-filters by user_id
            ->where('provider', $provider)
            ->whereNotNull('client_id')
            ->exists();
    }

    public function saveProviderClient(string $provider, string $clientId, string $clientSecret, string $redirectUri): void
    {
        OAuthSecret::query()->updateOrCreate(
            ['provider' => $provider, 'user_id' => $this->currentUser->id()],
            ['client_id' => $clientId, 'client_secret' => $clientSecret, 'redirect_uri' => $redirectUri],
        );
    }

    // ... loadProviderClient(), loadInbox(), saveInboxRefreshToken(), rotateRefreshToken(), removeInbox() all rewritten the same way.
}
```

**Key points:**
- `BelongsToUser` trait + `UserScope` make per-user filtering automatic on **reads** —
  but `updateOrCreate` writes need an explicit `user_id` in the lookup keys.
- The cast `'encrypted'` uses `APP_KEY` — AES-256-CBC by default in Laravel 13.
  [CITED: laravel.com/docs/13.x/encryption]
- The existing `OAuthSecretsRepository` public surface (`hasProviderClient`,
  `loadProviderClient`, `saveProviderClient`, `loadInbox`, `saveInboxRefreshToken`,
  `rotateRefreshToken`, `removeInbox`) stays **byte-for-byte the same** — no consumer
  changes outside the repo (D-16).
- The `EmailScanServiceProvider` singleton binding stays — it just resolves the new
  implementation. The constructor signature change (`Filesystem` → `CurrentUser`) is
  Laravel's container's job to wire.

**Migration safety pattern (D-19):**

```php
// Modules/Auth/Database/Migrations/2026_05_19_000006_delete_legacy_email_oauth_json.php

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        /** @var \Illuminate\Filesystem\Filesystem $fs */
        $fs = Container::getInstance()->make(\Illuminate\Filesystem\Filesystem::class);

        // base_path() is forbidden globally except inside an allow-list.
        // Migration files (this one) are part of the allow-list per the
        // existing v1.0 carve-out for migrations. If the planner decides
        // to tighten further, inject Application::storagePath() here.
        $secretsDir = base_path('storage/app/secrets');
        $jsonPath = $secretsDir . '/email-oauth.json';
        $bakPath = $secretsDir . '/email-oauth.json.pre-phase-12.bak';
        $readmePath = $secretsDir . '/README.md';

        if ($fs->exists($jsonPath)) {
            $fs->move($jsonPath, $bakPath);
            chmod($bakPath, 0o600);
        }

        if (! $fs->exists($readmePath)) {
            $fs->put($readmePath, /* the explanation text */ '');
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. Rollback to JSON storage is not supported;
        // the rename-to-.bak preserves the operator's ability to restore manually.
    }
};
```

### Anti-Patterns to Avoid

- **Don't use `Str::password()` for recovery codes.** It cannot exclude D-11's ambiguous
  characters; a slipping `0` vs `O` in a phone-read code is exactly the failure mode
  D-11 is designed to prevent. Use the custom generator in Pattern 3.
- **Don't put the Fortify provider in `App\Providers\`.** The existing one is already at
  `Modules/Core/Internal/Providers/FortifyServiceProvider.php`. Phase 12 MOVES it to
  `Modules/Auth/Internal/Fortify/FortifyServiceProvider.php` to keep auth concerns
  inside the Auth module (D-01).
- **Don't reach for `Storage::disk('local')->put(...)`** in the migration. Inject the
  `Filesystem` contract (or call `Container::getInstance()->make(Filesystem::class)`
  if inside a migration where DI is awkward — the existing v1.0 migrations use this
  pattern).
- **Don't add `Auth::loginUsingId(` outside `ImpersonateUserAction` and `EndImpersonationAction`.**
  The arch test catches it.
- **Don't use placeholder-only labels** in Volt views (UI-SPEC.md "Accessibility").
- **Don't add `Features::resetPasswords()` to the Fortify features array.** D-02 explicitly
  forbids email-based reset. The reset flow goes through the new `/reset-password` Volt
  page wired to `RecoveryCodeAuthenticator`, not through Fortify's pipeline.
- **Don't drop the existing `password_reset_tokens` table.** Even though we don't use it,
  removing it would require a destructive migration on existing dev databases and provides
  no benefit. Leave it in place; the table is unused but harmless.
  *(See Open Questions for reconsideration.)*
- **Don't render the impersonation banner via a Livewire component.** It's painted by a
  Blade partial slot driven by middleware — no Livewire round-trip needed (and using one
  would force the banner state to live in component state instead of session, defeating
  D-22's session-pivot architecture).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Authentication pipeline | Custom session + cookie + remember-me logic | Fortify `authenticateThrough()` | Fortify already handles session regeneration, remember-token rotation, cookie security flags. Rebuilding this is the canonical Laravel anti-pattern. |
| Username canonicalization | Custom lowercase + trim logic everywhere | Fortify's `CanonicalizeUsername` pipeline step + `'lowercase_usernames' => true` in config | One source of truth; lower-cases the username before the AttemptToAuthenticate step. |
| Password hashing | Bcrypt yourself | `Illuminate\Contracts\Hashing\Hasher` | Already used by v1.0; `'password' => 'hashed'` cast on User. |
| Encryption | DIY AES wrapper | Laravel `encrypted` Eloquent cast | Uses `APP_KEY`, handles IV generation + serialization. AES-256-CBC default. |
| Cross-tenant data isolation | Per-controller `where('user_id', $auth->id())` | `BelongsToUser` global scope (already in place) | The scope auto-filters reads; per-query forgetfulness is exactly Pitfall 5. |
| Session storage | Filesystem session driver | `database` driver (already configured) | Works in NativePHP bundle (Phase 15); no filesystem permission surprises. |
| Rate limiting (D-12 says NONE for login) | Skip — local-only deployment | — | D-12: bcrypt cost is the defense. |
| Route introspection in tests | Hand-list every route per `where(...)` | `Route::getRoutes()` + ReflectionParameter on signatureParameters | Auto-generates the matrix; impossible to forget a route. |
| The Fortify password-reset email | `Mail::send()` + a token table | — | D-02 + Pitfall 7: NO SMTP. Recovery codes replace email reset entirely. |
| Per-device session list | Custom `sessions` query UI | — | Deferred by CONTEXT.md (out of scope). |

**Key insight:** Almost every multi-user-auth feature has a Laravel-blessed answer that
already ships with Fortify or the framework core. Phase 12's job is **integration, not
invention**. The only genuinely new domain logic is (a) the recovery-code generator, (b)
`RecoveryCodeAuthenticator`, (c) `ImpersonateUserAction`'s password-verify-then-loginUsingId
sequence, and (d) the OAuth-secrets table.

## Runtime State Inventory

> Phase 12 is a schema + code activation phase, not a greenfield phase. Runtime state
> survives the schema reshape and the OAuth rewire. Every category answered explicitly.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| **Stored data** | (1) The existing `users` table has whatever the developer seeded — typically 1 row with `email = 'developer@diederik.test'` or similar. Phase 12 DROPS the email column. (2) `storage/app/secrets/email-oauth.json` may contain OAuth client + refresh tokens. (3) `sessions` table has any active session cookies. | (1) The schema migration drops `email` and adds `username` — **on the existing single user, run a data migration step that populates `username` from `email`** (e.g. local-part before `@`) so the dev's own login still works. Or document that the dev must run `diederik:reset-password` on the single user before logging in for the first time after the migration. (2) Rename JSON → `.bak` per D-19. (3) `sessions` table rows become invalid the instant `APP_KEY` rotates — for v1→v2 dev migration, all sessions cleared. |
| **Live service config** | None. Diederik is local-only; no Datadog / Cloudflare / Tailscale / external service holds string references. | None — verified by repo grep (no `datadog`, `cloudflare`, `tailscale` references in source). |
| **OS-registered state** | The macOS launchd plists at `~/Library/LaunchAgents/com.diederik.*.plist` (per v1.0) reference no v1→v2 identifiers; they call `php artisan schedule:work` etc. | None. The launchd plists do not embed schema-level identifiers. |
| **Secrets/env vars** | `.env` contains `APP_KEY`, optional `OAUTH_LOOPBACK_PORT`, etc. None of the env var names change in Phase 12. | None — Phase 12 only changes what `client_secret` / `tokens_blob` _decrypt to_; the `APP_KEY` itself is unchanged. |
| **Build artifacts/installed packages** | None — Phase 12 changes no composer or PHP package versions. `composer.lock` is unaffected. | None. |

**The canonical question:** *After every file in the repo is updated, what runtime systems
still have the old string cached, stored, or registered?*

**Answer for Phase 12:**
1. The `users` row(s) — needs a data step (populate `username` from `email`-local-part).
2. The `email-oauth.json` file — D-19 covers it (rename to `.bak`).
3. Any active session cookie — will be invalidated by the session-encryption-key change
   that Phase 17 ships per-install. For Phase 12, sessions persist if `APP_KEY` is
   unchanged — but the user model schema change (`email` → `username`) will cause
   `Auth::user()` (inside Fortify's pipeline) to fail-soft once because the User model no
   longer has the `email` property. **Action:** the migration runs only once per install;
   the dev re-logs-in after migration.

## Common Pitfalls

### Pitfall 1: Race condition in "first user becomes developer" (D-04)

**What goes wrong:** Two concurrent signup requests arrive, both check `User::count() === 0`,
both insert with `is_developer = true`. Now the system has two owners.

**Why it happens:** No DB-level constraint on "exactly one developer." The check + insert
must be atomic.

**How to avoid:** Wrap the SignupAction in `DB::transaction()` (use injected
`DatabaseManager`) AND use a `lockForUpdate()` on a probe query, or — simpler — rely on
the **unique index on `username`** to fail the second request. Because `/signup` returns
404 once any user exists, the race window is microseconds.

**Warning signs:** A test that creates two users in a tight loop and inspects
`is_developer` on both.

### Pitfall 2: SQLite case-insensitive uniqueness on `username` (D-02)

**What goes wrong:** SQLite, unlike Postgres, treats `'alice'` and `'Alice'` as different
strings under a default unique constraint. The user signs up as `Alice`; partner later
tries `alice` and succeeds — two users with effectively the same name.

**Why it happens:** SQLite supports `COLLATE NOCASE` but Laravel's schema builder doesn't
expose it cleanly. [CITED: web search — laracasts.com/discuss/channels/eloquent/sqlite-problem-case-sensitive]

**How to avoid:** Two complementary defenses:
1. **Normalize input at every write site** — `strtolower(trim($input))` in `SignupAction`,
   `AddUserAction`, and Fortify's `CanonicalizeUsername` pipeline step (already configured
   when `lowercase_usernames` is `true`).
2. **Store the column as lowercase** via a setter mutator on the User model AND a
   `LOWER(username)` unique index applied via raw SQL in the migration:
   ```sql
   CREATE UNIQUE INDEX users_username_lower_unique ON users (LOWER(username));
   ```
   Laravel migrations support `$connection->statement('CREATE UNIQUE INDEX ...')` (already
   used in v1.0 for the system_alerts triggers — same pattern).

**Warning signs:** Two User rows with `username = 'alice'` and `username = 'Alice'`. Test
this in `Modules/Auth/tests/Feature/SignupPageTest.php` with `expectException(...)`.

### Pitfall 3: Impersonation session bleed (D-22)

**What goes wrong:** `Auth::loginUsingId($partnerId)` swaps the auth state but reuses the
existing session ID. Old session state (CSRF tokens, validation errors, flash data) may
leak between the developer's session and the impersonated session — and vice versa when
returning. [CITED: notesonlaravel.com/laravel-impersonate-users + amitmerchant.com]

**Why it happens:** Laravel's session payload is a single blob keyed by session ID. The
auth swap doesn't reset the payload.

**How to avoid:**
1. **`$session->regenerate()` after the swap** in `ImpersonateUserAction`. This generates
   a new session ID + cookie while keeping the payload's needed keys
   (`auth.impersonating.*`). Laravel's `Session::regenerate(true)` (with `$destroy = true`)
   ALSO clears flash data — use it.
2. **Same on return:** `EndImpersonationAction` calls `Auth::loginUsingId($originalId)`
   then `$session->regenerate(true)` again.
3. **Test:** Pest feature test that asserts `csrf_token` after the swap is different from
   before, and that flash data set as the developer is NOT visible to the impersonated
   role.

**Warning signs:** A test that flashes a value as user A, impersonates B, and finds the
flash data visible to B.

### Pitfall 4: `force_password_change_at_next_login` redirect loop

**What goes wrong:** Middleware redirects every authenticated request to
`/change-password`. If `/change-password` itself goes through the middleware (which it does
— it's behind `auth`), the user lands on `/change-password` → middleware redirects →
`/change-password` → infinite loop. Browser shows "ERR_TOO_MANY_REDIRECTS".

**Why it happens:** The middleware's bypass logic must be route-name-aware. Per Pattern 5
above, the whitelist is `['auth.change-password', 'auth.change-password.submit', 'auth.logout']`.

**How to avoid:** Implemented in Pattern 5. Test: a Pest test that sets
`force_password_change_at_next_login = true`, then GETs `/change-password` and asserts a
200 OK (NOT a redirect).

**Warning signs:** `ERR_TOO_MANY_REDIRECTS` in browser; an integration test that times out.

### Pitfall 5: OAuth secrets `.json.bak` left world-readable

**What goes wrong:** The migration renames `email-oauth.json` → `email-oauth.json.pre-phase-12.bak`
but the new file inherits the umask, which on macOS Herd is typically 022 → file is mode
0644 → world-readable. The dev's IMAP refresh tokens are leaked to other macOS users on the
shared workstation.

**Why it happens:** `Filesystem::move()` (and the underlying `rename()`) preserves the
source file's permissions if both paths live on the same filesystem, but does **not** apply
chmod. Always explicitly chmod the destination after the move.

**How to avoid:** The migration code in Pattern 9 (D-19) calls `chmod($bakPath, 0o600)`
explicitly after the move. Test in `Modules/Auth/tests/Feature/LegacyJsonRenameTest.php`:
create a fake `email-oauth.json` at mode 0644, run the migration, assert the `.bak` is
mode 0600.

**Warning signs:** `ls -la storage/app/secrets/` shows `.bak` with anything other than
`-rw-------`.

### Pitfall 6: `EnsureLoginIsNotThrottled` left in pipeline despite D-12

**What goes wrong:** The Fortify provider sets `'limiters.login' => null` in config —
intending to disable throttling — but Fortify's authenticate-through pipeline still
**includes the `EnsureLoginIsNotThrottled` middleware** by default. With `'limiters.login'`
null, the middleware no-ops (it can't find the named limiter), but its presence in the
pipeline is mechanically wasteful.

**Why it happens:** Disabling the limiter and removing the middleware are two separate
concerns. Per [CITED: laravel.com/docs/13.x/fortify], the canonical way to remove the
middleware is to override `authenticateThrough()`.

**How to avoid:** Pattern 2 above explicitly overrides `Fortify::authenticateThrough(...)`
to drop `EnsureLoginIsNotThrottled`. Test: a Pest feature test that submits 100 login
attempts in a tight loop and asserts none are throttled.

**Warning signs:** Login fails with HTTP 429 after several attempts.

### Pitfall 7: `password_reset_tokens` table left dangling but Fortify still tries to write to it

**What goes wrong:** The current `config/fortify.php` has `'passwords' => 'users'` —
pointing at the config/auth.php broker which references the `password_reset_tokens` table.
If `Features::resetPasswords()` is not in the features array, Fortify won't register the
routes — BUT certain internal Fortify code paths may still touch the broker. Worth
verifying.

**Why it happens:** Features array controls route registration; the broker config is
checked separately.

**How to avoid:** Set the table reference to `null` in `config/fortify.php` or leave it as
`'passwords' => null`. Verify by running the full Pest suite after the feature drop and
checking no test hits the table.

**Warning signs:** A Pest test that exercises the reset path returns a 500 with a missing-table
error.

### Pitfall 8: The `CurrentUser` contract's `id(): int` return type breaks at signup-time

**What goes wrong:** During signup, the form submission goes through Fortify's pipeline.
The newly-created user is logged in via `PrepareAuthenticatedSession`. But **before** that
runs, the validation phase may instantiate UserScope on a query → `UserScope` calls
`$currentUser->id()` → unauthenticated context → throws `NotAuthenticatedException`. The
existing `UserScope` already catches this and silently no-ops — that behavior must be
preserved.

**Why it happens:** Race in the request lifecycle. Tested implicitly by the existing
`UserScope` catch block.

**How to avoid:** Confirmed already handled by `UserScope`. Phase 12 must not "tighten" the
exception handling — leave the silent fallthrough.

**Warning signs:** `NotAuthenticatedException` traces during signup tests.

### Pitfall 9: `User::count() === 0` check inside SignupAction is bypassed by Fortify default routes

**What goes wrong:** Phase 12 sets up Fortify with no `Features::registration()` — so the
`/register` route isn't registered. Phase 12 adds a custom `/signup` route. But if any
contributor LATER adds `Features::registration()` back to `config/fortify.php`, Fortify
registers `/register` which uses Fortify's default `CreateNewUser` action — bypassing the
SignupAction's `User::count() === 0` gate. Anyone on the network can register a second
user.

**Why it happens:** Two registration paths existing simultaneously.

**How to avoid:**
1. Use `Fortify::createUsersUsing(SignupAction::class)` — even if `Features::registration()`
   is added, the same action runs. SignupAction enforces the `User::count() === 0` check
   regardless.
2. Add a `noFortifyRegistrationFeatureEnabled` arch-test on `config/fortify.php` —
   programmatically read the file, assert `Features::registration()` is NOT in the array.

**Warning signs:** A second user appears in the `users` table via an unauthenticated request.

## Validation Architecture

> Nyquist validation is enabled (config/json default). Required section.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.x (PHPUnit 11 engine) + pest-plugin-arch + pest-plugin-laravel + spatie/pest-plugin-snapshots |
| Config file | `phpunit.xml` (project root) + `tests/Pest.php` |
| Quick run command | `pest --filter=<TestName>` |
| Full suite command | `pest --parallel` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| MULTI-01 | `CurrentUser` contract bound and resolvable | unit | `pest tests/Contracts/BoundaryArchTest.php --filter='noAuthFacadeOrHelper'` | ❌ Wave 0 — append rule |
| MULTI-01 | `Auth::user()` / `auth()` / `request()->user()` / `request()->session()` forbidden across all modules | arch | `pest tests/Contracts/BoundaryArchTest.php --filter='noAuthFacadeOrHelper'` | ❌ Wave 0 |
| MULTI-02 | `/login` Volt page renders + accepts valid credentials | feature | `pest Modules/Auth/tests/Feature/LoginPageTest.php` | ❌ Wave 0 |
| MULTI-02 | `/signup` returns 404 when User::count() > 0 | feature | `pest Modules/Auth/tests/Feature/SignupPageTest.php --filter='returns 404 when first user already exists'` | ❌ Wave 0 |
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

### Sampling Rate

- **Per task commit:** Quick test relevant to the slice (e.g. `pest Modules/Auth/tests/Unit/RecoveryCodeGeneratorTest.php`)
- **Per wave merge:** `pest --parallel --filter='Auth\\|Boundary\\|CrossUser\\|OAuthSecrets'`
- **Phase gate:** `pest --parallel` (full suite) + `composer analyse` (Larastan L10 strict) + `composer format:check`

### Wave 0 Gaps

- [ ] `Modules/Auth/` — entire module skeleton (provider, module.json, dirs)
- [ ] `Modules/Auth/tests/` — every test file listed in the table above
- [ ] `Modules/Auth/Database/Factories/UserRecoveryCodeFactory.php`
- [ ] `Modules/EmailScan/Models/OAuthSecret.php` + factory
- [ ] `tests/Contracts/BoundaryArchTest.php` — append `noAuthFacadeOrHelper` rule
- [ ] No framework install needed — Pest, plugins, all already present

## Security Domain

> `security_enforcement` defaults to enabled. Phase 12 is **security-load-bearing** —
> activates auth + password storage + multi-user isolation. Section required.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | `Illuminate\Contracts\Hashing\Hasher` (bcrypt); Fortify pipeline |
| V3 Session Management | yes | `SESSION_DRIVER=database`; `$session->regenerate()` on login + impersonation; 30-day lifetime per `config/session.php` |
| V4 Access Control | yes | `BelongsToUser` global scope; cross-user 404 test set (D-25); `is_developer` flag-gated routes |
| V5 Input Validation | yes | Volt component `rules()` validators on every form; Larastan L10 strict catches missing validation |
| V6 Cryptography | yes | Laravel `encrypted` cast (AES-256-CBC via `APP_KEY`); bcrypt for passwords + recovery codes |
| V7 Error Handling | yes | "Username or password is incorrect" generic error (no user enumeration); 404 for cross-user probes (not 403) |
| V8 Data Protection | yes | OAuth secrets encrypted at rest (D-17); `.bak` file at chmod 0600; passwords + recovery codes never logged |
| V9 Communications | n/a | Local-only deployment; no network surface other than loopback |
| V10 Malicious Code | n/a | No file upload paths added |
| V11 Business Logic | yes | "First user becomes developer" race protection (Pitfall 1); recovery code single-use enforcement |
| V12 Files & Resources | yes | `email-oauth.json.bak` chmod 0600; `storage/app/secrets/README.md` documents recovery path |
| V13 API | n/a | No HTTP API surface added |
| V14 Configuration | yes | `config/fortify.php` features array reviewed; `EnsureLoginIsNotThrottled` removed per D-12 |

### Known Threat Patterns for Laravel 13 + Fortify + Livewire 4 + SQLite

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| User enumeration via differential error messages | Information Disclosure | Generic "Username or password is incorrect" (UI-SPEC line 148) + `/signup` 404 once first user exists (D-03) |
| Session fixation across impersonation | Spoofing | `$session->regenerate(true)` after `loginUsingId()` (Pitfall 3) |
| Cross-user model probing via `/transactions/{id}` | Information Disclosure | `BelongsToUser` global scope returns 404 via `findOrFail` (D-25); arch test enforces every route is covered |
| Timing attack on recovery-code bcrypt check | Side Channel | bcrypt's natural ~50–200ms cost factor; iterating the unused codes runs in similar time regardless of match position |
| Force-password-change bypass via direct route access | Authorization | Global middleware enforces redirect except whitelist (Pattern 5) |
| OAuth client secret theft via DB dump | Information Disclosure | `encrypted` cast — ciphertext at rest, `APP_KEY` in `.env` (chmod 600) |
| Recovery codes leaking via Laravel debug bar / Telescope | Information Disclosure | Plaintext exists only inside `RecoveryCodeAuthenticator` for the duration of `verify()`; never serialized into Livewire props |
| `password_reset_tokens` table churn under leaked email | n/a — feature disabled | Don't add `Features::resetPasswords()` to Fortify (D-02); arch-test asserts |
| `Auth::loginUsingId()` smuggled into other modules | Authorization | `noAuthFacadeOrHelper` arch test catches the literal symbol (D-24) |
| SQLite injection via username | Injection | Eloquent + query builder parametrization; no raw concatenation |
| Case-folding bypass (`Alice` vs `alice`) | Spoofing | `lowercase_usernames=true` + `LOWER(username)` unique index (Pitfall 2) |

## Code Examples

Verified patterns from official sources.

### Volt SFC functional shape (alternative to Pattern 1's class shape)

```php
<?php
// Source: livewire.laravel.com/docs/3.x/volt
// Use this shape ONLY for trivial pages with no DI. The project's standard is the class shape.

use App\Livewire\Forms\LoginForm;
use function Livewire\Volt\{form, layout, title};

form(LoginForm::class);
layout('layouts.app');
title('Sign in · diederik');

$submit = function () {
    $this->form->authenticate();
};
?>

<form wire:submit="submit">
    <input type="text" wire:model="form.username">
    @error('form.username') <span>{{ $message }}</span> @enderror

    <button type="submit">Sign in</button>
</form>
```

### Fortify pipeline customization

```php
// Source: laravel.com/docs/13.x/fortify
Fortify::authenticateThrough(static fn (Request $request) => [
    CanonicalizeUsername::class,
    AttemptToAuthenticate::class,
    PrepareAuthenticatedSession::class,
]);
```

### Encrypted Eloquent cast

```php
// Source: laravel.com/docs/13.x/eloquent-mutators#encryption
protected function casts(): array
{
    return [
        'client_secret' => 'encrypted',
        'tokens_blob'   => 'encrypted',
    ];
}
```

### Volt route registration

```php
// Source: livewire.laravel.com/docs/3.x/volt
use Livewire\Volt\Volt;
Volt::route('/users', 'user-index');
```

### Class-based Livewire component routed via `Route::livewire()`

```php
// Source: livewire.laravel.com/docs (quickstart) + existing v1.0 routes/web.php pattern
use Modules\Auth\Internal\Http\Livewire\LoginPage;
Route::get('/login', LoginPage::class)->name('login');
```

### `BelongsToUser` consumer model — already in v1.0

```php
// Source: Modules/Core/Public/Concerns/BelongsToUser.php
use Modules\Core\Public\Concerns\BelongsToUser;
class Transaction extends Model
{
    use BelongsToUser; // auto-scopes reads + adds user_id to $fillable
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Fortify with `email` + email-based password reset | Fortify with `username` + recovery codes (local-only, no SMTP) | Phase 12 (v2.0) | Eliminates the SMTP-dead-on-arrival pitfall (#7) |
| `OAuthSecretsRepository` backed by JSON file | `OAuthSecretsRepository` backed by SQLite + `encrypted` cast | Phase 12 (v2.0) | Per-user isolation; partner gets their own Gmail/Microsoft creds |
| Livewire 3 `extends('layouts.app', ['title' => ...])` | Livewire 4 `#[Layout(...)]` + `#[Title(...)]` attributes | Livewire 4 release (Jan 2026) | Cleaner SFCs; matches v1.0 component idiom |
| Anonymous-class Volt SFC | Class-based Livewire component (paired with view) | Project preference + Larastan strict | Better PHPStan compatibility, cleaner constructor DI |
| `Str::password()` for random codes | Custom `random_int()` + alphabet | D-11 phone-readability constraint | Excludes O/0/I/1/L (`Str::password` cannot) |

**Deprecated/outdated:**

- **`Auth::loginUsingId()` as the default impersonation primitive** — modern Laravel
  ecosystem (Filament, laravel-impersonate package) uses `onceUsingId()` + middleware.
  **D-22 overrides this.** Accept the trade-off; mitigate via session-regenerate.
- **Fortify email-based password reset** — not deprecated in the framework, but disabled
  by D-02 + Pitfall 7. Recovery codes replace it.
- **`Features::registration()` in `config/fortify.php`** — not used; the `/signup` route is
  custom-defined to enforce the `User::count() === 0` gate (D-03).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Laravel 13's `encrypted` Eloquent cast defaults to AES-256-CBC and is unchanged from Laravel 12 | Standard Stack, Pattern 9 | Low — if GCM is the new default, ciphertexts still round-trip correctly through the same cast. APP_PREVIOUS_KEYS handles key rotations. [CITED: laravel.com/docs/13.x/encryption] |
| A2 | `laravel/fortify ^1.21` includes `Fortify::authenticateThrough()` and `Fortify::createUsersUsing()` | Standard Stack, Pattern 2 | Medium — if these methods landed only in 1.30+, the constraint becomes `^1.30`. Mitigation: verify by checking `vendor/laravel/fortify/src/Fortify.php` for the static method definitions during Plan 1. [VERIFIED via composer + SUMMARY.md "1.37.2"] |
| A3 | Livewire 4's `#[Layout]` and `#[Title]` attributes work on `final` classes (not just non-final) | Pattern 1 | Low — PHP attributes are read by reflection on any class shape. |
| A4 | `Route::getRoutes()` returns enough metadata (signatureParameters) to detect model-scoped binding at test boot | Pattern 8 | Medium — `signatureParameters()` is on the `Route` object; if Laravel 13 changed the API, fall back to parsing the URI pattern `\{[a-z_]+\}`. |
| A5 | Migration files are part of the existing v1.0 carve-out for `base_path()` / `storage_path()` global helpers | Pattern 9 | Low — confirmed by reading existing v1.0 migrations that use `storage_path()` (e.g. `OAuthSecretsRepository::absolutePath`). If not in the carve-out, inject Filesystem at the migration boundary via `Container::getInstance()->make()`. |
| A6 | `system_alerts` rows can be inserted via `DatabaseManager::table('system_alerts')->insert(...)` from anywhere — no need to introduce a new `SystemAlertEmitter` Public service | Pattern 4 | Medium — the existing v1.0 reads-only `SystemAlertQuery` service does not have a corresponding write surface. **Open Question:** introduce `SystemAlertEmitter` in `Modules/Core/Public/` or write directly via `DatabaseManager`. Cross-module access via `DatabaseManager` is mechanically fine but blurs the bounded-context boundary. |
| A7 | The existing single dev `User` row's `email` value can be split on `@` to derive a valid `username` (data migration on the `users` table reshape) | Runtime State Inventory | Low — the local dev's email is known. If the migration runs in CI or on a fresh box where no user exists, the data-migration step is a no-op. |
| A8 | `password_reset_tokens` table can be left in place even though no code path writes to it | Anti-Patterns | Low — orphan tables are harmless. But it confuses future readers. **Open Question:** drop it in this phase or defer to Phase 19's release-hygiene sweep. |
| A9 | The `Modules/Auth/` module gets `priority: 0` like Core, OR a low priority (e.g. 1) | Recommended Project Structure | Medium — `ModulePrioritiesArchTest` asserts Core has strictly the lowest priority. Auth must be `>= 1`. **Open Question:** does Auth depend on Core's `User::class` alias being registered first? If yes → Auth priority = 1 (after Core). If no → Auth = 0 alongside Core. Recommendation: **priority = 1**. |
| A10 | The Fortify `Authenticator` closure passed to `Fortify::authenticateUsing()` is what gets called for `username`-based login (vs the default email flow) | Pattern 2 | Low — verified by reading the current `FortifyServiceProvider` which already does this for email. |
| A11 | `Auth::loginUsingId()` in Laravel 13 is `\Illuminate\Contracts\Auth\StatefulGuard::loginUsingId()`, callable via injected `AuthManager` → `guard()` → `loginUsingId()` — no facade required inside `ImpersonateUserAction` | Pattern 6 | Low — verified by the `Illuminate\Contracts\Auth\StatefulGuard` interface having this method. |

## Open Questions

1. **`Modules/Auth/` module priority** (A9 above).
   - What we know: `ModulePrioritiesArchTest` says Core must be lowest. Auth must be ≥ 1.
   - What's unclear: Does Auth's provider need to boot before Core's `User::class` alias?
   - Recommendation: **priority = 1** (boots right after Core). This keeps the Fortify provider
     binding consistent with where it lives now (inside Core's provider chain via `register(FortifyServiceProvider::class)`).

2. **`SystemAlertEmitter` Public service vs direct `DatabaseManager` write** (A6 above).
   - What we know: v1.0 has `SystemAlertQuery` for reads but no write contract. Multiple modules
     (EmailScan, Chains, Recurring, etc.) write system_alerts rows.
   - What's unclear: How do EmailScan/Chains currently write? Inline `DB::insert` or via a helper?
   - Recommendation: Plan 1's first task does a `grep -rn "system_alerts" Modules/` to inventory
     existing writers. If a pattern emerges, follow it. If writes are scattered, propose adding
     a `Modules/Core/Public/Services/SystemAlertEmitter` as a minor refactor (orthogonal to Phase 12).

3. **Drop `password_reset_tokens` table or leave it?** (A8 above).
   - What we know: Nobody writes to it once `Features::resetPasswords()` is removed.
   - What's unclear: Whether dropping it now is worth a migration vs leaving for Phase 19's
     cleanup sweep.
   - Recommendation: **leave in place**. Orphan tables are harmless; dropping it requires a
     destructive SQLite migration (which on SQLite means CREATE-TABLE-COPY-DROP — fragile).
     Phase 19's release-hygiene sweep is the right phase to remove it.

4. **Data-migration step for the existing dev user's `email` → `username`** (A7 above).
   - What we know: The schema drops `email` and adds `username`. If the table has data, the
     drop will need a data-migration step.
   - What's unclear: Should the migration auto-derive `username` from `email`-local-part, or
     require the dev to manually re-`diederik:reset-password` after migration?
   - Recommendation: **Auto-derive in the migration**. `SELECT id, email FROM users; UPDATE users
     SET username = SUBSTR(email, 1, INSTR(email, '@') - 1) WHERE username IS NULL;` — runs inside
     the same migration that adds the column. If `INSTR(email, '@') = 0`, fallback `username = email`.

5. **`is_developer` column ownership: Phase 12 vs Phase 16 (DEVUI-01)** .
   - What we know: STATE.md says "DEVUI-01 (is_developer flag + EnsureDeveloperMode middleware
     + Settings toggle) lives entirely in Phase 16" — but Phase 12 references the column in D-04,
     D-05, D-09, D-21.
   - What's unclear: Does Phase 12 add the `is_developer` column, or does Phase 16, or both?
   - Recommendation: **Phase 12 adds the column** (migration). Phase 16 adds the middleware +
     Settings toggle. STATE.md is consistent with this — "Phase 12's signup-creates-developer
     behavior consumes the column but does not own it" means: Phase 12 owns the schema, Phase 16
     owns the UI. Plan accordingly.

6. **The "Authenticator" closure in `Fortify::authenticateUsing()` vs `SignupAction` calling Fortify**.
   - What we know: The current `FortifyServiceProvider` uses `Fortify::authenticateUsing()` as a
     closure that returns the `User` or `null`. Phase 12 moves it to a class
     (`Modules/Auth/Internal/Fortify/Authenticator.php`).
   - What's unclear: How does the Authenticator class relate to `RecoveryCodeAuthenticator`?
     If the user types a recovery code in the password field, the closure should detect that and
     fall through to recovery-code auth instead.
   - Recommendation: **Two paths, not interleaved.** `/login` form has TWO fields: `password`
     (normal) AND a separate flow at `/reset-password` (recovery code). Don't try to auto-detect
     "is this a password or a recovery code?" — that's brittle. Phase 12's UI-SPEC §"Sign-in flow"
     confirms: `/login` has only username + password; the recovery-code path lives at
     `/reset-password`.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.5 | All code | ✓ | 8.5 (Herd) | — |
| SQLite 3.45+ | All migrations + sessions | ✓ | 3.45+ (Herd bundled) | — |
| `random_int()` PHP builtin | RecoveryCodeGenerator | ✓ | bundled | — |
| `random_bytes()` PHP builtin | RecoveryCodeGenerator (alternative impl) | ✓ | bundled | — |
| Bcrypt (`Hasher`) | Password + recovery-code hashing | ✓ | bundled | — |
| `openssl` PHP extension | `encrypted` cast | ✓ | bundled | — |
| Laravel Herd | Local dev | ✓ | latest | — |
| No SMTP daemon | (intentional — D-02) | n/a (intentionally absent) | — | — |

**Missing dependencies with no fallback:** None.
**Missing dependencies with fallback:** None.

## Plan Skeleton (Recommended Vertical Slices)

> Skeleton — the planner will refine. Suggested 7 plans, vertical MVP per phase (C-04).

**Plan 1 — Module skeleton + arch test.** Creates `Modules/Auth/` directory, `module.json`
(priority = 1), service provider stub, route file stub. Appends `noAuthFacadeOrHelper` rule
to `BoundaryArchTest.php` with the D-24 allow-list. Demo: arch test runs green; no
production code yet but the rule is in place.

**Plan 2 — Schema reshape.** Adds migrations: drop `email`, add `username` + unique
`LOWER(username)` index, add `is_developer`, add `force_password_change_at_next_login`.
Updates User model. Adds data migration for existing dev row. Updates UserFactory. Demo:
`migrate:fresh` works; `users` table has new shape.

**Plan 3 — Fortify wiring + login Volt page.** Moves FortifyServiceProvider to
`Modules/Auth/Internal/Fortify/`. Customizes `config/fortify.php`. Wires
`authenticateThrough()` per Pattern 2. Builds `LoginPage` Volt class + view per UI-SPEC.
Wires `/login` and `/logout` routes. Demo: dev can log in via `/login` with username +
password (using the data-migrated username).

**Plan 4 — Signup ceremony + recovery codes.** Adds `user_recovery_codes` table migration.
Builds `RecoveryCodeGenerator`. Builds `SignupAction` (gated on `User::count() === 0`,
auto-flips `is_developer`, generates 10 codes, hashes them). Wires `Fortify::createUsersUsing()`.
Builds `SignupPage` + `RecoveryCodesDisplay` Volt components per UI-SPEC. Wires `/signup`
route (returns 404 when first user exists). Demo: fresh DB → `/signup` → dev creates
account → sees 10 codes → downloads `.txt` → continues to dashboard.

**Plan 5 — Force-password-change + reset flow.** Builds `ForcePasswordChangeMiddleware`
(Pattern 5). Registers in `web` + `auth` stack. Builds `ChangePasswordPage` Volt.
Builds `RecoveryCodeAuthenticator` (Pattern 4). Builds `ResetPasswordPage` Volt.
Builds `ResetPasswordAction` + `RegenerateRecoveryCodesAction`. Builds two console commands
(`diederik:reset-password`, `diederik:regenerate-recovery-codes`). Demo: dev forgets
password → goes to `/reset-password` → enters code → resets → logs in → forced to change
password (because reset code path sets the flag).

**Plan 6 — OAuth secrets rewire.** Adds `oauth_secrets` table migration. Adds
`OAuthSecret` model. Rewrites `OAuthSecretsRepository` to read from SQLite + accept
`CurrentUser`. Adds the JSON-rename migration (D-19). Adds the boot-time
`oauth.reauth_required` system_alert emitter (deferred listener fires on first authenticated
request post-migration). Demo: existing JSON renamed to `.bak`; EmailScan tests pass
against the new repo; warning alert appears in banner on first login post-migration.

**Plan 7 — Impersonation back-end + Add-user + Manage-user pages + cross-user 404 test set.**
Builds `ImpersonateUserAction`, `EndImpersonationAction`, `AddUserAction`,
`ImpersonationBannerMiddleware`. Builds `AddUserPage`, `ManageUserPage` Volt components per
UI-SPEC. Wires `/settings/users/new` and `/settings/users/{username}` routes (developer-gated).
Builds `CrossUserIsolationTest` (Pattern 8). Demo: owner can add a partner from Settings;
the impersonation action works via direct call from a Pest test; the banner appears for an
"impersonated" session in a test; the cross-user 404 test set is green across every
model-scoped route.

## Sources

### Primary (HIGH confidence)

- **Laravel 13 Fortify docs** — `https://laravel.com/docs/13.x/fortify` — verified
  `Fortify::loginView()` signature, `authenticateThrough()` pipeline customization,
  `EnsureLoginIsNotThrottled` middleware, `Fortify::createUsersUsing()` action, `username`
  field configuration.
- **Laravel 13 Encryption docs** — `https://laravel.com/docs/13.x/encryption` — default
  cipher AES-256-CBC, `APP_PREVIOUS_KEYS` for rotation, `encrypted` cast.
- **Livewire 4 Lifecycle Hooks docs** — `https://livewire.laravel.com/docs/lifecycle-hooks`
  — confirms `boot()` and `mount()` support DI by type-hint; method injection works on
  all lifecycle hooks.
- **Livewire 4 Volt docs** — `https://livewire.laravel.com/docs/3.x/volt` — Volt SFC
  syntax, functional + class-based shapes, `mount()` DI, `form()` helper, route
  registration via `Volt::route()`, layout/title hooks.
- **CONTEXT.md** — Phase 12 user decisions D-01 through D-27 (locked).
- **UI-SPEC.md** — Phase 12 visual contract (already approved).
- **`.planning/research/SUMMARY.md` §"Phase 12 (A)"** — Fortify activation rationale.
- **`.planning/research/PITFALLS.md` §#4, §#5, §#7** — auth facade leakage, missing
  `where('user_id')`, SMTP password reset failure modes.
- **`.planning/research/ARCHITECTURE.md`** — three-new-modules pattern, DI-rule extension.
- **`tests/Contracts/BoundaryArchTest.php`** — existing arch-test patterns to mirror
  (`noFacadeCallsFromCoreConsoleCommands`, `noLaravelGlobalHelpersInCoreConsoleCommands`).
- **`Modules/Core/Internal/Providers/FortifyServiceProvider.php`** — current Fortify wiring
  to model the new module's provider on.

### Secondary (MEDIUM confidence)

- **"Adding User Impersonation to Laravel (The Right Way)"** —
  `https://notesonlaravel.com/laravel-impersonate-users/` — session-bleed concern with
  `loginUsingId`, mitigated in research by `$session->regenerate()`.
- **"Three ways to impersonate a user in Laravel"** —
  `https://www.amitmerchant.com/three-ways-to-impersonate-a-user-in-laravel/` — corroborates
  the session-bleed concern.
- **"Security Tip: Laravel's Password Generator"** —
  `https://securinglaravel.com/security-tip-new-password-generator/` — confirms
  `Str::password()` does not support custom alphabets / ambiguous-char exclusion.
- **GitHub: laravel-impersonate** — `https://github.com/404labfr/laravel-impersonate`
  — reference implementation (not adopted; D-22 picks a leaner pattern).

### Tertiary (LOW confidence)

- **Laracasts threads on SQLite case sensitivity** — `https://laracasts.com/discuss/channels/eloquent/sqlite-problem-case-sensitive`
  — confirms the `LOWER(name)` unique index workaround is the canonical SQLite approach.
- Laravel 12 / 13 cross-version notes — assumed identical behavior for `encrypted` cast.

## Metadata

**Confidence breakdown:**

- Standard stack: HIGH — all packages already in composer.lock; versions verified.
- Architecture: HIGH — every contract and migration pattern has a direct v1.0 precedent.
- Pitfalls: HIGH — every pitfall has a documented mitigation traced to either the existing
  codebase or official Laravel/Fortify docs.
- Volt SFC patterns: HIGH — confirmed via Volt 3.x docs (Livewire 4 inherits the same model
  per the release announcement).
- Session-bleed risk on impersonation: MEDIUM — D-22 mandates `loginUsingId`; mitigated but
  not as clean as the `onceUsingId` alternative.

**Research date:** 2026-05-19
**Valid until:** 2026-06-19 (30 days — Laravel 13 + Fortify 1.x are stable; revisit if
Laravel 14 ships before then)

## RESEARCH COMPLETE
