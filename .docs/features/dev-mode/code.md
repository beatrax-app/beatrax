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
│   │   ├── RunRegistry.php
│   │   └── FileTailer.php
│   ├── Audit/
│   │   ├── SpatieAuditWriter.php
│   │   ├── RedactionExcerptCap.php
│   │   └── FinalizeRunAudit.php
│   ├── Logging/
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
│   ├── Queue/
│   ├── Registries/
│   ├── Sql/            (SELECT-only query parser)
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
  - `DevCommandRegistry::find(string $name): ?CommandSpec`,
    `all(): list<CommandSpec>`, `tier(string $name): ?string`.
  - `NavigationRegistry::entries(): list<NavigationEntry>`.
  - `AppActionRegistry::actions(): list<AppAction>`.
  - `AuditWriter::write(string $action, array $context): void`.
- **DTOs/**
  - `CommandSpec` — `(name, label, tier, argsSchema, description)`.
  - `ArgSpec` — `(name, label, type, rules, placeholder, helpText,
    options)`.
  - `NavigationEntry` — `(id, label, hint, icon, url, keywords)`.
  - `AppAction` — `(id, label, hint, icon, handlerEvent, url,
    keywords)`.
- **Models/**
  - `Job` — read-only model over the framework's `jobs` table.
    `failed_jobs` and `job_batches` are read through the query
    builder; the typed models over them went unused and were removed.

## Internal services

- `Internal/CommandRegistry` — concrete `DevCommandRegistry`. The
  hard-coded SAFE + DESTRUCTIVE allow-list (~14 commands).
  NEVER-EXPOSED commands (`migrate`, `migrate:rollback`,
  `db:seed`) are deliberately absent.
- `Internal/Process/CommandSpawner::spawn(string $name, array
  $args): string` — the single sanctioned Symfony-`Process`
  constructor. Throws `InvalidArgumentException` for any
  unrecognised command.
- `Internal/Process/RunRegistry` — cache-backed per-run state.
- `Internal/Process/FileTailer::stream(string $path): iterable<string>`
  — yields log lines.
- `Internal/Audit/SpatieAuditWriter` — concrete `AuditWriter`.
- `Internal/Audit/RedactionExcerptCap::cap(string $excerpt): string`
  — scrubs OAuth literals and Bearer / JWT patterns.
- `Internal/Audit/FinalizeRunAudit::handle($run)` — writes the
  closing audit row.
- `Internal/Logging/RedactSecretsProcessor::__invoke($record)` —
  Monolog tap. Lazy-rebuilds the OAuth pattern from
  `OAuthScrubSet::compiledPattern()` on demand.
- `Internal/Services/OAuthScrubSet` — singleton. Lazily decrypts
  `oauth_secrets.client_secret` + every string in `tokens_blob` on
  first `all()` / `compiledPattern()` call.
- `Internal/Http/Middleware/EnsureDeveloperMode::handle($req, $next)`
  — refuses non-developer callers with 404.
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
- `Internal/Console/PruneDevAuditCommand` — `dev:prune-audit`
  command for retention-policy enforcement on the
  `dev_mode_audit` table.

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
- Registers the `dev:prune-audit` console command.
