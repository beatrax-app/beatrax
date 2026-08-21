<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;
use Modules\Recurring\Public\Actions\RejectRecurringSeries;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function rjrsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rjrsSeries(User $user, string $cluster): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'rjrs-probe',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => $cluster,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = rjrsUser('rjrs');
    /** @var RecurringSeriesStateMachine $sm */
    $sm = $this->app->make(RecurringSeriesStateMachine::class);
    $this->sm = $sm;
});

function rjrsAction(): RejectRecurringSeries
{
    /** @var RejectRecurringSeries $action */
    $action = app(RejectRecurringSeries::class);

    return $action;
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('rejects a pending series and dispatches RecurringSeriesRejected', function (): void {
    Event::fake([RecurringSeriesRejected::class]);

    $series = rjrsSeries($this->user, 'rjrs::pending');

    (rjrsAction())($series->id, $this->user);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('rejected');

    Event::assertDispatched(RecurringSeriesRejected::class);
});

it('rejects an approved series', function (): void {
    $series = rjrsSeries($this->user, 'rjrs::approved');
    $this->sm->transition($series, 'approved', 'user_action', 'user');

    (rjrsAction())($series->id, $this->user);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('rejected');
});

it('rejects a snoozed series', function (): void {
    $series = rjrsSeries($this->user, 'rjrs::snoozed');
    $this->sm->transition($series, 'snoozed', 'user_action', 'user');

    (rjrsAction())($series->id, $this->user);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('rejected');
});

it('rejects a cadence_changed series', function (): void {
    $series = rjrsSeries($this->user, 'rjrs::cc');
    $this->sm->transition($series, 'approved', 'user_action', 'user');
    $series->refresh();
    $this->sm->transition($series, 'cadence_changed', 'detector_cadence_flip', 'detector');

    (rjrsAction())($series->id, $this->user);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('rejected');
});

it('is idempotent when the series is already rejected (no second transitions row)', function (): void {
    $series = rjrsSeries($this->user, 'rjrs::idem');
    (rjrsAction())($series->id, $this->user);

    (rjrsAction())($series->id, $this->user);

    $count = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->count();
    expect($count)->toBe(1);
});

it('throws NotFoundHttpException for a cross-user series id', function (): void {
    $intruder = rjrsUser('rjrs-intruder');
    $series = rjrsSeries($this->user, 'rjrs::xuser');

    expect(fn () => (rjrsAction())($series->id, $intruder))
        ->toThrow(NotFoundHttpException::class);
});
