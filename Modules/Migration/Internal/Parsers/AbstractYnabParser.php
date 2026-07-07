<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;
use League\Csv\Reader;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
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
use Throwable;

/**
 * Shared parsing body for `Ynab4Parser` and `NynabParser`. RESEARCH.md's
 * Format Schemas section identifies exactly ONE confirmed divergence between
 * the two formats — the category-column shape (`YnabCsvColumnMap` handles
 * both) — every other convention (two-CSV export shape, split-memo
 * reconstruction, transfer-payee pairing, cleared codes, amount format) is
 * identical, so both concrete parsers extend this class and differ only in
 * the `format()` string they return.
 *
 * `$extractedPath` is a directory already produced upstream (per
 * `ParsesMigrationSource`'s own contract — extraction is NOT this class's
 * job, see `Support\ZipExtractor`'s docblock); for the YNAB4 loose-folder
 * shape it IS the export root, for nYNAB it is wherever the caller already
 * unzipped the two-CSV export to. Both shapes look identical once extracted:
 * one `*Register.csv` and one `*Budget.csv` file, discovered by suffix glob
 * since the budget-name-prefixed filename is not fixed.
 *
 * No goals/schedules are ever produced here (`goals`/`schedules` collections
 * are always empty) — RESEARCH.md's Summary finding 1 confirms neither
 * export format carries goal or scheduled-transaction data; those are
 * Actual-only (`ActualParser`, Plan 04).
 *
 * `budgetCurrency` defaults to `'EUR'`: neither export format carries a
 * per-row or per-file currency column (RESEARCH.md: "YNAB4/nYNAB are
 * effectively single-currency exports"), so there is no source signal to
 * read a currency FROM — 'EUR' is beatrax's own base currency and the
 * documented fallback for a source with no currency signal at all (distinct
 * from Actual, which DOES carry a budget-file-level currency preference —
 * see `ActualParser`, Plan 04, for Req 7's non-EUR proof).
 */
abstract class AbstractYnabParser implements ParsesMigrationSource
{
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
     * Locates the export's Register.csv/Budget.csv pair by filename suffix
     * (the budget-name prefix is user-chosen, not fixed). Missing or
     * duplicate matches reject the whole file per Req 1's
     * reject-not-partial contract — this is the FIRST check, before any CSV
     * parsing is attempted, so a directory with unrelated contents (the
     * corrupt fixture) fails fast.
     *
     * @return array{0: string, 1: string}
     */
    private function locateFiles(string $extractedPath): array
    {
        $globbedRegister = glob($extractedPath.'/*Register.csv');
        $registerCandidates = $globbedRegister === false ? [] : $globbedRegister;
        $globbedBudget = glob($extractedPath.'/*Budget.csv');
        $budgetCandidates = $globbedBudget === false ? [] : $globbedBudget;

        if (count($registerCandidates) !== 1 || count($budgetCandidates) !== 1) {
            throw new UnrecognizedMigrationFileException(sprintf(
                "expected exactly one '*Register.csv' and one '*Budget.csv' file in '%s', found %d and %d",
                $extractedPath,
                count($registerCandidates),
                count($budgetCandidates),
            ));
        }

        return [$registerCandidates[0], $budgetCandidates[0]];
    }

