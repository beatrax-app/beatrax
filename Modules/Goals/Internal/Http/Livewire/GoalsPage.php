<?php

declare(strict_types=1);

namespace Modules\Goals\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Goals\Public\Services\GoalWriter;

/**
 * `/goals` page — list and manage savings goals with a 3-state progress bar,
 * projected-date copy, Flux create/edit modal, and Edit/kebab lifecycle
 * affordances (Mark complete, Archive, Restore).
 *
 * Method-parameter DI on every action and on render/mount — constructor
 * injection is banned on Livewire Component subclasses by phpstan-strict-rules
 * (same pattern as BudgetsPage and RecurringPage).
 *
 * State:
 *   - `name`, `targetAmount`, `targetDate`, `accountId` — form fields for the
 *     create/edit modal.
 *   - `editGoalId` — when non-zero, the modal is in edit mode.
 *   - `errorName`, `errorAmount`, `errorDate` — inline per-field validation
 *     banners (`.wiz-error` style).
 *   - `archivingGoalId` — non-zero triggers the in-card micro-confirm row.
 *   - `showArchived` — controls the "Archived goals (N)" disclosure.
 */
final class GoalsPage extends Component
{
    public string $name = '';

    public string $targetAmount = '';

    public string $targetDate = '';

    public string $accountId = '';

    public int $editGoalId = 0;

    public string $errorName = '';

    public string $errorAmount = '';

    public string $errorDate = '';

    public int $archivingGoalId = 0;

    public bool $showArchived = false;

    public function mount(CurrentUser $currentUser): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
    }

    // -----------------------------------------------------------------------
    // Create goal
    // -----------------------------------------------------------------------

    /**
     * Create a new goal from the modal form. Maps GoalWriter exceptions to
     * per-field inline errors. On success closes modal and dispatches a toast.
     */
    public function createGoal(CurrentUser $currentUser, GoalWriter $writer): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        if (trim($this->name) === '') {
            $this->errorName = 'Enter a name for your goal.';

            return;
        }

        if (trim($this->targetDate) === '') {
            $this->errorDate = 'Choose a target date.';

            return;
        }

        $accountId = $this->accountId !== '' ? (int) $this->accountId : null;

        try {
            $writer->save(
                $currentUser->user(),
                $this->name,
                $this->targetAmount,
                $this->targetDate,
                $accountId,
            );
        } catch (\InvalidArgumentException $e) {
            $this->errorAmount = 'Enter a valid amount greater than zero.';

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-hide', name: 'goal-form');
        $this->toast('Goal created.');
    }

    // -----------------------------------------------------------------------
    // Edit / update goal
    // -----------------------------------------------------------------------

    /**
     * Populate the form with an existing goal's values and open the edit modal.
     * Called from the "Edit" button on each goal card.
     */
    public function openEdit(int $goalId, GoalProgressQuery $query, CurrentUser $currentUser): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        // Find the goal in the active rows so we only allow editing owned goals.
        $rows = $query->forUser($currentUser->user());
        foreach ($rows as $row) {
            if ($row->id === $goalId) {
                $this->editGoalId = $goalId;
                $this->name = $row->name;
                $this->targetAmount = number_format($row->targetMinor / 100, 2, '.', '');
                $this->targetDate = '';   // no target_date on GoalProgressRow; will be left blank for now
                $this->accountId = $row->accountId !== null ? (string) $row->accountId : '';
                $this->clearErrors();
                $this->dispatch('modal-show', name: 'goal-form');

                return;
            }
        }
    }

    /**
     * Update an existing goal. Delegates cross-user rejection to GoalWriter
     * (which throws if the goal is not owned by the user).
     */
    public function updateGoal(CurrentUser $currentUser, GoalWriter $writer): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated() || $this->editGoalId === 0) {
            return;
        }

        if (trim($this->name) === '') {
            $this->errorName = 'Enter a name for your goal.';

            return;
        }

        if (trim($this->targetDate) === '') {
            $this->errorDate = 'Choose a target date.';

            return;
        }

        $accountId = $this->accountId !== '' ? (int) $this->accountId : null;

        try {
            $writer->update(
                $currentUser->user(),
                $this->editGoalId,
                $this->name,
                $this->targetAmount,
                $this->targetDate,
                $accountId,
            );
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'not found')) {
                // Cross-user attempt — silently ignore.
                $this->resetForm();

                return;
            }
            $this->errorAmount = 'Enter a valid amount greater than zero.';

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-hide', name: 'goal-form');
        $this->toast('Goal updated.');
    }

    // -----------------------------------------------------------------------
    // Lifecycle actions
    // -----------------------------------------------------------------------

    /**
     * Mark a goal as complete. The goal stays in the active list with a
     * "Completed" chip (D-08 — "reached" badge is passive; "complete" is
     * an explicit user action).
     */
    public function markComplete(CurrentUser $currentUser, GoalWriter $writer, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->markComplete($currentUser->user(), $goalId);
        $this->toast('Goal marked as complete.');
    }

    /**
     * Show the in-card micro-confirm for archiving a goal.
     * Actual archive happens in `archive()`.
     */
    public function confirmArchive(int $goalId): void
    {
        $this->archivingGoalId = $goalId;
    }

    /**
     * Cancel the archive micro-confirm.
     */
    public function cancelArchive(): void
    {
        $this->archivingGoalId = 0;
    }

    /**
     * Archive a goal after micro-confirm. Dispatches a "Goal archived. [Restore]"
     * toast with a one-tap undo action (D-09).
     */
    public function archive(CurrentUser $currentUser, GoalWriter $writer, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->archive($currentUser->user(), $goalId);
        $this->archivingGoalId = 0;
        $this->dispatch('toast', message: 'Goal archived.', undoAction: 'restore', undoPayload: $goalId);
    }

    /**
     * Restore an archived goal back to active status.
     */
    public function restore(CurrentUser $currentUser, GoalWriter $writer, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->restore($currentUser->user(), $goalId);
        $this->toast('Goal restored.');
    }

    /**
     * Cancel / close the create-or-edit modal, resetting all form state.
     */
    public function cancel(): void
    {
        $this->resetForm();
        $this->dispatch('modal-hide', name: 'goal-form');
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    public function render(
        CurrentUser $currentUser,
        GoalProgressQuery $query,
        DatabaseManager $db,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $rows = $query->forUser($user);
        $archived = $query->archivedForUser($user);

        // User's accounts for the account picker (savings-kind first, then name order).
        $accounts = $db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->orderByRaw("CASE WHEN kind = 'savings' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'kind'])
            ->toArray();

        $view = $views->make('goals::livewire.goals-page', [
            'rows' => $rows,
            'archived' => $archived,
            'accounts' => $accounts,
            'baseCurrency' => $user->base_currency,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Goals · beatrax']);

        return $view;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function clearErrors(): void
    {
        $this->errorName = '';
        $this->errorAmount = '';
        $this->errorDate = '';
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->targetAmount = '';
        $this->targetDate = '';
        $this->accountId = '';
        $this->editGoalId = 0;
        $this->clearErrors();
    }

    private function toast(string $message): void
    {
        $this->dispatch('toast', message: $message, undoAction: '', undoPayload: null);
    }
}
