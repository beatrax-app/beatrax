<?php

declare(strict_types=1);

namespace Modules\Onboarding\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Listeners\InitializeWizardProgressOnInstall;
use Modules\Onboarding\Internal\Services\ResumeStepResolver;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;
use Modules\Onboarding\Public\Services\WizardProgressQuery;

/**
 * Wires the Onboarding module:
 *
 *  - registers the wizard's state collaborators (registry / initializer
 *    / resume resolver / public progress query) as singletons.
 *  - listens for `UserInstalled` so the wizard's six per-user rows are
 *    seeded by the same event the default-category-tree seeder uses.
 *  - loads the wizard_progress migration so the state-machine table is
 *    created on `php artisan migrate`.
 *  - loads `/setup` and any other module routes.
 *  - registers the `onboarding::` Blade view namespace so the wizard's
 *    blade views can extend the wizard layout.
 *  - registers the `SetupWizard` Livewire parent component under the
 *    `onboarding.setup-wizard` alias.
 */
final class OnboardingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WizardStepRegistry::class);
        $this->app->singleton(WizardProgressInitializer::class);
        $this->app->singleton(ResumeStepResolver::class);
        $this->app->singleton(WizardProgressQuery::class);
    }

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

        $events->listen(UserInstalled::class, InitializeWizardProgressOnInstall::class);

        $livewire->component('onboarding.setup-wizard', SetupWizard::class);
    }
}
