# Developer mode

Developer mode is a per-user flag that unlocks a set of in-app debug surfaces — the
artisan command palette and runner, the queue inspector, the log tailer, a read-only SQL
panel, the audit log, a system snapshot, the doctor panel, and the embedded Horizon
dashboard when the build carries Horizon at all. It is intentionally off by default for
every freshly-created account; turning it on is a deliberate act for the operator or
maintainer.

On a shipped build the flag is not enough on its own. `DevConsoleBuildGate` is a second
lock, and it answers whether this build lets the console exist at all — see
[the Dev Console on a shipped build](../features/dev-mode/the-console-on-a-shipped-build.md).

## What developer mode exposes

When a user has `is_developer = true`:

- **⌘K command palette** — every artisan command registered in the safe tier becomes
  reachable through the in-app palette. Destructive commands are not in the palette at
  all; they are reached through the triple gate described below.
- **Dev Console pages** — `/dev` (the overview), `/dev/artisan`, `/dev/queue`,
  `/dev/logs`, `/dev/sql`, `/dev/audit`, `/dev/system` and `/dev/doctor` become
  accessible. Each renders the relevant live signal from the running app. There is no
  `/dev/failed-jobs`: failed jobs are the `failed` tab of the queue inspector, at
  `/dev/queue/failed`, alongside `pending` and `batches`.
- **Doctor panel** — `/dev/doctor` runs `beatrax:doctor` and parses its output into a
  pass / warn / fail breakdown.
- **Horizon iframe** — `/dev/horizon` is registered only when `config('app.dev_mode')`
  is true, which `BEATRAX_DEV_MODE` sets, **and** the Horizon service provider class is
  loadable. Horizon is a `require-dev` package, so a `--no-dev` install drops it and the
  route is never declared — a second guard beyond the flag, and the reason a shipped
  bundle has no such surface rather than a shut one. `BEATRAX_RUNTIME` gates nothing:
  `.env.bundled` writes `BEATRAX_RUNTIME=bundle` on the line below `BEATRAX_DEV_MODE`,
  and no PHP in either Composer root reads it.
- **App menu Developer submenu** — the native menu bar in the desktop bundle gains a
  Developer submenu with shortcuts to the same Dev Console pages.

## Turning developer mode on

The supported path is the in-app Settings toggle. From any authenticated page:

1. Open Settings.
2. Find the Developer Mode toggle.
3. Flip it on. The middleware re-evaluates on the next request; the Dev Console pages
   become reachable immediately.

The same flag can also be raised from the CLI when you do not have a working UI session
(e.g. just after a destructive restore):

```sh
php artisan beatrax:grant-dev <username>
```

The command is registered by the Auth module (`GrantDevCommand`). It takes the username
as a positional argument and it only ever grants. It is also in the runner's destructive
tier, because it changes a user's security posture — reaching it from the console costs
all three gates, where reaching it here costs a shell.

## Why developer mode is per-user, not global

Two reasons:

- Partner-shared installs need the technical user to have the Dev Console without the
  partner seeing or being able to reach the same surfaces. The owner/partner model
  ships — the owner adds an account at `/settings/users/new` — so this is a posture the
  product has now, not one it is waiting for, and a per-user flag is the minimum
  mechanism that makes it work.
- The middleware that gates Dev Console routes (`EnsureDeveloperMode`) is the security
  boundary the arch tests enforce. A single global flag would make the middleware
  redundant and erode the per-user posture immediately.

## What developer mode does NOT change

- The DB schema. Developer mode does not unlock destructive migrations or hidden
  tables — the schema is what it is for every user.
- The artisan allow-list. `CommandRegistry` holds two tiers and nothing else reaches a
  process: the safe tier is what the ⌘K palette offers, and the destructive tier —
  `db:restore`, `beatrax:regenerate-recovery-codes`, `beatrax:grant-dev` and
  `beatrax:install` — is reachable only through `DestructiveSpawnController`, past all
  three `TripleGateModal` locks. Anything outside both lists is not gated by an extra
  confirmation, it is absent: `migrate`, `migrate:fresh`, `db:wipe`, `db:seed` and
  `beatrax:reset-password` are never registered, and asking `CommandSpawner` for one
  throws before a process exists.
- The OAuth posture. Secrets are still constructor-injected through
  `OAuthSecretsRepository`; the Dev Console renders nothing from those columns.

## Turning developer mode off

The Settings toggle, which is the only way back down: `beatrax:grant-dev` has no
revoking counterpart at the CLI.

The next request from that user no longer renders the Dev Console pages and no longer
shows the Developer submenu in the native menu.
