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
 * @link ../../../../../.docs/features/budgets/architecture.md
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
