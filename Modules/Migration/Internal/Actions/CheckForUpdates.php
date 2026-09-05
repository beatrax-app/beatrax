<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Migration\Internal\Dto\ConflictDto;
use Modules\Migration\Internal\Dto\UnreconciledFieldDto;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Internal\Pipeline\ConflictValueCodec;
use Modules\Migration\Internal\Pipeline\ThreeWayMergeResolver;
use Modules\Migration\Internal\Support\ConflictLabel;
use Modules\Migration\Models\MigrationRun;

final readonly class CheckForUpdates
{
    public function __construct(
        private DatabaseManager $db,
        private StartMigrationRun $startMigrationRun,
        private ThreeWayMergeResolver $resolver,
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

        $newRun = $this->startMigrationRun->__invoke($user, $sourceProduct, $extractedPath, $prior->original_filename);

        $decision = $this->resolver->resolve($newRun->id, $user, $sourceProduct);

        foreach ($decision->conflicts as $conflict) {
            $this->recordConflict($newRun->id, $user, $conflict);
        }

        // Staged here and not at confirm: ConfirmMigration re-resolves against
        // the same rows and would report the same refusal a second time, and
        // this run cannot reach confirm without passing through here first.
        foreach ($decision->unreconciled as $unreconciled) {
            $this->recordUnreconciled($newRun->id, $user, $unreconciled);
        }

        // Nothing here touches a domain table: this step reads what WOULD
        // happen, so a Discard leaves the ledger exactly as it found it.
        // ConfirmMigration re-resolves and is the only writer.
        $newRun->update(['status' => MigrationRunStatus::NeedsAttention->value]);

        return $newRun->refresh();
    }

    private function recordConflict(int $runId, User $user, ConflictDto $conflict): void
    {
        $this->db->connection()->table('migration_staging_unmapped_items')->insert([
            'user_id' => $user->id,
            'migration_run_id' => $runId,
            'item_type' => UnmappedItemType::Conflict->value,
            'source_external_id' => $conflict->sourceExternalId,
            'entity_type' => $conflict->entityType,
            'field_name' => $conflict->fieldName,
            'local_value' => ConflictValueCodec::toStorage($conflict->localValue),
            'source_value' => ConflictValueCodec::toStorage($conflict->sourceValue),
            'baseline_value' => ConflictValueCodec::toStorage($conflict->baselineValue),
            'currency' => $conflict->currency,
            'resolution' => null,
            'display_label' => StoredCopy::of(CopyLine::of(ConflictLabel::keyFor($conflict->entityType, $conflict->fieldName))),
            'reason' => StoredCopy::of(CopyLine::of('migration::unmapped.reason.changed_on_both_sides', [
                'local' => self::scalarToDisplay($conflict->localValue),
                'source' => self::scalarToDisplay($conflict->sourceValue),
                'baseline' => self::scalarToDisplay($conflict->baselineValue),
            ])),
        ]);
    }

    private function recordUnreconciled(int $runId, User $user, UnreconciledFieldDto $item): void
    {
        $this->db->connection()->table('migration_staging_unmapped_items')->insert([
            'user_id' => $user->id,
            'migration_run_id' => $runId,
            'item_type' => UnmappedItemType::Extra->value,
            'source_external_id' => $item->entityType.'|'.$item->fieldName.'|'.$item->sourceCurrency,
            'display_label' => StoredCopy::of(CopyLine::of('migration::unmapped.label.amount_update')),
            'reason' => StoredCopy::of(CopyLine::of('migration::unmapped.reason.amount_currency_mismatch', [
                'local' => $item->localCurrency,
                'source' => $item->sourceCurrency,
            ])),
        ]);
    }

    // A stored value, not a sentence: the raw scalar is what the reader is
    // being asked to compare, so it rides verbatim. Only the word standing in
    // for "no value at all" is copy, and it travels as a key.
    private static function scalarToDisplay(mixed $value): string|CopyParam
    {
        return match (true) {
            $value === null => CopyParam::line('migration::unmapped.value.none'),
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
