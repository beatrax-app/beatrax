<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Categorization\Public\Actions\AssignCategory;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Events\CategorizationDiverged;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Public\Queries\CounterpartyDisplayName;
use Modules\Goals\Public\Services\GoalContributionQuery;
use Modules\Goals\Public\Services\GoalContributionWriter;
use Modules\Ledger\Internal\Http\Livewire\Concerns\ManagesSplitEditor;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Http\Livewire\Concerns\HandlesClearedStatus;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Ledger\Public\Services\ReconciliationWriter;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TransactionDetail extends Component
{
    private const RECONCILED_NOTICE_KEY = 'ledger::detail.toast.reconciled_locked';

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
        if (TransactionType::tryFrom($newType) === null) {
            throw new InvalidArgumentException(sprintf(
                "Invalid transaction type: '%s'",
                $newType,
            ));
        }

        $user = $currentUser->user();

        /** @var Transaction $tx */
        $tx = Transaction::query()
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($tx->status === ClearedStatus::Reconciled->value) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        // Collapse the split before the type leaves the splittable set, or
        // the legs are stranded on a transaction that can no longer show them.
        $didUnsplit = false;
        if (! TransactionType::tryFrom($newType)->isSplittable()) {
            $firstLeg = $db->connection()
                ->table('transaction_splits')
                ->where('transaction_id', $this->transactionId)
                ->where('user_id', $user->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first(['category_id']);

            if ($firstLeg !== null) {
                $survivingCategoryId = is_numeric($firstLeg->category_id) ? (int) $firstLeg->category_id : 0;
                $splitter->unsplit($user, $this->transactionId, $survivingCategoryId);
                $didUnsplit = true;
            }
        }

        $partnerId = $tx->pair_transaction_id;
        $breaksPair = $partnerId !== null
            && ! in_array($newType, TransactionType::transferValues(), true);

        $db->connection()->transaction(static function () use ($tx, $newType, $partnerId, $user, $breaksPair): void {
            $tx->type = $newType;
            if (! in_array($newType, TransactionType::transferValues(), true)) {
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

        // The partner's pair_transaction_id NULL-ing is deliberately absent
        // from dirtyFields — the merge engine cascades it as an FK side-effect.
        $events->dispatch(new TransactionMutated(
            transactionId: $this->transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['type' => $newType],
        ));

        $message = $breaksPair
            ? Lang::get('ledger::detail.toast.reclassified_pair_removed', ['type' => $newType])
            : Lang::get('ledger::detail.toast.reclassified', ['type' => $newType]);

        $this->toast($message);

        $this->reclassifyType = '';

        if ($didUnsplit) {
            $this->resetSplitEditor();
        }
    }

    // AssignsCategory scopes the UPDATE by user_id, so a foreign-user
    // transaction affects 0 rows and no divergence event fires.
    public function reclassifyCategory(
        int $newCategoryId,
        CurrentUser $currentUser,
        AssignsCategory $assign,
        DatabaseManager $db,
    ): void {
        $user = $currentUser->user();

        // Invokable straight from the browser, so the reconciled lock has to
        // be re-checked here and not only in the sibling mutators.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === ClearedStatus::Reconciled->value) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        // Both this dispatch and AssignCategory's framework-event dispatch go
        // through fromProvenance(), so the two channels cannot disagree about
        // what counts as a divergence.
        $priorProvenance = AssignCategory::readPriorProvenance($db, $this->transactionId, $user->id);

        $affected = ($assign)($this->transactionId, $newCategoryId, $user);
        if ($affected === 0) {
            return;
        }

        $divergence = CategorizationDiverged::fromProvenance(
            priorProvenance: $priorProvenance,
            transactionId: $this->transactionId,
            newCategoryId: $newCategoryId,
            userId: $user->id,
        );
        if ($divergence === null) {
            return;
        }

        $this->dispatch(
            'correction-divergence:fire',
            transactionId: $divergence->transactionId,
            ruleId: $divergence->ruleId,
            oldCategoryId: $divergence->oldCategoryId,
            newCategoryId: $divergence->newCategoryId,
            userId: $divergence->userId,
        );
    }

    public function saveNote(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        SetsTransactionNote $setNote,
        FieldProvenanceWriter $provenance,
    ): void {
        $user = $currentUser->user();

        // Pre-checked here rather than only inside the action, so the user
        // gets the "un-reconcile first" toast instead of a silent no-op.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === ClearedStatus::Reconciled->value) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        $trimmed = trim($this->note);
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

    public function toggleCleared(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        Clock $clock,
    ): void {
        $this->toggleClearedStatus($this->transactionId, $currentUser, $db, $events, $clock);
    }

    // The escape hatch every reconciled-lock toast on this page points to. A
    // foreign, missing or already non-reconciled id is a silent no-op.
    public function unreconcile(
        ReconciliationWriter $writer,
        CurrentUser $currentUser,
    ): void {
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

        if ($status === ClearedStatus::Reconciled->value) {
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

    public function removeGoalAttribution(
        int $goalId,
        CurrentUser $currentUser,
        GoalContributionWriter $contributions,
    ): void {
        if (! $contributions->detach($currentUser->user(), $goalId, $this->transactionId)) {
            return;
        }

        $this->toast(Lang::get('ledger::detail.toast.goal_attribution_removed'));
    }

    public function deleteTransaction(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        UrlGenerator $urls,
    ): void {
        $userId = $currentUser->user()->id;

        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->value('status');

        if ($status === null) {
            throw new NotFoundHttpException;
        }

        if ($status === ClearedStatus::Reconciled->value) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        // Read the leg ids before the parent delete cascades them away:
        // convergence cannot assume the peer's replay connection has FK
        // cascade on, so each leg needs its own tombstone below.
        $legRows = $db->connection()
            ->table('transaction_splits')
            ->where('transaction_id', $this->transactionId)
            ->where('user_id', $userId)
            ->get(['id']);

        $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->delete();

        $events->dispatch(new TransactionMutated(
            transactionId: $this->transactionId,
            userId: $userId,
            mutationType: 'delete',
            dirtyFields: [],
        ));

        foreach ($legRows as $legRow) {
            $legId = is_numeric($legRow->id) ? (int) $legRow->id : 0;
            $events->dispatch(new TransactionSplitMutated(
                splitId: $legId,
                transactionId: $this->transactionId,
                userId: $userId,
                mutationType: 'delete',
            ));
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

        // Read past the Category model, not through it: the shipped tree is
        // seeded with user_id = NULL and the model carries BelongsToUser, so
        // its relation answers null for every default category. The seam is
        // where every other category read in the app already goes.
        $categoryRow = $transaction->category_id === null
            ? null
            : $db->connection()->table('categories')
                ->where('id', $transaction->category_id)
                ->first(CategoryDisplayName::bareColumns());

        $currentCategoryName = $categoryRow === null
            ? null
            : CategoryDisplayName::fromRow($categoryRow);

        $isSplittable = (TransactionType::tryFrom($transaction->type)?->isSplittable() === true);
        $splitCategories = $isSplittable ? $categoryOptions->for($currentUser->user()) : [];

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

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('ledger::detail.page_title').' · Beatrax']);

        return $view;
    }
}
