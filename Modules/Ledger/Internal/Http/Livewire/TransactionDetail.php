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
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Internal\Http\Livewire\Concerns\ManagesSplitEditor;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Http\Livewire\Concerns\HandlesClearedStatus;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Ledger\Public\Services\ReconciliationWriter;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// DI-only: no constructor. Service collaborators arrive as parameters
// on mount()/render()/action methods — auth()/Auth::user()/facade
// lookups are out of bounds project-wide.
/**
 * @link ../../../../../.docs/features/ledger/architecture.md
 */
final class TransactionDetail extends Component
{
    // The one translation key every reconciled-row guard shows, so the
    // four guards cannot drift apart in wording.
    private const RECONCILED_NOTICE_KEY = 'ledger::detail.toast.reconciled_locked';

    use HandlesClearedStatus;
    use HandlesTaxTagging;
    use ManagesSplitEditor;

    public int $transactionId = 0;

    // Reset to '' after every successful reclassify so the dropdown
    // returns to "Choose a type..." and the just-applied value is
    // hidden from the option list.
    public string $reclassifyType = '';

    public string $note = '';

    public bool $noteSaved = false;

    public function mount(int $transactionId, CurrentUser $currentUser, DatabaseManager $db, TaxTagQuery $taxTagQuery, SensitiveColumnCodec $codec, Session $session): void
    {
        $this->transactionId = $transactionId;
        $userId = $currentUser->user()->id;

        // Raw Query Builder used instead of Eloquent's
        // Transaction::query()->exists() to clear PHPStan strict-rules
        // staticMethod.dynamicCall — Eloquent's exists() is a magic
        // forward over Builder's instance method, rejected on a fresh query.
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

        // Read-side decrypt — the note projection column is ciphertext
        // at rest once encryption is enabled; pass-through no-op otherwise.
        $this->note = is_string($row->note)
            ? $codec->decryptValue('transactions', 'note', $row->note, $userId, $session)['value']
            : '';

        $this->loadSplitState($currentUser, $db, $taxTagQuery, $codec, $session);
    }

    // Allow-listed via TransactionType — any other value raises
    // InvalidArgumentException before any DB read. Same-user scoping is
    // enforced by firstOrFail() on a query filtered by user_id; a
    // cross-user invocation raises NotFoundHttpException (404).
    /**
     * @link ../../../../../.docs/features/ledger/architecture.md
     */
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

