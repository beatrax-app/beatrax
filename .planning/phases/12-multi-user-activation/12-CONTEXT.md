# Phase 12: Multi-User Activation - Context

**Gathered:** 2026-05-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Activate the dormant multi-user schema so two users can sign up, log in, log
out, and each see only their own data. The codebase enforces user isolation via
the existing `BelongsToUser` global scope plus a DI-friendly `CurrentUser`
contract, with `Auth::user()` / `auth()` / `request()->user()` /
`request()->session()` forbidden by arch test across every module *except* the
new `Modules/Auth/` (and the impersonation action) where authentication state
is legitimately mutated.

Phase 12 ships:

- A new `Modules/Auth/` module hosting Fortify config + login / signup / logout
  / recovery-codes / password-reset / regenerate-codes Livewire (Volt) pages.
- A `users` schema reshape — drop `email`, add `username` (unique), add
  `is_developer`, add `force_password_change_at_next_login`. The first signup
  flips `is_developer = true` automatically.
- A new `user_recovery_codes` table — bcrypt-hashed codes, 10 per user, audit
  via `used_at`.
- A new `oauth_secrets` table — per (user_id, provider) row with `encrypted`
  cast on sensitive columns; `OAuthSecretsRepository` rewired to be
  `CurrentUser`-scoped; existing JSON file deleted (clean break).
- The back-end mechanism for "act as partner" (the UI lands in Phase 16) plus
  a banner-rendering middleware so any future caller of the swap gets the
  visual cue for free.
- Two CLI commands: `php artisan diederik:reset-password <username>` and
  `php artisan diederik:regenerate-recovery-codes <username>` (both
  interactive, both hidden-prompt input).
- Cross-user 404-not-403 Pest test set covering every route in
  `Modules/*/Routes/web.php`.
- Extension of `BoundaryArchTest`: `noAuthFacadeOrHelper` invariant with an
  explicit allow-list (Fortify config, `Modules/Auth/` actions/services, the
  impersonation action).

Phase 12 does NOT ship:

- Any UI surface for switching users (Phase 16 / Dev Console).
- Multi-user-aware UAT close-out (Phase 20).
- SMTP-based password reset (intentionally never in v2.0).
- Email of any kind (no `email` column anywhere).
- v1.0-data migration of users / chains / secrets — that's Phase 13's wizard.

</domain>

<decisions>
## Implementation Decisions

### Auth Surface + Signup Ceremony

- **D-01: Auth module home.** Create new `Modules/Auth/` (Public surface for
  `LoginAction`, `SignupAction`, `LogoutAction`, `ResetPasswordAction`,
  `RegenerateRecoveryCodesAction`; Internal for Livewire pages and the
  Fortify provider). Mirrors the structure of the other 11 modules.
- **D-02: Login identifier is `username`, not `email`.** Drop the `email`
  column from `users`; add `username` (unique, citext-equivalent — store
  lowercase or maintain a `LOWER(username)` unique index). Fortify's
  authentication pipeline is configured to use `username`. No SMTP, no
  email-verification, no password-reset emails — anywhere.
- **D-03: Signup policy.** Public `/signup` is open ONLY when
  `User::count() === 0`. After the first user is created, `/signup` returns
  HTTP 404 (route-level, not 403 — never reveals that the URL ever existed).
  The owner creates the second user from an in-app "Add user" page (lives
  inside the authenticated app, behind the `is_developer` middleware that
  Phase 16 will harden — for Phase 12, gate it on `is_developer` directly).
- **D-04: First-user-becomes-developer.** Signup action checks
  `User::count() === 0` inside the transaction; if true, sets
  `is_developer = true` on the new row. Plus a CLI escape hatch:
  `php artisan diederik:grant-dev <username>` (interactive confirm) for
  promoting / demoting later.
- **D-05: Partner-account initialization.** Owner's "Add user" page collects
  `username` + initial password (plain text input + confirm). The new user
  row is born with `force_password_change_at_next_login = true`. Owner
  reads the initial password to the partner; partner logs in, is force-
  redirected to set their own password, then sees their recovery codes
  inline. No email is involved at any step.
