<?php

declare(strict_types=1);

namespace Modules\Desktop\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;
use Modules\Desktop\Internal\Http\Livewire\FileStagingPage;
use Modules\Desktop\Internal\Http\Livewire\SetupScreen;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;
use Modules\Desktop\Internal\Listeners\ApplyCloseWindowChoice;
use Modules\Desktop\Internal\Listeners\ContinuePendingFileIntentAfterLogin;
use Modules\Desktop\Internal\Listeners\DispatchOsNotification;
use Modules\Desktop\Internal\Listeners\HandleNativeOpenFile;
use Modules\Desktop\Internal\Listeners\LockOnWindowHideOrClose;
use Modules\Desktop\Internal\Listeners\NavigateOnNotificationDeepLink;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Native\AppMenuBuilder;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Modules\Desktop\Internal\Native\NativeBiometricUnlock;
use Modules\Desktop\Internal\Native\OsThemeProbe;
use Modules\Desktop\Internal\Native\PendingFileIntent;
use Modules\Desktop\Internal\Native\SafeStorageSecretShield;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Contracts\OsThemeSignal;
use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Native\Desktop\Events\App\OpenFile;
use Native\Desktop\Events\ChildProcess\ProcessExited;
use Native\Desktop\Events\Windows\WindowBlurred;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Events\Windows\WindowFocused;
use Native\Desktop\Events\Windows\WindowHidden;

/**
 * Wires the Desktop module.
 *
 * `register()` binds the native-chrome singletons:
 *
 *   - `AppMenuBuilder` (D-11 application-menu composition)
 *   - `WindowFocusState` (D-13 focus-state tracker — the OS
 *     notification dispatcher consults this to decide whether to
 *     fire or stay quiet)
 *   - `DispatchOsNotification` (D-12 / D-13 / D-14 OS notification
 *     dispatcher; the only place the `Notification` facade is
 *     called in the module)
 *   - `OsThemeProbe` (D-16 OS-theme read, exposed via the
 *     `OsThemeSignal` Public contract — the dark-theme layout
 *     resolves the contract, never the concrete probe, so the
 *     `noNativePhpImportsOutsideDesktopModule` arch invariant stays
 *     intact)
 *
 * The bindings are singletons because each is stateless (or holds
 * the single shared focus flag) and the provider may resolve them
 * more than once per request (e.g. the layout reads
 * `OsThemeSignal` and the boot path resolves the menu builders).
 *
 * `boot()` loads migrations from `Database/Migrations`, web routes
 * from `Routes/web.php`, views under the `desktop` namespace, the
 * two first-launch Livewire screens (plan 15-05), and subscribes the
 * D-13 focus-state flippers + the single D-31 notification-deliverable
 * OS-notification handler.
 */
