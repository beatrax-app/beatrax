<?php

declare(strict_types=1);

namespace Modules\Goals\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Component;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Goals\Public\Exceptions\GoalNotFoundException;
use Modules\Goals\Public\Exceptions\InvalidGoalAmountException;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

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

    public string $linkedPotId = '';

    public int $editGoalId = 0;

    public string $errorName = '';

    public string $errorAmount = '';

    public string $errorDate = '';

    public string $errorLinkedPot = '';

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
    public function createGoal(CurrentUser $currentUser, GoalWriter $writer, DatabaseManager $db, PotWriter $potWriter): void
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
            $goal = $writer->save(
                $currentUser->user(),
                $this->name,
                $this->targetAmount,
                $this->targetDate,
                $accountId,
            );
        } catch (InvalidGoalAmountException $e) {
            $this->errorAmount = 'Enter a valid amount greater than zero.';

            return;
        }

        // D-11: if a pot was selected, link it from the goal side via PotWriter.
        if ($this->linkedPotId !== '') {
            $potId = (int) $this->linkedPotId;
            try {
                $this->linkPotToGoal($currentUser, $db, $potWriter, $potId, $goal->id);
            } catch (\InvalidArgumentException $e) {
                $this->errorLinkedPot = $e->getMessage();

                return;
            }
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'goal-form');
        $this->toast('Goal created.');
    }

    // -----------------------------------------------------------------------
    // Edit / update goal
    // -----------------------------------------------------------------------

    /**
     * Populate the form with an existing goal's values and open the edit modal.
     * Called from the "Edit" button on each goal card.
     */
    public function openEdit(int $goalId, GoalProgressQuery $query, CurrentUser $currentUser, PotBalanceQuery $potBalance): void
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
                $this->targetDate = $row->targetDate;   // prefill from the goal's stored target date (WR-01)
                $this->accountId = $row->accountId !== null ? (string) $row->accountId : '';
                // D-11: prefill the linked pot picker from the goal side.
                $linkedPotId = $potBalance->linkedPotIdForGoal($goalId, $currentUser->user());
                $this->linkedPotId = $linkedPotId !== null ? (string) $linkedPotId : '';
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
    public function updateGoal(CurrentUser $currentUser, GoalWriter $writer, PotBalanceQuery $potBalance, DatabaseManager $db, PotWriter $potWriter): void
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
        } catch (GoalNotFoundException $e) {
            // Cross-user / missing goal — silently ignore (control flow on
            // exception type, not message text — WR-05).
            $this->resetForm();

            return;
        } catch (InvalidGoalAmountException $e) {
            $this->errorAmount = 'Enter a valid amount greater than zero.';

            return;
        }

        // D-11: handle the pot picker change (link, move, or clear).
        $newPotId = $this->linkedPotId !== '' ? (int) $this->linkedPotId : null;
        $prevPotId = $potBalance->linkedPotIdForGoal($this->editGoalId, $currentUser->user());

        try {
            if ($newPotId !== null && $newPotId !== $prevPotId) {
                // Clear the previous link first so assertGoalOwnedAndFree passes (D-11).
                if ($prevPotId !== null) {
                    $this->clearPotGoalLink($currentUser, $db, $potWriter, $prevPotId);
                }

                $this->linkPotToGoal($currentUser, $db, $potWriter, $newPotId, $this->editGoalId);
            } elseif ($newPotId === null && $prevPotId !== null) {
                // User cleared the selection — remove the link.
                $this->clearPotGoalLink($currentUser, $db, $potWriter, $prevPotId);
            }
        } catch (\InvalidArgumentException $e) {
            $this->errorLinkedPot = $e->getMessage();

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'goal-form');
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
    public function confirmArchive(CurrentUser $currentUser, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $this->archivingGoalId = $goalId;
    }

    /**
     * Cancel the archive micro-confirm.
     */
    public function cancelArchive(CurrentUser $currentUser): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

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
        $this->dispatch('modal-close', name: 'goal-form');
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
        // Defence-in-depth: the `auth` route middleware makes this unreachable,
        // but guard anyway so an unauthenticated render degrades to the empty
        // page instead of throwing NotAuthenticatedException (IN-03).
        if (! $currentUser->isAuthenticated()) {
            $view = $views->make('goals::livewire.goals-page', [
                'rows' => [],
                'archived' => [],
                'accounts' => [],
                'pots' => [],
                'baseCurrency' => 'EUR',
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => 'Goals · beatrax']);

            return $view;
        }

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

        // Pot options for the linked-pot picker (D-11 / T-03-13):
        // Only active pots owned by this user that are either unlinked or
        // already linked to the goal being edited (so the current link stays
        // in the list). Scoped to the selected account when one is chosen.
        $selectedAccountId = $this->accountId !== '' ? (int) $this->accountId : null;
        $potsQuery = $db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(static function (Builder $q) use ($selectedAccountId): void {
                if ($selectedAccountId !== null) {
                    $q->where('account_id', $selectedAccountId);
                }
            })
            ->where(static function (Builder $q): void {
                $q->whereNull('goal_id');
            });

        // If editing, also include the pot currently linked to this goal.
        if ($this->editGoalId !== 0) {
            $potsQuery = $db->connection()
                ->table('pots')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where(static function (Builder $q) use ($selectedAccountId): void {
                    if ($selectedAccountId !== null) {
                        $q->where('account_id', $selectedAccountId);
                    }
                })
                ->where(static function (Builder $q): void {
                    $q->whereNull('goal_id');
                })
                ->union(
                    $db->connection()
                        ->table('pots')
                        ->where('user_id', $user->id)
                        ->where('status', 'active')
                        ->where('goal_id', $this->editGoalId)
                        ->select(['id', 'name', 'account_id', 'goal_id'])
                );
        }

        $pots = $potsQuery->get(['id', 'name', 'account_id', 'goal_id'])->toArray();

        $view = $views->make('goals::livewire.goals-page', [
            'rows' => $rows,
            'archived' => $archived,
            'accounts' => $accounts,
            'pots' => $pots,
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
        $this->errorLinkedPot = '';
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->targetAmount = '';
        $this->targetDate = '';
        $this->accountId = '';
        $this->linkedPotId = '';
        $this->editGoalId = 0;
        $this->clearErrors();
    }

    /**
     * Fetch the pot's current name so PotWriter::update does not blank it.
     * Returns a fallback empty string on lookup miss (should not happen in
     * normal flow — the picker only shows pots the user owns).
     */
    private function potName(int $potId, User $user, DatabaseManager $db): string
    {
        $row = $db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('id', $potId)
            ->first(['name']);

        if ($row === null) {
            return '';
        }

        return is_string($row->name) ? $row->name : '';
    }

    /**
     * Link $potId to $goalId using PotWriter::update. Fetches the pot's current
     * name first so the update does not blank it.
     *
     * @throws \InvalidArgumentException on one-pot-per-goal violation (T-03-12)
     * @throws PotNotFoundException on cross-user pot id (T-03-11)
     */
    private function linkPotToGoal(
        CurrentUser $currentUser,
        DatabaseManager $db,
        PotWriter $potWriter,
        int $potId,
        int $goalId,
    ): void {
        $user = $currentUser->user();
        $name = $this->potName($potId, $user, $db);
        $potWriter->update($user, $potId, $name, $goalId, null);
    }

    /**
     * Clear the goal_id link from $potId — sets goal_id to null.
     *
     * @throws PotNotFoundException on cross-user pot id
     */
    private function clearPotGoalLink(
        CurrentUser $currentUser,
        DatabaseManager $db,
        PotWriter $potWriter,
        int $potId,
    ): void {
        $user = $currentUser->user();
        $name = $this->potName($potId, $user, $db);
        $potWriter->update($user, $potId, $name, null, null);
    }

    private function toast(string $message): void
    {
        $this->dispatch('toast', message: $message, undoAction: '', undoPayload: null);
    }
}