- **D-06: Recovery-codes display at signup.** Inline dedicated page (no
  modal), full-width on a calm Linear-style layout. "Download .txt" button
  produces a plain text file named
  `diederik-recovery-codes-<username>.txt`. A plain checkbox ("I have saved
  these codes somewhere safe") gates the "Continue" button. After the
  user navigates away, the codes are never shown again — only regenerated.

### Recovery-Codes Mechanics

- **D-07: Storage.** New table `user_recovery_codes` with columns
  `(id, user_id, code_hash, used_at, created_at)`. `code_hash` is
  bcrypt-hashed (same default cost as user passwords). Unique index on
  `(user_id, code_hash)` is unnecessary (bcrypt salts make duplicate hashes
  vanishingly unlikely); add a non-unique index on `user_id`. Consumed
  codes get `used_at = now()` and are NOT deleted — they stay for audit.
- **D-08: What a code authorizes.** Both:
  1. Password-reset via `/reset-password` (typing `username` + recovery code
     + new password updates the password and stamps the code consumed).
  2. One-time login via `/login` (typing `username` + recovery code signs
     the user in AND sets `force_password_change_at_next_login = true` so
     they must set a new password before any other action in that session).
  Implementation: `RecoveryCodeAuthenticator` is shared by both routes.
- **D-09: Owner-resets-partner UI.** On the partner's profile page (visible
  only to `is_developer = true` users), two buttons are present:
  1. "Set new password for this user" — opens a modal collecting the new
     plaintext password. Sets `force_password_change_at_next_login = true`.
     Does NOT regenerate codes.
  2. "Regenerate recovery codes for this user" — shows the new 10 codes
     inline (same page as signup ceremony). Owner downloads `.txt` and
     hands them off (or reads aloud). Old unused codes are invalidated.
- **D-10: Regeneration semantics.** Available from Settings (self) and the
  owner's view of the partner's profile (other). Regeneration creates 10
  new rows, then either deletes or stamps `used_at` on the unused old rows
  — choose stamping (`used_at = now()`) to keep audit chain intact. UI
  shows a nudge banner ("Only 3 recovery codes remain") when
  `count(used_at IS NULL) <= 3` for the active user.
- **D-11: Code format.** 5 groups of 4 alphanumeric characters joined by
  hyphens. Uppercase letters + digits; ambiguous characters removed (no
  `O`, `0`, `I`, `1`, `L`). Example: `A2BJ-XK9M-PQ7N-RX4F-V8HD`. ~104 bits
  of entropy. Phone-readable for the owner-reads-aloud handoff.
- **D-12: No app-level rate limit.** Local-only deployment + bcrypt cost
  factor is the defense. Trade-off accepted; explicit "no rate limit"
  decision recorded so the Phase 19 security auditor doesn't flag it as
  an oversight. (If the desktop bundle is ever exposed via a tunnel for
  remote partner access, this decision must be revisited.)
- **D-13: Audit.** Every recovery-code attempt — success or failure —
  emits a `system_alerts` row. `severity = warning` on success
  ("Recovery code consumed for <username>"); `severity = error` on
  failure ("Failed recovery code attempt for <username>"). Reuses the
  v1.0 `Modules\Core\Public\Services\SystemAlertQuery` infrastructure;
  the alert banner surfaces to both users.
- **D-14: CLI shape.** Two interactive-only commands:
  - `php artisan diederik:reset-password <username>` — prompts for new
    password (hidden `secret()` input) + confirmation; sets the new
    password; sets `force_password_change_at_next_login = true`. Does not
    touch recovery codes.
  - `php artisan diederik:regenerate-recovery-codes <username>` — prints
    the 10 new codes to stdout (the operator captures them); invalidates
    old unused codes.
  Both refuse non-interactive use (no `--password=` style flag).

### `oauth_secrets` Table + JSON Migration

- **D-15: Table shape.** New table `oauth_secrets`:
  ```
  id              bigint primary key
  user_id         bigint not null  references users(id) on delete cascade
  provider        string not null  -- 'gmail' | 'microsoft'
  client_id       string           -- plaintext, queryable
  client_secret   text             -- encrypted via Laravel cast
  redirect_uri    string           -- plaintext, queryable
  tokens_blob     text             -- encrypted JSON: access_token, refresh_token, expires_at, scopes
  created_at      timestamp
  updated_at      timestamp
  ```
  Unique index on `(user_id, provider)`.
