---
phase: 16-developer-mode-ui
reviewed: 2026-05-24T00:00:00Z
depth: standard
files_reviewed: 159
files_reviewed_list:
  - Modules/Auth/Internal/Console/GrantDevCommand.php
  - Modules/Auth/Internal/Console/RegenerateRecoveryCodesCommand.php
  - Modules/Auth/Internal/Console/ResetPasswordCommand.php
  - Modules/Auth/Providers/AuthServiceProvider.php
  - Modules/Auth/Routes/web.php
  - Modules/Auth/tests/Feature/CrossUserIsolationTest.php
  - Modules/Auth/tests/Feature/GrantDevCommandTest.php
  - Modules/Auth/tests/Feature/RegenerateRecoveryCodesCommandTest.php
  - Modules/Chains/tests/Feature/CrossUserChainLinkIsolationTest.php
  - Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php
  - Modules/Core/Internal/Console/BackupDatabaseCommand.php
  - Modules/Core/Internal/Console/DoctorCommand.php
  - Modules/Core/Internal/Console/FailedJobsCommand.php
  - Modules/Core/Internal/Console/InstallCommand.php
  - Modules/Core/Internal/Http/Livewire/AppSidebar.php
  - Modules/Core/Internal/Http/Livewire/Dashboard.php
  - Modules/Core/Internal/Http/Livewire/SettingsPage.php
  - Modules/Core/Providers/CoreServiceProvider.php
  - Modules/Core/Public/Services/UserDataPathService.php
  - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/settings-page.blade.php
  - Modules/Core/tests/Feature/AppSidebarDevBlockLiveDataTest.php
  - Modules/Core/tests/Feature/AppSidebarRenderTest.php
  - Modules/Core/tests/Feature/SettingsPageDevModeToggleTest.php
  - Modules/Desktop/Internal/Native/AppMenuBuilder.php
  - Modules/DevMode/Database/Migrations/2026_05_24_000001_create_dev_mode_audit_table.php
  - Modules/DevMode/Internal/Audit/DevModeActivity.php
  - Modules/DevMode/Internal/Audit/FinalizeRunAudit.php
  - Modules/DevMode/Internal/Audit/NullAuditWriter.php
  - Modules/DevMode/Internal/Audit/RedactionExcerptCap.php
  - Modules/DevMode/Internal/Audit/SpatieAuditWriter.php
  - Modules/DevMode/Internal/CommandRegistry.php
  - Modules/DevMode/Internal/Console/PruneDevAuditCommand.php
  - Modules/DevMode/Internal/Doctor/ProbeOutputParser.php
  - Modules/DevMode/Internal/Enums/AuditEvent.php
  - Modules/DevMode/Internal/Http/Controllers/AdvancedToggleController.php
  - Modules/DevMode/Internal/Http/Controllers/ArtisanCancelController.php
  - Modules/DevMode/Internal/Http/Controllers/ArtisanSpawnController.php
  - Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php
  - Modules/DevMode/Internal/Http/Controllers/DestructiveSpawnController.php
  - Modules/DevMode/Internal/Http/Controllers/LogStreamController.php
  - Modules/DevMode/Internal/Http/Livewire/ArtisanRunnerPage.php
  - Modules/DevMode/Internal/Http/Livewire/AuditLogPage.php
  - Modules/DevMode/Internal/Http/Livewire/CommandPaletteModal.php
  - Modules/DevMode/Internal/Http/Livewire/DevOverviewPage.php
  - Modules/DevMode/Internal/Http/Livewire/DoctorPanelPage.php
  - Modules/DevMode/Internal/Http/Livewire/HorizonFramePage.php
  - Modules/DevMode/Internal/Http/Livewire/LogTailerPage.php
  - Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php
  - Modules/DevMode/Internal/Http/Livewire/SqlPanelPage.php
  - Modules/DevMode/Internal/Http/Livewire/SystemSnapshotPage.php
  - Modules/DevMode/Internal/Http/Livewire/TripleGateModal.php
  - Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php
  - Modules/DevMode/Internal/Listeners/BustOAuthScrubSetOnSecretChange.php
  - Modules/DevMode/Internal/Listeners/ResetAdvancedToggleOnLogin.php
  - Modules/DevMode/Internal/Listeners/WriteWorkerHeartbeat.php
  - Modules/DevMode/Internal/Logging/PushRedactProcessor.php
  - Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php
  - Modules/DevMode/Internal/Navigation/AppActionRegistryImpl.php
  - Modules/DevMode/Internal/Navigation/DevSidebarItems.php
  - Modules/DevMode/Internal/Navigation/NavigationRegistryImpl.php
  - Modules/DevMode/Internal/Process/CommandSpawner.php
  - Modules/DevMode/Internal/Process/FileTailer.php
  - Modules/DevMode/Internal/Process/RunRecord.php
  - Modules/DevMode/Internal/Process/RunRegistry.php
  - Modules/DevMode/Internal/Queue/QueueActions.php
  - Modules/DevMode/Internal/Registries/NullAppActionRegistry.php
  - Modules/DevMode/Internal/Registries/NullDevCommandRegistry.php
  - Modules/DevMode/Internal/Registries/NullNavigationRegistry.php
  - Modules/DevMode/Internal/Services/DevModeFlag.php
  - Modules/DevMode/Internal/Services/OAuthScrubSet.php
  - Modules/DevMode/Internal/Sql/ReadOnlySqliteConnection.php
  - Modules/DevMode/Internal/Sql/SchemaSnapshot.php
  - Modules/DevMode/Internal/Sql/SelectOnlyValidator.php
  - Modules/DevMode/Internal/Sql/WallClockCap.php
  - Modules/DevMode/Internal/System/ConfigFlattener.php
  - Modules/DevMode/Providers/DevModeServiceProvider.php
  - Modules/DevMode/Public/Contracts/AppActionRegistry.php
  - Modules/DevMode/Public/Contracts/AuditWriter.php
  - Modules/DevMode/Public/Contracts/DevCommandRegistry.php
  - Modules/DevMode/Public/Contracts/NavigationRegistry.php
  - Modules/DevMode/Public/Dto/AppAction.php
  - Modules/DevMode/Public/Dto/ArgSpec.php
  - Modules/DevMode/Public/Dto/CommandSpec.php
  - Modules/DevMode/Public/Dto/NavigationEntry.php
  - Modules/DevMode/Public/Models/FailedJob.php
  - Modules/DevMode/Public/Models/Job.php
  - Modules/DevMode/Public/Models/JobBatch.php
  - Modules/DevMode/Resources/views/components/run-card.blade.php
  - Modules/DevMode/Resources/views/components/status-pill.blade.php
  - Modules/DevMode/Resources/views/components/tier-chip.blade.php
  - Modules/DevMode/Resources/views/layouts/dev-shell.blade.php
  - Modules/DevMode/Resources/views/livewire/artisan-runner-page.blade.php
  - Modules/DevMode/Resources/views/livewire/audit-log-page.blade.php
  - Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php
  - Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php
  - Modules/DevMode/Resources/views/livewire/doctor-panel-page.blade.php
  - Modules/DevMode/Resources/views/livewire/horizon-frame-page.blade.php
  - Modules/DevMode/Resources/views/livewire/log-tailer-page.blade.php
  - Modules/DevMode/Resources/views/livewire/queue-inspector-page.blade.php
  - Modules/DevMode/Resources/views/livewire/sql-panel-page.blade.php
  - Modules/DevMode/Resources/views/livewire/system-snapshot-page.blade.php
  - Modules/DevMode/Resources/views/livewire/triple-gate-modal.blade.php
  - Modules/DevMode/Routes/web.php
  - Modules/DevMode/composer.json
  - Modules/DevMode/tests/Feature/AppMenuDeveloperSubmenuTest.php
  - Modules/DevMode/tests/Feature/ArtisanCancelTest.php
  - Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php
  - Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php
  - Modules/DevMode/tests/Feature/AuditLogWriteTest.php
  - Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php
  - Modules/DevMode/tests/Feature/CommandRegistryTest.php
  - Modules/DevMode/tests/Feature/CommandSpawnerTest.php
  - Modules/DevMode/tests/Feature/DashboardFailedToastGatingTest.php
  - Modules/DevMode/tests/Feature/DestructiveTripleGateRoundTripTest.php
  - Modules/DevMode/tests/Feature/DevOverviewPageTest.php
  - Modules/DevMode/tests/Feature/DoctorPanelParserTest.php
  - Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php
  - Modules/DevMode/tests/Feature/HorizonIframeGatingTest.php
  - Modules/DevMode/tests/Feature/LogStreamControllerTest.php
  - Modules/DevMode/tests/Feature/LogTailerPageTest.php
  - Modules/DevMode/tests/Feature/OAuthScrubSetBustTest.php
  - Modules/DevMode/tests/Feature/OAuthSecretDeletionStopsScrubbingTest.php
  - Modules/DevMode/tests/Feature/PaletteLayoutMountTest.php
  - Modules/DevMode/tests/Feature/QueueBulkDeleteTripleGateTest.php
  - Modules/DevMode/tests/Feature/QueueInspectorActionsTest.php
  - Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php
  - Modules/DevMode/tests/Feature/SqlPanelAuditTest.php
  - Modules/DevMode/tests/Feature/SystemSnapshotPageTest.php
  - Modules/DevMode/tests/Feature/TripleGateTest.php
  - Modules/DevMode/tests/Feature/WorkerHeartbeatTest.php
  - Modules/DevMode/tests/Pest.php
  - Modules/DevMode/tests/TestCase.php
  - Modules/DevMode/tests/Unit/EnvSnapshotRedactionTest.php
  - Modules/DevMode/tests/Unit/RedactSecretsProcessorBaselineTest.php
  - Modules/DevMode/tests/Unit/RedactSecretsProcessorTest.php
  - Modules/DevMode/tests/Unit/SelectOnlyValidatorTest.php
  - Modules/DriftAlerts/tests/Feature/TopNavDriftBadgeTest.php
  - Modules/EmailScan/Public/Services/OAuthSecretsRepository.php
  - Modules/EmailScan/tests/Feature/TopNavBadgeViaComposerTest.php
  - Modules/Forecasting/tests/Feature/TopNavForecastSlotTest.php
  - Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php
  - Modules/Recurring/tests/Feature/TopNavBadgeComposerTest.php
  - bootstrap/providers.php
  - config/activitylog.php
  - config/app.php
  - config/database.php
  - config/logging.php
  - config/nativephp.php
  - resources/css/app.css
  - resources/js/app.js
  - resources/js/palette.js
  - resources/views/layouts/app.blade.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Contracts/SelectOnlyValidatorContractTest.php
  - tests/Feature/BeatraxCommandsResolveTest.php
  - tests/Pest.php
  - tests/Snapshot/SidebarTest.php
