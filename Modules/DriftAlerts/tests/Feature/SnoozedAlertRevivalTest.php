<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Internal\Jobs\RevivedExpiredDriftSnoozesJob;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;

uses(RefreshDatabase::class);

/*
 * Hybrid snooze-revival tests: the hourly RevivedExpiredDriftSnoozesJob
 * flips state and writes the audit transition; the query-time
 * conditional on DriftAlertQuery::openForUser surfaces snoozed-but-
 * expired rows immediately between sweeps.
 */

function sarUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function sarTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'SAR ASN',
        'slug' => 'sar-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00SAR'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sar-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'sar-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'sar-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1149,
        'currency' => 'EUR',
        'settled_amount_minor' => -1149,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'sar-merchant',
        'counterparty_name' => 'SAR MERCHANT',
        'normalization_version' => 1,
        'description' => 'sar fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

/**
 * @param  array<string, mixed>  $alertOverrides
 */
function sarAlert(User $user, string $state = 'open', ?CarbonImmutable $snoozedUntil = null, array $alertOverrides = []): DriftAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'SAR series',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1149,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1149,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'sar::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => sarTransaction($db, $user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return DriftAlert::factory()->create(array_merge([
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
        'snoozed_until' => $snoozedUntil,
        'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ], $alertOverrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('flips snoozed -> open when snoozed_until is in the past and writes the audit transition', function (): void {
    $user = sarUser('sar-flip');
    $alert = sarAlert($user, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-20 08:00:00'));

    /** @var RevivedExpiredDriftSnoozesJob $job */
    $job = app(RevivedExpiredDriftSnoozesJob::class);

    $job->handle(
        app(DatabaseManager::class),
        app(DriftAlertStateMachine::class),
        app(Clock::class),
    );

    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('open');
    expect($fresh->snoozed_until)->toBeNull();

    $transition = DriftAlertTransition::query()
        ->where('drift_alert_id', $alert->id)
        ->orderByDesc('id')
        ->first();
    expect($transition)->not->toBeNull();
    expect($transition?->from_state)->toBe('snoozed');
    expect($transition?->to_state)->toBe('open');
    expect($transition?->transition_reason)->toBe('detector_revived_snooze');
    expect($transition?->actor)->toBe('detector');
});

it('does NOT flip snoozed alerts whose snoozed_until is in the future', function (): void {
    $user = sarUser('sar-future');
    $alert = sarAlert($user, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-21 12:00:00'));

    /** @var RevivedExpiredDriftSnoozesJob $job */
    $job = app(RevivedExpiredDriftSnoozesJob::class);

    $job->handle(
        app(DatabaseManager::class),
        app(DriftAlertStateMachine::class),
        app(Clock::class),
    );

    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('snoozed');

    $transitionCount = DriftAlertTransition::query()
        ->where('drift_alert_id', $alert->id)
        ->count();
    expect($transitionCount)->toBe(0);
});

it('is a no-op on already-open alerts', function (): void {
    $user = sarUser('sar-open');
    $alert = sarAlert($user, state: 'open');

    /** @var RevivedExpiredDriftSnoozesJob $job */
    $job = app(RevivedExpiredDriftSnoozesJob::class);

    $job->handle(
        app(DatabaseManager::class),
        app(DriftAlertStateMachine::class),
        app(Clock::class),
    );

    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('open');
    expect(DriftAlertTransition::query()->where('drift_alert_id', $alert->id)->count())->toBe(0);
});

it('DriftAlertQuery::openForUser query-time conditional returns snoozed-but-expired rows even without the sweep', function (): void {
    $user = sarUser('sar-querytime');
    $alert = sarAlert($user, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-20 08:00:00'));

    /** @var DriftAlertQuery $query */
    $query = app(DriftAlertQuery::class);

    $rows = $query->openForUser($user);
    $ids = array_map(static fn ($r) => $r->driftAlertId, $rows);

    expect($ids)->toContain($alert->id);
});

it('DriftAlertQuery::openCountForUser counts open + snoozed-but-expired', function (): void {
    $user = sarUser('sar-count');
    sarAlert($user, state: 'open');
    sarAlert($user, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-20 08:00:00'));

    /** @var DriftAlertQuery $query */
    $query = app(DriftAlertQuery::class);

    expect($query->openCountForUser($user))->toBe(2);
});

it('DriftAlertQuery::openCountForUser excludes snoozed alerts whose snoozed_until is still in the future', function (): void {
    $user = sarUser('sar-future-count');
    sarAlert($user, state: 'open');
    sarAlert($user, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-21 12:00:00'));

    /** @var DriftAlertQuery $query */
    $query = app(DriftAlertQuery::class);

    expect($query->openCountForUser($user))->toBe(1);
});

it('exercises the snooze-expiry-revival corpus fixture: detected then snoozed in the past, revival flips to open', function (): void {
    $fixture = require base_path('Modules/DriftAlerts/tests/fixtures/drift-corpus/snooze-expiry-revival.php');
    /** @var array{transactions: array<int, array<string, mixed>>, expected: array<string, mixed>} $fixture */
    expect($fixture['expected']['transitions'][0]['transition_reason'] ?? null)->toBe('detector_revived_snooze');

    $user = sarUser('sar-corpus');
    // Manually seed the post-detect / mid-snooze state described by the
    // fixture: an alert previously in 'snoozed' state whose
    // snoozed_until lies in the past. The fixture's expected.alerts
    // declares the post-revival shape (state='open', baseline=-999,
    // latest=-1149); the revival sweep transitions snoozed -> open.
    $alert = sarAlert($user, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-20 08:00:00'), alertOverrides: [
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -1149,
        'delta_minor' => -150,
        'annualized_impact_minor' => -1800,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
    ]);

    /** @var RevivedExpiredDriftSnoozesJob $job */
    $job = app(RevivedExpiredDriftSnoozesJob::class);
    $job->handle(
        app(DatabaseManager::class),
        app(DriftAlertStateMachine::class),
        app(Clock::class),
    );

    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('open');
    expect($fresh->baseline_amount_minor)->toBe(-999);
    expect($fresh->latest_amount_minor)->toBe(-1149);

    $transition = DriftAlertTransition::query()
        ->where('drift_alert_id', $alert->id)
        ->orderByDesc('id')
        ->first();
    expect($transition?->transition_reason)->toBe('detector_revived_snooze');
    expect($transition?->actor)->toBe('detector');
});
