<?php

declare(strict_types=1);

use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\Support\YnabSplitReconstructor;

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

it('YnabSplitReconstructor: assertLegsPresent rejects an empty leg group and accepts a zero-net one', function (): void {
    $reconstructor = new YnabSplitReconstructor;

    expect(fn () => $reconstructor->assertLegsPresent([]))->toThrow(UnrecognizedMigrationFileException::class);

    // Legs that cancel are a reclassification between two categories, not a
    // corrupt file: rejecting one used to reject the whole export with it.
    $reconstructor->assertLegsPresent([-500, 500]);
    $reconstructor->assertLegsPresent([-2000, -1000]);
    expect(true)->toBeTrue();
});

it('YnabSplitReconstructor: two splits back to back at one payee and date stay two groups', function (): void {
    $reconstructor = new YnabSplitReconstructor;

    // Account, Date and Payee are identical across all four rows; only the
    // memo's own "n of m" tells the second split from the first.
    $rows = [
        ['Account' => 'Checking', 'Date' => '02/06/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (1/2)'],
        ['Account' => 'Checking', 'Date' => '02/06/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (2/2)'],
        ['Account' => 'Checking', 'Date' => '02/06/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (1/2)'],
        ['Account' => 'Checking', 'Date' => '02/06/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (2/2)'],
    ];

    expect($reconstructor->groupSplitRows($rows))->toBe([[0, 1], [2, 3]]);
});

it('YnabSplitReconstructor: a three-leg split at the same payee and date is one group, not three', function (): void {
    $reconstructor = new YnabSplitReconstructor;

    $rows = [
        ['Account' => 'Checking', 'Date' => '02/06/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (1/3)'],
        ['Account' => 'Checking', 'Date' => '02/06/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (2/3)'],
        ['Account' => 'Checking', 'Date' => '02/06/2026', 'Payee' => 'Supermarket', 'Memo' => 'Split (3/3)'],
    ];

    expect($reconstructor->groupSplitRows($rows))->toBe([[0, 1, 2]]);
});

it('YnabSplitReconstructor: splitPosition reads the leg number and the group size', function (): void {
    $reconstructor = new YnabSplitReconstructor;

    expect($reconstructor->splitPosition('Split (2/3)'))->toBe([2, 3])
        ->and($reconstructor->splitPosition('Split 1/2'))->toBe([1, 2])
        ->and($reconstructor->splitPosition('Groceries run'))->toBeNull();
});
