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
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;

final class BudgetsStep extends Component
{
    /** @var array<int|string, string> */
    public array $amounts = [];

    public function continue(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods, BaseCurrency $baseCurrency): void
    {
        if ($currentUser->isAuthenticated()) {
            $user = $currentUser->user();
            $periodStart = $periods->current()->start;
            $currency = $baseCurrency->forUser($user);

            foreach ($this->amounts as $categoryId => $raw) {
                $minor = $writer->parseAmount($raw, $currency);
                if ($minor !== null) {
                    try {
                        $writer->setAssigned($user, (int) $categoryId, $periodStart, $minor);
                    } catch (InvalidArgumentException) {
                        // A category the user does not own can only arrive in a
                        // tampered payload, so the amount is dropped in silence.
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

    public function render(CurrentUser $currentUser, BudgetProgressQuery $query, BaseCurrency $baseCurrency, ViewFactory $views): View
    {
        $categories = $currentUser->isAuthenticated()
            ? $query->expenseCategories($currentUser->user())
            : [];

        return $views->make('onboarding::livewire.steps.budgets-step', [
            'categories' => $categories,
            'currency' => $baseCurrency->code(),
        ]);
    }
}
