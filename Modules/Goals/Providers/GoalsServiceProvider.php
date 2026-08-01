<?php

declare(strict_types=1);

namespace Modules\Goals\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Internal\Http\Livewire\GoalsSummaryCard;
use Modules\Goals\Public\Services\GoalProgressQuery;

final class GoalsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(GoalProgressQuery::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('goals');

        $livewire->component('goals.goals-page', GoalsPage::class);
        $livewire->component('goals.summary-card', GoalsSummaryCard::class);
    }
}
