<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

/** @link ../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
const FTG_TODAY = '2026-08-23';

const FTG_HORIZON_DAYS = 30;

const FTG_CHARGE_DATE = '2026-09-01';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(FTG_TODAY.' 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = User::query()->create([
        'username' => 'ftg',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
    $this->accountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'FTG Bank',
        'slug' => 'ftg-'.bin2hex(random_bytes(4)),
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00FTG'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 500_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    // Two series charging the same account on the same day. Per series the fold
    // combines their half-widths in quadrature; collapsed onto one funder line
    // it adds the bounds outright, and that difference is the whole of what the
    // toggle does to the picture.
    ftgSeries($this->db, $this->user->id, 'FTG Rent', -100_000);
    ftgSeries($this->db, $this->user->id, 'FTG Energy', -50_000);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function ftgSeries(DatabaseManager $db, int $userId, string $name, int $amountMinor): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => $amountMinor,
        'variance_tolerance_percent' => 20,
        'cluster_key' => 'ftg::'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => strtolower(str_replace(' ', '-', $name)),
        'next_expected_at' => FTG_CHARGE_DATE,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

// The button flipped a property nothing downstream read: ForecastChartView took
// no such argument and ProjectionPipeline hardcoded viewByFunder: false, so
// pressing it changed nothing but the button's own fill.
it('draws a different band once the reader asks to see the account by funder', function (): void {
    app(ProjectionPipeline::class)->project($this->user, null, FTG_HORIZON_DAYS);

    /** @var ForecastChartView $charts */
    $charts = app(ForecastChartView::class);

    $perSeries = $charts->selectedAccount($this->accountId, FTG_HORIZON_DAYS, null, $this->user, Currency::Eur->value, viewByFunder: false);
    $byFunder = $charts->selectedAccount($this->accountId, FTG_HORIZON_DAYS, null, $this->user, Currency::Eur->value, viewByFunder: true);

    // The point estimate is a sum either way, so only the band moves: the
    // collapse adds the bounds outright where the fold used quadrature.
    expect($byFunder['todayBalanceMinor'])->toBe($perSeries['todayBalanceMinor'])
        ->and($byFunder['horizonLowMinor'])->not->toBe($perSeries['horizonLowMinor'])
        ->and($byFunder['horizonLowMinor'])->toBeLessThan($perSeries['horizonLowMinor']);
});

it('redraws the chart payload when the toggle is pressed', function (): void {
    app(ProjectionPipeline::class)->project($this->user, null, FTG_HORIZON_DAYS);

    $component = Livewire::actingAs($this->user)
        ->test(ForecastPage::class)
        ->set('account', (string) $this->accountId);

    $before = $component->html();
    $after = $component->call('toggleViewByFunder')->html();

    expect($after)->not->toBe($before);

    $optionsOf = static function (string $html): string {
        expect(preg_match('/data-options="([^"]*)"/', $html, $matches))->toBe(1);

        return $matches[1];
    };

    expect($optionsOf($after))->not->toBe($optionsOf($before));
});
