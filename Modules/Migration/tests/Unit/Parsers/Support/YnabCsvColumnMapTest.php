<?php

declare(strict_types=1);

use Modules\Migration\Internal\Parsers\Support\YnabCsvColumnMap;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

it('YnabCsvColumnMap: accepts a valid ynab4 Register.csv header', function (): void {
    $map = new YnabCsvColumnMap;

    $map->assertRegisterHeader(['Account', 'Date', 'Payee', 'Master Category', 'Sub Category', 'Memo', 'Outflow', 'Inflow', 'Cleared'], 'ynab4');

    expect(true)->toBeTrue(); // no exception == pass
});

it('YnabCsvColumnMap: accepts a valid nynab Register.csv header with the combined column', function (): void {
    $map = new YnabCsvColumnMap;

    $map->assertRegisterHeader(['Account', 'Date', 'Payee', 'Category Group/Category', 'Memo', 'Outflow', 'Inflow', 'Cleared'], 'nynab');

    expect(true)->toBeTrue();
});

it('YnabCsvColumnMap: throws naming both the expected and found headers on a mismatched ynab4 header', function (): void {
    $map = new YnabCsvColumnMap;
    $thrown = null;

    $header = ['Account', 'Date', 'Payee', 'Memo', 'Outflow', 'Inflow', 'Cleared'];

    try {
        $map->assertRegisterHeader($header, 'ynab4');
    } catch (UnrecognizedMigrationFileException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown?->getMessage())->toContain('Master Category');
    expect($thrown?->getMessage())->toContain(implode(', ', $header));
});

it('YnabCsvColumnMap: throws on a mismatched Budget.csv header', function (): void {
    $map = new YnabCsvColumnMap;

    expect(fn () => $map->assertBudgetHeader(['Month', 'Category']))
        ->toThrow(UnrecognizedMigrationFileException::class);
});

it('YnabCsvColumnMap: resolves ynab4 category group/name from the separate Master/Sub Category columns', function (): void {
    $map = new YnabCsvColumnMap;

    [$group, $name] = $map->categoryGroupAndName(['Master Category' => 'Frequent', 'Sub Category' => 'Groceries'], 'ynab4');

    expect($group)->toBe('Frequent');
    expect($name)->toBe('Groceries');
});

it('YnabCsvColumnMap: resolves nynab category group/name from the combined column', function (): void {
    $map = new YnabCsvColumnMap;

    [$group, $name] = $map->categoryGroupAndName(['Category Group/Category' => 'Frequent: Groceries'], 'nynab');

    expect($group)->toBe('Frequent');
    expect($name)->toBe('Groceries');
});

it('YnabCsvColumnMap: an empty category cell (transfer row) resolves to [null, ""]', function (): void {
    $map = new YnabCsvColumnMap;

    [$group, $name] = $map->categoryGroupAndName(['Master Category' => '', 'Sub Category' => ''], 'ynab4');
    expect($group)->toBeNull();
    expect($name)->toBe('');

    [$group2, $name2] = $map->categoryGroupAndName(['Category Group/Category' => ''], 'nynab');
    expect($group2)->toBeNull();
    expect($name2)->toBe('');
});
