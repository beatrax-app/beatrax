---
phase: 16-developer-mode-ui
plan: 04b
type: execute
wave: 4
depends_on: [16-04]
files_modified:
  - Modules/DevMode/Internal/Audit/SpatieAuditWriter.php
  - Modules/DevMode/Internal/Audit/RedactionExcerptCap.php
  - Modules/DevMode/Internal/Audit/FinalizeRunAudit.php
  - Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php
  - Modules/DevMode/Internal/Logging/PushRedactProcessor.php
  - Modules/DevMode/Internal/Services/DevModeFlag.php
  - Modules/DevMode/Internal/Http/Livewire/TripleGateModal.php
  - Modules/DevMode/Internal/Http/Livewire/ArtisanRunnerPage.php
  - Modules/DevMode/Internal/Http/Livewire/AuditLogPage.php
  - Modules/DevMode/Internal/Http/Controllers/DestructiveSpawnController.php
  - Modules/DevMode/Internal/Listeners/WriteWorkerHeartbeat.php
  - Modules/DevMode/Internal/Listeners/ResetAdvancedToggleOnLogin.php
  - Modules/DevMode/Internal/Console/PruneDevAuditCommand.php
  - Modules/DevMode/Internal/Enums/AuditEvent.php
  - Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php
  - Modules/DevMode/Routes/web.php
  - Modules/DevMode/Providers/DevModeServiceProvider.php
  - config/logging.php
  - Modules/DevMode/Resources/views/layouts/dev-shell.blade.php
  - Modules/DevMode/Resources/views/livewire/triple-gate-modal.blade.php
  - Modules/DevMode/Resources/views/livewire/artisan-runner-page.blade.php
  - Modules/DevMode/Resources/views/livewire/audit-log-page.blade.php
  - Modules/DevMode/Resources/views/components/run-card.blade.php
  - Modules/DevMode/Resources/views/components/status-pill.blade.php
  - Modules/DevMode/Resources/views/components/tier-chip.blade.php
  - Modules/DevMode/tests/Feature/TripleGateTest.php
  - Modules/DevMode/tests/Feature/AuditLogWriteTest.php
  - Modules/DevMode/tests/Feature/WorkerHeartbeatTest.php
  - Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php
  - Modules/DevMode/tests/Feature/DestructiveTripleGateRoundTripTest.php
  - Modules/DevMode/tests/Unit/RedactSecretsProcessorBaselineTest.php
autonomous: true
requirements: [DEVUI-02, DEVUI-03]
mvp_mode: true

must_haves:
  truths:
    - "A SAFE run started via 16-04's POST /dev/artisan/spawn writes a dev_mode_audit row when the SSE controller observes process-gone (FinalizeRunAudit hook called from the stream controller's done branch)"
    - "Triple-gate (Dev Mode ON + Advanced ON + typed beatrax) is enforced server-side in TripleGateModal::confirm() via three independent checks; on success the DestructiveSpawnController is invoked to spawn the DESTRUCTIVE command (NOT through 16-04's SAFE-only spawn controller)"
    - "Every command run writes a dev_mode_audit row via spatie/laravel-activitylog ^5.0 with the D-24 row shape (excerpts capped at 8KB + basic Bearer+JWT redaction via RedactionExcerptCap; full OAuth scrub-set added by 16-05)"
    - "The Queue::looping listener writes dev_mode.queue_worker_heartbeat to cache on every worker tick via DI-form registration (NOT the Looping::class event-listener form — see W-8 fix)"
    - "ResetAdvancedToggleOnLogin clears dev_mode.advanced from session on every Login event; ArtisanRunnerPage::mount also resets on first Dev Console load per session"
    - "GET /dev/artisan as developer renders the runner page header + filter chips + worker pre-flight pill + day-section timeline of run-cards; fallback Flux modal lists SAFE-tier commands ONLY (DESTRUCTIVE excluded — see B-2 fix); destructive runs are reachable only via the runner page's per-row 'Re-run' affordance which routes through the triple-gate"
    - "GET /dev/audit shows the last ~20 dev_mode_audit rows filtered by tier/caller/command with tier coloring"
    - "Sidebar Artisan + Audit items become enabled (no nav-disabled class)"
    - "Audit taxonomy is declared as the AuditEvent enum (per I-5) — no free-form audit-action strings"
    - "Baseline Monolog redaction processor (Bearer headers + JWT-shape tokens) is installed into the config/logging.php tap slots on the stack, single, and daily channels BEFORE 16-05 lands; the same FQCN is upgraded in place in 16-05 by adding OAuthScrubSet to the constructor (W-1 fix — closes the redaction-gap window in Wave 4)"
  artifacts:
    - path: "Modules/DevMode/Internal/Audit/SpatieAuditWriter.php"
      provides: "Concrete AuditWriter using spatie/laravel-activitylog with 8KB excerpt cap + basic redaction (16-05 upgrades to full scrub-set)"
      contains: "activity"
    - path: "Modules/DevMode/Internal/Http/Livewire/TripleGateModal.php"
      provides: "Server-side triple-gate confirmation (Dev Mode ON + Advanced ON + typed beatrax)"
      contains: "hash_equals"
    - path: "Modules/DevMode/Internal/Audit/FinalizeRunAudit.php"
      provides: "Hook invoked by 16-04's ArtisanStreamController on the done branch — reads the per-run tmp file + writes the audit row via SpatieAuditWriter"
      contains: "FinalizeRunAudit"
    - path: "Modules/DevMode/Internal/Enums/AuditEvent.php"
      provides: "Enum locking the audit-event taxonomy (per I-5)"
      contains: "CommandExecuted"
    - path: "Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php"
      provides: "Monolog ProcessorInterface implementation — baseline Bearer-header + JWT-shape regex scrubbing (W-1 fix). 16-05 upgrades the constructor in place to inject OAuthScrubSet; the FQCN, the apply() / __invoke() signature, and the tap-slot registration in config/logging.php stay stable across the upgrade."
      contains: "ProcessorInterface"
    - path: "Modules/DevMode/Internal/Logging/PushRedactProcessor.php"
      provides: "Laravel 'tap class' pattern — __invoke(\\Illuminate\\Log\\Logger $logger): void resolves RedactSecretsProcessor from the container and pushes it onto each handler of the tapped channel. The container resolution means 16-05's constructor-DI upgrade to RedactSecretsProcessor propagates automatically without editing PushRedactProcessor or config/logging.php."
      contains: "pushProcessor"
    - path: "config/logging.php"
      provides: "Laravel logging config — published from skeleton in 16-03 with empty 'tap' => [] placeholders on stack, single, and daily channels (D-27 default channel = daily). This plan FILLS those three tap slots with [\\Modules\\DevMode\\Internal\\Logging\\PushRedactProcessor::class] so every log line on every channel is redacted before write."
      contains: "PushRedactProcessor"
  key_links:
    - from: "Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php (from 16-04)"
      to: "Modules\\DevMode\\Internal\\Audit\\FinalizeRunAudit"
      via: "stream controller's done branch invokes FinalizeRunAudit::__invoke($runId)"
      pattern: "FinalizeRunAudit"
    - from: "Modules/DevMode/Internal/Http/Livewire/TripleGateModal.php"
      to: "Modules\\DevMode\\Internal\\Http\\Controllers\\DestructiveSpawnController"
      via: "confirm() dispatches triple-gate:confirmed → ArtisanRunnerPage listener POSTs to DestructiveSpawnController"
      pattern: "DestructiveSpawnController"
    - from: "Modules/DevMode/Internal/Audit/SpatieAuditWriter.php"
      to: "spatie/laravel-activitylog"
      via: "activity('dev_mode')->causedBy(user)->withProperties([...])->log(AuditEvent::CommandExecuted->value)"
      pattern: "activity\\('dev_mode'\\)"
    - from: "config/logging.php (stack/single/daily channel tap slots)"
      to: "Modules\\DevMode\\Internal\\Logging\\PushRedactProcessor"
      via: "Monolog tap-class registration — Laravel resolves the tap class from the container and invokes __invoke($logger) on every log channel boot; PushRedactProcessor pushes RedactSecretsProcessor onto every handler of the tapped channel"
      pattern: "PushRedactProcessor"
    - from: "Modules/DevMode/Internal/Logging/PushRedactProcessor.php"
      to: "Modules\\DevMode\\Internal\\Logging\\RedactSecretsProcessor"
      via: "app(RedactSecretsProcessor::class) container resolution inside __invoke — picks up 16-05's constructor-DI upgrade automatically"
      pattern: "RedactSecretsProcessor::class"
