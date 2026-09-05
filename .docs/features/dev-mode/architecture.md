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

On a build that shipped, `is_developer` is not enough on its own — it
is a column in the user's own database, set for the first account that
signed up. `DevConsoleBuildGate` adds a second lock the account cannot
turn: a launch-environment flag on desktop, a debuggable build on a
phone. It lives in `Core` because the sidebar, the dashboard toast and
the native menu bar all have to ask it and none of them may depend on
this module. Development builds are untouched. The rule, the reason the
desktop flag is an environment variable rather than a launcher switch,
and the honest gap in the mobile signal are in
[the console on a shipped build](the-console-on-a-shipped-build.md).

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
  every `/dev/*` route carries it. The two components the layout
  mounts on every page — the ⌘K palette and the command-argument
  prompt — are not behind a route, so each restates both halves of
  that guard for itself.

## Module boundary

`Public/` exposes the cross-module surface — enough for the main app
layout to render the ⌘K palette and the sidebar nav-list:

- **Contracts/**
  - `DevCommandRegistry::safe()`, `destructive()`, `find($name)` —
    catalogue of every spawn-allowed command, partitioned by
    `CommandTier`. `find()` throws `InvalidArgumentException` rather
    than returning null, so an unregistered name never reaches a
    caller as a value.
  - `NavigationRegistry::all()` — the canonical nav list both
    the sidebar and the palette consume.
  - `AppActionRegistry::all()` — the named palette actions
    (`Run import`, `Scan email now`, `Open profile`, `Toggle theme`).
    Both contracts name the accessor `all()` and both have a Null
    implementation for the non-dev build, so the layout can resolve
    them unconditionally.
  - `AuditWriter::recordCommandRun()`, `finalizeCommandRun()`,
    `recordDestructiveQueueAction()`, `recordSelectQuery()` — the
    single sanctioned write path for `dev_mode_audit` rows.
- **DTOs/** — `CommandSpec`, `ArgSpec`, `NavigationEntry`, `AppAction`.
  `CommandSpec::$tier` is a `CommandTier` and `ArgSpec::$type` an
  `ArgType`, both from `Internal/Enums/` — the tier is a security
  gate, so it is a closed set rather than a string a typo can widen.
- **Models/** — `Job` (a typed read-only model over the framework's
  `jobs` table).

`Internal/` houses the implementation:

- **Internal/CommandRegistry** — concrete `DevCommandRegistry`. The
  hard-coded SAFE + DESTRUCTIVE allow-list.
- **Internal/Process/CommandSpawner** — the single Symfony `Process`
  constructor, spawning a whitelisted artisan command via a spawn-then-
  tail architecture: the HTTP request calling `start()` returns within
  milliseconds while the artisan child runs detached and writes
  stdout/stderr into a per-run tmp file the SSE stream controller
  tails. The spawn step: generates a UUID `run_id`; computes
  `outPath = storage/app/dev_mode/runs/{runId}.out` via
  `UserDataPathService::appPath()` so a NativePHP retarget is honoured;
  ensures the parent directory exists at mode 0700 (developer-only —
  the audit pipeline copies redacted tmp-file contents into the audit
  log, but the raw file stays restrictive regardless); resolves the
  `CommandSpec` via `DevCommandRegistry::find()` so an off-whitelist
  name throws `InvalidArgumentException` BEFORE a `Process` is
  constructed (NEVER-EXPOSED commands like `migrate` never reach the
  shell); builds a bash invocation that escapes every component via
  `escapeshellarg`, redirects stdout+stderr into the tmp file, detaches
  with `&`, and prints `$!` so the parent captures the child PID (a
  bash wrapper is required because Symfony `Process`'s built-in
  `start()` loses the PID under shell-redirect detach); stores the run
  in `RunRegistry`; and returns the `run_id` immediately since the
  child is detached. Injection resistance is three guards deep: the
  command name comes from `DevCommandRegistry::find()` (rejecting
  arbitrary names before assembly), every arg value is
  `escapeshellarg`'d, and the controllers validate every arg through
  Laravel's `validate()` against `ArgSpec::$rules` before this class is
  ever reached.
- **Internal/Process/RunRegistry** — the per-run state ledger
  (`pending` → `running` → `complete` / `failed`).
- **Internal/Process/FileTailer** — the streaming log tail.
- **Internal/Audit/SpatieAuditWriter** — concrete `AuditWriter` that
  routes every Dev Console audit row through `spatie/laravel-activitylog`
  ^5.0 into the `dev_mode_audit` table. Constructor DI on `CurrentUser`
  (the causer for `causedBy()`), `Clock` (present-day default for a null
  `finishedAt`), `RedactionExcerptCap` (scrub + byte-cap every excerpt
  landing in `properties`), and `ActivityLogger` (Spatie's bindable
  logger — the DI-only rule forbids the `activity()` global helper).
  Every `recordCommandRun()` write has a fixed row shape:
  `log_name = 'dev_mode'`; `description = AuditEvent::{case}->value`
  (enum-locked taxonomy, never a free-form string); `causer` = the
  authenticated developer or `callerUserId`; `properties` = `{command,
  args, tier, exit_code, stdout_excerpt, error_excerpt, started_at,
  finished_at, cancelled}`.
- **Internal/Audit/RedactionExcerptCap** — scrubs OAuth-literals +
  Bearer / JWT patterns out of audit excerpts.
- **Internal/Audit/FinalizeRunAudit** — the hook `SettleFinishedRun`
  invokes on the SSE `done` branch: reads the per-run tmp file, caps +
  redacts it, and writes the closing audit row via `AuditWriter`
  (`SpatieAuditWriter` at runtime). That action marks the run done in
  `RunRegistry` and decides which exit code is authoritative; this hook
  is what actually emits the audit-trail row, so every SAFE-tier run
  that exits cleanly leaves a record. Nothing it does may propagate: it
  runs inside a live stream, so a failed audit write is logged rather
  than thrown. DESTRUCTIVE runs flow through `DestructiveSpawnController`
  but their stream still terminates through the same SSE controller
  path, so this hook fires the same way — the per-run tmp file shape
  is tier-agnostic. The spawner uses a single tmp file with
  `> file 2>&1` redirection, so stdout and stderr are merged on disk;
  this hook treats the entire content as `stdout_excerpt` and leaves
  `error_excerpt` empty; splitting them would require separate tmp
  files.
- **Internal/Actions/** — `SpawnDevCommand` (tier allow-list, arg
  validation, spawn, and the `RunRecord` that comes back),
  `CancelDevCommandRun` (SIGTERM, the grace window, SIGKILL),
  `SettleFinishedRun` (what a gone PID means), `ReadLogTail` and
  `ReadLogContextWindow` (the log reads behind `/dev/logs/*`). Every
  `/dev` route is a thin entry point in front of one of these
  ([a controller hands the work to an action](../../conventions/a-controller-hands-the-work-to-an-action.md));
  the SSE pump below is the deliberate exception, because a frame and
  its flush ARE the response.
- **Internal/Http/Controllers/ArtisanStreamController** — `GET
  /dev/artisan/stream/{runId}`, the SSE tail of one run's stdout.
  Resolves the cached `RunRecord` via `RunRegistry::find()` and tails
  the per-run tmp file with the shared `FileTailer` primitive: each
  tick does `clearstatcache` + `fseek` + `fread` up to 65 536 bytes,
  emits `id: <offset>\ndata: {"line":"..."}\n\n` per chunk, and checks
  `ProcessLiveness::isAlive()` — when the process is gone it emits a
  terminal `event: done\ndata: {"exit":...}\n\n` and breaks.
  `usleep(150_000)` between ticks (~6.7 ticks/s). The browser's
  EventSource auto-reconnects with `Last-Event-ID` equal to the last
  delivered offset (a `?from=` query param is the manual-test/run-card
  fallback); a reconnect at the last-seen byte sees ONLY lines emitted
  after that point. Cross-user inspection rejects with 403 when the
  requesting developer is not the original spawner (a forged runId
  produces 404 instead, since run ids are unguessable UUIDs).
  `X-Accel-Buffering: no` plus `ob_flush()`/`flush()` per chunk stop
  Nginx + PHP-FPM from buffering the stream into silence.
- **Internal/Http/Controllers/LogStreamController** — `GET
  /dev/logs/poll`, a single-shot JSON read of any new bytes in today's
  rolling Laravel log file past the client's `?since=` offset (defense
  in depth re-applies `RedactSecretsProcessor` to every chunk; the
  on-write Monolog tap is the first layer). Returns immediately
  (~5-50 ms) so the single-threaded PHP built-in server can move on —
  the earlier SSE implementation held the server's only worker for up
  to `STREAM_TIMEOUT_SECONDS`, stalling every other in-app navigation;
  the client now polls every second instead for the same perceived UX
  with no blast-radius. Rotation detection: the response carries the
  file's current inode, which the client echoes back via `?inode=`; an
  inode change (logrotate truncate+rename, midnight rollover) OR a
  `?since` beyond the current file size both set `reset = true` and
  read from offset 0. The log path is computed from
  `ActiveLogFile::path()`, which resolves the file the configured
  channel actually writes — see
  [the file the log viewer opens](the-file-the-log-viewer-opens.md) —
  respects the `NATIVEPHP_STORAGE_PATH` retarget, and has no
  user-controlled input (no LFI surface). `GET /dev/logs/context` is the paired endpoint
  returning the ±radius lines around a given absolute line offset for
  click-to-expand, with the same redaction re-applied per line.
- **Internal/Enums/** — `CommandTier` (`Safe` / `Destructive`, with
  `reachesThePalette()` and a `fromStored()` that resolves an absent
  or unreadable persisted tier to `Safe`), `ArgType` (`Text`,
  `Select`, `FilePath`, `Boolean`), `AuditEvent` (the audit-row
  description taxonomy).
- **Internal/Support/DevModeSession** — the session keys the
  Advanced toggle is held in. The read sites fail closed on a typo
  but `ResetAdvancedToggleOnLogin`'s `forget()` fails open, so the
  key is a constant rather than a literal at each site.
- **Internal/Logging/RedactSecretsProcessor** — Monolog tap that
  scrubs every log line before it lands on disk.
- **Internal/Services/OAuthScrubSet** — singleton holding the
  decrypted-on-demand OAuth secret literals. Busted by
  `BustOAuthScrubSetOnSecretChange` Eloquent observer on every
  `OAuthSecret` save / delete.
- **Internal/Http/Middleware/EnsureDeveloperMode** — the single
  sanctioned `/dev/*` guard. Refuses any non-developer caller with
  404. Also registered as Livewire persistent middleware, because
  the update endpoint runs outside the route stack: without it a
  snapshot minted while the flag was on kept driving the SQL panel,
  the runner and the queue inspector after it came off.
- **Internal/Http/Middleware/HorizonFrameAncestors** — the
  `frame-ancestors` header on the Horizon iframe page so the
  embed renders without the CSP rejecting it.
- **Internal/Http/Livewire/** — twelve Livewire pages
  (DevOverviewPage, ArtisanRunnerPage, AuditLogPage,
  LogTailerPage, QueueInspectorPage, HorizonFramePage,
  DoctorPanelPage, SqlPanelPage, SystemSnapshotPage,
  TripleGateModal, CommandPaletteModal, CommandArgPromptModal).
- **Internal/Listeners/** — `LogQueueLifecycle` (logs `JobProcessed`
  - `JobFailed` so `/dev/logs` shows completions),
  `WriteWorkerHeartbeat` (queue-looping closure that bumps the
  heartbeat cache key on every tick), `ResetAdvancedToggleOnLogin`,
  `BustOAuthScrubSetOnSecretChange`.
- **Internal/Navigation/** — `NavigationRegistryImpl`,
  `AppActionRegistryImpl`, `DevSidebarItems` (the dev-shell sidebar
  rendering data).
- **Internal/Console/PruneDevAuditCommand** — `beatrax:prune-dev-audit`.

The repo-wide arch invariants `noHorizonImportsInShippedBuildCode`
and `noUnsanctionedAuditWriter` are anchored here.

## Key services + events

- `CommandSpawner::start($command, $args, $callerUserId, $tier)` —
  the single Symfony-`Process` constructor. Whitelists the command
  name against `DevCommandRegistry`, renders each arg per its
  `ArgType`, writes the opening audit row, spawns the process,
  returns the run id. `$tier` is a `CommandTier`, so the two spawn
  controllers cannot hand it a value the registry does not know.
- `RunRegistry::store($record)` / `find($runId)` /
  `markFinished($runId, $exitCode, $finishedAt)` /
  `markCancelled($runId)` — per-run state cache in the cache store
  (`RunRecord` rows live in cache, not the DB). The durable trace is
  the OPENING `dev_mode_audit` row, which `CommandSpawner` writes
  eagerly and deliberately incomplete; `FinalizeRunAudit` updates that
  same row in place, keyed on `properties.run_id`.
- `FileTailer::tailOnce($path, $fromOffset)` — one bounded read, not
  a stream: it returns `{chunk, newOffset}` for at most 64 KiB from
  the caller's offset, and the Livewire log tailer polls it. Every
  failure mode — missing, rotated, truncated, caught up, unreadable —
  hands back the caller's offset unchanged, which is what makes a
  re-poll idempotent.
- `WriteWorkerHeartbeat::__invoke()` — bumps the
  `queue.worker_heartbeat` cache key with the current
  `Clock::now()`. The boot-health probe reads it.
- `RedactSecretsProcessor::__invoke($record)` — Monolog tap.
  Replaces Bearer tokens, JWT shapes, and every OAuth secret literal
  with `[REDACTED]`. Reads the literals from `OAuthScrubSet` lazily;
  the scrub set is invalidated by the Eloquent observer on every
  `OAuthSecret` change.
- `SpatieAuditWriter::recordCommandRun($run)` — opens an activity
  log row scoped to the dev_mode_audit log name, described by an
  `AuditEvent` case. The current user + timestamp + redacted
  context are part of the canonical row.

The module raises no Public events; the Internal listeners observe
framework events (`JobProcessed`, `JobFailed`, `Login`) and the
Eloquent observer.

## The runner needs a child process, and the phone builds have none

Spawn-then-tail is the whole architecture: the request returns in
milliseconds because a detached child does the work. On iOS there is
no child to detach. Three separate things are missing, and each on
its own is fatal:

- `PHP_BINARY` is the empty string. The mobile runtime is the `embed`
  SAPI, which has no interpreter file behind it, so the invocation the
  spawner assembles begins `'' '…/artisan' 'route:list'`.
- `/bin/bash` and `/bin/sh` are not present in the app sandbox at all.
- `proc_open()` exists and is not disabled, but the fork behind it is
  refused: `PHP Startup: Fork failed: Operation not permitted`.
  `pcntl_fork()` is not compiled in either.

That last one is the important one, because it rules out the obvious
workarounds. Dropping the bash wrapper and exec'ing PHP directly fails
identically — the refusal is on starting any process, not on finding a
shell.

Running the command in-process instead, through `Artisan::call()` inside
the web request, is not the answer either, and this was measured on the
device rather than assumed:

- Every first-party command in the SAFE list is registered inside an
  `$this->app->runningInConsole()` guard, so from a web request they do
  not exist. `beatrax:doctor` answers `CommandNotFoundException`, and
  the Doctor panel is built entirely on that one command.
- `route:list` does run, and calls `Router::flushMiddlewareGroups()` on
  the live router as it goes. The request that invoked it then dies in
  `Kernel::terminate()` with `Target class [web] does not exist` — a
  500 for the reader either way, plus a router the rest of the process
  is now missing. A console command owns its process; in a web request
  it does not.
- `cache:clear` does work cleanly. It is the exception, not the rule,
  and a runner that silently supports three of eleven commands is a
  worse answer than one that says what it cannot do.

So the console reports the limit rather than working around it.
`CommandSpawner::start()` raises `ProcessSpawningUnavailableException`
before it creates the runs directory, the `RunRegistry` record or the
opening audit row, so a platform that cannot spawn leaves nothing
behind to sweep. The same exception replaces Symfony's
`ProcessStartFailedException` at the spawn itself, which is the layer
that catches a host where the interpreter path is fine and the fork is
still refused. `ArtisanSpawnController` and `DestructiveSpawnController`
answer `501` with `{error: "spawning_unavailable", message}`; the runner
page toasts the same sentence; the Doctor panel's Re-run shows whatever
`message` came back instead of silently un-pressing itself.

The empty-interpreter guard earns its place on the desktop too. Before
it, a spawner with `PHP_BINARY = ''` still returned `202` and a run id:
bash accepts `'' artisan cache:clear`, the wrapper exits 0, and the
console reports a started run that never ran anything.

## Data flow

The ⌘K command-palette flow:

```
user presses ⌘K from anywhere in the app
  → palette modal mounts via Livewire
  → CommandPaletteModal builds the entry list
       → NavigationRegistry::all (filtered by is_developer)
       → AppActionRegistry::all
       → DevCommandRegistry::safe (developers only; the destructive
         tier never enters the palette — it stays behind the Re-run
         affordance's triple gate)
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
  → CommandSpawner::start($name, $args, $callerUserId, $tier)
       → whitelist against CommandRegistry → InvalidArgumentException
                                              if not found
       → CommandArgValidator::assertValid($spec, $args)
       → AuditWriter::recordCommandRun(outcome-less row + run_id)
       → Symfony Process::start (watcher subshell writes the child's
                                 exit code to the .out.exit sidecar)
       → RunRegistry::record($run)
  → ArtisanRunnerPage subscribes to the run via wire:poll
       → display stdout/stderr tail
  → on completion:
       → FinalizeRunAudit::__invoke($runId, $exitCode, $cancelled)
           → AuditWriter::finalizeCommandRun(same row, by run_id,
                                             + exit_code + excerpts)
```

The queue-inspector flow:

```
/dev/queue
  → QueueInspectorPage reads the `jobs`, `failed_jobs` and
       `job_batches` tables through the query builder
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

## Livewire page notes

Per-page detail that doesn't fit the flow diagrams above:

- **DevOverviewPage** (`/dev`) — the console's landing page. Primary
  visual anchor is a theme-locked dark `.console-pane` (fixed
  `#0b1220` background / `#f1f5f9` text regardless of theme). A
  three-column head shows: worker heartbeat (from
  `WriteWorkerHeartbeat::CACHE_KEY`, rendered "Ns ago · ttl 60s" when
  fresh, "NOT RUNNING" when stale/missing); queue counts (pending/
  failed/active batches via the raw query builder); and the last
  command (the calling developer's most recent `dev_mode_audit` row —
  `causer_id`-scoped like every other read of that table; it was the one
  read the audit-page sweep missed, and it named another operator's last
  command on a page whose Clear all cannot reach that row). The console-pane tail
  shows the last 5 structured entries from the log file the configured
  channel writes, via `RecentLogEntriesReader` (continuation lines fold into the
  preceding entry; messages re-scrubbed), each linking to `/dev/logs`
  pre-filtered by severity + first words. The recent-runs card shows
  the calling developer's last 5 audit rows with a
  `?command=<encoded>` deep-link to `/dev/audit`; the open-alerts card
  shows `SystemAlertQuery::active()` for the current user
  (un-acknowledged `system_alerts` rows scoped to the caller-or-
  system-wide cohort).
- **ArtisanRunnerPage** (`/dev/artisan`) — header with a primary "⌘K Run
  a command" CTA; a filter chips row (All/Running/Failed/Destructive,
  persisted via `#[Url]`, each chip keyed on the run status the audit
  row actually carries — `failed` is a status the mapper produces from
  a non-zero exit code, not a shape the filter alone knows); a worker pre-flight pill reading
  `dev_mode.queue_worker_heartbeat` (green when fresher than 60s); a
  day-section timeline of run-cards; and a fallback Flux modal exposing
  SAFE-tier commands ONLY (DESTRUCTIVE stays reachable via the palette,
  the CLI, or the timeline's Re-run affordance, to avoid muscle-memory
  disasters). `mount()` resets `dev_mode.advanced` on first-load-per-
  session as a belt-and-braces alongside `ResetAdvancedToggleOnLogin`
  (Login does not always refire on session-resume). `spawn()` runs the
  declared `ArgSpec::$rules` through `CommandArgValidator`, the same
  seam both spawn controllers use; `rerun()` refuses another
  developer's run the way the stream and cancel endpoints do — it is
  the one of the three that EXECUTES. Method-DI throughout — Livewire components never receive constructor DI per the
  project's larastan-strict-rules profile.
- **CommandArgPromptModal** — global arg-prompt modal SFC, mounted once
  per layout so `command-args:prompt` can open it from anywhere (command
  palette, artisan runner fallback modal). Renders a Flux flyout with
  one input per `ArgSpec` on the targeted `CommandSpec` (`text` →
  `<input type=text>`, `boolean` → checkbox rendering the literal
  `--name` flag when truthy, `select` → `<select>` from
  `$argSpec->options`). Required-arg enforcement is three-layered: a
  disabled submit button in the Blade (UX nicety), a server-side
  re-check here with an in-modal error banner, and
  `ArtisanRunnerPage::spawn()`'s pre-spawn guard as the third line of
  defense for any caller that bypasses this modal entirely. Hostile
  DESTRUCTIVE-tier names that somehow arrive on the event route through
  `triple-gate:open` instead of spawning, since the palette JSON only
  ever exposes SAFE rows.
- **CommandPaletteModal** — the global ⌘K palette, mounted once per
  base layout (`app.blade.php` and `dev-shell.blade.php`) so any
  authenticated page can open it by dispatching `palette:open` (the
  global Alpine `<body>` keybind fires this on ⌘K/Ctrl+K). Renders the
  server-side JSON registry the client-side Fuse.js factory consumes,
  merging three sources: navigation entries (every authenticated view,
  visible to everyone), dev commands (SAFE-tier only, filtered by
  `is_developer` at JSON-emit time — never on the client, so tampering
  with the client-side JSON cannot bypass the filter), and app actions
  (visible to everyone). Recent shortcuts (per-user, 5 entries, 30-day
  TTL) live in cache key `dev_mode.palette_recent.{userId}`; `pickEntry()`
  writes to it on every selection (dedup + cap), and the same key seeds
  the Recent rail on `mount()`. Navigation itself is handled entirely
  client-side by the `palette()` Alpine factory (`window.location` for
  `url` rows, a Livewire browser event for `handlerEvent` rows,
  `spawn-command` for `dev`-source rows) — the Livewire methods here
  only persist state.
- **TripleGateModal** — the global triple-gate modal SFC, mounted once
  in the dev-shell layout so `triple-gate:open` can open it from
  anywhere on `/dev/*` (runner timeline rows, command palette, queue
  inspector's bulk-delete affordance). Server-side enforcement of all
  three locks before any DESTRUCTIVE command spawns: Gate 1
  `config('app.dev_mode') === true` (env-pinned); Gate 2
  `session(DevModeSession::ADVANCED_KEY) === true` (resets on Login
  via `ResetAdvancedToggleOnLogin`); Gate 3 the operator typed the exact
  app name `Beatrax` (timing-safe `hash_equals`, so
  client-side enable/disable of the button is purely cosmetic). On
  all-three-pass it dispatches `triple-gate:confirmed` with the command
  - args + typed token; downstream listeners
  (`DestructiveSpawnController` for artisan, `QueueInspectorPage` for
  bulk-delete) re-validate all three gates a second time so a tampered
  Livewire payload that somehow spoofs the confirmed event still cannot
  reach the spawner/delete path without passing the identical sweep.
- **SystemSnapshotPage** (`/dev/system`) — the full operational fact
  sheet for debugging install state: PHP version/SAPI/php.ini
  path/extension list; 4 key SQLite PRAGMAs (`journal_mode`,
  `synchronous`, `cache_size`, `page_size`) plus DB file path/size via
  `UserDataPathService::databaseFile()`; Laravel version/env/debug/
  locale/timezone; base/app/storage/config/cache paths; env vars
  filtered to `BEATRAX_*`/`NATIVEPHP_*`/`APP_KEY` and passed through
  `ConfigFlattener::redactSecretSuffixes()` (masks `APP_KEY` while
  `BEATRAX_DEV_MODE` renders plainly); NativePHP version (if installed)
  via `InstalledVersions::getPrettyVersion()` plus host OS via
  `php_uname()`; and the full effective config (`config()->all()`)
  flattened + redacted through the same denylist.
- **SqlPanelPage** (`/dev/sql`) — SELECT-only SQL execution + schema
  viewer, via a defense-in-depth pipeline: (1) `SelectOnlyValidator::validate()`,
  a parse-time guard via the doctrine/sql-formatter Tokenizer; (2)
  `ReadOnlySqliteConnection::execute()`, an execution-time guard via
  `PRAGMA query_only = 1` plus a 5-second cap enforced on a child
  process (`IsolatedSelectProcess`); (3)
  `AuditWriter::recordSelectQuery()`, writing a `dev_mode_audit` row
  (`AuditEvent::SqlSelect`) on every successful query. The page is
  gated on Dev Mode ON (route middleware) plus the session-scoped
  Advanced toggle; when Advanced is OFF the page still renders but Run
  is short-circuited with a banner — no typed-name modal here, since
  the parser + PRAGMA + cap are the actual guard. The schema viewer is
  an inner sidebar of `/dev/sql`, not a separate route; Browse-table
  reuses the same `run()` pipeline so the audit-row contract is
  identical.
- **SelectOnlyValidator** — the single seam for the
  doctrine/sql-formatter library, whose `Tokenizer` class is marked
  `@internal` upstream. Wrapping its instantiation + iteration in one
  file, backed by a contract test
  (`tests/Contracts/SelectOnlyValidatorContractTest.php`), means a
  future composer update that reshapes `Tokenizer` fails CI loudly
  instead of silently allowing a non-SELECT statement through; any
  change to this file must keep that contract test green. The
  statement-separator scan runs over the TOKENS, not the raw text, so
  a `;` inside a string literal or a trailing comment is data rather
  than a second statement — a bank description carries semicolons,
  and `PRAGMA query_only = 1` had already ruled the second statement
  out. A leading `WITH … SELECT` CTE is admitted; a CTE carrying a
  data-modifying keyword is not. Rejection reasons surfaced via
  `ValidationException`'s `sql` error key:
  `semicolon_followed_by_statement` (a statement token follows the
  `;`, e.g. `SELECT 1; INSERT…`), `empty_statement`,
  `first_token_not_select:{token}`, `cte_contains_write:{keyword}`,
  and `cte_without_select`.
- **RecentLogEntriesReader** — reads the tail of the active Laravel
  log file line-by-line through `SplFileObject`, keeping only a
  200-line window (the same reason its sibling `LogFileStats` gives:
  this pane backs `/dev`, the page opened BECAUSE something is
  wrong, and loading a multi-megabyte log whole tripled the
  request's peak memory). It folds raw lines into structured entries
  matching
  `[YYYY-MM-DD HH:MM:SS] channel.SEVERITY: message body`. Continuation
  lines (stack-trace rows, JSON payload tails) fold into the preceding
  entry's message; every returned message is re-run through
  `RedactSecretsProcessor` as defense-in-depth on top of the on-write
  Monolog tap. Each entry carries an `href` pre-filtered deep-link to
  `/dev/logs` (severity + `contains` params) so the operator can drill
  from a dashboard row into the live tailer with matching context.
- **ReadOnlySqliteConnection** — the read-only SQLite seam for
  `/dev/sql`. The `readonly_select` connection (config/database.php)
  shares the same on-disk file as the default `sqlite` connection; this
  class opens the PDO and applies `PRAGMA query_only = 1` per-PDO
  before executing, SQLite's engine-level read-only mode that rejects
  every write with `SQLITE_READONLY` (defense-in-depth alongside the
  parse-time `SelectOnlyValidator`). Under tests, where the default
  connection is `sqlite_testing` (in-memory), a separate
  `readonly_select` connection instance would resolve to a SEPARATE
  `:memory:` database with an empty schema — `resolveConnection()`
  special-cases that by falling back to the default connection when it
  is `sqlite_testing`, and that in-memory case is also the only one
  that still executes in-process (see "The SQL panel's timeout runs
  out of process" below).
- **QueueInspectorPage** (`/dev/queue/{tab}`) — a three-tab queue
  inspector; `pending`/`failed`/`batches` deep-linkable URLs resolve to
  one component with the tab driven by the route param (the bare
  `/dev/queue` URL redirects to `/dev/queue/pending`). Per-row actions:
  Pending (`jobs`) → delete; Failed (`failed_jobs`) → retry, delete
  (forget); Batches (`job_batches`) → retry-failures, cancel, delete.
  Bulk retry uses a single-confirm modal; bulk delete routes through
  the shared `TripleGateModal` (`triple-gate:open`), whose confirmed
  event listener calls `executeBulkDelete()`, re-validating all three
  gates before delegating to `QueueActions::bulkDelete`. Count tiles
  and per-tab rows both use the raw query builder (Eloquent's
  `__call` dynamic-call narrowing is banned by larastan-strict-rules).
  The inline JSON payload viewer runs every payload through
  `RedactSecretsProcessor::scrub()` at render time so Bearer/JWT/
  OAuth-secret literals never reach the browser; it's opt-in via
  `expandedRowId` (one expanded row per page).
- **LogTailerPage** (`/dev/logs`) — live tail of the daily-rotated
  Laravel log file with severity multi-select, channel filter,
  contains-filter, pause/resume, a 10k-line client-side ring buffer,
  and click-to-expand ±10 lines of context. Server state is minimal —
  every filter is `#[Url]` so the page is deep-linkable; the 10k-line
  scrollback lives entirely in a client-side Alpine ring buffer (the
  server never holds it), and pause/resume is purely client-side too
  (the SSE controller has no notion of pause — the Alpine handler just
  closes and re-opens the EventSource).
- **DoctorPanelPage** (`/dev/doctor`) — a thin wrapper that triggers
  `beatrax:doctor` through the same Process+SSE pipeline as the
  artisan runner; the page itself does not own the SSE consumer (Alpine
  does: POST `/dev/artisan/spawn` with `command=beatrax:doctor`, then
  open an EventSource against `/dev/artisan/stream/{runId}`). When the
  stream terminates, `FinalizeRunAudit` lands the captured stdout in
  the `dev_mode_audit` row, so on the NEXT GET this page reads the
  latest `beatrax:doctor` row's `properties.stdout_excerpt` and parses
  it via `ProbeOutputParser` into pass/warn/fail rows. The read is
  scoped to `causer_id` like every other `dev_mode_audit` read (probe
  output carries the filesystem paths of whoever ran it), and ordered
  by `created_at` THEN `id`, so the finished row wins over the empty
  eager one when both land in the same second. This single code
  path gives identical UX between "run from CLI" and "run from
  /dev/doctor" — both write the same audit row the page reads back, and
  the Re-run button just re-surfaces the spawn endpoint.
- **QueueActions** — the programmatic interface for the queue
  inspector, constructor-DI'd on `FailedJobProviderInterface` (the
  default database-queue binding is `DatabaseFailedJobProvider`, used
  for forget/find/all + the payload accessor retry needs),
  `BatchRepository` (direct DI for cancel/delete, never the `Bus`
  facade), `QueueFactory` (re-push the original payload via
  `connection($name)->pushRaw($payload, $queue)`), `DatabaseManager`
  (direct row-level access for the pending `jobs` table, since the
  framework provider exposes no `deletePending`), `AuditWriter`, and
  `CurrentUser`. Every action method writes exactly one
  `dev_mode_audit` row via an `AuditEvent` enum value, never a
  free-form string. Triple-gate enforcement lives one layer up:
  `QueueInspectorPage` dispatches `triple-gate:open` before invoking
  `bulkDelete` and re-validates all three gates in its confirmed-event
  listener before delegating here — `QueueActions` itself is a thin,
  testable seam that trusts the caller to have validated the gates.

## The SQL panel's timeout runs out of process

`PDO::ATTR_TIMEOUT` is connection-level (lock-wait), not
query-duration, so it caps nothing here. The cap used to be
`set_time_limit(5)` around the in-process `select()`, read back in
`SqlPanelPage` by matching `'maximum execution time'` on the caught
exception's message. That branch could never run: an execution-time
expiry is a PHP **fatal error**, not a `Throwable`, so neither the
`catch` nor `register_shutdown_function()` fires. Worse, the bundle
serves the app with the PHP built-in server, which is a single
process — one `SELECT count(*)` over an endless recursive CTE (a
statement `SelectOnlyValidator` allows: no write keyword, one
statement) took the whole backend down. Reproduced against a real
`php -S`: the runaway request answered with an empty reply, the next
request got connection-refused, and the server pid was gone.

Nothing in PHP can interrupt a statement SQLite is already stepping.
`sqlite3_interrupt` and `sqlite3_progress_handler` are not exposed by
`pdo_sqlite` or `ext-sqlite3`; ticks and `pcntl` signals are only
dispatched between VM instructions, and the VM is blocked inside
`sqlite3_step`. A statement-level timeout therefore does not exist to
be asked for.

So the query runs in a child process. `IsolatedSelectProcess` spawns
`PHP_BINARY -r <child>` with the database path in argv and the SQL on
stdin, and gives Symfony `Process` the 5-second timeout; on expiry
Symfony SIGKILLs the child and the parent raises
`QueryTimedOutException`, which is what `SqlPanelPage` maps to
`dev::sql.errors.timeout`. The child needs no framework and no
autoloader — PDO plus `json_encode` — and re-applies `PRAGMA
query_only = 1` itself, so the engine-level write guard travels with
it. Rows come back as JSON with `JSON_INVALID_UTF8_SUBSTITUTE` so a
BLOB column cannot fail the encode.

The child is the second and only other place allowed to issue `PRAGMA
busy_timeout` (see `OneConnectionHoldsOneBusyTimeoutArchTest`). Its PDO
is not one of Laravel's connections, so the `ConnectionEstablished`
listener that normally applies the configured wait never fires for it,
and without the pragma it would run at SQLite's default of not waiting
at all — a regression against the 30 s the in-process path had while
four desktop processes share one file. The number is still not written
here: `ReadOnlySqliteConnection` reads `getConfig('busy_timeout')` off
the calling connection and passes it in, and 0 means "the connection
configures none", not "wait five seconds".

What it costs: roughly 250 ms of interpreter start-up per query, and
the child opens the database file directly rather than reusing the
request's PDO. An in-memory database exists only inside the calling
process, so `canIsolate()` refuses it and the in-process path is kept
for the test connection; it is also refused when `PHP_BINARY` is empty
(the embed SAPI), where the failure surfaces as an engine error rather
than a silent uncapped run.

## Horizon conditional-registration arch invariants

`DevModeServiceProvider::boot()` registers the `/dev/horizon` route only
when `config('app.dev_mode') === true` AND the Horizon package's
`ServiceProvider` class is present (Horizon ships `require-dev`, so a
`--no-dev` production build never has it). The dev_mode flag is read
through an injected `Config\Repository`, not the `config()` global
helper, keeping the provider facade-free per the DI-only rule. When
either signal is false the route never registers, so the dev-shell
sidebar's `Route::has('dev.horizon')` check drops the nav item entirely
(DOM-absent, not merely nav-disabled); non-developers get a 404 from
the surrounding `ensureDeveloperMode` middleware regardless.

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

## Secret redaction pipeline

Four classes cooperate to keep OAuth secrets, Bearer headers, and
JWT-shaped tokens out of both the rolling log file and the
`dev_mode_audit` table:

- **`OAuthScrubSet`** — a singleton cache of every distinct decrypted
  OAuth-secret string the app knows about (`oauth_secrets.client_secret`
  plus the `access_token` / `refresh_token` fields of each row's
  `tokens_blob` JSON — the blob's `provider`, `email`, `scope` and
  `expires_at` sit beside them and are ordinary words, and taking
  those as needles rewrote unrelated log lines the console exists to
  read). Lazily
  loads on first `all()`/`compiledPattern()` call so app boot never
  hits the DB before migrations run. `compiledPattern()` returns a
  single pre-compiled alternation regex (`'/(s1|s2|s3)/'`), so a record
  is scrubbed in one `preg_replace` pass regardless of set size — a
  naive foreach + `str_replace` would be O(n·m) per record. The
  Eloquent observer `BustOAuthScrubSetOnSecretChange` busts the cache
  on every `OAuthSecret` save/delete, so a rotated secret takes effect
  on the very next write. The set is intentionally NOT user-scoped
  (every user's secret is redacted from every line, since the
  filesystem and audit DB are both shared across users on one
  machine); once a row is deleted the cache busts and that string stops
  being scrubbed — a revoked-and-removed token is no longer considered
  sensitive by this threat model. A post-boot load failure writes a
  critical `SystemAlert` once per process (best-effort — a failure to
  write the alert itself is swallowed rather than crashing the
  request, and `compiledPattern()` runs on every log record, so a
  per-record alert would be a flood). A failure is never cached as
  "nothing to scrub": the next call reloads, so redaction resumes as
  soon as the cause clears.
- **`RedactSecretsProcessor`** (on-write) — a Monolog `ProcessorInterface`
  registered via **`PushRedactProcessor`** (a Laravel logging "tap"
  class) onto every handler of the `stack`/`single`/`daily` channels.
  Container resolution (rather than `new RedactSecretsProcessor`) keeps
  the processor's DI chain invisible to `config/logging.php`; the
  `OAuthScrubSet` constructor argument is nullable so direct
  instantiation in unit tests still works.
- **`RedactionExcerptCap`** (on-write, audit-row) — the same
  three-layer scrub applied to `stdout_excerpt`/`error_excerpt` before
  `SpatieAuditWriter` persists them into `dev_mode_audit`, plus one
  layer the log tap does not need: the recovery-code shape. A
  registered destructive command, `beatrax:regenerate-recovery-codes`,
  prints ten live single-use credentials to stdout, and stdout is
  exactly what this excerpt is — the codes were landing in the table
  verbatim, behind a Copy button. The pattern is
  `RecoveryCodeGenerator`'s own: five hyphen-joined groups of four from
  an alphabet with no I, L, O, 0 or 1. A separate artifact from
  `RedactSecretsProcessor` because the two bound different exit points
  (rolling log file vs. audit DB row).
- **`AuditLogPage` reads AND clears causer-scoped.** `/dev/audit` used
  to filter on `log_name` alone, so every developer-mode user read every
  other one's rows — which is how a sheet of somebody else's recovery
  codes became one click away. The read now carries
  `where('causer_id', $user->id())` like every other `dev_mode_audit`
  read, and the caller filter + caller column went with it: a
  single-caller log has nothing to filter by. `truncateAll()` reads
  through the same predicate. It used to `delete()` the bare table, so
  the Clear all button — one Alpine `confirm()` in front of it —
  destroyed history the pressing developer was no longer allowed to
  open, including the rows recording another operator's destructive
  runs. Wiping what you cannot read is not a narrower power than
  reading it; the button now takes exactly the rows the page shows.
- **`ReadLogTail` / `ReadLogContextWindow`** (on-read) — re-apply the same
  processor to every chunk returned by `/dev/logs/poll` and `/dev/logs/context`,
  giving belt-and-braces redaction at both write time and read time.
- **`PushRedactProcessor`** — a Laravel-style "tap class" registered
  into a channel's `tap` array in `config/logging.php`; Laravel
  resolves it on every channel boot and invokes `__invoke($logger)` so
  the channel's Monolog handlers can be decorated AFTER the channel
  driver constructs them. It resolves `RedactSecretsProcessor` from the
  container (rather than `new RedactSecretsProcessor`) so a future
  change to the processor's DI chain requires no edit here or in
  `config/logging.php`. Laravel instantiates tap classes with
  `new $tap()`, so the constructor accepts no required arguments;
  container access goes through `Container::getInstance()->make(...)`
  rather than the `app()` global helper to honour the DI-only rule.

All three redaction sites apply the layers in the same fixed order:

1. OAuth scrub-set FIRST, so a literal that happens to look like a JWT
   (OAuth refresh tokens often do) is replaced with the more-specific
   `[REDACTED]` rather than the generic `[JWT_REDACTED]`.
2. `Authorization: Bearer <token>` → `Authorization: Bearer [REDACTED]`.
3. JWT shape (`eyJ…header.payload.signature`) → `[JWT_REDACTED]`.
4. (Audit-row path only) Byte-cap to `$maxBytes` (default 8 KiB) LAST,
   so a token straddling the byte boundary cannot leak through as a
   partial.
