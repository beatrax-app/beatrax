<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Goals\Public\Exceptions\InvalidGoalTargetDateException;
use Modules\Goals\Public\Services\GoalProjectionService;
use Modules\Goals\Public\Services\GoalWriter;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    $this->user = User::query()->create([
        'username' => 'goal-horizon-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function horizonGoal(User $user, int $targetMinor): Goal
{
    return Goal::query()->create([
        'user_id' => $user->id,
        'name' => 'Horizon',
        'start_date' => '2026-01-01',
        'target_minor' => $targetMinor,
        'target_currency' => 'EUR',
        'target_date' => '2027-01-01',
        'status' => GoalStatus::Active->value,
    ]);
}

it('never answers a finish date earlier than today, however small the rate', function (): void {
    // A rate of a cent or two a day against a large target divides out past
    // PHP_INT_MAX. The int cast then wraps and addDays() walks backwards, so
    // the card printed a finish date twenty years in the past.
    $goal = horizonGoal($this->user, PHP_INT_MAX);

    $attributed = [
        ['amountMinor' => 1, 'currency' => 'EUR', 'postedAt' => '2026-06-10'],
    ];

    $projection = app(GoalProjectionService::class)
        ->project($goal, 0, $this->user, null, $attributed, []);

    expect($projection['stalled'])->toBeFalse();
    expect($projection['beyondHorizon'])->toBeTrue();
    expect($projection['date'])->not->toBeNull();
    expect($projection['date'])->toBeGreaterThan(CarbonImmutable::today()->toDateString());
});

it('refuses a target date that is not a calendar date', function (): void {
    app(GoalWriter::class)->save($this->user, 'Holiday', '1000,00', '2026-02-30');
})->throws(InvalidGoalTargetDateException::class);

it('refuses a target date before the goal starts', function (): void {
    app(GoalWriter::class)->save($this->user, 'Holiday', '1000,00', '2020-01-01');
})->throws(InvalidGoalTargetDateException::class);

it('stores a real target date verbatim', function (): void {
    $goal = app(GoalWriter::class)->save($this->user, 'Holiday', '1000,00', '2026-12-24');

    expect($goal->target_date->toDateString())->toBe('2026-12-24');
});
