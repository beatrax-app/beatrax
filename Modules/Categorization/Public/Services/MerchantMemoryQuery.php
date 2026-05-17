<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use DateTimeImmutable;
use Exception;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Categorization\Public\Dto\MerchantMemoryDto;
use Modules\Core\Models\User;

/**
 * Read-side projection of merchant_memories. Used by the correction-
 * divergence drawer panel ("Auto-categorized from merchant history")
 * and as a reference query for RuleEvaluator (which inlines the same
 * JOIN shape for the hot-path import lookup).
 *
 * Every read scopes by `user_id` so a foreign user's memory never
 * surfaces.
 *
 * `latestForCounterpartyNormalized` JOINs the merchants table on
 * (user_id, normalized_name) because CanonicalTransaction +
 * transactions carry no merchant_id column — merchant identity is
 * derived from counterparty_normalized at query time. The merchants
 * table enforces UNIQUE (user_id, normalized_name) so the join yields
 * at most one merchant row per normalized_name.
 */
final readonly class MerchantMemoryQuery
{
    public function __construct(private DatabaseManager $db) {}

    public function latestForCounterpartyNormalized(User $user, string $counterpartyNormalized): ?MerchantMemoryDto
    {
        $userId = $user->id;
        if ($counterpartyNormalized === '') {
            return null;
        }

        $row = $this->db->connection()
            ->table('merchant_memories as mm')
            ->join('merchants as m', static function (JoinClause $join) use ($userId, $counterpartyNormalized): void {
                $join->on('mm.merchant_id', '=', 'm.id')
                    ->where('m.user_id', '=', $userId)
                    ->where('m.normalized_name', '=', $counterpartyNormalized);
            })
            ->where('mm.user_id', $userId)
            ->orderByDesc('mm.occurrence_count')
            ->first(['mm.id', 'mm.category_id', 'mm.occurrence_count', 'mm.last_seen_at']);

        if ($row === null) {
            return null;
        }

        $lastSeen = null;
        if (isset($row->last_seen_at) && is_string($row->last_seen_at) && $row->last_seen_at !== '') {
            try {
                $lastSeen = new DateTimeImmutable($row->last_seen_at);
            } catch (Exception) {
                $lastSeen = null;
            }
        }

        return new MerchantMemoryDto(
            memoryId: self::toInt($row->id),
            categoryId: self::toInt($row->category_id),
            occurrenceCount: self::toInt($row->occurrence_count),
            lastSeenAt: $lastSeen,
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
