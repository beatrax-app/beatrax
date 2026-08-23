<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\FX\Public\Dto\ConversionResult;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;

// "Bundled rates are used. No data leaves this device." is what Settings tells
// a reader who leaves online fetch off, which is the default. On the phone
// `exchange_rates` held zero rows: FetchFxRatesJob is the only writer and its
// first act is to return when the toggle is off, so the bundled snapshot — the
// fallback the column's own migration promises — was never loaded. Choosing USD
// as the reporting currency was accepted and every total stayed in euro.

it('carries the bundled snapshot into exchange_rates without any network fetch', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $rows = $db->connection()->table('exchange_rates')->where('source', 'bundled')->count();

    expect($rows)->toBeGreaterThan(0);
});

it('converts a euro amount into the reporting currency on an install that never went online', function (): void {
    User::query()->create([
        'username' => 'fx-bundled',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'USD',
        'fx_online_enabled' => false,
    ]);

    /** @var ExchangeRateService $service */
    $service = app(ExchangeRateService::class);

    /** @var ConversionResult $result */
    $result = $service->convertToBase(Money::ofMinor(100_000, 'EUR'), 'USD');

    expect($result->isPassthrough)->toBeFalse()
        ->and($result->converted->currency())->toBe('USD');
});
