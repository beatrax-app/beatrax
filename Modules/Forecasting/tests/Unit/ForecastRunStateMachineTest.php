<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Forecasting\Internal\Exceptions\InvalidForecastRunTransitionException;
use Modules\Forecasting\Internal\StateMachines\ForecastRunStateMachine;
use Modules\Forecasting\Models\ForecastRun;

uses(RefreshDatabase::class);

/*
 * Unit coverage for ForecastRunStateMachine — the sole legal mutator
 * of forecast_runs.status. Covers:
 *
 *   - Valid transitions: pending → running / failed, running → complete / failed.
 *   - Illegal transitions: every move out of a terminal state, plus
 *     same-state transitions (no entry in the map).
 *   - Transition-map snapshot (locks the shape so future edits are
 *     intentional).
 *   - Lifecycle timestamp stamps via the injected Clock contract.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'forecast-sm',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var ForecastRunStateMachine $machine */
    $machine = $this->app->make(ForecastRunStateMachine::class);
    $this->machine = $machine;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function frsmRun(int $userId, string $status): ForecastRun
{
    return ForecastRun::factory()->create([
        'user_id' => $userId,
        'horizon_days' => 30,
        'status' => $status,
        'started_at' => $status === 'pending' ? null : CarbonImmutable::parse('2026-05-19 12:00:00'),
        'completed_at' => null,
    ]);
}

it('locks the run-status transition graph', function (): void {
    expect(JobRunStatus::Pending->allowedNext())->toBe([JobRunStatus::Running, JobRunStatus::Failed]);
    expect(JobRunStatus::Running->allowedNext())->toBe([JobRunStatus::Complete, JobRunStatus::Failed]);
    expect(JobRunStatus::Complete->allowedNext())->toBe([]);
    expect(JobRunStatus::Failed->allowedNext())->toBe([]);
});

it('transitions pending → running via start() and stamps started_at from the Clock', function (): void {
    CarbonImmutable::setTestNow('2026-05-19 13:00:00');
    $run = frsmRun($this->user->id, 'pending');

    $this->machine->start($run);

    $fresh = ForecastRun::query()->findOrFail($run->id);
    expect($fresh->status)->toBe('running');
    expect($fresh->started_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->started_at?->format('Y-m-d H:i:s'))->toBe('2026-05-19 13:00:00');
    expect($fresh->completed_at)->toBeNull();
});

it('transitions running → complete via complete() and stamps completed_at', function (): void {
    CarbonImmutable::setTestNow('2026-05-19 14:00:00');
    $run = frsmRun($this->user->id, 'running');

    $this->machine->complete($run);

    $fresh = ForecastRun::query()->findOrFail($run->id);
    expect($fresh->status)->toBe('complete');
    expect($fresh->completed_at?->format('Y-m-d H:i:s'))->toBe('2026-05-19 14:00:00');
});

it('transitions running → failed via fail() and stamps completed_at', function (): void {
    CarbonImmutable::setTestNow('2026-05-19 15:00:00');
    $run = frsmRun($this->user->id, 'running');

    $this->machine->fail($run);

    $fresh = ForecastRun::query()->findOrFail($run->id);
    expect($fresh->status)->toBe('failed');
    expect($fresh->completed_at?->format('Y-m-d H:i:s'))->toBe('2026-05-19 15:00:00');
});

it('transitions pending → failed via fail() (job throws before start)', function (): void {
    CarbonImmutable::setTestNow('2026-05-19 16:00:00');
    $run = frsmRun($this->user->id, 'pending');

    $this->machine->fail($run);

    $fresh = ForecastRun::query()->findOrFail($run->id);
    expect($fresh->status)->toBe('failed');
    expect($fresh->completed_at?->format('Y-m-d H:i:s'))->toBe('2026-05-19 16:00:00');
});

dataset('illegal_transitions', [
    'complete → start' => ['complete', 'start'],
    'complete → complete' => ['complete', 'complete'],
    'complete → fail' => ['complete', 'fail'],
    'failed → start' => ['failed', 'start'],
    'failed → complete' => ['failed', 'complete'],
    'failed → fail' => ['failed', 'fail'],
    'running → start' => ['running', 'start'],
    'pending → complete' => ['pending', 'complete'],
]);

it('rejects every illegal transition and leaves the row untouched', function (string $from, string $method): void {
    $run = frsmRun($this->user->id, $from);

    expect(fn () => $this->machine->{$method}($run))
        ->toThrow(InvalidForecastRunTransitionException::class);

    $fresh = ForecastRun::query()->findOrFail($run->id);
    expect($fresh->status)->toBe($from);
})->with('illegal_transitions');

it('raises a RuntimeException when the run row has been deleted mid-flight', function (): void {
    $run = frsmRun($this->user->id, 'pending');
    $captured = ForecastRun::query()->findOrFail($run->id);
    $this->db->connection()->table('forecast_runs')->where('id', $run->id)->delete();

    expect(fn () => $this->machine->start($captured))
        ->toThrow(RuntimeException::class);
});

it('forbids facade imports inside the state machine + exception', function (): void {
    $smPath = base_path('Modules/Forecasting/Internal/StateMachines/ForecastRunStateMachine.php');
    $exPath = base_path('Modules/Forecasting/Internal/Exceptions/InvalidForecastRunTransitionException.php');

    foreach ([$smPath, $exPath] as $file) {
        $contents = (string) file_get_contents($file);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        expect($stripped)->not->toContain(
            'use Illuminate\\Support\\Facades\\',
            "{$file} must not import any facade.",
        );
    }
});
