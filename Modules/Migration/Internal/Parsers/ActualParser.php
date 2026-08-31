<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Internal\Contracts\ParsesMigrationSource;
use Modules\Migration\Internal\Dto\MigrationAccountDto;
use Modules\Migration\Internal\Dto\MigrationBatch;
use Modules\Migration\Internal\Dto\MigrationBudgetAssignmentDto;
use Modules\Migration\Internal\Dto\MigrationCategoryDto;
use Modules\Migration\Internal\Dto\MigrationGoalDto;
use Modules\Migration\Internal\Dto\MigrationPayeeDto;
use Modules\Migration\Internal\Dto\MigrationScheduleDto;
use Modules\Migration\Internal\Dto\MigrationTransactionDto;
use Modules\Migration\Internal\Dto\UnmappedItemDto;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\Support\ActualGoalDefInterpreter;
use Modules\Migration\Internal\Services\ActualSqliteReader;
use PDOException;

final readonly class ActualParser implements ParsesMigrationSource
{
    public function __construct(
        private ActualGoalDefInterpreter $goalDefInterpreter,
        private BaseCurrency $baseCurrency,
    ) {}

    public function format(): string
    {
        return MigrationSourceProduct::Actual->value;
    }

    public function parse(string $extractedPath, User $user, int $migrationRunId): MigrationBatch
    {
        $dbPath = rtrim($extractedPath, '/').'/db.sqlite';
        $metadataPath = rtrim($extractedPath, '/').'/metadata.json';

        if (! is_file($dbPath) || ! is_file($metadataPath)) {
            throw new UnrecognizedMigrationFileException(
                "expected 'db.sqlite' and 'metadata.json' in '{$extractedPath}' — not a recognized Actual export",
            );
        }

        try {
            $reader = new ActualSqliteReader($dbPath);

            return $this->buildBatch($reader, $user);
        } catch (PDOException $e) {
            throw new UnrecognizedMigrationFileException('db.sqlite could not be read as the expected Actual schema: '.$e->getMessage());
        }
    }

    private function buildBatch(ActualSqliteReader $reader, User $user): MigrationBatch
    {
        /** @var Collection<int, UnmappedItemDto> $unmapped */
        $unmapped = new Collection;

        $currency = $this->resolveCurrency($reader, $user, $unmapped);
        $this->reportAssumedBudgetType($reader, $unmapped);

        /** @var array<string, string> $categoryNames */
        $categoryNames = [];
        $categories = $this->buildCategoryCollection($reader, $categoryNames);

        /** @var Collection<int, MigrationAccountDto> $accounts */
        $accounts = new Collection;
        foreach ($reader->accounts() as $row) {
            $accounts->push(new MigrationAccountDto(
                sourceExternalId: $row['id'],
                name: $row['name'],
                currency: $currency,
            ));
        }

        /** @var array<string, bool> $payeeIsTransfer */
        $payeeIsTransfer = [];
        $payees = $this->buildPayeeCollection($reader, $payeeIsTransfer);

        /** @var Collection<int, MigrationBudgetAssignmentDto> $budgetAssignments */
        $budgetAssignments = new Collection;
        foreach ($reader->budgetAssignments() as $row) {
            $budgetAssignments->push(new MigrationBudgetAssignmentDto(
                sourceCategoryExternalId: $row['category'],
                periodStart: $this->parseBudgetMonth($row['month']),
                budgeted: Money::ofMinor($row['amount'], $currency),
            ));
        }

        $goals = $this->buildGoalCollection($reader, $categoryNames, $currency, $unmapped);

        /** @var Collection<int, MigrationScheduleDto> $schedules */
        $schedules = new Collection;
        foreach ($reader->schedulesWithRules() as $row) {
            $named = $row['name'] ?? null;
            $name = $named ?? Lang::get('migration::unmapped.label.schedule_untitled');
            $note = trim($name.' — '.$row['conditionsSummary']);
            $schedules->push(new MigrationScheduleDto(
                sourceExternalId: $row['id'],
                name: $name,
                note: $note,
            ));
            $unmapped->push(new UnmappedItemDto(
                itemType: UnmappedItemType::Extra->value,
                sourceExternalId: $row['id'],
                displayLabel: $named ?? StoredCopy::of(CopyLine::of('migration::unmapped.label.schedule_untitled')),
                reason: StoredCopy::of(CopyLine::of('migration::unmapped.reason.schedule_unsupported')),
            ));
        }

        foreach ($reader->customReports() as $row) {
            $unmapped->push(new UnmappedItemDto(
                itemType: UnmappedItemType::Extra->value,
                sourceExternalId: $row['id'],
                displayLabel: $row['name'],
                reason: StoredCopy::of(CopyLine::of('migration::unmapped.reason.saved_report_unsupported')),
            ));
        }

        /** @var list<array{id: string, is_parent: bool, is_child: bool, parent_id: ?string, account: string, category: ?string, amount: int, payee: ?string, notes: ?string, date: int, transfer_id: ?string, cleared: bool, reconciled: bool}> $rawTransactions */
        $rawTransactions = iterator_to_array($reader->transactions());

        return new MigrationBatch(
            sourceProduct: $this->format(),
            budgetCurrency: $currency,
            categories: $categories,
            accounts: $accounts,
            payees: $payees,
            budgetAssignments: $budgetAssignments,
            goals: $goals,
            schedules: $schedules,
            unmapped: $unmapped,
            transactions: $this->buildTransactionsGenerator($rawTransactions, $currency, $payeeIsTransfer),
        );
    }

    /**
     * @param  Collection<int, UnmappedItemDto>  $unmapped
     */
    private function resolveCurrency(ActualSqliteReader $reader, User $user, Collection $unmapped): string
    {
        $currency = $reader->currency();
        if ($currency === null) {
            $currency = $this->baseCurrency->forUser($user);
            $unmapped->push(new UnmappedItemDto(
                itemType: UnmappedItemType::Extra->value,
                sourceExternalId: null,
                displayLabel: StoredCopy::of(CopyLine::of('migration::unmapped.label.budget_file_currency')),
                reason: StoredCopy::of(CopyLine::of('migration::unmapped.reason.assumed_currency', ['currency' => $currency])),
            ));
        }

        return $currency;
    }

    /**
     * @param  Collection<int, UnmappedItemDto>  $unmapped
     */
    private function reportAssumedBudgetType(ActualSqliteReader $reader, Collection $unmapped): void
    {
        if ($reader->declaredBudgetType() !== null) {
            return;
        }

        $assumed = ActualSqliteReader::DEFAULT_BUDGET_TYPE->value;

        $unmapped->push(new UnmappedItemDto(
            itemType: UnmappedItemType::Extra->value,
            sourceExternalId: null,
            displayLabel: StoredCopy::of(CopyLine::of('migration::unmapped.label.budget_file_mode')),
            reason: StoredCopy::of(CopyLine::of('migration::unmapped.reason.assumed_budget_type', ['mode' => $assumed])),
        ));
    }

    /**
     * @param  array<string, string>  $categoryNames
     * @return Collection<int, MigrationCategoryDto>
     */
    private function buildCategoryCollection(ActualSqliteReader $reader, array &$categoryNames): Collection
    {
        $categoryGroupNames = [];
        foreach ($reader->categoryGroups() as $group) {
            $categoryGroupNames[$group['id']] = $group['name'];
        }

        /** @var Collection<int, MigrationCategoryDto> $categories */
        $categories = new Collection;

        foreach ($reader->categoryGroups() as $group) {
            $categories->push(new MigrationCategoryDto(
                sourceExternalId: $group['id'],
                name: $group['name'],
                sourceGroupName: null,
                parentSourceExternalId: null,
                kind: $group['is_income'] ? CategoryKind::Income->value : CategoryKind::Expense->value,
            ));
        }

        foreach ($reader->categories() as $row) {
            $categoryNames[$row['id']] = $row['name'];
            $categories->push(new MigrationCategoryDto(
                sourceExternalId: $row['id'],
                name: $row['name'],
                sourceGroupName: $row['group'] !== null ? ($categoryGroupNames[$row['group']] ?? null) : null,
                parentSourceExternalId: $row['group'],
                kind: $row['is_income'] ? CategoryKind::Income->value : CategoryKind::Expense->value,
            ));
        }

        return $categories;
    }

    /**
     * @param  array<string, bool>  $payeeIsTransfer
     * @return Collection<int, MigrationPayeeDto>
     */
    private function buildPayeeCollection(ActualSqliteReader $reader, array &$payeeIsTransfer): Collection
    {
        /** @var Collection<int, MigrationPayeeDto> $payees */
        $payees = new Collection;
        foreach ($reader->payees() as $row) {
            $payeeIsTransfer[$row['id']] = $row['transfer_acct'] !== null;
            if ($row['transfer_acct'] !== null) {
                continue;
            }
            $payees->push(new MigrationPayeeDto(
                sourceExternalId: $row['id'],
                name: $row['name'],
            ));
        }

        return $payees;
    }

    /**
     * @param  array<string, string>  $categoryNames
     * @param  Collection<int, UnmappedItemDto>  $unmapped
     * @return Collection<int, MigrationGoalDto>
     */
    private function buildGoalCollection(ActualSqliteReader $reader, array $categoryNames, string $currency, Collection $unmapped): Collection
    {
        /** @var Collection<int, MigrationGoalDto> $goals */
        $goals = new Collection;
        foreach ($reader->goalDefs() as $row) {
            $categoryName = $categoryNames[$row['category_id']] ?? $row['category_id'];
            $goal = $this->goalDefInterpreter->interpret($row['category_id'], $categoryName, $row['goal_def'], $currency);
            if ($goal !== null) {
                $goals->push($goal);

                continue;
            }

            $unmapped->push(new UnmappedItemDto(
                itemType: UnmappedItemType::Extra->value,
                sourceExternalId: $row['category_id'],
                displayLabel: StoredCopy::of(CopyLine::of('migration::unmapped.label.category_goal', ['name' => $categoryName])),
                reason: StoredCopy::of(CopyLine::of('migration::unmapped.reason.goal_def_unsupported')),
            ));
        }

        return $goals;
    }

    private function parseBudgetMonth(int $yyyymm): CarbonImmutable
    {
        $parsed = SafeDate::fromFormatOrNull('!Ym', (string) $yyyymm);
        if (! $parsed instanceof CarbonImmutable) {
            throw new UnrecognizedMigrationFileException("could not parse zero_budgets/reflect_budgets month value '{$yyyymm}' (expected YYYYMM)");
        }

        return $parsed->startOfMonth();
    }

    private function parseActualDate(int $yyyymmdd): CarbonImmutable
    {
        $parsed = SafeDate::fromFormatOrNull('!Ymd', (string) $yyyymmdd);
        if (! $parsed instanceof CarbonImmutable) {
            throw new UnrecognizedMigrationFileException("could not parse transactions.date value '{$yyyymmdd}' (expected YYYYMMDD)");
        }

        return $parsed;
    }

    /**
     * @param  list<array{id: string, is_parent: bool, is_child: bool, parent_id: ?string, account: string, category: ?string, amount: int, payee: ?string, notes: ?string, date: int, transfer_id: ?string, cleared: bool, reconciled: bool}>  $rows
     * @param  array<string, bool>  $payeeIsTransfer
     * @return Generator<int, MigrationTransactionDto>
     */
    private function buildTransactionsGenerator(array $rows, string $currency, array $payeeIsTransfer): Generator
    {
        /** @var array<string, list<int>> $childrenByParent */
        $childrenByParent = [];
        foreach ($rows as $i => $row) {
            if ($row['is_child'] && $row['parent_id'] !== null) {
                $childrenByParent[$row['parent_id']][] = $i;
            }
        }

        foreach ($rows as $i => $row) {
            if ($row['is_child']) {
                continue;
            }

            if ($row['is_parent']) {
                yield $this->buildSplitParentDto($row, $rows, $childrenByParent[$row['id']] ?? [], $i, $currency, $payeeIsTransfer);

                continue;
            }

            yield $this->buildPlainTransactionDto($row, $i, $currency, $payeeIsTransfer);
        }
    }

    /**
     * @param  array{id: string, is_parent: bool, is_child: bool, parent_id: ?string, account: string, category: ?string, amount: int, payee: ?string, notes: ?string, date: int, transfer_id: ?string, cleared: bool, reconciled: bool}  $row
     * @param  array<string, bool>  $payeeIsTransfer
     */
    private function buildPlainTransactionDto(array $row, int $index, string $currency, array $payeeIsTransfer): MigrationTransactionDto
    {
        $payeeId = $row['payee'];
        $isTransfer = $payeeId !== null && ($payeeIsTransfer[$payeeId] ?? false);

        return new MigrationTransactionDto(
            sourceExternalId: $row['id'],
            accountSourceExternalId: $row['account'],
            postedAt: $this->parseActualDate($row['date']),
            amount: Money::ofMinor($row['amount'], $currency),
            payeeSourceExternalId: $isTransfer ? null : $payeeId,
            categorySourceExternalId: $row['category'],
            description: $row['notes'],
            clearedStatus: $this->mapClearedStatus($row['cleared'], $row['reconciled']),
            sourceRowIndex: $index,
            rawPayload: $row,
            transferCounterpartSourceExternalId: $row['transfer_id'],
            splits: [],
        );
    }

    /**
     * @param  array{id: string, is_parent: bool, is_child: bool, parent_id: ?string, account: string, category: ?string, amount: int, payee: ?string, notes: ?string, date: int, transfer_id: ?string, cleared: bool, reconciled: bool}  $parentRow
     * @param  list<array{id: string, is_parent: bool, is_child: bool, parent_id: ?string, account: string, category: ?string, amount: int, payee: ?string, notes: ?string, date: int, transfer_id: ?string, cleared: bool, reconciled: bool}>  $rows
     * @param  list<int>  $childIndexes
     * @param  array<string, bool>  $payeeIsTransfer
     */
    private function buildSplitParentDto(array $parentRow, array $rows, array $childIndexes, int $index, string $currency, array $payeeIsTransfer): MigrationTransactionDto
    {
        $legs = [];
        foreach ($childIndexes as $childIndex) {
            $child = $rows[$childIndex];
            $legs[] = [
                'category_source_external_id' => $child['category'],
                'amount' => Money::ofMinor($child['amount'], $currency),
                'note' => $child['notes'],
            ];
        }

        $payeeId = $parentRow['payee'];
        $isTransfer = $payeeId !== null && ($payeeIsTransfer[$payeeId] ?? false);

        return new MigrationTransactionDto(
            sourceExternalId: $parentRow['id'],
            accountSourceExternalId: $parentRow['account'],
            postedAt: $this->parseActualDate($parentRow['date']),
            amount: Money::ofMinor($parentRow['amount'], $currency),
            payeeSourceExternalId: $isTransfer ? null : $payeeId,
            categorySourceExternalId: null,
            description: $parentRow['notes'],
            clearedStatus: $this->mapClearedStatus($parentRow['cleared'], $parentRow['reconciled']),
            sourceRowIndex: $index,
            rawPayload: $parentRow,
            transferCounterpartSourceExternalId: $parentRow['transfer_id'],
            splits: $legs,
        );
    }

    private function mapClearedStatus(bool $cleared, bool $reconciled): string
    {
        return match (true) {
            $reconciled => ClearedStatus::Reconciled->value,
            $cleared => ClearedStatus::Cleared->value,
            default => ClearedStatus::Uncleared->value,
        };
    }
}
