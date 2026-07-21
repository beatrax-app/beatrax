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
 * @link ../../../../../.docs/features/goals/architecture.md
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

    // The pot picker's option list is scoped to the selected account, but the
    // bound property survives a re-render — switching accounts would
    // silently keep (and submit) a pot from the previously selected account.
    public function updatedAccountId(): void
    {
        $this->linkedPotId = '';
    }

    // -----------------------------------------------------------------------
    // Create goal
    // -----------------------------------------------------------------------

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

        // Goal save + optional pot link run in one transaction so a failed
        // link rolls back the goal — no orphan goal, no duplicate on resubmit.
        try {
            $db->connection()->transaction(function () use ($currentUser, $writer, $db, $potWriter, $accountId): void {
                $goal = $writer->save(
                    $currentUser->user(),
                    $this->name,
                    $this->targetAmount,
                    $this->targetDate,
                    $accountId,
                );

                if ($this->linkedPotId !== '') {
                    $this->linkPotToGoal($currentUser, $db, $potWriter, (int) $this->linkedPotId, $goal->id);
                }
            });
        } catch (InvalidGoalAmountException) {
            $this->errorAmount = 'Enter a valid amount greater than zero.';

            return;
        } catch (\InvalidArgumentException $e) {
            $this->errorLinkedPot = $e->getMessage();

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'goal-form');
        $this->toast('Goal created.');
    }

    // -----------------------------------------------------------------------
    // Edit / update goal
    // -----------------------------------------------------------------------

    public function openEdit(int $goalId, GoalProgressQuery $query, CurrentUser $currentUser, PotBalanceQuery $potBalance): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $rows = $query->forUser($currentUser->user());
        foreach ($rows as $row) {
            if ($row->id === $goalId) {
                $this->editGoalId = $goalId;
                $this->name = $row->name;
                // Integer-only minor -> display formatting — no float
                // division on a money amount.
                $this->targetAmount = sprintf('%d.%02d', intdiv($row->targetMinor, 100), $row->targetMinor % 100);
                $this->targetDate = $row->targetDate;
                $this->accountId = $row->accountId !== null ? (string) $row->accountId : '';
                $linkedPotId = $potBalance->linkedPotIdForGoal($goalId, $currentUser->user());
                $this->linkedPotId = $linkedPotId !== null ? (string) $linkedPotId : '';
                $this->clearErrors();
                $this->dispatch('modal-show', name: 'goal-form');

                return;
            }
        }
    }

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

        $newPotId = $this->linkedPotId !== '' ? (int) $this->linkedPotId : null;
        $prevPotId = $potBalance->linkedPotIdForGoal($this->editGoalId, $currentUser->user());

        // The goal update and the clear + relink sequence run in one
        // transaction so a failed relink rolls back both — the existing
        // goal<->pot link is never silently lost.
        try {
            $db->connection()->transaction(function () use ($currentUser, $writer, $db, $potWriter, $accountId, $newPotId, $prevPotId): void {
                $writer->update(
                    $currentUser->user(),
                    $this->editGoalId,
                    $this->name,
                    $this->targetAmount,
                    $this->targetDate,
                    $accountId,
                );

                if ($newPotId !== null && $newPotId !== $prevPotId) {
                    if ($prevPotId !== null) {
                        $this->clearPotGoalLink($currentUser, $db, $potWriter, $prevPotId);
                    }

                    $this->linkPotToGoal($currentUser, $db, $potWriter, $newPotId, $this->editGoalId);
                } elseif ($newPotId === null && $prevPotId !== null) {
                    $this->clearPotGoalLink($currentUser, $db, $potWriter, $prevPotId);
                }
            });
        } catch (GoalNotFoundException) {
            $this->resetForm();

            return;
        } catch (InvalidGoalAmountException) {
            $this->errorAmount = 'Enter a valid amount greater than zero.';

            return;
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

    public function markComplete(CurrentUser $currentUser, GoalWriter $writer, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->markComplete($currentUser->user(), $goalId);
        $this->toast('Goal marked as complete.');
    }

    public function confirmArchive(CurrentUser $currentUser, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $this->archivingGoalId = $goalId;
    }

    public function cancelArchive(CurrentUser $currentUser): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $this->archivingGoalId = 0;
    }

    public function archive(CurrentUser $currentUser, GoalWriter $writer, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->archive($currentUser->user(), $goalId);
        $this->archivingGoalId = 0;
        $this->dispatch('toast', message: 'Goal archived.', undoAction: 'restore', undoPayload: $goalId);
    }

    public function restore(CurrentUser $currentUser, GoalWriter $writer, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->restore($currentUser->user(), $goalId);
        $this->toast('Goal restored.');
    }

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
        // Defence-in-depth: the auth route middleware makes this unreachable,
        // but guard anyway so an unauthenticated render degrades to the empty
        // page instead of throwing.
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

        $accounts = $db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->orderByRaw("CASE WHEN kind = 'savings' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'kind'])
            ->toArray();

        // Pot options for the linked-pot picker: only active pots owned by
        // this user that are fully unlinked (neither goal- nor category-
        // linked), or already linked to the goal being edited so the
        // current link stays in the list. Scoped to the selected account.
        $selectedAccountId = $this->accountId !== '' ? (int) $this->accountId : null;

        // Single source for the base picker query so the edit-mode union
        // branch cannot drift from the non-edit branch.
        $basePotsQuery = static function () use ($db, $user, $selectedAccountId): Builder {
            return $db->connection()
                ->table('pots')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where(static function (Builder $q) use ($selectedAccountId): void {
                    if ($selectedAccountId !== null) {
                        $q->where('account_id', $selectedAccountId);
                    }
                })
                ->whereNull('goal_id')
                ->whereNull('category_id');
        };

        $potsQuery = $basePotsQuery();

        if ($this->editGoalId !== 0) {
            $potsQuery = $basePotsQuery()
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
     * @throws \InvalidArgumentException on one-pot-per-goal violation or category-linked pot
     * @throws PotNotFoundException on cross-user pot id
     */
    private function linkPotToGoal(
        CurrentUser $currentUser,
        DatabaseManager $db,
        PotWriter $potWriter,
        int $potId,
        int $goalId,
    ): void {
        $user = $currentUser->user();

        $row = $db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('id', $potId)
            ->first(['name', 'category_id']);

        // PotWriter::update would otherwise null out category_id and
        // silently destroy the user's category link; the picker already
        // excludes these, this guards against stale/tampered ids.
        if ($row !== null && $row->category_id !== null) {
            throw new \InvalidArgumentException(
                'This pot is linked to a category. Remove that link on the Pots page first.'
            );
        }

        $name = ($row !== null && is_string($row->name)) ? $row->name : '';
        $potWriter->update($user, $potId, $name, $goalId, null);
    }

    /**
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
