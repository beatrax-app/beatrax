<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Budgets\Public\Services\BudgetWriter;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;

/**
 * `/budgets` page — set a monthly spending ceiling per expense category and
 * watch the current period's spend against it.
 *
 * Method-parameter DI on every action and on render — constructor injection is
 * banned on Livewire `Component` subclasses by phpstan-strict-rules (mirrors
 * RecurringPage). render() recomputes the progress rows on every action so the
 * bars reflect the latest budget edit without a separate refresh path. All
 * parsing + ownership-validated writes go through the shared BudgetWriter.
 *
 * State:
 *  - `amounts` — decimal strings keyed by category id, bound to each row's
 *    inline editor (seeded from the stored budgets in mount()).
 *  - `newCategoryId` / `newAmount` — the "add a budget" picker + field.
 */
final class BudgetsPage extends Component
{
    /** @var array<int, string> */
    public array $amounts = [];

    public string $newCategoryId = '';

    public string $newAmount = '';

    public function mount(CurrentUser $currentUser, BudgetProgressQuery $query): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        foreach ($query->forCurrentPeriod($currentUser->user()) as $row) {
            $this->amounts[$row->categoryId] = $this->minorToDecimal($row->budgetMinor);
        }
    }

    public function setBudget(CurrentUser $currentUser, BudgetWriter $writer): void
    {
        $categoryId = (int) $this->newCategoryId;
        $minor = $writer->parseAmount($this->newAmount);

        if ($categoryId <= 0 || $minor === null) {
            $this->toast('Pick a category and enter a valid amount.');

            return;
        }

        if (! $currentUser->isAuthenticated() || ! $writer->save($currentUser->user(), $categoryId, $minor)) {
            $this->toast('That category cannot be budgeted.');

            return;
        }

        $this->amounts[$categoryId] = $this->minorToDecimal($minor);
        $this->newCategoryId = '';
        $this->newAmount = '';
        $this->toast('Budget saved.');
    }

    public function updateBudget(CurrentUser $currentUser, BudgetWriter $writer, int $categoryId): void
    {
        $minor = $writer->parseAmount($this->amounts[$categoryId] ?? '');
        if ($minor === null) {
            $this->toast('Enter a valid amount.');

            return;
        }

        if (! $currentUser->isAuthenticated() || ! $writer->save($currentUser->user(), $categoryId, $minor)) {
            $this->toast('That category cannot be budgeted.');

            return;
        }

        $this->amounts[$categoryId] = $this->minorToDecimal($minor);
        $this->toast('Budget updated.');
    }

    public function removeBudget(CurrentUser $currentUser, BudgetWriter $writer, int $categoryId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->remove($currentUser->user(), $categoryId);
        unset($this->amounts[$categoryId]);
        $this->toast('Budget removed.');
    }

    public function render(
        CurrentUser $currentUser,
        BudgetProgressQuery $query,
        PeriodQuery $periods,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $rows = $query->forCurrentPeriod($user);

        $budgetedIds = array_map(static fn ($row): int => $row->categoryId, $rows);
        $available = array_filter(
            $query->expenseCategories($user),
            static fn (int $id): bool => ! in_array($id, $budgetedIds, true),
            ARRAY_FILTER_USE_KEY,
        );

        $totalBudgetMinor = array_sum(array_map(static fn ($row): int => $row->budgetMinor, $rows));
        $totalSpentMinor = array_sum(array_map(static fn ($row): int => $row->spentMinor, $rows));

        $view = $views->make('budgets::livewire.budgets-page', [
            'rows' => $rows,
            'available' => $available,
            'period' => $periods->current(),
            'totalBudgetMinor' => $totalBudgetMinor,
            'totalSpentMinor' => $totalSpentMinor,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Budgets · beatrax']);

        return $view;
    }

    private function minorToDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    private function toast(string $message): void
    {
        $this->dispatch('toast', message: $message, undoAction: '', undoPayload: null);
    }
}
