<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use JsonException;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Events\CategorizationDiverged;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Sync\Public\Events\TransactionMutated;

/**
 * Default implementation of the Categorization Public `AssignsCategory`
 * contract. Routes the write through Ledger's `UpdatesTransactionCategory`
 * action so Ledger remains the only mutator of `transactions`, and fires
 * the `TransactionCategorized` event after a successful write so other
 * modules can react without coupling.
 *
 * Divergence detection: BEFORE invoking the Ledger updater, the action
 * reads the row's existing `auto_category_provenance` (user-scoped).
 * After a successful write, if the prior provenance had source='rule'
 * AND the new categoryId differs from the rule's category_id AND the
 * provenance carried a non-null rule_id, dispatch
 * `CategorizationDiverged` so the CorrectionDivergenceToast SFC can
 * offer the user the choice between Update rule / Keep current rule.
 *
 * Memory-provenance overrides do NOT dispatch CategorizationDiverged
 * — memory grows automatically via MerchantMemoryWriter on every
 * TransactionCategorized event, so a memory-driven suggestion that the
 * user overrides updates memory transparently without a confirmation
 * surface.
 */
final class AssignCategory implements AssignsCategory
{
    public function __construct(
        private readonly UpdatesTransactionCategory $updater,
        private readonly Dispatcher $events,
        private readonly DatabaseManager $db,
        private readonly FieldProvenanceWriter $provenance,
    ) {}

    public function __invoke(int $transactionId, ?int $categoryId, User $user): int
    {
        $priorProvenance = self::readPriorProvenance($this->db, $transactionId, $user->id);

        $affected = ($this->updater)($transactionId, $categoryId, $user);

        if ($affected > 0) {
            $this->events->dispatch(new TransactionCategorized(
                transactionId: $transactionId,
                categoryId: $categoryId,
                userId: $user->id,
            ));

            // Hand-wired capture emission (D-02): only user-driven category edits
            // enter the op-log. Import-pipeline writes stay immutable.
            $this->events->dispatch(new TransactionMutated(
                transactionId: $transactionId,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: ['category_id' => $categoryId],
            ));

            // D-04 (Req 4 — manual-preservation): this action is the
            // sole manual entry point for user-driven category
            // assignment (reclassifyCategory + every InlineCategoryPicker
            // caller route through AssignsCategory), so every successful
            // write here is a 'manual' stamp — never invoked by the
            // Plan 05 rule engine, which writes categories through
            // UpdatesTransactionCategory directly.
            $this->provenance->stamp($user->id, $transactionId, ['category_id' => 'manual']);

            if ($categoryId !== null) {
                $divergence = CategorizationDiverged::fromProvenance(
                    priorProvenance: $priorProvenance,
                    transactionId: $transactionId,
                    newCategoryId: $categoryId,
                    userId: $user->id,
                );
                if ($divergence !== null) {
                    $this->events->dispatch($divergence);
                }
            }
        }

        return $affected;
    }

    /**
     * Reads transactions.auto_category_provenance (already cast as an
     * array by the Eloquent model) via the raw query builder so callers
     * stay decoupled from the model's casting layer.
     *
     * Static + DatabaseManager argument so the same helper is
     * available to TransactionDetail (Ledger) without crossing the
     * Ledger-Categorization boundary or duplicating the read shape.
     *
     * @return array<string, mixed>|null
     */
    public static function readPriorProvenance(DatabaseManager $db, int $transactionId, int $userId): ?array
    {
        $raw = $db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('auto_category_provenance');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // The auto_category_provenance column is best-effort audit
        // metadata — a corrupt JSON payload must NOT crash a reclassify
        // request. JSON_THROW_ON_ERROR matches the project-wide
        // json_decode convention (ApplyEnrichments, ApplyReceiptConflictResolution);
        // the JsonException catch returns null so the caller falls
        // back to the no-prior-provenance code path.
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
