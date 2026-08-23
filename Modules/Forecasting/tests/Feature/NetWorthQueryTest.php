<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\FX\Public\Support\BundledRates;

if (! function_exists('nwAccount')) {
    function nwAccount(DatabaseManager $db, int $userId, string $name, string $kind, int $openingMinor, string $currency = 'EUR'): int
    {
        return $db->connection()->table('accounts')->insertGetId([
            'user_id' => $userId, 'name' => $name, 'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
            'kind' => $kind, 'iban' => 'NL00NETW'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'default_currency' => $currency, 'opening_balance_minor' => $openingMinor, 'opening_balance_as_of_date' => '2026-01-01',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);

    // The install seeds a bundled rate for thirty currencies, and the
    // exclusion case below turns on a pair having no rate at all. Cleared
    // here rather than reached around, so the case still tests the path it
    // was written for instead of a currency chosen for being unlisted.
    $this->db->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
    $this->user = User::create(['username' => 'networth-fixture', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
});

it('sums EUR account balances into a single net-worth figure with a breakdown', function (): void {
    nwAccount($this->db, $this->user->id, 'Checking', 'bank', 200000);
    nwAccount($this->db, $this->user->id, 'PayPal', 'paypal', -30000);

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBe(170000);     // 200000 - 30000
    expect($netWorth->currency)->toBe('EUR');
    expect($netWorth->accounts)->toHaveCount(2);
    expect($netWorth->hasExcludedAccounts)->toBeFalse();
});

it('excludes non-EUR accounts from the figure but keeps them in the breakdown', function (): void {
    nwAccount($this->db, $this->user->id, 'Checking', 'bank', 200000, 'EUR');
    nwAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 99999, 'USD');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBe(200000);
    expect($netWorth->accounts)->toHaveCount(2);
    expect($netWorth->hasExcludedAccounts)->toBeTrue();
});

it('excludes the internal paypal_funding routing account', function (): void {
    nwAccount($this->db, $this->user->id, 'Checking', 'bank', 200000);
    nwAccount($this->db, $this->user->id, 'PP funding', 'paypal_funding', 500000);

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->accounts)->toHaveCount(1);
    expect($netWorth->totalMinor)->toBe(200000);
});

it('reports no accounts for a fresh user', function (): void {
    expect(app(NetWorthQuery::class)->forUser($this->user)->hasAccounts())->toBeFalse();
});
