<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use stdClass;

final class RuleEvaluator
{
    public function __construct(private readonly DatabaseManager $db) {}

    // The JOIN matches on the derived `normalized_name` key, which is not a
    // sensitive column, so no decrypt is needed even for an encrypted user.
    public function lookupMemory(CanonicalTransaction $tx, int $userId): ?stdClass
    {
        $normalized = $tx->counterpartyNormalized;
        if ($normalized === '' || $normalized === NormalizeStage::NO_COUNTERPARTY) {
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
            ->orderByDesc('mm.occurrence_count')
            ->first(['mm.id', 'mm.category_id', 'mm.occurrence_count']);

        return $row;
    }
}
