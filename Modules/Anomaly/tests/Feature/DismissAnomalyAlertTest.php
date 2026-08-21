<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalyAlertTransition;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertDismissed;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function dsmUser(): User
{
    return User::query()->create([
        'username' => 'dsm-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function dsmTxn(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'dsm-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/dsm-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'dsm-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'dsm-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify', 'counterparty_name' => 'SPOTIFY', 'normalization_version' => 1,
        'description' => 'dsm fixture', 'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function dsmAlert(User $user, string $state = 'open', array $overrides = []): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return AnomalyAlert::factory()->create(array_merge([
        'user_id' => $user->id, 'transaction_id' => dsmTxn($db, (int) $user->id), 'state' => $state,
        'direction' => 'expense', 'reasons' => ['large'], 'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349, 'currency' => 'EUR', 'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = dsmUser();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('plain-dismisses an open alert with dismissed_as=dismissed and NO suppression rule', function (): void {
    Event::fake([AnomalyAlertDismissed::class]);
    $action = $this->app->make(DismissAnomalyAlert::class);
    $alert = dsmAlert($this->user, 'open');

    ($action)($alert->id, $this->user);

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('dismissed')
        ->and($fresh->dismissed_as)->toBe('dismissed')
        ->and($fresh->actioned_at)->not->toBeNull();

    $ruleCount = app(DatabaseManager::class)->connection()->table('anomaly_suppression_rules')
        ->where('user_id', $this->user->id)->count();
    expect($ruleCount)->toBe(0);

    Event::assertDispatched(
        AnomalyAlertDismissed::class,
        fn (AnomalyAlertDismissed $e): bool => $e->dismissedAs === 'dismissed',
    );
});

it('records the user_dismissed transition reason', function (): void {
    $action = $this->app->make(DismissAnomalyAlert::class);
    $alert = dsmAlert($this->user, 'open');

    ($action)($alert->id, $this->user);

    $transition = AnomalyAlertTransition::query()->where('anomaly_alert_id', $alert->id)->first();
    expect($transition->to_state)->toBe('dismissed')
        ->and($transition->transition_reason)->toBe('user_dismissed');
});

it('is idempotent on an already-dismissed alert', function (): void {
    Event::fake([AnomalyAlertDismissed::class]);
    $action = $this->app->make(DismissAnomalyAlert::class);
    $alert = dsmAlert($this->user, 'dismissed', ['dismissed_as' => 'dismissed']);

    ($action)($alert->id, $this->user);

    expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $alert->id)->count())->toBe(0);
    Event::assertNotDispatched(AnomalyAlertDismissed::class);
});
