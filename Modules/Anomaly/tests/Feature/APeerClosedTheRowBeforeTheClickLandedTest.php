<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Actions\AcknowledgeAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Public\Actions\SnoozeAnomalyAlert;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;

function peerUser(): User
{
    return User::query()->create([
        'username' => 'peer-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function peerTxn(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'peer-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/peer-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'peer-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'peer-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify', 'counterparty_name' => 'SPOTIFY', 'normalization_version' => 1,
        'description' => 'peer fixture', 'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
}

// The other device acknowledged it and the op log replayed the new state onto
// this one, so the row on screen is terminal while the buttons are still live.
function peerClosedAlert(User $user): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return AnomalyAlert::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => peerTxn($db, (int) $user->id),
        'state' => 'acknowledged',
        'direction' => 'expense',
        'reasons' => ['large'],
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
        'actioned_at' => CarbonImmutable::parse('2026-06-16 12:00:00'),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = peerUser();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('treats dismissing a peer-closed alert as a no-op rather than a 500', function (): void {
    $alert = peerClosedAlert($this->user);

    /** @var DismissAnomalyAlert $action */
    $action = app(DismissAnomalyAlert::class);
    ($action)($alert->id, $this->user);

    expect(AnomalyAlert::query()->findOrFail($alert->id)->state)->toBe('acknowledged');
});

it('treats marking a peer-closed alert as expected as a no-op, and writes no mute', function (): void {
    $alert = peerClosedAlert($this->user);

    /** @var DismissAnomalyAlertAsExpected $action */
    $action = app(DismissAnomalyAlertAsExpected::class);
    expect(($action)($alert->id, $this->user))->toBeFalse();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('anomaly_suppression_rules')->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(AnomalyAlert::query()->findOrFail($alert->id)->state)->toBe('acknowledged');
});

it('treats snoozing a peer-closed alert as a no-op', function (): void {
    $alert = peerClosedAlert($this->user);

    /** @var SnoozeAnomalyAlert $action */
    $action = app(SnoozeAnomalyAlert::class);
    ($action)($alert->id, $this->user, CarbonImmutable::parse('2026-06-27 09:00:00'));

    /** @var AnomalyAlert $fresh */
    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('acknowledged')
        ->and($fresh->snoozed_until)->toBeNull();
});

it('treats acknowledging a peer-dismissed alert as a no-op', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $alert = AnomalyAlert::factory()->create([
        'user_id' => $this->user->id,
        'transaction_id' => peerTxn($db, (int) $this->user->id),
        'state' => 'dismissed',
        'direction' => 'expense',
        'reasons' => ['large'],
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ]);

    /** @var AcknowledgeAnomalyAlert $action */
    $action = app(AcknowledgeAnomalyAlert::class);
    ($action)($alert->id, $this->user);

    expect(AnomalyAlert::query()->findOrFail($alert->id)->state)->toBe('dismissed');
});

it('does not 500 the screen the row is still drawn on', function (): void {
    $alert = peerClosedAlert($this->user);

    Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => 'anomaly'])
        ->call('dismissAnomaly', (string) $alert->id)
        ->assertOk()
        ->call('snoozeAnomaly', (string) $alert->id, '2026-06-27T09:00:00+00:00')
        ->assertOk()
        ->call('markAnomalyExpected', (string) $alert->id)
        ->assertOk()
        ->call('acknowledgeAnomaly', (string) $alert->id)
        ->assertOk();
});
