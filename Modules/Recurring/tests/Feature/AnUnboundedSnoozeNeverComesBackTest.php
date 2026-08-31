<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;
use Modules\Recurring\Public\Actions\SnoozeRecurringSeries;

function unbUser(): User
{
    return User::query()->create([
        'username' => 'unb-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function unbSeries(User $user): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'unb-probe',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'unb::'.bin2hex(random_bytes(4)),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = unbUser();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('refuses a snooze target past the six-month ceiling and leaves the series in review', function (): void {
    $series = unbSeries($this->user);
    /** @var SnoozeRecurringSeries $action */
    $action = app(SnoozeRecurringSeries::class);

    expect(fn () => ($action)($series->id, $this->user, CarbonImmutable::parse('2036-05-17 12:00:00')))
        ->toThrow(InvalidArgumentException::class);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('pending')
        ->and($fresh->snoozed_until)->toBeNull()
        ->and(RecurringSeriesTransition::query()->where('recurring_series_id', $series->id)->count())->toBe(0);
});

it('refuses a snooze target in the past', function (): void {
    $series = unbSeries($this->user);
    /** @var SnoozeRecurringSeries $action */
    $action = app(SnoozeRecurringSeries::class);

    expect(fn () => ($action)($series->id, $this->user, CarbonImmutable::parse('2026-05-16 12:00:00')))
        ->toThrow(InvalidArgumentException::class);

    expect(RecurringSeries::query()->findOrFail($series->id)->state)->toBe('pending');
});

it('drops a tampered review-page payload without a 500 and without snoozing', function (): void {
    $series = unbSeries($this->user);

    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->call('snooze', $series->id, '2036-05-17T12:00:00+00:00')
        ->assertOk();

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('pending')
        ->and($fresh->snoozed_until)->toBeNull();
});

it('still snoozes to a target inside the ceiling', function (): void {
    $series = unbSeries($this->user);
    /** @var SnoozeRecurringSeries $action */
    $action = app(SnoozeRecurringSeries::class);

    ($action)($series->id, $this->user, CarbonImmutable::parse('2026-06-17 12:00:00'));

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('snoozed')
        ->and($fresh->snoozed_until?->toDateTimeString())->toBe('2026-06-17 12:00:00');
});
