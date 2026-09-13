<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Pots\Public\Enums\PotMovementKind;

// Archiving settles a pot, and a pot's balance is what has moved SINCE it was
// last settled. Summing every movement instead made the figure depend on how
// many devices wrote the settlement.
/**
 * @link ../../../../.docs/features/pots/architecture.md#a-balance-is-what-moved-since-the-last-settlement
 */
final readonly class PotSettlement
{
    public static function lastPerPot(ConnectionInterface $connection, int $userId): Builder
    {
        return $connection->table('pot_movements')
            ->where('user_id', $userId)
            ->where('kind', PotMovementKind::ReleasedOnArchive->value)
            ->groupBy('pot_id')
            ->select([
                'pot_id',
                $connection->raw('MAX(created_at) AS settled_at'),
            ]);
    }

    // The stamp and nothing beside it: `id` is minted from a random draw, so
    // ordering by it is not ordering by time, and the two devices' settlements
    // carry no order between them at all. A movement sharing the settlement's
    // second is read as settled, which is what makes the second one drop out.
    public static function movedSince(Builder $query, string $movements, string $cutoff): void
    {
        $query->where(static function (Builder $since) use ($movements, $cutoff): void {
            $since->whereNull($cutoff.'.settled_at')
                ->orWhereColumn($movements.'.created_at', '>', $cutoff.'.settled_at');
        });
    }
}
