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
 * `/pots` page — list savings pots grouped by account with per-account
 * reconciliation headers (real · allocated · unallocated), negative-unallocated
 * amber warning (D-02/D-15), and Flux modals for create/edit/fund/move/withdraw/
 * archive/restore (D-17). Inline movement history expansion per card (D-17).
 *
 * Method-parameter DI on every action and on render/mount — constructor
 * injection is banned on Livewire Component subclasses by phpstan-strict-rules
 * (same pattern as GoalsPage and BudgetsPage).
 *
 * State:
 *   - `name`, `amount`, `accountId`, `linkType`, `goalId`, `editPotId` — form
 *     fields for the create/edit modal. Category-linking is retired (D-15);
 *     `linkType` is now 'goal' | 'none' only — `PotWriter` always receives a
 *     null `$categoryId`.
 *   - `operationPotId`, `operationAmount`, `operationMemo`, `operationKind`,
 *     `transferTargetPotId` — fund/move/withdraw operation modal state.
 *   - `archivingPotId` — non-zero triggers the in-card micro-confirm strip.
 *   - `errorName`, `errorAmount` — inline per-field validation banners.
 *   - `showArchived` — controls the "Archived pots (N)" disclosure.
 */
final class PotsPage extends Component
{
    // Create / edit form state
    public string $name = '';

    public string $amount = '';

    public string $accountId = '';

    /** 'goal' | 'none' — category-linking retired (D-15) */
    public string $linkType = 'none';

    public string $goalId = '';

    public int $editPotId = 0;

    // Operation modal state (fund / withdraw / transfer)
    public int $operationPotId = 0;

    public string $operationAmount = '';

    public string $operationMemo = '';

    /** 'fund' | 'withdraw' | 'transfer' */
    public string $operationKind = '';

    /**
     * String-typed because it backs a <select> whose placeholder option is ''
     * — hydrating '' into an int property throws a Livewire property-type
     * error instead of a validation message (WR-09; same pattern as
     * $accountId here and on GoalsPage). Cast in movePot().
     */
    public string $transferTargetPotId = '';

    // Inline archive confirm
    public int $archivingPotId = 0;

    // Error fields
    public string $errorName = '';

    public string $errorAmount = '';

    public bool $showArchived = false;

