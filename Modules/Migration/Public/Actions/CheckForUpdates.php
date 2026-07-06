<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Internal\Pipeline\MergeDecision;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;
use Modules\Migration\Internal\Pipeline\ThreeWayMergeResolver;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Dto\ConflictDto;

/**
 * Reconciliation entry point (Req 10, D-11/D-12/D-13/D-14 — the highest-value
 * correctness feature of this phase, no codebase analog): given an
 * already-confirmed prior run and a newer export of the SAME source product,
 * this (1) parses + stages the newer export into a fresh `migration_runs` row
 * via `StartMigrationRun` (reused, not duplicated — Req 1's no-write-before
 * -domain-write discipline still holds for the parse/stage half), (2) runs
 * `ThreeWayMergeResolver` to partition every already-mapped entity into an
 * apply-set and a conflict-set, (3) applies ONLY the non-conflicting changes
 * through the existing public writers (`PromoteStagingToDomain::promote()`'s
 * per-entity resolve-gate already handles brand-new entities; a new
 * `$skipBudgetAssignmentKeys` list keeps conflicted budget-assignment rows
 * byte-for-byte untouched — the ONE entity kind `promote()` applies
 * unconditionally), (4) persists every conflict as a
 * `migration_staging_unmapped_items` row (`item_type = 'conflict'`) so
 * `PreviewSummaryBuilder`'s existing generic "Needs your decision" group
 * picks it up with zero further changes, and (5) advances the baseline to
 * the LOCAL value for every conflict (D-14's keep-local default — the
 * conflicting source value is discarded, not silently applied, and the
 * identical already-decided divergence will not re-flag on the next
 * reconciliation run).
 *
 * The new run's final `status` is `'needs_attention'` while unresolved
 * conflicts exist, else `'confirmed'` (mirrors `ConfirmMigration`'s
 * lifecycle-flip discipline, but flips based on the reconciliation outcome
 * rather than a user confirm click — Plan 08's wizard surfaces this result,
 * it does not gate it behind a second explicit action for this plan's scope).
 *
 * T-13.5-22 (run_id IDOR): `$priorConfirmedRunId` must resolve to a
 * user-owned, CONFIRMED run of the declared `$sourceProduct` — any other
 * outcome (foreign run, wrong status, wrong product) throws via
 * `firstOrFail()` (translated to a 404, never a 403, matching this
 * codebase's ASVS V4 convention).
 */
