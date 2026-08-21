<?php

declare(strict_types=1);

namespace Modules\Budgets\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Core\Public\Support\LoadsModuleResources;

final class BudgetsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    private const CARRYOVER_QUERY_CLASS = 'Modules\Budgets\Public\Services\CarryoverQuery';

    private const ENVELOPE_GLANCE_CARD_CLASS = 'Modules\Budgets\Public\Http\Livewire\EnvelopeGlanceCard';

    public function register(): void
    {
        $this->app->singleton(BudgetProgressQuery::class);

        if (class_exists(self::CARRYOVER_QUERY_CLASS)) {
            $this->app->singleton(self::CARRYOVER_QUERY_CLASS);
        }
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('budgets');

        $livewire->component('budgets.budgets-page', BudgetsPage::class);

        if (class_exists(self::ENVELOPE_GLANCE_CARD_CLASS)) {
            $livewire->component('budgets.envelope-glance-card', self::ENVELOPE_GLANCE_CARD_CLASS);
        }
    }
}