findings:
  critical: 4
  warning: 11
  info: 6
  total: 21
status: issues_found
---

# Phase 16: Code Review Report

**Reviewed:** 2026-05-24
**Depth:** standard
**Files Reviewed:** 159
**Status:** issues_found

## Summary

Phase 16 ships a substantial in-app Developer Console (artisan runner, SQL panel, log tailer, queue inspector, doctor, command palette, system snapshot, audit log) gated by `EnsureDeveloperMode` middleware with a triple-gate ceremony for destructive operations. The defense-in-depth scaffolding (parse-time SELECT validator + `PRAGMA query_only = 1`, three-guard injection resistance, OAuth scrub-set with cache bust observer, cross-user ownership check on SSE streams, hash_equals for typed app-name) is well-conceived and overall thoroughly tested.

However, the review surfaces **four BLOCKERs**:

1. The `CommandSpawner::renderArg()` option-rendering branch produces malformed shell tokens of the form `'--name=='value''` — any artisan command exposed with an `--option` arg (`queue:retry --queue`) will receive a literal `--queue==default` argv token that artisan will reject. This breaks every destructive AND safe option-bearing command silently.
2. The `'beatrax:failed-jobs prune'` SAFE-tier entry registers a single command name containing a space; `escapeshellarg()` quotes it as one shell token so artisan sees a single garbage command name and refuses to run.
3. The Artisan Runner page's fallback Flux modal calls `wire:click="spawn('...', {})"` and Re-run buttons call `wire:click="rerun('...')"`, but neither `spawn` nor `rerun` is defined on `ArtisanRunnerPage` — every click path from the page is non-functional (a 500 from Livewire's method dispatcher).
4. The Horizon iframe page renders `<iframe src="/horizon">` with no sandbox, CSP, or referrer-policy attributes, and Horizon's dashboard itself ships no `X-Frame-Options` / `Content-Security-Policy: frame-ancestors` header — any developer who briefly visits a hostile page while `/dev/horizon` is open could be subject to clickjacking against Horizon's destructive actions (cancel/retry/pause).

