---
phase: 15-desktop-shell-nativephp-integration
reviewed: 2026-05-23T00:00:00Z
depth: standard
files_reviewed: 90
files_reviewed_list:
  - .github/workflows/ci.yml
  - Modules/Categorization/Resources/views/livewire/categorization-provenance-panel.blade.php
  - Modules/Categorization/Resources/views/livewire/correction-divergence-toast.blade.php
  - Modules/Categorization/Resources/views/livewire/inline-category-picker.blade.php
  - Modules/Categorization/Resources/views/livewire/rule-form-modal.blade.php
  - Modules/Categorization/Resources/views/livewire/rules-page.blade.php
  - Modules/Categorization/Resources/views/livewire/triage-inbox.blade.php
  - Modules/Categorization/Resources/views/rules.blade.php
  - Modules/Categorization/Resources/views/triage.blade.php
  - Modules/Chains/Resources/views/livewire/chain-drawer.blade.php
  - Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php
  - Modules/Chains/Resources/views/livewire/partials/chain-node.blade.php
  - Modules/Core/Database/Migrations/2026_05_22_000001_add_theme_to_users.php
  - Modules/Core/Database/Migrations/2026_05_22_000002_add_close_behavior_to_users.php
  - Modules/Core/Internal/Http/Livewire/SettingsPage.php
  - Modules/Core/Models/User.php
  - Modules/Core/tests/Feature/ThemePreferenceTest.php
  - Modules/Desktop/Internal/Http/CloseActionController.php
  - Modules/Desktop/Internal/Http/FileOpenController.php
  - Modules/Desktop/Internal/Http/Livewire/CloseWindowPrompt.php
  - Modules/Desktop/Internal/Http/Livewire/FileStagingPage.php
  - Modules/Desktop/Internal/Http/Livewire/SetupScreen.php
  - Modules/Desktop/Internal/Http/Livewire/WelcomeScreen.php
  - Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php
  - Modules/Desktop/Internal/Listeners/ApplyCloseWindowChoice.php
  - Modules/Desktop/Internal/Listeners/ContinuePendingFileIntentAfterLogin.php
  - Modules/Desktop/Internal/Listeners/DispatchOsNotification.php
  - Modules/Desktop/Internal/Listeners/HandleNativeOpenFile.php
  - Modules/Desktop/Internal/Listeners/NavigateOnNotificationDeepLink.php
  - Modules/Desktop/Internal/Listeners/SurfaceWorkerCrashAlert.php
  - Modules/Desktop/Internal/Native/AppMenuBuilder.php
  - Modules/Desktop/Internal/Native/FileOpenIntake.php
  - Modules/Desktop/Internal/Native/FirstLaunchBootstrap.php
  - Modules/Desktop/Internal/Native/OsThemeProbe.php
  - Modules/Desktop/Internal/Native/PendingFileIntent.php
  - Modules/Desktop/Internal/Native/TrayMenuBuilder.php
  - Modules/Desktop/Internal/Native/WindowCloseBehavior.php
  - Modules/Desktop/Internal/Native/WindowFocusState.php
  - Modules/Desktop/Internal/NativeAppServiceProvider.php
  - Modules/Desktop/Providers/DesktopServiceProvider.php
  - Modules/Desktop/Public/Contracts/OsThemeSignal.php
  - Modules/Desktop/Public/Contracts/RemembersPendingFileIntent.php
  - Modules/Desktop/Public/Events/FileOpenedFromOs.php
  - Modules/Desktop/Public/Events/NotificationDeepLink.php
  - Modules/Desktop/Resources/views/close-window-prompt.blade.php
  - Modules/Desktop/Resources/views/setup.blade.php
  - Modules/Desktop/Resources/views/staging.blade.php
  - Modules/Desktop/Resources/views/welcome.blade.php
  - Modules/Desktop/Routes/web.php
  - Modules/Desktop/composer.json
  - Modules/Desktop/tests/Feature/CloseWindowPromptTest.php
  - Modules/Desktop/tests/Feature/FileOpenedFromOsTest.php
  - Modules/Desktop/tests/Feature/FirstLaunchBootstrapTest.php
  - Modules/Desktop/tests/Feature/WorkerCrashAlertTest.php
  - Modules/Desktop/tests/Pest.php
  - Modules/Desktop/tests/TestCase.php
  - Modules/Desktop/tests/Unit/AppMenuBuilderTest.php
  - Modules/Desktop/tests/Unit/DispatchOsNotificationTest.php
  - Modules/Desktop/tests/Unit/HardenedRuntimeEntitlementsTest.php
  - Modules/Desktop/tests/Unit/NativeAppServiceProviderTest.php
  - Modules/Desktop/tests/Unit/OsThemeProbeTest.php
  - Modules/Desktop/tests/Unit/SurfaceWorkerCrashAlertTest.php
  - Modules/Desktop/tests/Unit/TrayMenuBuilderTest.php
  - Modules/Desktop/tests/Unit/WindowFocusStateTest.php
  - Modules/DriftAlerts/Resources/views/livewire/dashboard-drift-badge.blade.php
  - Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php
  - Modules/DriftAlerts/Resources/views/livewire/drift-threshold-editor.blade.php
  - Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php
  - Modules/EmailScan/Resources/views/livewire/backfill-window-modal.blade.php
  - Modules/EmailScan/Resources/views/livewire/email-scan-health-tile.blade.php
  - Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php
  - Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php
  - Modules/Import/Internal/Listeners/HandleFileOpenedFromOs.php
  - Modules/Receipts/Internal/Listeners/HandleFileOpenedFromOs.php
  - Modules/Receipts/Resources/views/livewire/receipt-conflict-toast.blade.php
  - Modules/Receipts/Resources/views/livewire/wizard-email-file-step.blade.php
  - Modules/Recurring/Resources/views/livewire/fixed-payments-card.blade.php
  - Modules/Recurring/Resources/views/livewire/recurring-page.blade.php
  - Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php
  - Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php
  - bootstrap/providers.php
  - build/entitlements.mac.plist
  - config/nativephp.php
  - resources/css/app.css
  - resources/views/layouts/app.blade.php
  - routes/console.php
  - scripts/nativephp_force_adhoc_signing.php
  - scripts/nativephp_stage_build_resources.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Pest.php
