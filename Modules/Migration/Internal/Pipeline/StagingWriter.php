<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Public\Dto\MigrationAccountDto;
use Modules\Migration\Public\Dto\MigrationBatch;
use Modules\Migration\Public\Dto\MigrationBudgetAssignmentDto;
use Modules\Migration\Public\Dto\MigrationCategoryDto;
use Modules\Migration\Public\Dto\MigrationGoalDto;
use Modules\Migration\Public\Dto\MigrationPayeeDto;
use Modules\Migration\Public\Dto\MigrationTransactionDto;
use Modules\Migration\Public\Dto\UnmappedItemDto;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class StagingWriter
{
    private const CHUNK_SIZE = 500;

    // Neither Ynab4Parser/NynabParser nor ActualParser ever populates
    // MigrationAccountDto::$kind, but the staging schema's kind column is
    // NOT NULL; this placeholder is staged verbatim and carries no
    // promotion-time significance beyond satisfying the column.
    private const DEFAULT_ACCOUNT_KIND = 'checking';

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function write(MigrationBatch $batch, int $migrationRunId, User $user): void
    {
        $this->writeCategories($batch->categories, $migrationRunId, $user);
        $this->writeAccounts($batch->accounts, $migrationRunId, $user);
        $this->writePayees($batch->payees, $migrationRunId, $user);
        $this->writeBudgetAssignments($batch->budgetAssignments, $migrationRunId, $user);
        $this->writeGoals($batch->goals, $migrationRunId, $user);
        $this->writeUnmapped($batch->unmapped, $migrationRunId, $user);
        $this->writeTransactions($batch->transactions, $migrationRunId, $user);
    }

    /**
     * @param  Collection<int, MigrationCategoryDto>  $categories
     */
    private function writeCategories(Collection $categories, int $migrationRunId, User $user): void
    {
        $rows = $categories->map(fn (MigrationCategoryDto $c): array => [
            'user_id' => $user->id,
            'migration_run_id' => $migrationRunId,
            'source_external_id' => $c->sourceExternalId,
            'source_group_name' => $c->sourceGroupName,
            'name' => $c->name,
            'parent_source_external_id' => $c->parentSourceExternalId,
            'kind' => $c->kind,
            'resolution_status' => 'unmapped',
            'resolved_category_id' => null,
        ])->all();

        $this->insertChunked('migration_staging_categories', $rows);
    }

    /**
     * @param  Collection<int, MigrationAccountDto>  $accounts
     */
    private function writeAccounts(Collection $accounts, int $migrationRunId, User $user): void
    {
        $rows = $accounts->map(fn (MigrationAccountDto $a): array => [
            'user_id' => $user->id,
            'migration_run_id' => $migrationRunId,
            'source_external_id' => $a->sourceExternalId,
            'name' => $a->name,
            'kind' => $a->kind ?? self::DEFAULT_ACCOUNT_KIND,
            'currency' => $a->currency,
            'resolution_status' => 'unmapped',
            'resolved_account_id' => null,
        ])->all();

        $this->insertChunked('migration_staging_accounts', $rows);
    }

    /**
     * @param  Collection<int, MigrationPayeeDto>  $payees
     */
    private function writePayees(Collection $payees, int $migrationRunId, User $user): void
    {
        $rows = $payees->map(fn (MigrationPayeeDto $p): array => [
            'user_id' => $user->id,
            'migration_run_id' => $migrationRunId,
            'source_external_id' => $p->sourceExternalId,
            'normalized_name' => $p->name,
            'resolution_status' => 'unmapped',
            'resolved_counterparty_id' => null,
        ])->all();

        $this->insertChunked('migration_staging_payees', $rows);
    }

    /**
     * @param  Collection<int, MigrationBudgetAssignmentDto>  $assignments
     */
    private function writeBudgetAssignments(Collection $assignments, int $migrationRunId, User $user): void
    {
        $rows = $assignments->map(fn (MigrationBudgetAssignmentDto $a): array => [
            'user_id' => $user->id,
            'migration_run_id' => $migrationRunId,
            'source_category_external_id' => $a->sourceCategoryExternalId,
            'period_start' => $a->periodStart->toDateString(),
            'budgeted_minor' => $a->budgeted->toMinor(),
            'currency' => $a->budgeted->currency(),
        ])->all();

        $this->insertChunked('migration_staging_budget_assignments', $rows);
    }

    /**
     * @param  Collection<int, MigrationGoalDto>  $goals
     */
    private function writeGoals(Collection $goals, int $migrationRunId, User $user): void
    {
        $rows = $goals->map(fn (MigrationGoalDto $g): array => [
            'user_id' => $user->id,
            'migration_run_id' => $migrationRunId,
            'category_source_external_id' => $g->categorySourceExternalId,
            'name' => $g->name,
            'target_minor' => $g->targetAmount->toMinor(),
            'target_currency' => $g->targetAmount->currency(),
            'target_date' => $g->targetDate?->toDateString(),
        ])->all();

        $this->insertChunked('migration_staging_goals', $rows);
    }

    /**
     * @param  Collection<int, UnmappedItemDto>  $unmapped
     */
    private function writeUnmapped(Collection $unmapped, int $migrationRunId, User $user): void
    {
        $rows = $unmapped->map(fn (UnmappedItemDto $u): array => [
            'user_id' => $user->id,
            'migration_run_id' => $migrationRunId,
            'item_type' => $u->itemType,
            'source_external_id' => $u->sourceExternalId,
            'display_label' => $u->displayLabel,
            'reason' => $u->reason,
        ])->all();

        $this->insertChunked('migration_staging_unmapped_items', $rows);
    }

    /**
     * @param  iterable<int, MigrationTransactionDto>  $transactions
     */
    private function writeTransactions(iterable $transactions, int $migrationRunId, User $user): void
    {
        // Never materializes the generator into a PHP array — the foreach
        // below iterates it lazily one DTO at a time, appending each DTO's
        // row(s) to a bounded $chunk buffer flushed at CHUNK_SIZE.
        $chunk = [];

        foreach ($transactions as $dto) {
            foreach ($this->transactionRows($dto, $migrationRunId, $user) as $row) {
                $chunk[] = $row;
                if (count($chunk) >= self::CHUNK_SIZE) {
                    $this->db->connection()->table('migration_staging_transactions')->insert($chunk);
                    $chunk = [];
                }
            }
        }

        if ($chunk !== []) {
            $this->db->connection()->table('migration_staging_transactions')->insert($chunk);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transactionRows(MigrationTransactionDto $dto, int $migrationRunId, User $user): array
    {
        $ownExternalId = $dto->sourceExternalId ?? ('row-'.$dto->sourceRowIndex);

        $parentRow = [
            'user_id' => $user->id,
            'migration_run_id' => $migrationRunId,
            'source_external_id' => $ownExternalId,
            'account_source_external_id' => $dto->accountSourceExternalId,
            'posted_at' => $dto->postedAt->toDateTimeString(),
            'amount_minor' => $dto->amount->toMinor(),
            'currency' => $dto->amount->currency(),
            'settled_amount_minor' => $dto->amount->toMinor(),
            'settled_currency' => $dto->amount->currency(),
            'payee_source_external_id' => $dto->payeeSourceExternalId,
            'description' => $dto->description,
            'category_source_external_id' => $dto->categorySourceExternalId,
            'cleared_status' => $dto->clearedStatus,
            'is_split_parent' => $dto->splits !== [],
            'parent_source_external_id' => null,
            'transfer_counterpart_source_external_id' => $dto->transferCounterpartSourceExternalId,
        ];

        $rows = [$parentRow];

        foreach ($dto->splits as $index => $leg) {
            /** @var array{category_source_external_id: ?string, amount: Money, note: ?string} $leg */
            $rows[] = [
                'user_id' => $user->id,
                'migration_run_id' => $migrationRunId,
                'source_external_id' => $ownExternalId.'/leg-'.$index,
                'account_source_external_id' => $dto->accountSourceExternalId,
                'posted_at' => $dto->postedAt->toDateTimeString(),
                'amount_minor' => $leg['amount']->toMinor(),
                'currency' => $leg['amount']->currency(),
                'settled_amount_minor' => $leg['amount']->toMinor(),
                'settled_currency' => $leg['amount']->currency(),
                'payee_source_external_id' => $dto->payeeSourceExternalId,
                'description' => $leg['note'] ?? $dto->description,
                'category_source_external_id' => $leg['category_source_external_id'],
                'cleared_status' => $dto->clearedStatus,
                'is_split_parent' => false,
                'parent_source_external_id' => $ownExternalId,
                'transfer_counterpart_source_external_id' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertChunked(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $this->db->connection()->table($table)->insert($chunk);
        }
    }
}
