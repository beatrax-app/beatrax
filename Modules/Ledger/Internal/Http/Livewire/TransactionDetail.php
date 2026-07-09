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

/**
 * The `/transactions/{transactionId}` detail page.
 *
 * Renders a calm two-column `<dl>` of the row's headline metadata
 * (date, counterparty, native amount, settled-EUR amount) plus a
 * conditional "Effective rate" row that appears only when the
 * transaction carries a non-null `fx_rate_used` value.
 *
 * Renders the Reclassify control: a single-click type override that
 * atomically breaks the `pair_transaction_id` relationship on both
 * sides when the new type is non-transfer. Transfer-to-transfer
 * reclassifies preserve the pair — that path remains a one-sided type
 * swap.
 *
 * Multi-user readiness: every Eloquent query carries an explicit
 * `where('user_id', $currentUser->user()->id)` predicate. A request
 * for a transaction owned by a different user resolves to 404 in
 * `mount()` before any data is exposed to the view; the reclassify
 * action enforces the same scoping via `firstOrFail()` on a query
 * filtered by `user_id`.
 *
 * DI-only: this Livewire component has no constructor. Service
 * collaborators arrive as parameters on `mount()`, `render()`, and
 * action methods — the strict-rules ruleset forbids property-based
 * constructor injection on Component subclasses, and `auth()` /
 * `Auth::user()` / facade lookups are out of bounds project-wide.
 *
 * Page-shell wiring: `render()` calls `$view->extends('layouts.app', ...)`
 * so this component can be wired directly as a `Route::get(...,
 * TransactionDetail::class)` page handler without a separate Blade
 * wrapper. The macro is registered by Livewire's SupportPageComponents
 * feature and produces a `@extends('layouts.app') @section('content')`
 * envelope identical to every other beatrax page.
 */
final class TransactionDetail extends Component
{
    use HandlesClearedStatus;
    use HandlesTaxTagging;

    public int $transactionId = 0;

    /**
     * The pending dropdown selection driven by `wire:model.live` on the
     * Reclassify select. Reset to `''` after every successful reclassify
     * so the dropdown returns to "Choose a type…" and the just-applied
     * value is hidden from the option list (the Blade filters out the
     * transaction's current type).
     */
    public string $reclassifyType = '';

    /** User-editable note for this transaction (OQ-A). */
    public string $note = '';

    /** Flash indicator set to true after a successful note save. */
    public bool $noteSaved = false;

    // ── Split editor state (Phase 13.1 Plan 05) ──────────────────────────

    /**
     * Whether the inline split editor is currently expanded — true either
     * because the transaction already carries persisted legs, or because
     * the user just clicked "Split into categories" for a fresh split.
     */
    public bool $editingSplit = false;

    /**
     * Whether the transaction currently owns PERSISTED `transaction_splits`
     * rows. Distinct from `$editingSplit`: a freshly-opened, never-saved
     * split editor has `editingSplit = true` but `hasPersistedSplit =
     * false` (D-03a — nothing persists until "Save split"). Gates the
     * whole-transaction tax section suppression (D-06) and the "Tax tags
     * are set per category below." note (UI-SPEC §7.1).
     */
    public bool $hasPersistedSplit = false;

    /**
     * In-memory leg rows. Session-local until `saveSplit()` persists them
     * (D-03a) — opening the editor or editing a field never touches
     * `transaction_splits`. `categoryId` is typed `int|string|null` because
     * Livewire's `wire:model` on a `<select>` always arrives as a string
     * (or `''` for the empty option); every read normalises via
     * `legCategoryId()` before use.
     *
     * @var list<array{id: ?int, categoryId: int|string|null, amount: string, note: string, tax: bool}>
     */
    public array $legs = [];

    /**
     * Server-truthful "remaining to assign", computed via the Money VO
     * (UI-SPEC §12 — no client math). Positive = still to assign, negative
     * = over-allocated by |value|, zero = exact (save-enabled).
     */
    public int $remainingMinor = 0;

