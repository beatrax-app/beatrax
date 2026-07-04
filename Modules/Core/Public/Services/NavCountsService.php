<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Per-user item counts for the sidebar nav badges (Transactions, Recurring,
 * Counterparties, Drift alerts, Budgets, Subscriptions, Imports).
 *
 * The sidebar renders on every authenticated page, so running a COUNT per item
 * per render would be a steady tax. Instead the whole set is computed once and
 * CACHED per user (short TTL); writes that materially change a count call
 * forget() to drop the cache. Counts are read straight from the canonical
 * tables (user-scoped) rather than fanning out to each module's query service,
 * to keep the sidebar's hot path to a single cached read.
 */
final class NavCountsService
{
    private const CACHE_TTL = 300;

    /** @var list<string> recurring states considered "active". */
    private const ACTIVE_STATES = ['approved', 'cadence_changed'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @return array<string, int>
     */
    public function forUser(int $userId): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->cache->remember(
            $this->cacheKey($userId),
            self::CACHE_TTL,
            fn (): array => $this->compute($userId),
        );

        return $counts;
    }

    public function forget(int $userId): void
    {
        $this->cache->forget($this->cacheKey($userId));
    }

    /**
     * @return array<string, int>
     */
    private function compute(int $userId): array
    {
        $connection = $this->db->connection();
        $schema = $connection->getSchemaBuilder();

        // Count is 0 (not an error) for any table a build doesn't have, so the
        // sidebar never 500s if a module's migrations aren't present.
        $count = function (string $table, ?Closure $scope = null) use ($connection, $schema, $userId): int {
            if (! $schema->hasTable($table)) {
                return 0;
            }
            $query = $connection->table($table)->where('user_id', $userId);
            if ($scope !== null) {
                $scope($query);
            }

            return $query->count();
        };

        // Distinct user-scoped count for a column (e.g. how many categories the
        // user budgets, counting a category once across all its period rows).
        $countDistinct = function (string $table, string $column) use ($connection, $schema, $userId): int {
            if (! $schema->hasTable($table)) {
                return 0;
            }

            return $connection->table($table)->where('user_id', $userId)->distinct()->count($column);
        };

        $active = static fn (Builder $query): Builder => $query->whereIn('state', self::ACTIVE_STATES);

        return [
            'transactions' => $count('transactions'),
            'recurring' => $count('recurring_series', $active),
            'counterparties' => $count('counterparties'),
            'drift' => $count('drift_alerts', static fn (Builder $query): Builder => $query->where('state', 'open')),
            // Phase 13.2: the flat category_budgets table is write-dead after the
            // envelope cutover (D-13). The "Budgets" badge now reflects how many
            // distinct categories the user budgets via envelope_assignments.
            'budgets' => $countDistinct('envelope_assignments', 'category_id'),
            'subscriptions' => $count('recurring_series', static fn (Builder $query): Builder => $query->where('direction', 'expense')->whereIn('state', self::ACTIVE_STATES)),
            'imports' => $count('import_runs'),
            // Phase 07: total tagged transactions for the sidebar badge (no year filter —
            // the badge shows lifetime tagged count, not a per-year slice).
            //
            // Phase 13.3 Finding C: a raw row count double-counts once a
            // transaction has BOTH a stale whole-tx tag row (transaction_split_id
            // IS NULL) and leg-scoped tag rows for its splits — the whole-tx row
            // is "superseded" and no longer surfaced by TaxYearQuery/TaxTagQuery
            // (Phase 13.1 D-06a), so the sidebar count must exclude it too or the
            // badge reads one higher than every other tax surface. This mirrors
            // TaxYearQuery's whereNotNull/orWhereNotExists supersession shape
            // (Modules/Tax/Internal/Services/TaxYearQuery.php) directly against
            // the raw table rather than going through the Tax module's Public
            // TaxTagQuery service — matching every other count in this method,
            // which reads canonical tables directly to keep the sidebar's hot
            // path to a single cached read (see class docblock).
            'tax_tagged' => $count('tax_transaction_tags', static function (Builder $query) use ($connection): void {
                $query->where(static function (Builder $q) use ($connection): void {
                    $q->whereNotNull('transaction_split_id')
                        ->orWhereNotExists(static function (Builder $sub) use ($connection): void {
                            $sub->select($connection->raw(1))
                                ->from('tax_transaction_tags AS tag2')
                                ->whereColumn('tag2.transaction_id', 'tax_transaction_tags.transaction_id')
                                ->whereColumn('tag2.user_id', 'tax_transaction_tags.user_id')
                                ->whereNotNull('tag2.transaction_split_id');
                        });
                });
            }),
        ];
    }

    private function cacheKey(int $userId): string
    {
        return 'nav-counts:'.$userId;
    }
}
