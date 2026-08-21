<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function arsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function arsSeries(User $user, string $state, string $cluster): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'ars-probe',
        'state' => $state,
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => $cluster,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = arsUser('ars');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('promotes pending → approved, inserts one transitions row, dispatches event', function (): void {
    Event::fake([RecurringSeriesApproved::class]);

    $series = arsSeries($this->user, 'pending', 'ars::pending');

    /** @var ApproveRecurringSeries $action */
    $action = $this->app->make(ApproveRecurringSeries::class);
    ($action)($series->id, $this->user);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('approved');

    $transitions = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->get();
    expect($transitions)->toHaveCount(1);
    expect($transitions[0]->to_state)->toBe('approved');
    expect($transitions[0]->actor)->toBe('user');
    expect($transitions[0]->transition_reason)->toBe('user_action');

    Event::assertDispatched(RecurringSeriesApproved::class, function (RecurringSeriesApproved $e) use ($series): bool {
        return $e->seriesId === $series->id && $e->userId === $this->user->id;
    });
});

it('promotes cadence_changed → approved', function (): void {
    $series = arsSeries($this->user, 'pending', 'ars::cc');
    // The state machine is the only legal route into cadence_changed.
    /** @var RecurringSeriesStateMachine $sm */
    $sm = $this->app->make(RecurringSeriesStateMachine::class);
    $sm->transition($series, 'approved', 'user_action', 'user');
    $series->refresh();
    $sm->transition($series, 'cadence_changed', 'detector_cadence_flip', 'detector');

    /** @var ApproveRecurringSeries $action */
    $action = $this->app->make(ApproveRecurringSeries::class);
    ($action)($series->id, $this->user);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('approved');
});

it('is idempotent when the series is already approved (no second transitions row)', function (): void {
    Event::fake([RecurringSeriesApproved::class]);

    $series = arsSeries($this->user, 'pending', 'ars::idem');
    /** @var ApproveRecurringSeries $action */
    $action = $this->app->make(ApproveRecurringSeries::class);
    ($action)($series->id, $this->user);

    /** @var ApproveRecurringSeries $action */
    $action = $this->app->make(ApproveRecurringSeries::class);
    ($action)($series->id, $this->user);

    $count = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->count();
    expect($count)->toBe(1);
});

it('throws NotFoundHttpException for a cross-user series id', function (): void {
    $intruder = arsUser('ars-intruder');
    $series = arsSeries($this->user, 'pending', 'ars::xuser');

    /** @var ApproveRecurringSeries $action */
    $action = $this->app->make(ApproveRecurringSeries::class);
    expect(fn () => ($action)($series->id, $intruder))
        ->toThrow(NotFoundHttpException::class);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('pending');
});

it('throws NotFoundHttpException for an unknown series id', function (): void {
    /** @var ApproveRecurringSeries $action */
    $action = $this->app->make(ApproveRecurringSeries::class);
    expect(fn () => ($action)(999_999, $this->user))
        ->toThrow(NotFoundHttpException::class);
});
