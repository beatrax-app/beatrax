<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\NetWorthQuery;

// NetWorthQueryTest.php declares the same helper, so the two files running
// together would be a redeclaration fatal without this guard.
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

function fxRate(DatabaseManager $db, string $quote, string $rate, string $date = '2026-06-05', string $source = 'ecb'): void
{
    $db->connection()->table('exchange_rates')->updateOrInsert(
        ['base_currency' => 'EUR', 'quote_currency' => $quote, 'rate_date' => $date, 'source' => $source],
        ['rate' => $rate, 'created_at' => now(), 'updated_at' => now()],
    );
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->user = User::create([
        'username' => 'fx-networth-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
});

it('includes a non-EUR account in the total after FX conversion', function (): void {
    nwAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');
    // $100 at 1.08 USD/EUR lands around 9259 minor once converted.
    nwAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    fxRate($this->db, 'USD', '1.08');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBeGreaterThan(200_000);
    expect($netWorth->currency)->toBe('EUR');
    expect($netWorth->hasExcludedAccounts)->toBeFalse();
    expect($netWorth->accountsWithoutRate)->toBe(0);
});

it('preserves the original currency on each account balance line (D-02)', function (): void {
    nwAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    fxRate($this->db, 'USD', '1.08');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    $usdLine = collect($netWorth->accounts)->firstWhere('currency', 'USD');
    expect($usdLine)->not->toBeNull();
    expect($usdLine->currency)->toBe('USD');
    expect($usdLine->balanceMinor)->toBe(10_000);
});

it('sets hasExcludedAccounts=false and accountsWithoutRate=0 when all accounts have a rate', function (): void {
    nwAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');
    nwAccount($this->db, $this->user->id, 'GBP wallet', 'paypal', 50_000, 'GBP');
    fxRate($this->db, 'GBP', '0.86');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->hasExcludedAccounts)->toBeFalse();
    expect($netWorth->accountsWithoutRate)->toBe(0);
});

it('excludes an account and sets hasExcludedAccounts=true when no rate exists for the pair', function (): void {
    nwAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');
    nwAccount($this->db, $this->user->id, 'JPY wallet', 'paypal', 5_000_000, 'JPY'); // no JPY rate seeded

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->hasExcludedAccounts)->toBeTrue();
    expect($netWorth->accountsWithoutRate)->toBe(1);
    expect($netWorth->totalMinor)->toBe(200_000);
});

it('populates ratesSource and ratesAsOf from the conversion used', function (): void {
    nwAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    fxRate($this->db, 'USD', '1.08', '2026-06-05', 'ecb');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->ratesSource)->toBe('ecb');
    expect($netWorth->ratesAsOf)->not->toBeNull();
    expect($netWorth->ratesAsOf->toDateString())->toBe('2026-06-05');
});

it('sets NetWorth.currency to the user base_currency', function (): void {
    nwAccount($this->db, $this->user->id, 'Checking', 'bank', 100_000, 'EUR');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->currency)->toBe($this->user->base_currency);
});

it('EUR-only accounts with EUR base currency work without any rate rows (passthrough path)', function (): void {
    nwAccount($this->db, $this->user->id, 'Checking', 'bank', 150_000, 'EUR');
    nwAccount($this->db, $this->user->id, 'PayPal', 'paypal', -10_000, 'EUR');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBe(140_000);
    expect($netWorth->hasExcludedAccounts)->toBeFalse();
    expect($netWorth->ratesSource)->toBeNull();
});

it('carries the converted base equivalent and per-pair rate metadata on a converted line (UI-SPEC §5.2/§5.4)', function (): void {
    $this->travelTo('2026-06-06'); // freeze clock: a 2026-06-05 rate is 1 day old → fresh
    nwAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    fxRate($this->db, 'USD', '1.08', '2026-06-05', 'ecb');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    $usdLine = collect($netWorth->accounts)->firstWhere('currency', 'USD');
    expect($usdLine->isConverted())->toBeTrue();
    // ~9259 minor after conversion, hence strictly under the native 10 000.
    expect($usdLine->baseEquivalentMinor)->not->toBeNull();
    expect($usdLine->baseEquivalentMinor)->toBeLessThan(10_000);
    expect($usdLine->fxRate)->not->toBeNull();
    expect($usdLine->fxSource)->toBe('ecb');
    expect($usdLine->fxAsOf?->toDateString())->toBe('2026-06-05');
    expect($usdLine->fxIsStale)->toBeFalse();
});

it('leaves FX fields null on a base-currency (passthrough) line', function (): void {
    nwAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    $eurLine = collect($netWorth->accounts)->firstWhere('currency', 'EUR');
    expect($eurLine->isConverted())->toBeFalse();
    expect($eurLine->baseEquivalentMinor)->toBeNull();
    expect($eurLine->fxRate)->toBeNull();
    expect($eurLine->hasNoRate('EUR'))->toBeFalse();
});

it('keeps a no-rate non-base account visible but without a base equivalent (D-07)', function (): void {
    nwAccount($this->db, $this->user->id, 'JPY wallet', 'paypal', 5_000_000, 'JPY'); // no JPY rate seeded

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    $jpyLine = collect($netWorth->accounts)->firstWhere('currency', 'JPY');
    expect($jpyLine)->not->toBeNull();
    expect($jpyLine->isConverted())->toBeFalse();
    expect($jpyLine->baseEquivalentMinor)->toBeNull();
    expect($jpyLine->hasNoRate('EUR'))->toBeTrue();
});

it('flags per-account staleness when the rate is older than the freshness threshold (D-12)', function (): void {
    $this->travelTo('2026-06-07'); // freeze clock so the 2026-05-20 rate is deterministically stale
    nwAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    fxRate($this->db, 'USD', '1.08', '2026-05-20', 'bundled'); // well past the 3-day threshold

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    $usdLine = collect($netWorth->accounts)->firstWhere('currency', 'USD');
    expect($usdLine->isConverted())->toBeTrue();
    expect($usdLine->fxIsStale)->toBeTrue();
    expect($netWorth->hasStaleRates)->toBeTrue();
});
