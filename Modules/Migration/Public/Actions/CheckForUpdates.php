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
 * unconditionally), and (4) persists every conflict as a
 * `migration_staging_unmapped_items` row (`item_type = 'conflict'`) so
 * `PreviewSummaryBuilder`'s existing generic "Needs your decision" group
 * picks it up with zero further changes. The conflict is left UNRESOLVED
 * here (see the Test 3c gap-fix note below) — baseline advancement now
 * happens in `ConfirmMigration`, once the user's actual keep-local/
 * take-source decision is known.
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
 *
 * Req 10 gap-fix (13.5-VERIFICATION.md): a transaction's native
 * `amount_minor` is now reconciled by the SAME apply-set/conflict-set flow
 * as budget_assignment/category/account/description — a conflicted
 * transaction amount is left byte-for-byte untouched (never overwritten by
 * a LATER `ConfirmMigration` call either, since `PromoteStagingToDomain`'s
 * transaction step already resolve-gates on `migration_source_map` and
 * never revisits an already-mapped row — no separate skip-list was needed
 * for transactions, unlike budget_assignment's CR-01 fix). A non-conflicting
 * amount apply recomputes the transaction's stored `fingerprint` in the same
 * update (see `EntityChangeApplier::applyTransactionAmount()`), since
 * `amount_minor` — unlike `description` — is part of the fingerprint tuple
 * and the `transactions_fingerprint_uq` composite unique index.
 *
 * 13.5-HUMAN-UAT.md Test 3c gap-fix: a conflict is no longer FINALIZED here.
 * Previously `recordConflict()` was immediately followed by
 * `advanceBaselineToLocal()`, unconditionally committing D-14's keep-local
 * default before the user ever saw the preview page — the wizard's "Take
 * source" toggle had nothing left to change. Now `recordConflict()` persists
 * the full local/source/baseline value triple (plus the entity identity and
 * field name) onto the `migration_staging_unmapped_items` row and leaves
 * `resolution` NULL; `PreviewMigration::resolveConflict()` writes the user's
 * actual choice there, and `ConfirmMigration` is the ONLY place that now
 * applies a resolution (source value or nothing) and advances the baseline
 * — see that class's docblock. The entity-field write routing itself
 * (`EntityChangeApplier::apply()`) is unchanged and shared between this
 * class's own non-conflicting apply-set and `ConfirmMigration`'s
 * take-source conflict apply, so there is exactly one writer per entity
 * kind, not two.
 */
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
            ->where('status', 'confirmed')
            ->firstOrFail();

        // Parse + stage the newer export into its OWN fresh run — reuses
        // StartMigrationRun rather than duplicating parser-selection/staging
        // logic; nothing domain-side is written by this call.
        $newRun = $this->startMigrationRun->__invoke($user, $sourceProduct, $extractedPath, $prior->original_filename);

        $decision = $this->resolver->resolve($newRun->id, $user, $sourceProduct);

        foreach ($decision->conflicts as $conflict) {
            $this->recordConflict($newRun->id, $user, $conflict);
        }

        // Every OTHER entity kind (categories/accounts/transactions/
        // transfers/goals) already resolve-gates per-row inside promote()
        // (Req 9) — a re-confirm-shaped call is safe to make here unchanged.
        // Budget assignments are the one unconditional-apply exception, so
        // the conflicted composite keys are threaded through as an explicit
        // skip-list (D-12/D-13).
        $this->promoter->promote($newRun->id, $user, $decision->conflictedBudgetAssignmentKeys());

        $this->applyNonBudgetAssignmentChanges($newRun->id, $user, $sourceProduct, $decision);

        $hasConflicts = $decision->conflicts !== [];

        /** @var array{status: string, confirmed_at?: CarbonImmutable} $attrs */
        $attrs = ['status' => $hasConflicts ? 'needs_attention' : 'confirmed'];
        if (! $hasConflicts) {
            $attrs['confirmed_at'] = $this->clock->now();
        }

        $newRun->update($attrs);

        return $newRun->refresh();
    }

    /**
     * Persists the FULL local/source/baseline value triple (plus the entity
     * identity and field name, and a currency code when the field is
     * money-shaped) so `ConfirmMigration` can apply EITHER outcome later —
     * `resolution` starts NULL (no decision made yet, D-14's keep-local
     * default renders until the user touches the toggle). `display_label`/
     * `reason` are still populated with the same plain-text shape prior
     * versions of this class wrote (several tests query `display_label`
     * directly) but the wizard's own rendering now recomputes a human label
     * + resolution-aware copy from the structured columns instead
     * (`PreviewSummaryBuilder`), so these two are effectively a legacy/
     * debug-only mirror going forward, not the UI's source of truth.
     */
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

    /**
     * Applies every non-`budget_assignment` apply-set entry via the shared
     * `EntityChangeApplier` (a plain, user-scoped table update on the
     * entity's field, or the fingerprint-safe transaction-amount path) —
     * unlike budget assignments, `promote()` never re-visits an
     * already-mapped category/account/transaction row, so a non-conflicting
     * rename/edit needs this explicit path.
     */
    private function applyNonBudgetAssignmentChanges(int $runId, User $user, string $sourceProduct, MergeDecision $decision): void
    {
        foreach ($decision->applies as $apply) {
            if ($apply['entityType'] === 'budget_assignment') {
                // Already applied unconditionally inside promote()'s
                // promoteBudgetAssignments() pass above.
                continue;
            }

            $applied = $this->applier->apply($user, $sourceProduct, $apply['entityType'], $apply['sourceExternalId'], $apply['fields']);

            if (! $applied && $apply['entityType'] === 'transaction' && array_key_exists('amount_minor', $apply['fields'])) {
                // Vanishingly rare: the new amount collides with another
                // row's fingerprint tuple (same account/date/currency/
                // counterparty). Left byte-for-byte untouched rather than
                // silently dropped or half-applied — surfaced as a visible
                // unmapped item (mirrors CR-02's own fingerprint-collision-
                // visibility precedent).
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