        // Reconciled lock: warn-first, no write. Reads the already
        // user-scoped $tx just loaded above — no extra query needed.
        if ($tx->status === ClearedStatus::Reconciled->value) {
            $this->dispatch('toast', message: Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        // Reclassifying a split transaction to a non-splittable type
        // would strand its legs (see the linked architecture page) —
        // collapse the split first, through the sole mutator, before
        // the type leaves the splittable set.
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
                // Symmetric break — partner's pair_transaction_id is
                // cleared atomically in the same transaction. The
                // partner's own type is preserved; reclassify never
                // re-types the partner.
                Transaction::query()
                    ->where('user_id', $user->id)
                    ->where('id', $partnerId)
                    ->update(['pair_transaction_id' => null]);
            }
        });

        // Type reclassify enters the op-log as a per-field Set op. The
        // pair partner's pair_transaction_id NULL-ing is a structural
        // FK side-effect handled by the merge engine's cascade.
        $events->dispatch(new TransactionMutated(
            transactionId: $this->transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['type' => $newType],
        ));

        $message = $breaksPair
            ? Lang::get('ledger::detail.toast.reclassified_pair_removed', ['type' => $newType])
            : Lang::get('ledger::detail.toast.reclassified', ['type' => $newType]);

        $this->dispatch('toast', message: $message);

        $this->reclassifyType = '';

        // If the reclassify collapsed a split, drop the now-stale
        // in-memory leg state so the component no longer reports
        // hasPersistedSplit for a transaction that no longer has legs.
        if ($didUnsplit) {
            $this->resetSplitEditor();
        }
    }

    // See the linked architecture page for the correction-divergence
    // bridge. Cross-user safety: AssignsCategory scopes the UPDATE by
    // user_id; a foreign-user transaction returns 0 rows affected and
    // no event fires.
    /**
     * @link ../../../../../.docs/features/ledger/architecture.md
     */
    public function reclassifyCategory(
        int $newCategoryId,
        CurrentUser $currentUser,
        AssignsCategory $assign,
        DatabaseManager $db,
    ): void {
        $user = $currentUser->user();

        // Reconciled lock: warn-first, no write. This is a public
        // Livewire method invokable directly from the browser, so it
        // must enforce the same "un-reconcile first" contract as the
        // sibling mutators; the status read is scoped by user_id.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === ClearedStatus::Reconciled->value) {
            $this->dispatch('toast', message: Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        // Single canonical divergence detector: both this Livewire
        // dispatch and AssignCategory's framework-event dispatch route
        // through CategorizationDiverged::fromProvenance so the two
        // channels stay in lockstep on any provenance shape change.
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

    // Empty/blank input is normalised to NULL in the DB. Delegates the
    // write to Ledger's SetsTransactionNote Public action; a
    // TransactionMutated(edit) event is dispatched so the Sync capture
    // listener records it.
    public function saveNote(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        SetsTransactionNote $setNote,
        FieldProvenanceWriter $provenance,
    ): void {
        $user = $currentUser->user();

        // Reconciled lock: warn-first, no write. Pre-checked here
        // (rather than only inside the action) so this method can show
        // the specific "un-reconcile first" toast instead of a generic
        // no-op.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === ClearedStatus::Reconciled->value) {
            $this->dispatch('toast', message: Lang::get(self::RECONCILED_NOTICE_KEY));

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

            // This is the sole user-driven note write path, so it owns
            // stamping manual provenance.
            $provenance->stamp($user->id, $this->transactionId, ['note' => 'manual']);
        }

        $this->noteSaved = true;
        $this->dispatch('toast', message: Lang::get('ledger::detail.toast.note_saved'));
    }

    // Delegates to HandlesClearedStatus::toggleClearedStatus(), which
    // owns the warn-first-on-reconciled guard, the cleared<->uncleared
    // flip, and the dispatch-after-commit TransactionMutated event.
    public function toggleCleared(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        Clock $clock,
    ): void {
        $this->toggleClearedStatus($this->transactionId, $currentUser, $db, $events, $clock);
    }

    // The escape hatch every reconciled-lock warn toast on this page
    // points to. A foreign, missing, or already non-reconciled
    // transaction id is a silent no-op, mirroring
    // ReconciliationWriter::unreconcile()'s own cross-user handling.
    public function unreconcile(
        ReconciliationWriter $writer,
        CurrentUser $currentUser,
    ): void {
        $writer->unreconcile($currentUser->user(), $this->transactionId);

        $this->dispatch('toast', message: Lang::get('ledger::detail.toast.unreconciled'));
    }

    // Delegates the write to Ledger's ReassignsCounterparty Public
    // action. Cross-user counterparty, an unchanged value, or a
    // reconciled row all resolve to a silent no-op. This is the sole
    // user-driven counterparty_id write path.
    public function reassignCounterparty(
        int $newCounterpartyId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        ReassignsCounterparty $reassign,
        FieldProvenanceWriter $provenance,
    ): void {
        $user = $currentUser->user();

        // Ownership-check the transaction and read its status in the
        // same scoped query for the reconciled lock check below.
        // Pre-checked here so this method can show the specific
        // "un-reconcile first" toast instead of a generic no-op.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === null) {
            throw new NotFoundHttpException;
        }

        if ($status === ClearedStatus::Reconciled->value) {
            $this->dispatch('toast', message: Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        $affected = ($reassign)($this->transactionId, $newCounterpartyId, $user);

        if ($affected === 0) {
            // Cross-user/unknown counterparty or unchanged value —
            // silent no-op.
            return;
        }

        $events->dispatch(new TransactionMutated(
            transactionId: $this->transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['counterparty_id' => $newCounterpartyId],
        ));

        // This is the sole user-driven counterparty_id write path, so
        // it owns stamping manual provenance.
        $provenance->stamp($user->id, $this->transactionId, ['counterparty_id' => 'manual']);

        $this->dispatch('toast', message: Lang::get('ledger::detail.toast.counterparty_updated'));
    }

    // Deletes this transaction and emits a tombstone op so the Sync
    // engine propagates the deletion across devices; redirects to the
    // transactions list after a successful delete.
    public function deleteTransaction(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        UrlGenerator $urls,
    ): void {
        $userId = $currentUser->user()->id;

        // Ownership check before delete — reads status in the same
        // scoped query for the reconciled lock check below.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->value('status');

        if ($status === null) {
            throw new NotFoundHttpException;
        }

        if ($status === ClearedStatus::Reconciled->value) {
            $this->dispatch('toast', message: Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        // Read the leg ids before deleting the parent (the DB cascade
        // removes the leg rows locally) — convergence must not rely on
        // the peer's replay connection having FK cascade active, so an
        // explicit delete tombstone is emitted per leg below.
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

        // The Sync capture listener records a delete_tombstone op for
        // this dispatch.
        $events->dispatch(new TransactionMutated(
            transactionId: $this->transactionId,
            userId: $userId,
            mutationType: 'delete',
            dirtyFields: [],
        ));

        // One tombstone per leg, dispatched after the parent commit
        // above.
        foreach ($legRows as $legRow) {
            $legId = is_numeric($legRow->id) ? (int) $legRow->id : 0;
            $events->dispatch(new TransactionSplitMutated(
                splitId: $legId,
                transactionId: $this->transactionId,
                userId: $userId,
                mutationType: 'delete',
            ));
        }

        $this->redirect($urls->route('transactions.index'), navigate: true);
    }

    // Livewire lifecycle hook: recomputes the server-truthful remaining
    // figure whenever a leg amount changes. Fires for any
    // legs.{index}.amount mutation regardless of the debounce binding path.
    public function updated(string $name, mixed $value, CurrentUser $currentUser, DatabaseManager $db): void
    {
        if (str_starts_with($name, 'legs.') && str_ends_with($name, '.amount')) {
            $this->recomputeRemaining($currentUser, $db);
        }
    }

    // Normalises a leg's categoryId (which may arrive as a string or
    // '' from a Livewire <select> binding) to ?int.
    /**
     * @param  array{id: ?int, categoryId: int|string|null, amount: string, note: string, tax: bool}  $leg
     */
    private static function legCategoryId(array $leg): ?int
    {
        $raw = $leg['categoryId'];
        if ($raw === null || $raw === '') {
            return null;
        }

        return is_numeric($raw) ? (int) $raw : null;
    }

    // Split legs are entered as positive magnitudes — the sign belongs to
    // the parent transaction — so a zero or negative entry is not a valid
    // leg amount.
    private static function parseAmount(string $value): ?int
    {
        return MoneyInput::tryToPositiveMinor($value);
    }

    // Integer-only arithmetic (intdiv/modulo), never float division,
    // per the project's no-float-money rule.
    private static function formatAbsAmount(int $absMinor): string
    {
        $whole = intdiv($absMinor, Money::MINOR_UNITS_PER_MAJOR);
        $frac = $absMinor % Money::MINOR_UNITS_PER_MAJOR;

        return number_format($whole, 0, '', '.').','.str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
    }

    public function render(
        CurrentUser $currentUser,
        ViewFactory $views,
        ChainLinkQuery $chainQuery,
        TaxTagQuery $taxTagQuery,
        DatabaseManager $db,
        CategoryOptionsQuery $categoryOptions,
        SensitiveColumnCodec $codec,
        Session $session,
    ): View {
        $userId = $currentUser->user()->id;

        // Eager-load the resolved counterparty + category so the Blade
        // can render both without a second query. The relation is null
        // for pre-resolver history — the Blade falls back to plain text.
        $transaction = Transaction::query()
            ->with(['counterparty', 'category'])
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->firstOrFail();

        // Read-side decrypt — assigned back onto the in-memory model
        // attribute only (never re-saved), so the Blade renders plaintext.
        if (is_string($transaction->counterparty_name)) {
            $transaction->counterparty_name = $codec->decryptValue(
                'transactions',
                'counterparty_name',
                $transaction->counterparty_name,
                $userId,
                $session,
            )['value'];
        }

        // Offered for any non-transfer type. The category options list
        // is only loaded when needed — transfer detail pages never
        // render the Split section at all.
        $isSplittable = (TransactionType::tryFrom($transaction->type)?->isSplittable() === true);
        $splitCategories = $isSplittable ? $categoryOptions->for($currentUser->user()) : [];

        // Gates the "View chain" button on whether this transaction
        // actually participates in any chain_link, so a row with zero
        // chain coverage doesn't show a button that just opens an
        // empty drawer.
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

        // Only user-owned rows for the reassignment picker. display_name
        // is ciphertext at rest once encryption is enabled, so the
        // DB-level ORDER BY would sort by ciphertext — decrypt first,
        // then re-sort in PHP so the dropdown stays alphabetical either way.
        $counterparties = $db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->get(['id', 'display_name', 'slug'])
            ->map(function (\stdClass $row) use ($codec, $userId, $session): \stdClass {
                if (is_string($row->display_name)) {
                    $row->display_name = $codec->decryptValue('counterparties', 'display_name', $row->display_name, $userId, $session)['value'];
                }

                return $row;
            })
            ->sortBy('display_name')
            ->values();

        $view = $views->make('ledger::livewire.transaction-detail', [
            'transaction' => $transaction,
            'chainAvailable' => $chainAvailable,
            'txTaxRow' => $txTaxRow,
            'clearedStatus' => $clearedStatus,
            'counterparties' => $counterparties,
            'isSplittable' => $isSplittable,
            'splitCategories' => $splitCategories,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('ledger::detail.page_title').' · beatrax']);

        return $view;
    }
}
