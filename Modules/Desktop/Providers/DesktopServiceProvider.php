<?php

declare(strict_types=1);

namespace Modules\Desktop\Providers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;
use Modules\Desktop\Internal\Http\Livewire\SetupScreen;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;
use Modules\Desktop\Internal\Listeners\DispatchOsNotification;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Native\AppMenuBuilder;
use Modules\Desktop\Internal\Native\OsThemeProbe;
use Modules\Desktop\Internal\Native\TrayMenuBuilder;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Contracts\OsThemeSignal;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Import\Public\Events\TransactionImported;
use Native\Desktop\Events\ChildProcess\ProcessExited;
use Native\Desktop\Events\Windows\WindowBlurred;
use Native\Desktop\Events\Windows\WindowFocused;

/**
 * Wires the Desktop module.
 *
 * `register()` binds the native-chrome singletons:
 *
 *   - `AppMenuBuilder` (D-11 application-menu composition)
 *   - `TrayMenuBuilder` (D-09 system-tray context-menu composition)
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
 * D-13 focus-state flippers + the four D-12 OS-notification handlers.
 */
final class DesktopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Native-chrome builders — composed by NativeAppServiceProvider
        // at NativePHP boot. Singleton because each builder is
        // stateless and the boot path resolves them once per launch.
        $this->app->singleton(AppMenuBuilder::class);
        $this->app->singleton(TrayMenuBuilder::class);

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

        // OsThemeProbe is bound to the OsThemeSignal contract ONLY
        // when the app is running inside the NativePHP bundle. Under
        // Herd / in tests / before the Electron shell is ready, no
        // binding is registered — the app-layout's
        // `app()->bound(OsThemeSignal::class)` check falls through to
        // the client-side `prefers-color-scheme` pre-paint script. The
        // contract docblock formalises this invariant: "the absence of
        // a binding is itself the signal".
        //
        // The bundle sets `NATIVEPHP_RUNNING=true` (see
        // `config/nativephp-internal.php`); the binding is gated on
        // that signal so the bundle resolves the probe and Herd / CI
        // never tries to reach the NativePHP HTTP client.
        $config = $this->app->make(ConfigRepository::class);
        if ($config->get('nativephp-internal.running') === true) {
            $this->app->singleton(OsThemeSignal::class, OsThemeProbe::class);
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

        // Focus-state + OS-notification wiring is bundle-only. Under
        // Herd / in tests / before the Electron shell is ready,
        // there is no native shell to push OS notifications into —
        // the in-app `SystemAlertsBanner` handles every event. The
        // gate mirrors the `OsThemeProbe` contract-binding gate in
        // `register()`: the bundle sets `NATIVEPHP_RUNNING=true`
        // (see `config/nativephp-internal.php`), and the listener
        // wiring lights up only then. CI / test runs that fire
        // `TransactionImported` / `DriftAlertOpened` /
        // `ForecastShortfallDetected` therefore never reach the
        // NativePHP HTTP client.
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

        // D-12 OS-notification subscriptions. Each in-app domain
        // event the UI-SPEC names routes through one handler method
        // on the dispatcher; the dispatcher itself owns the D-13
        // focus-gate.
        $events->listen(TransactionImported::class, [DispatchOsNotification::class, 'handleTransactionImported']);
        $events->listen(DriftAlertOpened::class, [DispatchOsNotification::class, 'handleDriftAlert']);
        $events->listen(ForecastShortfallDetected::class, [DispatchOsNotification::class, 'handleForecastShortfall']);

        // D-07 worker crash-loop subscription. NativePHP fires
        // `ProcessExited` for every supervised child-process exit; the
        // listener accumulates exits in a rolling window and only
        // escalates once the threshold is crossed. The subscription is
        // bundle-only because the listener calls the `Notification`
        // facade when the window is unfocused — under Herd / in tests
        // there is no NativePHP HTTP client to push to.
        $events->listen(ProcessExited::class, [SurfaceWorkerCrashAlert::class, 'handle']);
    }
}