---

<objective>
Layer the audit pipeline + triple-gate + heartbeat + DESTRUCTIVE execution path + full runner UI + the BASELINE log-redaction processor on top of 16-04's spawn-then-tail pipeline. Specifically: replace 16-03's NullAuditWriter with SpatieAuditWriter, add RedactionExcerptCap (basic Bearer+JWT — full OAuth scrub-set in 16-05), add FinalizeRunAudit hook that 16-04's ArtisanStreamController invokes on the done branch, add TripleGateModal (server-side three-gate enforcement), add DestructiveSpawnController as the dedicated entry point for DESTRUCTIVE runs (triple-gate-confirmed only — separate from 16-04's SAFE-only ArtisanSpawnController), add Queue::looping heartbeat (DI form per W-8 fix), add the Login listener for advanced-toggle reset, add the ArtisanRunnerPage + AuditLogPage Livewire components + their views + the run-card / status-pill / tier-chip Blade primitives, declare the AuditEvent enum locking the taxonomy (per I-5), enable the Artisan + Audit sidebar items, AND install the BASELINE Monolog `RedactSecretsProcessor` + `PushRedactProcessor` tap class + fill the empty `'tap' => []` placeholder slots that 16-03 published in `config/logging.php` on the `stack`, `single`, and `daily` channels (W-1 fix — closes the on-write redaction-gap window in Wave 4, in the same wave as the runner; 16-05 upgrades the SAME processor class in place by adding OAuthScrubSet to its constructor).

Purpose: This plan completes DEVUI-02 (full runner UI + audit history) + DEVUI-03 (triple-gate + DESTRUCTIVE execution + audit). It was split out of the original 16-04 per checker B-5 (29-file scope across 3 tasks was too risky for L10-strict consistency under one executor pass). It runs serially after 16-04 inside Wave 4. The W-1 baseline redaction processor lands here (not in 16-05) so the redaction-gap window between 16-03 publishing the empty tap slots and any new log-producing surface landing closes inside the same Wave 4 as the runner.

Output: A working `/dev/artisan` page where a developer can run any SAFE-tier command from the fallback modal OR the timeline's Re-run affordance, watch live stdout stream into a run-card via the 16-04 SSE pipeline, run any DESTRUCTIVE command through the triple-gate modal (which routes through the dedicated DestructiveSpawnController), and review every command run from /dev/audit. The fallback modal lists SAFE-tier commands ONLY (B-2 fix — DESTRUCTIVE remains exclusively reachable via the runner page's per-row triple-gate path to prevent the muscle-memory disasters D-41 was guarding against). Additionally, every Laravel log line written through the `stack`, `single`, or `daily` channels is redacted at the Monolog handler level via the baseline RedactSecretsProcessor (Bearer + JWT) BEFORE the formatter writes the record to disk.

## Phase Goal

**As a** developer in the Dev Console, **I want to** click `db:backup` from the runner timeline and see it spawn + stream + audit through 16-04's pipeline, click a DESTRUCTIVE command like `db:restore` from a Re-run affordance and confirm it through the triple-gate (Dev Mode ON + Advanced ON + typed `beatrax`) before the DestructiveSpawnController spawns it, see every run audited in /dev/audit with tier coloring + filters, watch the worker heartbeat pill turn green when `queue:work` is running, AND have every log line on every channel scrubbed of Bearer headers + JWT tokens before it hits disk — **so that** DEVUI-02 + DEVUI-03 are complete, the on-write log-redaction window is closed in the same wave as the runner (W-1 fix), and the rest of Phase 16 can compose this audit + triple-gate + redaction machinery in 16-05/06/07.

## SCOPE BOUNDARY vs 16-04

This plan does NOT modify any of 16-04's artifacts EXCEPT `ArtisanStreamController` (to add the FinalizeRunAudit hook on the done branch) and `DevModeServiceProvider` (to swap the NullAuditWriter binding for SpatieAuditWriter + register the new listeners + Livewire components). All process / SSE / cancel / spawn primitives stay 16-04's responsibility.

## SCOPE BOUNDARY vs 16-05 (W-1 hand-off contract)

This plan installs the BASELINE `RedactSecretsProcessor` — it redacts ONLY the Bearer-header regex (`/Authorization:\s*Bearer\s+\S+/i`) and the JWT-shape regex (`/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/`). It DOES NOT reach into the `oauth_secrets` table — that scrub-set is 16-05's job.

The hand-off contract is:
1. THIS plan creates `Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php` with the baseline constructor (no DI on OAuthScrubSet) + `__invoke(LogRecord $record): LogRecord` + the two regexes.
2. THIS plan creates `Modules/DevMode/Internal/Logging/PushRedactProcessor.php` — Laravel tap class. Its `__invoke(\Illuminate\Log\Logger $logger): void` resolves `RedactSecretsProcessor` from the container via `app(RedactSecretsProcessor::class)` and pushes it onto every handler of the tapped channel via `$handler->pushProcessor(...)`.
3. THIS plan fills the empty `'tap' => []` slots on the `stack`, `single`, AND `daily` channels in `config/logging.php` (16-03 published the file with empty placeholders) with `[\Modules\DevMode\Internal\Logging\PushRedactProcessor::class]`.
4. 16-05 UPGRADES the SAME `RedactSecretsProcessor` class in place by adding `OAuthScrubSet $scrubSet` to its constructor and applying the compiled scrub-set regex BEFORE the Bearer/JWT regex in `__invoke`. The FQCN, the apply() / __invoke() signature, and the tap-slot registration in `config/logging.php` stay stable — the container resolution in `PushRedactProcessor` picks up the new constructor automatically.

The PHPDoc on `RedactSecretsProcessor` (this plan) MUST document the upgrade contract so the 16-05 executor does not break the stable interface: "16-05 adds OAuthScrubSet to the constructor — do not change the __invoke signature or the FQCN; PushRedactProcessor + config/logging.php must keep working without edits."
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@CLAUDE.md
@.planning/phases/16-developer-mode-ui/16-CONTEXT.md
@.planning/phases/16-developer-mode-ui/16-RESEARCH.md
@.planning/phases/16-developer-mode-ui/16-PATTERNS.md
@.planning/phases/16-developer-mode-ui/16-UI-SPEC.md
@.planning/phases/16-developer-mode-ui/16-03-SUMMARY.md
@.planning/phases/16-developer-mode-ui/16-04-SUMMARY.md
@.claude/skills/sketch-findings-diederik/SKILL.md

