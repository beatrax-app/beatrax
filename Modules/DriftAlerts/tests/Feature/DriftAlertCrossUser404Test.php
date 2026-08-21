<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function xduUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function xduTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'xdu-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/xdu-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'xdu-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'xdu-'.bin2hex(random_bytes(8))),
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
        'description' => 'xdu fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function xduAlert(User $user, string $state = 'open'): DriftAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Spotify',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1149,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'xdu::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => xduTransaction($db, $user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return DriftAlert::factory()->create([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'state' => $state,
        'direction' => 'expense',
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -1149,
        'currency' => 'EUR',
        'delta_minor' => -150,
        'annualized_impact_minor' => -1800,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => $occurrenceId,
        'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->userA = xduUser('xdu-a');
    $this->userB = xduUser('xdu-b');
    $this->alertA = xduAlert($this->userA, 'open');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('AcknowledgeDriftAlert 404s on a cross-user alert id and leaves the row untouched', function (): void {
    /** @var AcknowledgeDriftAlert $action */
    $action = $this->app->make(AcknowledgeDriftAlert::class);

    expect(fn () => ($action)($this->alertA->id, $this->userB))
        ->toThrow(NotFoundHttpException::class);

    /** @var DriftAlert $fresh */
    $fresh = DriftAlert::query()->findOrFail($this->alertA->id);
    expect($fresh->state)->toBe('open');

    $count = DriftAlertTransition::query()
        ->where('drift_alert_id', $this->alertA->id)
        ->count();
    expect($count)->toBe(0);
});

it('SnoozeDriftAlert 404s on a cross-user alert id and leaves the row untouched', function (): void {
    /** @var SnoozeDriftAlert $action */
    $action = $this->app->make(SnoozeDriftAlert::class);

    expect(fn () => ($action)($this->alertA->id, $this->userB, CarbonImmutable::parse('2026-05-27 09:00:00')))
        ->toThrow(NotFoundHttpException::class);

    /** @var DriftAlert $fresh */
    $fresh = DriftAlert::query()->findOrFail($this->alertA->id);
    expect($fresh->state)->toBe('open');
    expect($fresh->snoozed_until)->toBeNull();

    $count = DriftAlertTransition::query()
        ->where('drift_alert_id', $this->alertA->id)
        ->count();
    expect($count)->toBe(0);
});

it('DismissDriftAlertAsCancelled 404s on a cross-user alert id and leaves the row untouched', function (): void {
    /** @var DismissDriftAlertAsCancelled $action */
    $action = $this->app->make(DismissDriftAlertAsCancelled::class);

    expect(fn () => ($action)($this->alertA->id, $this->userB))
        ->toThrow(NotFoundHttpException::class);

    /** @var DriftAlert $fresh */
    $fresh = DriftAlert::query()->findOrFail($this->alertA->id);
    expect($fresh->state)->toBe('open');

    $count = DriftAlertTransition::query()
        ->where('drift_alert_id', $this->alertA->id)
        ->count();
    expect($count)->toBe(0);
});
