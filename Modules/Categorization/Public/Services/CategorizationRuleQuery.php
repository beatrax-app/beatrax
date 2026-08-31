<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use DateTimeImmutable;
use Exception;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Modules\Categorization\Public\Dto\CategorizationRuleDto;
use Modules\Categorization\Public\Dto\RuleActionDto;
use Modules\Categorization\Public\Dto\RuleConditionDto;
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Categorization\Public\Enums\RuleCombinator;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Public\Queries\CounterpartyDisplayName;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\Support\CategoryPathName;
use stdClass;

// Rules are read `priority asc, id asc` — the same order RuleEngine::match()
// executes them in, so the /rules table visually is the execution order.
final readonly class CategorizationRuleQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private CounterpartyDisplayName $counterpartyNames,
    ) {}

    /**
     * @return list<CategorizationRuleDto>
     */
    public function forUser(User $user): array
    {
        $rows = $this->db->connection()
            ->table('categorization_rules')
            ->where('user_id', $user->id)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return $this->hydrate($rows, $user->id);
    }

    public function findForUser(User $user, int $ruleId): ?CategorizationRuleDto
    {
        $row = $this->db->connection()
            ->table('categorization_rules')
            ->where('id', $ruleId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return null;
        }

        $dtos = $this->hydrate([$row], $user->id);

        return $dtos[0] ?? null;
    }

    /**
     * @param  iterable<int, stdClass>  $rows
     * @return list<CategorizationRuleDto>
     */
    private function hydrate(iterable $rows, int $userId): array
    {
        $rulesById = [];
        $ruleIds = [];
        foreach ($rows as $row) {
            $id = self::toInt($row->id);
            $rulesById[$id] = $row;
            $ruleIds[] = $id;
        }

        if ($ruleIds === []) {
            return [];
        }

        $connection = $this->db->connection();

        /** @var iterable<int, stdClass> $conditionRows */
        $conditionRows = $connection->table('rule_conditions')
            ->whereIn('rule_id', $ruleIds)
            ->orderBy('id')
            ->get();

        /** @var iterable<int, stdClass> $actionRows */
        $actionRows = $connection->table('rule_actions')
            ->whereIn('rule_id', $ruleIds)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $conditionsByRule = self::buildConditionsByRule($conditionRows);
        $actionsByRule = $this->buildActionsByRule($actionRows, $userId);

        $out = [];
        foreach ($ruleIds as $ruleId) {
            $out[] = $this->mapRule(
                $rulesById[$ruleId],
                $conditionsByRule[$ruleId] ?? [],
                $actionsByRule[$ruleId] ?? [],
            );
        }

        return $out;
    }

    /**
     * @param  iterable<int, stdClass>  $conditionRows
     * @return array<int, list<RuleConditionDto>>
     */
    private static function buildConditionsByRule(iterable $conditionRows): array
    {
        $byRule = [];
        foreach ($conditionRows as $row) {
            $byRule[self::toInt($row->rule_id)][] = new RuleConditionDto(
                id: self::toInt($row->id),
                field: self::toString($row->field),
                op: self::toString($row->op),
                valueType: self::toString($row->value_type),
                value: self::toString($row->value),
                value2: self::toStringOrNull($row->value2 ?? null),
            );
        }

        return $byRule;
    }

    // Two passes over the rows: the first collects the ids so the display
    // strings resolve in one query each rather than one per action.
    /**
     * @param  iterable<int, stdClass>  $actionRows
     * @return array<int, list<RuleActionDto>>
     */
    private function buildActionsByRule(iterable $actionRows, int $userId): array
    {
        /** @var array<int, array<string, mixed>> $decodedPayloads keyed by rule_actions.id */
        $decodedPayloads = [];
        $categoryIds = [];
        $counterpartyIds = [];
        foreach ($actionRows as $row) {
            $payload = self::decodePayload($row->payload);
            $decodedPayloads[self::toInt($row->id)] = $payload;
            $type = self::toString($row->type);
            $categoryIds[] = self::categoryIdOf($type, $payload);
            $counterpartyIds[] = self::counterpartyIdOf($type, $payload);
        }

        $categoryPaths = $this->resolveCategoryPaths(self::uniqueInts($categoryIds), $userId);
        $counterpartyNames = $this->counterpartyNames->forIds(self::uniqueInts($counterpartyIds), $userId);

        $byRule = [];
        foreach ($actionRows as $row) {
            $actionId = self::toInt($row->id);
            $type = self::toString($row->type);
            $payload = $decodedPayloads[$actionId] ?? [];
            $categoryId = self::categoryIdOf($type, $payload);
            $counterpartyId = self::counterpartyIdOf($type, $payload);

            $byRule[self::toInt($row->rule_id)][] = new RuleActionDto(
                id: $actionId,
                position: self::toInt($row->position),
                type: $type,
                payload: $payload,
                categoryPath: $categoryId === null ? null : ($categoryPaths[$categoryId] ?? null),
                counterpartyName: $counterpartyId === null ? null : ($counterpartyNames[$counterpartyId] ?? null),
            );
        }

        return $byRule;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function categoryIdOf(string $type, array $payload): ?int
    {
        return $type === ActionType::Category->value ? self::payloadIntId($payload, 'category_id') : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function counterpartyIdOf(string $type, array $payload): ?int
    {
        return $type === ActionType::Counterparty->value ? self::payloadIntId($payload, 'counterparty_id') : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function payloadIntId(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  list<int|null>  $ids
     * @return list<int>
     */
    private static function uniqueInts(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));
    }

    /**
     * @param  list<int>  $categoryIds
     * @return array<int, string> category id => breadcrumb path
     */
    private function resolveCategoryPaths(array $categoryIds, int $userId): array
    {
        if ($categoryIds === []) {
            return [];
        }

        // The visibility predicate applies to the parent half too: without it a
        // leaf whose parent_id points at another tenant's row prints that
        // tenant's category name in front of it.
        $rows = $this->db->connection()
            ->table('categories as c')
            ->leftJoin('categories as p', static function (JoinClause $join) use ($userId): void {
                $join->on('c.parent_id', '=', 'p.id')
                    ->where(static function (QueryBuilder $q) use ($userId): void {
                        $q->whereNull('p.user_id')->orWhere('p.user_id', $userId);
                    });
            })
            ->where(static function (QueryBuilder $q) use ($userId): void {
                $q->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->select(['c.id', ...CategoryDisplayName::columns('c'), ...CategoryDisplayName::columns('p', 'parent_category')])
            ->get();

        // Every visible category, not only the ones the rules point at: the
        // ordinal separating two identical paths counts from the lowest id of
        // all of them, and the rule form's picker beside this list counts the
        // same way. Narrowing spells one category two ways on one screen.
        $paths = [];
        foreach ($rows as $row) {
            $paths[self::toInt($row->id)] = CategoryPathName::join(
                CategoryDisplayName::fromRow($row, 'parent_category'),
                CategoryDisplayName::fromRow($row, 'category') ?? '',
            );
        }

        $wanted = array_flip($categoryIds);

        return array_intersect_key(CategoryPathName::distinct($paths), $wanted);
    }

    /**
     * @param  list<RuleConditionDto>  $conditions
     * @param  list<RuleActionDto>  $actions
     */
    private function mapRule(stdClass $row, array $conditions, array $actions): CategorizationRuleDto
    {
        $createdAtRaw = is_string($row->created_at) ? $row->created_at : null;
        if ($createdAtRaw === null || $createdAtRaw === '') {
            // Unreachable in practice — created_at is NOT NULL; the Clock
            // fallback keeps hydration total rather than throwing.
            $createdAt = $this->clock->now()->toDateTimeImmutable();
        } else {
            try {
                $createdAt = new DateTimeImmutable($createdAtRaw);
            } catch (Exception) {
                $createdAt = $this->clock->now()->toDateTimeImmutable();
            }
        }

        return new CategorizationRuleDto(
            id: self::toInt($row->id),
            userId: self::toInt($row->user_id),
            priority: self::toInt($row->priority),
            combinator: is_string($row->combinator) ? $row->combinator : RuleCombinator::All->value,
            hitsCount: self::toInt($row->hits_count),
            active: (bool) $row->active,
            notes: isset($row->notes) && is_string($row->notes) ? $row->notes : null,
            createdAt: $createdAt,
            conditions: $conditions,
            actions: $actions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodePayload(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return self::toStringKeyedArray($decoded);
        }

        return self::toStringKeyedArray($raw);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toStringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
