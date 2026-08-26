<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;

// A Revolut import lands rows in whatever currency the file names, so one
// account holds euro and dollar side by side. Measured on an iPhone: six euro
// rows worth EUR3,509.85 and three dollar ones worth -USD221.00 rendered as
// NET WORTH EUR3,288.85 -- euro cents and dollar cents added together.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);

    // The bundled snapshot ships a rate for every major, and one case here
    // turns on a pair having none at all, so this suite builds its own world.
    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::query()->create([
        'username' => 'multi-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function multiCcyAccount(DatabaseManager $db, int $userId, string $defaultCurrency = 'EUR'): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Revolut',
        'slug' => 'revolut-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'GB00REV'.strtoupper($hex),
        'default_currency' => $defaultCurrency,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function multiCcyRow(DatabaseManager $db, int $userId, int $accountId, int $minor, string $currency): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'revolut-csv',
        'raw_file_path' => '/tmp/rev-'.$hex.'.csv',
        'sha256' => hash('sha256', 'rev-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'rev-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_normalized' => 'rev',
        'counterparty_name' => 'REV',
        'normalization_version' => 1,
        'description' => 'revolut fixture',
        'type' => $minor >= 0 ? TransactionType::Income->value : TransactionType::Expense->value,
        'source_format' => 'revolut-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function multiCcyRate(DatabaseManager $db, string $quote, string $rate): void
{
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => $quote,
        'rate_date' => '2026-08-23',
        'rate' => $rate,
        'source' => 'ecb',
        'created_at' => '2026-08-23 00:00:00',
        'updated_at' => '2026-08-23 00:00:00',
    ]);
}

function multiCcyRevolutRows(DatabaseManager $db, int $userId, int $accountId): void
{
    foreach ([200_000, 100_000, 30_000, 15_000, 5_000, 985] as $minor) {
        multiCcyRow($db, $userId, $accountId, $minor, Currency::Eur->value);
    }
    foreach ([-10_000, -7_100, -5_000] as $minor) {
        multiCcyRow($db, $userId, $accountId, $minor, Currency::Usd->value);
    }
}

it('converts the dollars on a euro account instead of adding their cents to the euros', function (): void {
    $accountId = multiCcyAccount($this->db, $this->user->id);
    multiCcyRevolutRows($this->db, $this->user->id, $accountId);
    multiCcyRate($this->db, Currency::Usd->value, '2.0');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBe(339_935);
});

// A euro line and a dollar line on one account, each converted at its own
// rate, is the whole point: EUR3,509.85 stands, -USD221.00 becomes -EUR110.50
// at 2.0, and the two never meet as bare cents.
it('reports the euro and the dollar as separate breakdown lines', function (): void {
    $accountId = multiCcyAccount($this->db, $this->user->id);
    multiCcyRevolutRows($this->db, $this->user->id, $accountId);
    multiCcyRate($this->db, Currency::Usd->value, '2.0');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    $eur = collect($netWorth->accounts)->firstWhere('currency', Currency::Eur->value);
    $usd = collect($netWorth->accounts)->firstWhere('currency', Currency::Usd->value);

    expect($netWorth->accounts)->toHaveCount(2)
        ->and($eur->balanceMinor)->toBe(350_985)
        ->and($eur->accountId)->toBe($accountId)
        ->and($usd->balanceMinor)->toBe(-22_100)
        ->and($usd->accountId)->toBe($accountId)
        ->and($usd->baseEquivalentMinor)->toBe(-11_050);
});

// Two lines are still one account, and the card prints a count of accounts.
it('counts the account once though it contributes two lines', function (): void {
    $accountId = multiCcyAccount($this->db, $this->user->id);
    multiCcyRevolutRows($this->db, $this->user->id, $accountId);
    multiCcyRate($this->db, Currency::Usd->value, '2.0');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->accounts)->toHaveCount(2)
        ->and($netWorth->accountCount())->toBe(1);
});

// Never a silent one-to-one: a line whose pair has no rate is listed at its
// own figure, named as unconverted, and kept out of the total.
it('names the line it cannot convert instead of counting it at par', function (): void {
    $accountId = multiCcyAccount($this->db, $this->user->id);
    multiCcyRevolutRows($this->db, $this->user->id, $accountId);
    multiCcyRow($this->db, $this->user->id, $accountId, 500_000, Currency::Jpy->value);
    multiCcyRate($this->db, Currency::Usd->value, '2.0');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    $jpy = collect($netWorth->accounts)->firstWhere('currency', Currency::Jpy->value);

    expect($netWorth->totalMinor)->toBe(339_935)
        ->and($netWorth->balancesWithoutRate)->toBe(1)
        ->and($netWorth->hasExcludedAccounts)->toBeTrue()
        ->and($jpy->balanceMinor)->toBe(500_000)
        ->and($jpy->baseEquivalentMinor)->toBeNull()
        ->and($jpy->hasNoRate(Currency::Eur->value))->toBeTrue();
});

// The account with nothing on it still belongs in the breakdown, at zero in
// the currency it is denominated in -- not missing, and not in some other one.
it('still lists an account with no rows at all', function (): void {
    multiCcyAccount($this->db, $this->user->id, Currency::Usd->value);
    multiCcyRate($this->db, Currency::Usd->value, '2.0');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->accounts)->toHaveCount(1)
        ->and($netWorth->accounts[0]->currency)->toBe(Currency::Usd->value)
        ->and($netWorth->accounts[0]->balanceMinor)->toBe(0);
});
