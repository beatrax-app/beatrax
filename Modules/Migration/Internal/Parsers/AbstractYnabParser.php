<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Internal\Parsers\Concerns\ReadsYnabCsvFiles;
use Modules\Migration\Internal\Parsers\Support\AmountStringParser;
use Modules\Migration\Internal\Parsers\Support\YnabCsvColumnMap;
use Modules\Migration\Internal\Parsers\Support\YnabSplitReconstructor;
use Modules\Migration\Internal\Parsers\Support\YnabTransferMatcher;
use Modules\Migration\Public\Contracts\ParsesMigrationSource;
use Modules\Migration\Public\Dto\MigrationAccountDto;
use Modules\Migration\Public\Dto\MigrationBatch;
use Modules\Migration\Public\Dto\MigrationBudgetAssignmentDto;
use Modules\Migration\Public\Dto\MigrationCategoryDto;
use Modules\Migration\Public\Dto\MigrationGoalDto;
use Modules\Migration\Public\Dto\MigrationPayeeDto;
use Modules\Migration\Public\Dto\MigrationScheduleDto;
use Modules\Migration\Public\Dto\MigrationTransactionDto;
use Modules\Migration\Public\Dto\UnmappedItemDto;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

abstract class AbstractYnabParser implements ParsesMigrationSource
{
    use ReadsYnabCsvFiles;

    private const BUDGET_CURRENCY = 'EUR';

    /** @var list<string> */
    private const BUDGET_MONTH_FORMATS = ['!Y-m', '!M Y', '!m/Y'];

    public function __construct(
        private readonly AmountStringParser $amounts,
        private readonly YnabCsvColumnMap $columnMap,
        private readonly YnabSplitReconstructor $splitReconstructor,
        private readonly YnabTransferMatcher $transferMatcher,
    ) {}

