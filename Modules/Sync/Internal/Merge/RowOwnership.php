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

    // Owner-scoped references on covered tables. A row's local autoincrement
    // id is its cross-device identity, so an id minted on one device can name
    // a different row on another: a phone created accounts id 4, and on the
    // desktop id 4 already belonged to the other household member.
    private const OWNED_REFERENCES = [
        'transactions' => [
            'account_id' => 'accounts',
            'category_id' => 'categories',
            'counterparty_id' => 'counterparties',
            'import_run_id' => 'import_runs',
        ],
        'transaction_splits' => ['transaction_id' => 'transactions', 'category_id' => 'categories'],
        // Both legs of a transfer name a pot, and the counterpart leg is the
        // one that could quietly credit another household member's pot.
        'pot_movements' => ['pot_id' => 'pots', 'counterpart_pot_id' => 'pots'],
        'merchant_memories' => ['merchant_id' => 'merchants', 'category_id' => 'categories'],
        'tax_transaction_tags' => ['transaction_id' => 'transactions'],
        'chain_links' => ['from_transaction_id' => 'transactions', 'to_transaction_id' => 'transactions'],
        'recurring_series' => ['latest_funding_chain_link_id' => 'chain_links'],
        'recurring_series_occurrences' => [
            'recurring_series_id' => 'recurring_series',
            'transaction_id' => 'transactions',
        ],
        'anomaly_alerts' => ['transaction_id' => 'transactions'],
        'drift_alerts' => [
            'recurring_series_id' => 'recurring_series',
            'latest_occurrence_id' => 'recurring_series_occurrences',
        ],
        // `target_series_id` deliberately carries no database foreign key —
        // the series lives in another module — so this check is the only
        // thing stopping a mutation from naming another member's series.
        'forecast_scenario_mutations' => [
            'forecast_scenario_id' => 'forecast_scenarios',
            'target_series_id' => 'recurring_series',
        ],
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

    // Whether every owner-scoped id this row names belongs to the same user.
    // parentBelongsToUser() answers for the row itself and returns true the
    // moment a table carries its own user_id, so a row that IS the user's
    // while pointing at somebody else's account passed unchallenged.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function referencesBelongToUser(string $table, array $payload, int $userId): bool
    {
        foreach (self::OWNED_REFERENCES[$table] ?? [] as $column => $referencedTable) {
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
            $owner = $this->db->connection()
                ->table($referencedTable)
                ->where('id', $referenced)
                ->value('user_id');

            if ($owner !== null && (int) $owner !== $userId) {
                return false;
            }
        }

        return true;
    }
}
