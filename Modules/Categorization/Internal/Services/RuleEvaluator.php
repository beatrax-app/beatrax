<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Models\User;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use stdClass;

/**
 * Evaluates the user's CategorizationRule rows + merchant_memories
 * against an incoming CanonicalTransaction and returns the highest-
 * scoring candidate per the D-711 specificity scoring algorithm:
 *
 *   equals       = 100
 *   memory       =  90
 *   starts_with  =  50 + length(value)
 *   contains     =  10 + length(value)
 *
 * Tiebreaker: rule beats memory at equal score. Practical equality
 * happens only between a rule and a memory at the same score — the
 * loop evaluates rule candidates before the memory candidate so a
 * rule with score 90 wins over a memory at score 90.
 *
 * Reads are scoped by `where('user_id', $user->id)` on every query
 * so a foreign user's rule or memory never fires for the current
 * user — the T-07-09 cross-user mitigation.
 *
 * Case-insensitive string comparisons use the `mb_*` family so
 * Unicode characters (German umlauts, Spanish accents) compare
 * correctly. The match operators are evaluated in PHP rather than
 * via SQL LIKE so the rule.value never reaches the SQL string —
 * the T-07-05 SQL-injection mitigation.
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
 *
 * The rule pull rides the (user_id, active) composite index added by
 * the plan 01 migration. v1 acceptable cost is <50 active rules per
 * user; a v2 "bulk import/export" capability that yields hundreds of
 * rules per user would warrant a per-field index or pre-filtering by
 * field value at the SQL boundary. Out of scope for v1.
 */
final class RuleEvaluator
{
    /**
     * Allowed `field` enum values — must mirror the DB trigger
     * allow-list on categorization_rules.field. The mapping to
     * canonical-transaction properties happens inside evaluate().
     */
    private const VALID_FIELDS = ['merchant', 'description', 'counterparty'];

    /** Allowed `match` enum values — must mirror the DB trigger allow-list. */
    private const VALID_MATCHES = ['equals', 'starts_with', 'contains'];

    public function __construct(private readonly DatabaseManager $db) {}

    public function evaluate(CanonicalTransaction $tx, User $user): RuleEvaluationOutcome
    {
        $userId = $user->id;

        $connection = $this->db->connection();

        // Pull every active rule for this user — scoped by user_id +
        // active. The (user_id, active) composite index on
        // categorization_rules makes this an indexed read.
        /** @var iterable<stdClass> $rules */
        $rules = $connection
            ->table('categorization_rules')
            ->where('user_id', $userId)
            ->where('active', true)
            ->get();

        $best = RuleEvaluationOutcome::none();

        foreach ($rules as $rule) {
            $field = is_string($rule->field) ? $rule->field : '';
            $match = is_string($rule->match) ? $rule->match : '';
            $value = is_string($rule->value) ? $rule->value : '';
            $categoryId = self::toInt($rule->category_id);
            $ruleId = self::toInt($rule->id);

            if (! in_array($field, self::VALID_FIELDS, true) || ! in_array($match, self::VALID_MATCHES, true)) {
                continue;
            }

            $target = self::targetFieldValue($tx, $field);
            if ($target === null || $value === '') {
                continue;
            }

            $score = self::scoreMatch($match, $target, $value);
            if ($score === 0) {
                continue;
            }

            if ($score > $best->score) {
                $best = RuleEvaluationOutcome::rule($categoryId, $ruleId, $score);
            }
        }

        // Memory candidate — at score 90. Rule beats memory at equal
        // score, so the strict `>` comparison below ensures a rule at
        // score 90 (which can happen for a memory-bordering match)
        // wins over the memory.
        $memory = $this->lookupMemory($tx, $userId);
        if ($memory !== null) {
            $memoryScore = 90;
            if ($memoryScore > $best->score) {
                $categoryId = self::toInt($memory->category_id);
                $memoryId = self::toInt($memory->id);
                $best = RuleEvaluationOutcome::memory($categoryId, $memoryId, $memoryScore);
            }
        }

        return $best;
    }

    /**
     * Maps the configurable `field` enum to the canonical-transaction
     * property carrying the matchable value. `merchant` and
     * `counterparty` both target counterparty_name — they are user-
     * facing synonyms exposed in the rule-form modal.
     */
    private static function targetFieldValue(CanonicalTransaction $tx, string $field): ?string
    {
        return match ($field) {
            'merchant', 'counterparty' => $tx->counterpartyName,
            'description' => $tx->description,
            default => null,
        };
    }

    /**
     * Returns the D-711 specificity score for the given match operator
     * against the target field value, or 0 when the operator does not
     * match. All comparisons use mb_strtolower for Unicode-safe
     * case-insensitivity.
     */
    private static function scoreMatch(string $match, string $target, string $value): int
    {
        $targetLower = mb_strtolower($target);
        $valueLower = mb_strtolower($value);

        return match ($match) {
            'equals' => $targetLower === $valueLower ? 100 : 0,
            'starts_with' => mb_strpos($targetLower, $valueLower) === 0
                ? 50 + mb_strlen($value)
                : 0,
            'contains' => mb_strpos($targetLower, $valueLower) !== false
                ? 10 + mb_strlen($value)
                : 0,
            default => 0,
        };
    }

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
    private function lookupMemory(CanonicalTransaction $tx, int $userId): ?stdClass
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
