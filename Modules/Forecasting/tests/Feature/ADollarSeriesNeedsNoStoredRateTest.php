<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

// Nothing in production ever wrote recurring_series.latest_fx_rate_used, so
// every real dollar series reached the fold with a null rate and took the whole
// projection down with it. The corpus fixture hand-supplied 0.9050 and six
// tests stayed green over the dead path.

const DSNR_ASOF = '2026-05-01';

const DSNR_CHARGE_DATE = '2026-05-18';

const DSNR_OPENING_MINOR = 150_000;

// Behind the opening-balance date, so it is history the anchor already counted
// rather than a booked row ahead of today.
const DSNR_OBSERVED_DATE = '2026-04-18';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(DSNR_ASOF.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'dsnr-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->accountId = (int) $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'ASN Betaalrekening',
        'slug' => 'dsnr-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00DSNR'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => Currency::Eur->value,
        'opening_balance_minor' => DSNR_OPENING_MINOR,
        'opening_balance_as_of_date' => DSNR_ASOF,
        'created_at' => DSNR_ASOF.' 00:00:00',
        'updated_at' => DSNR_ASOF.' 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

// No latest_fx_rate_used key at all: the column takes its migration default,
// which is the value every series carries in the field.
function dsnrSeries(DatabaseManager $db, User $user, string $currency): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => Direction::Expense->value,
        'detected_name' => 'Netflix US',
        'state' => 'approved',
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => -1_199,
        'latest_currency' => $currency,
        'monthly_equivalent_minor' => -1_199,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => DSNR_CHARGE_DATE,
        'cluster_key' => 'dsnr-cluster-'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => 'netflix us',
        'created_at' => DSNR_ASOF.' 00:00:00',
        'updated_at' => DSNR_ASOF.' 00:00:00',
    ]);
}

// Binds a series to an account the way the resolver reads it: the latest
// occurrence's transaction. Without one it falls back to the reader's first
// account by name, which is also the first the pipeline folds.
function dsnrBindToAccount(DatabaseManager $db, User $user, int $seriesId, int $accountId): void
{
    $importRunId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dsnr-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'dsnr-'.bin2hex(random_bytes(8))),
        'uploaded_at' => DSNR_ASOF.' 00:00:00',
        'status' => 'previewed',
        'created_at' => DSNR_ASOF.' 00:00:00',
        'updated_at' => DSNR_ASOF.' 00:00:00',
    ]);

    $transactionId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'import_run_id' => $importRunId,
        'fingerprint' => hash('sha256', 'dsnr-'.bin2hex(random_bytes(8))),
        'posted_at' => DSNR_OBSERVED_DATE,
        'booked_at' => DSNR_OBSERVED_DATE.' 00:00:00',
        'value_date' => DSNR_OBSERVED_DATE,
        'amount_minor' => -1_199,
        'currency' => 'USD',
        'settled_amount_minor' => -959,
        'settled_currency' => Currency::Eur->value,
        'fx_rate_used' => '0.8',
        'counterparty_normalized' => 'netflix us',
        'counterparty_name' => 'Netflix US',
        'normalization_version' => 1,
        'description' => 'Netflix US '.DSNR_OBSERVED_DATE,
        'type' => Direction::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => DSNR_ASOF.' 00:00:00',
        'updated_at' => DSNR_ASOF.' 00:00:00',
    ]);

    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $transactionId,
        'observed_at' => DSNR_OBSERVED_DATE,
        'observed_amount_minor' => -1_199,
        'observed_currency' => 'USD',
        'created_at' => DSNR_ASOF.' 00:00:00',
        'updated_at' => DSNR_ASOF.' 00:00:00',
    ]);
}

// One pair, one rate, chosen so the arithmetic is readable: the bundled
// snapshot ships a rate of its own and would decide these figures instead.
function dsnrRate(DatabaseManager $db, string $quoteCurrency, string $rate): void
{
    $db->connection()->table('exchange_rates')->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => $quoteCurrency,
        'rate_date' => DSNR_ASOF,
        'rate' => $rate,
        'source' => 'test',
        'created_at' => DSNR_ASOF.' 00:00:00',
        'updated_at' => DSNR_ASOF.' 00:00:00',
    ]);
}

