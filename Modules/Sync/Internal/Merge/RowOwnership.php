<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

// Answers who a row belongs to, for every covered table. A replay applies
// entries that arrived from another device, so "which rows may this touch" is
// the question standing between a merge and a cross-user write — it lives in
// one place because the answer must never differ between call sites.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class RowOwnership
{
    // Covered tables that carry no user_id of their own; each reaches its
    // owner through the parent named here. Mirrors OpLogBackfiller.
    private const PARENT_SCOPE = [
        'rule_conditions' => ['rule_id', 'categorization_rules'],
        'rule_actions' => ['rule_id', 'categorization_rules'],
    ];

    public function __construct(
        private DatabaseManager $db,
    ) {}

    // Bounds a write to rows this user owns. A child table is bounded through
    // its parent; an unscoped table with no known parent is refused outright
    // rather than written user-wide — an op names a row, and a row this
    // process cannot attribute is not a row it may touch.
    public function scopeToUser(Builder $query, string $table, int $userId): Builder
    {
        if ($this->hasUserIdColumn($table)) {
            return $query->where('user_id', $userId);
        }

        $parent = self::PARENT_SCOPE[$table] ?? null;

        if ($parent === null) {
            return $query->whereRaw('1 = 0');
        }

        [$foreignKey, $parentTable] = $parent;

        return $query->whereIn(
            $foreignKey,
            $this->db->connection()->table($parentTable)->select('id')->where('user_id', $userId),
        );
    }

    // Memoised in a function static rather than a property: this class is
    // readonly, and a schema probe per created row would turn one catch-up
    // into thousands of PRAGMA reads.
    public function hasUserIdColumn(string $table): bool
    {
        /** @var array<string, bool> $cache */
        static $cache = [];

        if (! isset($cache[$table])) {
            $cache[$table] = $this->db->connection()
                ->getSchemaBuilder()
                ->hasColumn($table, 'user_id');
        }

        return $cache[$table];
    }

    // True for user-scoped tables (already proven by their own user_id) and
    // for a child row whose parent belongs to this user.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function parentBelongsToUser(string $table, array $payload, int $userId): bool
    {
        if ($this->hasUserIdColumn($table)) {
            return true;
        }

        $parent = self::PARENT_SCOPE[$table] ?? null;

        if ($parent === null) {
            return false;
        }

        [$foreignKey, $parentTable] = $parent;
        $parentId = $payload[$foreignKey] ?? null;

        return (is_int($parentId) || is_string($parentId))
            && $this->db->connection()
                ->table($parentTable)
                ->where('id', $parentId)
                ->where('user_id', $userId)
                ->exists();
    }
}