In addition the review surfaces a notable number of WARNINGs around facade/helper usage that violates the CLAUDE.md DI-only rule (config/logging.php, DevMode routes, dev-shell layout) and a couple of correctness edge cases (LogStreamController seeking past EOF, audit-page caller-filter still returning all rows on User-not-found, AppMenuBuilder advertising ⌘K → dev.overview which is the wrong target). None of those individually block ship but they accumulate.

## Critical Issues

### CR-01: CommandSpawner emits malformed `--option` argv tokens — every command with an option arg is silently broken

**File:** `Modules/DevMode/Internal/Process/CommandSpawner.php:202-206`
**Issue:** `renderArg()` returns `[escapeshellarg($argSpec->name.'=').'='.$escaped]` for an option arg. With `$argSpec->name = '--queue'` and `$value = 'default'`, that string-concatenates to `'--queue=' . '=' . 'default'` = literally `'--queue='='default'`. After the shell tokeniser strips the outer single-quotes, artisan receives one argv token: `--queue==default` (note the double `==`). Artisan's option parser splits on the first `=`, so it sees the option name `--queue` with value `=default` (leading `=`). For `queue:retry --queue=default` (the only SAFE-tier option arg today), this produces `Queue [=default] is not defined` or similar at runtime. The bug also pre-prograns every future `--option`-bearing safe AND destructive command, including any `--queue`, `--force`, etc. The contract test `CommandSpawnerTest.php` Test 4 only checks the path/positional injection case and never invokes any `--option`-bearing command, so the bug evades the regression suite.
**Fix:**
```php
// Modules/DevMode/Internal/Process/CommandSpawner.php — renderArg()
if ($isOption) {
    // `--name=value` packs both halves into a single arg so the
    // shell tokeniser does not need to recombine them.
    return [escapeshellarg($argSpec->name.'='.$stringValue)];
}
```
And add a regression test to `CommandSpawnerTest.php` that asserts the spawned `queue:retry --queue=high` actually reaches artisan as `--queue=high` (e.g. invoke an artisan command that echoes its options to stdout and grep the captured tmp file).

---

### CR-02: SAFE-tier `'beatrax:failed-jobs prune'` registers a non-existent command name — runs always fail

