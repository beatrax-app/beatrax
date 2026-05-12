<?php

declare(strict_types=1);

namespace Modules\Categorization\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Categorization\Internal\Http\Livewire\InlineCategoryPicker;
use Modules\Categorization\Internal\Http\Livewire\TriageInbox;
use Modules\Categorization\Internal\Listeners\SeedDefaultCategoryTree;
use Modules\Categorization\Public\Actions\AssignCategory;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
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
 * - registers the two Livewire components rendered on `/uncategorized`
 *   and inside the `/transactions` rows.
 * - loads migrations, routes, and views.
 */
final class CategorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssignsCategory::class, AssignCategory::class);
        $this->app->singleton(UncategorizedTriageQuery::class);
        $this->app->singleton(CategoryOptionsQuery::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'categorization');

        $events->listen(UserInstalled::class, SeedDefaultCategoryTree::class);

        $livewire->component('categorization.triage-inbox', TriageInbox::class);
        $livewire->component('categorization.inline-category-picker', InlineCategoryPicker::class);
    }
}
