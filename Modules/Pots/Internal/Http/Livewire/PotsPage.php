<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Public\Exceptions\InsufficientUnallocatedException;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

/**
 * @link ../../../../../.docs/features/pots/architecture.md
 */
final class PotsPage extends Component
{
    public string $name = '';

    public string $amount = '';

    public string $accountId = '';

    public string $linkType = 'none';

    public string $goalId = '';

    public int $editPotId = 0;

    public int $operationPotId = 0;

    public string $operationAmount = '';

    public string $operationMemo = '';

    public string $operationKind = '';

    // String-typed because it backs a <select> whose placeholder option is
    // '' — hydrating '' into an int property throws a Livewire property-type
    // error instead of a validation message. Cast in movePot().
    public string $transferTargetPotId = '';

    public int $archivingPotId = 0;

    public string $errorName = '';

    public string $errorAmount = '';

    public bool $showArchived = false;

    // -----------------------------------------------------------------------
    // Create pot
    // -----------------------------------------------------------------------

    public function createPot(CurrentUser $currentUser, PotWriter $writer): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $accountId = $this->accountId !== '' ? (int) $this->accountId : 0;
        $error = match (true) {
            trim($this->name) === '' => 'Enter a name for this pot.',
            $accountId === 0 => 'Select an account for this pot.',
            default => null,
        };
        if ($error !== null) {
            $this->errorName = $error;

            return;
        }

        // linkType is 'goal' | 'none' only — PotWriter always receives a
        // null categoryId here.
        $goalId = ($this->linkType === 'goal' && $this->goalId !== '') ? (int) $this->goalId : null;
        $rawAmount = trim($this->amount) !== '' ? $this->amount : null;

        try {
            $writer->save(
                $currentUser->user(),
                $this->name,
                $rawAmount,
                $accountId,
                $goalId,
                null,
            );
        } catch (InsufficientUnallocatedException|\InvalidArgumentException $e) {
            if ($e instanceof InsufficientUnallocatedException) {
                $this->errorAmount = 'Amount exceeds unallocated balance.';
            } else {
                $this->errorName = $e->getMessage();
            }

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
        $this->toast('Pot created.');
    }

    // -----------------------------------------------------------------------
    // Edit / update pot
    // -----------------------------------------------------------------------

