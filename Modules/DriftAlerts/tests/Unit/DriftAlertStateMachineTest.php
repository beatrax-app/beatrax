<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\StateMachine\InvalidStateTransitionException;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'drift-sm',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->seriesId = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Spotify',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1149,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'spotify|monthly|EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->occurrenceId = $this->db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $this->user->id,
        'recurring_series_id' => $this->seriesId,
        'transaction_id' => seedDriftAlertStateMachineTransaction($this->db, $this->user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    /** @var DriftAlertStateMachine $machine */
    $machine = $this->app->make(DriftAlertStateMachine::class);
    $this->machine = $machine;

    $this->alert = DriftAlert::factory()->create([
        'user_id' => $this->user->id,
        'recurring_series_id' => $this->seriesId,
        'state' => 'open',
        'direction' => 'expense',
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -1149,
        'currency' => 'EUR',
        'delta_minor' => -150,
        'annualized_impact_minor' => -1800,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => $this->occurrenceId,
        'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

dataset('allowed_transitions', [
    'open → acknowledged (user_action / user)' => ['open', 'acknowledged', 'user_action', 'user'],
    'open → snoozed (user_action / user)' => ['open', 'snoozed', 'user_action', 'user'],
    'open → dismissed_cancelled (user_dismissed_cancelled / user)' => ['open', 'dismissed_cancelled', 'user_dismissed_cancelled', 'user'],
    'snoozed → open (detector_revived_snooze / detector)' => ['snoozed', 'open', 'detector_revived_snooze', 'detector'],
    'snoozed → acknowledged (user_action / user)' => ['snoozed', 'acknowledged', 'user_action', 'user'],
    'snoozed → dismissed_cancelled (user_dismissed_cancelled / user)' => ['snoozed', 'dismissed_cancelled', 'user_dismissed_cancelled', 'user'],
]);

it('performs every legal transition in the ALLOWED_TRANSITIONS map and inserts exactly one audit row', function (string $from, string $to, string $reason, string $actor): void {
    $this->db->connection()->table('drift_alerts')
        ->where('id', $this->alert->id)
        ->update(['state' => $from]);

    $row = DriftAlert::query()->findOrFail($this->alert->id);
    $this->machine->transition($row, $to, $reason, $actor);

    $fresh = DriftAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe($to);

    $audits = DriftAlertTransition::query()
        ->where('drift_alert_id', $this->alert->id)
        ->get()
        ->all();

    expect($audits)->toHaveCount(1);
    expect($audits[0]->from_state)->toBe($from);
    expect($audits[0]->to_state)->toBe($to);
    expect($audits[0]->actor)->toBe($actor);
    expect($audits[0]->transition_reason)->toBe($reason);
})->with('allowed_transitions');

dataset('illegal_transitions', [
    'acknowledged → snoozed' => ['acknowledged', 'snoozed'],
    'acknowledged → open' => ['acknowledged', 'open'],
    'acknowledged → dismissed_cancelled' => ['acknowledged', 'dismissed_cancelled'],
    'dismissed_cancelled → open' => ['dismissed_cancelled', 'open'],
    'dismissed_cancelled → acknowledged' => ['dismissed_cancelled', 'acknowledged'],
]);

it('rejects every illegal transition, leaves the alert row state untouched, and writes no audit row', function (string $from, string $to): void {
    $this->db->connection()->table('drift_alerts')
        ->where('id', $this->alert->id)
        ->update(['state' => $from]);

    $row = DriftAlert::query()->findOrFail($this->alert->id);

    expect(fn () => $this->machine->transition($row, $to, 'user_action', 'user'))
        ->toThrow(InvalidStateTransitionException::class);

    $fresh = DriftAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe($from);

    $count = DriftAlertTransition::query()
        ->where('drift_alert_id', $this->alert->id)
        ->count();
    expect($count)->toBe(0);
})->with('illegal_transitions');

it('rejects a same-state transition (no entry exists in ALLOWED_TRANSITIONS)', function (): void {
    expect(fn () => $this->machine->transition($this->alert, 'open', 'user_action', 'user'))
        ->toThrow(InvalidStateTransitionException::class);
});

it('rejects an unknown actor', function (): void {
    expect(fn () => $this->machine->transition($this->alert, 'acknowledged', 'user_action', 'admin'))
        ->toThrow(InvalidArgumentException::class);

    $fresh = DriftAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe('open');
});

it('raises when the alert row is missing', function (): void {
    $ghost = DriftAlert::query()->findOrFail($this->alert->id);
    $ghost->delete();

    expect(fn () => $this->machine->transition($ghost, 'acknowledged', 'user_action', 'user'))
        ->toThrow(RuntimeException::class);
});

it('persists extraColumns alongside the snooze flip in the same transaction', function (): void {
    $this->machine->transition(
        $this->alert,
        'snoozed',
        'user_action',
        'user',
        notes: null,
        extraColumns: ['snoozed_until' => '2026-06-01 12:00:00'],
    );

    $fresh = DriftAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe('snoozed');
    expect($fresh->snoozed_until)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->snoozed_until?->format('Y-m-d H:i:s'))->toBe('2026-06-01 12:00:00');
});

it('records actor, reason, notes, and the Clock-stamped transitioned_at on the audit row', function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');

    $this->machine->transition(
        $this->alert,
        'acknowledged',
        'user_action',
        'user',
        notes: 'custom note',
    );

    $row = DriftAlertTransition::query()
        ->where('drift_alert_id', $this->alert->id)
        ->firstOrFail();

    expect($row->from_state)->toBe('open');
    expect($row->to_state)->toBe('acknowledged');
    expect($row->transition_reason)->toBe('user_action');
    expect($row->actor)->toBe('user');
    expect($row->notes)->toBe('custom note');
    expect($row->transitioned_at?->format('Y-m-d H:i:s'))->toBe('2026-05-20 09:00:00');
    expect($row->user_id)->toBe($this->user->id);
});

it('binds DriftAlertStateMachine as a singleton via DriftAlertsServiceProvider', function (): void {
    $first = $this->app->make(DriftAlertStateMachine::class);
    $second = $this->app->make(DriftAlertStateMachine::class);
    expect($first)->toBe($second);
});

it('writes user_id=NULL on the audit row when the source drift_alerts.user_id is NULL', function (): void {
    // A corrupted source row degrades the audit FK to null rather than failing
    // the transition. Locked here so a refactor cannot quietly make it throw.
    $this->db->connection()->table('drift_alerts')
        ->where('id', $this->alert->id)
        ->update(['user_id' => null]);

    $row = DriftAlert::query()->findOrFail($this->alert->id);
    $this->machine->transition($row, 'acknowledged', 'user_action', 'user');

    $transition = DriftAlertTransition::query()
        ->where('drift_alert_id', $this->alert->id)
        ->firstOrFail();
    expect($transition->user_id)->toBeNull();
});

function seedDriftAlertStateMachineTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'drift-sm-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB',
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/drift-sm.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_repeat('d', 64),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1149,
        'currency' => 'EUR',
        'settled_amount_minor' => -1149,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'description' => 'drift state-machine fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}
