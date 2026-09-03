<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Public\Events\EntityMutated;

// Deletes the rows a parent row owns, so the app rather than the database
// decides what an orphan is. A database cascade removes the child itself and
// tells nothing: no tombstone is written, the child's create op stays live in
// the log, and the peer either resurrects the row or quarantines it forever.
final readonly class DependentRowCascade
{
    // Ownership the schema cannot state once the cascade clauses are gone. It
    // covers every owning key except user_id, which stays discovered from the
    // schema by UserScopedDataPurge rather than repeated here.
    private const array OWNED_BY = [
        'accounts' => [
            ['card_statements', 'account_id'],
            ['forecast_shortfall_windows', 'account_id'],
            ['pots', 'account_id'],
            ['statement_summaries', 'account_id'],
            ['transactions', 'account_id'],
        ],
        'anomaly_alerts' => [
            ['anomaly_alert_transitions', 'anomaly_alert_id'],
        ],
        'card_statements' => [
            ['card_statement_credits', 'from_statement_id'],
        ],
        'categories' => [
            ['envelope_assignments', 'category_id'],
            ['envelope_moves', 'category_id'],
            ['envelope_moves', 'counterpart_category_id'],
            ['envelope_settings', 'category_id'],
            ['merchant_memories', 'category_id'],
        ],
        'categorization_rules' => [
            ['rule_actions', 'rule_id'],
            ['rule_conditions', 'rule_id'],
        ],
        'drift_alerts' => [
            ['drift_alert_transitions', 'drift_alert_id'],
        ],
        'forecast_scenarios' => [
            ['forecast_runs', 'scenario_id'],
            ['forecast_scenario_mutations', 'forecast_scenario_id'],
            ['forecast_shortfall_windows', 'scenario_id'],
        ],
        'goals' => [
            ['goal_contributions', 'goal_id'],
        ],
        'import_runs' => [
            ['statement_summaries', 'import_run_id'],
        ],
        'inboxes' => [
            ['discovered_senders', 'inbox_id'],
            ['inbox_messages', 'inbox_id'],
            ['inbox_scan_state', 'inbox_id'],
        ],
        'merchants' => [
            ['merchant_memories', 'merchant_id'],
        ],
        'migration_runs' => [
            ['migration_staging_accounts', 'migration_run_id'],
            ['migration_staging_budget_assignments', 'migration_run_id'],
            ['migration_staging_categories', 'migration_run_id'],
            ['migration_staging_goals', 'migration_run_id'],
            ['migration_staging_payees', 'migration_run_id'],
            ['migration_staging_transactions', 'migration_run_id'],
            ['migration_staging_unmapped_items', 'migration_run_id'],
        ],
        'migration_source_map' => [
            ['migration_import_baseline', 'migration_source_map_id'],
        ],
        'pots' => [
            ['pot_movements', 'pot_id'],
        ],
        'recurring_series' => [
            ['drift_alerts', 'recurring_series_id'],
            ['recurring_series_occurrences', 'recurring_series_id'],
            ['recurring_series_transitions', 'recurring_series_id'],
        ],
        'recurring_series_occurrences' => [
            ['drift_alerts', 'latest_occurrence_id'],
        ],
        'system_alerts' => [
            ['system_alert_acknowledgements', 'system_alert_id'],
        ],
        'transaction_splits' => [
            ['tax_transaction_tags', 'transaction_split_id'],
        ],
        'transactions' => [
            ['anomaly_alerts', 'transaction_id'],
            ['chain_links', 'from_transaction_id'],
            ['chain_links', 'to_transaction_id'],
            ['goal_contributions', 'transaction_id'],
            ['pending_enrichment_conflicts', 'transaction_id'],
            ['recurring_series_occurrences', 'transaction_id'],
            ['tax_transaction_tags', 'transaction_id'],
            ['transaction_splits', 'transaction_id'],
        ],
    ];

    // Keys a parent does NOT own: the child outlives it, and the column is
    // nulled or left alone. Listed so the guard can tell a deliberate
    // non-owning key from one nobody has classified yet.
    private const array NOT_OWNED = [
        'anomaly_suppression_rules.counterparty_id',
        'anomaly_suppression_rules.source_anomaly_alert_id',
        'card_statement_credits.to_statement_id',
        'card_statements.import_run_id',
        'categories.parent_id',
        'merchants.default_category_id',
        'pending_enrichment_conflicts.import_run_id',
        'pot_movements.counterpart_pot_id',
        'pots.category_id',
        'pots.goal_id',
        'recurring_series.latest_funding_chain_link_id',
        'tax_transaction_tags.deduction_category_id',
        'transaction_splits.category_id',
        'transactions.category_id',
        'transactions.import_run_id',
        'transactions.pair_transaction_id',
    ];

    /**
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public static function ownedBy(): array
    {
        return self::OWNED_BY;
    }

    /**
     * @return list<string>
     */
    public static function notOwned(): array
    {
        return self::NOT_OWNED;
    }

    public function __construct(
        private DatabaseManager $db,
        private MergeRulesRegistry $rules,
    ) {}

    // Call inside the parent's transaction, before the parent row goes. The
    // events are for dispatch after it commits, never from inside it.
    /**
     * @return list<EntityMutated>
     */
    public function delete(string $parentTable, int|string $parentId, int $userId): array
    {
        return $this->deleteAll($parentTable, [$parentId], $userId);
    }

    // The same walk for a set of parents, so a bulk delete does not have to
    // choose between one query per row and leaving the children behind.
    /**
     * @param  list<int|string>  $parentIds
     * @return list<EntityMutated>
     */
    public function deleteAll(string $parentTable, array $parentIds, int $userId): array
    {
        if ($parentIds === []) {
            return [];
        }

        $events = [];
        $seen = [];

        $this->sweep($parentTable, $parentIds, $userId, $events, $seen);

        return $events;
    }

    /**
     * @param  list<int|string>  $parentIds
     * @param  list<EntityMutated>  $events
     * @param  array<string, true>  $seen
     */
    private function sweep(string $parentTable, array $parentIds, int $userId, array &$events, array &$seen): void
    {
        foreach (self::OWNED_BY[$parentTable] ?? [] as [$childTable, $column]) {
            $fresh = $this->unseen($childTable, $this->childIds($childTable, $column, $parentIds, $userId), $seen);
            if ($fresh === []) {
                continue;
            }

            // Deepest first: a grandchild whose parent is already gone can no
            // longer be found by the column that owns it.
            $this->sweep($childTable, $fresh, $userId, $events, $seen);
            $this->deleteRows($childTable, $fresh, $userId);

            if (! array_key_exists($childTable, $this->rules->rules())) {
                continue;
            }

            foreach ($fresh as $id) {
                $events[] = new EntityMutated($childTable, $id, $userId, 'delete');
            }
        }
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<string, true>  $seen
     * @return list<int|string>
     */
    private function unseen(string $table, array $ids, array &$seen): array
    {
        $fresh = [];
        foreach ($ids as $id) {
            $key = $table.':'.$id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fresh[] = $id;
        }

        return $fresh;
    }

    /**
     * @param  list<int|string>  $parentIds
     * @return list<int|string>
     */
    private function childIds(string $table, string $column, array $parentIds, int $userId): array
    {
        $query = $this->db->connection()->table($table)->whereIn($column, $parentIds);

        $this->scopeToUser($query, $table, $userId);

        /** @var list<int|string> $ids */
        $ids = $query->pluck('id')->all();

        return $ids;
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function deleteRows(string $table, array $ids, int $userId): void
    {
        $query = $this->db->connection()->table($table)->whereIn('id', $ids);

        $this->scopeToUser($query, $table, $userId);

        $query->delete();
    }

    // A child with no user_id of its own is reached only through a parent this
    // caller already owns, so the parent's scope is the child's scope.
    private function scopeToUser(Builder $query, string $table, int $userId): void
    {
        if (in_array('user_id', $this->db->connection()->getSchemaBuilder()->getColumnListing($table), true)) {
            $query->where('user_id', $userId);
        }
    }
}
