<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Modules\DriftAlerts\Public\Events\DriftAlertSnoozed;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/*
 * SnoozeDriftAlert — atomic write of `snoozed_until` + state transition
 * through the DriftAlertStateMachine. Idempotent when re-snoozed to
 * the same target timestamp. Cross-user 404. Audit row carries the
 * snooze target inside `notes`.
 */

function sdaUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function sdaTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'sda-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sda-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'sda-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'sda-'.bin2hex(random_bytes(8))),
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
        'description' => 'sda fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sdaAlert(User $user, string $state = 'open', array $overrides = []): DriftAlert
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
        'cluster_key' => 'sda::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => sdaTransaction($db, $user->id),
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
        'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = sdaUser('sda');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('snoozes an open alert and writes snoozed_until atomically', function (): void {
    $alert = sdaAlert($this->user, 'open');
    $until = CarbonImmutable::parse('2026-05-27 09:00:00');

    /** @var SnoozeDriftAlert $action */
    $action = $this->app->make(SnoozeDriftAlert::class);
    ($action)($alert->id, $this->user, $until);

    /** @var DriftAlert $fresh */
    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('snoozed');
    expect($fresh->snoozed_until?->toDateTimeString())->toBe('2026-05-27 09:00:00');

    $transitions = DriftAlertTransition::query()
        ->where('drift_alert_id', $alert->id)
        ->get();
    expect($transitions)->toHaveCount(1);
    expect($transitions[0]->to_state)->toBe('snoozed');
    expect($transitions[0]->transition_reason)->toBe('user_action');
    expect($transitions[0]->actor)->toBe('user');
    expect($transitions[0]->notes)->toContain('snoozed_until=2026-05-27');
});

it('dispatches DriftAlertSnoozed carrying the snoozedUntil timestamp', function (): void {
    Event::fake([DriftAlertSnoozed::class]);

    $alert = sdaAlert($this->user, 'open');
    $until = CarbonImmutable::parse('2026-05-27 09:00:00');

    /** @var SnoozeDriftAlert $action */
    $action = $this->app->make(SnoozeDriftAlert::class);
    ($action)($alert->id, $this->user, $until);

    Event::assertDispatched(DriftAlertSnoozed::class, function (DriftAlertSnoozed $e) use ($alert, $until): bool {
        return $e->driftAlertId === $alert->id
            && $e->userId === $this->user->id
            && $e->snoozedUntil->toDateTimeString() === $until->toDateTimeString();
    });
});

it('is idempotent when re-snoozed to the same target (no second transitions row)', function (): void {
    $until = CarbonImmutable::parse('2026-05-27 09:00:00');
    $alert = sdaAlert($this->user, 'snoozed', [
        'snoozed_until' => $until,
    ]);

    /** @var SnoozeDriftAlert $action */
    $action = $this->app->make(SnoozeDriftAlert::class);
    ($action)($alert->id, $this->user, $until);

    $count = DriftAlertTransition::query()
        ->where('drift_alert_id', $alert->id)
        ->count();
    expect($count)->toBe(0);
});

it('throws NotFoundHttpException for a cross-user alert id', function (): void {
    $intruder = sdaUser('sda-intruder');
    $alert = sdaAlert($this->user, 'open');
    $until = CarbonImmutable::parse('2026-05-27 09:00:00');

    /** @var SnoozeDriftAlert $action */
    $action = $this->app->make(SnoozeDriftAlert::class);
    expect(fn () => ($action)($alert->id, $intruder, $until))
        ->toThrow(NotFoundHttpException::class);

    /** @var DriftAlert $fresh */
    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('open');
});
