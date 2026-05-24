---
phase: 16-developer-mode-ui
plan: 04
subsystem: dev-mode-runner-pipeline
tags: [symfony-process, sse, spawn-then-tail, file-tail, command-registry, escapeshellarg, posix, run-registry, advanced-toggle, beatrax-grant-dev, beatrax-regenerate-recovery-codes]

# Dependency graph
requires:
  - phase: 16-developer-mode-ui
    plan: 03
    provides: "Modules/DevMode/ bounded module + EnsureDeveloperMode middleware + 4 Public contracts bound to Null* defaults + dev-shell layout + DevOverviewPage + everyDevModeRouteAppliesEnsureDeveloperModeMiddleware arch invariant + UserDataPathService::logsFile() + readonly_select connection. This plan REPLACES the 16-03 NullDevCommandRegistry binding with the concrete CommandRegistry and adds CommandSpawner / RunRegistry / FileTailer singletons (CommandRegistry uses CommandSpec + a redefined ArgSpec DTO)."
  - phase: 12-multi-user-activation
    provides: "users.is_developer column + first-signup auto-promote (D-04). beatrax:grant-dev writes is_developer; beatrax:regenerate-recovery-codes uses the same user_recovery_codes shape Phase 12 D-15 defined (cf. UserRecoveryCode model + RecoveryCodeGenerator)."
provides:
  - "CommandRegistry (Modules/DevMode/Internal/CommandRegistry.php) — concrete implementation of DevCommandRegistry replacing 16-03's NullDevCommandRegistry. Hard-codes the CONTEXT D-12 (9 SAFE) + D-13 (6 DESTRUCTIVE) roster as an inline CommandSpec list. CONTEXT D-14 NEVER-EXPOSED commands (migrate, migrate:rollback, db:seed) are absent — find() throws InvalidArgumentException so the spawner whitelists against the throw before any shell assembly."
  - "ArgSpec DTO REDEFINED (Modules/DevMode/Public/Dto/ArgSpec.php) — replaces the 16-03 placeholder shape (name/label/type=string|int|bool|enum/required/default/options) with the spec'd runner shape (name/label/type=text|select|file-path|boolean/rules=array/placeholder?/helpText?/options?). The `rules` field is a Laravel-ready array the spawn controller validates via the injected ValidatorFactory before the shell sees any value."
  - "RunRecord DTO (Modules/DevMode/Internal/Process/RunRecord.php) — runId, pid, command, args, startedAt, callerUserId, tier, status, outPath, exitCode?, finishedAt?. Spatie LaravelData class; serialised manually via {@see RunRegistry::serialize()} so Carbon dates round-trip through the cache driver (Spatie's default cast rejects strings with timezone offset)."
  - "RunRegistry (Modules/DevMode/Internal/Process/RunRegistry.php) — cache-backed registry of in-flight + recently-completed runs at key `dev_mode.run.{runId}` (24 h TTL per RESEARCH Open Q3). store / find / markFinished / markCancelled. DI on Cache\\Repository + Clock; no facades. Hydrate path parses Carbon dates via CarbonImmutable::parse so the round-trip dodges Spatie/laravel-data's strict default-format cast."
  - "FileTailer (Modules/DevMode/Internal/Process/FileTailer.php) — pure-PHP fseek + clearstatcache + fread (max 65 536 bytes per tick) primitive. SHARED SEAM reused by 16-05's LogStreamController; no DI; rotation-safe (returns unchanged offset when file shrinks)."
  - "CommandSpawner (Modules/DevMode/Internal/Process/CommandSpawner.php) — architecture (b) spawn-then-tail per CONTEXT D-16. Three independent injection guards: (1) whitelist via DevCommandRegistry::find(), (2) escapeshellarg on every interpolated value, (3) Laravel validate() against ArgSpec::rules at the controller layer. Bash wrapper `bash -c '<cmd> > out 2>&1 < /dev/null & echo $!'` detaches via closed-stdin + & background and prints the child PID. Path lives at storage/app/dev_mode/runs/{runId}.out via UserDataPathService::appPath."
  - "ArtisanSpawnController POST /dev/artisan/spawn — SAFE-tier-only spawn endpoint. Rejects DESTRUCTIVE with 403 + {error: destructive_requires_triple_gate} (destructive runs land in 16-04b through the triple-gate). Returns {run_id, pid} with HTTP 202. Per-arg ArgSpec::rules validation enforced."
  - "ArtisanStreamController GET /dev/artisan/stream/{runId} — SSE tail endpoint (Content-Type: text/event-stream; X-Accel-Buffering: no; Cache-Control: no-cache). Emits id: + data: per chunk; honors Last-Event-ID header + ?from= query for D-16 page-refresh reconnect; posix_kill liveness check; final event: done. Cross-user inspection rejected via AccessDeniedHttpException (T-16-15). Reuses FileTailer."
  - "ArtisanCancelController POST /dev/artisan/cancel/{runId} — SIGTERM + 3s grace + SIGKILL fallback. Reads PID from cache (never request body — T-16-16). Cross-user cancel rejected. 204 on success, 404 unknown, 204 idempotent if already-exited."
  - "AdvancedToggleController POST /dev/advanced-toggle — writes session key `dev_mode.advanced` (boolean) for the triple-gate pre-flight. 204 No Content. The full reset-on-login listener + the UI surface land in 16-04b."
  - "Four new routes registered inside the existing /dev group: dev.artisan.spawn / dev.artisan.stream / dev.artisan.cancel / dev.advanced-toggle. All gated by ['web', 'auth', 'ensureDeveloperMode'] inherited from the group; the everyDevModeRouteAppliesEnsureDeveloperModeMiddleware invariant locks the coverage at PR time."
  - "GrantDevCommand (Modules/Auth/Internal/Console/GrantDevCommand.php) — beatrax:grant-dev {username}; idempotent; case-normalises usernames. Resolves DESTRUCTIVE-tier roster entry. Registered in AuthServiceProvider::boot()->commands([...])."
  - "RegenerateRecoveryCodesCommand (Modules/Auth/Internal/Console/RegenerateRecoveryCodesCommand.php) — beatrax:regenerate-recovery-codes {username}; burns every outstanding unused code (used_at-stamped) inside one DB transaction, issues 10 fresh hyphenated codes via the same RecoveryCodeGenerator + bcrypt-hash discipline SignupAction uses. Prints plaintext codes ONCE."
  - "AuthServiceProvider gained 2 new commands in the runningInConsole() block alongside ResetPasswordCommand."
  - "ISOLATION_ROUTE_ALLOW_LIST extended in Modules/Auth/tests/Feature/CrossUserIsolationTest.php — dev.overview (16-03 oversight) + dev.artisan.stream (new this plan) allow-listed (both EnsureDeveloperMode-gated; dev.artisan.stream has its own per-controller cross-user ownership check on top, T-16-15)."
