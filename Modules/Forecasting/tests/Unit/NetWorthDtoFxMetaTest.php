<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Forecasting\Public\Dto\NetWorth;

it('carries nullable FX metadata with safe defaults', function (): void {
    $nw = new NetWorth(
        totalMinor: 100_00,
        currency: 'EUR',
        accounts: [],
        hasExcludedAccounts: false,
    );

    expect($nw->ratesSource)->toBeNull();
    expect($nw->ratesAsOf)->toBeNull();
    expect($nw->hasStaleRates)->toBeFalse();
    expect($nw->balancesWithoutRate)->toBe(0);
});

it('stores FX metadata when all fields are supplied', function (): void {
    $asOf = CarbonImmutable::parse('2026-06-05');

    $nw = new NetWorth(
        totalMinor: 150_00,
        currency: 'EUR',
        accounts: [],
        hasExcludedAccounts: false,
        ratesSource: 'ecb',
        ratesAsOf: $asOf,
        hasStaleRates: false,
        balancesWithoutRate: 0,
    );

    expect($nw->ratesSource)->toBe('ecb');
    expect($nw->ratesAsOf)->toBe($asOf);
    expect($nw->hasStaleRates)->toBeFalse();
    expect($nw->balancesWithoutRate)->toBe(0);
});

it('marks stale rates and missing accounts when set', function (): void {
    $nw = new NetWorth(
        totalMinor: 50_00,
        currency: 'EUR',
        accounts: [],
        hasExcludedAccounts: true,
        ratesSource: 'bundled',
        ratesAsOf: CarbonImmutable::parse('2026-01-01'),
        hasStaleRates: true,
        balancesWithoutRate: 2,
    );

    expect($nw->hasStaleRates)->toBeTrue();
    expect($nw->balancesWithoutRate)->toBe(2);
    expect($nw->ratesSource)->toBe('bundled');
});
