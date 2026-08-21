<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Contracts\Clock;

final class NavCountsService
{
    private const CACHE_TTL = 300;

    /** @var list<string> recurring states considered "active". */
    private const ACTIVE_STATES = ['approved', 'cadence_changed'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CacheRepository $cache,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array<string, int>
     */
    public function forUser(int $userId): array
    {
        return $this->cache->remember(
            $this->cacheKey($userId),
            self::CACHE_TTL,
            fn (): array => $this->compute($userId),
        );
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

        // A snooze whose deadline has passed is back on /drift, so the badge
        // counts it too. The OR lives inside its own group: appended flat it
        // would escape the user_id predicate above and count every household
        // member's revived alerts.
        $now = $this->clock->now()->toDateTimeString();
        $openOrRevived = static fn (Builder $query): Builder => $query->where(
            static function (Builder $group) use ($now): void {
                $group->where('state', 'open')
                    ->orWhere(static function (Builder $revived) use ($now): void {
                        $revived->where('state', 'snoozed')
                            ->whereNotNull('snoozed_until')
                            ->where('snoozed_until', '<=', $now);
                    });
            },
        );

        return [
            'transactions' => $count('transactions'),
            'recurring' => $count('recurring_series', $active),
            'counterparties' => $count('counterparties'),
            'drift' => $count('drift_alerts', $openOrRevived),
            // The flat category_budgets table is write-dead after the
            // envelope cutover. The "Budgets" badge now reflects how many
            // distinct categories the user budgets via envelope_assignments.
            'budgets' => $countDistinct('envelope_assignments', 'category_id'),
            'subscriptions' => $count('recurring_series', static fn (Builder $query): Builder => $query->where('direction', 'expense')->whereIn('state', self::ACTIVE_STATES)),
            'imports' => $count('import_runs'),
            // Total tagged transactions for the sidebar badge (lifetime count,
            // no year filter). A raw row count would double-count a transaction
            // that has both a stale whole-tx tag row and leg-scoped tag rows for
            // its splits, so superseded whole-tx rows are excluded here too.
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
