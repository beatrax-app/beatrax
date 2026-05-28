# ADR 0004 — Local-only hosting; no cloud, telemetry, or remote logging

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-32

## Context

The data beatrax processes — full bank-account history, credit-card
statements, email receipts, the funding chains between every account —
is the single most sensitive class of personal data the average user
holds outside of their medical records. The category includes who they
pay, how much, when, where, and what for. It maps to relationships,
locations, health conditions, political affiliations, and
vulnerabilities.

For that class of data, the default posture every other personal-finance
product takes — "we collect it, we store it on our servers, we promise
not to misuse it" — was never an option. The privacy story has to be
provable, not promised.

Three failure modes that any cloud component would introduce, even a
seemingly innocent one, decided the posture:

- **A telemetry SDK that pings home with "anonymous" usage data**
  betrays which features a user uses, which inevitably correlates to
  which life events they are tracking.
- **A remote error reporter** (Sentry, Bugsnag) ships stack traces that
  include local variable contents — bank balances, merchant names, IBAN
  fragments. Even with PII scrubbing, the residual leak is
  unacceptable.
- **A cloud sync option** (even "encrypted at rest") creates a
  high-value target the maintainer becomes legally responsible for
  protecting, and changes the user's threat model from "my laptop" to
  "my laptop plus a third-party server".

The decision is to take cloud off the table entirely, not to take it
"off by default".

## Decision

beatrax is a local-only application. Specifically:

- **All data is stored on the user's machine** — SQLite database in the
  per-OS user-data directory; backups in `storage/app/backups/` on the
  same machine; OAuth tokens in `storage/app/secrets/` at chmod 600 on
  the same machine.
- **No telemetry.** No metrics SDK, no analytics, no "feature usage"
  pings, no crash reporter that contacts an external service.
- **No remote logging.** Logs land on disk in `storage/logs/`. The
  in-app log tailer under `/dev/logs` reads them locally; nothing is
  shipped off the machine.
- **No cloud sync.** Multi-user partner-sharing in v2.0 uses a shared
  SQLite database on one shared machine, not a cloud-hosted database.
- **One controlled outbound exception: auto-update.** The Electron
  auto-updater contacts `api.github.com` to check for new releases. The
  release manifest is Ed25519-signed; the installer payload is verified
  by SHA-512 against the manifest before unpacking. No other outbound
  network call exists in the shipped bundle.

The OAuth dance for Gmail API and Microsoft Graph runs on the user's
machine — the loopback `http://127.0.0.1:PORT/oauth/callback/{provider}`
redirect URI keeps the callback local. The access tokens stay in the
local secrets file. Subsequent API calls go directly from the user's
machine to the provider; the maintainer's servers are never in the
loop.

## Consequences

- **No "support" channel for crash reports.** When a user hits a bug,
  they have to share their logs by hand. The `/dev/logs` page makes
  this practical (one-click copy of the relevant log file); the
  `Modules/Core/Console/diederik:doctor` command bundles diagnostics for
  manual sharing.
- **Release verification is the user's responsibility.** Because the app
  doesn't phone home with crash data, the maintainer cannot detect a
  bad release in the field. The release workflow compensates by running
  a full integration smoke test against each platform installer before
  publishing.
- **CI / release pipeline enforcement.** The release workflow contains
  an arch test that fails if any production-code path imports a
  telemetry SDK (Sentry, Bugsnag) or a known analytics package. The
  outbound network surface is small enough to enumerate; the test
  enumerates it.
- **Cross-machine sync is a non-goal.** Users who want their data on a
  second device run a manual backup-and-restore via `php artisan
  db:backup` and `php artisan db:restore`. This is intentional.

## Alternatives considered

- **"Cloud sync, opt-in only"** — rejected. Opt-in becomes default-on
  for any sufficiently-pushed feature, and the maintenance cost of
  running cloud infrastructure for a single-user product is
  disproportionate to the benefit.
- **Telemetry "with the option to disable"** — rejected for the same
  reason. The presence of the SDK creates the data leak whether or not
  the user opts in.
- **Remote error reporter with PII scrubbing** — rejected. The residual
  risk after scrubbing is too high for the data class.

## Related

- [ADR 0003 — Hippocratic 3.0 license](0003-hippocratic-3-0-license.md)
  — codifies the privacy posture in the license.
- [ADR 0006 — NativePHP desktop shell](0006-nativephp-desktop-shell.md)
  — the desktop shell that ships beatrax as a local app rather than a
  hosted web service.
- [`legal/data-retention.md`](../legal/data-retention.md) — describes
  the local storage layout and the user-controlled export and delete
  paths.
