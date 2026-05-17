<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;
use Modules\Recurring\Public\Actions\RejectRecurringSeries;
use Modules\Recurring\Public\Actions\UnRejectRecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * UnRejectRecurringSeries — promotes a rejected series back to pending
 * so the next sweep will resurface it. Idempotent: a non-rejected
 * starting state is a silent no-op (matches the chains-side analog).
 */

function urrUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function urrSeries(User $user, string $cluster): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'urr-probe',
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
    $this->user = urrUser('urr@diederik.test');
    /** @var UnRejectRecurringSeries $action */
    $action = $this->app->make(UnRejectRecurringSeries::class);
    $this->action = $action;
    /** @var RejectRecurringSeries $reject */
    $reject = $this->app->make(RejectRecurringSeries::class);
    $this->reject = $reject;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('promotes a rejected series back to pending', function (): void {
    $series = urrSeries($this->user, 'urr::happy');
    ($this->reject)($series->id, $this->user);

    ($this->action)($series->id, $this->user);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('pending');

    $transitions = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->orderBy('id')
        ->get();
    expect($transitions)->toHaveCount(2);
    expect($transitions[1]->to_state)->toBe('pending');
    expect($transitions[1]->actor)->toBe('user');
});

it('is a silent no-op when the series is not currently rejected', function (): void {
    $series = urrSeries($this->user, 'urr::noop');

    ($this->action)($series->id, $this->user);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('pending');

    $count = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->count();
    expect($count)->toBe(0);
});

it('throws NotFoundHttpException for a cross-user series id', function (): void {
    $intruder = urrUser('urr-intruder@diederik.test');
    $series = urrSeries($this->user, 'urr::xuser');
    ($this->reject)($series->id, $this->user);

    expect(fn () => ($this->action)($series->id, $intruder))
        ->toThrow(NotFoundHttpException::class);
});