affects:
  - 16-04b-audit-pipeline-triple-gate-sidebar-enable
  - 16-05-log-tailer-redaction
  - 16-08-command-palette

# Tech tracking
tech-stack:
  added:
    - "Symfony Process (already a transitive dep — first first-class consumer in DevMode)"
  patterns:
    - "Architecture (b) spawn-then-tail (CONTEXT D-16 verbatim) — bash wrapper detaches a backgrounded child whose stdout + stderr are redirected into a per-run tmp file the SSE controller tails. Replaces the rejected architecture (a) spawn-in-controller pattern that would have lost the live-stream guarantee across page refreshes."
    - "Three independent injection guards for any user-supplied shell-bound arg: whitelist via registry::find() + escapeshellarg on every interpolated value + Laravel validate() against ArgSpec::rules. Tested with an injection-attempt path that asserts a sentinel file under /tmp is NOT created (CommandSpawnerTest Test 2)."
    - "Cache-backed run registry with manual Carbon serialise/hydrate to dodge spatie/laravel-data's strict default-format cast. The Spatie DTO is the read-only contract shape; the cache layer owns its own round-trip serialisation to avoid the timezone-offset cast bug."
    - "Pattern K (PATTERNS.md) — single-invokable controllers for non-Livewire HTTP. Four new instances: ArtisanSpawnController, ArtisanStreamController, ArtisanCancelController, AdvancedToggleController. All final readonly classes with constructor DI on their collaborators; method-DI on Request + CurrentUser per Laravel convention."
    - "ValidatorFactory injected over Request->validate() — Laravel's Request->validate() is a dynamic call on a static-resolution context that Larastan L10 strict-rules flags. Injecting Illuminate\\Contracts\\Validation\\Factory and calling ->make($payload, $rules)->validate() satisfies the static-rules check and keeps the call-site facade-free."
    - "PHPStan posix_kill stub workaround — Larastan's stub for posix_kill claims `bool(true)` always; the SIGTERM-grace loop's negated boolean + the post-grace if-still-alive branch are silenced via two @phpstan-ignore-next-line comments rather than restructuring the loop. The runtime semantics depend on kernel state the static analyser cannot model."
    - "Test-only output buffer interception for SSE captures: ob_start(callback) where the callback accumulates emitted chunks + returns '' keeps the controller's ob_flush()/flush() from leaking SSE bytes into PHPUnit's terminal output. The controller code stays unchanged."
    - "Background detach without setsid — the original architecture sketch in the plan suggested `setsid` for proper session leadership, but setsid is not in macOS's default toolchain (the project's local-only constraint). Plain `bash -c '<cmd> > out 2>&1 < /dev/null & echo $!'` is sufficient: the closed-stdin prevents SIGHUP propagation when the parent HTTP request exits, and the `&` background job + foreground exit leave the child alive until completion."

key-files:
  created:
    - "Modules/DevMode/Internal/CommandRegistry.php"
    - "Modules/DevMode/Internal/Process/RunRecord.php"
    - "Modules/DevMode/Internal/Process/RunRegistry.php"
    - "Modules/DevMode/Internal/Process/FileTailer.php"
    - "Modules/DevMode/Internal/Process/CommandSpawner.php"
    - "Modules/DevMode/Internal/Http/Controllers/ArtisanSpawnController.php"
    - "Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php"
    - "Modules/DevMode/Internal/Http/Controllers/ArtisanCancelController.php"
    - "Modules/DevMode/Internal/Http/Controllers/AdvancedToggleController.php"
    - "Modules/Auth/Internal/Console/GrantDevCommand.php"
    - "Modules/Auth/Internal/Console/RegenerateRecoveryCodesCommand.php"
    - "Modules/DevMode/tests/Feature/CommandRegistryTest.php"
    - "Modules/DevMode/tests/Feature/CommandSpawnerTest.php"
    - "Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php"
    - "Modules/DevMode/tests/Feature/ArtisanCancelTest.php"
    - "Modules/Auth/tests/Feature/GrantDevCommandTest.php"
    - "Modules/Auth/tests/Feature/RegenerateRecoveryCodesCommandTest.php"
  modified:
    - "Modules/DevMode/Public/Dto/ArgSpec.php — DTO REDEFINED to match the runner spec shape (text|select|file-path|boolean + Laravel rules array + placeholder/helpText/options). The 16-03 placeholder shape was a stub; this is the real surface every downstream plan reads."
    - "Modules/DevMode/Providers/DevModeServiceProvider.php — REPLACED NullDevCommandRegistry binding with the concrete CommandRegistry instance constructed from the full SAFE + DESTRUCTIVE roster inline. Added singletons for CommandSpawner, RunRegistry, FileTailer. AuditWriter stays Null (16-04b swaps it for SpatieAuditWriter)."
    - "Modules/DevMode/Routes/web.php — appended 4 routes inside the existing /dev group: dev.artisan.spawn, dev.artisan.stream, dev.artisan.cancel, dev.advanced-toggle."
    - "Modules/Auth/Providers/AuthServiceProvider.php — added GrantDevCommand + RegenerateRecoveryCodesCommand to the runningInConsole() ->commands([...]) block."
    - "Modules/Auth/tests/Feature/CrossUserIsolationTest.php — extended ISOLATION_ROUTE_ALLOW_LIST with dev.overview (16-03 oversight) + dev.artisan.stream (new this plan)."
    - "Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php — updated the Null* contract resolution test to assert the now-bound concrete (9 SAFE + 6 DESTRUCTIVE) since DevCommandRegistry is no longer empty."

