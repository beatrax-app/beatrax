<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalyAlertTransition;
use Modules\Anomaly\Public\Actions\AcknowledgeAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlert;
use Modules\Anomaly\Public\Actions\SnoozeAnomalyAlert;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function anomXduUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function anomXduTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'anomxdu-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/anomxdu-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'anomxdu-run-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'anomxdu-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 00:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => -2349,
        'currency' => 'EUR',
        'settled_amount_minor' => -2349,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'description' => 'anomxdu fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function anomXduAlert(User $user, string $state = 'open'): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return AnomalyAlert::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => anomXduTransaction($db, (int) $user->id),
        'state' => $state,
        'direction' => 'expense',
        'reasons' => ['large'],
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->userA = anomXduUser('anomxdu-a');
    $this->userB = anomXduUser('anomxdu-b');
    $this->alertA = anomXduAlert($this->userA, 'open');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('AcknowledgeAnomalyAlert 404s on a cross-user alert id and leaves the row untouched', function (): void {
    /** @var AcknowledgeAnomalyAlert $action */
    $action = $this->app->make(AcknowledgeAnomalyAlert::class);

    expect(fn () => ($action)($this->alertA->id, $this->userB))
        ->toThrow(NotFoundHttpException::class);

    $fresh = AnomalyAlert::query()->findOrFail($this->alertA->id);
    expect($fresh->state)->toBe('open');

    expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $this->alertA->id)->count())->toBe(0);
});

it('SnoozeAnomalyAlert 404s on a cross-user alert id and leaves the row untouched', function (): void {
    /** @var SnoozeAnomalyAlert $action */
    $action = $this->app->make(SnoozeAnomalyAlert::class);

    expect(fn () => ($action)($this->alertA->id, $this->userB, CarbonImmutable::parse('2026-06-27 09:00:00')))
        ->toThrow(NotFoundHttpException::class);

    $fresh = AnomalyAlert::query()->findOrFail($this->alertA->id);
    expect($fresh->state)->toBe('open');
    expect($fresh->snoozed_until)->toBeNull();

    expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $this->alertA->id)->count())->toBe(0);
});

it('DismissAnomalyAlert 404s on a cross-user alert id and leaves the row untouched', function (): void {
    /** @var DismissAnomalyAlert $action */
    $action = $this->app->make(DismissAnomalyAlert::class);

    expect(fn () => ($action)($this->alertA->id, $this->userB))
        ->toThrow(NotFoundHttpException::class);

    $fresh = AnomalyAlert::query()->findOrFail($this->alertA->id);
    expect($fresh->state)->toBe('open');

    expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $this->alertA->id)->count())->toBe(0);
});
