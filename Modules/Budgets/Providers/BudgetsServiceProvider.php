<?php

declare(strict_types=1);

namespace Modules\Budgets\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\BudgetProgressQuery;

// This provider is the sole owner of every envelope-budgeting binding.
// Classes are wired behind class_exists() guards, referenced by
// runtime-built FQCN string, so this provider stays parseable and
// PHPStan-clean even when referenced classes don't exist yet.
final class BudgetsServiceProvider extends ServiceProvider
{
    private const CARRYOVER_QUERY_CLASS = 'Modules\Budgets\Public\Services\CarryoverQuery';

    private const ENVELOPE_GLANCE_CARD_CLASS = 'Modules\Budgets\Internal\Http\Livewire\EnvelopeGlanceCard';

    public function register(): void
    {
        $this->app->singleton(BudgetProgressQuery::class);

        // CarryoverQuery mirrors BudgetProgressQuery's own singleton so it is
        // built at most once per request.
        if (class_exists(self::CARRYOVER_QUERY_CLASS)) {
            $this->app->singleton(self::CARRYOVER_QUERY_CLASS);
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
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'budgets');
        }

        $livewire->component('budgets.budgets-page', BudgetsPage::class);

        // Dashboard glance-card component, mirrors GoalsServiceProvider's
        // 'goals.summary-card' registration.
        if (class_exists(self::ENVELOPE_GLANCE_CARD_CLASS)) {
            $livewire->component('budgets.envelope-glance-card', self::ENVELOPE_GLANCE_CARD_CLASS);
        }
    }
}
