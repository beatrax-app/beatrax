<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;

/*
 * Wave 0 RED stub (D-10, GREEN in 13.3-02, Pitfall 5). `status` must be
 * added as an EXPLICIT entry on the `transactions` LWW field map — not
 * merely rely on `strategyFor()`'s unknown-field-defaults-to-'lww'
 * fallback, which would already make the first assertion pass without any
 * production change and mask a missing registry line. `status` must NOT
 * join `_create_required` (it has a DB default of `'cleared'`, mirroring
 * the registry's own existing comment about the deliberately-excluded
 * `payment_type` column).
 */

it('registers status as an explicit lww-strategy field on transactions', function (): void {
    $registry = new MergeRulesRegistry;
    $transactionsRules = $registry->rules()['transactions'] ?? [];

    expect($transactionsRules)->toHaveKey('status');
    expect($registry->strategyFor('transactions', 'status'))->toBe('lww');
});

it('never adds status to the transactions _create_required set', function (): void {
    $registry = new MergeRulesRegistry;

    expect($registry->requiredCreateColumns('transactions'))->not->toContain('status');
});
