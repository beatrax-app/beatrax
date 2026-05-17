<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use DateTimeImmutable;
use Exception;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Modules\Categorization\Public\Dto\CategorizationRuleDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use stdClass;

/**
 * Read-side projection of categorization_rules for the /rules page +
 * future correction-divergence drawer panel. Every read scopes by
 * `user_id` so a cross-user rule never surfaces in the current user's
 * UI.
 *
 * The category-path breadcrumb is joined on read so the table renders
 * "Subscriptions / Streaming" without a separate lookup per row.
 * Visibility rule mirrors CategoryOptionsQuery: a category row is
 * visible when its `user_id` is null (seeded default tree) OR matches
 * the user.
 */
final readonly class CategorizationRuleQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    /**
     * @return list<CategorizationRuleDto>
     */
    public function forUser(User $user): array
    {
        $userId = $user->id;

        $rows = $this->db->connection()
            ->table('categorization_rules as r')
            ->leftJoin('categories as c', static function (JoinClause $join) use ($userId): void {
                $join->on('r.category_id', '=', 'c.id')
                    ->where(static function (QueryBuilder $q) use ($userId): void {
                        $q->whereNull('c.user_id')->orWhere('c.user_id', $userId);
                    });
            })
            ->leftJoin('categories as p', static function (JoinClause $join) use ($userId): void {
                $join->on('c.parent_id', '=', 'p.id')
                    ->where(static function (QueryBuilder $q) use ($userId): void {
                        $q->whereNull('p.user_id')->orWhere('p.user_id', $userId);
                    });
            })
            ->where('r.user_id', $userId)
            ->orderByDesc('r.created_at')
            ->select([
                'r.id',
                'r.user_id',
                'r.field',
                'r.match',
                'r.value',
                'r.category_id',
                'r.hits_count',
                'r.active',
                'r.notes',
                'r.created_at',
                'c.name as category_name',
                'p.name as parent_category_name',
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapRow($row);
        }

        return $out;
    }

    public function findForUser(User $user, int $ruleId): ?CategorizationRuleDto
    {
        $userId = $user->id;

        $row = $this->db->connection()
            ->table('categorization_rules as r')
            ->leftJoin('categories as c', static function (JoinClause $join) use ($userId): void {
                $join->on('r.category_id', '=', 'c.id')
                    ->where(static function (QueryBuilder $q) use ($userId): void {
                        $q->whereNull('c.user_id')->orWhere('c.user_id', $userId);
                    });
            })
            ->leftJoin('categories as p', static function (JoinClause $join) use ($userId): void {
                $join->on('c.parent_id', '=', 'p.id')
                    ->where(static function (QueryBuilder $q) use ($userId): void {
                        $q->whereNull('p.user_id')->orWhere('p.user_id', $userId);
                    });
            })
            ->where('r.user_id', $userId)
            ->where('r.id', $ruleId)
            ->select([
                'r.id',
                'r.user_id',
                'r.field',
                'r.match',
                'r.value',
                'r.category_id',
                'r.hits_count',
                'r.active',
                'r.notes',
                'r.created_at',
                'c.name as category_name',
                'p.name as parent_category_name',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->mapRow($row);
    }

    private function mapRow(stdClass $row): CategorizationRuleDto
    {
        $categoryName = is_string($row->category_name ?? null) ? $row->category_name : '';
        $parentName = isset($row->parent_category_name) && is_string($row->parent_category_name)
            ? $row->parent_category_name
            : null;
        $path = $parentName === null ? $categoryName : $parentName.' / '.$categoryName;

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
            field: is_string($row->field) ? $row->field : '',
            match: is_string($row->match) ? $row->match : '',
            value: is_string($row->value) ? $row->value : '',
            categoryId: self::toInt($row->category_id),
            categoryPath: $path,
            hitsCount: self::toInt($row->hits_count),
            active: (bool) $row->active,
            notes: isset($row->notes) && is_string($row->notes) ? $row->notes : null,
            createdAt: $createdAt,
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
