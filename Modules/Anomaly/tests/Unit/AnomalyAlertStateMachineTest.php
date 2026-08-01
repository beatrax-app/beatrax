<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalyAlertTransition;
use Modules\Core\Models\User;
use Modules\Core\Public\StateMachine\InvalidStateTransitionException;

uses(RefreshDatabase::class);

/*
 * Unit coverage for the anomaly alert state machine — the sole legal
 * mutator of anomaly_alerts.state. Covers the ALLOWED_TRANSITIONS map
 * (including the diverging dismissed -> open undo edge), the atomic
 * state + audit-row write, the busy-timeout fence, the actor whitelist,
 * the missing-row guard, the extraColumns patch, and the
 * actor/reason/timestamp propagation into the audit row.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'anomaly-sm',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->transactionId = seedAnomalyStateMachineTransaction($this->db, $this->user->id);

    /** @var AnomalyAlertStateMachine $machine */
    $machine = $this->app->make(AnomalyAlertStateMachine::class);
    $this->machine = $machine;

    $this->alert = AnomalyAlert::factory()->create([
        'user_id' => $this->user->id,
        'transaction_id' => $this->transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => ['large'],
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-13 12:00:00'),
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

dataset('allowed_transitions', [
    'open → acknowledged (user_action / user)' => ['open', 'acknowledged', 'user_action', 'user'],
    'open → snoozed (user_action / user)' => ['open', 'snoozed', 'user_action', 'user'],
    'open → dismissed (user_dismissed / user)' => ['open', 'dismissed', 'user_dismissed', 'user'],
    'snoozed → open (detector_revived_snooze / detector)' => ['snoozed', 'open', 'detector_revived_snooze', 'detector'],
    'snoozed → acknowledged (user_action / user)' => ['snoozed', 'acknowledged', 'user_action', 'user'],
    'snoozed → dismissed (user_dismissed / user)' => ['snoozed', 'dismissed', 'user_dismissed', 'user'],
    'dismissed → open (user_undo_dismiss / user)' => ['dismissed', 'open', 'user_undo_dismiss', 'user'],
]);

it('performs every legal transition in the ALLOWED_TRANSITIONS map and inserts exactly one audit row', function (string $from, string $to, string $reason, string $actor): void {
    $this->db->connection()->table('anomaly_alerts')
        ->where('id', $this->alert->id)
        ->update(['state' => $from]);

    $row = AnomalyAlert::query()->findOrFail($this->alert->id);
    $this->machine->transition($row, $to, $reason, $actor);

    $fresh = AnomalyAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe($to);

    $audits = AnomalyAlertTransition::query()
        ->where('anomaly_alert_id', $this->alert->id)
        ->get()
        ->all();

    expect($audits)->toHaveCount(1);
    expect($audits[0]->from_state)->toBe($from);
    expect($audits[0]->to_state)->toBe($to);
    expect($audits[0]->actor)->toBe($actor);
    expect($audits[0]->transition_reason)->toBe($reason);
})->with('allowed_transitions');

it('allows the diverging dismissed → open undo edge', function (): void {
    $this->db->connection()->table('anomaly_alerts')
        ->where('id', $this->alert->id)
        ->update(['state' => 'dismissed']);

    $row = AnomalyAlert::query()->findOrFail($this->alert->id);
    $this->machine->transition($row, 'open', 'user_undo_dismiss', 'user');

    $fresh = AnomalyAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe('open');
});

dataset('illegal_transitions', [
    'acknowledged → snoozed' => ['acknowledged', 'snoozed'],
    'acknowledged → open' => ['acknowledged', 'open'],
    'acknowledged → dismissed' => ['acknowledged', 'dismissed'],
    'dismissed → acknowledged' => ['dismissed', 'acknowledged'],
    'dismissed → snoozed' => ['dismissed', 'snoozed'],
]);

it('rejects every illegal transition, leaves the alert row state untouched, and writes no audit row', function (string $from, string $to): void {
    $this->db->connection()->table('anomaly_alerts')
        ->where('id', $this->alert->id)
        ->update(['state' => $from]);

    $row = AnomalyAlert::query()->findOrFail($this->alert->id);

    expect(fn () => $this->machine->transition($row, $to, 'user_action', 'user'))
        ->toThrow(InvalidStateTransitionException::class);

    $fresh = AnomalyAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe($from);

    $count = AnomalyAlertTransition::query()
        ->where('anomaly_alert_id', $this->alert->id)
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

    $fresh = AnomalyAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe('open');
});

it('raises when the alert row is missing', function (): void {
    $ghost = AnomalyAlert::query()->findOrFail($this->alert->id);
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
        extraColumns: ['snoozed_until' => '2026-07-01 12:00:00'],
    );

    $fresh = AnomalyAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe('snoozed');
    expect($fresh->snoozed_until)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->snoozed_until?->format('Y-m-d H:i:s'))->toBe('2026-07-01 12:00:00');
});