    public function openEdit(int $potId, PotBalanceQuery $query, CurrentUser $currentUser): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $rows = $query->forUser($currentUser->user());
        foreach ($rows as $pot) {
            if ($pot->id === $potId) {
                $this->editPotId = $potId;
                $this->name = $pot->name;
                $this->accountId = (string) $pot->accountId;
                if ($pot->goalId !== null) {
                    $this->linkType = 'goal';
                    $this->goalId = (string) $pot->goalId;
                } else {
                    // Any pot with a lingering category_id falls back to
                    // 'none' rather than surfacing a picker that no longer
                    // exists.
                    $this->linkType = 'none';
                    $this->goalId = '';
                }
                $this->clearErrors();
                $this->dispatch('modal-show', name: 'pot-form');

                return;
            }
        }
    }

    public function updatePot(CurrentUser $currentUser, PotWriter $writer): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated() || $this->editPotId === 0) {
            return;
        }

        if (trim($this->name) === '') {
            $this->errorName = 'Enter a name for this pot.';

            return;
        }

        $goalId = null;
        if ($this->linkType === 'goal' && $this->goalId !== '') {
            $goalId = (int) $this->goalId;
        }

        try {
            $writer->update(
                $currentUser->user(),
                $this->editPotId,
                $this->name,
                $goalId,
                null,
            );
        } catch (PotNotFoundException|\InvalidArgumentException $e) {
            if ($e instanceof PotNotFoundException) {
                $this->resetForm();
            } else {
                $this->errorName = $e->getMessage();
            }

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
        $this->toast('Pot updated.');
    }

    // -----------------------------------------------------------------------
    // Fund pot
    // -----------------------------------------------------------------------

    public function fundPot(CurrentUser $currentUser, PotWriter $writer, PotBalanceQuery $query): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $memo = trim($this->operationMemo) !== '' ? $this->operationMemo : null;

        try {
            $writer->fund(
                $currentUser->user(),
                $this->operationPotId,
                $this->operationAmount,
                $memo,
            );
        } catch (InsufficientUnallocatedException) {
            $user = $currentUser->user();
            $pot = null;
            foreach ($query->forUser($user) as $p) {
                if ($p->id === $this->operationPotId) {
                    $pot = $p;
                    break;
                }
            }
            $unallocated = 0;
            $currency = 'EUR';
            if ($pot !== null) {
                $rec = $query->reconciliationForAccount($pot->accountId, $user);
                $unallocated = $rec->unallocatedMinor;
                $currency = $pot->currency;
            }
            $availableFormatted = Money::ofMinor(
                max(0, $unallocated),
                $currency
            )->format($currency === 'EUR' ? 'nl_NL' : 'en_US');
            $this->errorAmount = "Amount exceeds unallocated balance ({$availableFormatted} available).";

            return;
        } catch (\InvalidArgumentException $e) {
            $this->errorAmount = $e->getMessage();

            return;
        }

        $this->resetOperationModal();
        $this->dispatch('modal-close', name: 'pot-fund');
        $this->toast('Pot funded.');
    }

    // -----------------------------------------------------------------------
    // Withdraw
    // -----------------------------------------------------------------------

    public function withdrawPot(CurrentUser $currentUser, PotWriter $writer, PotBalanceQuery $query): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $memo = trim($this->operationMemo) !== '' ? $this->operationMemo : null;

        $user = $currentUser->user();
        $pot = null;
        foreach ($query->forUser($user) as $p) {
            if ($p->id === $this->operationPotId) {
                $pot = $p;
                break;
            }
        }

        try {
            $writer->withdraw(
                $user,
                $this->operationPotId,
                $this->operationAmount,
                $memo,
            );
        } catch (InsufficientUnallocatedException) {
            $potName = 'pot';
            $balance = 0;
            $currency = 'EUR';
            if ($pot !== null) {
                $potName = $pot->name;
                $balance = $pot->balanceMinor;
                $currency = $pot->currency;
            }
            $availableFormatted = Money::ofMinor(
                max(0, $balance),
                $currency
            )->format($currency === 'EUR' ? 'nl_NL' : 'en_US');
            $this->errorAmount = "Amount exceeds balance in {$potName} ({$availableFormatted} available).";

            return;
        } catch (\InvalidArgumentException $e) {
            $this->errorAmount = $e->getMessage();

            return;
        }

        $this->resetOperationModal();
        $this->dispatch('modal-close', name: 'pot-withdraw');
        $this->toast('Withdrawn from pot.');
    }

    // -----------------------------------------------------------------------
    // Move / transfer
    // -----------------------------------------------------------------------

    public function movePot(CurrentUser $currentUser, PotWriter $writer, PotBalanceQuery $query): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $memo = trim($this->operationMemo) !== '' ? $this->operationMemo : null;

        $user = $currentUser->user();
        $sourcePot = null;
        foreach ($query->forUser($user) as $p) {
            if ($p->id === $this->operationPotId) {
                $sourcePot = $p;
                break;
            }
        }

        try {
            // '' (placeholder) casts to 0, which PotWriter rejects with
            // PotNotFoundException, surfacing as an inline error below.
            $writer->transfer(
                $user,
                $this->operationPotId,
                (int) $this->transferTargetPotId,
                $this->operationAmount,
                $memo,
            );
        } catch (InsufficientUnallocatedException) {
            $potName = 'pot';
            $balance = 0;
            $currency = 'EUR';
            if ($sourcePot !== null) {
                $potName = $sourcePot->name;
                $balance = $sourcePot->balanceMinor;
                $currency = $sourcePot->currency;
            }
            $availableFormatted = Money::ofMinor(
                max(0, $balance),
                $currency
            )->format($currency === 'EUR' ? 'nl_NL' : 'en_US');
            $this->errorAmount = "Amount exceeds balance in {$potName} ({$availableFormatted} available).";

            return;
        } catch (\InvalidArgumentException $e) {
            $this->errorAmount = $e->getMessage();

            return;
        }

        $this->resetOperationModal();
        $this->dispatch('modal-close', name: 'pot-move');
        $this->toast('Funds moved.');
    }

    // -----------------------------------------------------------------------
    // Archive / restore
    // -----------------------------------------------------------------------

    public function confirmArchive(CurrentUser $currentUser, int $potId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $this->archivingPotId = $potId;
    }

    public function cancelArchive(CurrentUser $currentUser): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $this->archivingPotId = 0;
    }

    public function archivePot(CurrentUser $currentUser, PotWriter $writer, int $potId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->archive($currentUser->user(), $potId);
        $this->archivingPotId = 0;
        $this->dispatch('toast', message: 'Pot archived.', undoAction: 'restore', undoPayload: $potId);
    }

    public function restorePot(CurrentUser $currentUser, PotWriter $writer, int $potId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->restore($currentUser->user(), $potId);
        $this->toast('Pot restored.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    public function render(
        CurrentUser $currentUser,
        PotBalanceQuery $query,
        DatabaseManager $db,
        ViewFactory $views,
    ): View {
        // Defence-in-depth: the auth route middleware makes this unreachable,
        // but guard anyway so an unauthenticated render degrades gracefully.
        if (! $currentUser->isAuthenticated()) {
            $view = $views->make('pots::livewire.pots-page', [
                'groups' => [],
                'reconciliations' => [],
                'archived' => [],
                'accounts' => [],
                'goalsForPicker' => [],
                'potsForMove' => [],
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => 'Pots · beatrax']);

            return $view;
        }

        $user = $currentUser->user();

        $activePots = $query->forUser($user);

        $groups = [];
        foreach ($activePots as $pot) {
            $groups[$pot->accountId][] = $pot;
        }

        $reconciliations = [];
        foreach (array_keys($groups) as $accountId) {
            $reconciliations[$accountId] = $query->reconciliationForAccount($accountId, $user);
        }

        $archived = $query->archivedForUser($user);

        $accounts = $db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'default_currency'])
            ->toArray();

        $linkedGoalIds = $db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNotNull('goal_id')
            ->pluck('goal_id')
            ->toArray();

        $goalsForPickerQuery = $db->connection()
            ->table('goals')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderBy('name');

        if ($this->editPotId !== 0) {
            // When editing, exclude goals linked to OTHER pots but allow the
            // current pot's own goal. editPotId is client-controlled, so
            // scope this lookup to the user like every other read here.
            $currentPotGoalId = $db->connection()
                ->table('pots')
                ->where('user_id', $user->id)
                ->where('id', $this->editPotId)
                ->value('goal_id');
            $goalsToExclude = array_filter(
                $linkedGoalIds,
                static fn (mixed $id): bool => $id !== $currentPotGoalId
            );
            if ($goalsToExclude !== []) {
                $goalsForPickerQuery->whereNotIn('id', array_values($goalsToExclude));
            }
        } elseif ($linkedGoalIds !== []) {
            $goalsForPickerQuery->whereNotIn('id', $linkedGoalIds);
        }

        $goalsForPicker = $goalsForPickerQuery->get(['id', 'name'])->toArray();

        $view = $views->make('pots::livewire.pots-page', [
            'groups' => $groups,
            'reconciliations' => $reconciliations,
            'archived' => $archived,
            'accounts' => $accounts,
            'goalsForPicker' => $goalsForPicker,
            // The move-modal destination picker consumes the same
            // account-grouped active pots as the card list — one structure,
            // two view variables.
            'potsForMove' => $groups,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Pots · beatrax']);

        return $view;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function clearErrors(): void
    {
        $this->errorName = '';
        $this->errorAmount = '';
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->amount = '';
        $this->accountId = '';
        $this->linkType = 'none';
        $this->goalId = '';
        $this->editPotId = 0;
        $this->clearErrors();
    }

    private function resetOperationModal(): void
    {
        $this->operationPotId = 0;
        $this->operationAmount = '';
        $this->operationMemo = '';
        $this->operationKind = '';
        $this->transferTargetPotId = '';
        $this->clearErrors();
    }

    private function toast(string $message): void
    {
        $this->dispatch('toast', message: $message, undoAction: '', undoPayload: null);
    }
}
