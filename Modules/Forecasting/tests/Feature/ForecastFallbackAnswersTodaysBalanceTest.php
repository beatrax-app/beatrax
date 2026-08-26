<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\ForecastQuery;

uses(RefreshDatabase::class);

// Every install has no forecast run until one is computed, so the flat-line
// fallback is what a reader sees for the whole of their first session. It kept
// its own copy of "the money on this account": an unbounded SUM(amount_minor)
// with no baseline. On the phone, straight out of onboarding, net worth read
// EUR 1,820.00 while the calendar's today cell and the forecast both read
// EUR 920 — the difference being a rent dated 1 September, nine days out.

function ffbUser(): User
{
    return User::query()->create([
        'username' => 'ffb-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ffbAccount(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $hex = bin2hex(random_bytes(4));

    return (int) $db->connection()->table('accounts')->insertGetId(array_merge([
        'user_id' => $userId,
        'name' => 'ING',
        'slug' => 'ffb-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00FFB'.strtoupper($hex),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ], $overrides));
}

function ffbTransaction(DatabaseManager $db, int $userId, int $accountId, int $amountMinor, string $postedAt, ?int $settledMinor = null): void
{
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'ing-nl-csv',
        'raw_file_path' => '/tmp/ffb-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'ffb-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-08-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'ffb-'.bin2hex(random_bytes(8))),
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor ?? $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ffb-fixture',
        'counterparty_name' => 'FFB Fixture',
        'normalization_version' => 1,
        'description' => 'ffb fixture',
        'type' => 'expense',
        'source_format' => 'ing-nl-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 09:00:00'));
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = ffbUser();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('leaves a future-dated row out of the balance it opens on', function (): void {
    $accountId = ffbAccount($this->db, $this->user->id);
    ffbTransaction($this->db, $this->user->id, $accountId, 200_000, '2026-08-01');
    ffbTransaction($this->db, $this->user->id, $accountId, -5_420, '2026-08-05');
    ffbTransaction($this->db, $this->user->id, $accountId, -9_500, '2026-08-12');
    ffbTransaction($this->db, $this->user->id, $accountId, -3_080, '2026-08-20');
    ffbTransaction($this->db, $this->user->id, $accountId, -90_000, '2026-09-01');

    $forecast = app(ForecastQuery::class)->forUser($accountId, 30, null, $this->user);

    expect($forecast->todayBalanceMinor)->toBe(182_000);
});

it('opens on the starting balance the account carries', function (): void {
    $accountId = ffbAccount($this->db, $this->user->id, [
        'starting_balance_minor' => 100_000,
        'starting_balance_date' => '2026-08-01',
    ]);
    ffbTransaction($this->db, $this->user->id, $accountId, 25_000, '2026-08-10');

    $forecast = app(ForecastQuery::class)->forUser($accountId, 30, null, $this->user);

    expect($forecast->todayBalanceMinor)->toBe(125_000);
});

// Same rule as the balance query the rest of the app moved onto: a row bought
// in another currency counts at what the account was debited, not at what the
// merchant charged.
it('counts a foreign row at what the account was actually debited', function (): void {
    $accountId = ffbAccount($this->db, $this->user->id);
    ffbTransaction($this->db, $this->user->id, $accountId, -12_99, '2026-08-10', settledMinor: -12_07);

    $forecast = app(ForecastQuery::class)->forUser($accountId, 30, null, $this->user);

    expect($forecast->todayBalanceMinor)->toBe(-12_07);
});

// The fallback drew today's balance on every day of the horizon, so a booked
// row dated ahead of today never moved it. On a phone with three future rows
// and no opening balance, /forecast rendered 366 points all at zero under the
// words "projected over the next 30 days" -- for a ledger holding EUR3,400
// arriving on 15 September. A wrong statement about someone's money, not a
// missing feature.
it('steps on a booked row dated ahead of today, because that is a certainty', function (): void {
    $accountId = ffbAccount($this->db, $this->user->id);
    ffbTransaction($this->db, $this->user->id, $accountId, 200_000, '2026-08-01');
    ffbTransaction($this->db, $this->user->id, $accountId, -90_000, '2026-09-01');

    /** @var ForecastQuery $query */
    $query = $this->app->make(ForecastQuery::class);
    $dto = $query->forUser($accountId, 30, null, $this->user);

    /** @var array<string, int> $byDate */
    $byDate = [];
    foreach ($dto->points as $point) {
        $byDate[$point->date] = $point->pointMinor;
    }

    expect($byDate['2026-08-23'])->toBe(200_000)
        ->and($byDate['2026-08-31'])->toBe(200_000)
        ->and($byDate['2026-09-01'])->toBe(110_000)
        ->and($byDate['2026-09-22'])->toBe(110_000);
});

// What is already booked carries no uncertainty, which is the only reason it
// may be drawn at all with no projection behind it.
it('draws a booked step with no band around it', function (): void {
    $accountId = ffbAccount($this->db, $this->user->id);
    ffbTransaction($this->db, $this->user->id, $accountId, 200_000, '2026-08-01');
    ffbTransaction($this->db, $this->user->id, $accountId, -90_000, '2026-09-01');

    /** @var ForecastQuery $query */
    $query = $this->app->make(ForecastQuery::class);
    $dto = $query->forUser($accountId, 30, null, $this->user);

    foreach ($dto->points as $point) {
        expect($point->lowMinor)->toBe($point->pointMinor);
        expect($point->highMinor)->toBe($point->pointMinor);
    }
});
