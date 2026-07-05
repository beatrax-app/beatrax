<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use stdClass;

/**
 * Memory-only fallback lookup (demoted from its original rule-scoring
 * role by Plan 06 — `RuleEngine` + `RuleApplier` now own ALL rule
 * matching/application against the categorization-rules parent +
 * child tables; see those classes' docblocks). `lookupMemory()` is
 * the sole surviving responsibility:
 * it pulls the highest-`occurrence_count` `merchant_memories` row for
 * the canonical row's merchant, for `ApplyAutoCategoryStage` to apply
 * ONLY when none of the fired rules carried a `category` action
 * (RESEARCH Pattern 4).
 *
 * Reads are scoped by `where('user_id', $user->id)` on every query so
 * a foreign user's memory never fires for the current user.
 *
 * Merchant memory lookup derives `merchant_id` by JOINing the
 * merchants table on (user_id, normalized_name = counterpartyNormalized).
 * CanonicalTransaction does NOT carry a merchantId property — adding
 * one would ripple through every ingestion adapter + NormalizeStage
 * + tests. The merchants table enforces UNIQUE (user_id,
 * normalized_name) so the join yields at most one merchant per
 * normalized_name. When the transaction's counterpartyNormalized is
 * the empty-counterparty sentinel (see NormalizeStage::NO_COUNTERPARTY)
 * the memory lookup is skipped.
 */
final class RuleEvaluator
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Pulls the merchant_memories row for the canonical row's merchant
     * (derived via the merchants JOIN on user_id + normalized_name).
     * Returns null when:
     *  - the counterpartyNormalized is the empty-counterparty sentinel
     *    (NormalizeStage::NO_COUNTERPARTY), OR
     *  - no merchants row exists for (user_id, normalized_name), OR
     *  - no merchant_memories row exists for the merchant.
     *
     * When multiple memories exist for the same merchant (rare —
     * usually one row per (user, merchant, category) tuple) the highest
     * occurrence_count wins.
     */
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
