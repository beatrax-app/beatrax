<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Enums\CategoryKind;
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
use Modules\Migration\Internal\Enums\YnabClearedFlag;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\Concerns\ReadsYnabCsvFiles;
use Modules\Migration\Internal\Parsers\Support\AmountStringParser;
use Modules\Migration\Internal\Parsers\Support\YnabCsvColumnMap;
use Modules\Migration\Internal\Parsers\Support\YnabSplitReconstructor;
use Modules\Migration\Internal\Parsers\Support\YnabTransferMatcher;

abstract class AbstractYnabParser implements ParsesMigrationSource
{
    use ReadsYnabCsvFiles;

    /** @var list<string> */
    private const array BUDGET_MONTH_FORMATS = ['!Y-m', '!M Y', '!m/Y'];

    // Neither CSV export carries a per-transaction id, and the file position is
    // not one: inserting a single older row renumbers every row below it, so a
    // re-import mapped each of them onto the PREVIOUS row's ledger entry and
    // rewrote its amount. The identity is the row's own content instead.
    private const string IDENTITY_PREFIX = 'ynab-';

    private const string IDENTITY_SEPARATOR = "\x1f";

    public function __construct(
        private readonly AmountStringParser $amounts,
        private readonly YnabCsvColumnMap $columnMap,
        private readonly YnabSplitReconstructor $splitReconstructor,
        private readonly YnabTransferMatcher $transferMatcher,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    public function parse(string $extractedPath, User $user, int $migrationRunId): MigrationBatch
    {
        $format = $this->format();
        [$registerPath, $budgetPath] = $this->locateFiles($extractedPath);

        $rows = $this->readRegisterRows($registerPath, $format);
        $budgetRows = $this->readBudgetRows($budgetPath);

        // Neither export states a currency anywhere, and stamping a fixed EUR
        // booked a dollar ledger in euros and then refused every budget month
        // for disagreeing with the reader's own envelopes.
        $currency = $this->baseCurrency->forUser($user);

        $categories = $this->collectCategories($rows, $budgetRows, $format);
        $accounts = $this->collectAccounts($rows, $currency);
        $payees = $this->collectPayees($rows);
        $budgetAssignments = $this->buildBudgetAssignments($budgetRows, $currency);

        $splitGroups = $this->splitReconstructor->groupSplitRows($rows);
        $rowIndexToSplitGroup = [];
        foreach ($splitGroups as $groupId => $indexes) {
            foreach ($indexes as $rowIndex) {
                $rowIndexToSplitGroup[$rowIndex] = $groupId;
            }
        }

        $transferPairs = $this->transferMatcher->pair($this->collectTransferLegs($rows, $currency));
        $rowIdentities = $this->buildRowIdentities($rows, $format);

        /** @var Collection<int, MigrationGoalDto> $goals */
        $goals = new Collection;
        /** @var Collection<int, MigrationScheduleDto> $schedules */
        $schedules = new Collection;
        /** @var Collection<int, UnmappedItemDto> $unmapped */
        $unmapped = new Collection;

        return new MigrationBatch(
            sourceProduct: $format,
            budgetCurrency: $currency,
            categories: $categories,
            accounts: $accounts,
            payees: $payees,
            budgetAssignments: $budgetAssignments,
            goals: $goals,
            schedules: $schedules,
            unmapped: $unmapped,
            transactions: $this->buildTransactionsGenerator($rows, $format, $splitGroups, $rowIndexToSplitGroup, $transferPairs, $rowIdentities, $currency),
        );
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, string>
     */
    private function buildRowIdentities(array $rows, string $format): array
    {
        /** @var array<string, int> $ordinals */
        $ordinals = [];
        /** @var array<int, string> $identities */
        $identities = [];

        foreach ($rows as $index => $row) {
            [$group, $name] = $this->columnMap->categoryGroupAndName($row, $format);

            $tuple = implode(self::IDENTITY_SEPARATOR, [
                trim($row['Account'] ?? ''),
                trim($row['Date'] ?? ''),
                trim($row['Payee'] ?? ''),
                $group ?? '',
                $name,
            ]);

            $ordinals[$tuple] = ($ordinals[$tuple] ?? -1) + 1;
            $identities[$index] = self::IDENTITY_PREFIX.substr(hash('sha256', $tuple), 0, 32).'-'.$ordinals[$tuple];
        }

        return $identities;
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

        $kind = $group !== null && mb_strtolower($group) === 'income'
            ? CategoryKind::Income->value
            : CategoryKind::Expense->value;
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
        $kind = mb_strtolower($groupName) === 'income'
            ? CategoryKind::Income->value
            : CategoryKind::Expense->value;

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
    private function collectAccounts(array $rows, string $currency): Collection
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
                currency: $currency,
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
    private function buildBudgetAssignments(array $budgetRows, string $currency): Collection
    {
        /** @var Collection<int, MigrationBudgetAssignmentDto> $assignments */
        $assignments = new Collection;

        foreach ($budgetRows as $row) {
            $name = trim($row['Category'] ?? '');
            if ($name === '') {
                continue;
            }
            $group = trim($row['Category Group'] ?? '');
            $minor = $this->parseBudgetedMinor($row['Budgeted'] ?? '', $currency);

            // No cell means the export says nothing about this category-month,
            // which is not the instruction an explicit zero is: staging it as a
            // zero deletes a budget the reader typed in themselves.
            if ($minor === null) {
                continue;
            }

            $assignments->push(new MigrationBudgetAssignmentDto(
                sourceCategoryExternalId: $this->naturalCategoryKey($group !== '' ? $group : null, $name),
                periodStart: $this->parseBudgetMonth(trim($row['Month'] ?? '')),
                budgeted: Money::ofMinor($minor, $currency),
            ));
        }

        return $assignments;
    }

    // Null is "the export has no figure here" — a blank cell, or one this
    // parser cannot read. Zero is a figure, and stays one.
    private function parseBudgetedMinor(string $raw, string $currency): ?int
    {
        return $this->amounts->parseSigned($raw, $currency);
    }

    private function parseBudgetMonth(string $value): CarbonImmutable
    {
        foreach (self::BUDGET_MONTH_FORMATS as $format) {
            $parsed = SafeDate::fromFormatOrNull($format, $value);
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
    private function collectTransferLegs(array $rows, string $currency): array
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
                'amountMinor' => $this->signedMinor($row, $currency),
                'counterpartAccount' => (string) $this->transferMatcher->counterpartAccountName($payee),
            ];
        }

        return $legs;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function signedMinor(array $row, string $currency): int
    {
        $outflow = $this->amounts->requireMinor($row['Outflow'] ?? '', 'Register.csv Outflow', $currency);
        $inflow = $this->amounts->requireMinor($row['Inflow'] ?? '', 'Register.csv Inflow', $currency);

        return $inflow > 0 ? $inflow : -$outflow;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  list<list<int>>  $splitGroups
     * @param  array<int, int>  $rowIndexToSplitGroup
     * @param  array<int, int>  $transferPairs
     * @param  array<int, string>  $rowIdentities
     */
    private function buildTransactionsGenerator(
        array $rows,
        string $format,
        array $splitGroups,
        array $rowIndexToSplitGroup,
        array $transferPairs,
        array $rowIdentities,
        string $currency,
    ): Generator {
        $emittedGroups = [];

        foreach ($rows as $index => $row) {
            if (isset($rowIndexToSplitGroup[$index])) {
                $groupId = $rowIndexToSplitGroup[$index];
                if (isset($emittedGroups[$groupId])) {
                    continue;
                }
                $emittedGroups[$groupId] = true;

                yield $this->buildSplitParentDto($rows, $splitGroups[$groupId], $format, $rowIdentities, $currency);

                continue;
            }

            $counterpartIndex = $transferPairs[$index] ?? null;
            yield $this->buildPlainTransactionDto($row, $index, $format, $counterpartIndex, $rowIdentities, $currency);
        }
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<int, string>  $rowIdentities
     */
    private function buildPlainTransactionDto(array $row, int $index, string $format, ?int $counterpartIndex, array $rowIdentities, string $currency): MigrationTransactionDto
    {
        $payee = trim($row['Payee'] ?? '');
        $isTransfer = $this->transferMatcher->isTransferPayee($payee);

        [$group, $name] = $this->columnMap->categoryGroupAndName($row, $format);
        $categorySourceExternalId = ($name !== '' && ! $isTransfer) ? $this->naturalCategoryKey($group, $name) : null;
        $payeeSourceExternalId = ($isTransfer || $payee === '') ? null : $payee;

        return new MigrationTransactionDto(
            sourceExternalId: $rowIdentities[$index],
            accountSourceExternalId: trim($row['Account'] ?? ''),
            postedAt: $this->parseRegisterDate(trim($row['Date'] ?? '')),
            amount: Money::ofMinor($this->signedMinor($row, $currency), $currency),
            payeeSourceExternalId: $payeeSourceExternalId,
            categorySourceExternalId: $categorySourceExternalId,
            description: null,
            clearedStatus: $this->mapClearedStatus(trim($row['Cleared'] ?? '')),
            sourceRowIndex: $index,
            rawPayload: $row,
            transferCounterpartSourceExternalId: $counterpartIndex !== null ? $rowIdentities[$counterpartIndex] : null,
            splits: [],
        );
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  list<int>  $groupRowIndexes
     * @param  array<int, string>  $rowIdentities
     */
    private function buildSplitParentDto(array $rows, array $groupRowIndexes, string $format, array $rowIdentities, string $currency): MigrationTransactionDto
    {
        $legs = [];
        $legAmounts = [];
        $sumMinor = 0;

        foreach ($groupRowIndexes as $rowIndex) {
            $row = $rows[$rowIndex];
            $signed = $this->signedMinor($row, $currency);
            $legAmounts[] = $signed;
            $sumMinor += $signed;

            [$group, $name] = $this->columnMap->categoryGroupAndName($row, $format);

            $legs[] = [
                'category_source_external_id' => $name !== '' ? $this->naturalCategoryKey($group, $name) : null,
                'amount' => Money::ofMinor($signed, $currency),
                'note' => null,
            ];
        }

        $this->splitReconstructor->assertLegsPresent($legAmounts);

        $firstIndex = $groupRowIndexes[0];
        $firstRow = $rows[$firstIndex];

        return new MigrationTransactionDto(
            sourceExternalId: $rowIdentities[$firstIndex],
            accountSourceExternalId: trim($firstRow['Account'] ?? ''),
            postedAt: $this->parseRegisterDate(trim($firstRow['Date'] ?? '')),
            amount: Money::ofMinor($sumMinor, $currency),
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
        $parsed = SafeDate::fromFormatOrNull('!m/d/Y', $value);
        if (! $parsed instanceof CarbonImmutable) {
            throw new UnrecognizedMigrationFileException("could not parse Register.csv Date value '{$value}' (expected m/d/Y)");
        }

        return $parsed;
    }

    private function mapClearedStatus(string $flag): string
    {
        return YnabClearedFlag::statusFor($flag)->value;
    }
}
