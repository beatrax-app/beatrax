<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Sync\Public\Events\PeerRowsApplied;

// A pair is two columns on two rows and nothing in the schema holds them
// together: each merges on its own clock, so a retype that let go of one leg
// while a peer still held the other lands a half pair. The leg left naming a
// partner is counted by no flow and is invisible to the orphan sweep.
/**
 * @link ../../../../.docs/features/transfers/architecture.md#a-pair-only-one-side-let-go-of
 */
final readonly class ClearHalfPairsOnMergedRows
{
    use CoercesScalars;

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

        $dangling = $this->danglingAmong($touched, $event->userId);

        if ($dangling === []) {
            return;
        }

        // whereIn, because this is a set derived from the merge rather than a
        // row a reader named: the reconciled lock is a rule about one reader's
        // assertion, and refusing the repair there would strand the row.
        $this->db->connection()->table('transactions')
            ->where('user_id', $event->userId)
            ->whereIn('id', $dangling)
            ->update(['pair_transaction_id' => null]);
    }

    // Both ends, because the two devices hear about the break from opposite
    // sides: the one that received the link sees the naming leg, the one that
    // received the retype sees only the row being named. Reading one end alone
    // repairs on one device and leaves the other holding the half pair.
    /**
     * @param  list<int|string>  $touched
     * @return list<int>
     */
    private function danglingAmong(array $touched, int $userId): array
    {
        $legs = [];

        $naming = $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->whereNotNull('pair_transaction_id')
            ->where(static function (Builder $query) use ($touched): void {
                $query->whereIn('id', $touched)->orWhereIn('pair_transaction_id', $touched);
            })
            ->get(['id', 'pair_transaction_id']);

        foreach ($naming as $row) {
            $legs[self::toInt($row->id ?? null)] = self::toInt($row->pair_transaction_id ?? null);
        }

        if ($legs === []) {
            return [];
        }

        $partners = $this->partnersById(array_values(array_unique(array_values($legs))), $userId);

        $dangling = [];

        foreach ($legs as $legId => $partnerId) {
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
