<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Migration\Internal\Pipeline\MergeDecision;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;
use Modules\Migration\Internal\Pipeline\ThreeWayMergeResolver;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Dto\ConflictDto;
use stdClass;

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
 * update (see `applyTransactionAmount()`), since `amount_minor` — unlike
 * `description` — is part of the fingerprint tuple and the
 * `transactions_fingerprint_uq` composite unique index.
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
        private readonly FingerprintComposer $fingerprints,
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
     *
     * A transaction `amount_minor` change is routed through
     * `applyTransactionAmount()` instead of a bare column update: unlike a
     * description/name rename, `amount_minor` is part of the fingerprint
     * tuple AND the `transactions_fingerprint_uq` composite unique index
     * (Req 10 gap-fix — 13.5-VERIFICATION.md), so the stored `fingerprint`
     * column must be recomputed in the SAME update or it goes stale.
     */
    private function applyNonBudgetAssignmentChanges(int $runId, User $user, string $sourceProduct, MergeDecision $decision): void
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

            if ($apply['entityType'] === 'transaction' && array_key_exists('amount_minor', $apply['fields'])) {
                $newAmountMinor = $apply['fields']['amount_minor'];
                $applied = is_int($newAmountMinor)
                    && $this->applyTransactionAmount($user, $beatraxId, $newAmountMinor);

                if (! $applied) {
                    // Vanishingly rare: the new amount collides with another
                    // row's fingerprint tuple (same account/date/currency/
                    // counterparty). Left byte-for-byte untouched rather
                    // than silently dropped or half-applied — surfaced as a
                    // visible unmapped item (mirrors CR-02's own
                    // fingerprint-collision-visibility precedent).
                    $this->recordAmountApplyCollision($runId, $user, $apply['sourceExternalId']);

                    continue;
                }
            } else {
                $connection->table($table)
                    ->where('id', $beatraxId)
                    ->where('user_id', $user->id)
                    ->update($apply['fields']);
            }

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

    /**
     * Updates a transaction's `amount_minor` AND recomputes its stored
     * `fingerprint` in the same statement, so the second-layer SHA-256
     * idempotency guard (`FingerprintComposer`) never drifts from the row's
     * actual content. Returns false (no write performed) on a genuine
     * `transactions_fingerprint_uq` collision rather than letting the raw
     * `QueryException` bubble out of the whole reconciliation.
     */
    private function applyTransactionAmount(User $user, int $transactionId, int $newAmountMinor): bool
    {
        $connection = $this->db->connection();

        /** @var stdClass|null $row */
        $row = $connection->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return false;
        }

        $canonical = new CanonicalTransaction(
            userId: $user->id,
            accountId: self::toInt($row->account_id),
            type: self::toStr($row->type),
            postedAt: CarbonImmutable::parse(self::toStr($row->posted_at)),
            bookedAt: CarbonImmutable::parse(self::toStr($row->booked_at)),
            valueDate: CarbonImmutable::parse(self::toStr($row->value_date)),
            amountMinor: $newAmountMinor,
            currency: self::toStr($row->currency),
            settledAmountMinor: self::toInt($row->settled_amount_minor),
            settledCurrency: self::toStr($row->settled_currency),
            fxRateUsed: $row->fx_rate_used !== null ? self::toStr($row->fx_rate_used) : null,
            counterpartyName: $row->counterparty_name !== null ? self::toStr($row->counterparty_name) : null,
            counterpartyIban: $row->counterparty_iban !== null ? self::toStr($row->counterparty_iban) : null,
            counterpartyNormalized: self::toStr($row->counterparty_normalized),
            normalizationVersion: self::toInt($row->normalization_version),
            description: $row->description !== null ? self::toStr($row->description) : null,
            categoryId: $row->category_id !== null ? self::toInt($row->category_id) : null,
            sourceFormat: self::toStr($row->source_format),
            importRunId: self::toInt($row->import_run_id),
            sourceRowIndex: self::toInt($row->source_row_index),
            sourceRef: $row->source_ref !== null ? self::toStr($row->source_ref) : null,
        );

        $fingerprint = $this->fingerprints->compose($canonical);

        try {
            $connection->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->update([
                    'amount_minor' => $newAmountMinor,
                    'fingerprint' => $fingerprint,
                ]);
        } catch (QueryException) {
            return false;
        }

        return true;
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
