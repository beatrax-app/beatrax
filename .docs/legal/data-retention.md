# Data retention

beatrax is a local-only application. Every byte of user data lives on the user's own
machine, in a single SQLite file plus a small number of supporting files on the same
filesystem. Nothing is sent to a remote server by the app itself.

This document spells out the data-handling contract that statement implies: what is
stored, where it lives, how long it is kept, and how a user exports or deletes it.

## What is stored

The SQLite database holds:

- **Transactions** — every parsed row from every ingested ASN CSV, CAMT.053, or MT940
  export; every ICS PDF statement; every PayPal CSV; every email receipt that matched
  a configured matcher.
- **Source artefacts** — the original CSV / PDF / `.eml` files the user uploaded or
  the email-scan pipeline pulled in, stored under `storage/app/imports/`.
- **OAuth secrets** — provider tokens for Gmail and Microsoft Graph, kept under
  `storage/app/secrets/` with `chmod 600`, never in the database.
- **User accounts** — username, argon2id password hash, recovery code hashes, the
  `is_developer` flag, and the per-user UI preferences (theme, currency, etc.).
- **Derived state** — chain resolution links, recurring detections, drift alerts,
  forecast snapshots, system alerts.

Nothing is stored elsewhere. There is no cloud database, no analytics endpoint, no
crash reporter, no telemetry pipeline.

## Where it lives

| Context | Path |
|---|---|
| Development (Herd) | `database/database.sqlite` and `storage/app/` inside the project directory |
| macOS (shipped bundle) | `~/Library/Application Support/beatrax/database.sqlite` and the sibling `storage/` directory |
| Windows (shipped bundle) | `%APPDATA%\beatrax\database.sqlite` and the sibling `storage` directory |
| Linux (shipped bundle) | `~/.config/beatrax/database.sqlite` and the sibling `storage` directory |

The exact paths a running instance resolved are also visible inside the app under
**Help → Data locations**, with copy-to-clipboard buttons for each path.

The shipped bundle never writes inside its own install directory. Reinstalling or
auto-updating the app never touches user data.

## How long data is kept

Indefinitely, by default. The product's value proposition — multi-year subscription
drift analysis, cross-account chain reconstruction, historical category trends — depends
on the full history being available. No automated retention job prunes ledger rows.

Two narrowly-scoped retention rules apply to operational artefacts, not to user data:

- **Backups** under `storage/app/backups/` are pruned to the seven most recent daily
  files plus the four most recent Sunday-dated snapshots. The owner can keep more by
  copying them out of the directory.
- **Failed-jobs** rows are subject to manual pruning via `beatrax:failed-jobs prune
  --older-than=30d`. Nothing prunes them automatically.

System alerts, log entries, and audit rows are kept indefinitely.

## How a user exports their data

Two supported paths:

- **A backup file**. `php artisan db:backup` produces a self-contained `.sqlite` file
  under `storage/app/backups/` that any SQLite client can open. The file is portable
  across machines and across the development / shipped contexts as long as both run a
  compatible SQLite version. Source artefacts under `storage/app/imports/` are not in
  the SQLite file — copy that directory alongside if a full archive is wanted.
- **A direct file copy** of `database.sqlite` after stopping the app (`php artisan
  down`) and grabbing the `.sqlite`, `.sqlite-wal`, and `.sqlite-shm` files as a
  unit. The backup path is preferred because it produces a consistent snapshot
  without needing maintenance mode.

The Dev Console's data-locations help page provides a single "Export everything" CTA
that bundles both the latest backup and the imports directory into a single archive,
for users who want a one-click export.

## How a user deletes their data

The only mechanism is to delete the files. There is no in-app "wipe my account" button,
because the user owns the filesystem the data lives on and that filesystem is the
authoritative source.

To fully wipe a development install:

```sh
php artisan down                    # stop the app
rm -f database/database.sqlite      # the database itself
rm -f database/database.sqlite-wal database/database.sqlite-shm
rm -rf storage/app/imports          # original source artefacts
rm -rf storage/app/backups          # backup snapshots
rm -rf storage/app/secrets          # OAuth tokens
php artisan up
```

For the shipped bundle the equivalent is to delete the user-data directory listed
above. Uninstalling the application (drag to Trash, `Add or Remove Programs`, package
manager removal) does **not** delete the user-data directory — that is intentional, so
an accidental uninstall does not destroy a multi-year history. Users who want full
removal must delete the user-data directory explicitly.

## What is shared with third parties

Nothing, by the app itself. Two narrow exceptions exist at the user's explicit
request:

- **Gmail or Microsoft Graph requests during email scanning.** The app contacts
  Google's or Microsoft's API endpoints with the user's OAuth token to fetch new
  messages. Tokens and message bodies never reach any other party.
- **GitHub releases endpoint for auto-update polling.** The shipped app contacts
  `api.github.com` every four hours to check for a new version. The request carries no
  user-identifying data beyond the standard User-Agent and IP.

If either of these flows is undesirable, the user can disable email scanning under
Settings, and can turn auto-update off via the same surface. With both off, the app
makes no outbound network calls.

For the in-app surface that exposes all of the above interactively, see
**Help → Data locations**.
