<?php

declare(strict_types=1);

namespace Modules\Onboarding\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

/**
 * Wires the Onboarding module:
 *
 *  - loads the wizard_progress migration so the state-machine table is
 *    created on `php artisan migrate`.
 *  - loads the module's routes file when present (the wizard's `/setup`
 *    route registers there once the wizard components land in a later
 *    plan).
 *  - registers the `onboarding::` Blade view namespace so step views can
 *    extend the wizard layout.
 *
 * Service bindings, Livewire component registrations, and event-listener
 * wiring (e.g. UserInstalled → InitializeWizardProgress) attach in later
 * plans as the wizard surface lands; this skeleton boots clean with an
 * empty `register()` so the migration + tests in plan 16.1-01 exercise
 * the schema without needing any wizard-side wiring.
 */
final class OnboardingServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $routesPath = __DIR__.'/../Routes/web.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        $viewsPath = __DIR__.'/../Resources/views';
        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'onboarding');
        }

        unset($events, $livewire);
    }
}
