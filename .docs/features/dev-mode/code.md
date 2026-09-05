# `DevMode` — code

The file-level map for the module.

## Directory layout

```
Modules/DevMode/
├── Public/
│   ├── Contracts/
│   │   ├── AppActionRegistry.php
│   │   ├── AuditWriter.php
│   │   ├── DevCommandRegistry.php
│   │   └── NavigationRegistry.php
│   ├── Dto/
│   │   ├── AppAction.php
│   │   ├── ArgSpec.php
│   │   ├── CommandSpec.php
│   │   └── NavigationEntry.php
│   └── Models/
│       └── Job.php
├── Internal/
│   ├── CommandRegistry.php
│   ├── Process/
│   │   ├── CommandSpawner.php
│   │   ├── CommandArgValidator.php
│   │   ├── RunRegistry.php
│   │   ├── RunRecord.php
│   │   ├── RunExitCodeFile.php
│   │   ├── ProcessLiveness.php
│   │   └── FileTailer.php
│   ├── Audit/
│   │   ├── SpatieAuditWriter.php
│   │   ├── RedactionExcerptCap.php
│   │   └── FinalizeRunAudit.php
│   ├── Logging/
│   │   ├── ActiveLogFile.php
│   │   └── RedactSecretsProcessor.php
│   ├── Services/
│   │   └── OAuthScrubSet.php
│   ├── Listeners/
│   │   ├── LogQueueLifecycle.php
│   │   ├── WriteWorkerHeartbeat.php
│   │   ├── ResetAdvancedToggleOnLogin.php
│   │   └── BustOAuthScrubSetOnSecretChange.php
│   ├── Navigation/
│   │   ├── NavigationRegistryImpl.php
│   │   ├── AppActionRegistryImpl.php
│   │   └── DevSidebarItems.php
│   ├── Http/
│   │   ├── Middleware/
│   │   │   ├── EnsureDeveloperMode.php
│   │   │   └── HorizonFrameAncestors.php
│   │   ├── Controllers/
│   │   └── Livewire/
│   │       ├── DevOverviewPage.php
│   │       ├── ArtisanRunnerPage.php
│   │       ├── AuditLogPage.php
│   │       ├── LogTailerPage.php
│   │       ├── QueueInspectorPage.php
│   │       ├── HorizonFramePage.php
│   │       ├── DoctorPanelPage.php
│   │       ├── SqlPanelPage.php
│   │       ├── SystemSnapshotPage.php
│   │       ├── TripleGateModal.php
│   │       ├── CommandPaletteModal.php
│   │       └── CommandArgPromptModal.php
│   ├── Console/
│   │   └── PruneDevAuditCommand.php
│   ├── Doctor/         (probe extensions)
│   ├── Enums/
│   │   ├── ArgType.php
│   │   ├── AuditEvent.php
│   │   └── CommandTier.php
│   ├── Queue/
│   ├── Registries/
│   ├── Sql/            (SELECT-only query parser)
│   ├── Support/
│   │   └── DevModeSession.php
│   └── System/         (snapshot helpers)
├── Models/
│   └── DevModeAudit.php
├── Database/
│   └── Migrations/
│       └── 2026_05_24_000001_create_dev_mode_audit_table.php
├── Routes/
│   └── web.php
├── Resources/views/
├── Providers/
│   └── DevModeServiceProvider.php
└── tests/
    └── Feature/
```

## Public API

