<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Internal\Dto\StandingAmountRefusal;
use Modules\Pots\Internal\Enums\PotLinkType;
use Modules\Pots\Internal\Exceptions\AccountCannotHoldPotsException;
use Modules\Pots\Public\Dto\PotRow;
use Modules\Pots\Public\Exceptions\CrossAccountTransferException;
use Modules\Pots\Public\Exceptions\GoalAlreadyLinkedException;
use Modules\Pots\Public\Exceptions\InsufficientUnallocatedException;
use Modules\Pots\Public\Exceptions\InvalidPotAmountException;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Pots\Public\Exceptions\SelfTransferException;
use Modules\Pots\Public\Exceptions\TargetPotNotFoundException;
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

    // Its own slot rather than $errorAmount: the move's target refusals are not
    // about the figure, and the re-test below clears $errorAmount the moment a
    // readable amount is typed — which wiped a standing "pick another pot".
    public string $errorTarget = '';

    // The ceiling the refusal quoted, so a corrected amount can be re-tested
    // against the same claim instead of dismissing it. Null where the refusal
    // was about the amount parsing at all rather than about a balance.
    public ?int $errorAmountLimitMinor = null;

    // The ceiling's denomination, because the re-test parses the corrected
    // figure to compare it: a yen pot's "13840" read at a hundredth was
    // 100x the ceiling, so the refusal never cleared however it was retyped.
    public string $errorAmountLimitCurrency = '';

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

        if ($property === 'transferTargetPotId') {
            $this->errorTarget = '';

            return;
        }

        if ($property !== 'amount' && $property !== 'operationAmount') {
            return;
        }

        $typed = $property === 'amount' ? $this->amount : $this->operationAmount;
        $refusal = new StandingAmountRefusal($this->errorAmount, $this->errorAmountLimitMinor, $this->errorAmountLimitCurrency);

        if (! $refusal->stillRefuses($typed, blankIsAllowed: $property === 'amount', writer: $writer)) {
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
        $refused = false;

        try {
            $writer->save(
                $currentUser->user(),
                $this->name,
                $rawAmount,
                $accountId,
                $goalId,
                null,
            );
        } catch (InsufficientUnallocatedException) {
            // The same refusal fundPot() gives, with the same figure in it: one
            // rule may not read as two different messages.
            $this->refuseOverUnallocated($accountId, $currentUser, $query);
            $refused = true;
        } catch (\InvalidArgumentException $e) {
            $this->applyCreateRefusal($e);
            $refused = true;
        }

        if ($refused) {
            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
        $this->toast(Lang::get('pots::messages.toast.pot_created'));
    }

    private function refuseOverUnallocated(int $accountId, CurrentUser $currentUser, PotBalanceQuery $query): void
    {
        $reconciliation = $query->reconciliationForAccount($accountId, $currentUser->user());

        $this->errorAmountLimitMinor = max(0, $reconciliation->unallocatedMinor);
        $this->errorAmountLimitCurrency = $reconciliation->currency;
        $this->errorAmount = Lang::get(
            'pots::messages.errors.amount_exceeds_unallocated_available',
            ['amount' => Money::ofMinor($this->errorAmountLimitMinor, $reconciliation->currency)->format()],
        );
    }

    public function openEdit(int|string $potId, PotBalanceQuery $query, CurrentUser $currentUser): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $potId = DerivedRowId::fromWire($potId);

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
            } elseif ($e instanceof GoalAlreadyLinkedException) {
                $this->errorName = Lang::get('pots::messages.errors.goal_already_linked');
            } else {
                // Every other message here is written for a developer, and in
                // one language only.
                $this->errorName = Lang::get('pots::messages.errors.generic');
            }

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
        $this->toast(Lang::get('pots::messages.toast.pot_updated'));
    }

    // Which box a refusal belongs under. Narrowest first: the three named
    // arms all extend InvalidArgumentException, so testing the parent earlier
    // would swallow every one of them. The default is deliberately not the
    // exception's own message, which is written for a developer.
    private function applyCreateRefusal(\InvalidArgumentException $e): void
    {
        // The initial amount has a box of its own, and its refusal used to
        // arrive under the name as "check the fields".
        if ($e instanceof InvalidPotAmountException) {
            $this->errorAmount = Lang::get('pots::messages.errors.amount_invalid');

            return;
        }

        $this->errorName = Lang::get(match (true) {
            $e instanceof GoalAlreadyLinkedException => 'pots::messages.errors.goal_already_linked',
            $e instanceof AccountCannotHoldPotsException => 'pots::messages.errors.account_cannot_hold_pots',
            default => 'pots::messages.errors.generic',
        });
    }

    public function fundPot(CurrentUser $currentUser, PotWriter $writer, PotBalanceQuery $query, BaseCurrency $baseCurrency): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $memo = trim($this->operationMemo) !== '' ? $this->operationMemo : null;

        $refused = false;

        try {
            $writer->fund(
                $currentUser->user(),
                $this->operationPotId,
                $this->operationAmount,
                $memo,
            );
        } catch (InsufficientUnallocatedException) {
            $user = $currentUser->user();
            $pot = PotRow::withId($query->forUser($user), $this->operationPotId);
            $unallocated = 0;
            $currency = $baseCurrency->code();
            if ($pot !== null) {
                // The pot's currency decides which line the ceiling is read
                // from; quoting the account's line under the pot's sign is how
                // a EUR 105.714 ceiling was refused as if it were ¥15.000.
                $currency = $pot->currency;
                $unallocated = $query->reconciliationForAccount($pot->accountId, $user, $currency)->unallocatedMinor;
            }
            $this->errorAmountLimitMinor = max(0, $unallocated);
            $this->errorAmountLimitCurrency = $currency;
            $availableFormatted = Money::ofMinor(
                $this->errorAmountLimitMinor,
                $currency
            )->format();
            $this->errorAmount = Lang::get(
                'pots::messages.errors.amount_exceeds_unallocated_available',
                ['amount' => $availableFormatted],
            );
            $refused = true;
        } catch (InvalidPotAmountException) {
            $this->errorAmount = Lang::get('pots::messages.errors.amount_invalid');
            $refused = true;
        } catch (PotNotFoundException) {
            $this->abandonOperation('pot-fund', Lang::get('pots::messages.errors.pot_missing'));
            $refused = true;
        } catch (\InvalidArgumentException) {
            // Nothing else reaches here: fund() distinguishes every refusal it
            // has. A backstop with no cause to name says only what it knows.
            $this->toast(Lang::get('pots::messages.errors.operation_failed'));
            $refused = true;
        }

        if ($refused) {
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
        $pot = PotRow::withId($query->forUser($user), $this->operationPotId);

        $refused = false;

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
            $this->errorAmountLimitCurrency = $currency;
            $availableFormatted = Money::ofMinor(
                $this->errorAmountLimitMinor,
                $currency
            )->format();
            $this->errorAmount = Lang::get(
                'pots::messages.errors.amount_exceeds_pot_balance',
                ['name' => $potName, 'amount' => $availableFormatted],
            );
            $refused = true;
        } catch (InvalidPotAmountException) {
            $this->errorAmount = Lang::get('pots::messages.errors.amount_invalid');
            $refused = true;
        } catch (PotNotFoundException) {
            $this->abandonOperation('pot-withdraw', Lang::get('pots::messages.errors.pot_missing'));
            $refused = true;
        } catch (\InvalidArgumentException) {
            // Nothing else reaches here: withdraw() distinguishes every refusal
            // it has. A backstop with no cause to name says only what it knows.
            $this->toast(Lang::get('pots::messages.errors.operation_failed'));
            $refused = true;
        }

        if ($refused) {
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

        // The select's placeholder is '', which casts to pot id 0. Left to the
        // writer that reads as a pot that vanished, and nobody had picked one.
        if (trim($this->transferTargetPotId) === '') {
            $this->errorTarget = Lang::get('pots::messages.errors.select_target_pot');

            return;
        }

        $memo = trim($this->operationMemo) !== '' ? $this->operationMemo : null;

        $user = $currentUser->user();
        $rows = $query->forUser($user);
        $sourcePot = PotRow::withId($rows, $this->operationPotId);
        $targetPot = PotRow::withId($rows, (int) $this->transferTargetPotId);

        $refused = false;

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
            $this->errorAmountLimitCurrency = $currency;
            $availableFormatted = Money::ofMinor(
                $this->errorAmountLimitMinor,
                $currency
            )->format();
            $this->errorAmount = Lang::get(
                'pots::messages.errors.amount_exceeds_pot_balance',
                ['name' => $potName, 'amount' => $availableFormatted],
            );
            $refused = true;
        } catch (InvalidPotAmountException) {
            $this->errorAmount = Lang::get('pots::messages.errors.amount_invalid');
            $refused = true;
        } catch (SelfTransferException) {
            $this->errorTarget = Lang::get('pots::messages.errors.move_same_pot');
            $refused = true;
        } catch (CrossAccountTransferException) {
            // Every cross-currency move is one of these, and the reader was
            // sent back to fields that were all correct.
            $this->errorTarget = $targetPot instanceof PotRow
                ? Lang::get('pots::messages.errors.move_cross_account', [
                    'name' => $targetPot->name,
                    'account' => $targetPot->accountName,
                ])
                : Lang::get('pots::messages.errors.operation_failed');
            $refused = true;
        } catch (TargetPotNotFoundException) {
            // Above its parent: the source pot is the card the reader opened
            // and the target is the one they picked, and only one can be fixed
            // from this sheet.
            $this->errorTarget = Lang::get('pots::messages.errors.move_target_missing');
            $refused = true;
        } catch (PotNotFoundException) {
            $this->abandonOperation('pot-move', Lang::get('pots::messages.errors.pot_missing'));
            $refused = true;
        }

        if ($refused) {
            return;
        }

        $this->resetOperationModal();
        $this->dispatch('modal-close', name: 'pot-move');
        $this->toast(Lang::get('pots::messages.toast.funds_moved'));
    }

    public function confirmArchive(CurrentUser $currentUser, int|string $potId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $potId = DerivedRowId::fromWire($potId);

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

    public function restorePot(CurrentUser $currentUser, PotWriter $writer, int|string $potId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $potId = DerivedRowId::fromWire($potId);

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

            $view->extends('layouts.app', ['title' => Lang::get('pots::messages.page_title')]);

            return $view;
        }

        $user = $currentUser->user();

        $activePots = $query->forUser($user);

        $groups = [];
        foreach ($activePots as $pot) {
            $groups[$pot->accountId][] = $pot;
        }

        // One row per currency the account denominates or holds pots in, so a
        // relabelled account reconciles all of them instead of printing one
        // line's figure under another line's sign.
        $reconciliations = [];
        foreach (array_keys($groups) as $accountId) {
            $reconciliations[$accountId] = $query->reconciliationsForAccount($accountId, $user);
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

        $view->extends('layouts.app', ['title' => Lang::get('pots::messages.page_title')]);

        return $view;
    }

    // The pot the reader is operating on is gone, so the sheet is about
    // nothing: it closes rather than standing open over a ghost. Which of "no
    // such pot" and "not yours" it was stays unsaid — see PotNotFoundException.
    private function abandonOperation(string $modal, string $message): void
    {
        $this->resetOperationModal();
        $this->dispatch('modal-close', name: $modal);
        $this->toast($message);
    }

    private function clearErrors(): void
    {
        $this->errorName = '';
        $this->errorTarget = '';
        $this->clearAmountError();
    }

    private function clearAmountError(): void
    {
        $this->errorAmount = '';
        $this->errorAmountLimitMinor = null;
        $this->errorAmountLimitCurrency = '';
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
