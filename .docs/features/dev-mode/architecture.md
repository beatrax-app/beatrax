# `DevMode` — architecture

The `DevMode` module hosts the in-app Dev Console: the developer-only
sub-app under `/dev/*` that lets the owner spawn whitelisted artisan
commands, inspect queues, tail logs, run read-only SQL, walk system
probes, and operate the ⌘K command palette. It owns the
`dev_mode_audit` table that captures every dev action, the
secret-redacting log processor, the OAuth-secret scrub set, and the
worker-heartbeat writer that powers the boot-health probe.

## What this module is for

Once the shipped bundle is on the user's machine, the owner still
needs the operational seam every web app keeps in production: "look at
the logs", "retry that failed job", "back up the database", "what
config value is in effect". The Dev Console packages those operations
into a UI that runs against the LIVE install — no second SSH session,
no separate `tinker`. The console is therefore gated tightly:
`is_developer = true` plus an explicit middleware on every route,
plus a triple-gate modal in front of every destructive command.

The Horizon dashboard is embedded the same way: `/dev/horizon` is an
iframe Livewire page that loads Horizon's own UI behind a frame-
ancestors header, registered only when `app.dev_mode` is true and the
Horizon package is installed. Production-shipped builds with
`--no-dev` lose the dependency, the conditional registration silently
skips, and the sidebar entry disappears.

What the module explicitly does NOT do:

- It never exposes `migrate`, `migrate:rollback`, `db:seed`, or any
  other NEVER-EXPOSED command. The `CommandRegistry` is the
  authoritative allow-list; the `CommandSpawner` whitelists against
  it before constructing a Symfony `Process`. An attempt to spawn an
  unlisted command raises `InvalidArgumentException` before any
  side effect.
- It never logs an unredacted Bearer token, JWT, or OAuth secret.
  The `RedactSecretsProcessor` Monolog tap scrubs every log line; the
  `RedactionExcerptCap` does the same for audit-row excerpts.
- It never bypasses the dev gate. The
  `ensureDeveloperMode` middleware is the single sanctioned guard;
  every `/dev/*` route carries it.

## Module boundary

`Public/` exposes the cross-module surface — enough for the main app
layout to render the ⌘K palette and the sidebar nav-list:

