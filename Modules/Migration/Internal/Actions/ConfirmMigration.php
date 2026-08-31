<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Internal\Dto\MigrationConfirmResult;
use Modules\Migration\Internal\Enums\ConflictResolution;
use Modules\Migration\Internal\Enums\MigrationEntityType;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Internal\Exceptions\MigrationAlreadyDiscardedException;
use Modules\Migration\Internal\Pipeline\ConflictRow;
use Modules\Migration\Internal\Pipeline\ConflictValueCodec;
use Modules\Migration\Internal\Pipeline\EntityChangeApplier;
use Modules\Migration\Internal\Pipeline\MergeApplier;
use Modules\Migration\Internal\Pipeline\PromoteResult;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;
use Modules\Migration\Internal\Pipeline\ThreeWayMergeResolver;
use Modules\Migration\Models\MigrationRun;
use stdClass;

final readonly class ConfirmMigration
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private PromoteStagingToDomain $promoter,
        private EntityChangeApplier $applier,
        private ThreeWayMergeResolver $resolver,
        private MergeApplier $mergeApplier,
    ) {}

    public function __invoke(int $migrationRunId, User $user): MigrationConfirmResult
    {
        /** @var MigrationRun $run */
        $run = MigrationRun::query()
            ->where('id', $migrationRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($run->status === MigrationRunStatus::Confirmed->value) {
            return new MigrationConfirmResult(
                migrationRunId: $migrationRunId,
                categoriesCreated: $run->categories_count,
                accountsCreated: $run->accounts_count,
                transactionsInserted: $run->transactions_inserted_count,
                transactionsSkipped: $run->transactions_skipped_count,
                splitsCreated: $run->splits_count,
                transfersPaired: $run->transfers_paired_count,
                counterpartiesResolved: $run->counterparties_resolved_count,
                goalsCreated: $run->goals_created_count,
            );
        }

        // Staging for a discarded run is already truncated, so falling through
        // to promote() would flip the status back to 'confirmed' with all-zero
        // counts. DiscardMigrationRun carries the symmetric guard.
        if ($run->status === MigrationRunStatus::Discarded->value) {
            throw new MigrationAlreadyDiscardedException($migrationRunId);
        }

        // A 'needs_attention' run records every conflict with resolution NULL,
        // so re-deriving a default here would overwrite a keep-local decision.
        $conflicts = $this->loadConflicts($migrationRunId, $user);

        $skipBudgetAssignmentKeys = [];
        foreach ($conflicts as $conflict) {
            if ($conflict->entityType === MigrationEntityType::BudgetAssignment->value && $conflict->resolution !== ConflictResolution::TakeSource && $conflict->sourceExternalId !== null) {
                $skipBudgetAssignmentKeys[] = $conflict->sourceExternalId;
            }
        }

        // CheckForUpdates only staged and listed; the merge is re-derived here
        // against live values, so confirming is the first and only moment a
        // reconciliation reaches a domain table.
        $decision = $run->status === MigrationRunStatus::NeedsAttention->value
            ? $this->resolver->resolve($migrationRunId, $user, $run->source_product)
            : null;

        // No outer transaction: promote()'s per-entity writes are already
        // chunked, and wrapping them would collapse them into one unbounded one.
        $promoteResult = $this->promoter->promote($migrationRunId, $user, $skipBudgetAssignmentKeys);

        if ($decision !== null) {
            $this->mergeApplier->applyNonBudgetAssignmentChanges(
                $migrationRunId,
                $user,
                $run->source_product,
                $decision,
                self::deferredConflictKeys($conflicts),
            );
        }

        $this->applyTakeSourceConflicts($conflicts, $user, $run->source_product);
        $this->advanceKeepLocalConflictBaselines($conflicts, $user, $run->source_product);

        return $this->db->connection()->transaction(
            fn (): MigrationConfirmResult => $this->flipToConfirmed($run, $promoteResult),
        );
    }

    /**
     * @param  list<ConflictRow>  $conflicts
     * @return list<string>
     */
    private static function deferredConflictKeys(array $conflicts): array
    {
        $keys = [];
        foreach ($conflicts as $conflict) {
            $keys[] = MergeApplier::deferredKey($conflict->entityType, $conflict->sourceExternalId, $conflict->fieldName);
        }

        return $keys;
    }

    /**
     * @return list<ConflictRow>
     */
    private function loadConflicts(int $migrationRunId, User $user): array
    {
        $rows = $this->db->connection()->table('migration_staging_unmapped_items')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->where('item_type', UnmappedItemType::Conflict->value)
            ->whereNotNull('entity_type')
            ->get(['entity_type', 'source_external_id', 'field_name', 'local_value', 'source_value', 'resolution']);

        $conflicts = [];
        /** @var stdClass $row */
        foreach ($rows as $row) {
            if (! is_string($row->entity_type) || ! is_string($row->field_name)) {
                continue;
            }

            $conflicts[] = new ConflictRow(
                entityType: $row->entity_type,
                sourceExternalId: is_string($row->source_external_id) ? $row->source_external_id : null,
                fieldName: $row->field_name,
                localValue: is_string($row->local_value) ? $row->local_value : null,
                sourceValue: is_string($row->source_value) ? $row->source_value : null,
                resolution: is_string($row->resolution)
                    ? ConflictResolution::tryFrom($row->resolution) ?? ConflictResolution::KeepLocal
                    : ConflictResolution::KeepLocal,
            );
        }

        return $conflicts;
    }

    /**
     * @param  list<ConflictRow>  $conflicts
     */
    private function applyTakeSourceConflicts(array $conflicts, User $user, string $sourceProduct): void
    {
        foreach ($conflicts as $conflict) {
            // budget_assignment is deliberately excluded: its take-source
            // application already happened inside promote() above.
            if ($conflict->entityType === MigrationEntityType::BudgetAssignment->value || $conflict->resolution !== ConflictResolution::TakeSource) {
                continue;
            }
            if ($conflict->sourceExternalId === null) {
                continue;
            }

            $value = ConflictValueCodec::fromStorage($conflict->sourceValue, $conflict->fieldName);
            $this->applier->apply($user, $sourceProduct, $conflict->entityType, $conflict->sourceExternalId, [$conflict->fieldName => $value]);
        }
    }

    /**
     * @param  list<ConflictRow>  $conflicts
     */
    private function advanceKeepLocalConflictBaselines(array $conflicts, User $user, string $sourceProduct): void
    {
        foreach ($conflicts as $conflict) {
            // A take-source budget_assignment had its baseline advanced by
            // PromoteBudgetAssignments already.
            if ($conflict->resolution === ConflictResolution::TakeSource) {
                continue;
            }

            // The baseline records what the FILE said, not what the reader kept:
            // storing the local value here makes the same unchanged export read
            // as a source-side change next time, and the next import then
            // "applies" the very value this keep-local rejected.
            $value = ConflictValueCodec::fromStorage($conflict->sourceValue, $conflict->fieldName);
            $this->applier->advanceBaseline($user, $sourceProduct, $conflict->entityType, $conflict->sourceExternalId, $conflict->fieldName, $value);
        }
    }

    private function flipToConfirmed(MigrationRun $run, PromoteResult $promoteResult): MigrationConfirmResult
    {
        $run->update([
            'status' => MigrationRunStatus::Confirmed->value,
            'confirmed_at' => $this->clock->now(),
            'categories_count' => $promoteResult->categoriesCreated,
            'accounts_count' => $promoteResult->accountsCreated,
            'transactions_inserted_count' => $promoteResult->transactionsInserted,
            'transactions_skipped_count' => $promoteResult->transactionsSkipped,
            'splits_count' => $promoteResult->splitsCreated,
            'transfers_paired_count' => $promoteResult->transfersPaired,
            'counterparties_resolved_count' => $promoteResult->counterpartiesResolved,
            'goals_created_count' => $promoteResult->goalsCreated,
        ]);

        return new MigrationConfirmResult(
            migrationRunId: $run->id,
            categoriesCreated: $promoteResult->categoriesCreated,
            accountsCreated: $promoteResult->accountsCreated,
            transactionsInserted: $promoteResult->transactionsInserted,
            transactionsSkipped: $promoteResult->transactionsSkipped,
            splitsCreated: $promoteResult->splitsCreated,
            transfersPaired: $promoteResult->transfersPaired,
            counterpartiesResolved: $promoteResult->counterpartiesResolved,
            goalsCreated: $promoteResult->goalsCreated,
        );
    }
}
