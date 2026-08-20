<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

// Answers who a row belongs to, for every covered table. A replay applies
// entries that arrived from another device, so "which rows may this touch" is
// the question standing between a merge and a cross-user write — it lives in
// one place because the answer must never differ between call sites.
final class RowOwnership
{
    // Covered tables that carry no user_id of their own; each reaches its
    // owner through the parent named here. Mirrors OpLogBackfiller.
    private const PARENT_SCOPE = [
        'rule_conditions' => ['rule_id', 'categorization_rules'],
        'rule_actions' => ['rule_id', 'categorization_rules'],
    ];

    // Owner-scoped references carrying no foreign key, so derivation is blind
    // to them and this list is the only thing checking them. Everything with a
    // real FK is derived — the hand-written list of those had drifted twelve
    // tables behind the schema. ReferenceCoverageArchTest catches a new one.
    private const UNENFORCED_REFERENCES = [
        'transactions' => ['counterparty_id' => 'counterparties'],
        'forecast_scenario_mutations' => ['target_series_id' => 'recurring_series'],
    ];

    // A reference whose TARGET TABLE is named by another column on the same
    // row, so no foreign key can express it and no static map can either.
    // migration_source_map holds one row per migrated entity and beatrax_id
    // means whatever beatrax_entity_type says it means.
    private const POLYMORPHIC_REFERENCES = [
        'migration_source_map' => ['beatrax_id' => 'beatrax_entity_type'],
    ];

    // The closed vocabulary MigrationEntityType writes, mapped to the tables
    // it resolves against. A value outside this set is refused rather than
    // waved through — an unknown type is precisely the shape an op would take
    // to slip a reference past a check that only understands four words.
    private const POLYMORPHIC_TABLES = [
        'transaction' => 'transactions',
        'account' => 'accounts',
        'category' => 'categories',
        'envelope_assignment' => 'envelope_assignments',
    ];

    /** @var array<string, bool> */
    private array $userIdColumns = [];

    /** @var array<string, array<string, string>> */
    private array $ownedReferences = [];

    /** @var array<string, int> */
    private array $owners = [];

    public function __construct(
        private readonly DatabaseManager $db,
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

    // Memoised per instance rather than per process: OpLogReplayer builds one
    // of these per replay, so the cache spans exactly the window in which the
    // schema cannot change, and a migration between two runs is never read
    // through a stale answer.
    public function hasUserIdColumn(string $table): bool
    {
        return $this->userIdColumns[$table] ??= $this->db->connection()
            ->getSchemaBuilder()
            ->hasColumn($table, 'user_id');
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

    // Every column on this table that names a row belonging to a user, read
    // from the live foreign keys. Derived rather than listed because the list
    // is the kind that rots silently: it named eleven tables while the schema
    // had twenty-three, and nothing failed to say so.
    /**
     * @return array<string, string>
     */
    public function ownedReferences(string $table): array
    {
        if (isset($this->ownedReferences[$table])) {
            return $this->ownedReferences[$table];
        }

        $schema = $this->db->connection()->getSchemaBuilder();
        $references = self::UNENFORCED_REFERENCES[$table] ?? [];

        if ($schema->hasTable($table)) {
            foreach ($schema->getForeignKeys($table) as $foreignKey) {
                $target = $foreignKey['foreign_table'];

                if (! $this->hasUserIdColumn($target)) {
                    continue;
                }

                foreach ($foreignKey['columns'] as $column) {
                    // A composite key may carry user_id alongside the column
                    // that does the naming; scoping is already handled, and
                    // the user's own id is not a reference to check.
                    if ($column !== 'user_id') {
                        $references[$column] = $target;
                    }
                }
            }
        }

        return $this->ownedReferences[$table] = $references;
    }

    // The polymorphic columns on this table, mapped to the column naming
    // their target. Exposed so the coverage contract can tell a reference
    // that is checked elsewhere from one that is not checked at all.
    /**
     * @return array<string, string>
     */
    public function polymorphicReferences(string $table): array
    {
        return self::POLYMORPHIC_REFERENCES[$table] ?? [];
    }

    // Whether every owner-scoped id this row names belongs to the same user.
    // parentBelongsToUser() answers for the row itself and returns true the
    // moment a table carries its own user_id, so a row that IS the user's
    // while pointing at somebody else's account passed unchallenged.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function referencesBelongToUser(string $table, array $payload, int $userId, int|string|null $pk = null): bool
    {
        if (! $this->polymorphicReferenceBelongsToUser($table, $payload, $userId, $pk)) {
            return false;
        }

        foreach ($this->ownedReferences($table) as $column => $referencedTable) {
            $referenced = $payload[$column] ?? null;

            // A null reference is the column being unset rather than a bad
            // id, so it is not something to refuse the whole row over.
            if ($referenced === null) {
                continue;
            }

            if (! is_int($referenced) && ! is_string($referenced)) {
                return false;
            }

            // Only a row that EXISTS and belongs to someone else is refused.
            // An absent parent is an ordering problem, not a cross-user one —
            // children legitimately arrive before their parent — and the
            // deferral and foreign-key paths already handle that case.
            $owner = $this->ownerOf($referencedTable, $referenced);

            if ($owner !== null && $owner !== $userId) {
                return false;
            }
        }

        return true;
    }

    // Resolves the target table from its sibling type column, then asks the
    // ordinary ownership question. A single-field Set carries no sibling, so
    // the type is read back from the row being updated; with neither the
    // write is refused, because an unresolvable target cannot be cleared.
    /**
     * @param  array<string, mixed>  $payload
     */
    private function polymorphicReferenceBelongsToUser(string $table, array $payload, int $userId, int|string|null $pk): bool
    {
        foreach (self::POLYMORPHIC_REFERENCES[$table] ?? [] as $column => $typeColumn) {
            $referenced = $payload[$column] ?? null;

            if ($referenced === null) {
                continue;
            }

            if (! is_int($referenced) && ! is_string($referenced)) {
                return false;
            }

            $type = $payload[$typeColumn] ?? $this->siblingValue($table, $pk, $typeColumn);
            $referencedTable = is_string($type) ? (self::POLYMORPHIC_TABLES[$type] ?? null) : null;

            if ($referencedTable === null) {
                return false;
            }

            $owner = $this->ownerOf($referencedTable, $referenced);

            if ($owner !== null && $owner !== $userId) {
                return false;
            }
        }

        return true;
    }

    // The type column as the row already holds it, for a Set that carries
    // only the id. Not memoised: this is the value an earlier op in the very
    // same replay may have just rewritten.
    private function siblingValue(string $table, int|string|null $pk, string $column): ?string
    {
        if ($pk === null) {
            return null;
        }

        $value = $this->db->connection()->table($table)->where('id', $pk)->value($column);

        return is_string($value) ? $value : null;
    }

    // Null when the row is absent. Only a found owner is memoised: a replay
    // seeds every row it writes with the session's own user id, so an id that
    // is missing now cannot come back owned by somebody else, but caching the
    // miss would still be answering a later question with an older fact.
    private function ownerOf(string $table, int|string $id): ?int
    {
        $key = $table.':'.$id;

        if (isset($this->owners[$key])) {
            return $this->owners[$key];
        }

        $owner = $this->db->connection()
            ->table($table)
            ->where('id', $id)
            ->value('user_id');

        if (! is_numeric($owner)) {
            return null;
        }

        return $this->owners[$key] = (int) $owner;
    }
}
