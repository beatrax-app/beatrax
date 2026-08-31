<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\StateMachine\InvalidStateTransitionException;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Internal\StateMachines\SeriesRowVanishedException;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;

uses(RefreshDatabase::class);

// RecurringSeriesStateMachine is the sole legal mutator of recurring_series.state;
// the schema-level trigger below is what catches an out-of-band UPDATE that
// bypasses it.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'rsm',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Netflix',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'netflix|monthly|EUR',
    ]);

    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);
    $this->machine = $machine;
});

it('transitions pending → approved and inserts exactly one audit row', function (): void {
    $this->machine->transition($this->series, 'approved', 'user_action', 'user');

    $fresh = RecurringSeries::query()->findOrFail($this->series->id);
    expect($fresh->state)->toBe('approved');

    /** @var list<RecurringSeriesTransition> $rows */
    $rows = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $this->series->id)
        ->get()
        ->all();

    expect($rows)->toHaveCount(1);
    expect($rows[0]->from_state)->toBe('pending');
    expect($rows[0]->to_state)->toBe('approved');
    expect($rows[0]->actor)->toBe('user');
    expect($rows[0]->transition_reason)->toBe('user_action');
    expect($rows[0]->notes)->toBeNull();
    expect((int) $rows[0]->user_id)->toBe($this->user->id);
});

it('records optional notes when provided and null otherwise', function (): void {
    $this->machine->transition($this->series, 'approved', 'user_action', 'user', 'looks right');

    $row = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $this->series->id)
        ->first();

    expect($row?->notes)->toBe('looks right');
});

it('rejects an illegal transition and leaves both the row and audit table untouched', function (): void {
    expect(fn () => $this->machine->transition($this->series, 'cadence_changed', 'detector_cadence_flip', 'detector'))
        ->toThrow(InvalidStateTransitionException::class);

    $fresh = RecurringSeries::query()->findOrFail($this->series->id);
    expect($fresh->state)->toBe('pending');

    $count = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $this->series->id)
        ->count();
    expect($count)->toBe(0);
});

it('rejects a same-state transition (no entry exists in ALLOWED_TRANSITIONS)', function (): void {
    $this->machine->transition($this->series, 'approved', 'user_action', 'user');

    $approved = RecurringSeries::query()->findOrFail($this->series->id);

    expect(fn () => $this->machine->transition($approved, 'approved', 'user_action', 'user'))
        ->toThrow(InvalidStateTransitionException::class);
});

it('rejects an unknown actor', function (): void {
    expect(fn () => $this->machine->transition($this->series, 'approved', 'user_action', 'robot'))
        ->toThrow(InvalidArgumentException::class);

    $fresh = RecurringSeries::query()->findOrFail($this->series->id);
    expect($fresh->state)->toBe('pending');
});

it('raises when the series row is missing', function (): void {
    $ghost = RecurringSeries::query()->findOrFail($this->series->id);
    $ghost->delete();

    // The dedicated type is the point: a caller can tell a row that vanished
    // mid-flight from a transition the table forbids, which the shared
    // RuntimeException parent made indistinguishable.
    expect(fn () => $this->machine->transition($ghost, 'approved', 'user_action', 'user'))
        ->toThrow(SeriesRowVanishedException::class);
});

dataset('allowed_transitions', [
    'pending → approved' => ['pending', 'approved'],
    'pending → rejected' => ['pending', 'rejected'],
    'pending → snoozed' => ['pending', 'snoozed'],
    'approved → cadence_changed' => ['approved', 'cadence_changed'],
    'approved → rejected' => ['approved', 'rejected'],
    'cadence_changed → approved' => ['cadence_changed', 'approved'],
    'cadence_changed → rejected' => ['cadence_changed', 'rejected'],
    'snoozed → pending' => ['snoozed', 'pending'],
    'snoozed → approved' => ['snoozed', 'approved'],
    'snoozed → rejected' => ['snoozed', 'rejected'],
    'rejected → pending' => ['rejected', 'pending'],
]);

it('performs every legal transition in the ALLOWED_TRANSITIONS map', function (string $from, string $to): void {
    /** @var DatabaseManager $db */
    $db = $this->db;
    $db->connection()
        ->table('recurring_series')
        ->where('id', $this->series->id)
        ->update(['state' => $from]);

    $row = RecurringSeries::query()->findOrFail($this->series->id);
    $this->machine->transition($row, $to, 'user_action', 'user');

    $fresh = RecurringSeries::query()->findOrFail($this->series->id);
    expect($fresh->state)->toBe($to);
})->with('allowed_transitions');

it('refuses a direct Eloquent update that bypasses the state machine — schema trigger fires', function (): void {
    expect(
        fn () => RecurringSeries::query()
            ->where('id', $this->series->id)
            ->update(['state' => 'garbage']),
    )->toThrow(QueryException::class);

    $fresh = RecurringSeries::query()->findOrFail($this->series->id);
    expect($fresh->state)->toBe('pending');
});

it('resolves RecurringSeriesStateMachine from the container', function (): void {
    // Resolvable, not identical: a stateless service that dispatches events is
    // deliberately no longer a singleton, because Event::fake() cannot reach a
    // dispatcher one has already captured. ASingletonNeverCapturesTheDispatcher
    // holds that line; this holds the wiring.
    expect($this->app->make(RecurringSeriesStateMachine::class))->toBeInstanceOf(RecurringSeriesStateMachine::class);
});
