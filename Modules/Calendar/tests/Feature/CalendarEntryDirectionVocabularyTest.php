<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

// The month grid and the day panel both decide sign and colour by comparing a
// calendar entry's direction, which is recurring_series.direction carried
// through unchanged. The column is a real schema enum, so a spelling that
// drifts renders every inflow as an outflow — a minus sign on a salary, with
// no error anywhere.

function cedvSeries(User $user, Direction $direction, int $amountMinor, string $name): RecurringSeries
{
    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => $direction->value,
        'detected_name' => $name,
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => Currency::Eur->value,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cedv::'.$name,
        'next_expected_at' => CarbonImmutable::parse('2026-06-20'),
    ]);

    return $series;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');

    $this->user = User::query()->create([
        'username' => 'calendar-direction-vocabulary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('marks an income entry as an inflow on the month grid', function (): void {
    cedvSeries($this->user, Direction::Income, 350000, 'Salary Monthly');

    Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSee('Salary Monthly')
        ->assertSee('cal-entry--inflow');
});

it('leaves an expense entry off the inflow class', function (): void {
    cedvSeries($this->user, Direction::Expense, -9900, 'Netflix Monthly');

    Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSee('Netflix Monthly')
        ->assertDontSee('cal-entry--inflow');
});

// recurring_series.direction is declared as a schema enum, so a case that
// stops matching aborts the insert instead of quietly mis-signing a row.
it('stores both cases the recurring_series direction column accepts', function (): void {
    foreach (Direction::cases() as $index => $direction) {
        cedvSeries($this->user, $direction, 1000 * ($index + 1), 'Vocabulary '.$direction->value);
    }

    expect(RecurringSeries::query()->where('user_id', $this->user->id)->count())
        ->toBe(count(Direction::cases()));
});
