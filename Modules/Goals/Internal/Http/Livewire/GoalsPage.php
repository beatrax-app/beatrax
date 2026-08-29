<?php

declare(strict_types=1);

namespace Modules\Goals\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Goals\Public\Exceptions\GoalNotFoundException;
use Modules\Goals\Public\Exceptions\InvalidGoalAmountException;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

final class GoalsPage extends Component
{
    use DispatchesToast;

    public string $name = '';

    public string $targetAmount = '';

    public string $targetDate = '';

    public string $linkedPotId = '';

    public int $editGoalId = 0;

    public string $errorName = '';

    public string $errorAmount = '';

    public string $errorDate = '';

    public string $errorLinkedPot = '';

    public int $archivingGoalId = 0;

    public bool $showArchived = false;

    public function createGoal(CurrentUser $currentUser, GoalWriter $writer, DatabaseManager $db, PotWriter $potWriter): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated() || ! $this->validateNameAndDate()) {
            return;
        }

        // One transaction so a failed pot link rolls back the goal: no orphan
        // goal, no duplicate on resubmit.
        try {
            $db->connection()->transaction(function () use ($currentUser, $writer, $db, $potWriter): void {
                $goal = $writer->save(
                    $currentUser->user(),
                    $this->name,
                    $this->targetAmount,
                    $this->targetDate,
                );

                if ($this->linkedPotId !== '') {
                    $this->linkPotToGoal($currentUser, $db, $potWriter, (int) $this->linkedPotId, $goal->id);
                }
            });

            $this->resetForm();
            $this->dispatch('modal-close', name: 'goal-form');
            $this->toast(Lang::get('goals::messages.notices.goal_created'));
        } catch (\InvalidArgumentException $e) {
            $this->applyWriteFailure($e);
        }
    }

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
                // The shared formatter emits the comma-decimal form the
                // placeholder shows and tryToMinor() parses back.
                $this->targetAmount = MoneyInput::formatMinor($row->targetMinor);
                $this->targetDate = $row->targetDate;
                $linkedPotId = $potBalance->linkedPotIdForGoal($goalId, $currentUser->user());
                $this->linkedPotId = $linkedPotId !== null ? (string) $linkedPotId : '';
                $this->clearErrors();

                // No `modal-show` dispatch: the surface is a viewport decision
                // both Edit buttons already make, and announcing it server-side
                // stacked the desktop modal on the phone's bottom sheet.
                return;
            }
        }
    }

    public function updateGoal(CurrentUser $currentUser, GoalWriter $writer, PotBalanceQuery $potBalance, DatabaseManager $db, PotWriter $potWriter): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated() || $this->editGoalId === 0 || ! $this->validateNameAndDate()) {
            return;
        }

        $newPotId = $this->linkedPotId !== '' ? (int) $this->linkedPotId : null;
        $prevPotId = $potBalance->linkedPotIdForGoal($this->editGoalId, $currentUser->user());

        // One transaction so a failed relink rolls the update back with it, and
        // the existing goal-pot link is never silently lost.
        try {
            $db->connection()->transaction(function () use ($currentUser, $writer, $db, $potWriter, $newPotId, $prevPotId): void {
                $writer->update(
                    $currentUser->user(),
                    $this->editGoalId,
                    $this->name,
                    $this->targetAmount,
                    $this->targetDate,
                );

                $this->applyPotRelink($currentUser, $db, $potWriter, $newPotId, $prevPotId);
            });

            $this->resetForm();
            $this->dispatch('modal-close', name: 'goal-form');
            $this->toast(Lang::get('goals::messages.notices.goal_updated'));
        } catch (\InvalidArgumentException $e) {
            $this->applyWriteFailure($e);
        }
    }

    public function markComplete(CurrentUser $currentUser, GoalWriter $writer, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->markComplete($currentUser->user(), $goalId);
        $this->toast(Lang::get('goals::messages.notices.goal_marked_complete'));
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
        $this->toastWithUndo(Lang::get('goals::messages.notices.goal_archived'), undoAction: 'restore', undoPayload: $goalId);
    }

    public function restore(CurrentUser $currentUser, GoalWriter $writer, int $goalId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->restore($currentUser->user(), $goalId);
        $this->toast(Lang::get('goals::messages.notices.goal_restored'));
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->dispatch('modal-close', name: 'goal-form');
    }

    public function render(
        CurrentUser $currentUser,
        GoalProgressQuery $query,
        DatabaseManager $db,
        ViewFactory $views,
        BaseCurrency $baseCurrency,
    ): View {
        // Unreachable behind the auth middleware; kept so an unauthenticated
        // render degrades to the empty page instead of throwing.
        if (! $currentUser->isAuthenticated()) {
            $view = $views->make('goals::livewire.goals-page', [
                'rows' => [],
                'archived' => [],
                'pots' => [],
                'baseCurrency' => $baseCurrency->installDefault(),
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => Lang::get('goals::messages.page.title').' · Beatrax']);

            return $view;
        }

        $user = $currentUser->user();
        $rows = $query->forUser($user);
        $archived = $query->archivedForUser($user);

        // Fully unlinked pots, plus the edited goal's own pot so its current link
        // stays selectable. One base query, so the two branches cannot drift.
        $basePotsQuery = static function () use ($db, $user): Builder {
            return $db->connection()
                ->table('pots')
                ->where('user_id', $user->id)
                ->where('status', GoalStatus::Active->value)
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
                        ->where('status', GoalStatus::Active->value)
                        ->where('goal_id', $this->editGoalId)
                        ->select(['id', 'name', 'account_id', 'goal_id'])
                );
        }

        $pots = $potsQuery->get(['id', 'name', 'account_id', 'goal_id'])->toArray();

        $view = $views->make('goals::livewire.goals-page', [
            'rows' => $rows,
            'archived' => $archived,
            'pots' => $pots,
            'baseCurrency' => $baseCurrency->forUser($user),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('goals::messages.page.title').' · Beatrax']);

        return $view;
    }

    // The date control syncs the moment a day is tapped, so the message it was
    // refused for outlives the refusal: the sheet showed 15/06/2027 with
    // "Choose a target date." in red under it, and aria-invalid still true, until
    // the next submit. The other three fields defer to the submit and clear there.
    public function updatedTargetDate(): void
    {
        $this->errorDate = '';
    }

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
        $this->linkedPotId = '';
        $this->editGoalId = 0;
        $this->clearErrors();
    }

    private function validateNameAndDate(): bool
    {
        if (trim($this->name) === '') {
            $this->errorName = Lang::get('goals::messages.errors.name');

            return false;
        }

        if (trim($this->targetDate) === '') {
            $this->errorDate = Lang::get('goals::messages.errors.date');

            return false;
        }

        return true;
    }

    private function applyWriteFailure(\InvalidArgumentException $e): void
    {
        if ($e instanceof GoalNotFoundException) {
            $this->resetForm();

            return;
        }

        if ($e instanceof InvalidGoalAmountException) {
            $this->errorAmount = Lang::get('goals::messages.errors.amount');

            return;
        }

        $this->errorLinkedPot = $e->getMessage();
    }

    private function applyPotRelink(
        CurrentUser $currentUser,
        DatabaseManager $db,
        PotWriter $potWriter,
        ?int $newPotId,
        ?int $prevPotId,
    ): void {
        if ($newPotId !== null && $newPotId !== $prevPotId) {
            if ($prevPotId !== null) {
                $this->clearPotGoalLink($currentUser, $db, $potWriter, $prevPotId);
            }

            $this->linkPotToGoal($currentUser, $db, $potWriter, $newPotId, $this->editGoalId);

            return;
        }

        if ($newPotId === null && $prevPotId !== null) {
            $this->clearPotGoalLink($currentUser, $db, $potWriter, $prevPotId);
        }
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

        // PotWriter::update would null out category_id and destroy the user's
        // category link. The picker excludes these; a stale id would not.
        if ($row !== null && $row->category_id !== null) {
            throw new \InvalidArgumentException(
                Lang::get('goals::messages.errors.pot_linked_category')
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

        $row = $db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('id', $potId)
            ->first(['name']);

        $name = ($row !== null && is_string($row->name)) ? $row->name : '';
        $potWriter->update($user, $potId, $name, null, null);
    }
}
