<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use DateTimeImmutable;
use Exception;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Categorization\Public\Dto\CategorizationRuleDto;
use Modules\Categorization\Public\Dto\RuleActionDto;
use Modules\Categorization\Public\Dto\RuleConditionDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// Rules are pulled `priority asc, id asc` — the same deterministic order
// RuleEngine::match() executes them in, so the /rules table visually IS
// the execution order. DTOs are constructed only here, at the Public read
// boundary — never inside RuleEngine's per-transaction hot loop.
final readonly class CategorizationRuleQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SensitiveColumnCodec $codec,
        private Session $session,
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

    // Two passes over the same rows: the first collects the category and
    // counterparty ids so each set of display strings resolves in one query,
    // the second builds the DTOs with those strings attached — label work the
    // read boundary owns and the hot per-transaction RuleEngine never pays.
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
        $counterpartyNames = $this->resolveCounterpartyNames(self::uniqueInts($counterpartyIds), $userId);

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

    // The category id a `category` action names, or null for any other action
    // type or a payload missing a numeric id.
    /**
     * @param  array<string, mixed>  $payload
     */
    private static function categoryIdOf(string $type, array $payload): ?int
    {
        return $type === 'category' ? self::payloadIntId($payload, 'category_id') : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function counterpartyIdOf(string $type, array $payload): ?int
    {
        return $type === 'counterparty' ? self::payloadIntId($payload, 'counterparty_id') : null;
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

        $rows = $this->db->connection()
            ->table('categories as c')
            ->leftJoin('categories as p', 'c.parent_id', '=', 'p.id')
            ->whereIn('c.id', $categoryIds)
            ->where(static function (QueryBuilder $q) use ($userId): void {
                $q->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->select(['c.id', 'c.name as category_name', 'p.name as parent_category_name'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $categoryName = is_string($row->category_name ?? null) ? $row->category_name : '';
            $parentName = isset($row->parent_category_name) && is_string($row->parent_category_name)
                ? $row->parent_category_name
                : null;
            $out[self::toInt($row->id)] = $parentName === null ? $categoryName : $parentName.' / '.$categoryName;
        }

        return $out;
    }

    /**
     * @param  list<int>  $counterpartyIds
     * @return array<int, string> counterparty id => display name
     */
    private function resolveCounterpartyNames(array $counterpartyIds, int $userId): array
    {
        if ($counterpartyIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table('counterparties')
            ->whereIn('id', $counterpartyIds)
            ->where('user_id', $userId)
            ->select(['id', 'display_name'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $stored = is_string($row->display_name ?? null) ? $row->display_name : '';
            // Read-side decrypt — pass-through no-op when encryption is
            // not enabled for this user.
            $out[self::toInt($row->id)] = $stored === ''
                ? ''
                : $this->codec->decryptValue('counterparties', 'display_name', $stored, $userId, $this->session)['value'];
        }

        return $out;
    }

    /**
     * @param  list<RuleConditionDto>  $conditions
     * @param  list<RuleActionDto>  $actions
     */
    private function mapRule(stdClass $row, array $conditions, array $actions): CategorizationRuleDto
    {
        $createdAtRaw = is_string($row->created_at) ? $row->created_at : null;
        if ($createdAtRaw === null || $createdAtRaw === '') {
            // Defensive fallback: missing created_at falls back to the
            // injected Clock so the read-side test can pin time
            // deterministically. The DB column is NOT NULL on insert
            // so this branch is unreachable in normal operation.
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
            combinator: is_string($row->combinator) ? $row->combinator : 'all',
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

    // Discards the value entirely (empty array) unless it already is a
    // string-keyed array — never partially trusts a mixed-keyed array.
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
