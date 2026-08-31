<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use JsonException;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Sync\Public\Events\TransactionMutated;

final readonly class AssignCategory implements AssignsCategory
{
    public function __construct(
        private UpdatesTransactionCategory $updater,
        private Dispatcher $events,
        private DatabaseManager $db,
        private FieldProvenanceWriter $provenance,
    ) {}

    public function __invoke(int $transactionId, ?int $categoryId, User $user): int
    {
        $affected = ($this->updater)($transactionId, $categoryId, $user);

        // Rows affected answers "did a column change", not "did the reader
        // decide this". Agreeing with the rule and picking the category it
        // already chose changed nothing, so the decision went unstamped and
        // the next edit of that rule overwrote it.
        if ($affected === 0 && ! $this->confirmsStoredCategory($transactionId, $categoryId, $user)) {
            return $affected;
        }

        $this->events->dispatch(new TransactionCategorized(
            transactionId: $transactionId,
            categoryId: $categoryId,
            userId: $user->id,
        ));

        // Only user-driven category edits enter the op-log; import-pipeline
        // writes stay immutable. A confirmation moved no column, so there is
        // no value for a peer to converge on.
        if ($affected > 0) {
            $this->events->dispatch(new TransactionMutated(
                transactionId: $transactionId,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: ['category_id' => $categoryId],
            ));
        }

        // The sole entry point for a user-driven assignment: the rule
        // engine writes through UpdatesTransactionCategory directly.
        $this->provenance->stamp($user->id, $transactionId, ['category_id' => 'manual']);

        return $affected;
    }

    // A zero that means "the column already says this" rather than "the write
    // was refused". A missing or foreign row and one locked against edits are
    // both refusals, and neither is a decision the reader gets credit for.
    private function confirmsStoredCategory(int $transactionId, ?int $categoryId, User $user): bool
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['status', 'category_id']);

        if ($row === null || TransactionStatusQuery::locksEdits($row->status)) {
            return false;
        }

        return (is_numeric($row->category_id) ? (int) $row->category_id : null) === $categoryId;
    }

    // Static, with the connection passed in, so CategorizationProvenancePanel
    // reads the column through this decoder instead of re-deriving the payload
    // shape on the render side.
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

        // Best-effort audit metadata: a corrupt payload must not crash the
        // page reading it, so it falls through to the no-provenance path.
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
