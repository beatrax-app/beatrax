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
 * Optional wizard step — assign this month's money per expense category
 * before finishing setup (D-24). Mirrors the connector steps' shape: a
 * `continue` action that persists the entered amounts as month-1
 * `envelope_assignments` rows (via the shared, ownership-checked
 * `EnvelopeWriter::setAssigned()`, keyed to `PeriodQuery::current()`) and
 * bubbles `wizard.step.completed`, plus a `skip` action that bubbles
 * `wizard.step.skipped`. The parent SetupWizard owns the wizard_progress
 * mutation + the advance.
 *
 * New users start in the envelope model from day one — this step never
 * writes the retired `category_budgets` table.
 *
 * Entering nothing and continuing is valid — envelopes can always be
 * assigned later on the /budgets page; this step is a convenience, not a
 * gate.
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
                        // Not owned/global — silently ignored (IDOR guard).
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
