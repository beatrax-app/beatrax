<?php

declare(strict_types=1);

namespace Modules\Import\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Loads Import module migrations, routes, and views. The Import\Public\Contracts\RunsImports
 * binding lands in Plan 05.
 */
final class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 05 binds the Import\Public\Contracts\RunsImports here.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'import');
    }
}
