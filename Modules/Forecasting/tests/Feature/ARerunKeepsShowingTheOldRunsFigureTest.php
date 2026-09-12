<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Public\Enums\ShortfallRisk;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Public\ValueObjects\Money;

uses(RefreshDatabase::class);

// The chart says "Updating" off the newest run row while the highlights tile
// and the shortfall member read the newest COMPLETED one, so a re-projection
// left the dashboard printing the superseded run's dip as today's answer and
// its shortfall count as today's safety.

function rerunUser(): User
{
    return User::query()->create([
        'username' => 'rerun-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rerunAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Rerun Bank',
        'slug' => 'rerun-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00RERUN'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function rerunCompletedRun(DatabaseManager $db, int $userId, int $accountId, int $lowestMinor): void
{
    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => '2026-06-12',
            'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'Rerun Bank',
                    'default_currency' => 'EUR',
                    'today_balance_minor' => 100000,
                    'anchor_source' => 'user_input_opening_balance',
                    'points' => [
                        ['date' => '2026-06-20', 'low_minor' => $lowestMinor, 'point_minor' => $lowestMinor, 'high_minor' => $lowestMinor, 'currency' => 'EUR'],
                    ],
                ],
            ],
        ]),
        'created_at' => '2026-06-12 00:00:00',
        'updated_at' => '2026-06-12 00:00:00',
    ]);
}

function rerunInFlightRun(DatabaseManager $db, int $userId, string $status): void
{
    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
        'status' => $status,
        'result_json' => null,
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ]);
}

function rerunShortfallWindow(DatabaseManager $db, int $userId, int $accountId): void
{
    $db->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'scenario_id' => null,
        'horizon_days' => ForecastHighlightsQuery::TILE_HORIZON,
        'starts_at' => '2026-06-20',
        'ends_at' => '2026-06-25',
        'lowest_balance_minor' => -4200,
        'currency' => 'EUR',
        'buffer_used_minor' => 0,
        'created_at' => '2026-06-12 00:00:00',
        'updated_at' => '2026-06-12 00:00:00',
    ]);
}

// The dashboard redirects a user with no ledger at all to onboarding, and the
// tile under test lives on the dashboard.
function rerunSeedLedger(DatabaseManager $db, int $userId, int $accountId): void
{
    $hex = bin2hex(random_bytes(8));

    $importRunId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rerun.csv',
        'sha256' => str_pad($hex, 64, 'a'),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'inserted_count' => 1,
        'duplicate_count' => 0,
        'error_count' => 0,
        'enriched_count' => 0,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-06-02',
        'booked_at' => '2026-06-02 12:00:00',
        'value_date' => '2026-06-02',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Rerun Seed',
        'counterparty_normalized' => 'rerun seed',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => 1,
        'fingerprint' => str_pad($hex, 64, 'b'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'payment_type' => 'unknown',
        'occurrence_ordinal' => 0,
        'created_at' => '2026-06-02 00:00:00',
        'updated_at' => '2026-06-02 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-13 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = rerunUser();
    $this->accountId = rerunAccount($this->db, (int) $this->user->id);
    rerunSeedLedger($this->db, (int) $this->user->id, $this->accountId);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('reports the completed run when nothing newer is under way', function (): void {
    rerunCompletedRun($this->db, (int) $this->user->id, $this->accountId, 41733);

    $query = app(ForecastHighlightsQuery::class);

    expect($query->shortfallRiskForUser($this->user))->toBe(ShortfallRisk::None)
        ->and($query->forUser($this->user)->isComputing)->toBeFalse()
        ->and($query->forUser($this->user)->lowestProjectedBalanceMinor)->toBe(41733);
});

it('answers computing rather than the superseded run once a rerun is under way', function (string $status): void {
    rerunCompletedRun($this->db, (int) $this->user->id, $this->accountId, 41733);
    rerunInFlightRun($this->db, (int) $this->user->id, $status);

    $query = app(ForecastHighlightsQuery::class);

    expect($query->shortfallRiskForUser($this->user))->toBe(ShortfallRisk::Computing)
        ->and($query->forUser($this->user)->isComputing)->toBeTrue();
})->with(['pending', 'running']);

it('does not report a shortfall the superseded run found while the rerun is under way', function (): void {
    rerunCompletedRun($this->db, (int) $this->user->id, $this->accountId, -4200);
    rerunShortfallWindow($this->db, (int) $this->user->id, $this->accountId);
    rerunInFlightRun($this->db, (int) $this->user->id, 'running');

    expect(app(ForecastHighlightsQuery::class)->shortfallRiskForUser($this->user))
        ->toBe(ShortfallRisk::Computing);
});

it('goes back to the shortfall the moment the rerun closes', function (): void {
    rerunCompletedRun($this->db, (int) $this->user->id, $this->accountId, -4200);
    rerunShortfallWindow($this->db, (int) $this->user->id, $this->accountId);
    rerunInFlightRun($this->db, (int) $this->user->id, 'running');

    $this->db->connection()->table('forecast_runs')
        ->orderByDesc('id')
        ->limit(1)
        ->update(['status' => 'complete']);

    expect(app(ForecastHighlightsQuery::class)->shortfallRiskForUser($this->user))
        ->toBe(ShortfallRisk::Ahead);
});

it('treats a rerun that failed as an answer, not as work still in hand', function (): void {
    rerunCompletedRun($this->db, (int) $this->user->id, $this->accountId, 41733);
    rerunInFlightRun($this->db, (int) $this->user->id, 'failed');

    $query = app(ForecastHighlightsQuery::class);

    expect($query->shortfallRiskForUser($this->user))->toBe(ShortfallRisk::None)
        ->and($query->forUser($this->user)->isComputing)->toBeFalse();
});

it('prints the old figure on the dashboard tile while nothing is under way', function (): void {
    rerunCompletedRun($this->db, (int) $this->user->id, $this->accountId, 41733);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee(Money::ofMinor(41733, 'EUR')->format());
});

it('replaces the tile figure with the updating line while a rerun is under way', function (): void {
    rerunCompletedRun($this->db, (int) $this->user->id, $this->accountId, 41733);
    rerunInFlightRun($this->db, (int) $this->user->id, 'running');

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSee(Money::ofMinor(41733, 'EUR')->format())
        ->assertSee(Lang::get('forecasting::forecast.updating'));
});

it('says the all-accounts roll-up is updating rather than drawing it short an account', function (): void {
    rerunCompletedRun($this->db, (int) $this->user->id, $this->accountId, 41733);
    rerunInFlightRun($this->db, (int) $this->user->id, 'running');

    $this->actingAs($this->user)
        ->get('/forecast?horizon='.ForecastHighlightsQuery::TILE_HORIZON)
        ->assertOk()
        ->assertSee(Lang::get('forecasting::forecast.updating'));
});

it('gives the digest a sentence for a forecast still in hand', function (): void {
    $line = Lang::get('notifications::copy.digest.forecast_running');

    expect($line)->not->toBe('notifications::copy.digest.forecast_running')
        ->and($line)->not->toBe(Lang::get('notifications::copy.digest.forecast_not_run'));
});
