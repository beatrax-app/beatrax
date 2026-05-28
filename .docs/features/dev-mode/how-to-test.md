# `DevMode` — how to test

Practical recipes for exercising the `DevMode` module in isolation.

## Unit tests

- **Location:** `Modules/DevMode/tests/Unit/` (when present)
- **What they test:** the SELECT-only SQL parser; the
  `RedactionExcerptCap` Bearer / JWT scrub patterns; the
  `OAuthScrubSet`'s compiled-pattern shape; the `CommandSpec` +
  `ArgSpec` value-object equality.
- **Common stubs:** the scrub-set tests build the singleton
  manually with an in-memory list of literals to skip the DB
  decryption path; the parser tests are pure-function.

## Feature tests

- **Location:** `Modules/DevMode/tests/Feature/`
- **What they test:**
  - `EnsureDeveloperMode` middleware against developer +
    non-developer users (404 posture).
  - `CommandSpawner::spawn` whitelisting (unknown command → throw).
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
  - The `dev:prune-audit` command's retention enforcement.
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
  client-side gate is a UX shortcut; the server-side `TripleGateModal::run`
  re-validates. Confirm the server-side check is intact; the
  client-side gate is decorative.
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
  `FinalizeRunAudit::handle` did not fire. The most common cause is
  the closing run state was missed because the worker process died
  unexpectedly; the opening audit row is durable, the closing one
  isn't, and the next prune cycle surfaces the orphan.
- **`WriteWorkerHeartbeat` not firing** — the closure form of
  `QueueManager::looping(closure)` is the only reliable shape; the
  event-listener form does not fire under `queue:work`. Confirm the
  provider's `boot()` is the closure-form (per the docblock).
- **The ⌘K palette showing Dev Console items to a non-developer**
  — the route-side middleware should already 404 the click; the
  client-visible filter is `CommandPaletteModal::buildRegistry()`
  which drops entries whose id starts with `dev.` for non-developers.
  Both filters apply; missing one is a defense-in-depth regression.