**File:** `Modules/DevMode/Providers/DevModeServiceProvider.php:120-126`
**Issue:** The CommandSpec is registered with `name: 'beatrax:failed-jobs prune'` — a single string containing a space. `CommandSpawner::buildShellCommand()` (line 130) wraps this through `escapeshellarg($command)`, producing the shell token `'beatrax:failed-jobs prune'`. After shell tokenisation artisan receives `php artisan "beatrax:failed-jobs prune"` as one argv item, so Symfony Console looks up a command literally named `beatrax:failed-jobs prune` — which does not exist (the registered Artisan command is `beatrax:failed-jobs` with `action=prune` as a positional argument). Every Dev Console invocation of this entry will exit non-zero with `Command "beatrax:failed-jobs prune" is not defined`. Test `CommandRegistryTest.php` line 32 asserts the spec is named exactly `'beatrax:failed-jobs prune'`, so the test passes while the command is unusable. This is the only SAFE-tier command currently constructed via the action-argument shape; the symptom is invisible unless the operator clicks the row in the fallback modal (which is also broken — see CR-03).
**Fix:** Either (a) split the registration into the underlying command name plus a positional arg in `argsSchema`:
```php
new CommandSpec(
    name: 'beatrax:failed-jobs',
    label: 'Prune failed jobs',
    tier: 'safe',
    argsSchema: [
        new ArgSpec(
            name: 'action',
            label: 'Action',
            type: 'select',
            rules: ['required', 'in:prune'],
            options: ['prune'],
        ),
    ],
    description: 'Prune resolved entries from the Laravel-managed failed_jobs table.',
),
```
or (b) keep the labeled UI presentation but pass the action through `CommandSpawner` as a positional `args[action] = 'prune'`. Either way, update `CommandRegistryTest.php` to assert the corrected name and add a Pest test that spawns this entry and asserts the artisan process exits zero.

---

### CR-03: ArtisanRunnerPage fallback modal + Re-run buttons call non-existent Livewire methods

**File:** `Modules/DevMode/Resources/views/livewire/artisan-runner-page.blade.php:89` and `Modules/DevMode/Resources/views/components/run-card.blade.php:79`
**Issue:** The fallback Flux modal's per-command buttons emit `wire:click="spawn('{{ $spec->name }}', {})"` but `ArtisanRunnerPage` defines no `spawn()` method (only `mount()`, `setFilter()`, `render()`). Livewire's method dispatcher will throw `Livewire\Exceptions\MethodNotFoundException` and emit a 500 to the client — every SAFE-tier command in the fallback modal is broken. Similarly the SAFE-tier `<x-dev::run-card>` "Re-run" button (run-card.blade.php:79) emits `wire:click="rerun('{{ $runId }}')"` with no `rerun()` method anywhere — every Re-run on a safe completed run also throws. The JS literal `{}` for the args argument is also not valid Livewire wire:click syntax (Livewire parses parameters as Alpine/JS literals; this happens to be valid empty-object JSON, but tests in `ArtisanRunnerSafeTierTest.php` only assert command names appear in HTML, never that the click actually dispatches successfully). Net effect: the runner page renders but is functionally a no-op for the operator's primary action paths.
**Fix:** Implement both methods on `ArtisanRunnerPage`, routing through the existing `CommandSpawner` (for SAFE) or dispatching the `triple-gate:open` event (for destructive Re-run):
```php
public function spawn(string $command, CommandSpawner $spawner, CurrentUser $user, DevCommandRegistry $registry): void
{
    $spec = $registry->find($command);
    if ($spec->tier !== 'safe') {
        $this->dispatch('triple-gate:open', command: $command, args: []);
        return;
    }
    $runId = $spawner->start($command, [], $user->id(), 'safe');
    $this->dispatch('toast', message: 'Started '.$command.' (run '.$runId.')');
}

public function rerun(string $runId, RunRegistry $registry, CommandSpawner $spawner, CurrentUser $user): void
{
    $record = $registry->find($runId);
    if ($record === null) return;
    if ($record->tier === 'destructive') {
        $this->dispatch('triple-gate:open', command: $record->command, args: $record->args);
        return;
    }
    $spawner->start($record->command, $record->args, $user->id(), 'safe');
}
```
Then add Pest assertions that `wire:click="spawn(...)"` actually fires the spawner (`Livewire::test(ArtisanRunnerPage::class)->call('spawn', 'cache:clear')` + check RunRegistry).

---

### CR-04: Horizon iframe rendered without sandbox / referrer-policy — clickjacking surface against destructive Horizon actions

