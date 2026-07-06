<?php

declare(strict_types=1);

use Modules\Migration\Internal\Parsers\Support\YnabSplitReconstructor;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

it('YnabSplitReconstructor: recognizes both "Split (n/m)" and "Split n/m" memo conventions', function (): void {
    $reconstructor = new YnabSplitReconstructor;

    expect($reconstructor->isSplitMemo('Split (1/2)'))->toBeTrue();
    expect($reconstructor->isSplitMemo('Split 1/2'))->toBeTrue();
    expect($reconstructor->isSplitMemo('Groceries run'))->toBeFalse();
    expect($reconstructor->isSplitMemo(''))->toBeFalse();
});

it('YnabSplitReconstructor: groups consecutive same-(Account,Date,Payee) split-memo rows', function (): void {
    $reconstructor = new YnabSplitReconstructor;

    $rows = [
        ['Account' => 'Checking', 'Date' => '01/15/2026', 'Payee' => 'Albert Heijn', 'Memo' => ''],
        ['Account' => 'Checking', 'Date' => '01/17/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (1/2)'],
        ['Account' => 'Checking', 'Date' => '01/17/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (2/2)'],
        ['Account' => 'Checking', 'Date' => '01/18/2026', 'Payee' => 'Transfer : Savings', 'Memo' => ''],
    ];

    $groups = $reconstructor->groupSplitRows($rows);

    expect($groups)->toHaveCount(1);
    expect($groups[0])->toBe([1, 2]);
});

it('YnabSplitReconstructor: a lone split-memo row (no adjacent match) is never grouped', function (): void {
    $reconstructor = new YnabSplitReconstructor;

    $rows = [
        ['Account' => 'Checking', 'Date' => '01/17/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (1/2)'],
        ['Account' => 'Checking', 'Date' => '01/18/2026', 'Payee' => 'Someone Else', 'Memo' => ''],
    ];

    expect($reconstructor->groupSplitRows($rows))->toBe([]);
});

it('YnabSplitReconstructor: assertSumSane rejects an empty or zero-net leg group', function (): void {
    $reconstructor = new YnabSplitReconstructor;

    expect(fn () => $reconstructor->assertSumSane([]))->toThrow(UnrecognizedMigrationFileException::class);
    expect(fn () => $reconstructor->assertSumSane([-500, 500]))->toThrow(UnrecognizedMigrationFileException::class);

    // A sane group does not throw.
    $reconstructor->assertSumSane([-2000, -1000]);
    expect(true)->toBeTrue();
});
