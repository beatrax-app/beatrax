<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Internal\Parsers\Support\ActualGoalDefInterpreter;
use Modules\Migration\Internal\Services\ActualSqliteReader;
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
use PDOException;

/**
 * `ParsesMigrationSource` implementation for Actual Budget's export
 * (`db.sqlite` + `metadata.json` inside a ZIP — RESEARCH.md Format Schemas).
 * The only one of the three format parsers whose extracted directory is a
 * SQLite database rather than a CSV pair: this class drives
 * `ActualSqliteReader` — a wholly separate read-only `Pdo\Sqlite` connection
 * (Pitfall 6/T-13.5-09) reading Actual's resolved `v_transactions`/
 * `v_categories`/`v_payees` VIEWS (Pitfall 1), never the raw tables.
 *
 * Unlike YNAB4/nYNAB (no stable per-entity id in the CSV export — natural-key
 * fallback per D-10), Actual carries real UUIDs for every entity, so
 * `sourceExternalId` throughout this parser is simply the Actual row id —
 * no natural-key synthesis needed.
 *
 * Req 8 scope (Open Questions 2/3/4, resolved in 13.5-RESEARCH.md and this
 * plan's DOCUMENTED ASSUMPTIONS):
 *  - Currency (Open Q2): read from `preferences.currencyCode`; if absent,
 *    fall back to the user's `base_currency` and emit an "assumed
 *    {currency}" `UnmappedItemDto`.
 *  - Goals (Open Q3): `categories.goal_def`'s flat monthly/by-date subset
 *    maps to a `MigrationGoalDto` via `ActualGoalDefInterpreter`; anything
 *    else (multi-step templates, percentage-of-income schedules) becomes an
 *    `UnmappedItemDto('extra')` — never a lossy flat approximation.
 *  - Schedules (Open Q4): descoped to note-only — every `schedules` row
 *    becomes exactly one `MigrationScheduleDto` (note preserved) AND one
 *    `UnmappedItemDto('extra')`; `Modules\Recurring` is never touched.
 *  - Saved-report/`custom_reports` rows: no beatrax equivalent at all —
 *    always an `UnmappedItemDto('extra')`.
 */
final class ActualParser implements ParsesMigrationSource
{
    public function __construct(
        private readonly ActualGoalDefInterpreter $goalDefInterpreter,
    ) {}

