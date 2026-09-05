<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Public\Queries\CounterpartyDisplayName;
use Modules\Goals\Public\Services\GoalContributionQuery;
use Modules\Goals\Public\Services\GoalContributionWriter;
use Modules\Ledger\Internal\Http\Livewire\Concerns\ManagesSplitEditor;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\DeletesTransaction;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Http\Livewire\Concerns\HandlesClearedStatus;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Ledger\Public\Services\ReconciliationWriter;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Public\Transport\SensitiveTextBudget;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TransactionDetail extends Component
{
    private const string RECONCILED_NOTICE_KEY = 'ledger::detail.toast.reconciled_locked';

    use DispatchesToast;
    use HandlesClearedStatus;
    use HandlesTaxTagging;
    use ManagesSplitEditor;

    public int $transactionId = 0;

    // Reset to '' after a successful reclassify so the dropdown returns to
    // its placeholder and the applied value leaves the option list.
    public string $reclassifyType = '';

    public string $note = '';

    public bool $noteSaved = false;

    public bool $confirmingUnreconcile = false;

    public function mount(int $transactionId, CurrentUser $currentUser, DatabaseManager $db, TaxTagQuery $taxTagQuery, SensitiveColumnCodec $codec, Session $session, BaseCurrency $baseCurrency): void
    {
        $this->transactionId = $transactionId;
        $userId = $currentUser->user()->id;

        // Query Builder, not Transaction::query()->exists(): PHPStan
        // strict-rules rejects that magic forward as staticMethod.dynamicCall.
        $row = $db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->first(['id', 'note']);

        if ($row === null) {
            throw new NotFoundHttpException(sprintf(
                'Transaction %d not found.',
                $transactionId,
            ));
        }

        $this->note = is_string($row->note)
            ? $codec->decryptValue('transactions', 'note', $row->note, $userId, $session)['value']
            : '';

        $this->loadSplitState($currentUser, $db, $taxTagQuery, $codec, $session, $baseCurrency->forUser($currentUser->user()));
    }

    public function reclassify(
        string $newType,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        SavesTransactionSplit $splitter,
    ): void {
        $type = TransactionType::tryFrom($newType);
        if ($type === null) {
            // Only a payload can name a type the picker never offers, and the
            // refusal names the shape rather than the value: an HttpException
            // message is the whole body a production build returns.
            throw new BadRequestHttpException('Call names a transaction type that does not exist.');
        }

        $user = $currentUser->user();

        /** @var Transaction $tx */
        $tx = Transaction::query()
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (TransactionStatusQuery::locksEdits($tx->status)) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        $didUnsplit = $this->collapseSplitLeavingTheSplittableSet($type, $db, $splitter, $user);

        $partnerId = $tx->pair_transaction_id;
        $breaksPair = $partnerId !== null && ! $type->isTransfer();

        $db->connection()->transaction(static function () use ($tx, $type, $partnerId, $user, $breaksPair): void {
            $tx->type = $type->value;
            if (! $type->isTransfer()) {
                $tx->pair_transaction_id = null;
            }
            $tx->save();

            if ($breaksPair) {
                Transaction::query()
                    ->where('user_id', $user->id)
                    ->where('id', $partnerId)
                    ->update(['pair_transaction_id' => null]);
            }
        });

        // Both halves of the break are announced. TransferPairCascade unpicks a
        // pair only behind a TOMBSTONE, and nothing is deleted here — so a peer
        // told only about the type kept two rows naming each other, one of them
        // no longer a transfer at all.
        $events->dispatch(new TransactionMutated(
            transactionId: $this->transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: $breaksPair ? ['type' => $newType, 'pair_transaction_id' => null] : ['type' => $newType],
        ));

        if ($breaksPair) {
            $events->dispatch(new TransactionMutated(
                transactionId: $partnerId,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: ['pair_transaction_id' => null],
            ));
        }

        $message = $breaksPair
            ? Lang::get('ledger::detail.toast.reclassified_pair_removed', ['type' => $newType])
            : Lang::get('ledger::detail.toast.reclassified', ['type' => $newType]);

        $this->toast($message);

        $this->reclassifyType = '';

        if ($didUnsplit) {
            $this->resetSplitEditor();
        }
    }

    // Collapse the split before the type leaves the splittable set, or the legs
    // are stranded on a transaction that can no longer show them.
    /**
     * @return bool Whether legs were actually collapsed, so the editor is reset.
     */
    private function collapseSplitLeavingTheSplittableSet(
        TransactionType $type,
        DatabaseManager $db,
        SavesTransactionSplit $splitter,
        User $user,
    ): bool {
        if ($type->isSplittable()) {
            return false;
        }

        $firstLeg = $db->connection()
            ->table('transaction_splits')
            ->where('transaction_id', $this->transactionId)
            ->where('user_id', $user->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first(['category_id']);

        if ($firstLeg === null) {
            return false;
        }

        $splitter->unsplit(
            $user,
            $this->transactionId,
            is_numeric($firstLeg->category_id) ? (int) $firstLeg->category_id : 0,
        );

        return true;
    }

    public function saveNote(
        CurrentUser $currentUser,
        Dispatcher $events,
        SetsTransactionNote $setNote,
        FieldProvenanceWriter $provenance,
        TransactionStatusQuery $statusQuery,
    ): void {
        $user = $currentUser->user();

        // Pre-checked here rather than only inside the action, so the user
        // gets the "un-reconcile first" toast instead of a silent no-op.
        if ($statusQuery->isReconciled($user->id, $this->transactionId)) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        $trimmed = trim($this->note);

        // `note` is a sealed column, and a sealed value past the transport's
        // text budget is written locally and then withheld from every peer for
        // as long as it exists — a sync that looks clean and is not. Refused
        // here so the ceiling is a sentence rather than a line in a log.
        if (mb_strlen($trimmed) > SensitiveTextBudget::MAX_PLAINTEXT_CHARACTERS) {
            $this->toast(Lang::choice(
                'ledger::detail.toast.note_too_long',
                SensitiveTextBudget::MAX_PLAINTEXT_CHARACTERS,
                ['max' => Fmt::number(SensitiveTextBudget::MAX_PLAINTEXT_CHARACTERS)],
            ));

            return;
        }

        $value = $trimmed === '' ? null : $trimmed;

        $affected = ($setNote)($this->transactionId, $value, 'set', $user);

        if ($affected > 0) {
            $events->dispatch(new TransactionMutated(
                transactionId: $this->transactionId,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: ['note' => $value],
            ));

            $provenance->stamp($user->id, $this->transactionId, ['note' => 'manual']);
        }

        $this->noteSaved = true;
        $this->toast(Lang::get('ledger::detail.toast.note_saved'));
    }

    public function startUnreconcile(): void
    {
        $this->confirmingUnreconcile = true;
    }

    public function cancelUnreconcile(): void
    {
        $this->confirmingUnreconcile = false;
    }

    // The escape hatch every reconciled-lock toast on this page points to. A
    // foreign, missing or already non-reconciled id is a silent no-op.
    public function unreconcile(
        ReconciliationWriter $writer,
        CurrentUser $currentUser,
    ): void {
        $this->confirmingUnreconcile = false;

        $writer->unreconcile($currentUser->user(), $this->transactionId);

        $this->toast(Lang::get('ledger::detail.toast.unreconciled'));
    }

    public function reassignCounterparty(
        int $newCounterpartyId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        ReassignsCounterparty $reassign,
        FieldProvenanceWriter $provenance,
    ): void {
        $user = $currentUser->user();

        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === null) {
            throw new NotFoundHttpException;
        }

        if (TransactionStatusQuery::locksEdits($status)) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        $affected = ($reassign)($this->transactionId, $newCounterpartyId, $user);

        if ($affected === 0) {
            return;
        }

        $events->dispatch(new TransactionMutated(
            transactionId: $this->transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['counterparty_id' => $newCounterpartyId],
        ));

        $provenance->stamp($user->id, $this->transactionId, ['counterparty_id' => 'manual']);

        $this->toast(Lang::get('ledger::detail.toast.counterparty_updated'));
    }

    // Deliberately NOT behind the reconciled lock the sibling mutators use:
    // an attribution is a separate row that leaves the transaction untouched,
    // and a reconciled row is exactly the confirmed money a goal wants.
    public function attributeToGoal(
        int $goalId,
        CurrentUser $currentUser,
        GoalContributionWriter $contributions,
    ): void {
        if (! $contributions->attribute($currentUser->user(), $goalId, $this->transactionId)) {
            return;
        }

        $this->toast(Lang::get('ledger::detail.toast.goal_attributed'));
    }

    // Quoted on the way out and read back here: a goal id is minted, not taken
    // from the autoincrement, so it runs past 2^53 and a number literal is
    // rounded by the browser before the server ever sees it.
    public function removeGoalAttribution(
        int|string $goalId,
        CurrentUser $currentUser,
        GoalContributionWriter $contributions,
    ): void {
        if (! $contributions->detach($currentUser->user(), DerivedRowId::fromWire($goalId), $this->transactionId)) {
            return;
        }

        $this->toast(Lang::get('ledger::detail.toast.goal_attribution_removed'));
    }

    public function deleteTransaction(
        CurrentUser $currentUser,
        DatabaseManager $db,
        UrlGenerator $urls,
        DeletesTransaction $deleter,
    ): void {
        $user = $currentUser->user();

        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === null) {
            throw new NotFoundHttpException;
        }

        // Pre-checked here rather than only inside the action, so the user gets
        // the "un-reconcile first" toast instead of a silent no-op.
        if (TransactionStatusQuery::locksEdits($status)) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        if (! $deleter->delete($user, $this->transactionId)) {
            return;
        }

        $this->redirect(Destination::Transactions->urlFrom($urls), navigate: true);
    }

    public function updated(string $name, mixed $value, CurrentUser $currentUser, DatabaseManager $db, BaseCurrency $baseCurrency): void
    {
        if (str_starts_with($name, 'legs.') && str_ends_with($name, '.amount')) {
            $this->recomputeRemaining($currentUser, $db, $baseCurrency->forUser($currentUser->user()));
        }
    }

    public function render(
        CurrentUser $currentUser,
        ViewFactory $views,
        ChainLinkQuery $chainQuery,
        TaxTagQuery $taxTagQuery,
        DatabaseManager $db,
        CategoryOptionsQuery $categoryOptions,
        GoalContributionQuery $goalContributions,
        SensitiveColumnCodec $codec,
        Session $session,
        CounterpartyDisplayName $counterpartyNames,
    ): View {
        $userId = $currentUser->user()->id;

        // counterparty is null for pre-resolver history; the Blade falls
        // back to the plain-text name.
        $transaction = Transaction::query()
            ->with(['counterparty'])
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->firstOrFail();

        // Decrypted onto the in-memory attribute only: nothing on the render
        // path saves this model, so the plaintext never reaches the column.
        // Both columns are in SensitiveFieldRegistry, so a raw read here would
        // put base64 on the card.
        if (is_string($transaction->counterparty_name)) {
            $transaction->counterparty_name = $codec->decryptValue(
                'transactions',
                'counterparty_name',
                $transaction->counterparty_name,
                $userId,
                $session,
            )['value'];
        }

        if (is_string($transaction->description)) {
            $transaction->description = $codec->decryptValue(
                'transactions',
                'description',
                $transaction->description,
                $userId,
                $session,
            )['value'];
        }

        // Named off the same list the leg pickers below it offer, not off a
        // read of its own row: two categories can share a qualified path, and
        // the list is what decides which of them carries the ordinal. A private
        // read spelled it one way and the picker under it another.
        $visibleCategories = $categoryOptions->for($currentUser->user());

        $currentCategoryName = null;
        foreach ($visibleCategories as $option) {
            if ($option->id === $transaction->category_id) {
                $currentCategoryName = $option->path;

                break;
            }
        }

        $isSplittable = (TransactionType::tryFrom($transaction->type)?->isSplittable() === true);
        $splitCategories = $isSplittable ? $visibleCategories : [];

        // Gates the "View chain" button, so a row with no chain_link rows
        // does not offer a button that opens an empty drawer.
        $chainAvailable = $chainQuery->hasChainForTransaction(
            $this->transactionId,
            $currentUser->user(),
        );

        $taxState = $this->taxTagStateFor([$this->transactionId], $taxTagQuery, $currentUser);
        $txTaxRow = [
            'id' => $this->transactionId,
            'taxTagged' => $taxState[$this->transactionId]['taxTagged'] ?? false,
            'taxCategoryShortName' => $taxState[$this->transactionId]['taxCategoryShortName'] ?? null,
        ];

        $clearedState = $this->clearedStatusFor([$this->transactionId], $db, $currentUser);
        $clearedStatus = $clearedState[$this->transactionId] ?? ClearedStatus::Cleared->value;

        $view = $views->make('ledger::livewire.transaction-detail', [
            'transaction' => $transaction,
            'chainAvailable' => $chainAvailable,
            'txTaxRow' => $txTaxRow,
            'clearedStatus' => $clearedStatus,
            'counterparties' => $counterpartyNames->forUser($userId),
            'currentCategoryName' => $currentCategoryName,
            'isSplittable' => $isSplittable,
            'splitCategories' => $splitCategories,
            'goalOptions' => $goalContributions->attributableGoals($currentUser->user()),
            'attributedGoals' => $goalContributions->forTransaction($currentUser->user(), $this->transactionId),
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('ledger::detail.page_title').Brand::TITLE_SUFFIX]);

        return $view;
    }
}
