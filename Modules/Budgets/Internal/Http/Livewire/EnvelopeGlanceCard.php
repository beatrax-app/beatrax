<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;

/**
 * Compact dashboard "Budgets" glance card (Req 12, D-21) — the exact analog
 * of `GoalsSummaryCard`: method-DI render, unauth guard, graceful collapse.
 *
 * Shows the current period's "Ready to assign" figure — sourced from
 * `CarryoverQuery::forUserAndPeriod()`'s genesis-to-target fold, never the
 * retired `category_budgets` table — plus an amber "{N} over budget" pill
 * when at least one envelope is overspent.
 *
 * Does NOT call `->extends('layouts.app')` — it renders inline in the
 * dashboard (`@livewire('budgets.envelope-glance-card')`).
 *
 * Two distinct "no figure" states, per the UI-SPEC:
 *  - Unauthenticated: renders the card chrome with a null figure (never a
 *    blank gap) — mirrors GoalsSummaryCard's unauth branch.
 *  - Authenticated but zero expense categories: renders NOTHING (no chrome
 *    at all) — envelopes are implicit per D-12a, so once any expense
 *    category exists "Ready to assign" always shows; there is no
 *    "no envelopes yet" state to render chrome around.
 *
 * Method-parameter DI on render — no constructor (phpstan-strict-rules ban).
 */
final class EnvelopeGlanceCard extends Component
{
    public function render(
        CurrentUser $currentUser,
        BudgetProgressQuery $budgetProgress,
        CarryoverQuery $carryover,
        PeriodQuery $periods,
        ViewFactory $views,
    ): View {
        if (! $currentUser->isAuthenticated()) {
            return $views->make('budgets::livewire.envelope-glance-card', [
                'collapse' => false,
                'toBudgetMinor' => null,
                'overspentCount' => 0,
            ]);
        }

        $user = $currentUser->user();

        if ($budgetProgress->expenseCategories($user) === []) {
            return $views->make('budgets::livewire.envelope-glance-card', [
                'collapse' => true,
                'toBudgetMinor' => null,
                'overspentCount' => 0,
            ]);
        }

        $fold = $carryover->forUserAndPeriod($user, $periods->current());

        return $views->make('budgets::livewire.envelope-glance-card', [
            'collapse' => false,
            'toBudgetMinor' => $fold['toBudgetMinor'],
            'overspentCount' => $fold['overspentCount'],
        ]);
    }
}