final class DesktopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Native-chrome application-menu builder — composed by
        // NativeAppServiceProvider at NativePHP boot. Singleton
        // because the builder is stateless and the boot path resolves
        // it once per launch.
        //
        // The macOS menu-bar tray (D-09) is no longer composed via a
        // PHP-side builder; the persistent tray is created directly in
        // the Electron main process by
        // `scripts/nativephp_inject_persistent_tray.php` (see the
        // `NativeAppServiceProvider` class docblock for the
        // architectural rationale).
        $this->app->singleton(AppMenuBuilder::class);

        // D-13 focus state — single shared instance so the focus /
        // blur subscribers and the OS-notification dispatcher see
        // the same flag.
        $this->app->singleton(WindowFocusState::class);

        // D-12 / D-13 / D-14 OS-notification dispatcher.
        $this->app->singleton(DispatchOsNotification::class);

        // D-07 worker crash-loop alert listener. Singleton because the
        // rolling crash-counter state lives on the listener — it must
        // survive across multiple `ProcessExited` events the NativePHP
        // shell fires throughout the app's lifetime.
        $this->app->singleton(SurfaceWorkerCrashAlert::class);

        // D-08 window-close decision service. Singleton because it is
        // stateless and resolved by both the Livewire prompt and the
        // NativePHP close-intercept hook (in NativeAppServiceProvider).
        $this->app->singleton(WindowCloseBehavior::class);

        // D-04 pending-file-intent store. The Receipts / Import
        // listeners that subscribe to FileOpenedFromOs persist the
        // validated path here via the RemembersPendingFileIntent
        // Public contract; the FileStagingPage Livewire component
        // reads back through the same store via its concrete class.
        // Singleton because the Session collaborator is request-
        // scoped already — the singleton wrapper saves one constructor
        // call per resolution without breaking session-scoping.
        $this->app->singleton(PendingFileIntent::class);
        $this->app->bind(RemembersPendingFileIntent::class, PendingFileIntent::class);

        // D-04 continuation listener. Subscribes to Laravel's Login
        // event; reads PendingFileIntent and (when an intent is
        // present + still resolves) the user's next request lands
        // on the staging page rather than the dashboard.
        $this->app->singleton(ContinuePendingFileIntentAfterLogin::class);

        // PKG-06 native-event bridge. Subscribes to the NativePHP-emitted
        // \Native\Desktop\Events\App\OpenFile event (the macOS
        // open-file event NativePHP already wires; the cross-OS
        // argv-and-second-instance paths in the published Electron
        // main.js fire the same event) and feeds the path into
        // FileOpenIntake — the security boundary for the OS-supplied
        // path. Singleton because the listener is stateless.
        $this->app->singleton(HandleNativeOpenFile::class);

        // D-14 notification deep-link listener. Plan 15-02 deferred
        // this to 15-04; it consumes NotificationDeepLink and
        // navigates the focused window via `Window::current()->url(...)`.
        $this->app->singleton(NavigateOnNotificationDeepLink::class);

        // D-08 close-action applicator (JS glue deferred from 15-03).
        // The in-layout `close-window-choice` JS hook POSTs to
        // /desktop/close-action; the controller calls into this
        // listener which applies App::quit() / Window::current()->hide().
        $this->app->singleton(ApplyCloseWindowChoice::class);

        // D-06 / T-05-25 macOS Touch ID biometric unlock (05-06).
        // NativeBiometricUnlock is the sole place System::canPromptTouchID()
        // and System::promptTouchID() are called; it returns only a bool
        // (crypto/key-release stays in Auth module). Singleton so the
        // bundle-running guard is evaluated once per container lifecycle.
        // ⚠ NOT YET WIRED — no caller exists; native Touch ID unlock is
        // deferred (WR-08). The registration stands so wiring is a
        // contract-only change later.
        $this->app->singleton(NativeBiometricUnlock::class);

        // D-20 desktop key custody via OS keychain / Electron safeStorage.
        // DesktopKeyCustodian wraps System::encrypt/decrypt to hold the
        // already-unwrapped data key in the OS keychain while unlocked.
        // Degrades to the Auth module's encrypted-session path when
        // safeStorage is unavailable (headless CI, early-boot race).
        // ⚠ NOT YET WIRED — no caller exists; D-20 custody is deferred to
        // the Phase 14 key-consumer work (WR-08). Until then the unlocked
        // key follows session custody on all platforms (see LockStateManager).
        $this->app->singleton(DesktopKeyCustodian::class);

        // OsThemeProbe is bound to the OsThemeSignal contract ONLY
        // when the app is running inside the NativePHP bundle. Under
        // local dev / in tests / before the Electron shell is ready, no
        // binding is registered — the app-layout's
        // `app()->bound(OsThemeSignal::class)` check falls through to
        // the client-side `prefers-color-scheme` pre-paint script. The
        // contract docblock formalises this invariant: "the absence of
        // a binding is itself the signal".
        //
        // The bundle sets `NATIVEPHP_RUNNING=true` (see
        // `config/nativephp-internal.php`); the binding is gated on
        // that signal so the bundle resolves the probe and local dev / CI
        // never tries to reach the NativePHP HTTP client.
        $config = $this->app->make(ConfigRepository::class);
        if ($config->get('nativephp-internal.running') === true) {
            $this->app->singleton(OsThemeSignal::class, OsThemeProbe::class);

            // D-20 / WR-08: on the desktop bundle, route the unlocked data
            // key through Electron safeStorage (OS keychain) instead of the
            // plaintext session copy. Overrides the Auth NullKeyCustodian
            // default. Web / CI never enter this block, so they keep the
            // pass-through session custody.
            $this->app->singleton(KeyCustodian::class, DesktopKeyCustodian::class);

            // At-rest keychain shielding for persisted secrets (biometric
            // wrap blob, OAuth token blob). Delegates to DesktopKeyCustodian
            // so safeStorage stays in one place. Overrides the Core
            // PassthroughSecretShield default on the desktop bundle only.
            $this->app->singleton(SecretShield::class, SafeStorageSecretShield::class);

            // Keep the NativePHP security secret in sync with the live
            // launch so PreventRegularBrowserAccess does not 403 the
            // app's own window. See reassertNativePhpSecret() for the
            // full Windows stale-config-cache rationale.
            $this->reassertNativePhpSecret($config);
        }
    }

    /**
     * Re-assert the per-launch NativePHP security secret from the live
     * process environment so a stale config cache cannot strand it.
     *
     * NativePHP's Electron shell generates a fresh random secret on every
     * launch (`state.randomSecret`) and both (a) injects it into the
     * window's `_php_native` cookie + `X-NativePHP-Secret` header on every
     * request and (b) exports it to the spawned PHP processes as
     * `NATIVEPHP_SECRET`. The vendor `PreventRegularBrowserAccess`
     * middleware `abort(403)`s any request whose cookie/header does not
     * equal `config('nativephp-internal.secret')`.
     *
     * That config value resolves from `env('NATIVEPHP_SECRET')`, which is
     * frozen the moment `php artisan optimize` runs `config:cache` at
     * launch (NativePHP runs `optimize` on every production launch). On
     * Windows the bundle's `bootstrap/cache` write can fail or be skipped
     * — the same read-only / AV-locked filesystem class that surfaces as
     * the WinError 5 `view:cache` rename failure in the production log —
     * leaving a PRIOR launch's secret frozen in the cached config while
     * the live window sends the CURRENT secret. Every request then 403s
     * and the window renders nothing.
     *
     * `getenv()` (not `env()`) reads the live process environment
     * unconditionally, independent of the cached config repository — the
     * same reason `UserDataPathService` uses it — so the value here is
     * always the authoritative current-launch secret. When the cached
     * secret is already correct this is a harmless no-op overwrite.
     */
    private function reassertNativePhpSecret(ConfigRepository $config): void
    {
        $secret = getenv('NATIVEPHP_SECRET');

        if (is_string($secret) && $secret !== '') {
            $config->set('nativephp-internal.secret', $secret);
        }
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'desktop');
        }

        $livewire->component('desktop.setup-screen', SetupScreen::class);
        $livewire->component('desktop.welcome-screen', WelcomeScreen::class);
        $livewire->component('desktop.close-window-prompt', CloseWindowPrompt::class);
        $livewire->component('desktop.file-staging-page', FileStagingPage::class);

        // Lift the wall-clock execution ceiling for the long-lived
        // queue-worker loop. Registration is NOT bundle-gated: it must
        // light up in the spawned queue:work process (which never has
        // `nativephp-internal.running` set), and resetting the time
        // limit is harmless in any queue:work context, so it precedes
        // the bundle-only `return` guard below — mirroring the
        // DevMode heartbeat's unconditional looping() registration.
        $queueManager = $this->app->make(QueueManager::class);
        $queueManager->looping(static function (): void {
            // PHP's max_execution_time is wall-clock on Windows, where ext-pcntl
            // is absent so Laravel's per-job timeout never arms. NativePHP spawns
            // queue:work with the Desktop-owned 120s ceiling (NativeAppServiceProvider
            // ::phpIni()); without resetting it per poll the long-lived daemon accrues
            // 120s of real time and the SAPI kills the whole worker at Worker.php.
            // Resetting to unlimited each loop defuses that while leaving the 120s
            // web/auto-updater safety net intact. Mirrors WriteWorkerHeartbeat's
            // looping() registration (the Looping *event* does not fire reliably here).
            @set_time_limit(0);
        });

        // D-04 continuation listener — fires on every successful Login.
        // The listener re-checks the session-scoped PendingFileIntent
        // and clears it after consumption so the next login does not
        // re-fire the same intent. Subscription is NOT bundle-gated:
        // the pending-intent round-trip must work in local dev / CI / test
        // runs too (the feature tests cover those paths directly), and
        // the listener does not call any NativePHP facade — it only
        // touches the Session contract.
        $events->listen(Login::class, [ContinuePendingFileIntentAfterLogin::class, 'handle']);

        // Focus-state + OS-notification wiring is bundle-only. Under
        // local dev / in tests / before the Electron shell is ready,
        // there is no native shell to push OS notifications into —
        // the in-app `SystemAlertsBanner` handles every event. The
        // gate mirrors the `OsThemeProbe` contract-binding gate in
        // `register()`: the bundle sets `NATIVEPHP_RUNNING=true`
        // (see `config/nativephp-internal.php`), and the listener
        // wiring lights up only then. CI / test runs that dispatch the
        // Notifications module's deliverable event therefore never
        // reach the NativePHP HTTP client.
        $config = $this->app->make(ConfigRepository::class);
        if ($config->get('nativephp-internal.running') !== true) {
            return;
        }

        // D-13 focus-state flippers. NativePHP dispatches the
        // `WindowFocused` / `WindowBlurred` events into the Laravel
        // event bus; the closures here flip the shared
        // `WindowFocusState` singleton so `DispatchOsNotification`
        // sees the current state at firing time. The singleton is
        // resolved once at boot time and captured by the closures
        // so the runtime path holds no `app()` global helper.
        $focusState = $this->app->make(WindowFocusState::class);
        $events->listen(WindowFocused::class, static function () use ($focusState): void {
            $focusState->markFocused();
        });
        $events->listen(WindowBlurred::class, static function () use ($focusState): void {
            $focusState->markBlurred();
        });

        // D-12/D-31 OS-notification subscription. The Notifications
        // module now decides WHAT to notify (title/body/deep-link) and
        // persists the row FIRST; this ONE listener decides only
        // whether/how to deliver it to the OS (suppression + the D-13
        // focus-gate). Three per-category handlers collapsed into one.
        $events->listen(NotificationDeliverable::class, [DispatchOsNotification::class, 'handleNotificationDeliverable']);

        // D-07 worker crash-loop subscription. NativePHP fires
        // `ProcessExited` for every supervised child-process exit; the
        // listener accumulates exits in a rolling window and only
        // escalates once the threshold is crossed. The subscription is
        // bundle-only because the listener calls the `Notification`
        // facade when the window is unfocused — under local dev / in tests
        // there is no NativePHP HTTP client to push to.
        $events->listen(ProcessExited::class, [SurfaceWorkerCrashAlert::class, 'handle']);

        // PKG-06 native-event bridge: subscribe to the NativePHP-emitted
        // OpenFile event and feed it through FileOpenIntake. Bundle-only
        // because the event itself only fires inside the NativePHP
        // shell — under local dev / in tests the cross-OS plumbing is
        // absent and FileOpenIntake is invoked directly by feature
        // tests instead.
        $events->listen(OpenFile::class, [HandleNativeOpenFile::class, 'handle']);

        // D-06 desktop immediate-lock: hide or close the window → withhold the data key
        // with no grace period (T-05-24 — OS app-switcher snapshot must not show data).
        // Both WindowHidden and WindowClosed route to the same handle() method.
        // The listener itself does not call any NativePHP facade, so it needs no
        // phpstan.neon allowlist entry — only the event import here does.
        $events->listen(WindowHidden::class, [LockOnWindowHideOrClose::class, 'handle']);
        $events->listen(WindowClosed::class, [LockOnWindowHideOrClose::class, 'handle']);

        // D-14 notification-click deep-link. The listener calls
        // Window::current()->url(...) — only meaningful inside the
        // NativePHP bundle, so the subscription gates on the same
        // signal as the OS-notification dispatcher itself.
        $events->listen(NotificationDeepLink::class, [NavigateOnNotificationDeepLink::class, 'handle']);
    }
}
