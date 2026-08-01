<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalyAlertTransition;
use Modules\Anomaly\Public\Actions\AcknowledgeAnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertAcknowledged;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function ackUser(): User
{
    return User::query()->create([
        'username' => 'ack-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function ackTxn(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'ack-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/ack-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'ack-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'ack-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify', 'counterparty_name' => 'SPOTIFY', 'normalization_version' => 1,
        'description' => 'ack fixture', 'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function ackAlert(User $user, string $state = 'open', array $overrides = []): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return AnomalyAlert::factory()->create(array_merge([
        'user_id' => $user->id, 'transaction_id' => ackTxn($db, (int) $user->id), 'state' => $state,
        'direction' => 'expense', 'reasons' => ['large'], 'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349, 'currency' => 'EUR', 'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = ackUser();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('transitions an open alert to acknowledged with an audit row, actioned_at, and the event', function (): void {
    Event::fake([AnomalyAlertAcknowledged::class]);
    $action = $this->app->make(AcknowledgeAnomalyAlert::class);
    $alert = ackAlert($this->user, 'open');

    ($action)($alert->id, $this->user);

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('acknowledged')
        ->and($fresh->actioned_at)->not->toBeNull();

    $transition = AnomalyAlertTransition::query()->where('anomaly_alert_id', $alert->id)->first();
    expect($transition)->not->toBeNull()
        ->and($transition->to_state)->toBe('acknowledged')
        ->and($transition->transition_reason)->toBe('user_action')
        ->and($transition->actor)->toBe('user');

    Event::assertDispatched(AnomalyAlertAcknowledged::class);
});

it('acknowledges a snoozed alert', function (): void {
    $action = $this->app->make(AcknowledgeAnomalyAlert::class);
    $alert = ackAlert($this->user, 'snoozed', ['snoozed_until' => CarbonImmutable::parse('2026-06-25 09:00:00')]);

    ($action)($alert->id, $this->user);

    expect(AnomalyAlert::query()->findOrFail($alert->id)->state)->toBe('acknowledged');
});

it('is idempotent on an already-acknowledged alert (no second transition, no event)', function (): void {
    Event::fake([AnomalyAlertAcknowledged::class]);
    $action = $this->app->make(AcknowledgeAnomalyAlert::class);
    $alert = ackAlert($this->user, 'acknowledged');

    ($action)($alert->id, $this->user);

    expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $alert->id)->count())->toBe(0);
    Event::assertNotDispatched(AnomalyAlertAcknowledged::class);
});