final class CheckForUpdates
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly StartMigrationRun $startMigrationRun,
        private readonly ThreeWayMergeResolver $resolver,
        private readonly PromoteStagingToDomain $promoter,
        private readonly SourceMapWriter $sourceMapWriter,
    ) {}

    public function __invoke(int $priorConfirmedRunId, User $user, string $sourceProduct, string $extractedPath): MigrationRun
    {
        /** @var MigrationRun $prior */
        $prior = MigrationRun::query()
            ->where('id', $priorConfirmedRunId)
            ->where('user_id', $user->id)
            ->where('source_product', $sourceProduct)
            ->where('status', 'confirmed')
            ->firstOrFail();

        // Parse + stage the newer export into its OWN fresh run — reuses
        // StartMigrationRun rather than duplicating parser-selection/staging
        // logic; nothing domain-side is written by this call.
        $newRun = $this->startMigrationRun->__invoke($user, $sourceProduct, $extractedPath, $prior->original_filename);

        $decision = $this->resolver->resolve($newRun->id, $user, $sourceProduct);

        foreach ($decision->conflicts as $conflict) {
            $this->recordConflict($newRun->id, $user, $conflict);
            $this->advanceBaselineToLocal($user, $sourceProduct, $conflict);
        }

        // Every OTHER entity kind (categories/accounts/transactions/
        // transfers/goals) already resolve-gates per-row inside promote()
        // (Req 9) — a re-confirm-shaped call is safe to make here unchanged.
        // Budget assignments are the one unconditional-apply exception, so
        // the conflicted composite keys are threaded through as an explicit
        // skip-list (D-12/D-13).
        $this->promoter->promote($newRun->id, $user, $decision->conflictedBudgetAssignmentKeys());

        $this->applyNonBudgetAssignmentChanges($user, $sourceProduct, $decision);

        $hasConflicts = $decision->conflicts !== [];

        /** @var array{status: string, confirmed_at?: CarbonImmutable} $attrs */
        $attrs = ['status' => $hasConflicts ? 'needs_attention' : 'confirmed'];
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
            'display_label' => ucfirst($conflict->entityType).' '.$conflict->fieldName,
            'reason' => sprintf(
                "Both the source file and beatrax changed this since the last import — local value kept.\nLocal: %s\nSource: %s\nLast imported: %s",
                self::scalarToDisplay($conflict->localValue),
                self::scalarToDisplay($conflict->sourceValue),
                self::scalarToDisplay($conflict->baselineValue),
            ),
        ]);
    }

    /**
     * D-14: an unresolved conflict defaults to keep-local — the beatrax value
     * is left untouched (never applied here) AND the baseline is advanced to
     * that same local value, so the identical already-decided divergence
     * does not re-flag on the next reconciliation run (RESEARCH.md's
     * documented baseline-advance subtlety).
     */
    private function advanceBaselineToLocal(User $user, string $sourceProduct, ConflictDto $conflict): void
    {
        if ($conflict->sourceExternalId === null) {
            return;
        }

        $beatraxId = $this->sourceMapWriter->resolve($user, $sourceProduct, $conflict->entityType, $conflict->sourceExternalId);
        if ($beatraxId === null) {
            return; // defensive: a conflict only ever exists for an already-mapped entity.
        }

        $this->sourceMapWriter->record(
            $user,
            $sourceProduct,
            $conflict->entityType,
            $conflict->sourceExternalId,
            null,
            self::beatraxEntityType($conflict->entityType),
            $beatraxId,
            [$conflict->fieldName => self::scalarOrNull($conflict->localValue)],
        );
    }

    /**
     * Applies every non-`budget_assignment` apply-set entry directly (a
     * plain, user-scoped table update on the entity's field) — unlike
     * budget assignments, `promote()` never re-visits an already-mapped
     * category/account/transaction row, so a non-conflicting rename/edit
     * needs this explicit path. Advances the baseline to the newly-applied
     * source value afterward.
     */
    private function applyNonBudgetAssignmentChanges(User $user, string $sourceProduct, MergeDecision $decision): void
    {
        $connection = $this->db->connection();

        foreach ($decision->applies as $apply) {
            if ($apply['entityType'] === 'budget_assignment') {
                // Already applied unconditionally inside promote()'s
                // promoteBudgetAssignments() pass above.
                continue;
            }

            $table = match ($apply['entityType']) {
                'category' => 'categories',
                'account' => 'accounts',
                'transaction' => 'transactions',
                default => null,
            };

            if ($table === null) {
                continue;
            }

            $beatraxId = $this->sourceMapWriter->resolve($user, $sourceProduct, $apply['entityType'], $apply['sourceExternalId']);
            if ($beatraxId === null) {
                continue;
            }

            $connection->table($table)
                ->where('id', $beatraxId)
                ->where('user_id', $user->id)
                ->update($apply['fields']);

            $this->sourceMapWriter->record(
                $user,
                $sourceProduct,
                $apply['entityType'],
                $apply['sourceExternalId'],
                null,
                self::beatraxEntityType($apply['entityType']),
                $beatraxId,
                $apply['fields'],
            );
        }
    }

    private static function beatraxEntityType(string $entityType): string
    {
        return match ($entityType) {
            'budget_assignment' => 'envelope_assignment',
            default => $entityType,
        };
    }

    private static function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) || $value === null ? $value : (string) json_encode($value);
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