- **D-16: Repository scoping.** `OAuthSecretsRepository` constructor gains a
  `CurrentUser` dependency. Every public method (`hasProviderClient`,
  `getProviderClient`, `saveProviderClient`, `getInboxTokens`,
  `saveInboxTokens`, ...) implicitly filters by `currentUser->id()`. The
  public signatures of existing methods stay the same so EmailScan,
  Receipts, and the OAuth pipeline don't change.
- **D-17: Encryption.** `client_secret` and `tokens_blob` use Laravel's
  `encrypted` cast (AES-256-CBC via APP_KEY). `client_id` and
  `redirect_uri` are plaintext (queryable, non-secret). APP_KEY lives in
  `.env` (chmod 600); per-install regeneration is handled by Phase 17.
- **D-18: Fate of `storage/app/secrets/email-oauth.json`.** **Delete the
  file** as part of the migration. The operator (the developer) must
  re-authorize Gmail and Microsoft via the existing OAuth pipeline after
  Phase 12 deploys. On first boot under the new schema, a `system_alerts`
  warning fires: "OAuth secrets migrated to per-user table — re-authorize
  Gmail and Microsoft to resume email scanning". **This decision
  supersedes ROADMAP success criterion 3's "migrated in-place" phrasing.**
  It aligns with Phase 13 success criterion 4 ("OAuth secrets are NOT
  auto-copied during import — wizard prompts re-authorize") — Phases 12
  and 13 now use the same clean-cut policy.
- **D-19: Migration safety.** Before the migration deletes the JSON file,
  it renames it to `email-oauth.json.pre-phase-12.bak` (chmod 0600,
  alongside its original location) so the operator has a rollback path
  if Phase 12 deploy turns out to be premature. The rename is one-way
  — the app never reads `.bak` files. A README at
  `storage/app/secrets/README.md` documents the rename + how to recover.

### "Act as Partner" Debug Switch

- **D-20: No UI in Phase 12.** Phase 12 ships only the back-end action
  (`ImpersonateUserAction` in `Modules/Auth/Public/Actions/`) plus the
  banner middleware. Phase 16's Dev Console builds the button that
  triggers the action. A Pest feature test in Phase 12 invokes the
  action directly to prove session swap + banner rendering work.
- **D-21: Re-auth requirement.** When (in Phase 16) the developer
  triggers the switch, a modal demands the developer's OWN current
  password. The action verifies the password against the developer's
  user row via the standard Hash facade; wrong password = no switch +
  a `system_alerts` row (severity = error). Phase 12 ships the
  password-verification leg of the action; Phase 16 wires the modal.
- **D-22: Switch mechanism.** Session-attribute pivot. The action calls
  Laravel's `Auth::loginUsingId(partner_id)` and stashes
  `original_user_id` + `original_username` in the session under keys
  `auth.impersonating.original_user_id` and
  `auth.impersonating.original_username`. "Return to self" calls
  `Auth::loginUsingId(original_user_id)` and clears those keys.
  `CurrentUserService` is unchanged — it reads the active guard
  unchanged.
- **D-23: Visual cue.** Persistent non-dismissable header banner across
  every authenticated page: "Acting as `<partner-username>` —
  [Return to self]". A subtle accent-color (warning amber, soft) border
  wraps the main content area. A new
  `ImpersonationBannerMiddleware` reads
  `session('auth.impersonating.original_user_id')` and renders the
  banner via a global Blade include / Flux UI Banner component. Banner
  middleware runs after the auth middleware on every authenticated
  route group; no per-page opt-in.

### Cross-Module Boundary Tests

- **D-24: `noAuthFacadeOrHelper` arch-test rule.** Extend
  `tests/Contracts/BoundaryArchTest.php` with a new invariant: the
  symbols `Auth::user(`, `Auth::id(`, `auth()`, `request()->user(`,
  `request()->session(`, `session(` (the helper, not the facade — the
  facade is named differently in the AST), and `Auth::loginUsingId(`
  are forbidden everywhere EXCEPT:
  - `Modules/Auth/Public/Actions/*.php` (LoginAction, SignupAction,
    LogoutAction, ResetPasswordAction, ImpersonateUserAction, etc.)
  - `Modules/Auth/Internal/Fortify/**/*.php` (the Fortify provider +
    config wiring)
  - `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php`
    (reads `session()` to know when to render the banner)
  The allow-list is a tiny explicit array in the arch test — not a
  blanket exclusion of `Modules/Auth/`. Future readers must justify any
  addition by name.
- **D-25: Cross-user 404-not-403 test set.** Auto-generate the test
  matrix by introspecting Laravel's route table at test boot. For every
  route whose group is `auth`-middlewared and whose URL includes a
  model-scoped parameter (e.g. `/transactions/{transaction}`,
  `/chains/{chain}`, `/recurring/{recurring}`), the test creates two
  users, has user A create a record, logs in as user B, and asserts
  the response is HTTP 404 (not 403 — 403 leaks the existence of the
  resource to a cross-user probe). Routes that should genuinely return
  403 (none exist today) require an explicit allow-list entry.

### Sessions + Remember-Me

- **D-26: Session driver.** `SESSION_DRIVER=database` in the shipped
  build (table `sessions` already exists from v1.0's migrations). In
  the Herd dev runtime, keep whatever's in `.env` (currently
  `database`). This avoids the per-install `cookie` driver size
  limits and works inside the eventual NativePHP bundle without
  filesystem hooks. Phase 14's queue rewire already coordinates with
  the database-driven model.
- **D-27: Remember-me.** Standard Laravel "remember me" via the
  `remember_token` column on `users` (already present). 5-year
  lifetime (default). Single-machine, single-bundle context — no
  per-device session management.

### Claude's Discretion

- The exact wording and styling of the recovery-codes inline page
  (calm Linear-style — Claude picks the typography & layout subject
  to UI review).
- Whether the `system_alerts` row for OAuth re-auth uses a fresh
  alert type or an existing severity bucket — Claude picks during
  planning subject to alerts taxonomy.
- The shape of the boot-time check that decides whether to fire the
  "re-authorize" alert (likely "oauth_secrets is empty for the
  current user AND there's a leftover `email-oauth.json.pre-phase-12.bak`
  file" — Claude can refine).
- The exact code-format string format method (`Str::password()`-based
  vs `random_bytes()` + custom alphabet — pick the simpler one
  subject to the alphabet constraints in D-11).
- Whether the `force_password_change_at_next_login` flag is enforced
  via a global middleware or per-route — middleware is the cleaner
  default unless arch-test patterns disagree.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project planning

- `.planning/ROADMAP.md` §"Phase 12: Multi-User Activation" — goal,
  depends-on, requirements, success criteria. CONTEXT.md supersedes
  the JSON-migration line of success criterion 3.
- `.planning/REQUIREMENTS.md` §MULTI (MULTI-01 through MULTI-06) —
  acceptance criteria mapped to this phase.
- `.planning/PROJECT.md` — Hippocratic-3.0 license posture, calm
  Linear/Notion aesthetic, DI-only rule, local-only constraint.
- `.planning/STATE.md` — current milestone position; carried-forward
  decisions table.
- `.planning/research/SUMMARY.md` §"Phase 12 (A)" + §"Implications
  for Roadmap" — Fortify activation rationale, PITFALLS #4 / #5 / #7.
- `.planning/research/ARCHITECTURE.md` — three-new-modules pattern
  (Auth + Desktop + DevMode) and DI-rule extension.
- `.planning/research/PITFALLS.md` §#4 (Auth facade leakage), §#5
  (missing where('user_id')), §#7 (SMTP password reset) — mitigations
  this phase ships.

### v1.0 code Phase 12 extends

- `Modules/Core/Public/Contracts/CurrentUser.php` — the DI seam
  every consumer reads; Phase 12 does not change the interface.
- `Modules/Core/Public/Services/CurrentUserService.php` — default
  implementation; unchanged by Phase 12 (still reads the active
  guard; impersonation swaps the guard underneath it).
- `Modules/Core/Public/Concerns/BelongsToUser.php` — the
  trait every per-user model uses; unchanged.
- `Modules/Core/Public/Scopes/UserScope.php` — global scope that
  silently no-ops in unauthenticated contexts; unchanged.
- `Modules/Core/Models/User.php` — the model that gains
  `username`, `is_developer`, `force_password_change_at_next_login`
  columns and loses the `email` column.
- `Modules/Core/Database/Migrations/2026_05_12_000001_create_users_table.php`
  — original users-table migration; Phase 12 ships follow-up
  migrations that drop `email` and add the new columns.
- `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php`
  — current JSON-file implementation; Phase 12 reshapes the
  backend to SQLite + per-user without changing public methods.
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` —
  singleton binding for `OAuthSecretsRepository`; binding changes
  shape only if the constructor dependency list changes.

### Arch tests Phase 12 extends

- `tests/Contracts/BoundaryArchTest.php` — host of the new
  `noAuthFacadeOrHelper` invariant; current file already enforces
  the DI-only / no-helper rule for `config()` / `view()` etc.,
  so the pattern + allow-list shape can be cribbed.
- `tests/Contracts/UserIdColumnArchTest.php` — current rule for
  `user_id` columns; cross-reference when introducing
  `user_recovery_codes` and `oauth_secrets`.
- `tests/Contracts/ModulePrioritiesArchTest.php` — module
  load-order rules; `Modules/Auth/` may need an entry.

### Laravel feature surfaces Phase 12 wires

- `composer.json` — `laravel/fortify ^1.21` is already declared
  but not yet registered. Phase 12 publishes Fortify config,
  registers its provider via `Modules/Auth/Providers/...`, and
  configures the `username` login field.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`CurrentUser` contract + service** — already in place. Phase 12
  doesn't touch the interface; impersonation swaps the underlying
  guard so reads stay correct.
- **`BelongsToUser` + `UserScope`** — already work today (the scope
  silently no-ops without auth). Phase 12 just makes sure every
  per-user model uses the trait + the `user_id` column.
- **`OAuthSecretsRepository`** — current implementation is a
  carefully-built atomic writer with chmod-0600 + tmp+fsync+rename.
  Phase 12 swaps the backing store from JSON file to SQLite table
  but the **atomic semantics carry over** — `Eloquent` saves are
  transactional and the encrypted blob is replaced in a single SQL
  statement.
- **`Modules/Core/Public/Services/SystemAlertQuery.php`** + the
  `system_alerts` table — already used by v1.0 for user-visible
  warnings. Phase 12 reuses this for recovery-code audit + OAuth
  re-auth nudge.
- **`composer.json` already declares `laravel/fortify`** — no new
  composer dependency needed for Phase 12 (Fortify itself is the
  only required wiring; no `passport`, no `socialite`).

### Established Patterns

- **DI-only rule** — already enforced project-wide by
  `BoundaryArchTest`. Phase 12 extends the rule (no `Auth::user()`,
  no `auth()`, no `request()->user()`, no `request()->session()`)
  with a narrow allow-list inside `Modules/Auth/`.
- **Module Public/Internal split** — every existing module follows
  it. `Modules/Auth/` does the same: `Public/Contracts` (none new —
  consumers stay on `CurrentUser`), `Public/Actions` (Login, Signup,
  Logout, ResetPassword, RegenerateRecoveryCodes, ImpersonateUser),
  `Public/Services` (Fortify-aware factories if needed),
  `Internal/Fortify` (config + provider), `Internal/Http/Livewire`
  (the Volt pages), `Internal/Http/Middleware`
  (ImpersonationBannerMiddleware, ForcePasswordChangeMiddleware).
- **Bcrypt for code hashing** — same hashing as user passwords,
  same cost factor. No bespoke crypto.
- **`encrypted` cast on sensitive columns** — already used elsewhere
  in v1.0 if any (Claude verifies during planning); if not yet used,
  Phase 12 introduces it via the `oauth_secrets` model. Standard
  Laravel pattern.
- **Migrations live inside the module that owns the table** —
  `Modules/Auth/Database/Migrations/` for `user_recovery_codes` and
  `oauth_secrets` (the latter belongs to Auth because it's keyed by
  user; EmailScan stays a consumer).
- **Atomic actions in `Public/Actions/`** — every action is a
  single-purpose class with `__invoke` or a named method. Phase 12
  follows that pattern for every auth flow.

### Integration Points

- **Phase 13 (AppPaths + first-run wizard)** — Phase 12's
  `oauth_secrets` table must exist before Phase 13 runs, because
  Phase 13's wizard explicitly tells the partner to re-authorize.
  Phase 12 delivers the schema; Phase 13 delivers the wizard prompt.
- **Phase 14 (Queue rewire)** — Phase 12's per-user OAuth scoping
  is consumed by EmailScan jobs that run under
  `ShouldBeUniqueUntilProcessing` locks. The lock key must include
  `user_id` so concurrent same-provider scans for different users
  don't collide. Flag for Phase 14's planner.
- **Phase 15 (NativePHP shell)** — Phase 12's session driver
  (`database`) must work inside the bundle without filesystem
  surprises; Phase 15 verifies. Phase 12's "remember me" cookie
  must round-trip through the NativePHP web view.
- **Phase 16 (Dev Console)** — Phase 16 builds the UI surface for
  "act as partner", the "Add user" page, the "Reset password
  for partner" / "Regenerate codes for partner" buttons, and the
  `is_developer` toggle. Phase 12 delivers all the back-end actions
  + a `Modules/Auth/Public/Actions/AdminAddUserAction.php` etc.
  for Phase 16 to call.
- **EmailScan + Receipts modules** — both depend on
  `OAuthSecretsRepository`. Phase 12 changes the implementation
  but not the contract; consumers don't change. Verify the existing
  Pest tests still pass (`Modules/EmailScan/tests/Unit/Services/...`,
  `Modules/Receipts/tests/Unit/Pipeline/...`).
- **System alerts banner** — Phase 12 publishes alerts on
  OAuth re-auth need + recovery-code consumption + impersonation
  failures. The banner already exists in v1.0; the alert types
  are new.

</code_context>

<specifics>
## Specific Ideas

- **Code format example from D-11:** `A2BJ-XK9M-PQ7N-RX4F-V8HD` — that
  exact shape, uppercase, hyphens, no ambiguous chars. Used in the
  inline-display ceremony.
- **Recovery-codes filename:**
  `diederik-recovery-codes-<username>.txt` (lowercase username). One
  code per line, no header, no trailing whitespace — easy to grep,
  easy to print.
- **`force_password_change_at_next_login` flag:** a boolean column on
  `users`. When true, a global middleware redirects every request
  except `/change-password` (and the logout route) to
  `/change-password`. On successful change, the flag clears.
- **Impersonation banner copy:** "Acting as <partner-username> —
  [Return to self]" — verbatim, no emoji. Background: warning amber
  (calm, not red). Border tint same. The "Return to self" link calls
  the same action with `original_user_id` and clears the session
  keys.
- **`system_alerts` types Phase 12 introduces** (subject to taxonomy
  during planning):
  - `auth.recovery_code_consumed` (warning)
  - `auth.recovery_code_failed` (error)
  - `auth.impersonation_started` (warning)
  - `auth.impersonation_failed` (error — wrong password)
  - `auth.impersonation_ended` (warning)
  - `oauth.reauth_required` (warning, on first boot post Phase 12)
  - `auth.password_force_changed` (info)

</specifics>

<deferred>
## Deferred Ideas

- **Per-device session management / "log me out of other devices"** —
  no v2.0 need; single-machine app. Revisit if v3 adds an iOS
  companion.
- **Email-based recovery (forgot username → email-me-a-link)** —
  explicitly out of scope; project decision is no SMTP. Revisit only
  if a beta partner reports they cannot retain their recovery codes.
- **Sentry / crash reporting under an anonymous install UUID** —
  flagged in research/SUMMARY.md gaps but a Phase 21 / beta
  decision, not Phase 12.
- **SQLCipher / DB-at-rest encryption** — out of scope for Phase 12.
  Revisit if a beta partner mandates it.
- **TOTP / WebAuthn / passkeys as a second factor** — out of scope.
  Recovery codes + bcrypt password is the v2.0 surface. The
  `Modules/Auth/` module shape leaves room to add a `TwoFactor`
  internal subdir later.
- **Partner-shared "spaces" / read-write delegation** — explicitly
  out of scope per the v2.0 milestone scope document. Two users,
  fully isolated, period.
- **Audit-log retention policy** — Phase 12 writes `system_alerts`
  rows that never expire. If retention becomes an issue, a future
  phase can add pruning.

### Roadmap Deviations Captured Here

- **ROADMAP success criterion 3 ("legacy single-file
  storage/app/secrets/imap.json is migrated in-place")** is
  superseded by D-18. Phase 12 deletes (renames-to-`.bak`) the
  legacy JSON; the operator re-authorizes both providers. Aligns
  with Phase 13 success criterion 4. ROADMAP.md does not need a
  rewrite — CONTEXT.md is the new canonical source for Phase 12's
  policy.

</deferred>

---

*Phase: 12-multi-user-activation*
*Context gathered: 2026-05-19*