findings:
  critical: 3
  warning: 6
  info: 5
  total: 14
status: issues_found
---

# Phase 15: Code Review Report

**Reviewed:** 2026-05-23
**Depth:** standard
**Files Reviewed:** 90
**Status:** issues_found

## Summary

The desktop shell scaffolding is broadly well-engineered: the `FileOpenIntake` security boundary (canonicalise + extension allow-list + size cap + no shell execution) is correctly designed and tested; the `PendingFileIntent` store is session-scoped with stale-path re-validation; the `WindowCloseBehavior` allow-list rejects bad payloads with `InvalidArgumentException`; and Constructor DI is honoured across the module with a documented facade carve-out matching `BoundaryArchTest`. The macOS Hardened Runtime entitlements file is minimal and correct.

However, three load-bearing pieces of the first-launch / window-close UX are wired up but never actually invoked in production code paths, which would cause the shipped bundle to ship in a partially-broken state. In addition, the `WindowFocusState` defaults the opposite direction from what its docblock claims, which will produce spurious OS notifications immediately after launch. Several smaller correctness and quality issues round out the list.

The dark-theme retrofit (Plan 15-07) appears mechanical and correct: every reviewed Blade carries `dark:` companion utilities on `bg-white` / `text-slate-900` surfaces, matching the `darkCompanionUtilitiesOnThemedViews` arch invariant.

## Critical Issues

### CR-01: `FirstLaunchBootstrap::runPendingMigrations()` is never called in production code

