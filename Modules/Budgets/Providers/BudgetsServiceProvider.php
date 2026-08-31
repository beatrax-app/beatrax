<?php

declare(strict_types=1);

namespace Modules\Budgets\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Internal\Listeners\ActivateEnvelopeBudgetingOnInstall;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\LoadsModuleResources;

final class BudgetsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    private const string ENVELOPE_GLANCE_CARD_CLASS = 'Modules\Budgets\Public\Http\Livewire\EnvelopeGlanceCard';

    public function register(): void
    {
        $this->app->singleton(BudgetProgressQuery::class);
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        $events->listen(UserInstalled::class, ActivateEnvelopeBudgetingOnInstall::class);

        $this->loadModuleResources('budgets');

        $livewire->component('budgets.budgets-page', BudgetsPage::class);

        if (class_exists(self::ENVELOPE_GLANCE_CARD_CLASS)) {
            $livewire->component('budgets.envelope-glance-card', self::ENVELOPE_GLANCE_CARD_CLASS);
        }
    }
}
