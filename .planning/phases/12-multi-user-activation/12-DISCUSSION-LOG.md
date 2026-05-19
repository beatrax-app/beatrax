# Phase 12: Multi-User Activation - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-19
**Phase:** 12 — Multi-User Activation
**Areas discussed:** Auth surface + signup ceremony, Recovery-codes mechanics, `oauth_secrets` table design + JSON migration, "Act as partner" debug-switch UX

---

## Auth Surface + Signup Ceremony

### Q1 — Where does the auth UI live in the module tree?

| Option | Description | Selected |
|--------|-------------|----------|
| New `Modules/Auth/` module | Per research/SUMMARY.md — quarantines Fortify config, login/signup/logout Livewire pages, password-reset CLI, recovery-codes flow, profile selector. Keeps `Modules/Core/` focused on the User model + DI contracts. | ✓ |
| Inside `Modules/Core/` next to User | Auth UI is small (4-5 pages). Putting it next to `Models/User.php` avoids a new module. | |
| App-level `app/Http/Auth/` | Bypass modules for auth (Laravel's default location). Fastest, but breaks the project's module-discipline pattern. | |

**User's choice:** New `Modules/Auth/` module (Recommended).
**Notes:** Aligned with research recommendation.

### Q2 — Signup policy: how do we control who can create accounts?

| Option | Description | Selected |
|--------|-------------|----------|
| Open until 2 users exist, then auto-lock | `/signup` open until two rows; then 404. | |
| Owner-invites-partner via in-app flow | Public `/signup` locked the moment first user exists. Owner adds partner from inside the app. | ✓ (via Other) |
| Always open (no lockout) | Signup stays open forever. | |

**User's choice:** "The owner can just add another account" (free text — equivalent to option 2).
**Notes:** No open signup after first user. Public `/signup` is only available when zero users exist. Owner creates partner from an in-app "Add user" page.

### Q3 — First-user-becomes-developer rule

| Option | Description | Selected |
|--------|-------------|----------|
| Automatic at signup (row count check) | Signup action checks `User::count() === 0` before insert; if true, sets `is_developer = true`. | |
| Automatic + CLI to grant later | Same auto-grant, plus `php artisan diederik:grant-dev <username>` for later promotion. | ✓ |
| Seeder-only (manual) | Signup never grants dev; a seeder sets it. | |

**User's choice:** Automatic at first user + CLI to grant later.
**Notes:** Belt-and-suspenders — auto for the common case, CLI for the edge case (promote partner during a spike, etc.).

### Q4 — Partner-account initialization: how does owner add a partner?

| Option | Description | Selected |
|--------|-------------|----------|
| Owner types both email and password | Email + password + force-change-at-first-login. | |
| App generates a one-time password | Owner provides email; app generates strong random password. | |
| Recovery codes only — no password | First login via a recovery code. | |

**User's choice:** "Username and password. No email should be needed." (free text — superseded the email assumption entirely).
**Notes:** Major decision — `users.email` column is dropped, replaced by `username`. No SMTP, no email anywhere in v2.0.

### Q5 — Recovery codes display at signup

| Option | Description | Selected |
|--------|-------------|----------|
| Modal once + downloadable .txt + typed-ack | Belt-and-suspenders modal; can't dismiss without acknowledging. | |
| Modal once + typed-ack only (no download) | Forces transcription. | |
| Inline page (no modal), download button, plain checkbox | Less aggressive UX. | ✓ |

**User's choice:** Inline page (no modal), download button, plain checkbox.
**Notes:** Calm Linear-style — no aggressive modals; trust the user to read.

---

## Recovery-Codes Mechanics

### Q1 — Storage

| Option | Description | Selected |
|--------|-------------|----------|
| Separate table, one bcrypt hash per code | Schema: `(id, user_id, code_hash, used_at, created_at)`. | ✓ |
| AES-encrypted JSON blob on `users` table | Single column; full decrypt on every check. | |
| Plaintext-hashed (sha256) in side table | Cheaper but brute-forceable for short codes. | |

**User's choice:** Separate `user_recovery_codes` table, bcrypt per code.
**Notes:** Audit-friendly (used_at stamping), standard pattern.

### Q2 — What does a recovery code authorize?

| Option | Description | Selected |
|--------|-------------|----------|
| Password-reset only | Narrow, conservative; code never logs you in. | |
| Password-reset + one-time login | Code on `/login` signs you in + forces password reset in same session. | ✓ |
| Any "prove I'm me" ceremony | Most flexible; biggest blast radius. | |

**User's choice:** Password-reset + one-time login.
**Notes:** Single mechanism handles both "I forgot my password" AND "I forgot I had one set". Forces password change in same session for the one-time-login path.

### Q3 — Owner-resets-partner flow

| Option | Description | Selected |
|--------|-------------|----------|
| Owner sets a new password directly | "Reset password" button only. | |
| Owner triggers "regenerate recovery codes" | "Regenerate codes" button only; partner uses one to reset. | |
| Both (owner picks which UI to use) | Both buttons visible. | ✓ |

**User's choice:** Both — owner picks per situation.
**Notes:** More UI surface, but the two buttons mean different things (direct override vs hands-off-recovery-loop) so both have legitimate use cases.

### Q4 — Regeneration semantics

| Option | Description | Selected |
|--------|-------------|----------|
| On-demand from Settings; full replacement invalidates old | New batch replaces unused old codes. Auto-prompt at <=3 remaining. | ✓ |
| Only when codes run out | User must consume all codes before new ones issue. | |
| Manual only, no auto-prompt at low remaining | Lighter UI; users might not realize they're running out. | |

**User's choice:** On-demand from Settings; full replacement; auto-prompt at <=3 remaining.

### Q5 — Code format

| Option | Description | Selected |
|--------|-------------|----------|
| 5 groups of 4 alphanumeric (`A2BJ-XK91-...`) | ~104 bits entropy. Phone-readable. | ✓ |
| 6 groups of 4 hex (`a2b3-c4d5-...`) | Hex-only; less ambiguous. | |
| 8-character alphanumeric (`A2BJ-XK91`) | Shorter; less margin. | |

**User's choice:** 5 groups of 4 alphanumeric, uppercase, no ambiguous characters.

### Q6 — Rate-limiting

| Option | Description | Selected |
|--------|-------------|----------|
| 5 attempts per username per 15 min | Standard `RateLimiter` recipe. | |
| 3 attempts per IP per 15 min | Tighter; awful for local dev. | |
| No app-level rate limit | Rely on local-only + bcrypt cost. | ✓ |

**User's choice:** No app-level rate limit. Trade-off explicitly captured.
**Notes:** Acceptable for a local-only app. Phase 19 security audit should re-confirm. If desktop bundle ever exposed via tunnel for remote access, this MUST be revisited.

### Q7 — Audit

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — to `system_alerts` | Uses existing v1.0 alerts infra; visible to both users. | ✓ |
| Yes — to Laravel log only | Cheaper; invisible to non-developer. | |
| No audit | YAGNI. | |

**User's choice:** Audit to `system_alerts` (severity warning on success, error on failure).

### Q8 — CLI shape

| Option | Description | Selected |
|--------|-------------|----------|
| Interactive: `php artisan diederik:reset-password <username>` | Prompts for password via hidden `secret()` input. | ✓ |
| `--regenerate-codes` flag | Mixes responsibilities. | |
| Non-interactive: pass password as arg | Leaks plaintext into shell history. | |

**User's choice:** Interactive only. Separate `diederik:regenerate-recovery-codes <username>` command for code regen.

---

## `oauth_secrets` Table Design + JSON Migration

### Q1 — Table shape

| Option | Description | Selected |
|--------|-------------|----------|
| Columnar per (user_id, provider), encrypted columns | Field-level encryption; queryable redirect_uri. | ✓ |
| Single encrypted_payload_blob column | Minimal schema; full decrypt every read. | |
| Separate tables (oauth_provider_clients + oauth_inbox_tokens) | Over-engineered for two users. | |

**User's choice:** Columnar per (user_id, provider) with encrypted columns.

### Q2 — Repository scoping

| Option | Description | Selected |
|--------|-------------|----------|
| Inject `CurrentUser`; all reads/writes scoped | Silent user_id filtering. Consumers unchanged. | ✓ |
| Take `user_id` explicitly at every call site | Verbose; bypasses DI seam. | |
| Singleton + per-request scope decorator | Two layers; over-engineered. | |

**User's choice:** Inject CurrentUser; scope every method silently.

### Q3 — Fate of the existing `email-oauth.json`

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-import to user_id=1 on first boot; move to `.bak` | Roadmap-aligned: "migrated in-place". | |
| Migration as a separate artisan command (no auto-run) | Safer; easy to forget. | |
| Delete the JSON; operator re-authorizes | Cleanest cut; no carry-over. | ✓ |

**User's choice:** Delete the JSON; operator re-authorizes.
**Notes:** This SUPERSEDES ROADMAP success criterion 3. Implementation softens to "rename-to-`.bak`" for rollback safety per D-19. Aligns with Phase 13's already-locked policy on re-authorization.

### Q4 — Encryption strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Laravel `encrypted` cast (AES-256-CBC via APP_KEY) | Standard Laravel pattern. | ✓ |
| Explicit `Crypt::encryptString` in repository | More code; same key. | |
| SQLite-level encryption via SQLCipher | Strong but requires bundling SQLCipher. | |

**User's choice:** Laravel `encrypted` cast.

---

## "Act as Partner" Debug-Switch UX

### Q1 — UI surface

| Option | Description | Selected |
|--------|-------------|----------|
| App-menu dropdown next to user avatar | Visible to all users. | |
| Profile selector page | Plex/Netflix mental model. | |
| Only via Dev Console (Phase 16) — developer-only | Hardens surface; UI lands in Phase 16. | ✓ |

**User's choice:** Only via Dev Console (Phase 16).
**Notes:** Major scope narrowing — Phase 12 ships only the back-end action; Phase 16 builds the UI.

### Q2 — Re-auth requirement

| Option | Description | Selected |
|--------|-------------|----------|
| Require typed password confirmation | sudo-style. | ✓ |
| Confirmation modal only | No "something I know" barrier. | |
| No prompt at all | Audit-only accountability. | |

**User's choice:** Typed password confirmation.

### Q3 — Visual cue

| Option | Description | Selected |
|--------|-------------|----------|
| Persistent non-dismissable header banner + chrome accent tint | Impossible to miss. | ✓ |
| Header banner only (no chrome tint) | Lighter. | |
| Tiny avatar swap + tooltip only | Too subtle. | |

**User's choice:** Banner + accent border tint.

### Q4 — Switch mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Session-attribute pivot: `Auth::loginUsingId(partner_id)` + `original_user_id` in session | Simplest; CurrentUserService unchanged. | ✓ |
| Dedicated `ImpersonationService` with identity stack | Over-engineered for two users. | |
| Full logout/login cycle with stored "return ticket" | Slow + brittle + security risk. | |

**User's choice:** Session-attribute pivot.

---

## Claude's Discretion

- Exact wording and styling of the recovery-codes inline page (calm Linear-style; Claude picks typography & layout subject to UI review).
- Whether the `system_alerts` row for OAuth re-auth uses a fresh alert type or an existing severity bucket — Claude picks during planning.
- The boot-time check that decides whether to fire the "re-authorize" alert.
- Exact code-format generation method (`Str::password()`-based vs `random_bytes()` + custom alphabet).
- Whether `force_password_change_at_next_login` is enforced via a global middleware or per-route — middleware is the default unless arch tests disagree.

---

## Deferred Ideas

- Per-device session management ("log me out of other devices").
- Email-based recovery (no SMTP per project decision).
- Sentry / anonymous install UUID for crash reporting (Phase 21).
- SQLCipher / DB-at-rest encryption (future).
- TOTP / WebAuthn / passkeys as second factor.
- Partner-shared "spaces" / read-write delegation (explicitly out of scope per milestone).
- Audit-log retention policy.

## Roadmap Deviations

- **ROADMAP Phase 12 success criterion 3** ("storage/app/secrets/imap.json is migrated in-place") is **superseded** by CONTEXT.md D-18. Phase 12 deletes (renames-to-`.bak`) the legacy JSON; operator re-authorizes. ROADMAP.md is not rewritten — CONTEXT.md is canonical for the policy from this point forward.
