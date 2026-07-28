<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Internal\Exceptions\MigrationRunNotParsedException;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Dto\PreviewSummary;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class PreviewSummaryBuilder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
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

        // Excludes split legs (rows with a non-null parent_source_external_id)
        // so a 2-leg split counts as ONE transaction, matching the parser's
        // own MigrationTransactionDto granularity.
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

        // Distinguishes "never staged" from "staged but genuinely empty" via
        // migration_runs.status rather than an all-zero-counts heuristic —
        // only a 'discarded' run has had its staging deliberately truncated;
        // every other status means staging genuinely completed.
        if (self::toStr($run->status) === 'discarded') {
            throw new MigrationRunNotParsedException($migrationRunId);
        }

        return new PreviewSummary(
            categoriesCount: $categoriesCount,
            accountsCount: $accountsCount,
            counterpartiesCount: $counterpartiesCount,
            transactionsCount: $transactionsCount,
            budgetMonthsCount: $budgetMonthsCount,
            unmapped: $this->groupedUnmapped($connection, $migrationRunId, $user, self::toStr($run->source_product)),
        );
    }

    /**
     * @return array<string, array{items: list<array{id: int, label: string, reason: string, resolution: string}>, count: int}>
     */
    private function groupedUnmapped(Connection $connection, int $migrationRunId, User $user, string $sourceProduct): array
    {
        /** @var array<string, list<array{id: int, label: string, reason: string, resolution: string}>> $groups */
        $groups = [
            'category' => [],
            'payee' => [],
            'extra' => [],
            'conflict' => [],
        ];

        $rows = $connection->table('migration_staging_unmapped_items')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $itemType = self::toStr($row->item_type);
            if (! isset($groups[$itemType])) {
                continue;
            }

            $groups[$itemType][] = $itemType === 'conflict'
                ? $this->conflictItem($connection, $user, $sourceProduct, $row)
                : [
                    'id' => self::toInt($row->id),
                    'label' => self::toStr($row->display_label),
                    'reason' => self::toStr($row->reason),
                    'resolution' => 'keep_local',
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
        $entityType = $row->entity_type !== null ? self::toStr($row->entity_type) : '';
        $fieldName = $row->field_name !== null ? self::toStr($row->field_name) : '';
        $sourceExternalId = $row->source_external_id !== null ? self::toStr($row->source_external_id) : null;
        $currency = $row->currency !== null ? self::toStr($row->currency) : null;
        $resolution = $row->resolution !== null ? self::toStr($row->resolution) : 'keep_local';

        // A malformed row with no structured entity_type/field_name falls
        // back to the legacy pre-baked display_label/reason rather than
        // rendering a blank label.
        if ($entityType === '' || $fieldName === '') {
            return [
                'id' => self::toInt($row->id),
                'label' => self::toStr($row->display_label),
                'reason' => self::toStr($row->reason),
                'resolution' => $resolution,
            ];
        }

        $isMoney = ConflictValueCodec::isMoneyField($fieldName);
        $localRaw = $row->local_value !== null ? self::toStr($row->local_value) : null;
        $sourceRaw = $row->source_value !== null ? self::toStr($row->source_value) : null;
        $baselineRaw = $row->baseline_value !== null ? self::toStr($row->baseline_value) : null;

        $label = $this->conflictLabel($connection, $user, $sourceProduct, $entityType, $fieldName, $sourceExternalId);

        return [
            'id' => self::toInt($row->id),
            'label' => $label,
            'reason' => $this->conflictReason($resolution, $isMoney, $currency, $localRaw, $sourceRaw, $baselineRaw),
            'resolution' => $resolution,
        ];
    }

    private function conflictLabel(Connection $connection, User $user, string $sourceProduct, string $entityType, string $fieldName, ?string $sourceExternalId): string
    {
        // Defensive fallback to the field name alone — every conflict is
        // recorded against an already-mapped entity, so a null id here
        // should not normally happen.
        if ($sourceExternalId === null) {
            return ucfirst($entityType).' '.$fieldName;
        }

        return match ($entityType) {
            'budget_assignment' => $this->budgetAssignmentLabel($connection, $user, $sourceProduct, $sourceExternalId),
            'category' => $this->categoryOrAccountLabel($connection, $user, $sourceProduct, 'category', 'categories', $sourceExternalId),
            'account' => $this->categoryOrAccountLabel($connection, $user, $sourceProduct, 'account', 'accounts', $sourceExternalId),
            'transaction' => $this->transactionLabel($connection, $user, $sourceProduct, $sourceExternalId, $fieldName),
            default => ucfirst($entityType).' '.$fieldName,
        };
    }

    private function budgetAssignmentLabel(Connection $connection, User $user, string $sourceProduct, string $sourceExternalId): string
    {
        [$categoryExternalId, $periodStart] = array_pad(explode('|', $sourceExternalId, 2), 2, null);

        $categoryName = $categoryExternalId !== null
            ? $this->resolvedName($connection, $user, $sourceProduct, 'category', $categoryExternalId, 'categories')
            : null;

        $monthYear = $periodStart !== null ? CarbonImmutable::parse($periodStart)->format('F Y') : null;

        return match (true) {
            $categoryName !== null && $monthYear !== null => "{$categoryName} · {$monthYear} budget",
            $categoryName !== null => "{$categoryName} budget",
            default => 'Budget assignment',
        };
    }

    private function categoryOrAccountLabel(Connection $connection, User $user, string $sourceProduct, string $entityType, string $table, string $sourceExternalId): string
    {
        $name = $this->resolvedName($connection, $user, $sourceProduct, $entityType, $sourceExternalId, $table);
        $kind = $entityType === 'category' ? 'category' : 'account';

        return $name !== null ? "\"{$name}\" {$kind} name" : ucfirst($kind).' name';
    }

    private function transactionLabel(Connection $connection, User $user, string $sourceProduct, string $sourceExternalId, string $fieldName): string
    {
        $beatraxId = $this->resolveBeatraxId($connection, $user, $sourceProduct, 'transaction', $sourceExternalId);
        $fieldLabel = $fieldName === 'amount_minor' ? 'amount' : 'description';

        if ($beatraxId === null) {
            return 'Transaction '.$fieldLabel;
        }

        /** @var stdClass|null $txn */
        $txn = $connection->table('transactions')
            ->where('id', $beatraxId)
            ->where('user_id', $user->id)
            ->first(['counterparty_name', 'description', 'posted_at']);

        if ($txn === null) {
            return 'Transaction '.$fieldLabel;
        }

        // Decrypt-for-display: a null-guarded pass-through call, since
        // SensitiveColumnCodec is already a plaintext-user no-op, so no
        // separate is-encrypted branch is needed here.
        $counterpartyName = $txn->counterparty_name === null
            ? null
            : $this->codec->decryptValue('transactions', 'counterparty_name', self::toStr($txn->counterparty_name), $user->id, ($this->session)())['value'];
        $description = $txn->description === null
            ? null
            : $this->codec->decryptValue('transactions', 'description', self::toStr($txn->description), $user->id, ($this->session)())['value'];

        $identifier = $counterpartyName !== null ? $counterpartyName
            : ($description !== null ? $description : 'Transaction');
        $date = $txn->posted_at !== null ? CarbonImmutable::parse(self::toStr($txn->posted_at))->format('j M Y') : null;

        return $date !== null ? "{$identifier} · {$date} {$fieldLabel}" : "{$identifier} {$fieldLabel}";
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

    private function conflictReason(string $resolution, bool $isMoney, ?string $currency, ?string $localRaw, ?string $sourceRaw, ?string $baselineRaw): string
    {
        // Reflects whichever choice is CURRENTLY selected, so picking "Take
        // source" immediately updates what this line says will happen at
        // Confirm — never a fixed "local value kept" string.
        $intro = $resolution === 'take_source'
            ? "The new export's value will be applied when you confirm — your local value will be replaced."
            : 'Your local value will be kept — the new export\'s value will not be applied.';

        return sprintf(
            "%s\nLocal: %s · Source: %s · Last imported: %s",
            $intro,
            $this->formatConflictValue($localRaw, $isMoney, $currency),
            $this->formatConflictValue($sourceRaw, $isMoney, $currency),
            $this->formatConflictValue($baselineRaw, $isMoney, $currency),
        );
    }

    private function formatConflictValue(?string $raw, bool $isMoney, ?string $currency): string
    {
        if ($raw === null) {
            return '(none)';
        }

        if ($isMoney) {
            return Money::ofMinor((int) $raw, $currency ?? 'EUR')->format('nl_NL');
        }

        return '"'.$raw.'"';
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