- **Contracts/**
  - `DevCommandRegistry::find($name)`, `all()`, `tier($name)` —
    catalogue of every spawn-allowed command.
  - `NavigationRegistry::entries()` — the canonical nav list both
    the sidebar and the palette consume.
  - `AppActionRegistry::actions()` — the named palette actions
    (`Run import`, `Scan email now`, `Toggle theme`, `Open profile`).
  - `AuditWriter::write($action, $context)` — the single sanctioned
    write path for `dev_mode_audit` rows.
- **DTOs/** — `CommandSpec`, `ArgSpec`, `NavigationEntry`, `AppAction`.
- **Models/** — `Job`, `FailedJob`, `JobBatch` (typed read-only
  models over the framework's queue tables; the queue inspector
  consumes them).

`Internal/` houses the implementation:

- **Internal/CommandRegistry** — concrete `DevCommandRegistry`. The
  hard-coded SAFE + DESTRUCTIVE allow-list.
- **Internal/Process/CommandSpawner** — the single Symfony
  `Process` constructor. Refuses any command not in the registry.
- **Internal/Process/RunRegistry** — the per-run state ledger
  (`pending` → `running` → `complete` / `failed`).
- **Internal/Process/FileTailer** — the streaming log tail.
- **Internal/Audit/SpatieAuditWriter** — concrete `AuditWriter`.
  Routes rows through `spatie/laravel-activitylog` into
  `dev_mode_audit`.
- **Internal/Audit/RedactionExcerptCap** — scrubs OAuth-literals +
  Bearer / JWT patterns out of audit excerpts.
- **Internal/Audit/FinalizeRunAudit** — listener that writes the
  closing audit row when a run completes.
- **Internal/Logging/RedactSecretsProcessor** — Monolog tap that
  scrubs every log line before it lands on disk.
- **Internal/Services/OAuthScrubSet** — singleton holding the
  decrypted-on-demand OAuth secret literals. Busted by
  `BustOAuthScrubSetOnSecretChange` Eloquent observer on every
  `OAuthSecret` save / delete.
- **Internal/Http/Middleware/EnsureDeveloperMode** — the single
  sanctioned `/dev/*` guard. Refuses any non-developer caller with
  404.
- **Internal/Http/Middleware/HorizonFrameAncestors** — the
  `frame-ancestors` header on the Horizon iframe page so the
  embed renders without the CSP rejecting it.
- **Internal/Http/Livewire/** — twelve Livewire pages
  (DevOverviewPage, ArtisanRunnerPage, AuditLogPage,
  LogTailerPage, QueueInspectorPage, HorizonFramePage,
  DoctorPanelPage, SqlPanelPage, SystemSnapshotPage,
  TripleGateModal, CommandPaletteModal, CommandArgPromptModal).
- **Internal/Listeners/** — `LogQueueLifecycle` (logs `JobProcessed`
  + `JobFailed` so `/dev/logs` shows completions),
  `WriteWorkerHeartbeat` (queue-looping closure that bumps the
  heartbeat cache key on every tick), `ResetAdvancedToggleOnLogin`,
  `BustOAuthScrubSetOnSecretChange`.
- **Internal/Navigation/** — `NavigationRegistryImpl`,
  `AppActionRegistryImpl`, `DevSidebarItems` (the dev-shell sidebar
  rendering data).
- **Internal/Console/PruneDevAuditCommand** — `dev:prune-audit`.

The repo-wide arch invariants `noHorizonImportsInShippedBuildCode`
and `noUnsanctionedAuditWriter` are anchored here.

## Key services + events

- `CommandSpawner::spawn($name, $args)` — the single
  Symfony-`Process` constructor. Whitelists the command name against
  `DevCommandRegistry`, validates args against the `ArgSpec`, writes
  the opening audit row, spawns the process, returns the run id.
- `RunRegistry::record($run)` / `find($id)` — per-run state cache
  in the cache store (`Run` rows live in cache, not the DB; the
  closing audit row in `dev_mode_audit` is the durable trace).
- `FileTailer::stream($file)` — yields lines from the live log
  file. The Livewire log tailer consumes it.
- `WriteWorkerHeartbeat::__invoke()` — bumps the
  `queue.worker_heartbeat` cache key with the current
  `Clock::now()`. The boot-health probe reads it.
- `RedactSecretsProcessor::__invoke($record)` — Monolog tap.
  Replaces Bearer tokens, JWT shapes, and every OAuth secret literal
  with `[REDACTED]`. Reads the literals from `OAuthScrubSet` lazily;
  the scrub set is invalidated by the Eloquent observer on every
  `OAuthSecret` change.
- `SpatieAuditWriter::write($action, $context)` — opens an activity
  log row scoped to the dev_mode_audit log name. The current user
  + timestamp + redacted context are part of the canonical row.

The module raises no Public events; the Internal listeners observe
framework events (`JobProcessed`, `JobFailed`, `Login`) and the
Eloquent observer.

## Data flow

The ⌘K command-palette flow:

```
user presses ⌘K from anywhere in the app
  → palette modal mounts via Livewire
  → CommandPaletteModal builds the entry list
       → NavigationRegistry::entries (filtered by is_developer)
       → AppActionRegistry::actions
       → DevCommandRegistry::all (only for developers)
  → user picks one
       → if URL action: window.location to URL
       → if handlerEvent: dispatch browser event ('email-scan.run' etc.)
       → if command: route to /dev/artisan with prefilled name
```

The artisan-runner flow:

```
/dev/artisan
  → ArtisanRunnerPage shows the registry, filters by tier
  → user picks command
       → ArgPromptModal collects ArgSpec values
  → if tier=destructive:
       → TripleGateModal (Advanced toggle + explicit confirm +
                          typed phrase)
  → CommandSpawner::spawn($name, $args)
       → whitelist against CommandRegistry → InvalidArgumentException
                                              if not found
       → AuditWriter::write('command.start', context)
       → Symfony Process::start
       → RunRegistry::record($run)
  → ArtisanRunnerPage subscribes to the run via wire:poll
       → display stdout/stderr tail
  → on completion:
       → FinalizeRunAudit::handle($run)
           → AuditWriter::write('command.complete'|'command.failed',
                                context + exit_code + duration)
```

The queue-inspector flow:

```
/dev/queue
  → QueueInspectorPage reads framework Job + FailedJob + JobBatch
       (typed Public models)
  → recent JobProcessed / JobFailed events visible via /dev/logs
       (LogQueueLifecycle wrote them to the laravel log when they fired)
```

The Horizon iframe flow (dev_mode + Horizon installed only):

```
/dev/horizon
  → HorizonFramePage Livewire SFC
       → renders <iframe src="/horizon"> with frame-ancestors header
            (applied by HorizonFrameAncestors middleware)
```

## Horizon conditional-registration arch invariants

`DevModeServiceProvider::boot()` registers the `/dev/horizon` route only
when `config('app.dev_mode') === true` AND the Horizon package's
`ServiceProvider` class is present (Horizon ships `require-dev`, so a
`--no-dev` production build never has it). The dev_mode flag is read
through an injected `Config\Repository`, not the `config()` global
helper, keeping the provider facade-free per the DI-only rule.

That registration walks two arch invariants:

- `noHorizonImportsInShippedBuildCode` forbids any non-stripped
  `Laravel\Horizon\` symbol outside `app/Providers/HorizonServiceProvider.php`.
  The arch test strips `class_exists(\Laravel\Horizon\...)` arguments
  from its regex sweep first, so an inline FQCN inside `class_exists()`
  is legal.
- Pint's `fully_qualified_strict_types` fixer would otherwise hoist an
  inline `Laravel\Horizon\…::class` into a top-of-file `use` line,
  breaking that arch test. The hoist is suppressed because the
  imported short name `HorizonServiceProvider` is already in scope —
  Pint refuses to introduce an ambiguity. The matching pattern lives in
  `bootstrap/providers.php`: the local `App\Providers\HorizonServiceProvider`
  is imported at the top of `DevModeServiceProvider` purely as a
  name-conflict shim, and used in the route-registration body, so the
  import is not unused.
