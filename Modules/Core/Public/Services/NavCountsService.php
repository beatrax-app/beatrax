<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\RecurringSeriesState;

final readonly class NavCountsService
{
    private const int CACHE_TTL = 300;

    // Bumped by ForgetNavCountsOnWrite on any write to the tables below, and
    // folded into every cache key. The documented contract was that each
    // writing module calls forget(); one out of eight ever did, so seven
    // badges sat five minutes behind the reader's own action.
    private const string GENERATION_KEY = 'nav-counts:generation';

    // Badge => the table its count is read from, and through that the single
    // list of tables a write to which makes every badge stale. A badge added
    // without an entry here is a badge that never refreshes.
    private const array TABLES = [
        'transactions' => 'transactions',
        'recurring' => 'recurring_series',
        'counterparties' => 'counterparties',
        'drift' => 'drift_alerts',
        'budgets' => 'envelope_assignments',
        'subscriptions' => 'recurring_series',
        'imports' => 'import_runs',
        'tax_tagged' => 'tax_transaction_tags',
    ];

    /** @var list<string> recurring states considered "active". */
    private const array ACTIVE_STATES = [
        RecurringSeriesState::Approved->value,
        RecurringSeriesState::CadenceChanged->value,
    ];

    public function __construct(
        private DatabaseManager $db,
        private CacheRepository $cache,
        private Clock $clock,
    ) {}

    /**
     * @return list<string>
     */
    public static function countedTables(): array
    {
        return array_values(array_unique(array_values(self::TABLES)));
    }

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

    // Retires every user's entry at once. A household is two accounts, so
    // recomputing both costs less than working out whose write this was —
    // and a queued job writes with no authenticated user to attribute it to.
    public function bumpGeneration(): void
    {
        if ($this->cache->increment(self::GENERATION_KEY) === false) {
            // A store that will not increment a key it does not hold. Seeded
            // without a TTL on purpose: a generation that expires puts the
            // reader back on the entries it was bumped to retire.
            $this->cache->forever(self::GENERATION_KEY, 1);
        }
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
                $group->where('state', DriftAlertState::Open->value)
                    ->orWhere(static function (Builder $revived) use ($now): void {
                        $revived->where('state', DriftAlertState::Snoozed->value)
                            ->whereNotNull('snoozed_until')
                            ->where('snoozed_until', '<=', $now);
                    });
            },
        );

        return [
            'transactions' => $count(self::TABLES['transactions']),
            'recurring' => $count(self::TABLES['recurring'], $active),
            'counterparties' => $count(self::TABLES['counterparties']),
            'drift' => $count(self::TABLES['drift'], $openOrRevived),
            // The badge counts how many distinct categories the reader
            // budgets, so one category assigned in six months is one.
            'budgets' => $countDistinct(self::TABLES['budgets'], 'category_id'),
            'subscriptions' => $count(self::TABLES['subscriptions'], static fn (Builder $query): Builder => $query->where('direction', Direction::Expense->value)->whereIn('state', self::ACTIVE_STATES)),
            'imports' => $count(self::TABLES['imports']),
            // Total tagged transactions for the sidebar badge (lifetime count,
            // no year filter). A raw row count would double-count a transaction
            // that has both a stale whole-tx tag row and leg-scoped tag rows for
            // its splits, so superseded whole-tx rows are excluded here too.
            'tax_tagged' => $count(self::TABLES['tax_tagged'], static function (Builder $query) use ($connection): void {
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
        $generation = $this->cache->get(self::GENERATION_KEY, 0);

        return 'nav-counts:'.(is_numeric($generation) ? (int) $generation : 0).':'.$userId;
    }
}
