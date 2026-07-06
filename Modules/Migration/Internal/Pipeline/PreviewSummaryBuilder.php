<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Exceptions\MigrationRunNotParsedException;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Dto\PreviewSummary;

/**
 * Read model for the wizard's preview step (Req 11/12): computes the five
 * mapped counts (categories, accounts, counterparties, transactions, budget
 * months) plus the grouped unmapped-items summary, purely from
 * `migration_staging_*` — never a domain table (staging IS the preview
 * state, D-06).
 *
 * Every read goes through raw `DatabaseManager` `table()->where()` calls
 * (never a chained dynamic Eloquent ordering call) to stay clean under
 * `phpstan-strict-rules`' `staticMethod.dynamicCall` — the same discipline
 * `Modules\Goals\Public\Services\GoalProgressQuery` established (RESEARCH.md
 * Pitfall 5 / PATTERNS.md Shared Patterns).
 *
 * Cross-user isolation (T-13.5-14): every query below carries an explicit
 * `user_id` guard; a foreign-owned run resolves to a `ModelNotFoundException`
 * (translated to a 404, never a 403, per this codebase's ASVS V4 convention)
 * before any count query runs.
 */
final class PreviewSummaryBuilder
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function forRun(int $migrationRunId, User $user): PreviewSummary
    {
        $connection = $this->db->connection();

        $ownedByUser = $connection->table('migration_runs')
            ->where('id', $migrationRunId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $ownedByUser) {
            throw (new ModelNotFoundException)->setModel(MigrationRun::class, [$migrationRunId]);
        }

        $categoriesCount = $connection->table('migration_staging_categories')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->count();

        $accountsCount = $connection->table('migration_staging_accounts')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->count();

        $counterpartiesCount = $connection->table('migration_staging_payees')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->count();

        // Excludes split legs (rows with a non-null parent_source_external_id)
        // so a 2-leg split counts as ONE transaction, matching the parser's
        // own MigrationTransactionDto granularity.
        $transactionsCount = $connection->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->whereNull('parent_source_external_id')
            ->count();

        $budgetMonthsCount = $connection->table('migration_staging_budget_assignments')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->distinct()
            ->count('period_start');

        if ($categoriesCount === 0 && $accountsCount === 0 && $counterpartiesCount === 0
            && $transactionsCount === 0 && $budgetMonthsCount === 0) {
            throw new MigrationRunNotParsedException($migrationRunId);
        }

        return new PreviewSummary(
            categoriesCount: $categoriesCount,
            accountsCount: $accountsCount,
            counterpartiesCount: $counterpartiesCount,
            transactionsCount: $transactionsCount,
            budgetMonthsCount: $budgetMonthsCount,
            unmapped: $this->groupedUnmapped($connection, $migrationRunId, $user),
        );
    }

    /**
     * @return array<string, array{items: list<array{label: string, reason: string}>, count: int}>
     */
    private function groupedUnmapped(Connection $connection, int $migrationRunId, User $user): array
    {
        /** @var array<string, list<array{label: string, reason: string}>> $groups */
        $groups = [
            'category' => [],
            'payee' => [],
            'extra' => [],
            'conflict' => [],
        ];

        $rows = $connection->table('migration_staging_unmapped_items')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->get(['item_type', 'display_label', 'reason']);

        foreach ($rows as $row) {
            $itemType = self::toStr($row->item_type);
            if (! isset($groups[$itemType])) {
                continue; // defensive: unknown item_type never renders (schema allow-list is 'category'|'payee'|'extra'|'conflict')
            }

            $groups[$itemType][] = [
                'label' => self::toStr($row->display_label),
                'reason' => self::toStr($row->reason),
            ];
        }

        $result = [];
        foreach ($groups as $itemType => $items) {
            $result[$itemType] = ['items' => $items, 'count' => count($items)];
        }

        return $result;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
