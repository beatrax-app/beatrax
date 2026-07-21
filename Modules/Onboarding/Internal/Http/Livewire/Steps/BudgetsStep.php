<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;

/**
 * @link ../../../../../../.docs/features/onboarding/architecture.md
 */
final class BudgetsStep extends Component
{
    /** @var array<int|string, string> */
    public array $amounts = [];

    public function continue(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods): void
    {
        if ($currentUser->isAuthenticated()) {
            $user = $currentUser->user();
            $periodStart = $periods->current()->start;

            foreach ($this->amounts as $categoryId => $raw) {
                $minor = $writer->parseAmount($raw);
                if ($minor !== null) {
                    try {
                        // setAssigned() re-validates the category id
                        // server-side, but (unlike BudgetWriter::save())
                        // throws rather than silently returning false — an
                        // injected/foreign id is caught and ignored here.
                        $writer->setAssigned($user, (int) $categoryId, $periodStart, $minor);
                    } catch (InvalidArgumentException) {
                        // Not owned/global — silently ignored, an
                        // IDOR-attempt guard.
                    }
                }
            }
        }

        $this->dispatch('wizard.step.completed');
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(CurrentUser $currentUser, BudgetProgressQuery $query, ViewFactory $views): View
    {
        $categories = $currentUser->isAuthenticated()
            ? $query->expenseCategories($currentUser->user())
            : [];

        return $views->make('onboarding::livewire.steps.budgets-step', [
            'categories' => $categories,
        ]);
    }
}