- **Contracts/**
  - `DevCommandRegistry::safe(): list<CommandSpec>`,
    `destructive(): list<CommandSpec>`,
    `find(string $name): CommandSpec` (throws on an unregistered
    name).
  - `NavigationRegistry::all(): list<NavigationEntry>`.
  - `AppActionRegistry::all(): list<AppAction>`.
  - `AuditWriter::recordCommandRun(CommandRunAudit $run): void`,
    `finalizeCommandRun(...): bool`,
    `recordDestructiveQueueAction(...): void`,
    `recordSelectQuery(...): void`.
- **DTOs/**
  - `CommandSpec` — `(name, label, tier, argsSchema, description)`,
    where `tier` is a `CommandTier`.
  - `ArgSpec` — `(name, label, type, rules, placeholder, helpText,
    options)`, where `type` is an `ArgType`.
  - `CommandRunAudit` — the one audit row a run writes, carrying the
    same `CommandTier`.
  - `NavigationEntry` — `(id, label, hint, icon, url, keywords)`.
  - `AppAction` — `(id, label, hint, icon, handlerEvent, url,
    keywords)`.
- **Models/**
  - `Job` — read-only model over the framework's `jobs` table.
    `failed_jobs` and `job_batches` carry no model of their own; they
    are read through the query builder.

## Internal services

- `Internal/CommandRegistry` — concrete `DevCommandRegistry`. The
  hard-coded SAFE + DESTRUCTIVE allow-list (13 commands: 9 SAFE,
  4 DESTRUCTIVE). NEVER-EXPOSED commands (`migrate`, `migrate:fresh`,
  `migrate:rollback`, `db:wipe`, `db:seed`) are deliberately absent,
  and `beatrax:reset-password` with them — it refuses a
  non-interactive run by design, which is the shell-access gate
  ADR-0010 relies on.
- `Internal/Process/CommandSpawner::start(string $command, array
  $args, int $callerUserId, CommandTier $tier): string` — the single
  sanctioned Symfony-`Process` constructor. Throws
  `InvalidArgumentException` for any unrecognised command. The
  detached child runs inside a watcher subshell that waits on it and
  writes its exit code to the `RunExitCodeFile` sidecar; the pid the
  spawner publishes is still artisan's own.
- `Internal/Process/CommandArgValidator::assertValid(CommandSpec
  $spec, array $args): void` — runs the declared `ArgSpec::$rules`
  against the args a spawn was asked for. Every spawn entry point
  (both HTTP controllers, the arg-prompt modal, the runner page)
  goes through it, so none of them can be the surface that skipped
  the rules.
- `Internal/Process/RunExitCodeFile::pathFor(string $outPath)` /
  `read(string $outPath): ?int` — the `<run>.out.exit` sidecar. Null
  means no answer (the watcher was killed, or the run predates the
  sidecar), never "exited cleanly".
- `Internal/Process/RunRegistry` — cache-backed per-run state.
- `Internal/Process/FileTailer::tailOnce(string $path, int
  $fromOffset): array{chunk: string, newOffset: int}` — one bounded
  64 KiB read from an offset, polled rather than streamed.
- `Internal/Audit/SpatieAuditWriter` — concrete `AuditWriter`, bound
  unconditionally: there is no null-object fallback.
- `Internal/Enums/CommandTier` — `Safe` / `Destructive`.
  `reachesThePalette()` is the reachability predicate every spawn
  path asks; `fromStored()` resolves a persisted tier and falls back
  to `Safe`.
- `Internal/Support/DevModeSession` — `ADVANCED_KEY` and
  `ADVANCED_SEEN_KEY`, the two session keys the triple gate's second
  lock is held in.
- `Internal/Audit/RedactionExcerptCap::apply(string $text, int
  $maxBytes = self::DEFAULT_MAX_BYTES): string` — scrubs OAuth
  literals and Bearer / JWT patterns, then caps at 8 KiB.
- `Internal/Audit/FinalizeRunAudit::__invoke(string $runId, ?int
  $exitCode, bool $cancelled)` — merges the closing state onto the
  spawner's eager row, falling back to an append-only write so the
  fact of the run is never lost. A null `$exitCode` (what both
  callers have, since a vanished pid is all either of them saw) is
  resolved from the `RunExitCodeFile` sidecar.
- `Internal/Logging/ActiveLogFile::path()` — the file the configured
  log channel writes, and the single source the tailer, the stats
  reader and the `/dev` console pane all resolve through.
- `Internal/Logging/RedactSecretsProcessor::__invoke($record)` —
  Monolog tap. Lazy-rebuilds the OAuth pattern from
  `OAuthScrubSet::compiledPattern()` on demand.
- `Internal/Services/OAuthScrubSet` — singleton. Lazily decrypts
  `oauth_secrets.client_secret` + the `access_token` /
  `refresh_token` fields of `tokens_blob` on first `all()` /
  `compiledPattern()` call. A failed load is not cached, so the set
  recovers on the next call once the cause clears.
- `Internal/Http/Middleware/EnsureDeveloperMode::handle($req, $next)`
  — refuses with 404 when the build refuses the console
  (`DevConsoleBuildGate::permits()`) or the caller is not a
  developer. That gate is `Modules\Core\Public\Services` — `Shell`
  and `Desktop` ask it too, and neither may depend on this module.
- `Internal/Http/Middleware/HorizonFrameAncestors::handle($req, $next)`
  — appends the `frame-ancestors` CSP directive so the iframe
  renders.
- `Internal/Http/Livewire/` — the twelve console pages enumerated
  in the directory tree.
- `Internal/Listeners/LogQueueLifecycle::processed($event) /
  failed($event)` — writes a structured log line for every
  `JobProcessed` / `JobFailed`.
- `Internal/Listeners/WriteWorkerHeartbeat::__invoke()` —
  cache-bumps the heartbeat key on every queue tick.
- `Internal/Listeners/ResetAdvancedToggleOnLogin::handle($event)` —
  resets the artisan-runner Advanced toggle to OFF on every Login.
- `Internal/Listeners/BustOAuthScrubSetOnSecretChange` — Eloquent
  observer attached to `OAuthSecret`; busts `OAuthScrubSet` on
  every save / delete.
- `Internal/Navigation/DevSidebarItems` — the dev-shell sidebar
  rendering data (id, label, icon, route-name). The view gates
  `nav-disabled` on `Router::has(...)` at render time so runtime
  truth wins over the constant.
- `Internal/Console/PruneDevAuditCommand` —
  `beatrax:prune-dev-audit` command for retention-policy
  enforcement on the `dev_mode_audit` table.

## Models + migrations

- `Models/DevModeAudit` — Eloquent model over
  spatie/laravel-activitylog's `dev_mode_audit` activity-log
  table. The table is created by
  `2026_05_24_000001_create_dev_mode_audit_table.php`. Spatie's
  activity-log columns plus a few project-specific extras (run id,
  exit code, duration).

## Provider wiring

`DevModeServiceProvider::register()`:

- Binds `DevCommandRegistry` → `CommandRegistry` with the
  hard-coded SAFE + DESTRUCTIVE roster.
- Singletons every Internal service via factory closures so the
  dependency graph (`CommandSpawner` ← `RunRegistry` + `Clock` +
  `DevCommandRegistry` + `AuditWriter`) is explicit.
- Singletons `OAuthScrubSet`, `RedactionExcerptCap`,
  `RedactSecretsProcessor` so the lazy OAuth-secret decryption
  cache survives across every channel-emit / audit-row call.
- Builds `NavigationRegistryImpl` + `AppActionRegistryImpl` through
  factory closures that resolve route names via the `UrlGenerator`;
  missing routes drop out cleanly (so the dev-shell still works on
  a partial-test boot).

`DevModeServiceProvider::boot()`:

- Aliases the `ensureDeveloperMode` middleware.
- Loads migrations, routes, views (all file-/dir-existence guarded).
- Conditionally registers the Horizon iframe route when
  `config('app.dev_mode') === true` AND
  `\Laravel\Horizon\HorizonServiceProvider` exists. Both gates are
  load-bearing: production `--no-dev` builds skip the binding.
- Registers the twelve Livewire components under the `dev.*`
  namespace.
- Subscribes `WriteWorkerHeartbeat` to `QueueManager::looping(...)`
  using the closure form (the event-listener form does not fire
  reliably under `queue:work`).
- Subscribes `ResetAdvancedToggleOnLogin` to `Login`.
- Subscribes `LogQueueLifecycle::processed` /
  `LogQueueLifecycle::failed` to `JobProcessed` / `JobFailed`.
- Attaches `BustOAuthScrubSetOnSecretChange` as an Eloquent
  observer on `OAuthSecret`.
- Registers the `beatrax:prune-dev-audit` console command.