    public function mount(CurrentUser $currentUser): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
    }

    // -----------------------------------------------------------------------
    // Create pot
    // -----------------------------------------------------------------------

    /**
     * Create a new pot from the modal form. Maps PotWriter exceptions to
     * per-field inline errors. On success closes modal and dispatches a toast.
     */
    public function createPot(CurrentUser $currentUser, PotWriter $writer): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        if (trim($this->name) === '') {
            $this->errorName = 'Enter a name for this pot.';

            return;
        }

        $accountId = $this->accountId !== '' ? (int) $this->accountId : 0;
        if ($accountId === 0) {
            $this->errorName = 'Select an account for this pot.';

            return;
        }

        // D-15: category-linking retired — linkType is 'goal' | 'none' only,
        // so PotWriter always receives a null categoryId here.
        $goalId = null;
        if ($this->linkType === 'goal' && $this->goalId !== '') {
            $goalId = (int) $this->goalId;
        }

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
        } catch (InsufficientUnallocatedException) {
            $this->errorAmount = 'Amount exceeds unallocated balance.';

            return;
        } catch (\InvalidArgumentException $e) {
            $this->errorName = $e->getMessage();

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
        $this->toast('Pot created.');
    }

    // -----------------------------------------------------------------------
    // Edit / update pot
    // -----------------------------------------------------------------------

    /**
     * Populate the form with an existing pot's values and open the edit modal.
     * Called from the "Edit" item in the pot card kebab menu.
     */
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
                    // D-15: category-linking retired. Any pre-cutover active
                    // pot with a lingering category_id (should not exist post-
                    // cutover) falls back to 'none' rather than surfacing a
                    // picker that no longer exists.
                    $this->linkType = 'none';
                    $this->goalId = '';
                }
                $this->clearErrors();
                $this->dispatch('modal-show', name: 'pot-form');

                return;
            }
        }
    }

    /**
     * Update an existing pot's name and link. Delegates cross-user rejection
     * to PotWriter (which throws PotNotFoundException when not owned).
     */
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

        // D-15: category-linking retired — linkType is 'goal' | 'none' only,
        // so PotWriter always receives a null categoryId here.
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
        } catch (PotNotFoundException) {
            // Cross-user / missing pot — silently ignore
            $this->resetForm();

            return;
        } catch (\InvalidArgumentException $e) {
            $this->errorName = $e->getMessage();

            return;
        }

        $this->resetForm();
        $this->dispatch('modal-close', name: 'pot-form');
        $this->toast('Pot updated.');
    }

    // -----------------------------------------------------------------------
    // Fund pot
    // -----------------------------------------------------------------------

    /**
     * Fund a pot from the fund modal. Maps InsufficientUnallocatedException to
     * an inline error with the over-allocation copy from 03-UI-SPEC.
     */
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
            // Compute unallocated for the error message
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

    /**
     * Withdraw from a pot. Maps InsufficientUnallocatedException (over-source)
     * to an inline error with the over-source copy from 03-UI-SPEC.
     */
    public function withdrawPot(CurrentUser $currentUser, PotWriter $writer, PotBalanceQuery $query): void
    {
        $this->clearErrors();

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $memo = trim($this->operationMemo) !== '' ? $this->operationMemo : null;

        // Get pot name for error message before attempting write
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

    /**
     * Transfer funds between two pots in the same account.
     */
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
            // PotNotFoundException → inline error (WR-09).
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

    /**
     * Show the in-card micro-confirm for archiving a pot. Actual archive
     * happens in `archivePot()`.
     */
    public function confirmArchive(CurrentUser $currentUser, int $potId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $this->archivingPotId = $potId;
    }

    /**
     * Cancel the archive micro-confirm.
     */
    public function cancelArchive(CurrentUser $currentUser): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $this->archivingPotId = 0;
    }

    /**
     * Archive a pot after micro-confirm. Dispatches a "Pot archived. [Restore]"
     * toast with a one-tap undo action (mirrors GoalsPage::archive() D-09).
     */
    public function archivePot(CurrentUser $currentUser, PotWriter $writer, int $potId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->archive($currentUser->user(), $potId);
        $this->archivingPotId = 0;
        $this->dispatch('toast', message: 'Pot archived.', undoAction: 'restore', undoPayload: $potId);
    }

    /**
     * Restore an archived pot back to active status.
     */
    public function restorePot(CurrentUser $currentUser, PotWriter $writer, int $potId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->restore($currentUser->user(), $potId);
        $this->toast('Pot restored.');
    }

    /**
     * Cancel / close the create-or-edit modal, resetting all form state.
     */
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
        // Defence-in-depth: the `auth` route middleware makes this unreachable,
        // but guard anyway so an unauthenticated render degrades gracefully (IN-03).
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

        // Active pots: flat list ordered by account name then pot name
        $activePots = $query->forUser($user);

        // Group by accountId — preserves ordering from forUser()
        $groups = [];
        foreach ($activePots as $pot) {
            $groups[$pot->accountId][] = $pot;
        }

        // Per-account reconciliation rows (only for accounts that have active pots)
        $reconciliations = [];
        foreach (array_keys($groups) as $accountId) {
            $reconciliations[$accountId] = $query->reconciliationForAccount($accountId, $user);
        }

        $archived = $query->archivedForUser($user);

        // User's accounts for account picker (name order)
        $accounts = $db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'default_currency'])
            ->toArray();

        // Goals not already pot-linked (for the create/edit link picker)
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
            // When editing, exclude goals linked to OTHER pots (allow the current pot's goal).
            // editPotId is client-controlled — scope to the user like every other
            // read on user-owned tables (T-03-07 / WR-06).
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
            // two view variables (IN-04).
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
