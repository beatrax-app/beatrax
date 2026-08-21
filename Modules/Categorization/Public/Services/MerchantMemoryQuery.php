<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use DateTimeImmutable;
use Exception;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Categorization\Public\Dto\MerchantMemoryDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;

// Joins merchants on (user_id, normalized_name) because transactions carry no
// merchant_id: identity is derived from counterparty_normalized at query time.
final readonly class MerchantMemoryQuery
{
    use CoercesScalars;

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
                // orderByDesc(occurrence_count) makes the first row per name
                // the winner; the rest are shadow entries, dropped on purpose.
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
}
