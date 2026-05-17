<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;
use Modules\Recurring\Public\Actions\EditRecurringSeriesName;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * EditRecurringSeriesName — metric-style write to display_name_override
 * with NO state transition. Passing null clears the override.
 * Cross-user 404. The next detector sweep refreshes detected_name but
 * never clobbers display_name_override.
 */

function ersUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function ersSeries(User $user, string $cluster): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'spotify',
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
    $this->user = ersUser('ers@diederik.test');
});

function ersAction(): EditRecurringSeriesName
{
    /** @var EditRecurringSeriesName $action */
    $action = app(EditRecurringSeriesName::class);

    return $action;
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('writes display_name_override without producing a transitions row', function (): void {
    $series = ersSeries($this->user, 'ers::set');

    (ersAction())($series->id, $this->user, 'Spotify (family plan)');

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->display_name_override)->toBe('Spotify (family plan)');
    expect($fresh->state)->toBe('pending');

    $count = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->count();
    expect($count)->toBe(0);
});

it('clears the display_name_override when passed null', function (): void {
    $series = ersSeries($this->user, 'ers::clear');
    $series->display_name_override = 'previous override';
    $series->save();

    (ersAction())($series->id, $this->user, null);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->display_name_override)->toBeNull();
});

it('throws NotFoundHttpException for a cross-user series id', function (): void {
    $intruder = ersUser('ers-intruder@diederik.test');
    $series = ersSeries($this->user, 'ers::xuser');

    expect(fn () => (ersAction())($series->id, $intruder, 'evil'))
        ->toThrow(NotFoundHttpException::class);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->display_name_override)->toBeNull();
});
