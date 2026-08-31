<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\ScenarioEditorSidebar;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Http\Livewire\ModelWhatIfDropdown;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

// Four scenario amount boxes carried a hand-written "50,00" / "15,00" /
// "11,49" and no keyboard hint at all: one Dutch figure shown to all 26
// locales, over a parser that reads at the series' or the form's own currency.

function scenarioShapeUser(string $baseCurrency): User
{
    return User::query()->create([
        'username' => 'scenario-shape-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => $baseCurrency,
        'default_currency_view' => 'eur_only',
    ]);
}

function scenarioShapeSeries(User $user, string $currency): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Tokyo rent',
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => -120_000,
        'latest_currency' => $currency,
        'monthly_equivalent_minor' => -120_000,
        'variance_tolerance_percent' => 5,
        'cluster_key' => 'scenario-shape-'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => 'tokyo-rent',
        'next_expected_at' => '2026-06-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

it('offers the one-off box an example its own currency can hold', function (): void {
    $user = scenarioShapeUser(Currency::Jpy->value);

    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create(['user_id' => $user->id, 'name' => 'Tokyo']);

    $html = Livewire::actingAs($user)
        ->test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('startAddMutation')
        ->call('selectKind', ScenarioMutationKind::AddOneOff->value)
        ->html();

    expect($html)->toContain('placeholder="5,000"')
        ->and($html)->not->toContain('placeholder="50,00"')
        ->and($html)->toContain('inputmode="numeric"');
});

it('writes the euro one-off example the way the reader writes a number', function (): void {
    $user = scenarioShapeUser(Currency::Eur->value);

    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create(['user_id' => $user->id, 'name' => 'Amsterdam']);

    app()->setLocale('en');

    $html = Livewire::actingAs($user)
        ->test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('startAddMutation')
        ->call('selectKind', ScenarioMutationKind::AddOneOff->value)
        ->html();

    expect($html)->toContain('placeholder="50.00"')
        ->and($html)->not->toContain('placeholder="50,00"')
        ->and($html)->toContain('inputmode="decimal"');
});

it('offers the new-amount box the shape the series own currency takes', function (): void {
    $user = scenarioShapeUser(Currency::Eur->value);
    $seriesId = scenarioShapeSeries($user, Currency::Jpy->value);

    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create(['user_id' => $user->id, 'name' => 'Tokyo']);

    $html = Livewire::actingAs($user)
        ->test(ScenarioEditorSidebar::class, ['scenarioId' => $scenario->id])
        ->call('startAddMutation')
        ->call('selectKind', ScenarioMutationKind::ChangeSeriesAmount->value)
        ->set('form.seriesId', (string) $seriesId)
        ->html();

    expect($html)->toContain('placeholder="1,149"')
        ->and($html)->not->toContain('placeholder="11,49"')
        ->and($html)->toContain('inputmode="numeric"');
});

it('offers the what-if box the shape the series own currency takes', function (): void {
    $user = scenarioShapeUser(Currency::Eur->value);
    $seriesId = scenarioShapeSeries($user, Currency::Jpy->value);

    $html = Livewire::actingAs($user)
        ->test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->call('openAmountForm')
        ->html();

    expect($html)->toContain('placeholder="1,149"')
        ->and($html)->not->toContain('placeholder="11,49"')
        ->and($html)->toContain('inputmode="numeric"');
});

it('still offers the what-if box two decimals on a euro series', function (): void {
    $user = scenarioShapeUser(Currency::Eur->value);
    $seriesId = scenarioShapeSeries($user, Currency::Eur->value);

    app()->setLocale('nl');

    $html = Livewire::actingAs($user)
        ->test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->call('openAmountForm')
        ->html();

    expect($html)->toContain('placeholder="11,49"')
        ->and($html)->toContain('inputmode="decimal"');
});