    public function parse(string $extractedPath, User $user, int $migrationRunId): MigrationBatch
    {
        $format = $this->format();
        [$registerPath, $budgetPath] = $this->locateFiles($extractedPath);

        $rows = $this->readRegisterRows($registerPath, $format);
        $budgetRows = $this->readBudgetRows($budgetPath);

        $categories = $this->collectCategories($rows, $budgetRows, $format);
        $accounts = $this->collectAccounts($rows);
        $payees = $this->collectPayees($rows);
        $budgetAssignments = $this->buildBudgetAssignments($budgetRows);

        $splitGroups = $this->splitReconstructor->groupSplitRows($rows);
        $rowIndexToSplitGroup = [];
        foreach ($splitGroups as $groupId => $indexes) {
            foreach ($indexes as $rowIndex) {
                $rowIndexToSplitGroup[$rowIndex] = $groupId;
            }
        }

        $transferPairs = $this->transferMatcher->pair($this->collectTransferLegs($rows));

        /** @var Collection<int, MigrationGoalDto> $goals */
        $goals = new Collection;
        /** @var Collection<int, MigrationScheduleDto> $schedules */
        $schedules = new Collection;
        /** @var Collection<int, UnmappedItemDto> $unmapped */
        $unmapped = new Collection;

        return new MigrationBatch(
            sourceProduct: $format,
            budgetCurrency: self::BUDGET_CURRENCY,
            categories: $categories,
            accounts: $accounts,
            payees: $payees,
            budgetAssignments: $budgetAssignments,
            goals: $goals,
            schedules: $schedules,
            unmapped: $unmapped,
            transactions: $this->buildTransactionsGenerator($rows, $format, $splitGroups, $rowIndexToSplitGroup, $transferPairs),
        );
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, array<string, string>>  $budgetRows
     * @return Collection<int, MigrationCategoryDto>
     */
    private function collectCategories(array $rows, array $budgetRows, string $format): Collection
    {
        $seen = [];
        /** @var Collection<int, MigrationCategoryDto> $categories */
        $categories = new Collection;

        foreach ($rows as $row) {
            [$group, $name] = $this->columnMap->categoryGroupAndName($row, $format);
            $this->addCategory($categories, $seen, $group, $name);
        }

        foreach ($budgetRows as $row) {
            $group = trim($row['Category Group'] ?? '');
            $this->addCategory($categories, $seen, $group !== '' ? $group : null, $row['Category'] ?? '');
        }

        return $categories;
    }

    /**
     * @param  Collection<int, MigrationCategoryDto>  $categories
     * @param  array<string, bool>  $seen
     */
    private function addCategory(Collection $categories, array &$seen, ?string $group, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $parentSourceExternalId = null;
        if ($group !== null && trim($group) !== '') {
            $parentSourceExternalId = $this->naturalGroupKey($group);
            $this->addCategoryGroup($categories, $seen, $group, $parentSourceExternalId);
        }

        $key = $this->naturalCategoryKey($group, $name);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        $kind = $group !== null && mb_strtolower($group) === 'income' ? 'income' : 'expense';
        $categories->push(new MigrationCategoryDto(
            sourceExternalId: $key,
            name: $name,
            sourceGroupName: $group,
            parentSourceExternalId: $parentSourceExternalId,
            kind: $kind,
        ));
    }

    /**
     * @param  Collection<int, MigrationCategoryDto>  $categories
     * @param  array<string, bool>  $seen
     */
    private function addCategoryGroup(Collection $categories, array &$seen, string $group, string $groupKey): void
    {
        if (isset($seen[$groupKey])) {
            return;
        }
        $seen[$groupKey] = true;

        $groupName = trim($group);
        $kind = mb_strtolower($groupName) === 'income' ? 'income' : 'expense';

        $categories->push(new MigrationCategoryDto(
            sourceExternalId: $groupKey,
            name: $groupName,
            sourceGroupName: null,
            parentSourceExternalId: null,
            kind: $kind,
        ));
    }

    private function naturalGroupKey(string $group): string
    {
        return 'grp:'.mb_strtolower(trim($group));
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return Collection<int, MigrationAccountDto>
     */
    private function collectAccounts(array $rows): Collection
    {
        $seen = [];
        /** @var Collection<int, MigrationAccountDto> $accounts */
        $accounts = new Collection;

        foreach ($rows as $row) {
            $name = trim($row['Account'] ?? '');
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $accounts->push(new MigrationAccountDto(
                sourceExternalId: $name,
                name: $name,
                currency: self::BUDGET_CURRENCY,
            ));
        }

        return $accounts;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return Collection<int, MigrationPayeeDto>
     */
    private function collectPayees(array $rows): Collection
    {
        $seen = [];
        /** @var Collection<int, MigrationPayeeDto> $payees */
        $payees = new Collection;

        foreach ($rows as $row) {
            $name = trim($row['Payee'] ?? '');
            if ($name === '' || $this->transferMatcher->isTransferPayee($name) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $payees->push(new MigrationPayeeDto(
                sourceExternalId: $name,
                name: $name,
            ));
        }

        return $payees;
    }

    /**
     * @param  array<int, array<string, string>>  $budgetRows
     * @return Collection<int, MigrationBudgetAssignmentDto>
     */
    private function buildBudgetAssignments(array $budgetRows): Collection
    {
        /** @var Collection<int, MigrationBudgetAssignmentDto> $assignments */
        $assignments = new Collection;

        foreach ($budgetRows as $row) {
            $name = trim($row['Category'] ?? '');
            if ($name === '') {
                continue;
            }
            $group = trim($row['Category Group'] ?? '');
            $minor = $this->parseBudgetedMinor($row['Budgeted'] ?? '');

            $assignments->push(new MigrationBudgetAssignmentDto(
                sourceCategoryExternalId: $this->naturalCategoryKey($group !== '' ? $group : null, $name),
                periodStart: $this->parseBudgetMonth(trim($row['Month'] ?? '')),
                budgeted: Money::ofMinor($minor, self::BUDGET_CURRENCY),
            ));
        }

        return $assignments;
    }

    private function parseBudgetedMinor(string $raw): int
    {
        // AmountStringParser::parse() nulls a non-positive result, so a negative
        // Budgeted cell would read as the 0 a blank cell gives.
        $trimmed = trim($raw);
        $isNegative = str_starts_with($trimmed, '-');
        $magnitude = $isNegative ? ltrim(substr($trimmed, 1)) : $trimmed;

        $minor = $this->amounts->parse($magnitude);
        if ($minor === null) {
            return 0;
        }

        return $isNegative ? -$minor : $minor;
    }

    private function parseBudgetMonth(string $value): CarbonImmutable
    {
        foreach (self::BUDGET_MONTH_FORMATS as $format) {
            $parsed = CarbonImmutable::createFromFormat($format, $value);
            if ($parsed instanceof CarbonImmutable) {
                return $parsed->startOfMonth();
            }
        }

        throw new UnrecognizedMigrationFileException(
            "could not parse Budget.csv Month value '{$value}' against the supported format allow-list",
        );
    }

    private function naturalCategoryKey(?string $group, string $name): string
    {
        $normalisedGroup = $group !== null ? mb_strtolower(trim($group)) : '';
        $normalisedName = mb_strtolower(trim($name));

        return 'cat:'.$normalisedGroup.'/'.$normalisedName;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return list<array{rowIndex: int, account: string, date: string, amountMinor: int, counterpartAccount: string}>
     */
    private function collectTransferLegs(array $rows): array
    {
        $legs = [];

        foreach ($rows as $index => $row) {
            $payee = trim($row['Payee'] ?? '');
            if (! $this->transferMatcher->isTransferPayee($payee)) {
                continue;
            }

            $legs[] = [
                'rowIndex' => $index,
                'account' => trim($row['Account'] ?? ''),
                'date' => trim($row['Date'] ?? ''),
                'amountMinor' => $this->signedMinor($row),
                'counterpartAccount' => (string) $this->transferMatcher->counterpartAccountName($payee),
            ];
        }

        return $legs;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function signedMinor(array $row): int
    {
        $outflow = $this->amounts->parse($row['Outflow'] ?? '') ?? 0;
        $inflow = $this->amounts->parse($row['Inflow'] ?? '') ?? 0;

        return $inflow > 0 ? $inflow : -$outflow;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  list<list<int>>  $splitGroups
     * @param  array<int, int>  $rowIndexToSplitGroup
     * @param  array<int, int>  $transferPairs
     */
    private function buildTransactionsGenerator(
        array $rows,
        string $format,
        array $splitGroups,
        array $rowIndexToSplitGroup,
        array $transferPairs,
    ): Generator {
        $emittedGroups = [];

        foreach ($rows as $index => $row) {
            if (isset($rowIndexToSplitGroup[$index])) {
                $groupId = $rowIndexToSplitGroup[$index];
                if (isset($emittedGroups[$groupId])) {
                    continue;
                }
                $emittedGroups[$groupId] = true;

                yield $this->buildSplitParentDto($rows, $splitGroups[$groupId], $format);

                continue;
            }

            $counterpartIndex = $transferPairs[$index] ?? null;
            yield $this->buildPlainTransactionDto($row, $index, $format, $counterpartIndex);
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function buildPlainTransactionDto(array $row, int $index, string $format, ?int $counterpartIndex): MigrationTransactionDto
    {
        $payee = trim($row['Payee'] ?? '');
        $isTransfer = $this->transferMatcher->isTransferPayee($payee);

        [$group, $name] = $this->columnMap->categoryGroupAndName($row, $format);
        $categorySourceExternalId = ($name !== '' && ! $isTransfer) ? $this->naturalCategoryKey($group, $name) : null;
        $payeeSourceExternalId = ($isTransfer || $payee === '') ? null : $payee;

        return new MigrationTransactionDto(
            sourceExternalId: 'row-'.$index,
            accountSourceExternalId: trim($row['Account'] ?? ''),
            postedAt: $this->parseRegisterDate(trim($row['Date'] ?? '')),
            amount: Money::ofMinor($this->signedMinor($row), self::BUDGET_CURRENCY),
            payeeSourceExternalId: $payeeSourceExternalId,
            categorySourceExternalId: $categorySourceExternalId,
            description: null,
            clearedStatus: $this->mapClearedStatus(trim($row['Cleared'] ?? '')),
            sourceRowIndex: $index,
            rawPayload: $row,
            transferCounterpartSourceExternalId: $counterpartIndex !== null ? 'row-'.$counterpartIndex : null,
            splits: [],
        );
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  list<int>  $groupRowIndexes
     */
    private function buildSplitParentDto(array $rows, array $groupRowIndexes, string $format): MigrationTransactionDto
    {
        $legs = [];
        $legAmounts = [];
        $sumMinor = 0;

        foreach ($groupRowIndexes as $rowIndex) {
            $row = $rows[$rowIndex];
            $signed = $this->signedMinor($row);
            $legAmounts[] = $signed;
            $sumMinor += $signed;

            [$group, $name] = $this->columnMap->categoryGroupAndName($row, $format);

            $legs[] = [
                'category_source_external_id' => $name !== '' ? $this->naturalCategoryKey($group, $name) : null,
                'amount' => Money::ofMinor($signed, self::BUDGET_CURRENCY),
                'note' => null,
            ];
        }

        $this->splitReconstructor->assertSumSane($legAmounts);

        $firstIndex = $groupRowIndexes[0];
        $firstRow = $rows[$firstIndex];

        return new MigrationTransactionDto(
            sourceExternalId: 'split-'.$firstIndex,
            accountSourceExternalId: trim($firstRow['Account'] ?? ''),
            postedAt: $this->parseRegisterDate(trim($firstRow['Date'] ?? '')),
            amount: Money::ofMinor($sumMinor, self::BUDGET_CURRENCY),
            payeeSourceExternalId: trim($firstRow['Payee'] ?? '') !== '' ? trim($firstRow['Payee']) : null,
            categorySourceExternalId: null,
            description: null,
            clearedStatus: $this->mapClearedStatus(trim($firstRow['Cleared'] ?? '')),
            sourceRowIndex: $firstIndex,
            rawPayload: $firstRow,
            transferCounterpartSourceExternalId: null,
            splits: $legs,
        );
    }

    private function parseRegisterDate(string $value): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!m/d/Y', $value);
        if (! $parsed instanceof CarbonImmutable) {
            throw new UnrecognizedMigrationFileException("could not parse Register.csv Date value '{$value}' (expected m/d/Y)");
        }

        return $parsed;
    }

    private function mapClearedStatus(string $flag): string
    {
        // Neither export can express beatrax's third status, 'reconciled'.
        return mb_strtoupper($flag) === 'C' ? 'cleared' : 'uncleared';
    }
}
