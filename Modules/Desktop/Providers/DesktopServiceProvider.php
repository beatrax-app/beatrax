<?php

declare(strict_types=1);

namespace Modules\Desktop\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Desktop\Internal\Http\Livewire\SetupScreen;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;

/**
 * Wires the Desktop module.
 *
 * `register()` is empty for now — the native-chrome singleton bindings
 * (window/menu/tray builders) are introduced by a later plan.
 *
 * `boot()` loads migrations from `Database/Migrations`, web routes from
 * `Routes/web.php`, and views under the `desktop` namespace, each gated
 * on an existence guard so an absent directory or file does not abort
 * provider boot. Plan 15-05 additionally registers the two first-launch
 * Livewire screens (`desktop.setup-screen`, `desktop.welcome-screen`).
 */
final class DesktopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
