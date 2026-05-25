<?php

declare(strict_types=1);

namespace Modules\Community\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

/**
 * Wires the Community module:
 *
 *  - loads the community_merchant_mappings migration so the global
 *    corpus table is created on `php artisan migrate`.
 *  - loads the module's routes file when present (the
 *    `/community/mystery-merchants` page registers there once the
 *    corpus surface lands in a later plan).
 *  - registers the `community::` Blade view namespace so corpus pages
 *    and the suggest-mapping modal can render their views.
 *
 * Service bindings, the UserInstalled → SeedCommunityCorpus listener,
 * the Native\Desktop\Contracts\Shell binding for the external-browser
 * launch, and Livewire component registrations attach in later plans;
 * this skeleton boots clean with an empty `register()` so the migration
 * + tests in plan 16.1-01 exercise the schema without needing any
 * corpus-side wiring.
 */
final class CommunityServiceProvider extends ServiceProvider
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
            $this->loadViewsFrom($viewsPath, 'community');
        }

        unset($events, $livewire);
    }
}
