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
 * divergence drawer panel ("Auto-categorized from merchant history"),
 * as a reference query for RuleEvaluator (which inlines the same JOIN
 * shape for the hot-path import lookup), and as the batch read API
 * downstream analytical modules call to decorate row sets with the
 * user-assigned category for a counterparty.
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
 *
 * `forCounterpartiesNormalized` is the bulk variant — it accepts a
 * list of normalized counterparty names and returns one row per name
 * via a single SQL query (whereIn on the normalized_name JOIN). Missing
 * names are simply absent from the returned map so callers can default
 * via the `??` operator.
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

    /**
     * Bulk read variant — returns a map of normalized counterparty name
     * to MerchantMemoryDto for the given user. Names with no memory row
     * are omitted from the returned map (no nulled placeholder). Empty
     * strings in the input list are skipped.
     *
     * Executes a single SQL query regardless of input list size.
     *
     * @param  list<string>  $counterpartyNormalizedList
     * @return array<string, MerchantMemoryDto>
     */
    public function forCounterpartiesNormalized(User $user, array $counterpartyNormalizedList): array
    {
        $names = array_values(array_filter(
            $counterpartyNormalizedList,
            static fn (string $name): bool => $name !== '',
        ));
        if ($names === []) {
            return [];
        }

        $userId = $user->id;

        $rows = $this->db->connection()
            ->table('merchant_memories as mm')
            ->join('merchants as m', static function (JoinClause $join) use ($userId, $names): void {
                $join->on('mm.merchant_id', '=', 'm.id')
                    ->where('m.user_id', '=', $userId)
                    ->whereIn('m.normalized_name', $names);
            })
            ->where('mm.user_id', $userId)
            ->orderByDesc('mm.occurrence_count')
            ->get(['mm.id', 'mm.category_id', 'mm.occurrence_count', 'mm.last_seen_at', 'm.normalized_name']);

        $map = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $normalized = isset($row->normalized_name) && is_string($row->normalized_name)
                ? $row->normalized_name
                : null;
            if ($normalized === null || isset($map[$normalized])) {
                // `orderByDesc(occurrence_count)` makes the first row per
                // name the winner; subsequent rows for the same name are
                // shadow entries (different category_id with lower count)
                // and are dropped on purpose so the returned shape stays
                // one-DTO-per-name.
                continue;
            }

            $lastSeen = null;
            if (isset($row->last_seen_at) && is_string($row->last_seen_at) && $row->last_seen_at !== '') {
                try {
                    $lastSeen = new DateTimeImmutable($row->last_seen_at);
                } catch (Exception) {
                    $lastSeen = null;
                }
            }

            $map[$normalized] = new MerchantMemoryDto(
                memoryId: self::toInt($row->id),
                categoryId: self::toInt($row->category_id),
                occurrenceCount: self::toInt($row->occurrence_count),
                lastSeenAt: $lastSeen,
            );
        }

        return $map;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