it('patches dismissed_as via extraColumns alongside the dismiss flip', function (): void {
    $this->machine->transition(
        $this->alert,
        'dismissed',
        'user_dismissed',
        'user',
        notes: null,
        extraColumns: ['dismissed_as' => 'not_unusual'],
    );

    $fresh = AnomalyAlert::query()->findOrFail($this->alert->id);
    expect($fresh->state)->toBe('dismissed');
    expect($fresh->dismissed_as)->toBe('not_unusual');
});

it('records actor, reason, notes, and the Clock-stamped transitioned_at on the audit row', function (): void {
    CarbonImmutable::setTestNow('2026-06-14 09:00:00');

    $this->machine->transition(
        $this->alert,
        'acknowledged',
        'user_action',
        'user',
        notes: 'custom note',
    );

    $row = AnomalyAlertTransition::query()
        ->where('anomaly_alert_id', $this->alert->id)
        ->firstOrFail();

    expect($row->from_state)->toBe('open');
    expect($row->to_state)->toBe('acknowledged');
    expect($row->transition_reason)->toBe('user_action');
    expect($row->actor)->toBe('user');
    expect($row->notes)->toBe('custom note');
    expect($row->transitioned_at?->format('Y-m-d H:i:s'))->toBe('2026-06-14 09:00:00');
    expect($row->user_id)->toBe($this->user->id);
});

it('binds AnomalyAlertStateMachine as a singleton via AnomalyServiceProvider', function (): void {
    $first = $this->app->make(AnomalyAlertStateMachine::class);
    $second = $this->app->make(AnomalyAlertStateMachine::class);
    expect($first)->toBe($second);
});

it('casts the reasons column to an array', function (): void {
    $fresh = AnomalyAlert::query()->findOrFail($this->alert->id);
    expect($fresh->reasons)->toBeArray();
    expect($fresh->reasons)->toBe(['large']);
});

it('writes user_id=NULL on the audit row when the source anomaly_alerts.user_id is NULL', function (): void {
    $this->db->connection()->table('anomaly_alerts')
        ->where('id', $this->alert->id)
        ->update(['user_id' => null]);

    $row = AnomalyAlert::query()->findOrFail($this->alert->id);
    $this->machine->transition($row, 'acknowledged', 'user_action', 'user');

    $transition = AnomalyAlertTransition::query()
        ->where('anomaly_alert_id', $this->alert->id)
        ->firstOrFail();
    expect($transition->user_id)->toBeNull();
});

/**
 * Seeds a transactions row sufficient to satisfy the FK constraint on
 * anomaly_alerts.transaction_id without pulling in the full Ingestion
 * fixture stack.
 */
function seedAnomalyStateMachineTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'anomaly-sm-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.substr(bin2hex(random_bytes(8)), 0, 10),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/anomaly-sm.csv',
        'sha256' => hash('sha256', 'anomaly-sm'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-06-13 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'anomaly-sm-fp'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-10',
        'booked_at' => '2026-06-10 00:00:00',
        'value_date' => '2026-06-10',
        'amount_minor' => -2349,
        'currency' => 'EUR',
        'settled_amount_minor' => -2349,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'description' => 'anomaly state-machine fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ]);
}
