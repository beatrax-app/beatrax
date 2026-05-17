<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;
use Modules\Recurring\Public\Actions\SnoozeRecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * SnoozeRecurringSeries — atomic write of `snoozed_until` + state
 * transition through the state machine. Idempotent when re-snoozing
 * to the same date. Cross-user 404. Transitions row notes carries
 * the snooze target.
 */

function snrsUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function snrsSeries(User $user, string $cluster): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'snrs-probe',
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
    $this->user = snrsUser('snrs@diederik.test');
    /** @var SnoozeRecurringSeries $action */
    $action = $this->app->make(SnoozeRecurringSeries::class);
    $this->action = $action;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('writes snoozed_until and flips state to snoozed atomically', function (): void {
    $series = snrsSeries($this->user, 'snrs::pending');
    $until = CarbonImmutable::parse('2026-06-17 12:00:00');

    ($this->action)($series->id, $this->user, $until);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('snoozed');
    expect($fresh->snoozed_until?->toDateTimeString())->toBe('2026-06-17 12:00:00');

    $transition = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->firstOrFail();
    expect($transition->to_state)->toBe('snoozed');
    expect($transition->actor)->toBe('user');
    expect($transition->notes)->toContain('snoozed_until=2026-06-17');
});

it('is idempotent when re-snoozed to the same date (no second transitions row)', function (): void {
    $series = snrsSeries($this->user, 'snrs::idem');
    $until = CarbonImmutable::parse('2026-06-17 12:00:00');

    ($this->action)($series->id, $this->user, $until);
    ($this->action)($series->id, $this->user, $until);

    $count = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->count();
    expect($count)->toBe(1);
});

it('throws NotFoundHttpException for a cross-user series id', function (): void {
    $intruder = snrsUser('snrs-intruder@diederik.test');
    $series = snrsSeries($this->user, 'snrs::xuser');

    $until = CarbonImmutable::parse('2026-06-17 12:00:00');
    expect(fn () => ($this->action)($series->id, $intruder, $until))
        ->toThrow(NotFoundHttpException::class);
});
