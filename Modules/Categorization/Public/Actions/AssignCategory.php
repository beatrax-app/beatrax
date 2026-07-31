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

// Routes the write through Ledger's UpdatesTransactionCategory action so
// Ledger remains the only mutator of `transactions`. Memory-provenance
// overrides do NOT dispatch CategorizationDiverged — memory grows
// automatically via MerchantMemoryWriter, so no confirmation is needed.
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

            // Only user-driven category edits enter the op-log;
            // import-pipeline writes stay immutable.
            $this->events->dispatch(new TransactionMutated(
                transactionId: $transactionId,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: ['category_id' => $categoryId],
            ));

            // This is the sole manual entry point for user-driven category
            // assignment, so every successful write here is a 'manual'
            // stamp — the rule engine writes categories through
            // UpdatesTransactionCategory directly, never through here.
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

    // Static + DatabaseManager argument so the same helper is available
    // to TransactionDetail (Ledger) without crossing the
    // Ledger-Categorization boundary or duplicating the read shape.
    /**
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

        // auto_category_provenance is best-effort audit metadata — a
        // corrupt JSON payload must NOT crash a reclassify request, so a
        // JsonException falls through to the no-prior-provenance path the
        // is_array guard below already owns.
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