    /**
     * Reads Register.csv into an array of associative string rows (header
     * name keyed), validating the header BEFORE any record is read — a
     * missing/renamed column fails loud (Req 1 / Open Q1), never a partial
     * import.
     *
     * @return array<int, array<string, string>>
     */
    private function readRegisterRows(string $path, string $format): array
    {
        $reader = $this->openReader($path, 'Register.csv');
        $this->columnMap->assertRegisterHeader($this->readerHeader($reader, 'Register.csv'), $format);

        return $this->readerRows($reader);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readBudgetRows(string $path): array
    {
        $reader = $this->openReader($path, 'Budget.csv');
        $this->columnMap->assertBudgetHeader($this->readerHeader($reader, 'Budget.csv'));

        return $this->readerRows($reader);
    }

    /**
     * @return Reader<array<array-key, mixed>>
     */
    private function openReader(string $path, string $fileLabel): Reader
    {
        try {
            $reader = Reader::from($path, 'r');
            $reader->setHeaderOffset(0);

            return $reader;
        } catch (Throwable $e) {
            throw new UnrecognizedMigrationFileException("could not open {$fileLabel}: ".$e->getMessage());
        }
    }

    /**
     * @param  Reader<array<array-key, mixed>>  $reader
     * @return array<string>
     */
    private function readerHeader(Reader $reader, string $fileLabel): array
    {
        try {
            return $reader->getHeader();
        } catch (Throwable $e) {
            throw new UnrecognizedMigrationFileException("could not read {$fileLabel} header: ".$e->getMessage());
        }
    }

    /**
     * @param  Reader<array<array-key, mixed>>  $reader
     * @return array<int, array<string, string>>
     */
    private function readerRows(Reader $reader): array
    {
        $rows = [];
        foreach ($reader->getRecords() as $record) {
            $rows[] = $this->normaliseRow($record);
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @return array<string, string>
     */
    private function normaliseRow(array $record): array
    {
        $row = [];
        foreach ($record as $key => $value) {
            if ($value !== null && ! is_string($value)) {
                throw new UnrecognizedMigrationFileException('unexpected non-string CSV cell value');
            }
            $row[(string) $key] = $value ?? '';
        }

        return $row;
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
            return; // transfer / uncategorized row
        }

        // WR-03: materialize the source's Category Group as a real parent
        // Category (created once per distinct group, before any of its
        // children — collectCategories() below relies on this ordering, and
        // PromoteStagingToDomain::promoteCategories() processes staged rows
        // in that same order) rather than discarding the group structure and
        // leaving every migrated category flat/top-level.
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

    /**
     * CR-01 (13.5 deep review): a bare `'group/'.` prefix is NOT disjoint
     * from `naturalCategoryKey()`'s own `"{group}/{name}"` shape whenever a
     * source group is itself literally named "Group" (case-insensitively) —
     * `naturalGroupKey('Group')` and `naturalCategoryKey('Group', 'x')` both
     * start with the substring `'group/'`, so a genuinely different group
     * named e.g. "group" (the leaf half of some OTHER category's key) could
     * collide with this synthetic parent key and silently misattribute
     * parentage (see 13.5-REVIEW-DEEP.md CR-01 for the exact colliding
     * example). Every category key produced by `naturalCategoryKey()` is
     * unconditionally tagged with the fixed literal prefix `'cat:'` and every
     * group key produced here with the fixed literal prefix `'grp:'` — since
     * BOTH prefixes are prepended unconditionally to every key in their
     * respective space (never derived from `$group`/`$name` content), a
     * category key can never start with `'grp:'` and a group key can never
     * start with `'cat:'`, regardless of what any source group or category
     * happens to be named. This makes the two keyspaces provably disjoint
     * for every possible input, not just "unlikely to collide in practice".
     */
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

            // No stable per-account id exists in either CSV export — the
            // normalized account name IS the natural-key fallback (D-10).
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

            // No stable per-payee id in either CSV export — natural-key
            // fallback on the display-name string itself (D-10).
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

    /**
     * WR-07: `AmountStringParser::parse()`'s shared contract (verbatim-
     * copied from `EnvelopeWriter::parseAmount()`, per this class's own
     * `$amounts` dependency — Outflow/Inflow-oriented, see IN-02) treats ANY
     * non-positive result as "no amount" (`null`), which would silently
     * coerce a genuine negative `Budgeted` value (a manual correction,
     * however rare in practice for this column) to the SAME `0` a blank or
     * malformed cell produces — a real, silent data-loss path for the one
     * column this parser applies to signed money. The sign is detected here
     * and re-applied to the magnitude-only parse, so a blank/malformed cell
     * (still `0`, matching the prior contract) is no longer conflated with a
     * genuine negative value (now preserved).
     */
    private function parseBudgetedMinor(string $raw): int
    {
        $trimmed = trim($raw);
        $isNegative = str_starts_with($trimmed, '-');
        $magnitude = $isNegative ? ltrim(substr($trimmed, 1)) : $trimmed;

        $minor = $this->amounts->parse($magnitude);
        if ($minor === null) {
            return 0; // genuinely blank or malformed — matches the prior contract.
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

        // CR-01: 'cat:' is unconditionally prepended to every category key
        // (see naturalGroupKey()'s docblock for why this makes the two
        // keyspaces provably disjoint).
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
                    continue; // already yielded when this group's first row was reached
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

        return new MigrationTransactionDto(
            sourceExternalId: 'row-'.$index,
            accountSourceExternalId: trim($row['Account'] ?? ''),
            postedAt: $this->parseRegisterDate(trim($row['Date'] ?? '')),
            amount: Money::ofMinor($this->signedMinor($row), self::BUDGET_CURRENCY),
            payeeSourceExternalId: $isTransfer ? null : ($payee !== '' ? $payee : null),
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
            categorySourceExternalId: null, // category lives on each leg for a split parent
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
        // 'C' -> cleared, everything else -> uncleared. YNAB4/nYNAB's
        // Cleared column never yields 'reconciled' — RESEARCH.md confirms
        // reconciled status is not exportable from either source.
        return mb_strtoupper($flag) === 'C' ? 'cleared' : 'uncleared';
    }
}
