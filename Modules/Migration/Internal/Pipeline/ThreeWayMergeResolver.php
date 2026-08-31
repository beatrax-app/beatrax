<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Internal\Dto\ConflictDto;
use Modules\Migration\Internal\Enums\MigrationEntityType;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final readonly class ThreeWayMergeResolver
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private PeriodQuery $periods,
    ) {}

    public function resolve(int $newRunId, User $user, string $sourceProduct): MergeDecision
    {
        /** @var list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}> $applies */
        $applies = [];
        /** @var list<ConflictDto> $conflicts */
        $conflicts = [];

        $this->reconcileBudgetAssignments($newRunId, $user, $sourceProduct, $applies, $conflicts);
        $this->reconcileCategories($newRunId, $user, $sourceProduct, $applies, $conflicts);
        $this->reconcileAccounts($newRunId, $user, $sourceProduct, $applies, $conflicts);
        $this->reconcileTransactionDescriptions($newRunId, $user, $sourceProduct, $applies, $conflicts);
        $this->reconcileTransactionAmounts($newRunId, $user, $sourceProduct, $applies, $conflicts);

        return new MergeDecision($applies, $conflicts);
    }

    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileBudgetAssignments(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_budget_assignments')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $categoryExternalId = self::toString($row->source_category_external_id);
            $periodStart = self::toString($row->period_start);
            $sourceExternalId = $categoryExternalId.'|'.$periodStart;
            $currency = self::toString($row->currency);
            $sNewMinor = self::toInt($row->budgeted_minor);

            $map = $this->findMap($user, $sourceProduct, 'budget_assignment', $sourceExternalId);
            if ($map === null) {
                continue;
            }

            $baselineRaw = $this->baselineValue($user, self::toInt($map->id), 'budgeted_minor');
            if ($baselineRaw === null) {
                continue;
            }
            $baselineMinor = self::toInt($baselineRaw);

            $categoryId = $this->beatraxId($user, $sourceProduct, 'category', $categoryExternalId);
            if ($categoryId === null) {
                continue;
            }

            // Absence == 0, mirroring PromoteStagingToDomain's zero-minor
            // convention: a deleted assignment is "assigned nothing", not
            // "unknown". Keyed by the reader's own period, which is where
            // EnvelopeWriter stores the source file's month.
            $currentValue = $connection->table('envelope_assignments')
                ->where('user_id', $user->id)
                ->where('category_id', $categoryId)
                ->where('period_start', $this->periods->containingForUser($user, CarbonImmutable::parse($periodStart))->start->toDateString())
                ->value('assigned_minor');
            $currentMinor = self::toInt($currentValue);

            if (self::moneyEquals($sNewMinor, $currency, $baselineMinor, $currency)) {
                continue;
            }

            if (self::moneyEquals($currentMinor, $currency, $baselineMinor, $currency)) {
                $applies[] = [
                    'entityType' => MigrationEntityType::BudgetAssignment->value,
                    'sourceExternalId' => $sourceExternalId,
                    'fields' => ['budgeted_minor' => $sNewMinor],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: MigrationEntityType::BudgetAssignment->value,
                sourceExternalId: $sourceExternalId,
                fieldName: 'budgeted_minor',
                localValue: $currentMinor,
                sourceValue: $sNewMinor,
                baselineValue: $baselineMinor,
                currency: $currency,
            );
        }
    }

    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileCategories(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_categories')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toString($row->source_external_id);
            $map = $this->findMap($user, $sourceProduct, 'category', $externalId);
            if ($map === null) {
                continue;
            }

            $baseline = $this->baselineValue($user, self::toInt($map->id), 'name');
            if ($baseline === null) {
                continue;
            }

            $sNew = self::toString($row->name);
            $own = $connection->table('categories')
                ->where('id', self::toInt($map->beatrax_id))
                ->where('user_id', $user->id)
                ->first(['name']);

            // A category matched into the shared tree is one nothing here may
            // rename: EntityChangeApplier scopes every write to the reader's own
            // rows, so offering the rename as a decision would resolve to
            // nothing either way, with an empty local value beside it.
            if ($own === null) {
                continue;
            }

            $current = self::toString($own->name);

            if ($sNew === $baseline) {
                continue;
            }

            if ($current === $baseline) {
                $applies[] = [
                    'entityType' => MigrationEntityType::Category->value,
                    'sourceExternalId' => $externalId,
                    'fields' => ['name' => $sNew],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: MigrationEntityType::Category->value,
                sourceExternalId: $externalId,
                fieldName: 'name',
                localValue: $current,
                sourceValue: $sNew,
                baselineValue: $baseline,
            );
        }
    }

    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileAccounts(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_accounts')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toString($row->source_external_id);
            $map = $this->findMap($user, $sourceProduct, 'account', $externalId);
            if ($map === null) {
                continue;
            }

            $baseline = $this->baselineValue($user, self::toInt($map->id), 'name');
            if ($baseline === null) {
                continue;
            }

            $accountId = self::toInt($map->beatrax_id);
            $sNew = self::toString($row->name);
            $current = self::toString(
                $connection->table('accounts')->where('id', $accountId)->where('user_id', $user->id)->value('name')
            );

            if ($sNew === $baseline) {
                continue;
            }

            if ($current === $baseline) {
                $applies[] = [
                    'entityType' => MigrationEntityType::Account->value,
                    'sourceExternalId' => $externalId,
                    'fields' => ['name' => $sNew],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: MigrationEntityType::Account->value,
                sourceExternalId: $externalId,
                fieldName: 'name',
                localValue: $current,
                sourceValue: $sNew,
                baselineValue: $baseline,
            );
        }
    }

    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileTransactionDescriptions(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->whereNull('parent_source_external_id')
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toString($row->source_external_id);
            $map = $this->findMap($user, $sourceProduct, 'transaction', $externalId);
            if ($map === null) {
                continue;
            }

            $baseline = $this->baselineValue($user, self::toInt($map->id), 'description');
            if ($baseline === null) {
                continue;
            }

            $transactionId = self::toInt($map->beatrax_id);
            $sNew = $row->description !== null ? self::toString($row->description) : '';
            $currentRaw = $connection->table('transactions')->where('id', $transactionId)->where('user_id', $user->id)->value('description');
            // The live value is ciphertext for an encrypted user; comparing it
            // raw against the plaintext baseline conflicts on every re-run.
            $current = is_string($currentRaw)
                ? $this->codec->decryptValue('transactions', 'description', $currentRaw, $user->id, ($this->session)())['value']
                : self::toString($currentRaw);

            if ($sNew === $baseline) {
                continue;
            }

            if ($current === $baseline) {
                $applies[] = [
                    'entityType' => MigrationEntityType::Transaction->value,
                    'sourceExternalId' => $externalId,
                    'fields' => ['description' => $sNew],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: MigrationEntityType::Transaction->value,
                sourceExternalId: $externalId,
                fieldName: 'description',
                localValue: $current,
                sourceValue: $sNew,
                baselineValue: $baseline,
            );
        }
    }

    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileTransactionAmounts(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->whereNull('parent_source_external_id')
            ->where('is_split_parent', false)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toString($row->source_external_id);
            $map = $this->findMap($user, $sourceProduct, 'transaction', $externalId);
            if ($map === null) {
                continue;
            }

            $baselineRaw = $this->baselineValue($user, self::toInt($map->id), 'amount_minor');
            if ($baselineRaw === null) {
                continue;
            }
            $baselineMinor = self::toInt($baselineRaw);

            $transactionId = self::toInt($map->beatrax_id);
            $sNewMinor = self::toInt($row->amount_minor);
            $sourceCurrency = self::toString($row->currency);

            /** @var stdClass|null $currentRow */
            $currentRow = $connection->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->first(['amount_minor', 'currency']);

            if ($currentRow === null) {
                continue;
            }

            $currentCurrency = self::toString($currentRow->currency);
            $currentMinor = self::toInt($currentRow->amount_minor);

            if (self::moneyEquals($sNewMinor, $sourceCurrency, $baselineMinor, $sourceCurrency)) {
                continue;
            }

            // Both legs carry the LIVE currency, not $sourceCurrency: a
            // mismatched pair makes moneyEquals() report a false conflict.
            if (self::moneyEquals($currentMinor, $currentCurrency, $baselineMinor, $currentCurrency)) {
                $applies[] = [
                    'entityType' => MigrationEntityType::Transaction->value,
                    'sourceExternalId' => $externalId,
                    'fields' => ['amount_minor' => $sNewMinor],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: MigrationEntityType::Transaction->value,
                sourceExternalId: $externalId,
                fieldName: 'amount_minor',
                localValue: $currentMinor,
                sourceValue: $sNewMinor,
                baselineValue: $baselineMinor,
                currency: $currentCurrency,
            );
        }
    }

    private function findMap(User $user, string $sourceProduct, string $entityType, string $sourceExternalId): ?stdClass
    {
        $row = $this->db->connection()->table('migration_source_map')
            ->where('user_id', $user->id)
            ->where('source_product', $sourceProduct)
            ->where('source_entity_type', $entityType)
            ->where('source_external_id', $sourceExternalId)
            ->first(['id', 'beatrax_id']);

        return $row instanceof stdClass ? $row : null;
    }

    private function beatraxId(User $user, string $sourceProduct, string $entityType, string $sourceExternalId): ?int
    {
        $map = $this->findMap($user, $sourceProduct, $entityType, $sourceExternalId);

        return $map !== null ? self::toInt($map->beatrax_id) : null;
    }

    private function baselineValue(User $user, int $mapId, string $field): ?string
    {
        $value = $this->db->connection()->table('migration_import_baseline')
            ->where('migration_source_map_id', $mapId)
            ->where('field_name', $field)
            ->where('user_id', $user->id)
            ->value('baseline_value');

        return is_string($value) ? $value : null;
    }

    private static function moneyEquals(int $aMinor, string $aCurrency, int $bMinor, string $bCurrency): bool
    {
        return Money::ofMinor($aMinor, $aCurrency)->equals(Money::ofMinor($bMinor, $bCurrency));
    }
}
