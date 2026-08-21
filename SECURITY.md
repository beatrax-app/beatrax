# Security Policy

Beatrax handles a person's complete banking history on their own
machine. That class of data carries real consequences if something goes
wrong, so we take security reports seriously and want them routed
through a private channel that lets us patch before the issue is
public.

## Reporting a vulnerability

Use GitHub's **Private vulnerability reporting** feature, not the
public Issues tab:

1. Go to the repository on GitHub.
2. Click the **Security** tab.
3. Click **Report a vulnerability**.
4. Fill in the form with as much detail as you can — reproduction steps,
   affected versions, and impact analysis are all helpful.

The report stays private between you and the maintainers until a fix
ships and we publish a coordinated disclosure.

If for some reason private vulnerability reporting is disabled or
inaccessible to you, do not open a public issue with the details. Reach
out through a private channel first and we'll re-enable the reporting
flow.

## Scope

**In scope:**

- The Beatrax application code in this repository.
- The bundled PHP dependencies declared in `composer.json` (Laravel,
  Livewire, Flux, genkgo/camt, brick/money, etc.) when the vulnerability
  is reachable through Beatrax's specific usage.
- The bundled Electron/NativePHP shell layer in the released
  installers.
- The auto-update path (Ed25519 manifest signing + SHA-512 binary
  verification).
- Local data-at-rest assumptions (SQLite database file, OAuth-token
  encryption at rest).

**Out of scope:**

- Vulnerabilities in third-party services Beatrax integrates with
  (Gmail API, Microsoft Graph, your OS) unless they are triggered
  exclusively by a flaw in Beatrax's own handling.
- Operating-system-level security on the user's machine. Beatrax assumes
  the host OS is uncompromised; OS-level compromise is outside what a
  user-space application can defend against.
- Social engineering of the maintainer, GitHub itself, or any
  GitHub-managed infrastructure.
- Issues that require a user to actively grant Beatrax destructive
  permissions on their own machine.
- Theoretical risks that have no demonstrable reproduction.

## Safe harbor

Good-faith security research is welcome. If you act in good faith — you
report through the private channel, you don't exploit beyond what's
needed to demonstrate the issue, you don't access data that isn't
yours, and you don't disclose publicly before the coordinated date —
the maintainers will not pursue legal action.

Coordinated disclosure timeline: by default, **90 days from
acknowledgment to public disclosure**. If we need more time to ship a
fix safely, we'll ask. If you need to disclose sooner because of an
ongoing exploit in the wild, tell us and we'll work out an accelerated
timeline together.

## Response timeline

| Stage | Target |
|-------|--------|
| Acknowledgment of report | within 7 days |
| Triage decision (in scope, severity, planned fix window) | within 14 days |
| Patch ships or detailed status update | within 60 days |
| Public disclosure (coordinated with reporter) | 90 days from acknowledgment, unless extended |

These are targets, not guarantees — Beatrax is maintained by a small
team in their spare time. If something slips, we'll tell you why.

## Credit

Reporters who follow this policy and want public credit will be named
in the release notes for the patched version (or in a security
advisory). Reporters who prefer anonymity will be respected.
