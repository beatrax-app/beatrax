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

final class CalendarServiceProvider extends ServiceProvider
{
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
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'calendar');
        }

        $livewire->component('calendar.calendar-page', CalendarPage::class);
    }
}
