<?php

declare(strict_types=1);

use Modules\Migration\Internal\Enums\ActualBudgetType;
use Modules\Migration\Internal\Services\ActualSqliteReader;
use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

beforeEach(function (): void {
    $this->zipPath = sys_get_temp_dir().'/actual-reader-test-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($this->zipPath);
    $this->extracted = MigrationFixturePaths::extractZip($this->zipPath);
    $this->reader = new ActualSqliteReader($this->extracted.'/db.sqlite');
});

afterEach(function (): void {
    @unlink($this->zipPath);
});

it('proves the connection is read-only — a write attempt throws PDOException', function (): void {
    $property = new ReflectionProperty(ActualSqliteReader::class, 'pdo');
    $pdo = $property->getValue($this->reader);

    expect(fn () => $pdo->exec("INSERT INTO accounts (id, name) VALUES ('should-fail', 'x')"))
        ->toThrow(PDOException::class);
});

it('never ATTACHes the extracted file into another connection', function (): void {
    $source = (string) file_get_contents(__DIR__.'/../../../Internal/Services/ActualSqliteReader.php');

    expect(substr_count($source, 'ATTACH'))->toBe(0);
});

it('reads categories/payees/transactions through the resolved v_ views, not the raw tables', function (): void {
    $source = (string) file_get_contents(__DIR__.'/../../../Internal/Services/ActualSqliteReader.php');

    expect($source)->toContain('v_transactions');
    expect($source)->toContain('v_categories');
    expect($source)->toContain('v_payees');
});

it('categories() reads live rows from v_categories, tombstoned/pre-merge indirection resolved', function (): void {
    $categories = $this->reader->categories();

    expect($categories)->toHaveCount(4);
    expect(array_column($categories, 'name'))->toContain('Groceries', 'Household', 'Salary', 'Emergency Fund');
});

it('transactions() excludes the tombstoned row via v_transactions', function (): void {
    $rows = iterator_to_array($this->reader->transactions());
    $ids = array_column($rows, 'id');

    expect($ids)->not->toContain('tx-6-tombstoned');
});

it('budgetType() resolves envelope mode from preferences.budgetType', function (): void {
    expect($this->reader->budgetType())->toBe(ActualBudgetType::Envelope)
        ->and($this->reader->declaredBudgetType())->toBe(ActualBudgetType::Envelope);
});

it('budgetType() defaults an export that names no mode rather than refusing the file', function (): void {
    $zipPath = sys_get_temp_dir().'/actual-reader-no-budget-type-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($zipPath, ActualFixtureBuilder::NO_BUDGET_TYPE);
    $reader = new ActualSqliteReader(MigrationFixturePaths::extractZip($zipPath).'/db.sqlite');

    expect($reader->declaredBudgetType())->toBeNull()
        ->and($reader->budgetType())->toBe(ActualSqliteReader::DEFAULT_BUDGET_TYPE)
        ->and($reader->budgetAssignments())->toHaveCount(4);

    @unlink($zipPath);
});

it('budgetAssignments() reads zero_budgets when budgetType is envelope, not "no budget"', function (): void {
    $rows = $this->reader->budgetAssignments();

    expect($rows)->toHaveCount(4);
    expect(array_sum(array_column($rows, 'amount')))->toBeGreaterThan(0);
});

it('currency() reads preferences.currencyCode', function (): void {
    expect($this->reader->currency())->toBe(ActualFixtureBuilder::BUDGET_FILE_CURRENCY);
});

it('goalDefs() returns raw goal_def JSON for every category carrying one', function (): void {
    $rows = $this->reader->goalDefs();

    expect($rows)->toHaveCount(2);
    expect(array_column($rows, 'category_id'))->toContain('cat-groceries', 'cat-emergency-fund');
});

it('schedulesWithRules() joins schedules to their rule and summarizes conditions', function (): void {
    $rows = $this->reader->schedulesWithRules();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['id'])->toBe('sched-1');
    expect($rows[0]['conditionsSummary'])->not->toBe('');
});

it('customReports() surfaces the saved-report row', function (): void {
    $rows = $this->reader->customReports();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['id'])->toBe('report-1');
});
