<?php

declare(strict_types=1);

namespace Modules\Position\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Position\Public\Services\PositionQuery;

/**
 * Wires the Position module.
 *
 * Register-only and thin (D-30): `Modules\Position` exists solely to compose
 * "your current position" from other modules' existing Public seams
 * (`ThisPeriodAtAGlanceQuery`, `BudgetProgressQuery`, `RecurringSeriesQuery`,
 * `ForecastHighlightsQuery`) into one `PositionSummaryDto`. It has no routes,
 * no views, no Livewire components — the digest job (Task 2) is its first
 * consumer and the dashboard's adoption is a deliberately separate plan
 * (18-18, D-30 guard 1).
 *
 * register() binds the sole stateless in-tree service — `PositionQuery` — as
 * a singleton (it holds no per-request state; every dependency it composes
 * is itself a singleton).
 *
 * boot() carries the same `is_dir`/`is_file` conditional-load guards every
 * module provider in this codebase uses (cloned from
 * `Modules\Anomaly\Providers\AnomalyServiceProvider`), even though this
 * module ships with no migrations/routes/views today — kept for shape
 * consistency and so a later plan that DOES add one of those doesn't also
 * have to remember to add the guard.
 */
final class PositionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PositionQuery::class);
    }

    public function boot(): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'position');
        }
    }
}
