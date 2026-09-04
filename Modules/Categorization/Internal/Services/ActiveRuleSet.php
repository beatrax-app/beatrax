<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Categorization\Models\RuleAction;
use Modules\Categorization\Public\Enums\RuleCombinator;
use Modules\Core\Public\Concerns\CoercesScalars;
use stdClass;

// The rule book read whole, in three queries, and held for the life of this
// instance. Loading it per transaction cost one query per rule per row: a
// reapply over a five-year ledger issued 283 queries for every one of 25,000
// rows. Neither the container nor any caller keeps an instance across a write.
/**
 * @link ../../../../.docs/features/categorization/rule-evaluation-order.md
 */
final class ActiveRuleSet
{
    use CoercesScalars;

    /** @var array<int, list<ActiveRule>> */
    private array $byUser = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return list<ActiveRule>
     */
    public function forUser(int $userId): array
    {
        return $this->byUser[$userId] ??= $this->load($userId);
    }

    /**
     * @return list<ActiveRule>
     */
    private function load(int $userId): array
    {
        $conditions = $this->conditionsByRule($userId);
        $actions = $this->actionsByRule($userId);

        $rules = [];

        foreach ($this->activeRules($userId) as $row) {
            $ruleId = self::toInt($row->id);

            $rules[] = new ActiveRule(
                ruleId: $ruleId,
                priority: self::toInt($row->priority),
                combinator: RuleCombinator::coerce(is_string($row->combinator) ? $row->combinator : null),
                conditions: $conditions[$ruleId] ?? [],
                actions: $actions[$ruleId] ?? [],
            );
        }

        return $rules;
    }

    /**
     * @return list<stdClass>
     */
    private function activeRules(int $userId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->connection()
            ->table('categorization_rules')
            ->where('user_id', $userId)
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'priority', 'combinator'])
            ->all();

        return $rows;
    }

    // Joined to the rule rather than gathered under a `whereIn` of rule ids:
    // the id list is itself the unbounded thing, and a join needs no binds.
    /**
     * @return array<int, list<stdClass>>
     */
    private function conditionsByRule(int $userId): array
    {
        $byRule = [];

        foreach ($this->ownedBy($userId, 'rule_conditions')->orderBy('rule_conditions.id')->cursor() as $row) {
            $byRule[self::toInt($row->rule_id)][] = $row;
        }

        return $byRule;
    }

    // Hydrated through newFromBuilder because MatchedRule hands RuleAction
    // models to RuleApplier, and a row selected here carries exactly the
    // table's own columns.
    /**
     * @return array<int, list<RuleAction>>
     */
    private function actionsByRule(int $userId): array
    {
        $byRule = [];

        $rows = $this->ownedBy($userId, 'rule_actions')
            ->orderBy('rule_actions.position')
            ->orderBy('rule_actions.id');

        foreach ($rows->cursor() as $row) {
            /** @var array<string, mixed> $attributes */
            $attributes = get_object_vars($row);
            $byRule[self::toInt($row->rule_id)][] = (new RuleAction)->newFromBuilder($attributes);
        }

        return $byRule;
    }

    private function ownedBy(int $userId, string $table): Builder
    {
        return $this->db->connection()
            ->table($table)
            ->join('categorization_rules', 'categorization_rules.id', '=', $table.'.rule_id')
            ->where('categorization_rules.user_id', $userId)
            ->where('categorization_rules.active', true)
            ->select($table.'.*');
    }
}
