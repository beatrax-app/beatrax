<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

// The coordinates of transactions whose index body could not be built, and
// nothing else: which row, whose, and when it was asked for. It carries no
// ledger content, because the content is exactly what the process that
// enqueued the row could not read.
/**
 * @link ../../../../.docs/features/search/architecture.md#a-column-this-process-cannot-read
 */
final readonly class SearchIndexRepairQueue
{
    // One pass does not have to finish the queue; what it leaves, the next one
    // takes. The bound is what keeps a whole-ledger repair off one request.
    public const int DRAIN_LIMIT = 500;

    public function __construct(private DatabaseManager $db) {}

    public function request(int $userId, int $transactionId, string $now): void
    {
        $this->db->connection()->table('search_index_repairs')->updateOrInsert(
            ['user_id' => $userId, 'transaction_id' => $transactionId],
            ['requested_at' => $now],
        );
    }

    public function retire(int $userId, int $transactionId): void
    {
        $this->db->connection()->table('search_index_repairs')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->delete();
    }

    // The gate the recovery pass asks on every request, so it must answer false
    // once this keyring has had its turn — a column sealed to an epoch whose
    // wrap never arrived is not repairable now and will not become so until key
    // material moves.
    public function hasWork(int $userId, ?string $keyringFingerprint): bool
    {
        return $this->unanswered($userId, $keyringFingerprint)->exists();
    }

    // Every account on the device, for the health probe: the reader asking it
    // is asking about the index, and an index missing another household
    // member's rows is missing rows.
    public function owedTotal(): int
    {
        $connection = $this->db->connection();

        return $connection->getSchemaBuilder()->hasTable('search_index_repairs')
            ? $connection->table('search_index_repairs')->count()
            : 0;
    }

    /**
     * @return list<int>
     */
    public function claim(int $userId, ?string $keyringFingerprint, int $limit): array
    {
        $ids = [];

        $rows = $this->unanswered($userId, $keyringFingerprint)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('transaction_id');

        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    // Stamps the keyring a pass could not open these rows under, and answers
    // how many of them are still owed — the same question, because a row the
    // pass rebuilt was retired by the writer and is no longer here to stamp.
    /**
     * @param  list<int>  $transactionIds
     */
    public function markAnswered(int $userId, array $transactionIds, ?string $keyringFingerprint): int
    {
        if ($transactionIds === []) {
            return 0;
        }

        return $this->db->connection()->table('search_index_repairs')
            ->where('user_id', $userId)
            ->whereIn('transaction_id', $transactionIds)
            ->update(['failed_fingerprint' => $keyringFingerprint]);
    }

    private function unanswered(int $userId, ?string $keyringFingerprint): Builder
    {
        $query = $this->db->connection()->table('search_index_repairs')->where('user_id', $userId);

        // A device with no keyring file to hash cannot bound the retry, and
        // asking again is the safe half of that: the pass only runs where a key
        // is held, so this is the state that does not occur.
        return $keyringFingerprint === null
            ? $query
            : $query->where(static function (Builder $unanswered) use ($keyringFingerprint): void {
                $unanswered->whereNull('failed_fingerprint')
                    ->orWhere('failed_fingerprint', '!=', $keyringFingerprint);
            });
    }
}
