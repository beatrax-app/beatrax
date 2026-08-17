<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
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
    use DispatchesToast;

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
            trim($this->name) === '' => Lang::get('pots::messages.errors.enter_name'),
            $accountId === 0 => Lang::get('pots::messages.errors.select_account'),
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
                $this->errorAmount = Lang::get('pots::messages.errors.amount_exceeds_unallocated');
            } else {
                $this->errorName = $e->getMessage();
            }

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
        $this->toast(Lang::get('pots::messages.toast.pot_created'));
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

                // Deliberately does NOT dispatch `modal-show` — see the same
                // note in GoalsPage::openEdit(). Every Edit affordance here
                // already picks sheet-or-modal from the viewport width, and
                // announcing it from the server put the desktop modal on top
                // of the phone sheet.

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
            $this->errorName = Lang::get('pots::messages.errors.enter_name');

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
        } catch (\InvalidArgumentException $e) {
            // PotNotFoundException derives from InvalidArgumentException, so
            // the single catch covers both; the instance check keeps the
            // not-found reset distinct from a surfaced validation message.
            if ($e instanceof PotNotFoundException) {
                $this->resetForm();
            } else {
                $this->errorName = $e->getMessage();
            }

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
        $this->toast(Lang::get('pots::messages.toast.pot_updated'));
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
            $this->errorAmount = Lang::get(
                'pots::messages.errors.amount_exceeds_unallocated_available',
                ['amount' => $availableFormatted],
            );

            return;
        } catch (\InvalidArgumentException $e) {
            $this->errorAmount = $e->getMessage();

            return;
        }

        $this->resetOperationModal();
        $this->dispatch('modal-close', name: 'pot-fund');
        $this->toast(Lang::get('pots::messages.toast.pot_funded'));
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
            $potName = Lang::get('pots::messages.pot_fallback');
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
            $this->errorAmount = Lang::get(
                'pots::messages.errors.amount_exceeds_pot_balance',
                ['name' => $potName, 'amount' => $availableFormatted],
            );

            return;
        } catch (\InvalidArgumentException $e) {
            $this->errorAmount = $e->getMessage();

            return;
        }

        $this->resetOperationModal();
        $this->dispatch('modal-close', name: 'pot-withdraw');
        $this->toast(Lang::get('pots::messages.toast.withdrawn'));
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
            $potName = Lang::get('pots::messages.pot_fallback');
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
            $this->errorAmount = Lang::get(
                'pots::messages.errors.amount_exceeds_pot_balance',
                ['name' => $potName, 'amount' => $availableFormatted],
            );

            return;
        } catch (\InvalidArgumentException $e) {
            $this->errorAmount = $e->getMessage();

            return;
        }

        $this->resetOperationModal();
        $this->dispatch('modal-close', name: 'pot-move');
        $this->toast(Lang::get('pots::messages.toast.funds_moved'));
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
        $this->toastWithUndo(Lang::get('pots::messages.toast.pot_archived'), undoAction: 'restore', undoPayload: $potId);
    }

    public function restorePot(CurrentUser $currentUser, PotWriter $writer, int $potId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->restore($currentUser->user(), $potId);
        $this->toast(Lang::get('pots::messages.toast.pot_restored'));
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
            $view->extends('layouts.app', ['title' => Lang::get('pots::messages.page_title')]);

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

        $accounts = $query->accountsForUser($user);

        $goalsForPicker = $query->goalsForPicker($user, $this->editPotId);

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
        $view->extends('layouts.app', ['title' => 'Pots · Beatrax']);

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
        $this->toastWithUndo($message, undoAction: '', undoPayload: null);
    }
}