<interfaces>
AuditEvent enum (per I-5 fix — declare audit-action strings as enum cases):
- `final class AuditEvent` (backed string enum, PHP 8.1+):
  - `CommandExecuted = 'command_executed'`
  - `CommandCancelled = 'command_cancelled'`
  - `QueueAction = 'queue_action'` (reused by 16-06)
  - `SqlSelect = 'sql.select'` (reused by 16-07)
  - Add new cases here whenever a new audit category emerges. 16-06's queue.* taxonomy adds its own cases in 16-06's plan (the enum can grow per plan; rule is: NEVER pass a free-form string to SpatieAuditWriter's log() — always pass `AuditEvent::Foo->value`).

Audit row shape (D-24) reminder:
- `command`, `args` (JSON), `tier` (safe|destructive), `caller_user_id`, `started_at`, `finished_at`, `exit_code`, `stdout_excerpt` (8KB cap), `error_excerpt` (8KB cap).
- Excerpts pass through RedactionExcerptCap BEFORE write (basic baseline here: Bearer + JWT only; 16-05 upgrades to full OAuth scrub-set).

RedactionExcerptCap (basic baseline — audit-log excerpt cap layer; SEPARATE artifact from RedactSecretsProcessor below):
- `final readonly class RedactionExcerptCap`. No DI in this plan (the OAuth scrub-set comes in 16-05; this is the baseline). 16-05 changes the constructor to inject OAuthScrubSet.
- `apply(string $text, int $maxBytes = 8192): string` — applies the Bearer regex (`/Authorization:\s*Bearer\s+\S+/i` → `Authorization: Bearer [REDACTED]`) + JWT regex (`/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/` → `[JWT_REDACTED]`), then truncates to $maxBytes using `mb_substr`.
- Lives at `Modules/DevMode/Internal/Audit/RedactionExcerptCap.php`. Consumed by `SpatieAuditWriter` only (NOT by the Monolog tap path — that path uses `RedactSecretsProcessor` below).

RedactSecretsProcessor (baseline — W-1 fix, on-write Monolog processor; SEPARATE artifact from RedactionExcerptCap above):
- `final class RedactSecretsProcessor implements \Monolog\Processor\ProcessorInterface`. Lives at `Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php`.
- Baseline constructor: no arguments (16-05 will add `OAuthScrubSet $scrubSet`; do not change the FQCN or the __invoke signature).
- `__invoke(\Monolog\LogRecord $record): \Monolog\LogRecord`:
  - Apply Bearer regex (`/Authorization:\s*Bearer\s+\S+/i` → `'Authorization: Bearer [REDACTED]'`) on `$record->message`.
  - Apply JWT regex (`/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/` → `'[JWT_REDACTED]'`) on `$record->message`.
  - Recursively scrub the same regexes on every string value in `$record->context` and `$record->extra`.
  - Return a `$record->with(message: $scrubbedMessage, context: $scrubbedContext, extra: $scrubbedExtra)` — Monolog v3 LogRecord is immutable.
- PHPDoc MUST document the 16-05 upgrade contract verbatim: "16-05 adds `OAuthScrubSet $scrubSet` to the constructor and applies the compiled scrub-set regex BEFORE the Bearer/JWT regexes inside __invoke. The FQCN, the __invoke signature, and the on-write tap registration in `config/logging.php` MUST remain stable across the upgrade — `PushRedactProcessor` resolves this class from the container so the constructor-DI change is invisible to it."

PushRedactProcessor (Laravel tap class — wires RedactSecretsProcessor onto every Monolog handler of the tapped channel):
- `final class PushRedactProcessor`. Lives at `Modules/DevMode/Internal/Logging/PushRedactProcessor.php`. No DI in the constructor (Laravel instantiates tap classes with `new`).
- `__invoke(\Illuminate\Log\Logger $logger): void`:
  ```
  $processor = app(\Modules\DevMode\Internal\Logging\RedactSecretsProcessor::class);
  foreach ($logger->getLogger()->getHandlers() as $handler) {
      $handler->pushProcessor($processor);
  }
  ```
- Container resolution is the trick: 16-05 changes the RedactSecretsProcessor constructor, but `app(RedactSecretsProcessor::class)` continues to resolve correctly without touching this file.

`config/logging.php` tap-slot fill:
- 16-03 published `config/logging.php` from the Laravel skeleton with `'tap' => []` placeholders on the `stack`, `single`, and `daily` channels. THIS plan hand-edits those three channels:
  ```
  'stack' => [
      'driver' => 'stack',
      'channels' => explode(',', env('LOG_STACK', 'single')),
      'ignore_exceptions' => false,
      'tap' => [\Modules\DevMode\Internal\Logging\PushRedactProcessor::class],
  ],
  'single' => [
      'driver' => 'single',
      'path' => storage_path('logs/laravel.log'),
      'level' => env('LOG_LEVEL', 'debug'),
      'replace_placeholders' => true,
      'tap' => [\Modules\DevMode\Internal\Logging\PushRedactProcessor::class],
  ],
  'daily' => [
      'driver' => 'daily',
      'path' => storage_path('logs/laravel.log'),
      'level' => env('LOG_LEVEL', 'debug'),
      'days' => env('LOG_DAILY_DAYS', 14),
      'replace_placeholders' => true,
      'tap' => [\Modules\DevMode\Internal\Logging\PushRedactProcessor::class],
  ],
  ```
- Use the existing channel structure that 16-03 published — verify by reading the file before editing. The actual non-tap key/value pairs may differ slightly from the snippet above (this is illustrative); only the `'tap' => [...]` line is the load-bearing change. If 16-03 used `'tap' => []` consistently, replace each empty array with the single-element array shown. Do NOT touch any other channel (`emergency`, `syslog`, `errorlog`, `null`, `stderr`, `papertrail`, etc.) — only `stack`, `single`, `daily`.
- `php artisan vendor:publish --tag=laravel-logging` is NON-IDEMPOTENT in Laravel (re-running prompts to overwrite); since 16-03 already published the file, THIS plan does NOT re-publish — it ONLY hand-edits the three tap slots. The published file is committed in 16-03's commit; this plan's commit diff for `config/logging.php` MUST show only the three single-line additions to the `tap` arrays. Verify via `git diff config/logging.php` after the edit — three lines changed, nothing else.

SpatieAuditWriter:
- `final readonly class SpatieAuditWriter implements AuditWriter`.
- Constructor DI on `CurrentUser`, `Clock`, `RedactionExcerptCap`.
- `recordCommandRun(...)` writes via spatie's `\Spatie\Activitylog\ActivityLogger` injected via DI (avoid the `activity()` global helper per CLAUDE.md DI-only — but verify the package exposes a DI-friendly logger; if it only exposes the `activity()` global helper, document that as an exception per the existing facade-allow-list pattern). Pseudo-shape:
  ```
  $this->logger
      ->useLog('dev_mode')
      ->causedBy($this->currentUser->user())
      ->withProperties([
          'command'        => $command,
          'args'           => $args,
          'tier'           => $tier,
          'exit_code'      => $exitCode,
          'stdout_excerpt' => $this->cap->apply($stdoutExcerpt),
          'error_excerpt'  => $this->cap->apply($errorExcerpt),
          'started_at'     => $startedAt->toISOString(),
          'finished_at'    => $finishedAt?->toISOString(),
      ])
      ->log(AuditEvent::CommandExecuted->value);
  ```
- `recordDestructiveQueueAction(...)`: similar shape but `log(AuditEvent::QueueAction->value)`.
- `recordSelectQuery(...)`: `log(AuditEvent::SqlSelect->value)`.
- Bind in `DevModeServiceProvider::register()` — REPLACES the `NullAuditWriter` binding from 16-03.

FinalizeRunAudit hook:
- `final readonly class FinalizeRunAudit`. Constructor DI on `AuditWriter` + `RunRegistry` + `Filesystem`.
- `__invoke(string $runId, int $exitCode, bool $cancelled): void`:
  - Resolve `RunRecord` from RunRegistry.
  - Read the per-run tmp file at `$record->outPath`; cap to 8KB pre-redaction (read with `fread($fh, 8192*4)` for headroom, since redaction may shrink the text).
  - Split stdout vs stderr: the tmp file is merged (`> file 2>&1`); this plan treats the entire content as `stdout_excerpt` and leaves `error_excerpt` empty. Document this limitation in the SUMMARY — splitting stdout/stderr requires architecture change (separate tmp files); not worth the complexity in v1.
  - Call `$this->audit->recordCommandRun($record->command, $record->args, $record->tier, $record->callerUserId, $record->startedAt, now(), $exitCode, $excerpt, '');`.
  - If `$cancelled`, ALSO call `recordCommandRun` with the cancel marker (or use `AuditEvent::CommandCancelled` via the same writer — planner discretion; recommendation: single audit row with `exit_code = -signal-num` + properties.cancelled=true rather than two rows).

16-04 ArtisanStreamController integration:
- Edit 16-04's `ArtisanStreamController` (the only 16-04 file this plan touches): in the done branch (where the loop emits `event: done`), inject `FinalizeRunAudit` via constructor DI and invoke `$this->finalize($runId, $exitCode, $cancelled)` before the `break`. 16-04 left this hook unimplemented; 16-04b wires it.

Triple-gate (D-20 / D-21 / D-22):
- `TripleGateModal` Livewire component (mirror PATTERNS.md analog `Modules/Categorization/Internal/Http/Livewire/RuleFormModal.php`). State:
  - `public string $command;` (set by the open event)
  - `public array $resolvedArgs;` (set by the open event)
  - `#[Validate('required|string')] public string $typed = '';`
- Open via `Livewire.dispatch('triple-gate:open', { command: 'db:restore', args: { from: '/path/to/backup.sqlite' } })`.
- `confirm(CurrentUser $user, DevModeFlag $devMode, SessionRepository $session): void`:
  - Gate 1: `if (! $devMode->isOn()) throw ValidationException::withMessages(['_gate' => 'dev_mode_off']);`.
  - Gate 2: `if ($session->get('dev_mode.advanced') !== true) throw ValidationException::withMessages(['_gate' => 'advanced_off']);`.
  - Gate 3: `if (! hash_equals('beatrax', $this->typed)) throw ValidationException::withMessages(['typed' => 'app_name_mismatch']);`.
  - On success: dispatches `triple-gate:confirmed` with the command + args; closes the modal; the ArtisanRunnerPage listener handles the spawn via DestructiveSpawnController.
- Modal Blade: `<flux:modal name="triple-gate" :dismissible="false">` — click-outside does NOT close per UI-SPEC. Rose-tinted header. `.gate-cmd` mono preview row showing `php artisan {command} {resolvedArgs}`. Input "Type beatrax to confirm". Buttons "Cancel" + "Run {command-name}" (rose `.pill-btn.danger`, disabled until `typed === 'beatrax'` — Alpine `x-data` watches input).

DevModeFlag service:
- `final readonly class DevModeFlag`. No DI. `isOn(): bool { return config('app.dev_mode') === true; }`. Thin wrapper so tests can mock without poking config directly.

DestructiveSpawnController:
- `__invoke(Request $request, CurrentUser $user, CommandSpawner $spawner, DevCommandRegistry $registry, DevModeFlag $devMode, SessionRepository $session): JsonResponse`.
- Re-validates the triple-gate server-side (defense-in-depth — the TripleGateModal already did, but the controller re-checks because the actual spawn happens here, separately from the modal's confirm step):
  - DevModeFlag on
  - session.advanced === true
  - body must include `confirmed_typed === 'beatrax'` (the Livewire event passes this through)
- Validates body: `command` (string, must be in registry-destructive-names), `args` (array, validated per ArgSpec).
- On accept: `$runId = $spawner->start($command, $args, $user->user()->id, 'destructive'); return response()->json(['run_id' => $runId, 'pid' => $registry->find($runId)->pid], 202);`.
- This is the SEPARATE entry point for DESTRUCTIVE execution (16-04's ArtisanSpawnController rejects DESTRUCTIVE; this controller handles them).

Worker heartbeat (D-39) — **W-8 FIX: use DI-form Queue::looping registration, not the event-listener form**:
- `WriteWorkerHeartbeat` listener: `final readonly class WriteWorkerHeartbeat`. Constructor DI on `Clock`, `CacheRepository`.
- `__invoke(): void`:
  ```
  $this->cache->put('dev_mode.queue_worker_heartbeat', $this->clock->now()->getTimestamp(), 60);
  ```
- Wire in `DevModeServiceProvider::boot()` via DI form:
  ```
  $this->app->make(\Illuminate\Queue\QueueManager::class)->looping(function () {
      $this->app->make(WriteWorkerHeartbeat::class)();
  });
  ```
  Per W-8: do NOT use `$events->listen(Looping::class, ...)` — Laravel 13's `queue:work` does not reliably dispatch `Looping::class` per loop iteration; only the closure form fires.
- Verify the heartbeat behavior with a Pest test that runs `queue:work --once` via `Artisan::call(...)` and asserts the cache key was written within 5s of the test start (Test 3 in TripleGateTest).

Advanced toggle reset on login:
- `ResetAdvancedToggleOnLogin` listener subscribes to `Illuminate\Auth\Events\Login`. Calls `$session->forget('dev_mode.advanced')`.
- Wired in `DevModeServiceProvider::boot()` via `$events->listen(Login::class, ResetAdvancedToggleOnLogin::class)`.
- ArtisanRunnerPage's mount() also resets the toggle on first-load-per-session:
  ```
  if (!$session->has('dev_mode.advanced_session_seen')) {
      $session->forget('dev_mode.advanced');
      $session->put('dev_mode.advanced_session_seen', true);
  }
  ```

`/dev/audit` page (D-25):
- `AuditLogPage` Livewire component with `#[Url]` filters: `?tier=destructive`, `?caller=username`, `?command=db:restore`.
- Reads `\Spatie\Activitylog\Models\Activity::query()->where('log_name', 'dev_mode')->orderByDesc('created_at')->cursorPaginate(50)`. Filter scopes via URL properties.
- Table per UI-SPEC § Dense tables: command (mono) · tier chip · caller username · started_at diffForHumans · exit_code (rose if non-zero). Hover expands to show stdout/stderr excerpts + args JSON.

ArtisanRunnerPage (D-25 "Recent runs" panel):
- Header "Artisan runner" + primary `<flux:button>` "⌘K Run a command" (dispatches `palette:open` once 16-08 lands; for now no-op gracefully if the component isn't registered).
- Filter chips row: All · Running · Failed · Destructive.
- Worker pre-flight pill reads cache `dev_mode.queue_worker_heartbeat`; alive if timestamp < now()-60s.
- Day-section labels in the timeline.
- Run cards `<x-dev::run-card :run="$run" />` for each row from `\Spatie\Activitylog\Models\Activity::query()->where('causer_id', $user->id)->latest()->limit(100)->get()`.
- Per-row buttons per UI-SPEC. For DESTRUCTIVE Re-run: dispatches `triple-gate:open`; on confirm, the listener POSTs to `DestructiveSpawnController`. For SAFE Re-run: POSTs to 16-04's `ArtisanSpawnController` directly.

The fallback runner modal (**B-2 fix — SAFE-tier ONLY**):
- `<flux:modal name="run-command">` lists SAFE-tier commands ONLY at render time. Filter at render via `$registry->safe()` — NEVER `$registry->all()` or any list that includes DESTRUCTIVE specs.
- This is the v1 affordance for the SAFE-tier "click a command and run it" flow until 16-08's palette lands.
- DESTRUCTIVE commands are NOT in the fallback modal. They are reachable ONLY via the runner page's per-row Re-run affordance on previously-run DESTRUCTIVE commands (which itself routes through TripleGateModal). For a developer's FIRST DESTRUCTIVE run, the affordance is either the palette (when 16-08 lands) or a one-off `php artisan` from the terminal. Document this design choice + rationale (D-41 / B-2 fix) inline in the plan's run-command modal Blade view comment.

PruneDevAuditCommand:
- `Modules/DevMode/Internal/Console/PruneDevAuditCommand.php`: signature `beatrax:prune-dev-audit {--older-than=}`. Validates `--older-than` is a positive integer count of days. Deletes `Activity::where('log_name', 'dev_mode')->where('created_at', '<', now()->subDays($days))->delete()`. SAFE-tier (mirror PATTERNS analog `BackupDatabaseCommand`).

Sidebar updates:
- Remove the `nav-disabled` class on the "Artisan" and "Audit" sidebar items in `dev-shell.blade.php` (16-03 added them disabled). Per W-3 fix, use the explicit allow-list approach instead of `Route::has` — set the `enabled` flag on those slugs to true in the `DevSidebarItems` constants array. NOTE: `DevSidebarItems` is established by 16-06 in Wave 5; if 16-06 hasn't shipped yet (it hasn't — this is Wave 4), declare the equivalent local enabled-list in 16-03's `dev-shell.blade.php` directly and let 16-06 migrate it to the service when it lands. Document the migration in the SUMMARY.

Routes — register in `Modules/DevMode/Routes/web.php` (append):
```
Route::get('/artisan', ArtisanRunnerPage::class)->name('dev.artisan');
Route::get('/audit', AuditLogPage::class)->name('dev.audit');
Route::post('/artisan/destructive-spawn', DestructiveSpawnController::class)->name('dev.artisan.destructive-spawn');
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: AuditEvent enum + RedactionExcerptCap + SpatieAuditWriter + FinalizeRunAudit hook + ArtisanStreamController wire-up + BASELINE Monolog RedactSecretsProcessor + PushRedactProcessor + config/logging.php tap-slot fill (W-1 fix)</name>
  <files>Modules/DevMode/Internal/Enums/AuditEvent.php, Modules/DevMode/Internal/Audit/RedactionExcerptCap.php, Modules/DevMode/Internal/Audit/SpatieAuditWriter.php, Modules/DevMode/Internal/Audit/FinalizeRunAudit.php, Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php, Modules/DevMode/Internal/Logging/PushRedactProcessor.php, config/logging.php, Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php, Modules/DevMode/Providers/DevModeServiceProvider.php, Modules/DevMode/Internal/Console/PruneDevAuditCommand.php, Modules/DevMode/tests/Feature/AuditLogWriteTest.php, Modules/DevMode/tests/Unit/RedactSecretsProcessorBaselineTest.php</files>
  <behavior>
    - Test 1 (AuditEvent enum): the enum has cases CommandExecuted, CommandCancelled, QueueAction, SqlSelect with the documented string values.
    - Test 2 (RedactionExcerptCap basic): `apply('Authorization: Bearer abc123def456_xyz')` returns `Authorization: Bearer [REDACTED]`.
    - Test 3 (RedactionExcerptCap basic): `apply('eyJhbGciOi…long.payload.sig')` returns `[JWT_REDACTED]`.
    - Test 4 (RedactionExcerptCap cap): `apply(str_repeat('a', 10000))` returns a string of length 8192.
    - Test 5 (SpatieAuditWriter): `recordCommandRun('db:backup', ['destination' => '/tmp/x.db'], 'safe', $userId, $startedAt, $finishedAt, 0, 'output', 'errors')` writes a row to `dev_mode_audit` with the D-24 fields + log_name='dev_mode' + properties.command='db:backup'.
    - Test 6 (SpatieAuditWriter): stdout containing `Authorization: Bearer XYZ` results in audit row stdout_excerpt='Authorization: Bearer [REDACTED]'.
    - Test 7 (FinalizeRunAudit end-to-end): start a real SAFE command via 16-04's CommandSpawner → invoke 16-04's ArtisanStreamController via test HTTP client → after process completes + done emitted → assert a dev_mode_audit row exists with the captured stdout (redacted, capped).
    - Test 8 (PruneDevAuditCommand): seed 5 audit rows with mixed dates; run `beatrax:prune-dev-audit --older-than=7`; only rows older than 7 days are deleted.
    - **Test 9 (RedactSecretsProcessorBaselineTest — W-1 baseline regression guard, lives in `Modules/DevMode/tests/Unit/RedactSecretsProcessorBaselineTest.php`):** instantiate `RedactSecretsProcessor` directly (baseline ctor, no DI); pass a Monolog `LogRecord` whose `message` contains `Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c` AND a separate standalone JWT token; assert the returned record's message contains BOTH `Authorization: Bearer [REDACTED]` AND `[JWT_REDACTED]` (the Bearer regex eats the whole token in that header form; the standalone JWT is replaced by `[JWT_REDACTED]`). Assert the original token strings do NOT appear anywhere in the returned message, context, or extra. This test stays GREEN through 16-05's constructor-DI upgrade (16-05 adds OAuthScrubSet before the Bearer/JWT scrubbing — baseline behavior must remain intact).
    - **Test 10 (RedactSecretsProcessorBaselineTest — context recursion):** pass a record whose `context = ['headers' => ['Authorization' => 'Bearer abc.def.ghi'], 'nested' => ['eyJh.fooo123456789012345678901.barr12345678901234567890']]`; assert the returned record's context is recursively scrubbed (both nested string values replaced).
    - **Test 11 (config/logging.php tap-slot fill — W-1 wiring guard):** assert `config('logging.channels.stack.tap')`, `config('logging.channels.single.tap')`, AND `config('logging.channels.daily.tap')` each contain `\Modules\DevMode\Internal\Logging\PushRedactProcessor::class`. This locks the wiring so a future config-edit that removes the tap slot is caught immediately. Lives in `RedactSecretsProcessorBaselineTest.php`.
    - **Test 12 (end-to-end on-write redaction via logger() — W-1 belt+braces):** call `logger()->info('Token: Authorization: Bearer abc.def.ghi')`; read the resulting log file written to `storage/logs/`; assert the file contains `Authorization: Bearer [REDACTED]` and does NOT contain the literal token. Use the per-test ephemeral channel pattern that 16-05's W-5 test infra documents (build a temp channel via `app(\Illuminate\Log\LogManager::class)->build([...])` with the same `tap` array) so the test does not race the real shared `laravel-{date}.log` under `pest --parallel`. Lives in `RedactSecretsProcessorBaselineTest.php`.
  </behavior>
  <action>
    Step 1 — Create `AuditEvent` enum per &lt;interfaces&gt;. Pure value object; no DI.

    Step 2 — Create `RedactionExcerptCap` per &lt;interfaces&gt;. No DI in this plan. 16-05 upgrades the constructor; document the upgrade contract in the class PHPDoc ("16-05 adds OAuthScrubSet to the constructor — do not break the existing apply() signature").

    Step 3 — Create `SpatieAuditWriter` per &lt;interfaces&gt;. Constructor DI on CurrentUser + Clock + RedactionExcerptCap. Inject `\Spatie\Activitylog\ActivityLogger` (verify the class exists in spatie/laravel-activitylog ^5.0; if only the `activity()` global helper exists, document the helper as an exception alongside CLAUDE.md DI rules and add to phpstan.neon facade-allow-list).

    Step 4 — Create `FinalizeRunAudit` per &lt;interfaces&gt;. Constructor DI on AuditWriter + RunRegistry + Filesystem.

    Step 5 — Edit 16-04's `ArtisanStreamController` (the only 16-04 file 16-04b touches): add FinalizeRunAudit to constructor DI; in the done branch (where `event: done` is emitted), call `$this->finalize($runId, $exitCode, $cancelled);` BEFORE the break.

    Step 6 — Edit `DevModeServiceProvider::register()`: REPLACE the NullAuditWriter binding with `$this->app->singleton(AuditWriter::class, SpatieAuditWriter::class);`. ALSO bind `RedactSecretsProcessor` as a container singleton so `app(RedactSecretsProcessor::class)` always returns the same instance (per-process; the OAuth scrub-set cache 16-05 adds lives on the singleton).

    Step 7 — Create `PruneDevAuditCommand` per &lt;interfaces&gt;. SAFE-tier; registered via the existing console-kernel discovery.

    **Step 8 (W-1 BASELINE) — Create `Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php` per &lt;interfaces&gt;** (NOT the same artifact as `RedactionExcerptCap` in Step 2 — that one is the audit-log excerpt layer; this one is the Monolog on-write processor). Baseline constructor takes NO arguments. `__invoke(LogRecord $record): LogRecord` applies the Bearer regex + JWT regex on the message AND recursively on every string in context and extra. Return via `$record->with(...)` — Monolog v3 LogRecord is immutable. Document the 16-05 upgrade contract verbatim in the class PHPDoc (see &lt;interfaces&gt; above). Implementation guidance for the recursive helper: `private function scrubArray(array $a): array { return array_map(fn($v) => is_array($v) ? $this->scrubArray($v) : (is_string($v) ? $this->scrub($v) : $v), $a); }` where `scrub(string $s): string` applies the two regexes in order.

    **Step 9 (W-1 BASELINE) — Create `Modules/DevMode/Internal/Logging/PushRedactProcessor.php` per &lt;interfaces&gt;.** Laravel tap class — Laravel instantiates with `new` (no constructor DI). `__invoke(\Illuminate\Log\Logger $logger): void` resolves `RedactSecretsProcessor` from the container via `app(RedactSecretsProcessor::class)` and pushes it onto every handler returned by `$logger->getLogger()->getHandlers()`. The container resolution is the load-bearing detail — it lets 16-05's constructor-DI upgrade propagate without touching this file or `config/logging.php`.

    **Step 10 (W-1 BASELINE) — Edit `config/logging.php` to fill the three tap slots per &lt;interfaces&gt;:** read the file as published by 16-03 (it has empty `'tap' => []` placeholders on `stack`, `single`, and `daily`). Replace EACH of those three empty arrays with `[\Modules\DevMode\Internal\Logging\PushRedactProcessor::class]`. Do NOT re-run `php artisan vendor:publish --tag=laravel-logging` (non-idempotent, would prompt to overwrite). Do NOT edit any other channel. After the edit, `git diff config/logging.php` MUST show exactly three single-line changes — three `'tap' => []` lines became `'tap' => [\Modules\DevMode\Internal\Logging\PushRedactProcessor::class],`. Verify before commit.

    Step 11 — Tests covering 12 behaviors. For Test 7 (FinalizeRunAudit end-to-end), reuse 16-04's CommandSpawner + a real `cache:clear`. For Test 5 + 6, use `DB::table('dev_mode_audit')->latest()->first()` to inspect the written row. For Tests 9-12 (the new W-1 baseline guards), use `Modules/DevMode/tests/Unit/RedactSecretsProcessorBaselineTest.php` as the test file; Test 12 uses the per-test ephemeral channel pattern (build a temp channel via `app(\Illuminate\Log\LogManager::class)->build([...])` with `'tap' => [PushRedactProcessor::class]` and a per-test tmp path) to avoid Pest-parallel races on the shared `laravel-{date}.log`.
  </action>
  <verify>
    <automated>cd /Users/wesselverheij/Development/diederik &amp;&amp; vendor/bin/pest --filter='AuditLogWrite|PruneDevAudit|RedactSecretsProcessorBaseline' &amp;&amp; vendor/bin/pest --parallel</automated>
  </verify>
  <done>AuditEvent enum locks taxonomy; RedactionExcerptCap baseline working (audit excerpt layer); SpatieAuditWriter writes D-24 rows with basic redaction + 8KB cap; FinalizeRunAudit invoked from 16-04's ArtisanStreamController on done branch; PruneDevAuditCommand registered + working; **W-1 baseline Monolog `RedactSecretsProcessor` + `PushRedactProcessor` created at `Modules/DevMode/Internal/Logging/`; `config/logging.php` `stack` + `single` + `daily` tap slots all reference `PushRedactProcessor::class` (verified via Test 11); `logger()->info` calls with Bearer/JWT content are redacted on disk (verified via Test 12); the on-write redaction-gap window closes in Wave 4, not Wave 5; the upgrade contract for 16-05 (add `OAuthScrubSet` to RedactSecretsProcessor constructor without changing FQCN/signature/config) is documented in the class PHPDoc;** 12 tests pass.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: DevModeFlag + TripleGateModal + DestructiveSpawnController + Queue::looping heartbeat (DI form) + Login listener</name>
  <files>Modules/DevMode/Internal/Services/DevModeFlag.php, Modules/DevMode/Internal/Http/Livewire/TripleGateModal.php, Modules/DevMode/Resources/views/livewire/triple-gate-modal.blade.php, Modules/DevMode/Internal/Http/Controllers/DestructiveSpawnController.php, Modules/DevMode/Internal/Listeners/WriteWorkerHeartbeat.php, Modules/DevMode/Internal/Listeners/ResetAdvancedToggleOnLogin.php, Modules/DevMode/Providers/DevModeServiceProvider.php, Modules/DevMode/Routes/web.php, Modules/DevMode/tests/Feature/TripleGateTest.php, Modules/DevMode/tests/Feature/WorkerHeartbeatTest.php, Modules/DevMode/tests/Feature/DestructiveTripleGateRoundTripTest.php</files>
  <behavior>
    - Test 1 (TripleGate): server-side rejection — `confirm()` throws ValidationException with `_gate=dev_mode_off` when DevModeFlag::isOn() returns false.
    - Test 2 (TripleGate): rejection with `_gate=advanced_off` when session lacks `dev_mode.advanced=true`.
    - Test 3 (TripleGate): rejection with `typed=app_name_mismatch` when typed input is `"Beatrax"` (capital B) — case-sensitive `hash_equals`.
    - Test 4 (TripleGate): success — all three gates pass + `triple-gate:confirmed` event dispatched with command + args.
    - Test 5 (DestructiveSpawn defense-in-depth): POST /dev/artisan/destructive-spawn with a destructive command + missing session.advanced returns 403 (re-validated server-side even though the TripleGateModal already validated).
    - Test 6 (DestructiveSpawn success): POST /dev/artisan/destructive-spawn with all three gates met + valid destructive command spawns the process via 16-04's CommandSpawner, returns 202 + run_id.
    - Test 7 (Heartbeat DI form): registering the QueueManager::looping closure in DevModeServiceProvider::boot() + invoking the looping closure manually (via `app(QueueManager::class)->looping(...)` + then `Queue::push(...) && Artisan::call('queue:work', ['--once' => true])`) writes the cache key `dev_mode.queue_worker_heartbeat` to current timestamp with TTL 60.
    - Test 8 (Login reset): firing `Illuminate\Auth\Events\Login` clears session key `dev_mode.advanced`.
    - Test 9 (round-trip): full happy path — set session.advanced=true; dispatch triple-gate:open with destructive command; type 'beatrax'; click confirm; assert triple-gate:confirmed event; assert a follow-up POST to DestructiveSpawnController spawns the run + writes an audit row tagged tier=destructive.
  </behavior>
  <action>
    Step 1 — Create `DevModeFlag` per &lt;interfaces&gt;.

    Step 2 — Create `TripleGateModal` Livewire component per &lt;interfaces&gt;. Method-DI on `confirm(CurrentUser $user, DevModeFlag $devMode, SessionRepository $session): void`. Create the Blade view per UI-SPEC § Triple-gate modal (rose-tinted header, `.gate-cmd` mono preview, disabled-until-match primary button via Alpine `x-data` watching input, no-close-on-scrim via Flux's `:dismissible="false"`).

    Step 3 — Create `DestructiveSpawnController` per &lt;interfaces&gt;. Re-validates triple-gate server-side. Calls 16-04's `CommandSpawner::start(..., 'destructive')`.

    Step 4 — Create the two listeners per &lt;interfaces&gt;. **W-8 FIX: wire heartbeat via DI form, NOT event-listener:**
      ```
      // In DevModeServiceProvider::boot():
      $this->app->make(\Illuminate\Queue\QueueManager::class)->looping(function () {
          $this->app->make(WriteWorkerHeartbeat::class)();
      });
      $events->listen(\Illuminate\Auth\Events\Login::class, ResetAdvancedToggleOnLogin::class);
      ```

    Step 5 — Register the route for DestructiveSpawnController in `Modules/DevMode/Routes/web.php`:
      ```
      Route::post('/artisan/destructive-spawn', DestructiveSpawnController::class)->name('dev.artisan.destructive-spawn');
      ```

    Step 6 — Tests covering 9 behaviors. For Test 7 (heartbeat DI form), drive via `Artisan::call('queue:work', ['--once' => true])` after queueing a fake job; assert cache key written within 5s. For Test 9 (round-trip), use Livewire::test on TripleGateModal + then a separate HTTP POST to /dev/artisan/destructive-spawn.
  </action>
  <verify>
    <automated>cd /Users/wesselverheij/Development/diederik &amp;&amp; vendor/bin/pest --filter='TripleGate|WorkerHeartbeat|DestructiveTripleGateRoundTrip' &amp;&amp; vendor/bin/pest --parallel</automated>
  </verify>
  <done>TripleGateModal enforces all 3 gates server-side; DestructiveSpawnController re-validates + spawns through 16-04; QueueManager::looping DI-form registers heartbeat (W-8 verified — heartbeat key written by queue:work --once); Login listener clears session; 9 tests pass.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: ArtisanRunnerPage + AuditLogPage + Blade components (run-card/status-pill/tier-chip) + fallback SAFE-only modal + sidebar enable</name>
  <files>Modules/DevMode/Internal/Http/Livewire/ArtisanRunnerPage.php, Modules/DevMode/Internal/Http/Livewire/AuditLogPage.php, Modules/DevMode/Resources/views/livewire/artisan-runner-page.blade.php, Modules/DevMode/Resources/views/livewire/audit-log-page.blade.php, Modules/DevMode/Resources/views/components/run-card.blade.php, Modules/DevMode/Resources/views/components/status-pill.blade.php, Modules/DevMode/Resources/views/components/tier-chip.blade.php, Modules/DevMode/Resources/views/layouts/dev-shell.blade.php, Modules/DevMode/Providers/DevModeServiceProvider.php, Modules/DevMode/Routes/web.php, Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php</files>
  <behavior>
    - Test 1 (ArtisanRunner): GET /dev/artisan as developer renders the page header + filter chips + worker-pre-flight pill (heartbeat cache-empty → "Queue worker: NOT RUNNING") + empty timeline.
    - Test 2 (ArtisanRunner — SAFE end-to-end): firing `cache:clear` via the fallback Flux modal triggers POST to 16-04's /dev/artisan/spawn; after the streamed run finishes (FinalizeRunAudit writes the audit row), the run-card on a subsequent GET shows status=done.
    - Test 3 (Fallback modal SAFE-only — B-2 fix): GET /dev/artisan as developer + render the fallback modal HTML → assert it contains EVERY safe command name as a button; assert it contains ZERO destructive command names (e.g. no `db:restore`, no `migrate:fresh`). Render the page source + grep — no destructive literal appears in the modal markup.
    - Test 4 (AuditLog): GET /dev/audit shows rows from prior runs; tier chip matches the run's tier; non-zero exit codes render with rose-600 color (assert class presence).
    - Test 5 (AuditLog): filtering via `?tier=destructive` returns only destructive-tier rows.
    - Test 6 (Sidebar): GET any /dev/* page; the sidebar HTML shows the Artisan + Audit items WITHOUT the `nav-disabled` class.
  </behavior>
  <action>
    Step 1 — Create Blade components per UI-SPEC § Cross-cutting:
      - `status-pill.blade.php`: variants ok|warn|fail|muted. 2px 8px padding; 6px dot prefix; tabular-nums.
      - `tier-chip.blade.php`: variants safe|destructive. 2px 7px padding; 10.5px uppercase JetBrains Mono; emerald (safe) / rose (destructive).
      - `run-card.blade.php`: takes `:run` prop. Full structure per UI-SPEC § Artisan timeline (head with status pill + mono cmd + tier chip + meta; dark-inset `.run-card-out` 180px max-height; state-dependent action buttons). For running runs the `<pre>` has `x-data="runCardStream({ url: '/dev/artisan/stream/' + runId })"` Alpine binding that opens an EventSource + appends `data:` events.

    Step 2 — Create `ArtisanRunnerPage` Livewire component per &lt;interfaces&gt;. Method-DI on `render(CurrentUser $user, ViewFactory $views, CacheRepository $cache, DevCommandRegistry $registry): View`. Mount() resets advanced toggle on first-load-per-session. Public methods: `spawn(string $command, array $args)` (routes through TripleGateModal for destructive; bypasses for safe), `rerun(string $runId)` (same routing).

    Step 3 — Create `AuditLogPage` Livewire component per &lt;interfaces&gt;. `#[Url]` filter properties: tier, caller, command. Dense table per UI-SPEC.

    Step 4 — Register both components + TripleGateModal in `DevModeServiceProvider::boot()`:
      ```
      $livewire->component('dev.artisan-runner-page', ArtisanRunnerPage::class);
      $livewire->component('dev.audit-log-page', AuditLogPage::class);
      $livewire->component('dev.triple-gate-modal', TripleGateModal::class);
      ```
      Also mount TripleGateModal globally in `Modules/DevMode/Resources/views/layouts/dev-shell.blade.php` body so any page can dispatch `triple-gate:open`.

    Step 5 — Register the page routes (NOT the spawn/stream/cancel — those are 16-04's):
      ```
      Route::get('/artisan', ArtisanRunnerPage::class)->name('dev.artisan');
      Route::get('/audit', AuditLogPage::class)->name('dev.audit');
      ```

    Step 6 — Update `Modules/DevMode/Resources/views/layouts/dev-shell.blade.php`: remove the `nav-disabled` class from the Artisan + Audit items. Per W-3 fix, use explicit allow-list (NOT `Route::has`): hardcode the enabled-slugs as `['overview', 'artisan', 'audit']` until 16-06 lands DevSidebarItems service.

    Step 7 — Fallback modal Blade view (B-2 fix critical):
      ```
      <flux:modal name="run-command">
          {{-- B-2 fix: SAFE-tier ONLY. DESTRUCTIVE commands deliberately omitted from this modal
               per D-41 (palette excludes DESTRUCTIVE to prevent muscle-memory disasters).
               First-time DESTRUCTIVE runs are reachable via the palette (16-08) or php artisan CLI;
               subsequent DESTRUCTIVE runs are reachable via the timeline's per-row Re-run affordance
               which routes through TripleGateModal. --}}
          @foreach ($registry->safe() as $spec)
              <button wire:click="spawn('{{ $spec->name }}', {})">{{ $spec->label }}</button>
          @endforeach
      </flux:modal>
      ```
      The comment is load-bearing — keeps future maintainers from "fixing" the perceived gap by adding destructive commands here.

    Step 8 — Tests for 6 behaviors. Test 3 is the canonical B-2 regression guard.
  </action>
  <verify>
    <automated>cd /Users/wesselverheij/Development/diederik &amp;&amp; vendor/bin/pest --filter='ArtisanRunnerSafeTier|AuditLogPage' &amp;&amp; vendor/bin/pest --parallel &amp;&amp; vendor/bin/phpstan analyse --memory-limit=2G &amp;&amp; vendor/bin/pint --test</automated>
  </verify>
  <done>/dev/artisan + /dev/audit fully functional; SAFE streams via 16-04 SSE + audits via 16-04b FinalizeRunAudit; DESTRUCTIVE via triple-gate + DestructiveSpawnController; fallback modal is SAFE-only (B-2 fix verified by Test 3); sidebar Artisan + Audit enabled; 6 tests pass.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser → POST /dev/artisan/destructive-spawn | EnsureDeveloperMode + re-validated triple-gate (DevModeFlag + session.advanced + typed=beatrax). Defense-in-depth: TripleGateModal already validated, but the controller re-checks because the actual spawn happens here. |
| triple-gate confirm → DestructiveSpawnController | Three independent server-side checks before spawn. UI-only gates would be insufficient. |
| FinalizeRunAudit hook → dev_mode_audit | Excerpts pass through RedactionExcerptCap BEFORE write (basic Bearer + JWT; 16-05 adds full OAuth scrub-set). |
| Fallback modal → SAFE commands ONLY (B-2 fix) | The modal renders `$registry->safe()` only; DESTRUCTIVE commands never appear in this surface. Prevents muscle-memory disasters per D-41. Test 3 in Task 3 is the regression guard. |
| Laravel logger() → disk (NEW — W-1 fix) | Every Monolog record on the `stack`, `single`, and `daily` channels passes through `PushRedactProcessor → RedactSecretsProcessor` BEFORE the formatter writes the line to `storage/logs/laravel*.log`. Baseline scrubs Bearer + JWT; 16-05 adds OAuth scrub-set inside the SAME processor class via constructor DI without touching this wiring. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-16-02 | Tampering | Destructive command run by mis-click or tampered Livewire payload | mitigate | Triple-gate enforced server-side in `TripleGateModal::confirm()` (3 checks) + RE-VALIDATED in DestructiveSpawnController (3 checks again). Two independent server-side enforcement points. Test 5 in Task 2 covers the controller-side re-validation. |
| T-16-02b (B-2 regression) | Tampering | Fallback modal exposes DESTRUCTIVE shortcuts | mitigate | The fallback Flux modal renders `$registry->safe()` only; never `$registry->all()` or `$registry->destructive()`. Test 3 in Task 3 asserts no destructive command name appears in the modal HTML. The Blade view has a load-bearing comment documenting the rationale (D-41 / B-2). |
| T-16-13 | Information Disclosure | dev_mode_audit excerpts contain sensitive content | mitigate (basic) | RedactionExcerptCap applies Bearer + JWT regex + 8KB cap before write (this plan baseline). 16-05 upgrades to full OAuth scrub-set in the SAME wave (Wave 5 immediately after Wave 4) so the window is short. |
| T-16-14 | Elevation of Privilege | Wire-click flipping Advanced toggle from non-developer | mitigate | AdvancedToggleController (16-04) requires /dev group middleware. Non-developers cannot reach the route (404). |
| T-16-15 | Information Disclosure | SSE stream cross-user inspection | mitigate | 16-04's ArtisanStreamController cross-checks callerUserId — 16-04b inherits this guarantee + the FinalizeRunAudit hook does not break it. |
| T-16-16 | Tampering | SIGTERM forged cancel | mitigate | 16-04's ArtisanCancelController reads PID from cache; 16-04b doesn't touch the cancel path. |
| T-16-W8 (W-8 regression) | DoS | Worker heartbeat silently never fires under queue:work | mitigate | DI-form `QueueManager::looping(closure)` registration verified to fire under `queue:work --once` via Test 7 in Task 2. The event-listener form is explicitly NOT used. |
| **T-16-W1 (W-1 baseline)** | **Information Disclosure** | **Bearer/JWT tokens leak to `storage/logs/laravel*.log` BEFORE 16-05 lands** | **mitigate** | **Baseline `RedactSecretsProcessor` + `PushRedactProcessor` installed in THIS plan (Wave 4); `config/logging.php` `stack`/`single`/`daily` tap slots filled with `PushRedactProcessor::class` in the same commit. Tests 11 + 12 in Task 1 lock the wiring + the end-to-end on-write behavior. 16-05 upgrades the processor in place (adds `OAuthScrubSet`) without touching the wiring; the upgrade contract is documented in the class PHPDoc + in 16-05's plan.** |
</threat_model>

<verification>
- `vendor/bin/pest --filter='AuditLogWrite|TripleGate|WorkerHeartbeat|DestructiveTripleGateRoundTrip|ArtisanRunnerSafeTier|PruneDevAudit|RedactSecretsProcessorBaseline'` — all green.
- `vendor/bin/pest --parallel` — full suite green.
- `vendor/bin/phpstan analyse --memory-limit=2G` — Larastan L10 strict clean.
- `vendor/bin/pint --test` — Pint clean.
- `php artisan route:list | grep /dev` — overview / artisan / audit / artisan/spawn / artisan/stream / artisan/cancel / artisan/destructive-spawn / advanced-toggle all present + gated.
- `php artisan tinker --execute='echo json_encode([config("logging.channels.stack.tap"), config("logging.channels.single.tap"), config("logging.channels.daily.tap")]);'` — each of the three arrays contains `Modules\DevMode\Internal\Logging\PushRedactProcessor` (W-1 wiring smoke).
- `git diff config/logging.php` (post-edit, pre-commit) — exactly three single-line changes (the three `'tap' => []` lines became `'tap' => [\Modules\DevMode\Internal\Logging\PushRedactProcessor::class],`); no other config keys touched.
- Manual smoke: `composer dev`; visit /dev/artisan; click `cache:clear` in the fallback modal → live stream + audit row written; visit a previous DESTRUCTIVE run → click Re-run → triple-gate appears → type `Beatrax` (wrong case) → primary disabled → type `beatrax` → primary enables → click Run → DestructiveSpawnController spawns + streams + audits. Inspect the fallback modal HTML — no destructive command name appears (B-2 fix). Trigger a `logger()->info('test Authorization: Bearer abc.def.ghi')` from `php artisan tinker`; tail `storage/logs/laravel-{today}.log` — observe `Authorization: Bearer [REDACTED]` (W-1 end-to-end smoke).
- DEVUI-02 + DEVUI-03 fully satisfied.

</verification>

<success_criteria>
- 16-04's SSE pipeline + 16-04b's audit hook produce a dev_mode_audit row for every SAFE run via FinalizeRunAudit.
- DESTRUCTIVE execution requires triple-gate (server-side, 3 checks at modal + 3 checks at controller).
- DESTRUCTIVE commands are NOT exposed in the fallback modal (B-2 regression-guarded by Task 3 Test 3).
- AuditEvent enum locks the taxonomy (per I-5 fix) — no free-form audit-action strings.
- Queue::looping heartbeat registered via DI form (per W-8 fix) — heartbeat key written under queue:work --once.
- /dev/artisan + /dev/audit pages render per UI-SPEC; sidebar Artisan + Audit enabled.
- **W-1 baseline on-write log redaction is live in Wave 4: `RedactSecretsProcessor` + `PushRedactProcessor` exist at `Modules/DevMode/Internal/Logging/`; `config/logging.php` `stack` + `single` + `daily` channels reference `PushRedactProcessor::class` in their `tap` arrays; `logger()->info('... Authorization: Bearer ...')` writes `Authorization: Bearer [REDACTED]` to disk (Test 12); the upgrade contract for 16-05 (add `OAuthScrubSet` to the processor constructor without changing FQCN/signature/config) is documented in the class PHPDoc.**
- Pest + Larastan + Pint all green.
</success_criteria>

<output>
Create `.planning/phases/16-developer-mode-ui/16-04b-SUMMARY.md` when done. The SUMMARY MUST document:
1. The split rationale (B-5: 29-file scope was too risky for one plan; 16-04 owns process pipeline, 16-04b owns audit + UI + triple-gate + baseline log redaction).
2. The fallback-modal SAFE-only design + the Test 3 regression guard (B-2 fix).
3. The Queue::looping DI-form registration (W-8 fix) and why the event-listener form was rejected.
4. The AuditEvent enum taxonomy (I-5 fix) and how 16-06's queue.* + 16-07's sql.* cases extend it.
5. The FinalizeRunAudit hook contract between 16-04's stream controller and 16-04b's audit pipeline.
6. The stdout-vs-stderr merged-tmp-file limitation (the per-run tmp file captures `2>&1` merged output; splitting requires architecture change deferred to v2).
7. The sidebar Artisan + Audit enable + the W-3 explicit-allow-list approach (vs Route::has) + the 16-06 DevSidebarItems migration path.
8. **The W-1 baseline log-redaction install — `RedactSecretsProcessor` + `PushRedactProcessor` FQCNs, the three `config/logging.php` channel tap slots filled, the upgrade contract for 16-05 (constructor-DI change only; FQCN + signature + wiring stable), and why this lands in Wave 4 rather than Wave 5 (closes the on-write redaction-gap window in the same wave as the runner). Note the distinction between `RedactionExcerptCap` (audit-log excerpt layer, consumed by SpatieAuditWriter) and `RedactSecretsProcessor` (Monolog on-write layer, consumed by the logger tap pipeline) — both upgrade in 16-05 but live at different paths and serve different threat boundaries.**
</output>
</content>
</invoke>