<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\CounterpartyKey;
use stdClass;

final readonly class RuleEvaluator
{
    public function __construct(private DatabaseManager $db) {}

    // The JOIN matches on the derived `normalized_name` key, which is not a
    // sensitive column, so no decrypt is needed even for an encrypted user.
    public function lookupMemory(CanonicalTransaction $tx, int $userId): ?stdClass
    {
        $normalized = $tx->counterpartyNormalized;
        if ($normalized === '' || $normalized === CounterpartyKey::NONE) {
            return null;
        }

        /** @var stdClass|null $row */
        $row = $this->db->connection()
            ->table('merchant_memories as mm')
            ->join('merchants as m', static function (JoinClause $join) use ($userId, $normalized): void {
                $join->on('mm.merchant_id', '=', 'm.id')
                    ->where('m.user_id', '=', $userId)
                    ->where('m.normalized_name', '=', $normalized);
            })
            ->where('mm.user_id', $userId)
            // Recency first: on the count alone a fresh correction at 1 could
            // never beat an old memory at 18, so the reader got the wrong
            // category back -- and no divergence toast, because AssignCategory
            // documents that memory relearns on its own. The id breaks ties.
            ->orderByDesc('mm.last_seen_at')
            ->orderByDesc('mm.occurrence_count')
            ->orderByDesc('mm.id')
            ->first(['mm.id', 'mm.category_id', 'mm.occurrence_count']);

        return $row;
    }
}
