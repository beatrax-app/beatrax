<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;
use Modules\Forecasting\Internal\Pipeline\ScenarioApplier;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

/** @link ../../../../.docs/features/forecasting/projection-math.md#stage-1--one-series-becomes-banded-contributions */
beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'scenario-billing-day',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Main',
        'slug' => 'srkibd-main',
        'kind' => 'asn',
        'iban' => 'NL00SRKIBD0001',
        'default_currency' => 'EUR',
    ]);
});

/**
 * @return list<string>
 */
function srkibdDates(User $user, SeriesCadence $cadence, string $startDate, string $asOf, int $horizonDays): array
{
    /** @var CreateScenario $create */
    $create = app(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = app(AddScenarioMutation::class);
    /** @var ScenarioApplier $applier */
    $applier = app(ScenarioApplier::class);

    $scenarioId = ($create)($user, 'billing day '.$cadence->value);
    ($add)($scenarioId, $user, ScenarioMutationKind::AddRecurring->value, new AddRecurringPayload(
        startDate: $startDate,
        amountMinor: -1500,
        currency: 'EUR',
        direction: Direction::Expense->value,
        cadence: $cadence->value,
    ));

    $contributions = $applier->apply([], $scenarioId, $user, CarbonImmutable::parse($asOf), $horizonDays);

    return array_map(static fn (ForecastContribution $c): string => $c->date->toDateString(), $contributions);
}

it('returns a modelled monthly charge to the 31st after February has clamped it', function (): void {
    expect(srkibdDates($this->user, SeriesCadence::Monthly, '2026-01-31', '2026-01-01', 220))->toBe([
        '2026-01-31',
        '2026-02-28',
        '2026-03-31',
        '2026-04-30',
        '2026-05-31',
        '2026-06-30',
        '2026-07-31',
    ]);
});

it('returns a modelled quarterly charge to the 31st a year past a 30-day quarter', function (): void {
    expect(srkibdDates($this->user, SeriesCadence::Quarterly, '2025-12-31', '2025-12-01', 420))->toBe([
        '2025-12-31',
        '2026-03-31',
        '2026-06-30',
        '2026-09-30',
        '2026-12-31',
    ]);
});

it('returns a modelled yearly charge to 29 February on the next leap year', function (): void {
    expect(srkibdDates($this->user, SeriesCadence::Yearly, '2024-02-29', '2024-02-01', 1560))->toBe([
        '2024-02-29',
        '2025-02-28',
        '2026-02-28',
        '2027-02-28',
        '2028-02-29',
    ]);
});
