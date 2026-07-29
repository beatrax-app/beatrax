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
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Ledger\Public\Exceptions\SplitSumMismatchException;
use Modules\Ledger\Public\Http\Livewire\Concerns\HandlesClearedStatus;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Ledger\Public\Services\ReconciliationWriter;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\SplittableTypes;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
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
    // The one message every reconciled-row guard shows, so the four
    // guards cannot drift apart in wording.
    private const RECONCILED_NOTICE = 'This transaction is reconciled. Un-reconcile it to make changes.';

    use HandlesClearedStatus;
    use HandlesTaxTagging;

    public int $transactionId = 0;

    // Reset to '' after every successful reclassify so the dropdown
    // returns to "Choose a type..." and the just-applied value is
    // hidden from the option list.
    public string $reclassifyType = '';

    public string $note = '';

    public bool $noteSaved = false;

    // True either because the transaction already carries persisted
    // legs, or because the user just clicked "Split into categories".
    public bool $editingSplit = false;

    // Distinct from $editingSplit: a freshly-opened, never-saved split
    // editor has editingSplit=true but hasPersistedSplit=false —
    // nothing persists until "Save split". Gates the whole-transaction
    // tax section suppression.
    public bool $hasPersistedSplit = false;

    // Session-local until saveSplit() persists them. categoryId is
    // int|string|null because Livewire's <select> wire:model always
    // arrives as a string; every read normalises via legCategoryId().
    /** @var list<array{id: ?int, categoryId: int|string|null, amount: string, note: string, tax: bool}> */
    public array $legs = [];

    // Computed via the Money value object, never client math. Positive
    // = still to assign, negative = over-allocated, zero = exact.
    public int $remainingMinor = 0;

    // Set when the server rejects a save (SplitSumMismatchException,
    // invalid leg, etc).
    public ?string $splitError = null;

    public bool $confirmUnsplit = false;

    public ?int $unsplitSurvivorIndex = null;

    public bool $confirmRemoveToOne = false;

    public ?int $pendingRemoveIndex = null;

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

    // Allow-listed via Transaction::TYPES — any other value raises
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
        if (! in_array($newType, Transaction::TYPES, true)) {
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
        if ($tx->status === 'reconciled') {
            $this->dispatch('toast', message: self::RECONCILED_NOTICE);

            return;
        }

        // Reclassifying a split transaction to a non-splittable type
        // would strand its legs (see the linked architecture page) —
        // collapse the split first, through the sole mutator, before
        // the type leaves the splittable set.
        $didUnsplit = false;
        if (! SplittableTypes::contains($newType)) {
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
            && ! in_array($newType, ['transfer_out', 'transfer_in'], true);

        $db->connection()->transaction(static function () use ($tx, $newType, $partnerId, $user, $breaksPair): void {
            $tx->type = $newType;
            if (! in_array($newType, ['transfer_out', 'transfer_in'], true)) {
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
            ? sprintf('Reclassified to %s — pair removed', $newType)
            : sprintf('Reclassified to %s', $newType);

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

        if ($status === 'reconciled') {
            $this->dispatch('toast', message: self::RECONCILED_NOTICE);

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

        if ($status === 'reconciled') {
            $this->dispatch('toast', message: self::RECONCILED_NOTICE);

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
        $this->dispatch('toast', message: 'Note saved');
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

        $this->dispatch('toast', message: 'Un-reconciled — you can edit this transaction again.');
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

        if ($status === 'reconciled') {
            $this->dispatch('toast', message: self::RECONCILED_NOTICE);

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

        $this->dispatch('toast', message: 'Counterparty updated');
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

        if ($status === 'reconciled') {
            $this->dispatch('toast', message: self::RECONCILED_NOTICE);

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

    // Seeds exactly 2 in-memory legs for a not-yet-split transaction —
    // leg 0 = current category + full parent amount, leg 1 = blank —
    // and persists nothing. No-op if already split (legs are already
    // loaded by loadSplitState() at mount time).
    public function openSplitEditor(CurrentUser $currentUser, DatabaseManager $db): void
    {
        if ($this->hasPersistedSplit) {
            return;
        }

        $userId = $currentUser->user()->id;
        $parent = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->first(['category_id', 'settled_amount_minor']);

        if ($parent === null) {
            return;
        }

        $categoryId = is_numeric($parent->category_id) ? (int) $parent->category_id : null;
        $absMinor = abs(is_numeric($parent->settled_amount_minor) ? (int) $parent->settled_amount_minor : 0);

        $this->legs = [
            ['id' => null, 'categoryId' => $categoryId, 'amount' => self::formatAbsAmount($absMinor), 'note' => '', 'tax' => false],
            ['id' => null, 'categoryId' => null, 'amount' => self::formatAbsAmount(0), 'note' => '', 'tax' => false],
        ];
        $this->editingSplit = true;
        $this->splitError = null;

        $this->recomputeRemaining($currentUser, $db);
    }

    public function addLeg(): void
    {
        $this->legs[] = ['id' => null, 'categoryId' => null, 'amount' => self::formatAbsAmount(0), 'note' => '', 'tax' => false];
    }

    // At >=3 legs this removes instantly. At exactly 2, removing would
    // drop the count to 1 — instead of silently collapsing, this routes
    // to the same two-step confirm as "Unsplit transaction", scoped to
    // the leg that would become the sole survivor.
    public function removeLeg(int $index, CurrentUser $currentUser, DatabaseManager $db): void
    {
        if (! array_key_exists($index, $this->legs)) {
            return;
        }

        if (count($this->legs) <= 2) {
            $this->pendingRemoveIndex = $index;
            $this->confirmRemoveToOne = true;

            return;
        }

        $legs = $this->legs;
        array_splice($legs, $index, 1);
        $this->legs = $legs;
        $this->recomputeRemaining($currentUser, $db);
    }

    public function cancelRemoveToOne(): void
    {
        $this->confirmRemoveToOne = false;
        $this->pendingRemoveIndex = null;
    }

    // The other leg (not the one the user clicked remove on) becomes
    // the surviving category via SavesTransactionSplit::unsplit().
    public function confirmRemoveToOneAction(SavesTransactionSplit $splitter, CurrentUser $currentUser): void
    {
        if ($this->pendingRemoveIndex === null || count($this->legs) !== 2) {
            return;
        }

        // A never-persisted editor has no split to reverse — backing
        // out must be a pure in-memory collapse, not a durable write.
        // Only call the mutator when a persisted split actually exists.
        if (! $this->hasPersistedSplit) {
            $this->resetSplitEditor();
            $this->confirmRemoveToOne = false;
            $this->pendingRemoveIndex = null;
            $this->dispatch('toast', message: 'Removed — one category remains');

            return;
        }

        $survivorIndex = $this->pendingRemoveIndex === 0 ? 1 : 0;
        $survivorCategoryId = self::legCategoryId($this->legs[$survivorIndex]);

        if ($survivorCategoryId === null) {
            $this->splitError = 'Choose a category before removing.';

            return;
        }

        try {
            $splitter->unsplit($currentUser->user(), $this->transactionId, $survivorCategoryId);
        } catch (InvalidArgumentException $e) {
            $this->splitError = $e->getMessage();

            return;
        }

        $this->resetSplitEditor();
        $this->confirmRemoveToOne = false;
        $this->pendingRemoveIndex = null;
        $this->dispatch('toast', message: 'Removed — one category remains');
    }

    // Defaults the survivor radio to the larger-magnitude leg — a
    // pre-selected (not locked) radio choice.
    public function unsplit(): void
    {
        if ($this->legs === []) {
            return;
        }

        $this->unsplitSurvivorIndex = $this->largestMagnitudeLegIndex();
        $this->confirmUnsplit = true;
    }

    public function selectUnsplitSurvivor(int $index): void
    {
        if (array_key_exists($index, $this->legs)) {
            $this->unsplitSurvivorIndex = $index;
        }
    }

    public function cancelUnsplit(): void
    {
        $this->confirmUnsplit = false;
        $this->unsplitSurvivorIndex = null;
    }

    public function confirmUnsplitAction(SavesTransactionSplit $splitter, CurrentUser $currentUser): void
    {
        if ($this->unsplitSurvivorIndex === null || ! array_key_exists($this->unsplitSurvivorIndex, $this->legs)) {
            return;
        }

        // Never-persisted editor — collapse purely in memory, no
        // mutator call, no category write, no op-log entry.
        if (! $this->hasPersistedSplit) {
            $this->resetSplitEditor();
            $this->confirmUnsplit = false;
            $this->unsplitSurvivorIndex = null;
            $this->dispatch('toast', message: 'Unsplit — restored to a single category');

            return;
        }

        $survivorCategoryId = self::legCategoryId($this->legs[$this->unsplitSurvivorIndex]);

        if ($survivorCategoryId === null) {
            $this->splitError = 'Choose a category before unsplitting.';

            return;
        }

        try {
            $splitter->unsplit($currentUser->user(), $this->transactionId, $survivorCategoryId);
        } catch (InvalidArgumentException $e) {
            $this->splitError = $e->getMessage();

            return;
        }

        $this->resetSplitEditor();
        $this->confirmUnsplit = false;
        $this->unsplitSurvivorIndex = null;
        $this->dispatch('toast', message: 'Unsplit — restored to a single category');
    }

    // Re-validates server-side (the disabled Save button is a UX gate
    // only, never authoritative): recomputes remainingMinor fresh,
    // rejects any leg that fails to parse or carries no category, then
    // delegates to the sole mutator.
    public function saveSplit(SavesTransactionSplit $splitter, CurrentUser $currentUser, DatabaseManager $db, TaxTagQuery $taxTagQuery, SensitiveColumnCodec $codec, Session $session): void
    {
        $this->splitError = null;
        $userId = $currentUser->user()->id;

        $this->recomputeRemaining($currentUser, $db);

        if ($this->remainingMinor !== 0) {
            $this->splitError = "Couldn't save — leg totals must match the transaction total exactly.";

            return;
        }

        $parent = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->first(['settled_amount_minor']);

        if ($parent === null) {
            $this->splitError = 'Transaction not found.';

            return;
        }

        $parentMinor = is_numeric($parent->settled_amount_minor) ? (int) $parent->settled_amount_minor : 0;

        /** @var list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}> $legsPayload */
        $legsPayload = [];

        foreach ($this->legs as $leg) {
            $abs = self::parseAmount($leg['amount']);
            if ($abs === null) {
                $this->splitError = "Amount can't be €0,00";

                return;
            }

            $categoryId = self::legCategoryId($leg);
            if ($categoryId === null) {
                $this->splitError = 'Choose a category.';

                return;
            }

            $trimmedNote = trim($leg['note']);

            $legsPayload[] = [
                'id' => $leg['id'],
                'category_id' => $categoryId,
                'settled_amount_minor' => $parentMinor < 0 ? -$abs : $abs,
                'note' => $trimmedNote !== '' ? $trimmedNote : null,
            ];
        }

        try {
            $splitter->save($currentUser->user(), $this->transactionId, $legsPayload);
        } catch (SplitSumMismatchException) {
            $this->splitError = "Couldn't save — leg totals must match the transaction total exactly.";

            return;
        } catch (InvalidArgumentException $e) {
            $this->splitError = $e->getMessage();

            return;
        }

        // Reload persisted legs (real DB ids) + tax state so subsequent
        // edits/removals correctly diff against the now-saved rows.
        $this->loadSplitState($currentUser, $db, $taxTagQuery, $codec, $session);
        $this->dispatch('toast', message: 'Split saved');
    }

    // A no-op for a leg that has not yet been persisted (no id) — leg-
    // scoped tax tagging requires an existing transaction_splits row.
    public function toggleLegTax(int $index, TagTransaction $tag, UntagTransaction $untag, CurrentUser $currentUser, DatabaseManager $db): void
    {
        if (! array_key_exists($index, $this->legs)) {
            return;
        }

        $legId = $this->legs[$index]['id'];
        if ($legId === null) {
            return;
        }

        $userId = $currentUser->user()->id;

        // Reconciled lock: warn-first, no write. Tax tags on a
        // reconciled transaction's legs are exactly the classification
        // a reconcile is meant to freeze.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->value('status');

        if ($status === 'reconciled') {
            $this->dispatch('toast', message: self::RECONCILED_NOTICE);

            return;
        }

        $newState = ! $this->legs[$index]['tax'];

        if ($newState) {
            $tag->execute($userId, $this->transactionId, null, null, null, $legId);
        } else {
            $untag->execute($userId, $this->transactionId, $legId);
        }

        $this->legs[$index]['tax'] = $newState;
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

    // Called at mount and after every successful saveSplit() so leg
    // ids stay in sync with the DB for subsequent edit diffs.
    private function loadSplitState(CurrentUser $currentUser, DatabaseManager $db, TaxTagQuery $taxTagQuery, SensitiveColumnCodec $codec, Session $session): void
    {
        $userId = $currentUser->user()->id;

        $rows = $db->connection()
            ->table('transaction_splits')
            ->where('transaction_id', $this->transactionId)
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->get(['id', 'category_id', 'settled_amount_minor', 'note']);

        if ($rows->isEmpty()) {
            $this->hasPersistedSplit = false;
            $this->editingSplit = false;
            $this->legs = [];

            return;
        }

        $taxStates = $taxTagQuery->forTransactionIdsWithLegs($userId, [$this->transactionId]);

        $legs = [];
        foreach ($rows as $row) {
            $legId = is_numeric($row->id) ? (int) $row->id : 0;
            $key = $this->transactionId.':'.$legId;

            // Read-side decrypt — pass-through no-op when encryption
            // is not enabled for this user.
            $legNote = is_string($row->note)
                ? $codec->decryptValue('transaction_splits', 'note', $row->note, $userId, $session)['value']
                : '';

            $legs[] = [
                'id' => $legId,
                'categoryId' => is_numeric($row->category_id) ? (int) $row->category_id : null,
                'amount' => self::formatAbsAmount(is_numeric($row->settled_amount_minor) ? abs((int) $row->settled_amount_minor) : 0),
                'note' => $legNote,
                'tax' => isset($taxStates[$key]),
            ];
        }

        $this->legs = $legs;
        $this->hasPersistedSplit = true;
        $this->editingSplit = true;

        $this->recomputeRemaining($currentUser, $db);
    }

    // Re-reads the parent's settled amount and re-sums every leg's
    // parsed absolute amount, both via the Money value object — never
    // client-supplied math.
    private function recomputeRemaining(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $userId = $currentUser->user()->id;

        $parent = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->first(['settled_amount_minor', 'settled_currency']);

        if ($parent === null) {
            $this->remainingMinor = 0;

            return;
        }

        $parentMinor = is_numeric($parent->settled_amount_minor) ? (int) $parent->settled_amount_minor : 0;
        $currency = is_string($parent->settled_currency) ? $parent->settled_currency : 'EUR';

        $parentAbs = Money::ofMinor(abs($parentMinor), $currency);
        $sumAbs = Money::ofMinor(0, $currency);

        foreach ($this->legs as $leg) {
            $abs = self::parseAmount($leg['amount']) ?? 0;
            $sumAbs = $sumAbs->plus(Money::ofMinor($abs, $currency));
        }

        $this->remainingMinor = $parentAbs->minus($sumAbs)->toMinor();
    }

    private function resetSplitEditor(): void
    {
        $this->editingSplit = false;
        $this->hasPersistedSplit = false;
        $this->legs = [];
        $this->remainingMinor = 0;
        $this->splitError = null;
    }

    private function largestMagnitudeLegIndex(): int
    {
        $bestIndex = 0;
        $bestAbs = -1;

        foreach ($this->legs as $index => $leg) {
            $abs = self::parseAmount($leg['amount']) ?? 0;
            if ($abs > $bestAbs) {
                $bestAbs = $abs;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
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

    // Parses a user-entered absolute amount into positive integer minor
    // units, or null if invalid/zero/negative. Handles the Dutch
    // grouped form "1.234,56" and the plain forms "1234.56"/"50,00"/"50"
    // — mirrors PotWriter::parseAmount(); keep both in sync.
    private static function parseAmount(string $value): ?int
    {
        $normalised = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($normalised === '') {
            return null;
        }

        $lastDot = strrpos($normalised, '.');
        $lastComma = strrpos($normalised, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $normalised = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $normalised)
                : str_replace(',', '', $normalised);
        } elseif ($lastComma !== false) {
            $normalised = str_replace(',', '.', $normalised);
        }

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $normalised) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $normalised, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return $minor > 0 ? $minor : null;
    }

    // Integer-only arithmetic (intdiv/modulo), never float division,
    // per the project's no-float-money rule.
    private static function formatAbsAmount(int $absMinor): string
    {
        $whole = intdiv($absMinor, 100);
        $frac = $absMinor % 100;

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
        $isSplittable = SplittableTypes::contains($transaction->type);
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
        $clearedStatus = $clearedState[$this->transactionId] ?? 'cleared';

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
        $view->extends('layouts.app', ['title' => 'Transaction · beatrax']);

        return $view;
    }
}