    /**
     * Page-level error banner (UI-SPEC §9.5) — set when the server rejects
     * a save (SplitSumMismatchException, invalid leg, etc). Rendered via
     * `.wiz-error`.
     */
    public ?string $splitError = null;

    /** True while the "Unsplit transaction" two-step confirm is showing (§8.1). */
    public bool $confirmUnsplit = false;

    /** Index into `$legs` of the radio-selected unsplit survivor (§8.1). */
    public ?int $unsplitSurvivorIndex = null;

    /** True while the remove-to-one-leg two-step confirm is showing (§8.2). */
    public bool $confirmRemoveToOne = false;

    /** Index into `$legs` the user attempted to remove while only 2 remained (§8.2). */
    public ?int $pendingRemoveIndex = null;

    public function mount(int $transactionId, CurrentUser $currentUser, DatabaseManager $db, TaxTagQuery $taxTagQuery, SensitiveColumnCodec $codec, Session $session): void
    {
        $this->transactionId = $transactionId;
        $userId = $currentUser->user()->id;

        // The raw Query Builder `exists()` call is used here instead of
        // Eloquent's `Transaction::query()->exists()` to clear PHPStan
        // strict-rules `staticMethod.dynamicCall` — Eloquent's exists()
        // is a magic forward over Builder's instance method, which the
        // strict ruleset rejects on a freshly resolved query. Same
        // pattern as UpdateTransactionCategory's category-visibility
        // pre-check.
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

        // Load the existing note into the Livewire wire:model property.
        // CRYPT-01 (D-02b) read-side decrypt — the note projection column
        // is ciphertext at rest once encryption is enabled (op-log
        // write-back re-encrypts it via the same Sync Public codec, Plan
        // 03); pass-through no-op otherwise.
        $this->note = is_string($row->note)
            ? $codec->decryptValue('transactions', 'note', $row->note, $userId, $session)['value']
            : '';

        $this->loadSplitState($currentUser, $db, $taxTagQuery, $codec, $session);
    }