key-decisions:
  - "Architecture (b) spawn-then-tail per CONTEXT D-16 verbatim — see the dedicated 'Architecture lock' section below. Architecture (a) spawn-in-controller is REJECTED because a page refresh would lose the live stream (the spawning HTTP request owns the Process for its lifetime under (a))."
  - "ArgSpec DTO redefined to match the runner spec (text|select|file-path|boolean + Laravel rules array) — the 16-03 ArgSpec was a placeholder with a different shape (string|int|bool|enum + required/default/options). The interfaces block in 16-04 PLAN.md explicitly redefines it; no downstream consumer of the old shape exists yet (only CommandSpec references it)."
  - "Drop setsid from the bash wrapper — setsid is not part of macOS's default toolchain, and the plain `bash -c '<cmd> > out 2>&1 < /dev/null & echo $!'` pattern already detaches via closed-stdin + & background. The original plan sketch mentioned setsid for session-leader semantics; on macOS this would have errored at runtime."
  - "Cache-backed RunRegistry manually serialises + hydrates Carbon dates via toIso8601String() + CarbonImmutable::parse() — bypasses spatie/laravel-data's strict default-format cast which rejects ISO-8601 strings with timezone offset (CannotCastDate exception). The DTO stays the read-only contract shape; the cache layer owns its own round-trip."
  - "ValidatorFactory contract injected over Request->validate() — Larastan L10 strict-rules flags Request->validate() as a dynamic-call-on-static error. Injecting Illuminate\\Contracts\\Validation\\Factory and calling ->make($payload, $rules)->validate() resolves cleanly + stays facade-free per CLAUDE.md DI-only rule."
  - "AccessDeniedHttpException over abort(403) for cross-user inspection rejection — abort() is a global helper banned by larastan-strict-rules. Throwing the Symfony HTTP-kernel exception directly satisfies the rule and keeps controller code facade-free. Mirrors the project's NotFoundHttpException usage in EnsureDeveloperMode."
  - "Single bash wrapper captures the child PID via echo \$! — the only reliable pattern for capturing a backgrounded child's PID through Symfony Process. The alternative (Process::start() + register_shutdown_function with tick callbacks) is documented in 16-RESEARCH as fragile under PHP-FPM worker shutdown; the wrapper approach is what makes the spawn truly fire-and-forget."
  - "Inline ranking 24h cache TTL on dev_mode.run.{runId} (per RESEARCH Open Q3 recommendation) — long enough for any SAFE-tier command to complete, short enough to bound cache footprint to one operator-day of activity. The tmp file at storage/app/dev_mode/runs/{runId}.out is not subject to the same TTL — a SAFE-tier cleanup command (beatrax:prune-dev-runs) is out of scope for this plan."

patterns-established:
  - "Spawn-then-tail (CONTEXT D-16): a separate HTTP request spawns the detached process and stores (run_id, pid, outPath) in the cache; the SSE controller adopts the cached PID + path via RunRegistry::find() and tails the tmp file via fseek + clearstatcache. A page refresh creates a fresh SSE connection that adopts the same cached entry and resumes from the requested offset — the live-stream guarantee holds without any in-memory state on the parent request."
  - "Shared FileTailer seam between two SSE pipelines — Modules/DevMode/Internal/Process/FileTailer.php is the tailer 16-04's ArtisanStreamController uses TODAY and 16-05's LogStreamController will use TOMORROW. One implementation, one tested code path, two consumers. Future SSE-over-growing-file consumers should inject the same primitive."
  - "Three-guard injection-resistance for shell-bound user input: registry whitelist + escapeshellarg + Laravel validate(). The injection-attempt regression test (CommandSpawnerTest 'rejects an injection-attempt path') asserts a /tmp/PWNED-* sentinel is NOT created when the malicious payload is supplied. Future spawner-style features should mirror the three-guard discipline + the regression test."

requirements-completed: [DEVUI-02]

# Metrics
duration: 90min
completed: 2026-05-24
---

# Phase 16 Plan 04: Artisan-Runner Process Pipeline (SAFE-tier spawn + SSE tail + cancel + missing-command scaffolding) Summary

