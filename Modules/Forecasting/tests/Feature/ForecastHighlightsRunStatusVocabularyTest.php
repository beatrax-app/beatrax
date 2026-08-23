<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

// The lowest-balance tile reads the newest forecast_runs row whose status is
// finished. Spelled as a bare string it fails silently: the query stays valid,
// no run matches, and a tile that has stopped reporting looks the same as one
// with no projection yet.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->user = User::query()->create([
        'username' => 'forecast-vocabulary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Vocabulary ASN',
        'slug' => 'forecast-vocab',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00FVOC0000000001',
        'default_currency' => Currency::Eur->value,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function fvRun(object $context, JobRunStatus $status, int $pointMinor): void
{
    $now = CarbonImmutable::now()->toDateTimeString();

    DB::table('forecast_runs')->insert([
        'user_id' => $context->user->id,
        'scenario_id' => null,
        'horizon_days' => ForecastHighlightsQuery::HORIZON_DAYS,
        'started_at' => $now,
        'completed_at' => $now,
        'status' => $status->value,
        'result_json' => json_encode([
            'accounts' => [
                (string) $context->account->id => [
                    'points' => [
                        ['date' => '2026-06-01', 'point_minor' => $pointMinor],
                    ],
                ],
            ],
        ]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('reads the run stored under the status the owning enum calls complete', function (): void {
    fvRun($this, JobRunStatus::Complete, -4200);

    expect(app(ForecastHighlightsQuery::class)->forUser($this->user)->lowestProjectedBalanceMinor)
        ->toBe(-4200);
});

// The other three cases share the column, so a spelling that drifted onto one
// of them would surface an unfinished projection as a real forecast.
it('reads none of the other cases the same column accepts', function (): void {
    foreach ([JobRunStatus::Pending, JobRunStatus::Running, JobRunStatus::Failed] as $status) {
        fvRun($this, $status, -9900);
    }

    expect(app(ForecastHighlightsQuery::class)->forUser($this->user)->lowestProjectedBalanceMinor)
        ->toBeNull();
});
