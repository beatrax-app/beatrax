<?php

declare(strict_types=1);

namespace Modules\Categorization\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Loads Categorization module migrations, routes, and views. The category-tree
 * seeder and triage Livewire components arrive in later plans.
 */
final class CategorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 06 binds the Categorization\Public\Actions\AssignCategory here.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'categorization');
    }
}
