<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\RowChunk;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Sync\Public\Events\PeerRowsApplied;
use stdClass;

// A pair is two columns on two rows and the schema holds only the foreign key.
// Each merges on its own clock, so a retype that let go of one leg while a peer
// still held the other lands a half pair. The leg left naming a partner is
// counted by no flow and is invisible to the orphan sweep.
/**
 * @link ../../../../.docs/features/transfers/architecture.md#a-pair-only-one-side-let-go-of
 */
final readonly class ClearHalfPairsOnMergedRows
{
    use CoercesScalars;

    // A rebuild announces every row it re-creates, so the arriving ids and the
    // rows naming them are both the whole ledger on a phone under a 128 MB
    // ceiling — and one whereIn over them would pass SQLite's bind ceiling too.
    private const int CHUNK = RowChunk::DEFAULT_SIZE;

    public function __construct(private DatabaseManager $db) {}

    public function handle(PeerRowsApplied $event): void
    {
        $touched = [
            ...$event->created['transactions'] ?? [],
            ...$event->updated['transactions'] ?? [],
        ];

        if ($touched === []) {
            return;
        }

        $dangling = [];

        foreach (array_chunk($touched, self::CHUNK) as $batch) {
            $this->collectDangling($batch, $event->userId, $dangling);
        }

        // Written only once every verdict is in. Each is read off the state the
        // merge left, so a leg cleared early cannot change the answer for a leg
        // naming it, and two devices repair the same rows from the same state.
        foreach (array_chunk(array_values($dangling), self::CHUNK) as $batch) {
            $this->db->connection()->table('transactions')
                ->where('user_id', $event->userId)
                ->whereIn('id', $batch)
                ->update(['pair_transaction_id' => null]);
        }
    }

    // Both ends, because the two devices hear about the same break from
    // opposite sides: the one that received the link sees the naming leg, the
    // one that received the retype sees only the row being named. Reading one
    // end alone repairs one device and leaves the other half paired.
    /**
     * @param  list<int|string>  $batch
     * @param  array<int, int>  $dangling
     */
    private function collectDangling(array $batch, int $userId, array &$dangling): void
    {
        $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->whereNotNull('pair_transaction_id')
            ->where(static function (Builder $query) use ($batch): void {
                $query->whereIn('id', $batch)->orWhereIn('pair_transaction_id', $batch);
            })
            ->select(['id', 'pair_transaction_id'])
            ->chunkById(self::CHUNK, function (Collection $legs) use ($userId, &$dangling): void {
                foreach ($this->danglingIn($legs, $userId) as $legId) {
                    $dangling[$legId] = $legId;
                }
            });
    }

    /**
     * @param  Collection<int, stdClass>  $legs
     * @return list<int>
     */
    private function danglingIn(Collection $legs, int $userId): array
    {
        $named = [];

        foreach ($legs as $row) {
            $named[self::toInt($row->id ?? null)] = self::toInt($row->pair_transaction_id ?? null);
        }

        $partners = $this->partnersById(array_values(array_unique(array_values($named))), $userId);

        $dangling = [];

        foreach ($named as $legId => $partnerId) {
            $partner = $partners[$partnerId] ?? null;

            if ($partner !== null && self::cannotNameBack($partner, $legId)) {
                $dangling[] = $legId;
            }
        }

        return $dangling;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{pair: int|null, type: string}>
     */
    private function partnersById(array $ids, int $userId): array
    {
        $partners = [];

        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->get(['id', 'type', 'pair_transaction_id']);

        foreach ($rows as $row) {
            $partners[self::toInt($row->id ?? null)] = [
                'pair' => is_numeric($row->pair_transaction_id ?? null)
                    ? self::toInt($row->pair_transaction_id)
                    : null,
                'type' => self::toString($row->type ?? null),
            ];
        }

        return $partners;
    }

    // Only a partner that demonstrably cannot name this leg back. A partner
    // still typed as a transfer with an empty link wears the same shape as a
    // link the next batch is about to deliver, and clearing on that would
    // unpair a healthy pair whose two Sets straddled a batch boundary.
    /**
     * @param  array{pair: int|null, type: string}  $partner
     */
    private static function cannotNameBack(array $partner, int $legId): bool
    {
        if ($partner['pair'] === $legId) {
            return false;
        }

        return $partner['pair'] !== null
            || ! in_array($partner['type'], TransactionType::transferValues(), true);
    }
}
