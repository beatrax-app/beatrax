<?php

declare(strict_types=1);

namespace Modules\Desktop\Providers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Desktop\Internal\Http\Livewire\SetupScreen;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;
use Modules\Desktop\Internal\Native\AppMenuBuilder;
use Modules\Desktop\Internal\Native\OsThemeProbe;
use Modules\Desktop\Internal\Native\TrayMenuBuilder;
use Modules\Desktop\Public\Contracts\OsThemeSignal;

/**
 * Wires the Desktop module.
 *
 * `register()` binds the native-chrome singletons:
 *
 *   - `AppMenuBuilder` (D-11 application-menu composition)
 *   - `TrayMenuBuilder` (D-09 system-tray context-menu composition)
 *   - `OsThemeProbe` (D-16 OS-theme read, exposed via the
 *     `OsThemeSignal` Public contract — the dark-theme layout
 *     resolves the contract, never the concrete probe, so the
 *     `noNativePhpImportsOutsideDesktopModule` arch invariant stays
 *     intact)
 *
 * The bindings are singletons because each is stateless and the
 * provider may resolve them more than once per request (e.g. the
 * layout reads `OsThemeSignal` and the boot path resolves the menu
 * builders).
 *
 * `boot()` loads migrations from `Database/Migrations`, web routes
 * from `Routes/web.php`, and views under the `desktop` namespace,
 * each gated on an existence guard so an absent directory or file
 * does not abort provider boot. Plan 15-05 additionally registers
 * the two first-launch Livewire screens (`desktop.setup-screen`,
 * `desktop.welcome-screen`).
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

    public function boot(LivewireManager $livewire): void
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
    }
}
