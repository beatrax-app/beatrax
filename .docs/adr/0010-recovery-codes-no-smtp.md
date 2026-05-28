# ADR 0010 — Password reset via recovery codes; no SMTP-based reset in v2.0

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-32

## Context

v2.0 introduces multi-user partner-sharing. Multi-user means real
authentication: usernames, hashed passwords, sessions, logout, and the
question every web app eventually has to answer — "what happens when
the user forgets their password?".

The default Laravel answer is an SMTP-relayed password-reset email
with a one-time token. In a hosted SaaS deployment, that flow is
load-bearing — the provider runs SMTP, deliverability is a known cost
of doing business, and the user trusts the email address on file.

beatrax does not run SMTP. The desktop bundle (see
[ADR 0006](0006-nativephp-desktop-shell.md)) ships to end users'
machines; it cannot ship a working SMTP outbound relay. Three options
were on the table:

- **Wire SMTP through the user's own mail provider.** Possible only if
  the user has already wired Gmail OAuth or Microsoft Graph OAuth for
  the EmailScan module — and even then, sending mail is a different
  scope than the read-only receipt scan. The OAuth scope upgrade is
  intrusive, the failure modes are silent (mail "sent" but spam-binned),
  and the user has to be online to use it.
- **Forfeit password reset entirely.** Forgetting the password locks the
  user out permanently. Unacceptable for a tool people use daily and
  forget their password to once a year.
- **Recovery codes plus an owner-resets-partner path plus a
  CLI fallback.** No outbound mail required; the recovery codes are
  generated at account creation and shown once; the owner-as-admin
  path mirrors what every team product offers; the CLI is the
  last-resort escape hatch for the single-user case.

The recovery-codes-plus-CLI shape is the one that ships in v2.0.

## Decision

Three password-reset paths, all SMTP-free, in declining order of "what
the user reaches for first":

1. **Recovery codes.** At account creation (and on demand via
   `php artisan beatrax:rotate-recovery-codes`), the system generates
   ten one-time-use recovery codes, hashes them via the same hasher
   used for passwords, and displays the plaintext codes once for the
   user to print or paste into their password manager. The
   `user_recovery_codes` table holds one row per code; a successful
   reset marks the code consumed via a state transition that the
   `UserRecoveryCodeStateMachine` is the sole mutator of (see
   `Modules/Auth/Internal/Recovery/`).

2. **Owner-resets-partner.** In a multi-user shared install, the owner
   (the user flagged `is_owner=true` on the `users` table) sees a
   "force password change" action against every non-owner user from
   the user-administration surface. The action flips the partner's
   `force_password_change` boolean and signs them out; on next login
   the partner sets a new password from the in-app prompt. This
   covers the v2.0 partner case where the partner has forgotten their
   recovery codes and the owner is around to help.

3. **`php artisan beatrax:reset-password` CLI.** The last-resort path,
   documented in [`runbooks/force-password-reset.md`](../runbooks/force-password-reset.md).
   Runs on the local machine where the SQLite file lives; rewrites the
   target user's `password_hash` and sets `force_password_change=1`.
   Owners use this if they forget their own recovery codes AND
   forget their password AND have nobody else with `is_owner=true` on
   the install.

**SMTP-based password reset (AUTH-22)** is explicitly deferred to v2.1.
The OAuth-scope upgrade plus the deliverability surface plus the
"online to reset" requirement are too much weight for a v2.0 that
already has three working paths.

## Consequences

- **Account creation has one new mandatory ritual.** The user has to
  copy the ten recovery codes somewhere before continuing. The signup
  surface forces an "I have saved these" checkbox; the codes are
  shown once and never displayed again. This matches what every modern
  2FA setup already trains users to do.
- **Recovery codes hash to the same scheme as passwords.** Stolen
  database does not yield usable recovery codes — the same way it does
  not yield usable passwords. The `unique` index on the
  `user_recovery_codes(user_id, code_hash)` pair prevents duplicate
  insertion races.
- **No outbound mail surface to defend.** The shipped bundle exposes
  no SMTP client, no `Mail::send()` calls, no queued mail jobs. The
  `noOutboundMailInShippedBundle` arch invariant enforces this.
- **CLI fallback assumes shell access.** Partners who installed via the
  `.dmg` and never opened a terminal cannot self-rescue if both the
  owner and their recovery codes are unavailable. The
  [`runbooks/force-password-reset.md`](../runbooks/force-password-reset.md)
  procedure walks through the recovery; the README notes the
  expectation explicitly.
- **v2.1 reopens the SMTP question.** When an audit produces evidence
  that the recovery-codes-plus-owner-resets-partner path is failing
  real users, AUTH-22 lands as a follow-on plan: Gmail OAuth scope
  upgrade for outbound mail, deliverability monitoring, dual-path
  reset (recovery code OR email link). Until then, the shipped bundle
  carries no SMTP.

## Alternatives considered

- **SMTP via Gmail OAuth in v2.0.** Rejected on scope grounds — the
  OAuth-scope upgrade plus deliverability plus online-to-reset weight
  was disproportionate to ship alongside the multi-user activation.
  Deferred to v2.1.
- **Security questions (mother's maiden name etc.).** Rejected — known
  weak against social engineering and against the "partner shares the
  install" threat model.
- **Magic link via QR-code-printed-on-installer.** Rejected — would
  have required a separate physical artefact, and the recovery-codes
  flow already provides the same "thing you have" channel.
- **Trust the OS keychain for credential storage.** Rejected for
  cross-platform reasons (macOS Keychain, Windows DPAPI, and Linux
  Secret Service are three different stories) and because the codebase
  already hashes passwords against a single scheme.

## Related

- [ADR 0008 — Multi-user via BelongsToUser](0008-multi-user-belongstouser.md)
  — the multi-user activation this reset story lives inside.
- [`runbooks/force-password-reset.md`](../runbooks/force-password-reset.md)
  — the CLI-fallback procedure.
