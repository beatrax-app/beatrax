<?php

declare(strict_types=1);

namespace Modules\Categorization\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Modules\Categorization\Internal\Listeners\SeedDefaultCategoryTree;
use Modules\Categorization\Public\Actions\AssignCategory;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Services\UncategorizedTriageQuery;
use Modules\Core\Public\Events\UserInstalled;

/**
 * Wires the Categorization module:
 *
 * - binds the `AssignsCategory` public contract to its `AssignCategory`
 *   default implementation (routes writes through Ledger's
 *   `UpdatesTransactionCategory`).
 * - registers `UncategorizedTriageQuery` as a stateless singleton.
 * - listens for `UserInstalled` and seeds the default category tree
 *   without coupling Core to Categorization.
 * - loads migrations, routes, and views.
 */
final class CategorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssignsCategory::class, AssignCategory::class);
        $this->app->singleton(UncategorizedTriageQuery::class);
    }

    public function boot(Dispatcher $events): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'categorization');

        $events->listen(UserInstalled::class, SeedDefaultCategoryTree::class);
    }
}