**File:** `Modules/DevMode/Resources/views/livewire/horizon-frame-page.blade.php:7-13`
**Issue:** The `<iframe src="/horizon">` is rendered with no `sandbox` attribute, no `referrerpolicy`, and the surrounding HTTP response sends no `Content-Security-Policy: frame-ancestors` or `X-Frame-Options: SAMEORIGIN`. Horizon's dashboard ships destructive UI controls (pause/continue worker, retry failed jobs, kill batches) and Horizon itself does NOT emit a default `X-Frame-Options: DENY` header. Any HTTPS page the developer briefly visits while a `/dev/horizon` tab is open could (a) load a hostile origin in a child iframe within `/horizon`'s nested context if Horizon ever serves a content-injectable view, or (b) — more concretely — embed a transparent `<iframe src="https://beatrax.test/dev/horizon">` of its own inside an attacker-controlled page IF the developer is also logged in to `beatrax.test` and the same browser session leaks via SameSite=lax cookies (which Laravel's default session cookie is). Clicking through the attacker's UI could fire `POST /horizon/api/...` actions. Even if those POSTs are CSRF-protected (Horizon uses Laravel's standard CSRF token, which an attacker cannot read cross-origin), the dashboard's interactive controls also include destructive `GET` deep-links (e.g. opening a job for inspection) and the embedded session still leaks Horizon's existence + queue state via timing/load events.
**Fix:** Add the iframe `sandbox` attribute + a tight `referrerpolicy`, AND emit `Content-Security-Policy: frame-ancestors 'self'` on the response so Horizon cannot be embedded outside `/dev/horizon`:
```html
<iframe
    src="/horizon"
    class="w-full h-[80vh] border-0 rounded-md"
    title="Horizon dashboard"
    sandbox="allow-same-origin allow-scripts allow-forms"
    referrerpolicy="same-origin"
></iframe>
```
And in `HorizonFramePage::render()`, attach a response header (or via middleware on the `dev.horizon` route):
```php
$response = $views->make('dev::livewire.horizon-frame-page');
// then in the controller layer or via a small `frame-ancestors` middleware:
// response()->header('Content-Security-Policy', "frame-ancestors 'self'");
```
Alternatively, surface Horizon as a popout link rather than an iframe — the only reason to iframe-embed is aesthetic, and the security profile is materially worse than an external dashboard link.

---

## Warnings

### WR-01: `BackupDatabaseCommand` `VACUUM INTO` path interpolation is shell-unsafe via SQLite's string literal escape

**File:** `Modules/Core/Internal/Console/BackupDatabaseCommand.php:110-116`
**Issue:** The command builds the VACUUM INTO statement via `sprintf("VACUUM INTO '%s'", $escaped)` where `$escaped = str_replace("'", "''", $destination)`. This is the SQLite string-literal escape, which is correct against quote-injection — but the destination path itself is computed from `$basename` (basename `beatrax-{timestamp}.sqlite`) plus the configured `backups()` directory. The path therefore is operator-controlled-only and not user-supplied today, but the safer pattern is parameter binding rather than string interpolation. SQLite does NOT accept bound parameters for VACUUM INTO's target string (PRAGMA + VACUUM INTO target are parse-time constants), so the interpolation IS unavoidable — but the function should at minimum reject any destination path that contains a NUL byte, single quote, or non-ASCII character before assembling the statement so a future change to `backups()` cannot smuggle a malicious filename in.
**Fix:** Add an `assert($this->isSafeBackupPath($destination))` guard prior to the sprintf that rejects paths matching `/[\x00\n\r]/`. (Out of strict Phase 16 scope but the file is in the review list since the SAFE-tier roster spawns `db:backup`.)

---

### WR-02: `LogStreamController::context()` clamps `$start` to 0 but does not validate `$targetLine < $total` — empty array on negative offset works, but a deliberately huge offset still iterates from `max(0, $targetLine - $radius)` which can equal `$total - 1` and return one stale line

**File:** `Modules/DevMode/Internal/Http/Controllers/LogStreamController.php:204-219`
**Issue:** When `$targetLine` is greater than `$total - 1` (e.g. the operator passes `?line=999999` against a 5-line file), `$end = min($total - 1, $targetLine + $radius)` clamps correctly to `$total - 1`, but `$start = max(0, $targetLine - $radius)` evaluates to e.g. `max(0, 999989)` = `999989`. The `for ($i = $start; $i <= $end; $i++)` loop then never enters (start > end), so the response is empty `lines: []`. This is "safe" but it gives the client no signal that the requested line was out of range — the same response as an empty file. Minor UX bug; not a security issue.
**Fix:** Clamp `$targetLine` to `[0, $total - 1]` before computing the radius window:
```php
$targetLine = min(max(0, $targetLine), max(0, $total - 1));
$start = max(0, $targetLine - $radius);
$end = min($total - 1, $targetLine + $radius);
```

---

### WR-03: `AuditLogPage` username filter falls through with `whereRaw('1 = 0')` — works, but obscures intent

**File:** `Modules/DevMode/Internal/Http/Livewire/AuditLogPage.php:67-78`
**Issue:** When the operator types a username that does not match any `users` row, the query is augmented with `whereRaw('1 = 0')` to force an empty result. This is correct in behaviour but uses raw SQL where `->whereNull('id')->whereNotNull('id')` or `->limit(0)` would be equivalent and clearly intentional. More importantly, the `User::query()->where('username', $this->callerFilter)->first()` call lookup runs an unbounded `LIKE`/`=` query (Eloquent escapes via parameter binding so injection is not a risk) per render — the page polls (no wire:poll, manual render only), so the cost is bounded; but the username lookup should at least be `select id` rather than hydrating the full User model.
**Fix:** Replace with the raw query builder shape the rest of the file already uses:
```php
$callerId = $db->connection()->table('users')
    ->where('username', $this->callerFilter)
    ->value('id');
if ($callerId === null) {
    $audit->limit(0);  // intent-clear way to force empty results
} else {
    $audit->where('causer_id', $callerId);
}
```

---

### WR-04: `AppMenuBuilder::DEV_RUN_COMMAND` accelerator targets `dev.overview` route — not the palette

**File:** `Modules/Desktop/Internal/Native/AppMenuBuilder.php:141-143`
**Issue:** Both Developer submenu entries route to `dev.overview` (`/dev`); only the accelerator differs (`Cmd+.` vs `Cmd+K`). The "⌘K Run a command" entry's expected behaviour per UI-SPEC is to open the command palette — but `Menu::route('dev.overview', ...)->accelerator('Cmd+K')` simply navigates to `/dev` AND has Electron-side `Cmd+K` resolved by the OS menu. Since the OS menu accelerator fires the route handler (navigate to /dev), the palette never opens from this menu item. The body-level `x-on:keydown.window` ⌘K handler in the Blade layouts dispatches `palette:open`, but the OS menu's accelerator runs BEFORE that handler can fire (the menu intercepts the keypress). Net effect: when the focused window is on `/dev/overview`, pressing ⌘K navigates to `/dev` (same page) instead of opening the palette; when on any other view, ⌘K navigates to `/dev` (losing the user's current page).
**Fix:** Either (a) wire the menu item to a JS bridge that dispatches `palette:open` rather than navigating, or (b) drop the accelerator from the menu entirely and rely on the body-level keybind handler, which already covers both layouts. Option (b) is the smaller diff:
```php
Menu::label(self::DEV_RUN_COMMAND), // no route, no accelerator — visual hint only
```
or just remove the `DEV_RUN_COMMAND` entry from the menu.

---

### WR-05: `config/logging.php` + `DevMode/Routes/web.php` use facade-helpers (`config(...)`) — violates DI-only invariant

**File:** `Modules/DevMode/Routes/web.php:134`, `resources/views/layouts/app.blade.php:21-25`
**Issue:** The DevMode routes file calls `config('app.dev_mode')` directly (line 134); the main app layout calls `auth()->check()` + `auth()->user()` + `app()->bound(...)` + `app(...)`. CLAUDE.md (user memory: `feedback_laravel_di_only`) forbids these helpers in non-test code. Blade templates are a documented carve-out for facades when the alternative is too painful, but routes files are not — the BoundaryArchTest already enforces `Illuminate\\Support\\Facades` is not used inside `Modules`, but `config()` is a global helper not a facade so it slips past. The `dev-shell.blade.php` layout uses `@inject` (the cleaner path) for its CurrentUser / Container / DevSidebarItems — `resources/views/layouts/app.blade.php` should be migrated to the same pattern.
**Fix:** In `Modules/DevMode/Routes/web.php` inject the `Config\Repository` via the route-group's closure if dev-mode gating is needed at route-load time, or move the conditional Horizon route registration into the ServiceProvider's `boot()` where DI is available. In `resources/views/layouts/app.blade.php`, replace `auth()->check()` / `auth()->user()` / `app()->bound(...)` with `@inject('currentUser', \Modules\Core\Public\Contracts\CurrentUser::class)` matching the dev-shell pattern.

---

### WR-06: `FinalizeRunAudit` swallows arbitrary Throwables silently in the SSE controller — a corrupt audit pipeline never surfaces

**File:** `Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php:148-155`
**Issue:** The `try { ($finalize)(...); } catch (\Throwable) { /* swallow */ }` in the SSE handler is documented ("audit pipeline has its own logging path; the next run will reveal a systemic problem") but the audit pipeline writes through Spatie's ActivityLogger which itself relies on the DB connection — a DB failure during finalize would silently lose the audit row AND leave no log entry pointing at the failure (the comment says "the audit pipeline has its own logging path" but `SpatieAuditWriter::dispatch` does not actually log on failure). For a security-critical audit surface this is too quiet.
**Fix:** Replace the swallow with a `Log` channel write at minimum:
```php
try {
    ($finalize)($runIdLocal, $exit, $cancelled);
} catch (\Throwable $e) {
    // Best-effort logging — never propagate (must not break SSE)
    try {
        Container::getInstance()->make(\Psr\Log\LoggerInterface::class)
            ->error('FinalizeRunAudit failed for run '.$runIdLocal, ['exception' => $e->getMessage()]);
    } catch (\Throwable) {}
}
```

---

### WR-07: `OAuthScrubSet::load()` swallows DB errors as an empty set — silently disables redaction across the app

**File:** `Modules/DevMode/Internal/Services/OAuthScrubSet.php:138-160`
**Issue:** The `try { ... $rows = OAuthSecret::query()->get(); ... } catch (Throwable) { return []; }` block intentionally returns an empty set on any DB error — comment says "a missing-table or boot-time encrypter blip MUST NOT halt application boot". This is reasonable for boot-time resilience, but at runtime it silently disables redaction across both the Monolog tap AND the audit row pipeline — every OAuth secret value would leak through both surfaces until the next bust + successful load. A long-lived issue with the encrypter or the oauth_secrets table would never surface to the operator.
**Fix:** Differentiate boot-time vs runtime: cache the failure for a short TTL (e.g. 60s) and emit a `system_alerts` row of severity=critical on the first runtime failure so the operator gets a banner. The existing `Modules/Core/Models/SystemAlert` model handles this pattern.

---

### WR-08: `ResetAdvancedToggleOnLogin` is registered against the framework `Login` event, but `Login::class` fires on EVERY successful auth — including "remember-me" hydration

**File:** `Modules/DevMode/Internal/Listeners/ResetAdvancedToggleOnLogin.php` + `Modules/DevMode/Providers/DevModeServiceProvider.php:573`
**Issue:** `Illuminate\Auth\Events\Login` fires on session creation (which is what we want) AND on remember-me cookie auth hydration in a fresh request (which is fine). The listener is correct in scope but the surrounding test `TripleGateTest.php` only exercises an explicit `event(new Login(...))`. The plan documents this as "Advanced toggle resets on every login" — that's correct behaviour. No issue, but the `ArtisanRunnerPage::mount()` belt-and-braces reset (line 53-63) writes to the session every first-load-per-session WITHOUT also writing to a `dev_mode.advanced_session_seen` invalidation when the user logs out. If a developer logs in, flips Advanced ON, signs out, and another user (developer or not) logs in on the same browser, the session is replaced — that's OK. But the `dev_mode.advanced_session_seen` key is on the OLD session and would NOT come into play. Not a defect — just unnecessary code.
**Fix:** Remove the belt-and-braces mount() reset; the `Login` listener already covers all login paths. If it's there for "remember-me hydrated session without Login event", verify that on Laravel 12 — Laravel's Authenticatable hydrate fires `Login` on every cookie-hydrated request.

---

### WR-09: `CommandSpawner::ensureRunsDirectory()` silently swallows `mkdir(0700)` failures and falls through to `is_dir($dir)` recheck — race window leaves directory at default umask

**File:** `Modules/DevMode/Internal/Process/CommandSpawner.php:241-252`
**Issue:** The `if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) { throw ... }` pattern handles the race where two requests both call `mkdir` simultaneously, but on a successful `mkdir(0700, recursive: true)` PHP creates the INTERMEDIATE directories with `0755 & ~umask` — NOT `0700`. So `storage/app/dev_mode` ends up 0755 even though `storage/app/dev_mode/runs` is 0700. On a shared box (multi-user macOS, partner account) the intermediate dir is world-readable, exposing the names of all runs (UUIDs ARE the run IDs).
**Fix:** Walk each directory level individually with explicit chmod:
```php
private function ensureRunsDirectory(string $dir): void
{
    foreach ([dirname($dir), $dir] as $path) {
        if (! is_dir($path)) {
            if (! @mkdir($path, 0700, false) && ! is_dir($path)) {
                throw new RuntimeException(...);
            }
            @chmod($path, 0700);
        }
    }
}
```

---

### WR-10: `Modules/DevMode/Internal/Http/Livewire/AuditLogPage.php` has no rate limit / pagination — operator can scroll an arbitrary number of audit rows

**File:** `Modules/DevMode/Internal/Http/Livewire/AuditLogPage.php:81`
**Issue:** The audit-log page loads exactly 50 rows hard-capped via `->limit(50)`. There is no pagination, no "Load more", no time-range filter. On a busy install (lots of `beatrax:doctor` polling, palette-spawned cache:clear, queue retries) the operator's own dev_mode_audit table will grow to thousands of rows in a week and the audit page caps at the latest 50 with no way to scroll back. Functionally limits the audit trail's usefulness; not a correctness or security issue.
**Fix:** Add cursor pagination (e.g. `?before=<id>`) so a developer can walk the full history.

---

### WR-11: `QueueInspectorPage::bulkDelete()` does not re-validate the per-row caller — the `triple-gate:confirmed` event has no sender authentication

**File:** `Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php:192-210` + `Modules/DevMode/Internal/Http/Livewire/TripleGateModal.php:109-114`
**Issue:** `TripleGateModal::confirm()` dispatches `triple-gate:confirmed` with `command: $this->command, args: $this->resolvedArgs, confirmed_typed: $this->typed`. The `QueueInspectorPage::executeBulkDelete` listener filters on `$command === 'queue.bulk.delete'`, but the listener does NOT re-validate the three gates (DevModeFlag, session.advanced, typed) — it trusts the gate's prior validation. If a future bug in TripleGateModal's gate-validation logic allows a `triple-gate:confirmed` event to escape without full validation (e.g. an exception in confirm() that fires the dispatch before throwing), the queue rows are deleted without a destructive ceremony. The destructive artisan controller's defense-in-depth re-validation is the correct mirror; the queue listener lacks it.
**Fix:** Have `executeBulkDelete` accept `DevModeFlag` + `Session` via method-DI and re-validate the three gates before delegating to `QueueActions::bulkDelete()`. The same fix applies to any other future `#[On('triple-gate:confirmed')]` listener.

---

## Info

### IN-01: Several PHPDocs reference plan/wave/CONTEXT labels — violates GSD-agnostic codebase rule

**File:** Throughout `Modules/DevMode/**` — e.g. `CommandSpawner.php:18` ("per CONTEXT D-16"), `SelectOnlyValidator.php:14` ("CONTEXT D-45"), `ResetAdvancedToggleOnLogin.php:12` ("CONTEXT D-20"), `DevModeServiceProvider.php:89-94` ("CONTEXT D-12 SAFE-tier"), `RunRegistry.php:13-25` ("CONTEXT D-16"), and most blade templates
**Issue:** CLAUDE.md (user memory: `feedback_codebase_gsd_agnostic`) requires "No `.planning/` / PLAN.md / RESEARCH.md references in code, PHPDocs, or comments". The DevMode codebase liberally references `CONTEXT D-XX`, `D-29 / D-30`, `T-16-XX`, `RESEARCH § Pattern X`, `Pitfall 7`, `W-1 fix`, `B-2 fix`, `16-04b`, etc. These tags are GSD-internal artifacts that mean nothing to a future maintainer reading the code in isolation.
**Fix:** Strip every CONTEXT/D-/T-/W-/B-/I-/SC tag, plan/wave numbers, and "Phase 16-04b" references from PHPDocs across the DevMode module. Replace with self-contained explanations of WHY the code does what it does. This is a sweeping change (~80+ files) but it brings the module in line with project rules.

---

### IN-02: Several PHPDocs describe historical migration paths instead of current state

**File:** Throughout `Modules/DevMode/**` — e.g. `OAuthScrubSet.php:62-69` ("baseline regression test"), `RedactionExcerptCap.php:34-39` ("the baseline AuditLogWriteTest from 16-04b"), `NullAuditWriter.php:15` ("The 16-04 audit-pipeline plan replaces this binding"), `RedactSecretsProcessor.php:43-50` ("Container resolution contract")
**Issue:** CLAUDE.md (user memory: `feedback_docs_describe_current_state`) requires "Docs describe current state, never history". Many DevMode PHPDocs describe "16-05 upgraded this", "16-04b lands this", "16-08 wires", "16-04 lands the concrete". A future maintainer reading the code does not need to know which plan added what.
**Fix:** Rewrite PHPDocs to describe what the code does NOW, without reference to which plan wave landed which behavior.

---

### IN-03: The `dev-shell.blade.php` layout uses `Route::has(...)` facade inside the @php block — Route facade is on the BoundaryArchTest blocklist

**File:** `Modules/DevMode/Resources/views/layouts/dev-shell.blade.php:127`
**Issue:** The layout calls `\Illuminate\Support\Facades\Route::has($item['route'])` inside the loop. Blade templates are a documented carve-out for facades, but the project's tightening on DI-only suggests the `Router` should be `@inject`-resolved consistent with how `currentUser` / `container` / `devSidebarItems` are injected at the top.
**Fix:** Add `@inject('router', \Illuminate\Routing\Router::class)` at the top and replace `\Illuminate\Support\Facades\Route::has(...)` with `$router->getRoutes()->hasNamedRoute($item['route'])`.

---

### IN-04: `SqlPanelPage::browseTable()` constructs the SELECT via string interpolation rather than the schema-snapshot allowlist

**File:** `Modules/DevMode/Internal/Http/Livewire/SqlPanelPage.php:146-165`
**Issue:** The Browse button passes `$table` through `'"'.str_replace('"', '""', $table).'"'` to produce a safely-quoted SQLite identifier. This is correct for SQLite identifier quoting — but the source of `$table` is the Blade template, which renders the value from `$tables` (the `SchemaSnapshot::all()` output). The wire:click in the Blade interpolates the value as a JS literal `wire:click="browseTable('{{ $table['name'] }}')"` — a single quote in the table name (legal in SQLite) would break the Livewire wire:click parser AND break the SQL identifier escape. Combined with the `SelectOnlyValidator` gate (which only checks the FIRST token), there is no realistic injection vector since SQLite table names rarely contain single quotes — but the safer pattern is to assert `$table` is on the `SchemaSnapshot::all()` whitelist within `browseTable()` itself:
**Fix:**
```php
public function browseTable(string $table, ..., SchemaSnapshot $schema): void
{
    $allowedNames = array_column($schema->all(), 'name');
    if (! in_array($table, $allowedNames, true)) {
        $this->errorMessage = 'Unknown table.';
        return;
    }
    // existing pipeline
}
```

---

### IN-05: `palette.js` `dispatchEntry()` falls back to `window.location.href = '/dev/artisan'` for `dev`-source rows OUTSIDE `/dev/artisan` — silently navigates away

**File:** `resources/js/palette.js:111-117`
**Issue:** When the operator picks a SAFE-tier dev command from the palette while NOT on `/dev/artisan`, the code dispatches `spawn-command` AND navigates to `/dev/artisan`. The navigation discards the dispatch (Livewire is unmounting). The comment acknowledges this ("the runner page is not mounted to receive the dispatch") — but the effect is that the operator's first pick from the palette outside the runner page goes nowhere; they must arrive on /dev/artisan and re-pick.
**Fix:** Either (a) navigate to `/dev/artisan?spawn={name}` and have ArtisanRunnerPage's mount() consume that query param to fire the spawn after the page mounts, or (b) post directly to `/dev/artisan/spawn` from the palette's JS and show a toast for the resulting `run_id`.

---

### IN-06: `LogStreamController` SSE-stream `Cache-Control: no-cache` is incomplete — proxies may still buffer

**File:** `Modules/DevMode/Internal/Http/Controllers/LogStreamController.php:145-148`
**Issue:** The response sets `Cache-Control: no-cache` (private cache control) + `X-Accel-Buffering: no` (nginx-specific). For local-only deployment on Herd this is sufficient — but the conventional SSE header set is `Cache-Control: no-cache, no-transform`. Adding `no-transform` prevents intermediate proxies from gzip-buffering the response in unexpected ways. Minor.
**Fix:**
```php
$response->headers->set('Cache-Control', 'no-cache, no-transform');
```

---

_Reviewed: 2026-05-24_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
