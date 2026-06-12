<?php

declare(strict_types=1);

namespace Modules\Calendar\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;

/**
 * Wires the Calendar module: migrations, routes, views, and the
 * CalendarPage Livewire component registration.
 *
 * CalendarQuery (Internal\Services) arrives in Plan 02 and will be
 * bound as a singleton in register() at that point. The register()
 * body is intentionally empty for Plan 01 — CalendarPage does not
 * inject CalendarQuery yet.
 */
final class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // CalendarQuery singleton binding added in Plan 02 once the class exists.
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
