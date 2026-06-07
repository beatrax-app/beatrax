<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Budgets\Public\Services\BudgetWriter;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Optional wizard step — set a monthly spending ceiling per expense category
 * before finishing setup. Mirrors the connector steps' shape: a `continue`
 * action that persists the entered budgets (via the shared, ownership-checked
 * BudgetWriter) and bubbles `wizard.step.completed`, plus a `skip` action
 * that bubbles `wizard.step.skipped`. The parent SetupWizard owns the
 * wizard_progress mutation + the advance.
 *
 * Entering nothing and continuing is valid — budgets can always be set later
 * on the /budgets page; this step is a convenience, not a gate.
 */
final class BudgetsStep extends Component
{
    /** @var array<int|string, string> */
    public array $amounts = [];

    public function continue(CurrentUser $currentUser, BudgetWriter $writer): void
    {
        if ($currentUser->isAuthenticated()) {
            $user = $currentUser->user();
            foreach ($this->amounts as $categoryId => $raw) {
                $minor = $writer->parseAmount($raw);
                if ($minor !== null) {
                    // save() re-validates the category id server-side, so an
                    // injected/foreign id is silently ignored.
                    $writer->save($user, (int) $categoryId, $minor);
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
