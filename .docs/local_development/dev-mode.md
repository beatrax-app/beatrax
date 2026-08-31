# Developer mode

Developer mode is a per-user flag that unlocks a set of in-app debug surfaces — the
artisan command palette, queue inspector, log tailer, failed-jobs viewer, doctor panel,
and the embedded Horizon dashboard when the runtime supports it. It is intentionally
off by default for every freshly-created account; turning it on is a deliberate act for
the operator or maintainer.

## What developer mode exposes

When a user has `is_developer = true`:

- **⌘K command palette** — every artisan command registered in the safe tier becomes
  reachable through the in-app palette. Destructive commands stay gated behind an
  additional confirmation tier.
- **Dev Console pages** — `/dev/queue`, `/dev/logs`, `/dev/failed-jobs`, `/dev/doctor`
  become accessible. Each renders the relevant live signal from the running app.
- **Doctor panel** — `/dev/doctor` runs `beatrax:doctor` and parses its output into a
  pass / warn / fail breakdown.
- **Horizon iframe** — when `BEATRAX_RUNTIME=local` is set (i.e. the local Docker dev
  environment, not the shipped desktop bundle), the embedded Horizon dashboard renders
  under `/dev/horizon`. The shipped bundle does not ship Redis or Horizon, so this
  surface is intentionally absent there.
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

The command is registered by the Auth module (`GrantDevCommand`) and lives in the
destructive tier of the artisan runner — it changes a user's security posture, so it
warrants the same confirmation a password reset would. It takes the username as a
positional argument, and it only ever grants.

## Why developer mode is per-user, not global

Two reasons:

- Partner-shared installs (the v2.0 multi-user shape) need the technical user to have
  the Dev Console without the partner seeing or being able to reach the same surfaces.
  A per-user flag is the minimum mechanism that makes that posture work.
- The middleware that gates Dev Console routes (`EnsureDeveloperMode`) is the security
  boundary the arch tests enforce. A single global flag would make the middleware
  redundant and erode the per-user posture immediately.

## What developer mode does NOT change

- The DB schema. Developer mode does not unlock destructive migrations or hidden
  tables — the schema is what it is for every user.
- The destructive-artisan tier. Even with developer mode on, destructive commands
  (`migrate:fresh`, `db:wipe`, `auth:reset-password`) still require an extra
  confirmation in the runner.
- The OAuth posture. Secrets are still constructor-injected through
  `OAuthSecretsRepository`; the Dev Console renders nothing from those columns.

## Turning developer mode off

The Settings toggle, which is the only way back down: `beatrax:grant-dev` has no
revoking counterpart at the CLI.

The next request from that user no longer renders the Dev Console pages and no longer
shows the Developer submenu in the native menu.