    /**
     * Manually override the transaction's `type`. The user-facing entry
     * point for the reclassify action.
     *
     * Allow-listed via `Transaction::TYPES` — any other value raises
     * `InvalidArgumentException` before any DB read. Same-user scoping
     * is enforced by `firstOrFail()` on a query filtered by `user_id`;
     * a cross-user invocation raises `NotFoundHttpException` (404).
     *
     * When the new type is not `transfer_out` / `transfer_in` and the
     * row currently carries a `pair_transaction_id`, the pair is
     * broken atomically: both the row and its partner have
     * `pair_transaction_id` set to `NULL` inside the same DB
     * transaction. Transfer-to-transfer reclassifies preserve the
     * pair (re-pairing is the listener's job at import time).
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

        // D-08 reconciled lock: warn-first, no write (T-13.3-13). Reads the
        // already user-scoped $tx just loaded above — no extra query needed.
        if ($tx->status === 'reconciled') {
            $this->dispatch('toast', message: 'This transaction is reconciled. Un-reconcile it to make changes.');

            return;
        }

        // CR-01: reclassifying a SPLIT transaction to a non-splittable type
        // would strand its legs — the read-side union (SpendByCategoryQuery)
        // has no type filter, so the orphaned legs keep counting as category
        // spend while the parent is dropped from the unsplit roll-up, and the
        // type-gated UI offers no path to unsplit or re-tag. Collapse the
        // split FIRST, through the sole mutator, so leg-delete tombstones are
        // emitted (WR-01) before the type leaves the splittable set. The first
        // leg's category (user-scoped) becomes the surviving category —
        // guaranteed to satisfy unsplit()'s "survivor must be a current leg
        // category" check.
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
                // partner's own `type` is preserved; reclassify never
                // re-types the partner.
                Transaction::query()
                    ->where('user_id', $user->id)
                    ->where('id', $partnerId)
                    ->update(['pair_transaction_id' => null]);
            }
        });

        // Hand-wired capture emission (D-02): type reclassify enters the op-log
        // as a per-field Set op. The pair partner's pair_transaction_id NULL-ing
        // is a structural FK side-effect handled by the merge engine's cascade (D-08).
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

        // CR-01: if the reclassify collapsed a split, drop the now-stale
        // in-memory leg state so the component no longer reports
        // hasPersistedSplit (which would otherwise keep suppressing the
        // whole-transaction tax section for a transaction that no longer
        // has legs).
        if ($didUnsplit) {
            $this->resetSplitEditor();
        }
    }

    /**
     * Reclassify the transaction's category. Reads the row's prior
     * `auto_category_provenance` BEFORE invoking AssignsCategory so
     * the correction-divergence bridge can compare against the rule
     * that fired at import time. After AssignsCategory returns (which
     * already dispatches the framework `CategorizationDiverged`
     * event), this method re-emits the Livewire-local
     * `correction-divergence:fire` event so the globally-mounted
     * CorrectionDivergenceToast SFC surfaces the Update rule / Keep
     * current rule choice within the same request lifecycle.
     *
     * The dual-channel pattern (framework event + Livewire-local
     * event) keeps the framework event reusable by non-UI
     * consumers (audit-log, analytics) while the local event drives
     * the toast without depending on a redirect or broadcaster.
     *
     * Cross-user safety: AssignsCategory's implementation scopes the
     * UPDATE by user_id; a foreign-user transaction returns 0 rows
     * affected and no event fires. The Livewire-local
     * `correction-divergence:fire` event carries the explicit
     * `$userId` field so the toast SFC can perform its cross-user
     * guard defensively.
     */
    public function reclassifyCategory(
        int $newCategoryId,
        CurrentUser $currentUser,
        AssignsCategory $assign,
        DatabaseManager $db,
    ): void {
        $user = $currentUser->user();

        // D-08 reconciled lock: warn-first, no write (WR-01). reclassifyCategory
        // is a public Livewire method — directly invokable from the browser —
        // so it must enforce the same "un-reconcile first" contract as the
        // sibling mutators. Status read is scoped by user_id so a cross-user id
        // no-ops rather than leaking.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === 'reconciled') {
            $this->dispatch('toast', message: 'This transaction is reconciled. Un-reconcile it to make changes.');

            return;
        }

        // Single canonical divergence detector lives on
        // CategorizationDiverged::fromProvenance; both this Livewire
        // dispatch and AssignCategory's framework-event dispatch route
        // their validation through the same factory so the two
        // channels stay in lockstep on any future provenance shape
        // change.
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

    /**
     * Save the user-edited note on this transaction (OQ-A).
     *
     * Empty/blank input is normalised to NULL in the DB (IS NULL semantics).
     * Delegates the actual write to Ledger's `SetsTransactionNote` Public
     * action (T-13.4-13b — module boundary fix; the same pure guarded
     * writer the Plan 05 rule engine reuses) — this method no longer runs
     * an inline `transactions` UPDATE. A TransactionMutated(edit) event is
     * dispatched, scoped to a genuine change, so the Sync capture listener
     * records it (D-02).
     */
    public function saveNote(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        SetsTransactionNote $setNote,
        FieldProvenanceWriter $provenance,
    ): void {
        $user = $currentUser->user();

        // D-08 reconciled lock: warn-first, no write (T-13.3-13/T-13.3-14).
        // Status read is scoped by user_id so a cross-user id no-ops rather
        // than leaking. Pre-checked here (rather than only inside the
        // action) so this method can show the specific "un-reconcile
        // first" toast instead of a generic no-op.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === 'reconciled') {
            $this->dispatch('toast', message: 'This transaction is reconciled. Un-reconcile it to make changes.');

            return;
        }

        $trimmed = trim($this->note);
        $value = $trimmed === '' ? null : $trimmed;

        $affected = ($setNote)($this->transactionId, $value, 'set', $user);

