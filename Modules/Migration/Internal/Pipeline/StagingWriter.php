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
 * Lands a parsed `MigrationBatch` into the seven `migration_staging_*`
 * scratch tables (D-06/D-07) — the ONLY write this class performs; no domain
 * table is ever touched here (Req 11's no-write-before-confirm invariant).
 *
 * The six bounded collections (categories, accounts, payees, budget
 * assignments, goals, already-known-unmapped items) are small enough per
 * format to insert directly. `goals` (`migration_staging_goals`, Plan 06
 * Rule 2 fix) is the persistence path `MigrationBatch::$goals` was missing
 * entirely — without it, a parsed `MigrationGoalDto` had nowhere to land
 * between parse and confirm, making Req 8's goal promotion structurally
 * impossible. `transactions` is the one deliberate exception (D-04/D-05):
 * it streams the batch's lazy `Generator<MigrationTransactionDto>` directly
 * into `migration_staging_transactions` in chunks of `CHUNK_SIZE` rows,
 * mirroring `Modules\Ledger\Public\Actions\RecordTransactions::persistChunk()`'s
 * bounded-write pattern — the generator itself is never collapsed into one
 * in-memory array before being written (Pitfall 4).
 *
 * A split-parent `MigrationTransactionDto` (non-empty `splits`) is flattened
 * into TWO OR MORE staging rows: one parent row (`is_split_parent = true`,
 * `category_source_external_id` NULL — category lives on each leg, never on
 * the parent, matching the DTO's own convention) plus one row per split leg
 * (`is_split_parent = false`, `parent_source_external_id` set to the
 * parent's `source_external_id`, a synthesized leg `source_external_id` of
 * `"{parent source_external_id}/leg-{n}"` since a split leg carries no
 * source-native id of its own). Transfer-pair linkage is carried purely via
 * the `transfer_counterpart_source_external_id` column already on the
 * staging schema — no separate transfer table.
 *
 * Every inserted row carries both `user_id` and `migration_run_id`
 * (denormalized scope columns present on every `migration_staging_*` table).
 * Money fields decompose to `(minor, currency)` column pairs — this phase's
 * importer never computes or fabricates FX (Req 7): `settled_amount_minor`/
 * `settled_currency` mirror `amount_minor`/`currency` verbatim at staging
 * time, exactly the source-currency value, never a base-currency conversion.
 *
 * All writes are parameterized query-builder `insert()` calls — a source
 * row's string content is never interpolated into SQL (T-13.5-13).
 */
final class StagingWriter
{
    /**
     * Maximum rows persisted per `insert()` call against
     * `migration_staging_transactions` — mirrors `RecordTransactions`'s
     * `CHUNK_SIZE = 500` convention (Pitfall 4 / D-05).
     */
    private const CHUNK_SIZE = 500;

    /**
     * Fallback account `kind` value: neither `Ynab4Parser`/`NynabParser`
     * (no per-account type signal in either CSV export) nor `ActualParser`
     * ever populates `MigrationAccountDto::$kind`, but the staging schema's
     * `kind` column is NOT NULL. This placeholder is staged verbatim and
     * carries no promotion-time significance beyond satisfying the column.
     */
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
     * Streams the batch's lazy transaction generator directly into
     * `migration_staging_transactions` in bounded chunks. Never
     * materializes the generator into a PHP array (Pitfall 4) — the
     * `foreach` below iterates it lazily one `MigrationTransactionDto` at a
     * time, and each DTO's own row(s) are appended to a bounded `$chunk`
     * buffer that is flushed the moment it reaches `CHUNK_SIZE`.
     *
     * @param  iterable<int, MigrationTransactionDto>  $transactions
     */
    private function writeTransactions(iterable $transactions, int $migrationRunId, User $user): void
    {
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
     * Builds the one-or-more staging rows a single `MigrationTransactionDto`
     * expands to: exactly one row for a plain transaction, or one parent row
     * plus one row per split leg for a split parent.
     *
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