**File:** `Modules/Desktop/Internal/Native/FirstLaunchBootstrap.php:85-92`
**Issue:** The first-launch DB bootstrap method has no production caller. A grep across the codebase (excluding tests and vendor) finds zero invocations of `runPendingMigrations()`. `NativeAppServiceProvider::boot()` does not call it; `DesktopServiceProvider::boot()` does not call it; no artisan command, no listener, no schedule entry calls it. `SetupScreen::render()` only invokes `hasPendingMigrations()` — it reads the pending state but never triggers the run.

This means on a fresh NativePHP bundle launch, the schema is never created and the entire D-21 / D-22 / D-23 first-launch flow is non-functional: the setup screen would spin on `wire:poll.2000ms` forever because `hasPendingMigrations()` will keep returning true, and the welcome / signup path never opens.

The feature tests pass because the Pest `RefreshDatabase` trait migrates the schema for them.

**Fix:** Wire `runPendingMigrations()` into the NativePHP boot path, e.g. from `NativeAppServiceProvider::boot()` (which NativePHP resolves via the container and which already runs once at native-shell startup), executed BEFORE any window is opened:
```php
public function boot(): void
{
    $this->bootstrap->runPendingMigrations();
    $this->windows->open('main')->...;
    // ...
}
```
Add `FirstLaunchBootstrap` to the constructor and a feature test that actually drives the production path (e.g. assert `hasPendingMigrations()` flips false after `NativeAppServiceProvider::boot()`).

### CR-02: `EnsureDatabaseReady` middleware is never registered on any production route

