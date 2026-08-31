<?php

declare(strict_types=1);

namespace Modules\Goals\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Internal\Exceptions\GoalTargetDateBeforeStartException;
use Modules\Goals\Internal\Services\GoalPotLinkWriter;
use Modules\Goals\Public\Exceptions\GoalNotFoundException;
use Modules\Goals\Public\Exceptions\InvalidGoalAmountException;
use Modules\Goals\Public\Exceptions\InvalidGoalNameException;
use Modules\Goals\Public\Exceptions\InvalidGoalTargetDateException;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Pots\Public\Enums\PotStatus;
use Modules\Pots\Public\Exceptions\PotAlreadyLinkedException;
use Modules\Pots\Public\Exceptions\PotLinkedToCategoryException;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Pots\Public\Services\PotBalanceQuery;

final class GoalsPage extends Component
{
    use DispatchesToast;

    public string $name = '';

    public string $targetAmount = '';

    public string $targetDate = '';

    public string $linkedPotId = '';

    // Locked because editGoal() is the only writer and the Blade only reads it:
    // it names the row updateGoal() saves the form over. Unlocked, opening goal
    // A's sheet and naming goal B in the same payload wrote A's name, amount,
    // date and linked pot over B. PotsPage::$editPotId is the opposite case.
    #[Locked]
    public int $editGoalId = 0;

    public string $errorName = '';

    public string $errorAmount = '';

    public string $errorDate = '';

    public string $errorLinkedPot = '';

    public int $archivingGoalId = 0;

    public bool $showArchived = false;

    // The modal is dismissible and the sheet closes on the client, so a form
    // abandoned mid-edit keeps every field it was filled with. "Add goal" only
    // cleared editGoalId, so it re-opened the edited goal's own values and saved
    // them as a second goal -- taking the first goal's linked pot with it.
    public function startCreate(): void
    {
        $this->resetForm();
    }

    public function createGoal(CurrentUser $currentUser, GoalPotLinkWriter $writer): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated() || ! $this->validateNameAndDate()) {
            return;
        }

        try {
            $writer->create(
                $currentUser->user(),
                $this->name,
                $this->targetAmount,
                $this->targetDate,
                $this->linkedPotId !== '' ? (int) $this->linkedPotId : null,
            );

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
                // placeholder shows and tryToMinor() parses back, at the
                // goal's own scale so a yen target is not shown as a hundredth.
                $this->targetAmount = MoneyInput::formatMinor($row->targetMinor, $row->currency);
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

    public function updateGoal(CurrentUser $currentUser, GoalPotLinkWriter $writer, PotBalanceQuery $potBalance): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated() || $this->editGoalId === 0 || ! $this->validateNameAndDate()) {
            return;
        }

        try {
            $writer->update(
                $currentUser->user(),
                $this->editGoalId,
                $this->name,
                $this->targetAmount,
                $this->targetDate,
                $this->linkedPotId !== '' ? (int) $this->linkedPotId : null,
                $potBalance->linkedPotIdForGoal($this->editGoalId, $currentUser->user()),
            );

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

        // Undo rather than a prompt: the row drops the tick, the progress bar
        // and the target date the moment this lands, and restore() already
        // exists as archive's way back. A confirm on every completion would
        // charge the ordinary case for the mis-tap.
        $this->toastWithUndo(Lang::get('goals::messages.notices.goal_marked_complete'), undoAction: 'restore', undoPayload: $goalId);
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
                ->where('status', PotStatus::Active->value)
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
                        ->where('status', PotStatus::Active->value)
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

        $message = static fn (string $key): string => Lang::get('goals::messages.errors.'.$key);

        // The default is deliberately not the exception's own message, which
        // is written for a developer, exists in one language, and has nothing
        // to do with the linked pot it was being printed under.
        match (true) {
            $e instanceof InvalidGoalNameException => $this->errorName = $message('name'),
            $e instanceof InvalidGoalAmountException => $this->errorAmount = $message('amount'),
            // Narrower first: a real date the goal simply starts after is not
            // the unparseable one its parent reports, and "Choose a real date."
            // answered a question the reader had not asked.
            $e instanceof GoalTargetDateBeforeStartException => $this->errorDate = $message('date_before_start'),
            $e instanceof InvalidGoalTargetDateException => $this->errorDate = $message('date_invalid'),
            $e instanceof PotLinkedToCategoryException => $this->errorLinkedPot = $message('pot_linked_category'),
            $e instanceof PotAlreadyLinkedException => $this->errorLinkedPot = $message('pot_already_linked'),
            // The picker is built in render(), so a pot archived on the Pots
            // page after this modal opened is still on offer. Which of "no such
            // pot" and "not yours" it was stays unsaid.
            $e instanceof PotNotFoundException => $this->errorLinkedPot = $message('pot_missing'),
            default => $this->errorLinkedPot = $message('generic'),
        };
    }
}
