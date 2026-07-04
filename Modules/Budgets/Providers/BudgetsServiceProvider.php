<?php

declare(strict_types=1);

namespace Modules\Budgets\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\BudgetProgressQuery;

/**
 * Wires the Budgets module: the read-model query as a singleton, and the
 * conditional load of migrations / routes / views plus the BudgetsPage
 * Livewire component.
 *
 * CRITICAL: This provider is the SOLE owner of every Phase 13.2 envelope
 * binding (mirrors the Phase 11 SyncServiceProvider single-owner
 * precedent). Downstream plans never edit this file — their classes are
 * wired here behind `class_exists()` guards, referenced by runtime-built
 * FQCN string so this provider stays parseable and PHPStan-clean before
 * those classes exist.
 *
 * Envelope binding inventory (by implementing plan):
 *   Plan 03: CarryoverQuery (singleton, D-09 request-scoped memoization)
 *   Plan 07: EnvelopeGlanceCard Livewire component
 *   (EnvelopeWriter is a plain autowired service — no singleton needed.)
 */
final class BudgetsServiceProvider extends ServiceProvider
{
    private const CARRYOVER_QUERY_CLASS = 'Modules\Budgets\Public\Services\CarryoverQuery';

    private const ENVELOPE_GLANCE_CARD_CLASS = 'Modules\Budgets\Internal\Http\Livewire\EnvelopeGlanceCard';

    public function register(): void
    {
        $this->app->singleton(BudgetProgressQuery::class);

        // Plan 03 injection point: CarryoverQuery mirrors BudgetProgressQuery's
        // existing singleton (D-09) so it is built at most once per request.
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

        // Plan 07 injection point: dashboard glance-card component, mirrors
        // GoalsServiceProvider's 'goals.summary-card' registration.
        if (class_exists(self::ENVELOPE_GLANCE_CARD_CLASS)) {
            $livewire->component('budgets.envelope-glance-card', self::ENVELOPE_GLANCE_CARD_CLASS);
        }
    }
}
