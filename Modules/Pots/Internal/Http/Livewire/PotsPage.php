<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Internal\Enums\PotLinkType;
use Modules\Pots\Public\Exceptions\InsufficientUnallocatedException;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

final class PotsPage extends Component
{
    use DispatchesToast;

    public string $name = '';

    public string $amount = '';

    public string $accountId = '';

    public string $linkType = PotLinkType::None->value;

    public string $goalId = '';

    public int $editPotId = 0;

    public int $operationPotId = 0;

    public string $operationAmount = '';

    public string $operationMemo = '';

    public string $operationKind = '';

    // String-typed: the select's placeholder option is '', and hydrating that
    // into an int property throws instead of validating. Cast in movePot().
    public string $transferTargetPotId = '';

    public int $archivingPotId = 0;

    public string $errorName = '';

    public string $errorAmount = '';

    // The ceiling the refusal quoted, so a corrected amount can be re-tested
    // against the same claim instead of dismissing it. Null where the refusal
    // was about the amount parsing at all rather than about a balance.
    public ?int $errorAmountLimitMinor = null;

    public bool $showArchived = false;

    // A refusal names the figure printed beside the box, and the box goes on
    // being edited underneath it. Typing 300 against 241,09 available and then
    // correcting to 100 left the message standing over a number it no longer
    // described. Re-tested rather than cleared: 500 is still refused.
    public function updated(string $property, mixed $value, PotWriter $writer): void
    {
        if ($property === 'name' || $property === 'accountId') {
            $this->errorName = '';
        }

        // A different account has a different unallocated balance, so the
        // quoted ceiling stops describing anything at all.
        if ($property === 'accountId') {
            $this->clearAmountError();

            return;
        }

        if ($property !== 'amount' && $property !== 'operationAmount') {
            return;
        }

        $typed = $property === 'amount' ? $this->amount : $this->operationAmount;
        if (! $this->amountStillRefused($typed, blankIsAllowed: $property === 'amount', writer: $writer)) {
            $this->clearAmountError();
        }
    }

    public function createPot(CurrentUser $currentUser, PotWriter $writer, PotBalanceQuery $query): void
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

        // PotWriter always gets a null categoryId: category-linked pots are
        // no longer creatable.
        $goalId = ($this->linkType === PotLinkType::Goal->value && $this->goalId !== '') ? (int) $this->goalId : null;
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
                $this->errorAmountLimitMinor = max(0, $query->currentUnallocatedForAccount($accountId, $currentUser->user()));
            } else {
                $this->errorName = $e->getMessage();
            }

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
        $this->toast(Lang::get('pots::messages.toast.pot_created'));
    }

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
                    $this->linkType = PotLinkType::Goal->value;
                    $this->goalId = (string) $pot->goalId;
                } else {
                    // A lingering category_id falls back to the unlinked case
                    // rather than surfacing a picker that no longer exists.
                    $this->linkType = PotLinkType::None->value;
                    $this->goalId = '';
                }
                $this->clearErrors();

                // No `modal-show` dispatch: announcing it server-side put the
                // desktop modal on top of the phone's bottom sheet.
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
        if ($this->linkType === PotLinkType::Goal->value && $this->goalId !== '') {
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
            // PotNotFoundException extends InvalidArgumentException, so one catch
            // covers both and the instance check separates the two responses.
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

    public function fundPot(CurrentUser $currentUser, PotWriter $writer, PotBalanceQuery $query, BaseCurrency $baseCurrency): void
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
            $currency = $baseCurrency->code();
            if ($pot !== null) {
                $rec = $query->reconciliationForAccount($pot->accountId, $user);
                $unallocated = $rec->unallocatedMinor;
                $currency = $pot->currency;
            }
            $this->errorAmountLimitMinor = max(0, $unallocated);
            $availableFormatted = Money::ofMinor(
                $this->errorAmountLimitMinor,
                $currency
            )->format();
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

    public function withdrawPot(CurrentUser $currentUser, PotWriter $writer, PotBalanceQuery $query, BaseCurrency $baseCurrency): void
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
            $currency = $baseCurrency->code();
            if ($pot !== null) {
                $potName = $pot->name;
                $balance = $pot->balanceMinor;
                $currency = $pot->currency;
            }
            $this->errorAmountLimitMinor = max(0, $balance);
            $availableFormatted = Money::ofMinor(
                $this->errorAmountLimitMinor,
                $currency
            )->format();
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

    public function movePot(CurrentUser $currentUser, PotWriter $writer, PotBalanceQuery $query, BaseCurrency $baseCurrency): void
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
            $currency = $baseCurrency->code();
            if ($sourcePot !== null) {
                $potName = $sourcePot->name;
                $balance = $sourcePot->balanceMinor;
                $currency = $sourcePot->currency;
            }
            $this->errorAmountLimitMinor = max(0, $balance);
            $availableFormatted = Money::ofMinor(
                $this->errorAmountLimitMinor,
                $currency
            )->format();
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

    public function render(
        CurrentUser $currentUser,
        PotBalanceQuery $query,
        ViewFactory $views,
    ): View {
        // Unreachable behind the auth middleware; kept so an unauthenticated
        // render degrades to the empty page instead of throwing.
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
            'potsForMove' => $groups,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('pots::messages.page_title')]);

        return $view;
    }

    private function clearErrors(): void
    {
        $this->errorName = '';
        $this->clearAmountError();
    }

    private function clearAmountError(): void
    {
        $this->errorAmount = '';
        $this->errorAmountLimitMinor = null;
    }

    private function amountStillRefused(string $typed, bool $blankIsAllowed, PotWriter $writer): bool
    {
        if ($this->errorAmount === '') {
            return false;
        }

        if (trim($typed) === '') {
            return ! $blankIsAllowed;
        }

        $minor = $writer->parseAmount($typed);
        if ($minor === null) {
            return true;
        }

        return $this->errorAmountLimitMinor !== null && $minor > $this->errorAmountLimitMinor;
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->amount = '';
        $this->accountId = '';
        $this->linkType = PotLinkType::None->value;
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
}
