<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Models\RuleAction;
use Modules\Core\Models\User;
use stdClass;

/**
 * Pure multi-condition rule matcher — the single source of truth for
 * "does rule X fire on transaction Y," shared by the import stage
 * (Plan 06) and the re-apply job (Plan 07). `match()` has no side
 * effects: it only reads `categorization_rules` / `rule_conditions` /
 * `rule_actions` and returns a list of firing rules. Application of a
 * matched rule's actions is exclusively Plan 05's job (`RuleApplier`).
 *
 * Three invariants carried forward from `RuleEvaluator` (see that
 * class's docblock for the original rationale):
 *
 *  - Case-insensitive string comparisons use the `mb_*` family so
 *    Unicode characters (German umlauts, Spanish accents) compare
 *    correctly. String condition operators (`contains`, `equals`,
 *    `starts_with`) are evaluated in PHP after a broad
 *    `where('user_id', ...)->where('active', 1)` pull — the
 *    user-authored condition `value` is NEVER pushed into a SQL
 *    pattern-match clause. That is the SQL-injection mitigation for
 *    this untrusted input (T-13.4-04).
 *  - Every rule / condition / action pull is scoped by
 *    `where('user_id', $user->id)` (on the parent pull; conditions and
 *    actions are then scoped by `rule_id` of an already user-scoped
 *    parent) so a foreign user's rule never fires for the current
 *    user (T-13.4-05).
 *  - Match ordering is a deterministic pure function of (rule set,
 *    input): rules are pulled `->orderBy('priority')->orderBy('id')`
 *    at the SQL layer. There is no PHP-side array re-ordering anywhere
 *    in this class — depending on an unordered pull's incidental
 *    return order would break idempotent re-apply / sync convergence
 *    (T-13.4-06).
 */