    public function format(): string
    {
        return 'actual';
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

        $currency = $reader->currency();
        if ($currency === null) {
            $currency = $user->base_currency;
            $unmapped->push(new UnmappedItemDto(
                itemType: 'extra',
                sourceExternalId: null,
                displayLabel: 'Budget-file currency',
                reason: "assumed {$currency} — no 'preferences.currencyCode' row found in this export",
            ));
        }

        $categoryGroupNames = [];
        foreach ($reader->categoryGroups() as $group) {
            $categoryGroupNames[$group['id']] = $group['name'];
        }

        $categoryNames = [];
        /** @var Collection<int, MigrationCategoryDto> $categories */
        $categories = new Collection;

        // WR-03: materialize each Category Group as a real parent Category
        // BEFORE any of its member categories (PromoteStagingToDomain::
        // promoteCategories() processes staged rows in insertion order, so
        // every parent row must land before its children) — Actual's group
        // id is a stable UUID, reused verbatim as the parent's
        // sourceExternalId, so `parentSourceExternalId` below resolves it
        // directly rather than discarding the group structure flat.
        foreach ($reader->categoryGroups() as $group) {
            $categories->push(new MigrationCategoryDto(
                sourceExternalId: $group['id'],
                name: $group['name'],
                sourceGroupName: null,
                parentSourceExternalId: null,
                kind: $group['is_income'] ? 'income' : 'expense',
            ));
        }

        foreach ($reader->categories() as $row) {
            $categoryNames[$row['id']] = $row['name'];
            $categories->push(new MigrationCategoryDto(
                sourceExternalId: $row['id'],
                name: $row['name'],
                sourceGroupName: $row['group'] !== null ? ($categoryGroupNames[$row['group']] ?? null) : null,
                parentSourceExternalId: $row['group'],
                kind: $row['is_income'] ? 'income' : 'expense',
            ));
        }

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
        /** @var Collection<int, MigrationPayeeDto> $payees */
        $payees = new Collection;
        foreach ($reader->payees() as $row) {
            $payeeIsTransfer[$row['id']] = $row['transfer_acct'] !== null;
            if ($row['transfer_acct'] !== null) {
                continue; // synthetic transfer-marker payee, never a real payee (Req 6)
            }
            $payees->push(new MigrationPayeeDto(
                sourceExternalId: $row['id'],
                name: $row['name'],
            ));
        }

        /** @var Collection<int, MigrationBudgetAssignmentDto> $budgetAssignments */
        $budgetAssignments = new Collection;
        foreach ($reader->budgetAssignments() as $row) {
            $budgetAssignments->push(new MigrationBudgetAssignmentDto(
                sourceCategoryExternalId: $row['category'],
                periodStart: $this->parseBudgetMonth($row['month']),
                budgeted: Money::ofMinor($row['amount'], $currency),
            ));
        }

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
                itemType: 'extra',
                sourceExternalId: $row['category_id'],
                displayLabel: $categoryName.' goal',
                reason: 'categories.goal_def uses an unsupported (non-flat) template shape — the goal was not imported',
            ));
        }

        /** @var Collection<int, MigrationScheduleDto> $schedules */
        $schedules = new Collection;
        foreach ($reader->schedulesWithRules() as $row) {
            $name = $row['name'] ?? 'Untitled schedule';
            $note = trim($name.' — '.$row['conditionsSummary']);
            $schedules->push(new MigrationScheduleDto(
                sourceExternalId: $row['id'],
                name: $name,
                note: $note,
            ));
            $unmapped->push(new UnmappedItemDto(
                itemType: 'extra',
                sourceExternalId: $row['id'],
                displayLabel: $name,
                reason: 'Scheduled/recurring transactions have no beatrax create-from-external-source path yet — preserved as a note only, not a live Recurring series',
            ));
        }

        foreach ($reader->customReports() as $row) {
            $unmapped->push(new UnmappedItemDto(
                itemType: 'extra',
                sourceExternalId: $row['id'],
                displayLabel: $row['name'],
                reason: 'Saved reports/analysis configs have no beatrax equivalent',
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

    private function parseBudgetMonth(int $yyyymm): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Ym', (string) $yyyymm);
        if (! $parsed instanceof CarbonImmutable) {
            throw new UnrecognizedMigrationFileException("could not parse zero_budgets/reflect_budgets month value '{$yyyymm}' (expected YYYYMM)");
        }

        return $parsed->startOfMonth();
    }

    private function parseActualDate(int $yyyymmdd): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Ymd', (string) $yyyymmdd);
        if (! $parsed instanceof CarbonImmutable) {
            throw new UnrecognizedMigrationFileException("could not parse transactions.date value '{$yyyymmdd}' (expected YYYYMMDD)");
        }

        return $parsed;
    }

    /**
     * Groups `is_child` rows under their `is_parent` sibling (Actual's
     * explicit parent/child columns — substantially simpler than YNAB's
     * memo-convention split reconstruction) and yields each non-child row
     * lazily. The internal `$rows` array is a lightweight structural work
     * buffer (mirrors `AbstractYnabParser`'s identical precedent — Plan 03);
     * this method's OWN return value is the genuine
     * `Generator<MigrationTransactionDto>` D-05 requires, never
     * `iterator_to_array()`'d by this parser itself.
     *
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
                continue; // emitted inline as part of its parent's splits
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
            categorySourceExternalId: null, // category lives on each leg for a split parent
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
            $reconciled => 'reconciled',
            $cleared => 'cleared',
            default => 'uncleared',
        };
    }
}