**File:** `Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php`
**Issue:** The middleware exists, is documented, and is exercised by `FirstLaunchBootstrapTest` against ad-hoc stub routes (`/__test/gated*`). But `DesktopServiceProvider::boot()` does not register it as a route- or aliased-middleware, no `bootstrap/app.php` `withMiddleware()` call adds it, and the production routes in `Modules/Desktop/Routes/web.php` (and any other module's routes) do not list it.

Consequence: even if CR-01 is fixed, a request that arrives mid-migration will see whatever Laravel can render with a half-built schema — possibly a 500 (table not found on `User::query()->count()` inside the Welcome flow), possibly an empty dashboard. The "Setting up…" redirect that the entire setup-screen design promises never fires.

**Fix:** Register the middleware globally (or on the `web` group). Likely in `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady::class,
    ]);
});
```
Add a feature test that hits an authenticated route (e.g. `/dashboard`) with the migrations repository dropped and asserts the redirect to `desktop.setup`. The current test only proves the middleware works on a synthetic route — it does not prove the middleware is actually wired.

### CR-03: Window-close prompt has no Electron-side trigger and the route renders an invisible modal

**File:** `Modules/Desktop/Internal/Http/Livewire/CloseWindowPrompt.php`, `Modules/Desktop/Routes/web.php:49-50`, `Modules/Desktop/Resources/views/close-window-prompt.blade.php`
**Issue:** The D-08 close prompt is wired structurally but functionally inert:
1. The Electron main process is never instructed to intercept the window-close (`close` / `before-quit`) event and navigate to `/desktop/close-prompt`. No JS hook in `resources/views/layouts/app.blade.php`, no NativePHP `window->onClose(...)` invocation in `NativeAppServiceProvider::boot()`, and the published Electron `src/main/index.js` (per the docblocks referenced) is described but its registration is not in this codebase. So clicking the X just closes the window — the prompt is never shown.
2. Even if a user navigates directly to `/desktop/close-prompt`, the rendered Livewire view contains a `<flux:modal name="close-window-prompt" dismissible="false">` that is never opened: there is no `mount()` `dispatch('modal-show', ...)`, no auto-open script in the view, and the controller does not push a `modal-show` event. The result is a blank Livewire page with an invisible modal element.

The plan's "JS glue deferred from plan 15-03" note (CloseActionController docblock, line 30) explains the omission, but the route + controller + DB column + persistence path are all live in this phase's deliverable. Without the trigger, every other piece of D-08 work is unreachable user-side.

**Fix:** Either:
- Add the close-intercept in `NativeAppServiceProvider::boot()` so the X-click navigates to `route('desktop.close-prompt')` when `users.close_behavior IS NULL` and applies the persisted choice otherwise, and dispatch `modal-show` from the component's `mount()` so visiting the route surfaces the dialog, OR
- Defer the entire route registration (and `desktop.close-action` endpoint) until the JS glue lands, so the codebase does not advertise a non-working UX.

A test that visits `/desktop/close-prompt` and asserts the rendered HTML contains the open-modal dispatch would catch this regression.

## Warnings

### WR-01: `WindowFocusState` default is `unfocused` but the docblock claims it defaults to "conservative — don't drop notifications"

**File:** `Modules/Desktop/Internal/Native/WindowFocusState.php:25-31`
**Issue:** The docblock says "Default state on construction is `unfocused` — … a deliberately conservative default so notifications during the boot-up race are NOT silently dropped". But the actual policy in `DispatchOsNotification::shouldFire()` is `return ! $this->focus->isFocused();` — i.e. fire when **un**focused. So defaulting to unfocused means **every** notification fired before the first `WindowFocused` event arrives will pop as an OS notification on top of the in-app banner the freshly-launched, focused user is already looking at.

The conservative direction is reversed: defaulting to `focused = true` suppresses the OS notification and lets the in-app banner show; defaulting to `focused = false` (today's code) duplicates the alert.

**Fix:** Either flip the default:
```php
private bool $focused = true;
```
…or eagerly probe NativePHP at boot for the current focus state (likely simpler), and update the docblock to match the chosen direction.

### WR-02: `CloseActionController` returns 422 silently — no error JSON for the JS hook

**File:** `Modules/Desktop/Internal/Http/CloseActionController.php:43-45`
**Issue:** When the POSTed `choice` is missing or off-allow-list, the controller returns an empty 422 body. The JS hook in `app.blade.php` (lines 92-109) ignores the response entirely (`fetch(...)` with no `.then`/`.catch`). If a future bug ever lets a bad payload through (e.g. browser autofill garbage, a Livewire-event-name typo), the failure mode is silent: the user clicks the button, nothing happens, the window does not close, no log entry surfaces. The user has no signal something failed.

**Fix:** Log the rejection (e.g. via constructor-injected `Psr\Log\LoggerInterface`) so operator-side diagnostics show the bad payload. Optionally have the JS hook surface a generic error toast on non-2xx responses. The arch invariant against facade calls means using the contract not `Log::warning(...)`.

### WR-03: `HandleNativeOpenFile::normalize()` accepts any first non-empty string from an associative array

**File:** `Modules/Desktop/Internal/Listeners/HandleNativeOpenFile.php:57-72`
**Issue:** When NativePHP delivers `$event->path` as an array (the "payload-shape drift between NativePHP versions" the docblock mentions), the normalizer returns the FIRST non-empty string value via `foreach ($raw as $value)`. For an associative payload like `['type' => 'open-file', 'path' => '/Users/.../file.csv']`, this would return `'open-file'` — i.e. the event TYPE — rather than the path. The downstream `FileOpenIntake::receive()` then attempts `realpath('open-file')` which will fail closed (so no security regression), but the legitimate file-open is silently dropped.

The bug is that there is no key-aware extraction; iteration order on associative arrays is insertion order, which is fragile.

**Fix:** Prefer a key-aware lookup before falling back to iteration:
```php
if (is_array($raw)) {
    if (isset($raw['path']) && is_string($raw['path']) && $raw['path'] !== '') {
        return $raw['path'];
    }
    foreach ($raw as $value) { /* ... */ }
}
```

### WR-04: `nativephp_force_adhoc_signing.php` skips the patch on a false-positive `identity:` match outside `mac:`

**File:** `scripts/nativephp_force_adhoc_signing.php:79-83`
**Issue:** The guard `preg_match('/\bidentity\s*:/', $source) === 1` matches **any** `identity:` token anywhere in the electron-builder config — including `appx: { identity: ... }`, `publish: { identity: ... }`, or even a JS comment `// identity:`. A future electron-builder upgrade that introduces an unrelated `identity:` key would silently skip the mac-block patch, and the next macOS build would re-acquire the partial-signing bug the script exists to prevent.

The patch itself is correctly scoped to the `mac: {` block, but the IDEMPOTENCY check is not.

**Fix:** Scope the guard to the mac block:
```php
if (preg_match('/\bmac:\s*\{[^}]*\bidentity\s*:/s', $source) === 1) {
    fwrite(STDOUT, "...\n");
    exit(0);
}
```
This still tolerates the legitimate "already patched" state without false-positive matches in unrelated blocks.

### WR-05: `OsThemeProbe` maps `SystemThemesEnum::SYSTEM` to `light` — silently loses information the layout can use

**File:** `Modules/Desktop/Internal/Native/OsThemeProbe.php:38-44`
**Issue:** The match statement collapses both `LIGHT` and `SYSTEM` to `'light'`. The docblock says "the pre-paint `prefers-color-scheme` script in the layout corrects it client-side before first paint", but the layout's pre-paint script ONLY runs when `$userTheme === 'system'` AND there's no server-side bound contract. Since the binding IS bound inside the bundle, the script does not run, and the SYSTEM-themed-OS-no-explicit-preference user gets light mode even if their actual `prefers-color-scheme` is dark.

The cleaner fix is to query `matchMedia` at boot via NativePHP's webview bridge, but more practically: when the probe returns `SYSTEM`, the layout should fall through to the pre-paint script.

**Fix:** Return a nullable signal, or expose a separate "is OS preference explicit" boolean so the layout can decide:
```php
public function currentOsTheme(): string
{
    return match (System::theme()) {
        SystemThemesEnum::DARK => 'dark',
        SystemThemesEnum::LIGHT => 'light',
        SystemThemesEnum::SYSTEM => 'light', // explicit fall-back
    };
}
```
…AND change the layout's `@if ($userTheme === 'system')` block to also emit the pre-paint script when the bundle binding returns SYSTEM (which today is indistinguishable from LIGHT). Alternatively widen the contract:
```php
public function currentOsTheme(): ?string;  // null = OS has no explicit preference; client-side script decides
```
This is a contract change so it needs a coordinated layout edit.

### WR-06: `SurfaceWorkerCrashAlert::escalate()` always fires the OS notification even when no DB row was inserted

**File:** `Modules/Desktop/Internal/Listeners/SurfaceWorkerCrashAlert.php:157-189`
**Issue:** The de-dup check writes the `system_alerts` row only when `$alreadyAlerted` is false, but the OS-notification fire (lines 184-188) happens unconditionally on every crash-loop escalation, regardless of whether the alert row was actually inserted. So a repeat crash-loop while the prior alert is still un-acknowledged will:
1. Skip the system_alerts insert (correct, per de-dup).
2. Fire ANOTHER OS notification (incorrect — the partner is already aware; the focus-gate is the only thing in their way).

Once the partner re-focuses the window to dismiss the banner, the next unfocused crash-loop fires a fresh notification anyway — that's the intended noise. But while the banner is still un-acknowledged on the dashboard the partner doesn't even need to see, the listener spams duplicate OS notifications.

**Fix:** Guard the OS-notification on `! $alreadyAlerted`:
```php
if ($alreadyAlerted || $this->focus->isFocused()) {
    return;
}
Notification::title(...)->message(...)->...->show();
```

## Info

### IN-01: `EnsureDatabaseReady` only exempts one route name — comment promises a "future safety prefix" pattern but the array is fixed

**File:** `Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php:31-37`
**Issue:** The docblock says "future extra-safe URLs (an error variant of the setup screen, for example) can be added by registering them under the same name prefix", but `isExempt()` uses `in_array($name, ..., true)` — strict equality, not a prefix match. Adding a `desktop.setup.error` route would require a code change, not a route-name pattern.
**Fix:** Either change the docblock to reflect the actual exact-match behaviour, or implement the promised prefix matching:
```php
foreach (self::EXEMPT_ROUTE_PREFIXES as $prefix) {
    if (str_starts_with($name, $prefix)) return true;
}
```

### IN-02: `ContinuePendingFileIntentAfterLogin::handle()` is effectively a no-op

**File:** `Modules/Desktop/Internal/Listeners/ContinuePendingFileIntentAfterLogin.php:56-73`
**Issue:** The handler reads `$this->intent->pending()` and then does nothing with it: the comment explains why (the staging page reads the same store on its next navigation). The listener exists purely to trigger the side-effect of `pending()`'s stale-path check. That's a legitimate use case but the code looks like dead code — a future contributor will be tempted to inline-delete the early-return and never realise the side-effect was intentional.
**Fix:** Make the side-effect explicit, e.g.:
```php
public function handle(Login $event): void
{
    // The intent.pending() call has the side-effect of clearing a
    // stale (unresolvable) entry — keep the call even though we
    // don't use the result; FileStagingPage reads the same store on
    // the next request.
    $this->intent->pending();
}
```
Drop the unused `$pending === null` branch.

### IN-03: `WelcomeScreen` links via raw `<a href="/signup">` instead of `route('signup')`

**File:** `Modules/Desktop/Resources/views/welcome.blade.php:20`
**Issue:** Hard-coded `/signup` URL — if the route prefix or name ever changes, the welcome screen silently links to a 404. Every other Blade in this phase that opens an internal route uses `route('...')` (the staging page, the OAuth wizard, the inboxes page).
**Fix:** `<a href="{{ route('signup') }}" ...>`.

### IN-04: `FileStagingPage::startImport()` ignores the `extension` distinction

**File:** `Modules/Desktop/Internal/Http/Livewire/FileStagingPage.php:116-126`
**Issue:** The docblock says ".eml routes to the email-file step of the upload wizard, .csv routes to the import preview/confirm flow", but the implementation `return $this->redirect($urls->route('imports.new'), navigate: true);` is identical for both extensions. The Blade differentiates copy (`A bank or PayPal export` vs `An email receipt`) but the navigation target is the same. The comment ALSO says "the wizard chooses internally based on issuer", which contradicts the docblock's claim that the routing happens here.

Either the implementation is correct and the docblock is misleading, or the implementation has lost the per-extension branch. Either way the documentation should match.
**Fix:** Update the docblock to reflect "both extensions route to imports.new; the wizard internally picks the right step", OR restore the per-extension routing if the wizard truly does have separate entrypoints.

### IN-05: `FileOpenIntake::MAX_BYTES` of 50 MB has no per-extension override

**File:** `Modules/Desktop/Internal/Native/FileOpenIntake.php:54-61`
**Issue:** A 50 MB cap is reasonable for `.csv` exports, but a single `.eml` receipt is realistically <1 MB. Accepting a 50 MB `.eml` is a defensible upper bound but it ALSO accepts a 50 MB CSV — which on a personal-finance dashboard is 250k+ rows, the kind of accidental log-export-mis-drop the docblock claims to reject. Per-extension caps would let the bound tighten.

This is not a security concern (the downstream pipelines have their own validation) but a UX nicety: a single named constant cannot do double duty here.
**Fix:** Make `MAX_BYTES` an array keyed by extension:
```php
public const MAX_BYTES = [
    'csv' => 50 * 1024 * 1024,
    'eml' => 5 * 1024 * 1024,
];
```

---

_Reviewed: 2026-05-23_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
