<?php

declare(strict_types=1);

namespace Modules\Calendar\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Calendar\Internal\Services\AccountResolver;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Calendar\Internal\Services\DailyBalanceAggregator;
use Modules\Calendar\Internal\Services\OccurrenceMatcher;
use Modules\Calendar\Internal\Services\SeriesEntryPlacer;
use Modules\Core\Public\Support\LoadsModuleResources;

final class CalendarServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(AccountResolver::class);
        $this->app->singleton(OccurrenceMatcher::class);
        $this->app->singleton(DailyBalanceAggregator::class);
        $this->app->singleton(SeriesEntryPlacer::class);
        $this->app->singleton(CalendarQuery::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('calendar');

        $livewire->component('calendar.calendar-page', CalendarPage::class);
    }
}
