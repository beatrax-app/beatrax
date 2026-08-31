<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Internal\Dto\PreviewSummary;
use Modules\Migration\Internal\Enums\ConflictResolution;
use Modules\Migration\Internal\Enums\MigrationEntityType;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Internal\Exceptions\MigrationRunNotParsedException;
use Modules\Migration\Internal\Support\ConflictLabel;
use Modules\Migration\Models\MigrationRun;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final readonly class PreviewSummaryBuilder
{
    use CoercesScalars;

    private const string BUDGET_KEY_SEPARATOR = '|';

    private const string BUDGET_KEY_PERIOD_FORMAT = '!Y-m-d';

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private BaseCurrency $baseCurrency,
    ) {}

    public function forRun(int $migrationRunId, User $user): PreviewSummary
    {
        $connection = $this->db->connection();

        $run = $connection->table('migration_runs')
            ->where('id', $migrationRunId)
            ->where('user_id', $user->id)
            ->first(['status', 'source_product']);

        if ($run === null) {
            throw (new ModelNotFoundException)->setModel(MigrationRun::class, [$migrationRunId]);
        }

        $categoriesCount = $connection->table('migration_staging_categories')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->count();

        $accountsCount = $connection->table('migration_staging_accounts')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->count();

        $counterpartiesCount = $connection->table('migration_staging_payees')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->count();

        $transactionsCount = $connection->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->whereNull('parent_source_external_id')
            ->count();

        $budgetMonthsCount = $connection->table('migration_staging_budget_assignments')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->distinct()
            ->count('period_start');

        // Only a discarded run has had its staging truncated, so status — not an
        // all-zero-counts heuristic — separates "never staged" from "empty".
        if (self::toString($run->status) === MigrationRunStatus::Discarded->value) {
            throw new MigrationRunNotParsedException($migrationRunId);
        }

        return new PreviewSummary(
            categoriesCount: $categoriesCount,
            accountsCount: $accountsCount,
            counterpartiesCount: $counterpartiesCount,
            transactionsCount: $transactionsCount,
            budgetMonthsCount: $budgetMonthsCount,
            unmapped: $this->groupedUnmapped($connection, $migrationRunId, $user, self::toString($run->source_product)),
        );
    }

    /**
     * @return array<string, array{items: list<array{id: int, label: string, reason: string, resolution: string}>, count: int}>
     */
    private function groupedUnmapped(Connection $connection, int $migrationRunId, User $user, string $sourceProduct): array
    {
        /** @var array<string, list<array{id: int, label: string, reason: string, resolution: string}>> $groups */
        $groups = [
            UnmappedItemType::Extra->value => [],
            UnmappedItemType::Conflict->value => [],
        ];

        $rows = $connection->table('migration_staging_unmapped_items')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $itemType = self::toString($row->item_type);
            if (! isset($groups[$itemType])) {
                continue;
            }

            $groups[$itemType][] = $itemType === UnmappedItemType::Conflict->value
                ? $this->conflictItem($connection, $user, $sourceProduct, $row)
                : [
                    'id' => self::toInt($row->id),
                    'label' => StoredCopy::read(self::toString($row->display_label)),
                    'reason' => StoredCopy::read(self::toString($row->reason)),
                    'resolution' => ConflictResolution::KeepLocal->value,
                ];
        }

        $result = [];
        foreach ($groups as $itemType => $items) {
            $result[$itemType] = ['items' => $items, 'count' => count($items)];
        }

        return $result;
    }

    /**
     * @return array{id: int, label: string, reason: string, resolution: string}
     */
    private function conflictItem(Connection $connection, User $user, string $sourceProduct, stdClass $row): array
    {
        $entityType = $row->entity_type !== null ? self::toString($row->entity_type) : '';
        $fieldName = $row->field_name !== null ? self::toString($row->field_name) : '';
        $sourceExternalId = $row->source_external_id !== null ? self::toString($row->source_external_id) : null;
        $currency = $row->currency !== null ? self::toString($row->currency) : null;
        $resolution = ConflictResolution::tryFrom(self::toString($row->resolution)) ?? ConflictResolution::KeepLocal;

        if ($entityType === '' || $fieldName === '') {
            return [
                'id' => self::toInt($row->id),
                'label' => StoredCopy::read(self::toString($row->display_label)),
                'reason' => StoredCopy::read(self::toString($row->reason)),
                'resolution' => $resolution->value,
            ];
        }

        $isMoney = ConflictValueCodec::isMoneyField($fieldName);
        $localRaw = $row->local_value !== null ? self::toString($row->local_value) : null;
        $sourceRaw = $row->source_value !== null ? self::toString($row->source_value) : null;
        $baselineRaw = $row->baseline_value !== null ? self::toString($row->baseline_value) : null;

        $label = $this->conflictLabel($connection, $user, $sourceProduct, $entityType, $fieldName, $sourceExternalId);

        return [
            'id' => self::toInt($row->id),
            'label' => $label,
            'reason' => $this->conflictReason($resolution, $isMoney, $currency, $localRaw, $sourceRaw, $baselineRaw),
            'resolution' => $resolution->value,
        ];
    }

    private function conflictLabel(Connection $connection, User $user, string $sourceProduct, string $entityType, string $fieldName, ?string $sourceExternalId): string
    {
        if ($sourceExternalId === null) {
            return Lang::get(ConflictLabel::keyFor($entityType, $fieldName));
        }

        return match ($entityType) {
            'budget_assignment' => $this->budgetAssignmentLabel($connection, $user, $sourceProduct, $sourceExternalId),
            'category' => $this->categoryOrAccountLabel($connection, $user, $sourceProduct, 'category', 'categories', $sourceExternalId),
            'account' => $this->categoryOrAccountLabel($connection, $user, $sourceProduct, 'account', 'accounts', $sourceExternalId),
            'transaction' => $this->transactionLabel($connection, $user, $sourceProduct, $sourceExternalId, $fieldName),
            default => Lang::get(ConflictLabel::keyFor($entityType, $fieldName)),
        };
    }

    private function budgetAssignmentLabel(Connection $connection, User $user, string $sourceProduct, string $sourceExternalId): string
    {
        // Split at the LAST separator, not the first: the period start can never
        // hold one, and a category the reader named "Rent | Utilities" put one in
        // the other half — which sent a category-name fragment to the date parser
        // and threw the whole preview away instead of listing their conflicts.
        $separator = strrpos($sourceExternalId, self::BUDGET_KEY_SEPARATOR);

        $categoryExternalId = $separator === false ? null : substr($sourceExternalId, 0, $separator);
        $periodStart = $separator === false ? null : substr($sourceExternalId, $separator + 1);

        $categoryName = $categoryExternalId !== null
            ? $this->resolvedName($connection, $user, $sourceProduct, 'category', $categoryExternalId, 'categories')
            : null;

        $monthYear = $periodStart !== null
            ? SafeDate::fromFormatOrNull(self::BUDGET_KEY_PERIOD_FORMAT, $periodStart)?->translatedFormat('F Y')
            : null;

        return match (true) {
            $categoryName !== null && $monthYear !== null => Lang::get('migration::unmapped.conflict.budget_for_month', ['category' => $categoryName, 'month' => $monthYear]),
            $categoryName !== null => Lang::get('migration::unmapped.conflict.budget_for_category', ['category' => $categoryName]),
            default => Lang::get('migration::unmapped.conflict.budget_assignment'),
        };
    }

    private function categoryOrAccountLabel(Connection $connection, User $user, string $sourceProduct, string $entityType, string $table, string $sourceExternalId): string
    {
        $name = $this->resolvedName($connection, $user, $sourceProduct, $entityType, $sourceExternalId, $table);
        $isCategory = $entityType === MigrationEntityType::Category->value;

        if ($name === null) {
            return Lang::get($isCategory ? 'migration::unmapped.conflict.category_name' : 'migration::unmapped.conflict.account_name');
        }

        return Lang::get(
            $isCategory ? 'migration::unmapped.conflict.category_name_of' : 'migration::unmapped.conflict.account_name_of',
            ['name' => $name],
        );
    }

    private function transactionLabel(Connection $connection, User $user, string $sourceProduct, string $sourceExternalId, string $fieldName): string
    {
        $beatraxId = $this->resolveBeatraxId($connection, $user, $sourceProduct, MigrationEntityType::Transaction->value, $sourceExternalId);
        $isAmount = $fieldName === 'amount_minor';

        /** @var stdClass|null $txn */
        $txn = $beatraxId === null ? null : $connection->table('transactions')
            ->where('id', $beatraxId)
            ->where('user_id', $user->id)
            ->first(['counterparty_name', 'description', 'posted_at']);

        // Never mapped and mapped-then-gone read the same to the reader: in
        // neither case is there a transaction here to name.
        if ($txn === null) {
            return Lang::get($isAmount ? 'migration::unmapped.conflict.transaction_amount' : 'migration::unmapped.conflict.transaction_description');
        }

        $counterpartyName = $txn->counterparty_name === null
            ? null
            : $this->codec->decryptValue('transactions', 'counterparty_name', self::toString($txn->counterparty_name), $user->id, ($this->session)())['value'];
        $description = $txn->description === null
            ? null
            : $this->codec->decryptValue('transactions', 'description', self::toString($txn->description), $user->id, ($this->session)())['value'];

        $identifier = $counterpartyName ?? $description ?? Lang::get('migration::unmapped.label.transaction_unnamed');
        $date = $txn->posted_at !== null ? CarbonImmutable::parse(self::toString($txn->posted_at))->translatedFormat('j M Y') : null;

        if ($date === null) {
            return Lang::get(
                $isAmount ? 'migration::unmapped.conflict.transaction_amount_of' : 'migration::unmapped.conflict.transaction_description_of',
                ['name' => $identifier],
            );
        }

        return Lang::get(
            $isAmount ? 'migration::unmapped.conflict.transaction_amount_of_dated' : 'migration::unmapped.conflict.transaction_description_of_dated',
            ['name' => $identifier, 'date' => $date],
        );
    }

    private function resolvedName(Connection $connection, User $user, string $sourceProduct, string $entityType, string $sourceExternalId, string $table): ?string
    {
        $beatraxId = $this->resolveBeatraxId($connection, $user, $sourceProduct, $entityType, $sourceExternalId);
        if ($beatraxId === null) {
            return null;
        }

        $name = $connection->table($table)->where('id', $beatraxId)->where('user_id', $user->id)->value('name');

        return is_string($name) ? $name : null;
    }

    private function resolveBeatraxId(Connection $connection, User $user, string $sourceProduct, string $entityType, string $sourceExternalId): ?int
    {
        $value = $connection->table('migration_source_map')
            ->where('user_id', $user->id)
            ->where('source_product', $sourceProduct)
            ->where('source_entity_type', $entityType)
            ->where('source_external_id', $sourceExternalId)
            ->value('beatrax_id');

        return is_numeric($value) ? (int) $value : null;
    }

    private function conflictReason(ConflictResolution $resolution, bool $isMoney, ?string $currency, ?string $localRaw, ?string $sourceRaw, ?string $baselineRaw): string
    {
        $intro = Lang::get($resolution === ConflictResolution::TakeSource
            ? 'migration::unmapped.reason.take_source'
            : 'migration::unmapped.reason.keep_local');

        return Lang::get('migration::unmapped.reason.compared_values', [
            'intro' => $intro,
            'local' => $this->formatConflictValue($localRaw, $isMoney, $currency),
            'source' => $this->formatConflictValue($sourceRaw, $isMoney, $currency),
            'baseline' => $this->formatConflictValue($baselineRaw, $isMoney, $currency),
        ]);
    }

    private function formatConflictValue(?string $raw, bool $isMoney, ?string $currency): string
    {
        if ($raw === null) {
            return Lang::get('migration::unmapped.value.none');
        }

        if ($isMoney) {
            return Money::ofMinor((int) $raw, $currency ?? $this->baseCurrency->code())->format();
        }

        return Lang::get('migration::unmapped.value.quoted', ['value' => $raw]);
    }
}
