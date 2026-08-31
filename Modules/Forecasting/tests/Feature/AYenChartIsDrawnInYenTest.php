<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Forecasting\Models\ForecastRun;
use Modules\Ledger\Public\ValueObjects\Money;

uses(RefreshDatabase::class);

// The chart already declares the currency its axis is labelled in, and then
// divided every point by a hundred regardless: a ¥980,000 balance was plotted
// at -9800 beside an axis reading ¥. Same page, same figure, two answers a
// hundredfold apart.

const YEN_CHART_POINT_MINOR = -980_000;

function yenChartUser(): User
{
    return User::query()->create([
        'username' => 'yen-chart-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'JPY',
    ]);
}

function yenChartAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Tokyo',
        'slug' => 'yen-chart-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'JP00YEN'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'JPY',
        'opening_balance_minor' => YEN_CHART_POINT_MINOR,
        'opening_balance_as_of_date' => '2026-05-01',
        'forecast_min_buffer_minor' => 50_000,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function yenChartRun(DatabaseManager $db, int $userId, int $accountId): void
{
    $points = [];
    for ($d = 0; $d <= 30; $d++) {
        $points[] = [
            'date' => (new CarbonImmutable('2026-05-19'))->addDays($d)->toDateString(),
            'low_minor' => YEN_CHART_POINT_MINOR - 1_000,
            'point_minor' => YEN_CHART_POINT_MINOR,
            'high_minor' => YEN_CHART_POINT_MINOR + 1_000,
            'currency' => 'JPY',
        ];
    }

    $run = new ForecastRun;
    $run->user_id = $userId;
    $run->scenario_id = null;
    $run->horizon_days = 30;
    $run->status = 'complete';
    $run->save();

    $db->connection()->table('forecast_runs')->where('id', $run->id)->update([
        'result_json' => json_encode([
            'as_of' => '2026-05-19',
            'horizon_days' => 30,
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'Tokyo',
                    'default_currency' => 'JPY',
                    'today_balance_minor' => YEN_CHART_POINT_MINOR,
                    'anchor_source' => 'sum_of_transactions',
                    'points' => $points,
                ],
            ],
        ]),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = yenChartUser();
    $this->accountId = yenChartAccount($db, $this->user->id);
    yenChartRun($db, $this->user->id, $this->accountId);
    $this->actingAs($this->user);
});

it('plots a yen balance at the figure the page prints beside it', function (): void {
    $view = app(ForecastChartView::class)
        ->selectedAccount($this->accountId, 30, null, $this->user, 'JPY');

    $options = $view['apexOptions'];

    expect($options['beatraxCurrency'])->toBe('JPY');

    $line = $options['series'][1]['data'];
    expect($line[0]['y'])->toBe((float) YEN_CHART_POINT_MINOR);

    $range = $options['series'][0]['data'];
    expect($range[0]['y'])->toBe([(float) (YEN_CHART_POINT_MINOR - 1_000), (float) (YEN_CHART_POINT_MINOR + 1_000)]);
});

it('draws the buffer floor line at the same scale as the series', function (): void {
    $view = app(ForecastChartView::class)
        ->selectedAccount($this->accountId, 30, null, $this->user, 'JPY');

    expect($view['apexOptions']['annotations']['yaxis'][0]['y2'])->toBe(50_000.0);
});

it('keeps a two-decimal currency exactly where it was', function (): void {
    expect(Money::majorUnits(-980_000, 'EUR'))->toBe(-9800.0)
        ->and(Money::majorUnits(-980_000, 'JPY'))->toBe(-980000.0)
        ->and(Money::majorUnits(1_000, 'ZZZ'))->toBe(10.0);
});
