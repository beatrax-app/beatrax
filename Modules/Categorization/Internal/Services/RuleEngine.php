<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Models\RuleAction;
use Modules\Categorization\Public\Enums\ConditionOperator;
use Modules\Categorization\Public\Enums\ConditionValueType;
use Modules\Categorization\Public\Enums\RuleCombinator;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use stdClass;

final class RuleEngine
{
    use CoercesScalars;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return list<MatchedRule>
     *
     * @link ../../../../.docs/features/categorization/rule-evaluation-order.md
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
            $combinator = RuleCombinator::coerce(is_string($rule->combinator) ? $rule->combinator : null);

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

            $fires = $combinator === RuleCombinator::All
                ? ($results !== [] && ! in_array(false, $results, true))
                : in_array(true, $results, true);

            if ($fires) {
                $matched[] = new MatchedRule($ruleId, $priority, $this->actionsFor($ruleId));
            }
        }

        return $matched;
    }

    // Raw query builder rather than the Eloquent RuleAction builder, to
    // avoid a larastan strict-rules dynamic-call warning. The id tiebreak
    // matters because `position` carries no write-layer uniqueness.
    /**
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

    private function conditionMatches(stdClass $condition, RuleMatchInput $tx): bool
    {
        $valueType = ConditionValueType::tryFrom(is_string($condition->value_type) ? $condition->value_type : '');
        $op = ConditionOperator::tryFrom(is_string($condition->op) ? $condition->op : '');
        $field = is_string($condition->field) ? $condition->field : '';
        $value = is_string($condition->value) ? $condition->value : '';
        $value2 = is_string($condition->value2) ? $condition->value2 : null;

        if ($valueType === null || $op === null) {
            return false;
        }

        return match ($valueType) {
            ConditionValueType::Text => self::matchString($op, self::targetFieldValue($tx, $field), $value),
            ConditionValueType::Amount => self::matchAmount($op, $tx->settledAmountMinor, self::toIntValue($value), $value2 !== null ? self::toIntValue($value2) : null),
            ConditionValueType::Date => self::matchDate($op, $tx->postedAt, $value, $value2),
        };
    }

    private static function targetFieldValue(RuleMatchInput $tx, string $field): ?string
    {
        return match ($field) {
            'merchant', 'counterparty' => $tx->counterpartyName,
            'description' => $tx->description,
            default => null,
        };
    }

    // Matching runs in PHP over the mb_* family so a user-authored
    // condition value never reaches a SQL pattern-match clause.
    private static function matchString(ConditionOperator $op, ?string $target, string $value): bool
    {
        if ($target === null || $value === '') {
            return false;
        }

        $t = mb_strtolower($target);
        $v = mb_strtolower($value);

        return match ($op) {
            ConditionOperator::Equals => $t === $v,
            ConditionOperator::StartsWith => mb_strpos($t, $v) === 0,
            ConditionOperator::Contains => mb_strpos($t, $v) !== false,
            default => false,
        };
    }

    private static function matchAmount(ConditionOperator $op, int $target, int $value, ?int $value2): bool
    {
        return match ($op) {
            ConditionOperator::GreaterThan => $target > $value,
            ConditionOperator::LessThan => $target < $value,
            ConditionOperator::Equals => $target === $value,
            ConditionOperator::Between => $value2 !== null
                && $target >= min($value, $value2)
                && $target <= max($value, $value2),
            default => false,
        };
    }

    // Both sides collapse to start-of-day, so `between`'s inclusive upper
    // bound still contains a transaction posted later that same day. The
    // emptiness guard precedes the parse because parse('') is NOW; a present
    // but unreadable value still throws, and the job counts that row as errored.
    /**
     * @link ../../../../.docs/features/categorization/rule-evaluation-order.md#how-a-condition-is-evaluated
     */
    private static function matchDate(ConditionOperator $op, CarbonImmutable $target, string $value, ?string $value2): bool
    {
        if ($value === '') {
            return false;
        }

        $t = $target->startOfDay();
        $v = CarbonImmutable::parse($value)->startOfDay();

        return match ($op) {
            ConditionOperator::After => $t->greaterThan($v),
            ConditionOperator::Before => $t->lessThan($v),
            ConditionOperator::Between => $value2 !== null && $value2 !== ''
                && self::withinInclusiveRange($t, $v, CarbonImmutable::parse($value2)->startOfDay()),
            default => false,
        };
    }

    private static function withinInclusiveRange(CarbonImmutable $target, CarbonImmutable $bound1, CarbonImmutable $bound2): bool
    {
        $lo = $bound1->lessThanOrEqualTo($bound2) ? $bound1 : $bound2;
        $hi = $bound1->lessThanOrEqualTo($bound2) ? $bound2 : $bound1;

        return $target->greaterThanOrEqualTo($lo) && $target->lessThanOrEqualTo($hi);
    }

    private static function toIntValue(string $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