it('prices a dollar series at the live rate without a rate stored on the series', function (): void {
    dsnrSeries($this->db, $this->user, 'USD');
    dsnrRate($this->db, 'USD', '1.25');

    Bus::dispatchSync(new ProjectForecastJob(userId: $this->user->id, scenarioId: null, horizonDays: 30));

    $dto = app(ForecastQuery::class)->forUser($this->accountId, 30, null, $this->user);

    $charge = null;
    foreach ($dto->points as $point) {
        if ($point->date === DSNR_CHARGE_DATE) {
            $charge = $point;
        }
    }

    // USD 11.99 at EUR 1 = USD 1.25 is EUR 9.59, so the curve steps down by
    // 959 — not by 1199, which would be the dollar figure worn as euros.
    expect($charge)->not->toBeNull()
        ->and($charge->pointMinor)->toBe(DSNR_OPENING_MINOR - 959)
        ->and($charge->currency)->toBe(Currency::Eur->value)
        ->and($dto->unconvertedCurrencies)->toBe([]);
});

// The rate map is memoised per target currency, and the first euro account to
// be folded holds no dollars. Built from that account's own currencies it comes
// back empty, and the dollars on the next euro account read as a pair with no
// rate at all.
it('prices a dollar series on the second of two euro accounts', function (): void {
    $this->db->connection()->table('accounts')->where('id', $this->accountId)->update(['name' => 'Zzz Later']);
    $this->db->connection()->table('accounts')->insert([
        'user_id' => $this->user->id,
        'name' => 'Aaa First',
        'slug' => 'dsnr-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00DSNR'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => Currency::Eur->value,
        'opening_balance_minor' => DSNR_OPENING_MINOR,
        'opening_balance_as_of_date' => DSNR_ASOF,
        'created_at' => DSNR_ASOF.' 00:00:00',
        'updated_at' => DSNR_ASOF.' 00:00:00',
    ]);
    dsnrBindToAccount($this->db, $this->user, dsnrSeries($this->db, $this->user, 'USD'), $this->accountId);
    dsnrRate($this->db, 'USD', '1.25');

    Bus::dispatchSync(new ProjectForecastJob(userId: $this->user->id, scenarioId: null, horizonDays: 30));

    $dto = app(ForecastQuery::class)->forUser($this->accountId, 30, null, $this->user);

    $charge = null;
    foreach ($dto->points as $point) {
        if ($point->date === DSNR_CHARGE_DATE) {
            $charge = $point;
        }
    }

    expect($charge)->not->toBeNull()
        ->and($charge->pointMinor)->toBe(DSNR_OPENING_MINOR - 959)
        ->and($dto->unconvertedCurrencies)->toBe([]);
});

it('names a currency it cannot price rather than losing the whole projection', function (): void {
    dsnrSeries($this->db, $this->user, 'JPY');
    dsnrRate($this->db, 'USD', '1.25');

    Bus::dispatchSync(new ProjectForecastJob(userId: $this->user->id, scenarioId: null, horizonDays: 30));

    $dto = app(ForecastQuery::class)->forUser($this->accountId, 30, null, $this->user);

    $charge = null;
    foreach ($dto->points as $point) {
        if ($point->date === DSNR_CHARGE_DATE) {
            $charge = $point;
        }
    }

    expect($dto->isComputing)->toBeFalse()
        ->and($dto->runFailed)->toBeFalse()
        ->and($charge)->not->toBeNull()
        // Left out of the running balance rather than counted at one to one,
        // and named where the reader can see what is missing from it.
        ->and($charge->pointMinor)->toBe(DSNR_OPENING_MINOR)
        ->and($dto->unconvertedCurrencies)->toBe(['JPY']);
});
