# `DevMode` — how to test

Practical recipes for exercising the `DevMode` module in isolation.

## Unit tests

- **Location:** `Modules/DevMode/tests/Unit/` (when present)
- **What they test:** the SELECT-only SQL parser; the
  `RedactionExcerptCap` Bearer / JWT scrub patterns; the
  `OAuthScrubSet`'s compiled-pattern shape; the `CommandSpec` +
  `ArgSpec` value-object equality; the `RunRegistry` tier
  hydration, which pins that a cached run with an absent or
  unreadable tier comes back as `CommandTier::Safe`.
- **Common stubs:** the scrub-set tests build the singleton
  manually with an in-memory list of literals to skip the DB
  decryption path; the parser tests are pure-function.

## Feature tests

- **Location:** `Modules/DevMode/tests/Feature/`
- **What they test:**
  - `EnsureDeveloperMode` middleware against developer +
    non-developer users (404 posture).
  - `CommandSpawner::start` whitelisting (unknown command → throw).
  - The triple-gate modal end-to-end (Advanced toggle + confirm +
    typed phrase).
  - The audit-row open + close lifecycle for a happy + failed run.
  - The Monolog tap redaction for a representative leak (Bearer
    token, OAuth secret literal, JWT shape).
  - The OAuth-secret rotation invalidates the scrub set on the next
    log line.
  - The conditional Horizon iframe registration under each of the
    four `(dev_mode, horizon installed)` combinations.
  - The ⌘K palette JSON shape for developer + non-developer users.
  - The queue-worker heartbeat update on `QueueManager::looping`
    closure ticks.
  - The `beatrax:prune-dev-audit` command's retention enforcement.
- **Setup:** every test uses `RefreshDatabase`. Tests that
  exercise the Horizon conditional set
  `config(['app.dev_mode' => true])` and either
  `class_alias(\stdClass::class, \Laravel\Horizon\HorizonServiceProvider::class)`
  (to fake "installed") or assert the route is absent.

## Contract / arch invariants

- `noUnsanctionedAuditWriter` — only `AuditWriter` may write to
  `dev_mode_audit`.
- `noHorizonImportsInShippedBuildCode` — only
  `app/Providers/HorizonServiceProvider.php` may import a Horizon
  symbol. `class_exists(\Laravel\Horizon\…)` arguments are stripped
  from the scan first so the inline FQCN inside this provider's
  `boot()` is legal.
- `noFacadeCallsInDevModeProvider` — the provider must use
  constructor / method DI for `Router`, `Config\Repository`,
  `LivewireManager`, `Dispatcher`; the `view()`, `config()`,
  `app()` global helpers are forbidden.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/DevMode/tests

# Just the spawner whitelist
vendor/bin/pest Modules/DevMode/tests/Feature --filter "CommandSpawner"

# Just the redaction
vendor/bin/pest Modules/DevMode/tests/Feature --filter "Redact"

