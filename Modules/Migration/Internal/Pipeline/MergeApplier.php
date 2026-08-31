<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Migration\Internal\Enums\MigrationEntityType;
use Modules\Migration\Internal\Enums\UnmappedItemType;

// The non-conflicting half of a reconciliation: every field the source moved
// while Beatrax stood still. Conflicts are the reader's to settle and are
// applied by ConfirmMigration from the persisted resolution instead.
final readonly class MergeApplier
{
    public function __construct(
        private DatabaseManager $db,
        private EntityChangeApplier $applier,
    ) {}

    /**
     * @param  list<string>  $deferredKeys  `{entityType}|{sourceExternalId}|{field}` triples already
     *                                      recorded as conflicts for this run.
     */
    public function applyNonBudgetAssignmentChanges(int $runId, User $user, string $sourceProduct, MergeDecision $decision, array $deferredKeys = []): void
    {
        foreach ($decision->applies as $apply) {
            if ($apply['entityType'] === MigrationEntityType::BudgetAssignment->value) {
                // Already applied unconditionally inside promote()'s
                // PromoteBudgetAssignments pass.
                continue;
            }

            $fields = self::withoutDeferredFields($apply['entityType'], $apply['sourceExternalId'], $apply['fields'], $deferredKeys);
            if ($fields === []) {
                continue;
            }

            $applied = $this->applier->apply($user, $sourceProduct, $apply['entityType'], $apply['sourceExternalId'], $fields);

            if (! $applied && $apply['entityType'] === MigrationEntityType::Transaction->value && array_key_exists('amount_minor', $fields)) {
                // The new amount collides with another row's fingerprint tuple.
                // Left untouched and surfaced, never half-applied.
                $this->recordAmountApplyCollision($runId, $user, $apply['sourceExternalId']);
            }
        }
    }

    public static function deferredKey(string $entityType, ?string $sourceExternalId, string $fieldName): string
    {
        return $entityType.'|'.($sourceExternalId ?? '').'|'.$fieldName;
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $fields
     * @param  list<string>  $deferredKeys
     * @return array<string, string|int|float|bool|null>
     */
    private static function withoutDeferredFields(string $entityType, string $sourceExternalId, array $fields, array $deferredKeys): array
    {
        if ($deferredKeys === []) {
            return $fields;
        }

        return array_filter(
            $fields,
            static fn (string $field): bool => ! in_array(self::deferredKey($entityType, $sourceExternalId, $field), $deferredKeys, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function recordAmountApplyCollision(int $runId, User $user, string $sourceExternalId): void
    {
        $this->db->connection()->table('migration_staging_unmapped_items')->insert([
            'user_id' => $user->id,
            'migration_run_id' => $runId,
            'item_type' => UnmappedItemType::Extra->value,
            'source_external_id' => $sourceExternalId,
            'display_label' => StoredCopy::of(CopyLine::of('migration::unmapped.label.amount_update')),
            'reason' => StoredCopy::of(CopyLine::of('migration::unmapped.reason.amount_apply_collision')),
        ]);
    }
}
