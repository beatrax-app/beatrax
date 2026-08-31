<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\MergeStrategy;

// `status` has to be an explicit entry on the transactions field map: leaning on
// strategyFor()'s unknown-field-defaults-to-lww fallback would make the first
// assertion pass with no production change and hide a missing registry line. It
// stays out of _create_required, since the column has a DB default of 'cleared'.

it('registers status as an explicit lww-strategy field on transactions', function (): void {
    $registry = new MergeRulesRegistry;
    $transactionsRules = $registry->rules()['transactions'] ?? [];

    expect($transactionsRules)->toHaveKey('status');
    expect($registry->strategyFor('transactions', 'status'))->toBe(MergeStrategy::Lww);
});

it('never adds status to the transactions _create_required set', function (): void {
    $registry = new MergeRulesRegistry;

    expect($registry->requiredCreateColumns('transactions'))->not->toContain('status');
});