# Stop on first failure
vendor/bin/pest Modules/DevMode/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A spawn attempt returns `InvalidArgumentException: command not
  registered`** — confirm the command name appears in
  `CommandRegistry` (and the args schema matches the upstream
  command's signature). Adding a NEVER-EXPOSED command is a
  deliberate change requiring a registry edit; never bypass via a
  direct `Process` constructor.
- **The triple gate accepts an empty typed phrase** — the modal's
  client-side gate is a UX shortcut; the server-side
  `TripleGateModal::confirm()` re-validates — dev mode on, the
  advanced-session key set, and `hash_equals('Beatrax', $this->typed)`
  — throwing a `ValidationException` on each. Confirm those three
  checks are intact; the client-side gate is decorative.
- **A leaked token visible in a log line** — extend
  `RedactSecretsProcessor` with the new pattern + a unit test
  proving the pattern fires. The processor is the single chokepoint;
  every line in every channel routes through it.
- **`/dev/horizon` returning 404 in dev** — read the gate. Both
  `config('app.dev_mode') === true` AND
  `\Laravel\Horizon\HorizonServiceProvider` (the package's actual
  provider) must be loaded. Run `composer show laravel/horizon` to
  confirm the package is installed; check `.env` for `APP_DEV_MODE`.
- **Audit rows missing for a run that completed** —
  `FinalizeRunAudit::__invoke` did not fire. The most common cause is
  the closing run state was missed because the worker process died
  unexpectedly. The opening row is durable — `CommandSpawner` writes
  it eagerly via `AuditWriter::recordCommandRun()` — so what you see
  is not an absent row but an open one: no `finished_at`, no exit
  code. Nothing sweeps for these; `beatrax:prune-dev-audit` is
  manual-only and deletes by age rather than reporting orphans, so
  the open row is the evidence and it stays until someone reads it.
- **`WriteWorkerHeartbeat` not firing** — the closure form of
  `QueueManager::looping(closure)` is the only reliable shape; the
  event-listener form does not fire under `queue:work`. Confirm the
  provider's `boot()` is the closure-form (per the docblock).
- **The ⌘K palette showing Dev Console items to a non-developer**
  — the route-side middleware should already 404 the click; the
  client-visible filter is `CommandPaletteModal::buildRegistry()`
  which drops entries whose id starts with `dev.` for non-developers.
  Both filters apply; missing one is a defense-in-depth regression.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

- **`/dev/*` requires `is_developer = true`.** The
  `ensureDeveloperMode` middleware is on every route; a non-developer
  caller receives 404. No 403 — the surface stays hidden.
- **A shipped build requires a second key.** `DevConsoleBuildGate`
  runs ahead of the account check and answers the same 404 when the
  build refuses. A development build is unaffected.
- **Only `local` and `testing` are development builds.** The gate holds
  an allow-list, not a `!== 'production'` comparison, so `staging`,
  `prod` and `Production` all need the same key a release does. The
  negative is pinned per spelling; the value is trimmed and
  lower-cased first, so `Local` still opens.
- **Nothing offers a console the build will not open.** The ⌘K
  registry, the argument prompt, the sidebar Dev block, the dashboard
  failed-job toast and the native Developer submenu all consult the
  same gate, so a shipped build renders no entry rather than a dead
  one.
- **The artisan runner only spawns commands in
  `DevCommandRegistry`.** `CommandSpawner::start()` whitelists the
  command name against the registry before any `Process` is
  constructed; an unknown name raises `InvalidArgumentException`.
- **NEVER-EXPOSED commands (`migrate`, `migrate:rollback`, `db:seed`)
  are absent from the registry.** Adding one is a deliberate
  registry change visible in code review; the spawner trusts the
  registry as the authoritative allow-list.
- **DESTRUCTIVE-tier commands require the triple gate.** The
  `TripleGateModal` requires (a) the Advanced toggle on, (b) an
  explicit confirm click, (c) the exact app name `Beatrax` typed in,
  compared with `hash_equals`. The runner page mounts with Advanced
  off; every Login resets it (`ResetAdvancedToggleOnLogin`).
- **Every spawned command leaves exactly one audit row.** The
  spawner writes it eagerly as `AuditEvent::CommandExecuted` with
  no outcome; `FinalizeRunAudit` finds it again by `run_id` and
  fills in the exit code, the excerpts and `finished_at`. The exit
  code comes from the `<run>.out.exit` sidecar the spawner's watcher
  subshell writes, since neither caller holds the code itself. A
  cancel is a `__cancelled` flag merged onto that same row, never a
  second event.
- **`AuditWriter` is the only sanctioned path to a
  `dev_mode_audit` row.** Direct INSERTs are blocked by the
  `noUnsanctionedAuditWriter` arch invariant.
- **`/dev/audit` reads and clears through one predicate.** Both
  `render()` and `truncateAll()` filter on `log_name` plus
  `causer_id`, so Clear all takes the rows the page shows and no
  others — proven by giving two developers a row each and pressing
  the button as one of them.
- **Every log line is scrubbed by `RedactSecretsProcessor` before
  hitting disk.** Bearer tokens, JWT shapes, and every OAuth
  literal (decrypted from `oauth_secrets`) are replaced by
  `[REDACTED]`. The scrub set is invalidated by the Eloquent
  observer on every `OAuthSecret` save / delete so a rotated secret
  applies on the very next log line.
- **The audit-row excerpt is also scrubbed.**
  `RedactionExcerptCap::apply()` runs the same scrub sequence as
  `RedactSecretsProcessor` — OAuth scrub set, then the Bearer header,
  then the JWT shape — and `SpatieAuditWriter` calls it on both
  excerpts before the row is persisted, so the audit surface never
  replays a leaked token from the run's stdout. The 8 KiB cap is
  applied last, after redaction, which is why `FinalizeRunAudit`
  reads 32 KiB of the run's output: a replacement can shorten the
  text, and the cap has to consume real content.
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
  closing row is written. Nothing reports orphans:
  `beatrax:prune-dev-audit` deletes by age, so the open row (no
  `finished_at`, no exit code) is the evidence and it stays until
  someone reads it.
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
  - [`Core`](../core/how-to-test.md) — `Clock`, `CurrentUser`,
    `SystemAlert` (for the doctor probes' surface).
  - [`Auth`](../auth/how-to-test.md) — `RequireDeveloperMiddleware`
    composes with `EnsureDeveloperMode` on the route group; both
    must pass.
  - [`EmailScan`](../email-scan/how-to-test.md) — `OAuthSecret` model the
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
- `dev_mode_audit` retention — the `beatrax:prune-dev-audit` command
  takes a retention argument; the operator runs it periodically.
- No per-user opt-out for the audit log; every dev action is
  recorded.
