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

it('trims whitespace from the override and treats an all-whitespace value as a clear', function (): void {
    $series = ersSeries($this->user, 'ers::trim');
    $series->display_name_override = 'previous';
    $series->save();

    (ersAction())($series->id, $this->user, '   ');

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->display_name_override)->toBeNull();
})->group('trim-whitespace');

it('trims surrounding whitespace from a non-empty override', function (): void {
    $series = ersSeries($this->user, 'ers::trim-edge');

    (ersAction())($series->id, $this->user, '  Spotify Family  ');

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->display_name_override)->toBe('Spotify Family');
})->group('trim-whitespace-edges');

it('throws InvalidArgumentException for a name exceeding the 120-character cap', function (): void {
    $series = ersSeries($this->user, 'ers::cap');
    $tooLong = str_repeat('x', 121);

    expect(fn () => (ersAction())($series->id, $this->user, $tooLong))
        ->toThrow(InvalidArgumentException::class);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->display_name_override)->toBeNull();
})->group('display-name-length-cap');

it('accepts an override at exactly the 120-character cap', function (): void {
    $series = ersSeries($this->user, 'ers::cap-edge');
    $atCap = str_repeat('y', 120);

    (ersAction())($series->id, $this->user, $atCap);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->display_name_override)->toBe($atCap);
})->group('display-name-length-cap-edge');
