# `DevMode` — specs

The behavioural contract for the `DevMode` module.

## Behavioral contracts

- **`/dev/*` requires `is_developer = true`.** The
  `ensureDeveloperMode` middleware is on every route; a non-developer
  caller receives 404. No 403 — the surface stays hidden.
- **The artisan runner only spawns commands in
  `DevCommandRegistry`.** `CommandSpawner::spawn()` whitelists the
  command name against the registry before any `Process` is
  constructed; an unknown name raises `InvalidArgumentException`.
- **NEVER-EXPOSED commands (`migrate`, `migrate:rollback`, `db:seed`)
  are absent from the registry.** Adding one is a deliberate
  registry change visible in code review; the spawner trusts the
  registry as the authoritative allow-list.
- **DESTRUCTIVE-tier commands require the triple gate.** The
  `TripleGateModal` requires (a) the Advanced toggle on, (b) an
  explicit confirm click, (c) a typed phrase matching the command
  label. The runner page mounts with Advanced off; every Login
  resets it (`ResetAdvancedToggleOnLogin`).
- **Every spawned command writes an opening + closing audit row.**
  The opening row carries the `command.start` action; the closing
  row carries `command.complete` or `command.failed` with exit
  code + duration. `FinalizeRunAudit` is the closing writer.
- **`AuditWriter::write` is the only sanctioned path to a
  `dev_mode_audit` row.** Direct INSERTs are blocked by the
  `noUnsanctionedAuditWriter` arch invariant.
- **Every log line is scrubbed by `RedactSecretsProcessor` before
  hitting disk.** Bearer tokens, JWT shapes, and every OAuth
  literal (decrypted from `oauth_secrets`) are replaced by
  `[REDACTED]`. The scrub set is invalidated by the Eloquent
  observer on every `OAuthSecret` save / delete so a rotated secret
  applies on the very next log line.
- **The audit-row excerpt is also scrubbed.**
  `RedactionExcerptCap::cap()` runs the same scrub pipeline before
  the row is persisted, so the audit surface never replays a leaked
  token from the run's stdout.
- **The queue-worker heartbeat updates on every queue tick.**
  `WriteWorkerHeartbeat` is registered via
  `QueueManager::looping(closure)` — the event-listener form does
  not fire reliably under `queue:work`. The boot-health probe reads
  the heartbeat and surfaces a warning when it is stale.
- **The Horizon iframe is registered only when both
  `config('app.dev_mode') === true` and
  `Laravel\Horizon\HorizonServiceProvider` exists.** A production
  `--no-dev` build silently skips the route; the dev-shell sidebar
  reads `Router::has('dev.horizon')` to gate the nav item.
- **The Horizon iframe carries a `frame-ancestors` CSP header.**
  `HorizonFrameAncestors` middleware applies it; without the header
  the embed is rejected by the browser CSP.
- **The Horizon import is allowed only in
  `app/Providers/HorizonServiceProvider.php`.** The repo-wide
  `noHorizonImportsInShippedBuildCode` invariant blocks any
  Horizon symbol outside that file (the regex strips
  `class_exists(\Laravel\Horizon\…)` arguments first so the inline
  FQCN gate inside this provider's `boot()` is legal).
- **The ⌘K palette filters dev rows for non-developers at
  JSON-emit time.** Defense-in-depth on top of the middleware on the
  routes themselves; a non-developer never sees the Dev Console
  labels in their palette JSON.
- **The Advanced toggle in the artisan runner resets on every
  successful Login.** `ResetAdvancedToggleOnLogin` is the listener;
  the runner page's `mount()` resets a second time on first-load-
  per-session as a belt-and-braces for the session-resume path that
  does NOT fire `Login`.
- **`LogQueueLifecycle` writes `JobProcessed` / `JobFailed` to the
  laravel log.** Both the `database` queue driver and Horizon delete
  successful rows from the `jobs` table on completion; the
  log is the visibility seam for the queue inspector.
- **The SQL panel is SELECT-only.** The query parser refuses any
  statement whose first token is not `SELECT` / `WITH` (after
  whitespace + comment stripping).

## Edge cases

- **Spawning `config:show` without an argument** — the spec marks
  the `config` arg as `required`; the spawn-time required-arg guard
  refuses to spawn. Earlier draft marked `nullable` and produced an
  opaque "Not enough arguments" abort from Symfony Console.
- **A run that completes between two Livewire polls** — the
  `RunRegistry` cache key survives both polls; the closing audit
  row is the durable trace.
- **A worker dying mid-run** — the opening audit row is durable; no
  closing row is written. `FinalizeRunAudit` does not retroactively
  close orphan runs; the next dev-prune cycle reports them in the
  `dev:prune-audit` summary so the operator can investigate.
- **A user with `is_developer = false` clicking a Dev Console
  bookmark** — `ensureDeveloperMode` returns 404; the same surface
  is hidden the same way as for an unauthenticated request.
- **`config('app.dev_mode')` is true but Horizon is not installed**
  — the conditional registration skips silently; the dev-shell
  sidebar does not render the Horizon item. No exception, no
  broken nav.
- **An OAuth secret is rotated while a long-running command is
  printing** — the Eloquent observer busts `OAuthScrubSet` on the
  save; subsequent log lines pick up the new pattern. Already-
  written lines carry the old redaction (they were already
  redacted with the prior pattern when emitted).
- **A SELECT-only query containing `;DELETE…`** — the parser
  rejects multiple statements; semicolons inside a quoted literal
  pass (the parser is statement-boundary-aware).
- **A failed-job row that has been pruned from `failed_jobs`** —
  `LogQueueLifecycle::failed` still wrote to the log when the
  failure happened; the audit trail survives independently of the
  framework's `failed_jobs` table TTL.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/specs.md) — `Clock`, `CurrentUser`,
    `SystemAlert` (for the doctor probes' surface).
  - [`Auth`](../auth/specs.md) — `RequireDeveloperMiddleware`
    composes with `EnsureDeveloperMode` on the route group; both
    must pass.
  - [`EmailScan`](../email-scan/specs.md) — `OAuthSecret` model the
    scrub-set decrypts from.
  - `spatie/laravel-activitylog` (third-party).
- **Depended on by**
  - Every authenticated layout — the ⌘K palette mount, the sidebar
    nav-list. Both surfaces inject the `NavigationRegistry` and the
    `AppActionRegistry` Public contracts; the registries are
    populated in this module's provider.
  - The boot-health probe (in `Core`) — reads
    `queue.worker_heartbeat` cache key written by
    `WriteWorkerHeartbeat`.

## Configuration + feature flags

- `config('app.dev_mode')` — the master gate for the Horizon iframe
  registration and the dev-shell's existence. False in a packaged
  build by default; true in local dev.
- `users.is_developer` — per-user developer flag. The owner of the
  install carries it (set true at signup); partner accounts default
  to false.
- `BEATRAX_RUNTIME=local` (env) — informs the system-snapshot page's
  presentation; does NOT affect the dev gate.
- `dev_mode_audit` retention — the `dev:prune-audit` command takes
  a retention argument; the operator runs it periodically.
- No per-user opt-out for the audit log; every dev action is
  recorded.
