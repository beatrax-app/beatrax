<?php

declare(strict_types=1);

use Modules\Migration\Internal\Parsers\Support\YnabTransferMatcher;

it('YnabTransferMatcher: detects the "Transfer : <Account>" payee convention and extracts the counterpart name', function (): void {
    $matcher = new YnabTransferMatcher;

    expect($matcher->isTransferPayee('Transfer : Savings'))->toBeTrue();
    expect($matcher->isTransferPayee('Albert Heijn'))->toBeFalse();
    expect($matcher->counterpartAccountName('Transfer : Savings'))->toBe('Savings');
    expect($matcher->counterpartAccountName('Albert Heijn'))->toBeNull();
});

it('YnabTransferMatcher: pairs two legs by date + amount magnitude + mutual counterpart account', function (): void {
    $matcher = new YnabTransferMatcher;

    $legs = [
        ['rowIndex' => 5, 'account' => 'Checking', 'date' => '01/18/2026', 'amountMinor' => -10000, 'counterpartAccount' => 'Savings'],
        ['rowIndex' => 6, 'account' => 'Savings', 'date' => '01/18/2026', 'amountMinor' => 10000, 'counterpartAccount' => 'Checking'],
    ];

    $pairs = $matcher->pair($legs);

    expect($pairs)->toBe([5 => 6, 6 => 5]);
});

it('YnabTransferMatcher: an unmatched leg (no counterpart signal) is omitted from the pairing result', function (): void {
    $matcher = new YnabTransferMatcher;

    $legs = [
        ['rowIndex' => 5, 'account' => 'Checking', 'date' => '01/18/2026', 'amountMinor' => -10000, 'counterpartAccount' => 'Savings'],
        ['rowIndex' => 9, 'account' => 'Cash', 'date' => '02/01/2026', 'amountMinor' => -500, 'counterpartAccount' => 'Unknown'],
    ];

    expect($matcher->pair($legs))->toBe([]);
});

it('WR-05: two same-sign rows are NEVER paired as a transfer, even with matching date/magnitude/account names', function (): void {
    $matcher = new YnabTransferMatcher;

    // Both legs are outflows, satisfying everything the old predicate checked;
    // a real transfer pair is always one inflow and one outflow.
    $legs = [
        ['rowIndex' => 5, 'account' => 'Checking', 'date' => '01/18/2026', 'amountMinor' => -10000, 'counterpartAccount' => 'Savings'],
        ['rowIndex' => 6, 'account' => 'Savings', 'date' => '01/18/2026', 'amountMinor' => -10000, 'counterpartAccount' => 'Checking'],
    ];

    expect($matcher->pair($legs))->toBe([]);
});