final class RuleEngine
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Pulls the user's active rules (priority asc, id asc — DB-level,
     * never a PHP sort) and evaluates each rule's condition set against
     * `$tx`. A rule with `combinator = 'all'` fires only when every one
     * of its conditions matches (an empty condition set never fires);
     * `combinator = 'any'` fires when at least one condition matches.
     *
     * @return list<MatchedRule>
     */
    public function match(RuleMatchInput $tx, User $user): array
    {
        $connection = $this->db->connection();

        /** @var iterable<stdClass> $rules */
        $rules = $connection
            ->table('categorization_rules')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'priority', 'combinator']);

        $matched = [];

        foreach ($rules as $rule) {
            $ruleId = self::toInt($rule->id);
            $priority = self::toInt($rule->priority);
            $combinator = $rule->combinator === 'any' ? 'any' : 'all';

            /** @var iterable<stdClass> $conditions */
            $conditions = $connection
                ->table('rule_conditions')
                ->where('rule_id', $ruleId)
                ->orderBy('id')
                ->get();

            $results = [];
            foreach ($conditions as $condition) {
                $results[] = $this->conditionMatches($condition, $tx);
            }

            $fires = $combinator === 'all'
                ? ($results !== [] && ! in_array(false, $results, true))
                : in_array(true, $results, true);

            if ($fires) {
                $matched[] = new MatchedRule($ruleId, $priority, $this->actionsFor($ruleId));
            }
        }

        return $matched;
    }

    /**
     * Reads via the raw query builder (not the Eloquent `RuleAction`
     * builder) to avoid a larastan strict-rules dynamic-call warning
     * on chained builder methods — mirrors the existing
     * `GoalProgressQuery` convention in this codebase. Each row is
     * then hydrated into a real `RuleAction` model so its `payload`
     * JSON cast applies for callers.
     *
     * WR-02: `->orderBy('id')` is a deliberate tiebreak after
     * `->orderBy('position')` — `position` has no uniqueness enforcement
     * at the write layer, so two `rule_actions` rows sharing the same
     * position (reachable via any non-UI caller) would otherwise fold in
     * a DB-incidental order, undermining the same determinism guarantee
     * `match()`'s own docblock requires for rule ordering.
     *
     * @return list<RuleAction>
     */
    private function actionsFor(int $ruleId): array
    {
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()
            ->table('rule_actions')
            ->where('rule_id', $ruleId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $actions = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $attributes */
            $attributes = get_object_vars($row);
            $actions[] = (new RuleAction)->newFromBuilder($attributes);
        }

        return $actions;
    }

    /**
     * Dispatches a single condition row to its `value_type`-specific
     * matcher. `field` only matters for `value_type = 'string'` — the
     * `amount` and `date` value_types always compare against the
     * transaction's canonical `settledAmountMinor` / `postedAt`
     * (RESEARCH Open Question 1, resolved).
     */
    private function conditionMatches(stdClass $condition, RuleMatchInput $tx): bool
    {
        $valueType = is_string($condition->value_type) ? $condition->value_type : '';
        $op = is_string($condition->op) ? $condition->op : '';
        $field = is_string($condition->field) ? $condition->field : '';
        $value = is_string($condition->value) ? $condition->value : '';
        $value2 = is_string($condition->value2) ? $condition->value2 : null;

        return match ($valueType) {
            'string' => self::matchString($op, self::targetFieldValue($tx, $field), $value),
            'amount' => self::matchAmount($op, $tx->settledAmountMinor, self::toIntValue($value), $value2 !== null ? self::toIntValue($value2) : null),
            'date' => self::matchDate($op, $tx->postedAt, $value, $value2),
            default => false,
        };
    }

    /**
     * Maps the configurable `field` enum to the RuleMatchInput property
     * carrying the matchable value. `merchant` and `counterparty` are
     * both user-facing synonyms for the counterparty name.
     */
    private static function targetFieldValue(RuleMatchInput $tx, string $field): ?string
    {
        return match ($field) {
            'merchant', 'counterparty' => $tx->counterpartyName,
            'description' => $tx->description,
            default => null,
        };
    }

    /**
     * String condition matcher. All comparisons use `mb_strtolower` /
     * `mb_strpos` for Unicode-safe, case-insensitive matching — never a
     * SQL pattern-match clause (see class docblock).
     */
    private static function matchString(string $op, ?string $target, string $value): bool
    {
        if ($target === null || $value === '') {
            return false;
        }

        $t = mb_strtolower($target);
        $v = mb_strtolower($value);

        return match ($op) {
            'equals' => $t === $v,
            'starts_with' => mb_strpos($t, $v) === 0,
            'contains' => mb_strpos($t, $v) !== false,
            default => false,
        };
    }

    /** Amount condition matcher — minor-unit integer comparisons. */
    private static function matchAmount(string $op, int $target, int $value, ?int $value2): bool
    {
        return match ($op) {
            '>' => $target > $value,
            '<' => $target < $value,
            'equals' => $target === $value,
            'between' => $value2 !== null
                && $target >= min($value, $value2)
                && $target <= max($value, $value2),
            default => false,
        };
    }

    /**
     * Date condition matcher. `$value`/`$value2` are ISO-8601 date
     * strings; comparisons are calendar-date-level (both sides
     * normalized to start-of-day) so a `postedAt` timestamp later in
     * the same calendar day as an `after` boundary still compares
     * correctly.
     */
    private static function matchDate(string $op, CarbonImmutable $target, string $value, ?string $value2): bool
    {
        $t = $target->startOfDay();
        $v = CarbonImmutable::parse($value)->startOfDay();

        return match ($op) {
            'after' => $t->greaterThan($v),
            'before' => $t->lessThan($v),
            'between' => $value2 !== null && self::withinInclusiveRange($t, $v, CarbonImmutable::parse($value2)->startOfDay()),
            default => false,
        };
    }

    private static function withinInclusiveRange(CarbonImmutable $target, CarbonImmutable $bound1, CarbonImmutable $bound2): bool
    {
        $lo = $bound1->lessThanOrEqualTo($bound2) ? $bound1 : $bound2;
        $hi = $bound1->lessThanOrEqualTo($bound2) ? $bound2 : $bound1;

        return $target->greaterThanOrEqualTo($lo) && $target->lessThanOrEqualTo($hi);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toIntValue(string $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
