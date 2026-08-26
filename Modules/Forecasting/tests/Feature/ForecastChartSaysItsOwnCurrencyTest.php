<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Models\ForecastRun;

uses(RefreshDatabase::class);

// The ApexCharts helper in app.js formats every money axis with
// `document.documentElement.dataset.baseCurrency` — the READER's reporting
// currency — while the points it is drawing are in whatever currency the chart
// was built from. On the phone, with the reporting currency on USD, the ASN
// panel printed "EUR 2,706.72 today" and then drew an axis reading $2,708 /
// $2,707 / $2,706 four centimetres below it: one number, two currencies.

function fccUser(): User
{
    return User::query()->create([
        'username' => 'fcc-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'USD',
    ]);
}

function fccAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN',
        'slug' => 'fcc-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00FCC'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 270672,
        'opening_balance_as_of_date' => '2026-05-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function fccRun(DatabaseManager $db, int $userId, int $accountId, int $horizonDays): void
{
    $points = [];
    for ($d = 0; $d <= 30; $d++) {
        $points[] = [
            'date' => (new CarbonImmutable('2026-05-19'))->addDays($d)->toDateString(),
            'low_minor' => 270572,
            'point_minor' => 270672,
            'high_minor' => 270772,
            'currency' => 'EUR',
        ];
    }

    $run = new ForecastRun;
    $run->user_id = $userId;
    $run->scenario_id = null;
    $run->horizon_days = $horizonDays;
    $run->status = 'complete';
    $run->save();
    $db->connection()->table('forecast_runs')->where('id', $run->id)->update([
        'result_json' => json_encode([
            'as_of' => '2026-05-19',
            'horizon_days' => $horizonDays,
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'ASN',
                    'default_currency' => 'EUR',
                    'today_balance_minor' => 270672,
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
    $this->user = fccUser();
    $this->accountId = fccAccount($db, $this->user->id);
    fccRun($db, $this->user->id, $this->accountId, 30);
});

it('tells the per-account chart which currency its own points are in', function (): void {
    $html = $this->actingAs($this->user)
        ->get('/forecast?account='.$this->accountId)
        ->assertOk()
        ->getContent();

    expect($html)->toContain('&quot;beatraxCurrency&quot;:&quot;EUR&quot;');
});

it('tells the all-accounts chart which currency its roll-up is in', function (): void {
    $html = $this->actingAs($this->user)
        ->get('/forecast')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('&quot;beatraxCurrency&quot;');
});

it('reads the chart-declared currency before falling back to the page attribute', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($js)->toContain('options.beatraxCurrency')
        ->and($js)->toContain('dataset.baseCurrency');

    $declared = strpos($js, 'options.beatraxCurrency');
    $fallback = strpos($js, 'dataset.baseCurrency');

    expect($declared)->toBeLessThan($fallback);
});