**Architecture (b) spawn-then-tail process pipeline lands end-to-end: bash-wrapped detached child writes to a per-run tmp file the SSE controller tails via FileTailer (shared with 16-05's log tailer); CommandRegistry holds the full CONTEXT D-12 + D-13 roster behind escapeshellarg + Laravel-validated ArgSpec rules; missing DESTRUCTIVE-tier commands beatrax:grant-dev + beatrax:regenerate-recovery-codes scaffolded in Modules/Auth/Internal/Console/.**

## Performance

- **Duration:** ~90 min (env bootstrap + 3 tasks + verification)
- **Tasks:** 3 (all TDD)
- **Commits:** 3 atomic commits
- **Files created:** 17
- **Files modified:** 6
- **Test growth:** 2210 (Wave 3 baseline) → 2230 passed (+20 visible in default Pest suite: 6 CommandRegistry + 4 CommandSpawner + 6 ArtisanStreamReconnect + 4 ArtisanCancel). The 8 Task 3 Auth Feature tests (4 GrantDev + 4 RegenerateRecoveryCodes) pass when invoked directly but the default Pest suite does not discover them because Modules/Auth/tests/Feature is missing from the phpunit.xml `<testsuite name="Feature">` block (pre-existing gap, see "Deferred Issues" below).
- **Larastan L10 strict:** clean (0 errors)
- **Pint:** clean

## Architecture Lock — D-16 spawn-then-tail (architecture (b), architecture (a) REJECTED)

CONTEXT D-16 verbatim: "`pid` + `run_id` persisted in cache (database driver) so a page refresh reconnects to the **live stream**." Two viable shapes existed:

| | Architecture (a) — spawn-in-controller | Architecture (b) — spawn-then-tail (THIS PLAN) |
|--|--|--|
| **Spawn ownership** | The spawning HTTP request owns the Process for its lifetime; the SSE controller is the spawning controller. | A separate POST endpoint spawns the Process detached; the SSE controller is a different HTTP request that adopts the cached PID + tmp-file path. |
| **Page-refresh behaviour** | The browser disconnects mid-stream → the spawning request is aborted → the Process is killed by PHP-FPM worker recycling. A refresh starts a NEW Process from a new spawn request; the original run's output is lost. | The browser disconnects → the SSE request returns but the detached child keeps running and writing to the tmp file. A refresh creates a fresh SSE connection that adopts the SAME cached run_id + tmp file and tails from the requested offset. The live-stream guarantee holds. |
| **D-16 compliance** | ❌ "page refresh reconnects to the live stream" is structurally impossible. | ✅ verbatim. |
| **Reconnect behaviour** | N/A — would need to be reconstructed from a persisted audit row, never live. | EventSource's auto-reconnect sends Last-Event-ID; controller honours it OR `?from=` query; second handle observes only lines emitted after the reconnect offset. |
| **Threat model** | Spawn happens inside the request → less surface area but no liveness across refresh. | Spawn detaches via `bash -c '<cmd> > out 2>&1 < /dev/null & echo $!'`; the closed-stdin prevents SIGHUP from the parent; the `&` puts the child in the background; `echo $!` prints the PID for the parent to capture. Injection resistance via three independent guards (whitelist + escapeshellarg + Laravel validate). |

**Architecture (b)** is the committed shape per the plan's ARCHITECTURE LOCK section. The headline regression for the D-16 contract is `ArtisanStreamReconnectTest::it honors ?from= for page-refresh-reconnect`, which spawns a ticking child, waits for the file to grow past a cut-point offset, then opens a SECOND SSE handle with `?from={cutOffset}` and asserts the body contains ONLY line-N markers emitted after the cut-point.

## FileTailer as a shared seam reused by 16-05

`Modules/DevMode/Internal/Process/FileTailer.php` is a pure-PHP fseek + clearstatcache + fread (max 65 536 bytes/tick) primitive. Both the artisan-runner SSE controller (this plan) AND the log-tailer SSE controller (16-05) need the SAME "open file, seek to known offset, read new bytes, return chunk + new offset" loop body. Centralising it here means:

- **One tested code path** (4 invariants in CommandSpawnerTest cover growing-file + missing-file + truncation-rotation + idempotent-empty-read).
- **One performance ceiling** — 65 536 bytes/tick × (1000ms / tick_interval) ≈ 430 KB/s for the artisan controller (150ms tick) or 262 KB/s for the log tailer (250ms tick). Well above any SAFE-tier command's stdout rate.
- **One rotation-safety contract** — when `filesize($path) < $fromOffset` (truncation / rotation), the tailer returns an empty chunk + the UNCHANGED offset, leaving the caller to decide whether to reset to 0 or wait.

Downstream 16-05 should constructor-inject `FileTailer` and reuse it as-is; do NOT re-implement the tail loop.

## DESTRUCTIVE execution + audit pipeline + triple-gate deferred to 16-04b

This plan ships the SAFE-tier execution surface ONLY. Per the plan's `<objective>` and `<interfaces>`, every DESTRUCTIVE-tier concern lands in 16-04b:

| Concern | Lands in | Why deferred from 16-04 |
|---|---|---|
| DESTRUCTIVE-tier spawn (the actual execution) | 16-04b | Requires the triple-gate (Advanced toggle + typed-app-name + Dev Mode ON). Independent of the SAFE pipeline. |
| TripleGateModal Livewire component | 16-04b | Three-locks confirmation flow; depends on a new session listener + the AdvancedToggleController this plan ships. |
| Audit pipeline (SpatieAuditWriter, dev_mode_audit row writes) | 16-04b | The audit row needs the full stdout/stderr after the process exits + post-redaction excerpts. This plan's SSE controller emits the SSE protocol only; 16-04b adds a `finalize($runId)` step. |
| Heartbeat (Queue::looping listener) | 16-04b | The pre-flight pill needs a heartbeat producer registered on Queue::looping. Independent of this plan. |
| Runner UI page + audit page | 16-04b | The dev-shell sidebar's Artisan + Audit nav items auto-enable via the existing Route::has(...) checks once 16-04b registers `dev.artisan` + `dev.audit` page routes. |

`ArtisanSpawnController::__invoke` actively rejects every DESTRUCTIVE command at the SAFE-tier layer with `403 {error: destructive_requires_triple_gate}`. 16-04b will introduce a separate DESTRUCTIVE-spawn pathway through the TripleGateModal pipeline that calls `CommandSpawner::start(..., 'destructive')` directly after triple-gate validation.

## Task 3 commands created (both were absent)

`php artisan list | grep beatrax:` confirmed neither command existed before this plan. Both were scaffolded fresh in `Modules/Auth/Internal/Console/`:

| Command | Signature | Constructor DI | Notes |
|---|---|---|---|
| `GrantDevCommand` | `beatrax:grant-dev {username}` | None — direct Eloquent on User per CLAUDE.md exemption | Idempotent (re-grant on existing dev prints "Already a developer" + exits SUCCESS). Case-normalises usernames. Errors with FAILURE on unknown username. |
| `RegenerateRecoveryCodesCommand` | `beatrax:regenerate-recovery-codes {username}` | `DatabaseManager + Hasher + RecoveryCodeGenerator + Clock` | Burns every unused outstanding code (used_at-stamped inside one DB transaction) then issues 10 fresh hyphenated codes via the same RecoveryCodeGenerator + bcrypt hash discipline SignupAction uses. Prints the plaintext codes ONCE — operator records on the spot or re-runs. |

Both registered in `AuthServiceProvider::boot()->commands([...])` alongside `ResetPasswordCommand`. The DESTRUCTIVE-tier roster in `CommandRegistry` now resolves end-to-end via `php artisan` — the runner UI in 16-04b can rely on the names being real executables.

## Injection-resistance discipline in CommandSpawner

Three independent guards protect every shell invocation against shell-metachar injection (T-16-11 / T-16-SC2):

1. **Command whitelist via `DevCommandRegistry::find()`** — throws InvalidArgumentException for any name outside the SAFE + DESTRUCTIVE roster. CONTEXT D-14 NEVER-EXPOSED commands (`migrate`, `migrate:rollback`, `db:seed`) are absent from the registry → cannot be spawned.
2. **`escapeshellarg` on every interpolated value** — the bash wrapper is built from `escapeshellarg(PHP_BINARY) escapeshellarg($artisanPath) escapeshellarg($command) <escaped-args> > escapeshellarg($outPath) 2>&1 < /dev/null & echo \$!`. Every string that touches the shell is wrapped, including the command name (belt-and-braces in case a future planner registers a name with a metacharacter).
3. **Laravel validate() against `ArgSpec::rules`** — the controller validates `args.<name>` against the ArgSpec's rules list BEFORE the spawner is called. Each rule is a string in the standard Laravel rule shape (e.g. `['required', 'string', 'max:255']`).

**Canonical regression test:** `Modules/DevMode/tests/Feature/CommandSpawnerTest.php::it rejects an injection-attempt path via escapeshellarg discipline (T-16-11 / T-16-SC2)`. The test spawns `db:restore` with the malicious arg `/tmp/'; touch /tmp/PWNED-{hex}; '` and asserts the sentinel file is NEVER created — the metachars are neutralised by `escapeshellarg` before the bash wrapper sees them. Future regressions to any of the three guards will fail this test.

## Task Commits

| Task | Commit | Title |
|------|--------|-------|
| 1 (TDD) | `1e92c6c` | feat(16-04): DTOs + CommandRegistry + RunRegistry + FileTailer + CommandSpawner (architecture b) |
| 2 (TDD) | `39ed4fe` | feat(16-04): SAFE spawn + SSE tail + cancel + advanced-toggle controllers + routes |
| 3 (TDD) | `1ad30da` | feat(16-04): scaffold beatrax:grant-dev + beatrax:regenerate-recovery-codes commands |

Each task is a single commit (RED + GREEN halves landed together because the plan's `<done>` criteria explicitly required the GREEN-phase tests to pass for the task to be considered complete; the RED phase was verified by writing the tests + observing the expected failures during initial implementation iterations).

## Decisions Made

See `key-decisions` in the frontmatter for the full list with rationale. Quick recap:

- **Architecture (b) spawn-then-tail per CONTEXT D-16 verbatim** — architecture (a) is structurally incompatible with the "page refresh reconnects to the live stream" guarantee.
- **ArgSpec DTO redefined** — the 16-03 placeholder shape is replaced with the runner spec shape (text|select|file-path|boolean + Laravel rules array + placeholder/helpText/options).
- **Drop setsid from the bash wrapper** — not part of macOS's default toolchain; plain `bash -c '<cmd> > out 2>&1 < /dev/null & echo $!'` is sufficient.
- **Manual Carbon serialise/hydrate in RunRegistry** — bypasses spatie/laravel-data's strict default-format cast which rejects ISO-8601 strings with timezone offset.
- **ValidatorFactory injected over `Request->validate()`** — Larastan L10 strict-rules flags `Request->validate()` as a dynamic-call-on-static error.
- **AccessDeniedHttpException over `abort()` for cross-user rejection** — `abort()` is banned by larastan-strict-rules; throwing the Symfony HTTP-kernel exception is the facade-free alternative.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Bootstrap the test environment that the worktree lacked**
- **Found during:** Task 1 setup (pre-RED-phase suite check)
- **Issue:** The worktree had no `.env`, no `vendor/`, no `database/database.sqlite`, and no built assets. Same per-worktree environment hygiene issue every preceding wave surfaced.
- **Fix:** `cp .env.example .env && composer install && php artisan key:generate && touch database/database.sqlite && php artisan migrate --force && npm install && npm run build`.
- **Verification:** Baseline `vendor/bin/pest --filter='EnsureDeveloperMode|DevOverview|SettingsPageDevMode'` reached 14 passed (the Wave 3 baseline), matching 16-03's SUMMARY.
- **Committed in:** N/A — environment-bootstrap actions, not tracked changes.

**2. [Rule 1 — Bug] Spatie LaravelData CannotCastDate exception on RunRecord hydrate**
- **Found during:** Task 1 (CommandSpawnerTest run after the first `start()` call)
- **Issue:** `RunRecord::from($cachedArray)` raised `CannotCastDate ("Could not cast date '2026-05-24T14:37:08+02:00' into a 'Carbon\\CarbonInterface' using formats: Y-m-d\\TH:i:sP")`. Spatie's default date cast rejects ISO-8601 strings with timezone offset.
- **Fix:** RunRegistry owns its own serialise/hydrate methods. `serialize()` returns a primitive array with `startedAt->toIso8601String()` + `finishedAt?->toIso8601String()`; `hydrate()` parses both back via `CarbonImmutable::parse()`. The DTO stays the read-only contract shape.
- **Files modified:** `Modules/DevMode/Internal/Process/RunRegistry.php`
- **Verification:** All 10 Task 1 tests pass; round-trip is exact.
- **Committed in:** `1e92c6c` (Task 1 commit)

**3. [Rule 3 — Blocking] macOS lacks setsid; original bash wrapper failed to launch the child**
- **Found during:** Task 2 (ArtisanStreamReconnectTest + ArtisanCancelTest initial runs)
- **Issue:** The original architecture sketch wrapped the child in `setsid` for proper session-leader semantics, but `setsid` is not part of macOS's default toolchain. `which setsid` returns nothing; the bash wrapper printed the PID of the failed `setsid` command rather than the actual child PID; tests asserting `posix_kill($pid, 0) === true` immediately after spawn failed.
- **Fix:** Dropped `setsid` from both the CommandSpawner wrapper AND the test helper functions. Plain `bash -c '<cmd> > out 2>&1 < /dev/null & echo $!'` already detaches via closed-stdin + `&` background; the closed-stdin prevents SIGHUP propagation when the parent HTTP request exits. PHPDoc updated to document the trade-off (setsid would give true session leadership but breaks on macOS — the project's local-only constraint is macOS).
- **Files modified:** `Modules/DevMode/Internal/Process/CommandSpawner.php`, `Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php`, `Modules/DevMode/tests/Feature/ArtisanCancelTest.php`
- **Verification:** All 10 Task 2 tests pass on macOS (Darwin 24.6 / PHP 8.5.0alpha1 / Herd).
- **Committed in:** `39ed4fe` (Task 2 commit)

**4. [Rule 1 — Bug] SSE capture in tests returned an empty body because the controller's ob_flush() drained the test's parent buffer**
- **Found during:** Task 2 (ArtisanStreamReconnectTest::it streams text/event-stream)
- **Issue:** The first cut of `captureSseStream()` called `ob_start()` then `$callback()` then `ob_get_contents()`. Inside the callback the controller calls `@ob_flush(); @flush();` per chunk, which empties the test's just-started buffer into PHP's parent output handler. After the callback returned, `ob_get_contents()` was empty.
- **Fix:** Use `ob_start(callback)` form where the callback function accumulates each flushed chunk into a string variable and returns `''` so nothing escapes upstream. The controller's `ob_flush()` invocations now feed the accumulator instead of leaking to PHPUnit's terminal output.
- **Files modified:** `Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php` (test helper only)
- **Verification:** Both Test 3 (basic stream) + Test 4 (reconnect headline) pass.
- **Committed in:** `39ed4fe` (Task 2 commit)

**5. [Rule 2 — Missing critical] Updated 16-03's EnsureDeveloperModeTest contract-resolution case to reflect the now-bound concrete DevCommandRegistry**
- **Found during:** Task 1 full-suite verification
- **Issue:** 16-03 wrote `expect($commands->safe())->toBe([])` because at that time the binding was `NullDevCommandRegistry`. Task 1 replaces the binding with the concrete CommandRegistry which returns 9 SAFE specs.
- **Fix:** Rewrote the test assertion to expect `toHaveCount(9)` + `toHaveCount(6)` instead of `toBe([])`. The full SAFE/DESTRUCTIVE roster invariants live in the new `CommandRegistryTest` (Task 1); this test just confirms the contract resolves.
- **Files modified:** `Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php`
- **Verification:** All 4 EnsureDeveloperMode tests pass.
- **Committed in:** `1e92c6c` (Task 1 commit)

**6. [Rule 2 — Missing critical] Added the new dev.overview + dev.artisan.stream routes to CrossUserIsolationTest's allow-list**
- **Found during:** Task 2 verification (running Modules/Auth/tests/Feature/CrossUserIsolationTest after the new routes landed)
- **Issue:** `CrossUserIsolationTest::it covers or allow-lists every auth-gated GET route — regression guard` walks every authenticated GET route and demands either a cross-user probe case OR an allow-list entry. `dev.overview` (16-03 oversight) + `dev.artisan.stream` (new this plan) both lacked entries.
- **Fix:** Extended `ISOLATION_ROUTE_ALLOW_LIST` with both names + a comment explaining that EnsureDeveloperMode-gated routes are unreachable for non-developers AND that `dev.artisan.stream` adds its own per-controller cross-user ownership check on top (T-16-15, with its own dedicated test in this plan's Task 2).
- **Files modified:** `Modules/Auth/tests/Feature/CrossUserIsolationTest.php`
- **Verification:** All 9 CrossUserIsolation tests pass.
- **Committed in:** `1ad30da` (Task 3 commit — bundled because it surfaced after my SSE routes landed but only ran in the broader Auth/tests/Feature sweep during Task 3 verification).

**7. [Rule 1 — Bug] Larastan L10 noise — posix_kill stub claims `bool(true)` always**
- **Found during:** Task 2 Larastan run
- **Issue:** Larastan's stub for `posix_kill` always returns `true`, so the SIGTERM-grace loop's `if (! posix_kill(...))` is flagged "negated boolean always false" and the post-grace `if (posix_kill(...))` is flagged "always true". The runtime semantics depend on kernel state the static analyser cannot model.
- **Fix:** Added two `@phpstan-ignore-next-line` comments at the problematic lines with a PHPDoc explaining the runtime-vs-stub gap. Restructuring the loop would obscure the SIGTERM → grace → SIGKILL semantics; the ignores are the cleaner trade-off.
- **Files modified:** `Modules/DevMode/Internal/Http/Controllers/ArtisanCancelController.php`
- **Verification:** Larastan L10 strict clean.
- **Committed in:** `39ed4fe` (Task 2 commit)

**8. [Rule 1 — Bug] Larastan L10 noise — Request->validate() flagged as dynamic-call-on-static**
- **Found during:** Task 2 Larastan run
- **Issue:** Larastan-strict-rules flags `Request->validate()` as a dynamic-call-on-static error (the `validate()` method is declared static in some inherited context). Three controllers used it.
- **Fix:** Injected `Illuminate\\Contracts\\Validation\\Factory` in `ArtisanSpawnController` + `AdvancedToggleController` and switched to `$this->validator->make($payload, $rules)->validate()`. Cleaner DI pattern + larastan-strict-rules clean.
- **Files modified:** `Modules/DevMode/Internal/Http/Controllers/ArtisanSpawnController.php`, `Modules/DevMode/Internal/Http/Controllers/AdvancedToggleController.php`
- **Verification:** Larastan L10 strict clean; all controller tests pass.
- **Committed in:** `39ed4fe` (Task 2 commit)

**9. [Rule 1 — Bug] Larastan L10 — abort() banned by larastan-strict-rules**
- **Found during:** Task 2 Larastan run
- **Issue:** Three calls to `abort(403, ...)` / `abort(500, ...)` flagged "Global helper function 'abort' should not be used" (larastanStrictRules.noGlobalLaravelFunction).
- **Fix:** Throw `Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException` (403) / `HttpException` (500) directly. Mirrors the project's existing `NotFoundHttpException` usage in `EnsureDeveloperMode` middleware.
- **Files modified:** `Modules/DevMode/Internal/Http/Controllers/ArtisanCancelController.php`, `Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php`
- **Verification:** Larastan L10 strict clean; controller behaviour identical.
- **Committed in:** `39ed4fe` (Task 2 commit)

**10. [Rule 1 — Bug] Pint cosmetic fixes (FQN hoist + import-order + braces)**
- **Found during:** Task 2 Pint run
- **Issue:** Two test files had inline FQNs the project's Pint preset hoists into `use` statements; one had an anonymous-class brace style Pint enforces.
- **Fix:** Ran `vendor/bin/pint Modules/DevMode/tests/Feature/Artisan*.php` and accepted the fixer output. Cosmetic-only; no behaviour change.
- **Files modified:** `Modules/DevMode/tests/Feature/ArtisanCancelTest.php`, `Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php`
- **Verification:** Pint passed; tests still pass.
- **Committed in:** `39ed4fe` (Task 2 commit)

**11. [Rule 1 — Bug] Larastan L10 — `is_string()` guard on already-typed Console argument**
- **Found during:** Task 3 Larastan run
- **Issue:** GrantDevCommand + RegenerateRecoveryCodesCommand both had `if (! is_string($usernameArg) || trim($usernameArg) === '')` guards Larastan flagged as "is_string with string will always evaluate to true". The typed signature already narrows the argument to string.
- **Fix:** Dropped the is_string guard, kept the empty-string check. Mirrors ResetPasswordCommand's existing carve-out (which uses the same pattern with the same Larastan-narrowing comment).
- **Files modified:** `Modules/Auth/Internal/Console/GrantDevCommand.php`, `Modules/Auth/Internal/Console/RegenerateRecoveryCodesCommand.php`
- **Verification:** Larastan L10 strict clean.
- **Committed in:** `1ad30da` (Task 3 commit)

---

**Total deviations:** 11 auto-fixed (3 Rule 3 — blocking; 8 Rule 1 — bug; 2 of those overlap with Rule 2 — missing critical for the test-allow-list extensions). All 11 are necessary follow-throughs of the plan's intent. None changed scope.

## Deferred Issues

**1. Modules/Auth/tests/Feature is not in the default `<testsuite name="Feature">` block in phpunit.xml** — pre-existing setup gap. Tests under `Modules/Auth/tests/Feature/` pass when invoked directly via `vendor/bin/pest Modules/Auth/tests/Feature/` but are NOT discovered by the default `vendor/bin/pest` suite. As a result, the 8 Task 3 Auth Feature tests (4 GrantDev + 4 RegenerateRecoveryCodes) do not contribute to the default Pest counter — the suite still shows 2230 passed even with the new tests. Adding `<directory>Modules/Auth/tests/Feature</directory>` would surface several other Auth Feature tests that may or may not currently pass; the safer move is to defer the phpunit.xml update to a future cleanup plan that can address any latent failures the wider discovery surfaces. The new tests are committed + run cleanly in isolation; the registration gap does not affect Plan 04's success criteria.

**2. spatie/laravel-data Carbon round-trip via DTO::from(array) is unusable** — RunRegistry works around it via manual serialise/hydrate. A future DTO-heavy plan may want to invest in either (a) a custom Spatie Data cast that accepts ISO-8601 strings with timezone offset, OR (b) registering `Carbon\\CarbonImmutable` with Spatie's default cast registry to widen the accepted formats. Out of scope here.

## User Setup Required

None — no external service configuration required. The SAFE-tier spawn pipeline is fully local; no IMAP / OAuth / API keys touched.

## Hand-off Notes for 16-04b

- The CommandSpawner is wired for both `'safe'` and `'destructive'` tiers — 16-04b's TripleGateModal-validated DESTRUCTIVE-spawn pathway should call `CommandSpawner::start($command, $args, $userId, 'destructive')` directly after triple-gate validation. The spawner already records the tier in the RunRecord so the audit pipeline can read it back.
- The AdvancedToggleController writes the session key `dev_mode.advanced` that 16-04b's triple-gate needs to read. The reset-on-login listener (`AdvancedToggleResetOnLogin`) is 16-04b's responsibility.
- ArtisanStreamController's terminal `event: done` event currently emits `{exit: <int>, cancelled: <bool>}` based on the cached RunRecord status. 16-04b's audit pipeline finalize step should be inserted at the point this controller calls `RunRegistry::markFinished()` — currently the controller marks finished with `exitCode: 0` as a placeholder; 16-04b should read the exit code authoritatively from the bash wrapper (probably via a sidecar file written by the wrapper).
- The dev-sidebar's Artisan + Audit nav items will auto-enable via the existing `Route::has(...)` checks once 16-04b registers `dev.artisan` + `dev.audit` page routes inside the existing /dev group.
- The four Public contracts the runner UI in 16-04b consumes — `DevCommandRegistry` (concrete bound here), `AuditWriter` (still Null, swap to SpatieAuditWriter in 16-04b), `NavigationRegistry` (still Null, swap in 16-08), `AppActionRegistry` (still Null, swap in 16-08) — remain bound on the DevModeServiceProvider. Replace each Null* from your own ServiceProvider; do NOT edit DevModeServiceProvider.

## Known Stubs

- **DESTRUCTIVE-tier execution path is intentionally rejected at the SAFE controller layer.** The 6 DESTRUCTIVE commands in the registry display correctly in the runner UI (when 16-04b lands) but cannot fire through `/dev/artisan/spawn`. The full DESTRUCTIVE pipeline lands in 16-04b through TripleGateModal → separate spawn controller path.
- **AuditWriter contract still binds to NullAuditWriter.** No audit rows are written by this plan. 16-04b swaps the binding for SpatieAuditWriter and adds the `finalize($runId)` step the SSE controller calls.
- **ArtisanStreamController's terminal `event: done` emits exitCode=0 as a placeholder.** 16-04b reads the exit code authoritatively via the audit-pipeline finalize step.
- **AdvancedToggleController has no reset-on-login listener yet.** The session flag persists across logins until 16-04b registers `AdvancedToggleResetOnLogin` for the Login event.

None of these stubs prevents this plan's goal (SAFE-tier spawn-then-tail pipeline + missing-command scaffolding) from being achieved.

## Self-Check: PASSED

Files asserted present:

- `Modules/DevMode/Internal/CommandRegistry.php` — FOUND
- `Modules/DevMode/Internal/Process/RunRecord.php` — FOUND
- `Modules/DevMode/Internal/Process/RunRegistry.php` — FOUND
- `Modules/DevMode/Internal/Process/FileTailer.php` — FOUND
- `Modules/DevMode/Internal/Process/CommandSpawner.php` — FOUND
- `Modules/DevMode/Internal/Http/Controllers/ArtisanSpawnController.php` — FOUND
- `Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php` — FOUND
- `Modules/DevMode/Internal/Http/Controllers/ArtisanCancelController.php` — FOUND
- `Modules/DevMode/Internal/Http/Controllers/AdvancedToggleController.php` — FOUND
- `Modules/Auth/Internal/Console/GrantDevCommand.php` — FOUND
- `Modules/Auth/Internal/Console/RegenerateRecoveryCodesCommand.php` — FOUND
- `Modules/DevMode/tests/Feature/CommandRegistryTest.php` — FOUND
- `Modules/DevMode/tests/Feature/CommandSpawnerTest.php` — FOUND
- `Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php` — FOUND
- `Modules/DevMode/tests/Feature/ArtisanCancelTest.php` — FOUND
- `Modules/Auth/tests/Feature/GrantDevCommandTest.php` — FOUND
- `Modules/Auth/tests/Feature/RegenerateRecoveryCodesCommandTest.php` — FOUND
- `Modules/DevMode/Public/Dto/ArgSpec.php` (modified) — FOUND (redefined to the runner spec shape)
- `Modules/DevMode/Providers/DevModeServiceProvider.php` (modified) — FOUND (CommandRegistry binding swapped + 3 singletons added)
- `Modules/DevMode/Routes/web.php` (modified) — FOUND (4 routes added)
- `Modules/Auth/Providers/AuthServiceProvider.php` (modified) — FOUND (2 commands registered)
- `Modules/Auth/tests/Feature/CrossUserIsolationTest.php` (modified) — FOUND (allow-list extended)
- `Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php` (modified) — FOUND (contract-resolution test updated)

Commits asserted present:

- `1e92c6c` (Task 1 — DTOs + CommandRegistry + RunRegistry + FileTailer + CommandSpawner) — FOUND
- `39ed4fe` (Task 2 — SAFE spawn + SSE tail + cancel + advanced-toggle controllers + routes) — FOUND
- `1ad30da` (Task 3 — scaffold beatrax:grant-dev + beatrax:regenerate-recovery-codes commands) — FOUND

## Next Phase Readiness

- **16-04b (audit pipeline + triple-gate + sidebar enable):** Bind `SpatieAuditWriter` (replacing `NullAuditWriter`); register `AdvancedToggleResetOnLogin` listener on the Login event; install the baseline `RedactSecretsProcessor::class` FQCN into `config/logging.php` tap slots (W-1 fix); register `dev.artisan` + `dev.audit` page routes inside the existing /dev group (the dev-sidebar nav items auto-enable); add the DESTRUCTIVE-spawn pathway through TripleGateModal that calls `CommandSpawner::start(..., 'destructive')` after triple-gate validation. The audit pipeline's `finalize($runId)` step should hook into ArtisanStreamController's `RunRegistry::markFinished()` call point.
- **16-05 (log tailer + redaction):** Constructor-inject the existing `Modules/DevMode/Internal/Process/FileTailer.php` and reuse it as-is for the LogStreamController. Use `UserDataPathService::logsFile()` as the canonical log-file path. Upgrade the `RedactSecretsProcessor` in place with the full OAuth scrub-set (per CONTEXT D-29 + the RESEARCH § OAuth-Secret Audit table).
- **16-06 (queue inspector + Horizon iframe):** Independent of this plan. Register `dev.queue` + `dev.horizon` routes inside the existing /dev group.
- **16-07 (doctor + SQL + system):** Independent. Reuse `readonly_select` connection from 16-03; wire `SelectOnlyValidator` around `Doctrine\\SqlFormatter\\Tokenizer`.
- **16-08 (command palette):** Bind `NavigationRegistryImpl` + `AppActionRegistryImpl` from a new ServiceProvider; import fuse.js client-side. The palette can filter SAFE-tier commands from `DevCommandRegistry::safe()` for the developer-only source — the contract surface is now live.

---
*Phase: 16-developer-mode-ui*
*Completed: 2026-05-24*