        if ($affected > 0) {
            // Hand-wired capture emission (D-02).
            $events->dispatch(new TransactionMutated(
                transactionId: $this->transactionId,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: ['note' => $value],
            ));

            // D-04 (Req 4 — manual-preservation): this is the SOLE
            // user-driven note write path (T-13.4-13).
            $provenance->stamp($user->id, $this->transactionId, ['note' => 'manual']);
        }

        $this->noteSaved = true;
        $this->dispatch('toast', message: 'Note saved');
    }

    /**
     * Toggle this transaction's cleared/uncleared status (SC-1, D-08/D-11).
     *
     * Delegates to `HandlesClearedStatus::toggleClearedStatus()` — the
     * trait owns the warn-first-on-reconciled guard, the cleared<->
     * uncleared flip, the `Transaction::STATUSES` validation, and the
     * dispatch-after-commit `TransactionMutated` event. This method exists
     * so the detail page's own transactionId never has to be threaded
     * through the generic `#[On('cleared-toggle')]` event path.
     */
    public function toggleCleared(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        Clock $clock,
    ): void {
        $this->toggleClearedStatus($this->transactionId, $currentUser, $db, $events, $clock);
    }

    /**
     * The escape hatch every reconciled-lock warn toast on this page points
     * to (D-08, SC-2). Delegates entirely to
     * `ReconciliationWriter::unreconcile()` — the sole mutator that reverts
     * a `reconciled` row back to `cleared` — so the guard clauses added
     * above (reclassify/saveNote/reassignCounterparty/deleteTransaction)
     * have a real path back to an editable state.
     *
     * A foreign, missing, or already non-reconciled transaction id is a
     * silent no-op (mirrors `ReconciliationWriter::unreconcile()`'s own
     * cross-user handling — I2 guard).
     */
    public function unreconcile(
        ReconciliationWriter $writer,
        CurrentUser $currentUser,
    ): void {
        $writer->unreconcile($currentUser->user(), $this->transactionId);

        $this->dispatch('toast', message: 'Un-reconciled — you can edit this transaction again.');
    }

    /**
     * Reassign this transaction's counterparty_id to a different user-owned
     * counterparty (OQ-C).
     *
     * Delegates the actual write to Ledger's `ReassignsCounterparty` Public
     * action (T-13.4-13b — module boundary fix; the same pure guarded
     * writer the Plan 05 rule engine reuses) — this method no longer runs
     * an inline `transactions` UPDATE. Cross-user counterparty, an
     * unchanged value, or a reconciled row all resolve to a silent no-op
     * (neither the transaction nor the op-log is mutated). This is the
     * SOLE user-driven counterparty_id write path — import-pipeline
     * (ResolveCounterpartyStage) and GC writes stay immutable.
     */
    public function reassignCounterparty(
        int $newCounterpartyId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        ReassignsCounterparty $reassign,
        FieldProvenanceWriter $provenance,
    ): void {
        $user = $currentUser->user();

        // Ownership-check the transaction (same as reclassify() pattern) and
        // read its status in the same scoped query for the D-08 lock check
        // below (T-13.3-13/T-13.3-14). Pre-checked here (rather than only
        // inside the action) so this method can show the specific
        // "un-reconcile first" toast instead of a generic no-op.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('status');

        if ($status === null) {
            throw new NotFoundHttpException;
        }

        // D-08 reconciled lock: warn-first, no write.
        if ($status === 'reconciled') {
            $this->dispatch('toast', message: 'This transaction is reconciled. Un-reconcile it to make changes.');

            return;
        }

        $affected = ($reassign)($this->transactionId, $newCounterpartyId, $user);

        if ($affected === 0) {
            // Cross-user/unknown counterparty or unchanged value —
            // silent no-op (T-11-11).
            return;
        }

        // Hand-wired capture emission (D-02): user-driven reassignment only.
        $events->dispatch(new TransactionMutated(
            transactionId: $this->transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['counterparty_id' => $newCounterpartyId],
        ));

        // D-04 (Req 4 — manual-preservation): this is the SOLE
        // user-driven counterparty_id write path (T-13.4-13).
        $provenance->stamp($user->id, $this->transactionId, ['counterparty_id' => 'manual']);

        $this->dispatch('toast', message: 'Counterparty updated');
    }

    /**
     * Delete this transaction and emit a tombstone op so the Sync engine
     * propagates the deletion across devices (D-03/D-08).
     *
     * Redirects to the transactions list after a successful delete.
     */
    public function deleteTransaction(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        UrlGenerator $urls,
    ): void {
        $userId = $currentUser->user()->id;

        // Ownership check before delete — reads status in the same scoped
        // query for the D-08 lock check below (T-13.3-13/T-13.3-14).
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->value('status');

        if ($status === null) {
            throw new NotFoundHttpException;
        }

        // D-08 reconciled lock: warn-first, no write.
        if ($status === 'reconciled') {
            $this->dispatch('toast', message: 'This transaction is reconciled. Un-reconcile it to make changes.');

            return;
        }

        // WR-01: read the leg ids BEFORE deleting the parent (the DB cascade
        // removes the leg rows locally). Convergence must not rely on the
        // peer's replay connection having FK cascade active — emit an
        // explicit delete tombstone per leg, mirroring unsplit(), so the leg
        // deletions are first-class ops in the log rather than an implicit
        // FK side-effect.
        $legRows = $db->connection()
            ->table('transaction_splits')
            ->where('transaction_id', $this->transactionId)
            ->where('user_id', $userId)
            ->get(['id']);

        $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)   // I2 guard
            ->delete();

        // Tombstone emission — the listener records a delete_tombstone op (D-03).
        $events->dispatch(new TransactionMutated(
            transactionId: $this->transactionId,
            userId: $userId,
            mutationType: 'delete',
            dirtyFields: [],
        ));

        // Per-leg delete tombstones (WR-01) — after the parent commit, per the
        // WR-06 dispatch-after-commit contract.
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

    // ── Split editor actions (Phase 13.1 Plan 05) ────────────────────────

    /**
     * Open the split editor for a not-yet-split transaction. Seeds exactly
     * 2 in-memory legs — leg 0 = current category + full parent amount,
     * leg 1 = blank/€0,00 — and persists NOTHING (D-03a). No-op if the
     * transaction is already split (its legs are already loaded by
     * `loadSplitState()` at mount time).
     */
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

    /** Appends a blank leg (amount €0,00, no category) — in-memory only. */
    public function addLeg(): void
    {
        $this->legs[] = ['id' => null, 'categoryId' => null, 'amount' => self::formatAbsAmount(0), 'note' => '', 'tax' => false];
    }

    /**
     * Removes a leg. At ≥3 legs this removes instantly and re-validates
     * remaining. At exactly 2 legs, removing would drop the count to 1 —
     * instead of silently collapsing, this routes to the same two-step
     * confirm as "Unsplit transaction" (§8.2), scoped to the leg that
     * would become the sole survivor.
     */
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

    /** Dismisses the remove-to-one-leg confirm without changes ("Keep this category"). */
    public function cancelRemoveToOne(): void
    {
        $this->confirmRemoveToOne = false;
        $this->pendingRemoveIndex = null;
    }

    /**
     * Confirms the remove-to-one-leg collapse: the OTHER leg (not the one
     * the user clicked remove on) becomes the surviving category via
     * `SavesTransactionSplit::unsplit()` (§8.2, Req 8).
     */
    public function confirmRemoveToOneAction(SavesTransactionSplit $splitter, CurrentUser $currentUser): void
    {
        if ($this->pendingRemoveIndex === null || count($this->legs) !== 2) {
            return;
        }

        // WR-02: a never-persisted editor (opened via openSplitEditor, nothing
        // saved) has no split to reverse — backing out must be a pure
        // in-memory collapse, NOT a durable category write + op-log entry.
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

    /**
     * Opens the "Unsplit transaction" two-step confirm (§8.1). Defaults the
     * survivor radio to the larger-magnitude leg — a Claude's-discretion
     * default flagged as revisable in the UI-SPEC, surfaced here as a
     * pre-selected (not locked) radio choice.
     */
    public function unsplit(): void
    {
        if ($this->legs === []) {
            return;
        }

        $this->unsplitSurvivorIndex = $this->largestMagnitudeLegIndex();
        $this->confirmUnsplit = true;
    }

    /** Flips the radio-selected survivor before confirming (§8.1). */
    public function selectUnsplitSurvivor(int $index): void
    {
        if (array_key_exists($index, $this->legs)) {
            $this->unsplitSurvivorIndex = $index;
        }
    }

    /** Dismisses the unsplit confirm without changes ("Keep split"). */
    public function cancelUnsplit(): void
    {
        $this->confirmUnsplit = false;
        $this->unsplitSurvivorIndex = null;
    }

    /** Confirms "Yes, unsplit" — collapses to the radio-selected survivor category. */
    public function confirmUnsplitAction(SavesTransactionSplit $splitter, CurrentUser $currentUser): void
    {
        if ($this->unsplitSurvivorIndex === null || ! array_key_exists($this->unsplitSurvivorIndex, $this->legs)) {
            return;
        }

        // WR-02: never-persisted editor — collapse purely in memory, no
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

    /**
     * Saves the split. Re-validates server-side (T-13.1-12 — the disabled
     * Save button is a UX gate only, never authoritative): recomputes
     * `remainingMinor` fresh, rejects any leg that fails to parse (covers
     * both a literal €0,00 entry and a non-numeric/negative one — the
     * absolute-amount-only input can never itself produce an opposite-sign
     * value, so Req 7's sign guard is enforced structurally here and
     * defensively inside `SaveTransactionSplit`, Plan 01) or carries no
     * category, then delegates to the sole mutator.
     */
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

    /**
     * Toggles the tax-deductible state of one leg via the leg-aware
     * TagTransaction/UntagTransaction (Req 5, D-06a). A no-op for a leg
     * that has not yet been persisted (no `id`) — leg-scoped tax tagging
     * requires an existing `transaction_splits` row (T-13.1-09's
     * leg-ownership check).
     */
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

        // D-08 reconciled lock: warn-first, no write (WR-01). Tax tags on a
        // reconciled transaction's legs are exactly the classification a
        // reconcile is meant to freeze. Status read is scoped by user_id.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->value('status');

        if ($status === 'reconciled') {
            $this->dispatch('toast', message: 'This transaction is reconciled. Un-reconcile it to make changes.');

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

    /**
     * Livewire lifecycle hook: recomputes the server-truthful remaining
     * figure whenever a leg amount changes (UI-SPEC §12 — no client math).
     * Fires for any `legs.{index}.amount` mutation regardless of the
     * `wire:model.live.debounce.300ms` binding path.
     */
    public function updated(string $name, mixed $value, CurrentUser $currentUser, DatabaseManager $db): void
    {
        if (str_starts_with($name, 'legs.') && str_ends_with($name, '.amount')) {
            $this->recomputeRemaining($currentUser, $db);
        }
    }

    /**
     * Loads existing `transaction_splits` rows (if any) into `$this->legs`
     * and sets `hasPersistedSplit`/`editingSplit` accordingly. Called at
     * mount and after every successful `saveSplit()` so leg ids stay in
     * sync with the DB for subsequent edit diffs.
     */
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

            // CRYPT-01 (D-02b) read-side decrypt — pass-through no-op
            // when encryption is not enabled for this user.
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

    /**
     * Server-truthful "remaining to assign" (UI-SPEC §12): re-reads the
     * parent's settled amount and re-sums every leg's parsed absolute
     * amount, both via the Money VO — never client-supplied math. Sets
     * `abs(parent) - sum(abs(legs))`: positive = still to assign, negative
     * = over-allocated, zero = exact.
     */
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

    /** Resets the editor back to the not-yet-split empty state (§7.2). */
    private function resetSplitEditor(): void
    {
        $this->editingSplit = false;
        $this->hasPersistedSplit = false;
        $this->legs = [];
        $this->remainingMinor = 0;
        $this->splitError = null;
    }

    /** Index of the leg with the largest absolute (parsed) amount — ties keep the first. */
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

    /**
     * Normalises a leg's `categoryId` (which may arrive as a string or `''`
     * from a Livewire `<select>` binding) to `?int`.
     *
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

    /**
     * Parse a user-entered absolute amount into positive integer minor
     * units, or null if invalid/zero/negative. Handles the Dutch grouped
     * form "1.234,56" and the plain forms "1234.56" / "50,00" / "50".
     *
     * COPIED (adapted) from `Modules\Pots\Public\Services\PotWriter::parseAmount()`
     * (itself copied verbatim from GoalWriter) — same duplication
     * convention, not re-implemented differently. Do not hand-roll a new
     * regex; keep in sync if the canonical copy ever changes.
     */
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

    /**
     * Formats an absolute minor-unit amount as a Dutch-decimal display
     * string ("1234,56") for pre-filling a leg's amount input — integer-only
     * arithmetic (intdiv/modulo), never float division, per the project's
     * no-float-money rule.
     */
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

        // Eager-load the resolved counterparty + category so the Blade can
        // render both the click-through anchor to counterparties.profile
        // and the split section's empty-state category name without a
        // second query. The counterparty relation is NULL when the row
        // carries no counterparty_id (pre-resolver history, pathological
        // rows the resolver couldn't materialise) — the Blade falls back
        // to plain-text rendering in that case.
        $transaction = Transaction::query()
            ->with(['counterparty', 'category'])
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->firstOrFail();

        // CRYPT-01 (D-02b) read-side decrypt — the headline
        // counterparty_name is ciphertext at rest once encryption is
        // enabled; pass-through no-op otherwise. Assigned back onto the
        // in-memory model attribute only (never re-saved), so the Blade
        // ($transaction->counterparty_name) renders plaintext.
        if (is_string($transaction->counterparty_name)) {
            $transaction->counterparty_name = $codec->decryptValue(
                'transactions',
                'counterparty_name',
                $transaction->counterparty_name,
                $userId,
                $session,
            )['value'];
        }

        // Split editor gate (Req 6, D-07/D-08): offered for any non-transfer
        // type. The category options list is only loaded when needed —
        // transfer detail pages never render the Split section at all.
        $isSplittable = SplittableTypes::contains($transaction->type);
        $splitCategories = $isSplittable ? $categoryOptions->for($currentUser->user()) : [];

        // Chain drill-down drawer: gate the "View chain" button on
        // whether this transaction actually participates in any
        // chain_link (either side, non-rejected state). Previously
        // the button rendered unconditionally — every transaction
        // showed it, including rows with zero chain coverage, and
        // the drawer would just say "No funding chain found". The
        // gate moves that signal to the row level where the user
        // can see it before clicking.
        $chainAvailable = $chainQuery->hasChainForTransaction(
            $this->transactionId,
            $currentUser->user(),
        );

        // Batch-load tax state for this single transaction (Pitfall 1 — reuses same path).
        $taxState = $this->taxTagStateFor([$this->transactionId], $taxTagQuery, $currentUser);
        $txTaxRow = [
            'id' => $this->transactionId,
            'taxTagged' => $taxState[$this->transactionId]['taxTagged'] ?? false,
            'taxCategoryShortName' => $taxState[$this->transactionId]['taxCategoryShortName'] ?? null,
        ];

        // Batch-load cleared status for this single transaction (SC-1, Pitfall 1 — same path).
        $clearedState = $this->clearedStatusFor([$this->transactionId], $db, $currentUser);
        $clearedStatus = $clearedState[$this->transactionId] ?? 'cleared';

        // Load the user's counterparties for the reassignment picker.
        // Only user-owned rows — WHERE user_id is mandatory (Pitfall 4).
        // CRYPT-01 (D-02b) read-side decrypt: display_name is ciphertext
        // at rest once encryption is enabled, so the DB-level ORDER BY
        // above would sort by ciphertext — decrypt first, then re-sort in
        // PHP so the dropdown stays alphabetical either way.
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
