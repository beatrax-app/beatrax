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

// Overriding a memory-provenance category does not dispatch
// CategorizationDiverged: merchant memory relearns on its own, so there is
// nothing to ask the user to confirm.
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

            // The sole entry point for a user-driven assignment: the rule
            // engine writes through UpdatesTransactionCategory directly.
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

    // Static, with the connection passed in, so Ledger's TransactionDetail can
    // reuse it without crossing the module boundary.
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

        // Best-effort audit metadata: a corrupt payload must not crash a
        // reclassify, so it falls through to the no-provenance path.
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
