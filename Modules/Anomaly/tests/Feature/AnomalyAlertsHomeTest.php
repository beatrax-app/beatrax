<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalySuppressionRule;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;

uses(RefreshDatabase::class);

// The page under test is owned by DriftAlerts; it consumes the Anomaly
// Public query + actions.
function aahUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/** @return array{0: int, 1: int} [transactionId, counterpartyId] */
function aahTxn(DatabaseManager $db, int $userId, string $merchantSlug): array
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN test', 'slug' => 'aah-asn-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/aah-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'aah-run-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed', 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => $merchantSlug.'-'.$suffix,
        'display_name' => ucfirst($merchantSlug),
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'aah-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_id' => $counterpartyId, 'counterparty_normalized' => $merchantSlug,
        'counterparty_name' => strtoupper($merchantSlug), 'normalization_version' => 1,
        'description' => 'aah fixture', 'type' => 'expense', 'source_format' => 'asn-csv',
        'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$txnId, $counterpartyId];
}

/** @param array<string, mixed> $overrides */
function aahAlert(User $user, string $state, string $merchantSlug = 'spotify', array $overrides = []): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$txnId] = aahTxn($db, (int) $user->id, $merchantSlug);

    return AnomalyAlert::factory()->create(array_merge([
        'user_id' => $user->id,
        'transaction_id' => $txnId,
        'state' => $state,
        'direction' => 'expense',
        'reasons' => ['large'],
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = aahUser('aah-a');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders seeded anomaly rows with reason chips on the Open tab under ?type=anomaly', function (): void {
    aahAlert($this->user, 'open', 'spotify', ['reasons' => ['large', 'first_time']]);

    $response = $this->actingAs($this->user)->get('/drift?type=anomaly');

    $response->assertOk()
        ->assertSeeText('Unusual charges')
        ->assertSeeText('Spotify')
        ->assertSeeText('Large charge')
        ->assertSeeText('First time')
        ->assertSeeText('Acknowledge');
});

it('defaults the type to drift so the plain /drift still renders drift rows', function (): void {
    aahAlert($this->user, 'open', 'spotify');

    $this->actingAs($this->user)
        ->get('/drift')
        ->assertOk()
        ->assertSeeText('No open drift alerts')
        ->assertDontSeeText('Large charge');
});

it('shows acknowledged anomalies under History and dismissed under Dismissed', function (): void {
    aahAlert($this->user, 'acknowledged', 'netflix');
    aahAlert($this->user, 'dismissed', 'hbo', ['dismissed_as' => 'expected']);

    $this->actingAs($this->user)
        ->get('/drift?type=anomaly&tab=history')
        ->assertOk()
        ->assertSeeText('Netflix')
        ->assertDontSeeText('Hbo');

    $this->actingAs($this->user)
        ->get('/drift?type=anomaly&tab=dismissed')
        ->assertOk()
        ->assertSeeText('Hbo')
        ->assertDontSeeText('Netflix');
});

it('acknowledges an anomaly row and dispatches a toast', function (): void {
    $alert = aahAlert($this->user, 'open');

    Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => 'anomaly'])
        ->call('acknowledgeAnomaly', $alert->id)
        ->assertDispatched('toast');

    expect(AnomalyAlert::query()->findOrFail($alert->id)->state)->toBe('acknowledged');
});

it('snoozes an anomaly row to the 1-week target and writes snoozed_until', function (): void {
    $alert = aahAlert($this->user, 'open');

    $until = CarbonImmutable::parse('2026-06-20 09:00:00')->addWeek();

    Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => 'anomaly'])
        ->call('snoozeAnomaly', $alert->id, $until->toIso8601String())
        ->assertDispatched('toast');

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('snoozed')
        ->and($fresh->snoozed_until?->toDateTimeString())->toBe($until->toDateTimeString());
});

it('dismisses an anomaly row without creating a suppression rule', function (): void {
    $alert = aahAlert($this->user, 'open');

    Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => 'anomaly'])
        ->call('dismissAnomaly', $alert->id)
        ->assertDispatched('toast');

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('dismissed')
        ->and($fresh->dismissed_as)->toBe('dismissed')
        ->and(AnomalySuppressionRule::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('marks an anomaly as expected — dismisses it and creates a suppression rule', function (): void {
    $alert = aahAlert($this->user, 'open');

    Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => 'anomaly'])
        ->call('markAnomalyExpected', $alert->id)
        ->assertDispatched('toast');

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('dismissed')
        ->and($fresh->dismissed_as)->toBe('expected')
        ->and(
            AnomalySuppressionRule::query()
                ->where('user_id', $this->user->id)
                ->where('source_anomaly_alert_id', $alert->id)
                ->count()
        )->toBe(1);
});

it('undoes a suppression — re-opens the anomaly and deletes the rule', function (): void {
    $alert = aahAlert($this->user, 'open');

    $component = Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => 'anomaly'])
        ->call('markAnomalyExpected', $alert->id);

    expect(AnomalySuppressionRule::query()->where('source_anomaly_alert_id', $alert->id)->count())->toBe(1);

    $component->call('undoAnomalySuppression', $alert->id)->assertDispatched('toast');

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('open')
        ->and(AnomalySuppressionRule::query()->where('source_anomaly_alert_id', $alert->id)->count())->toBe(0);
});
