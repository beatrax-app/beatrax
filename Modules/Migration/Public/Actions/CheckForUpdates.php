<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Internal\Pipeline\ConflictValueCodec;
use Modules\Migration\Internal\Pipeline\EntityChangeApplier;
use Modules\Migration\Internal\Pipeline\MergeDecision;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;
use Modules\Migration\Internal\Pipeline\ThreeWayMergeResolver;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Dto\ConflictDto;
use Modules\Migration\Public\Enums\MigrationEntityType;
use Modules\Migration\Public\Enums\MigrationRunStatus;

final class CheckForUpdates
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly StartMigrationRun $startMigrationRun,
        private readonly ThreeWayMergeResolver $resolver,
        private readonly PromoteStagingToDomain $promoter,
        private readonly EntityChangeApplier $applier,
    ) {}

    public function __invoke(int $priorConfirmedRunId, User $user, string $sourceProduct, string $extractedPath): MigrationRun
    {
        /** @var MigrationRun $prior */
        $prior = MigrationRun::query()
            ->where('id', $priorConfirmedRunId)
            ->where('user_id', $user->id)
            ->where('source_product', $sourceProduct)
            ->where('status', MigrationRunStatus::Confirmed->value)
            ->firstOrFail();

        // Reuses StartMigrationRun (rather than duplicating parser-selection/
        // staging logic) to parse + stage the newer export into its own
        // fresh run; nothing domain-side is written by this call.
        $newRun = $this->startMigrationRun->__invoke($user, $sourceProduct, $extractedPath, $prior->original_filename);

        $decision = $this->resolver->resolve($newRun->id, $user, $sourceProduct);

        foreach ($decision->conflicts as $conflict) {
            $this->recordConflict($newRun->id, $user, $conflict);
        }

        // Every other entity kind already resolve-gates per-row inside
        // promote(); budget assignments are the one unconditional-apply
        // exception, so the conflicted composite keys are threaded through
        // as an explicit skip-list.
        $this->promoter->promote($newRun->id, $user, $decision->conflictedBudgetAssignmentKeys());

        $this->applyNonBudgetAssignmentChanges($newRun->id, $user, $sourceProduct, $decision);

        $hasConflicts = $decision->conflicts !== [];

        /** @var array{status: string, confirmed_at?: CarbonImmutable} $attrs */
        $attrs = ['status' => $hasConflicts ? MigrationRunStatus::NeedsAttention->value : MigrationRunStatus::Confirmed->value];
        if (! $hasConflicts) {
            $attrs['confirmed_at'] = $this->clock->now();
        }

        $newRun->update($attrs);

        return $newRun->refresh();
    }

    private function recordConflict(int $runId, User $user, ConflictDto $conflict): void
    {
        $this->db->connection()->table('migration_staging_unmapped_items')->insert([
            'user_id' => $user->id,
            'migration_run_id' => $runId,
            'item_type' => 'conflict',
            'source_external_id' => $conflict->sourceExternalId,
            'entity_type' => $conflict->entityType,
            'field_name' => $conflict->fieldName,
            'local_value' => ConflictValueCodec::toStorage($conflict->localValue),
            'source_value' => ConflictValueCodec::toStorage($conflict->sourceValue),
            'baseline_value' => ConflictValueCodec::toStorage($conflict->baselineValue),
            'currency' => $conflict->currency,
            'resolution' => null,
            'display_label' => ucfirst($conflict->entityType).' '.$conflict->fieldName,
            'reason' => sprintf(
                "Both the source file and beatrax changed this since the last import.\nLocal: %s\nSource: %s\nLast imported: %s",
                self::scalarToDisplay($conflict->localValue),
                self::scalarToDisplay($conflict->sourceValue),
                self::scalarToDisplay($conflict->baselineValue),
            ),
        ]);
    }

    private function applyNonBudgetAssignmentChanges(int $runId, User $user, string $sourceProduct, MergeDecision $decision): void
    {
        foreach ($decision->applies as $apply) {
            if ($apply['entityType'] === MigrationEntityType::BudgetAssignment->value) {
                // Already applied unconditionally inside promote()'s
                // promoteBudgetAssignments() pass above.
                continue;
            }

            $applied = $this->applier->apply($user, $sourceProduct, $apply['entityType'], $apply['sourceExternalId'], $apply['fields']);

            if (! $applied && $apply['entityType'] === MigrationEntityType::Transaction->value && array_key_exists('amount_minor', $apply['fields'])) {
                // Vanishingly rare: the new amount collides with another
                // row's fingerprint tuple. Left byte-for-byte untouched
                // rather than silently dropped or half-applied — surfaced
                // as a visible unmapped item instead.
                $this->recordAmountApplyCollision($runId, $user, $apply['sourceExternalId']);
            }
        }
    }

    private function recordAmountApplyCollision(int $runId, User $user, string $sourceExternalId): void
    {
        $this->db->connection()->table('migration_staging_unmapped_items')->insert([
            'user_id' => $user->id,
            'migration_run_id' => $runId,
            'item_type' => 'extra',
            'source_external_id' => $sourceExternalId,
            'display_label' => 'Transaction amount update',
            'reason' => "The source's new amount could not be applied — it collides with another transaction's fingerprint (same account, date, currency and counterparty). Left unchanged.",
        ]);
    }

    private static function scalarToDisplay(mixed $value): string
    {
        return match (true) {
            $value === null => '(none)',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
