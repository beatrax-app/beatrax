<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Modules\Recurring\Models\RecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function ddacUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function ddacTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'ddac-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ddac-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'ddac-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'ddac-'.bin2hex(random_bytes(8))),
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
        'description' => 'ddac fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function ddacAlert(User $user, string $state = 'open'): DriftAlert
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
        'cluster_key' => 'ddac::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => ddacTransaction($db, $user->id),
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
    $this->user = ddacUser('ddac');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('transitions an open alert to dismissed_cancelled with the audit reason', function (): void {
    $alert = ddacAlert($this->user, 'open');

    /** @var DismissDriftAlertAsCancelled $action */
    $action = $this->app->make(DismissDriftAlertAsCancelled::class);
    ($action)($alert->id, $this->user);

    /** @var DriftAlert $fresh */
    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('dismissed_cancelled');
    expect($fresh->actioned_at?->toDateTimeString())->toBe('2026-05-20 09:00:00');

    $transitions = DriftAlertTransition::query()
        ->where('drift_alert_id', $alert->id)
        ->get();
    expect($transitions)->toHaveCount(1);
    expect($transitions[0]->from_state)->toBe('open');
    expect($transitions[0]->to_state)->toBe('dismissed_cancelled');
    expect($transitions[0]->transition_reason)->toBe('user_dismissed_cancelled');
    expect($transitions[0]->actor)->toBe('user');
});

it('dispatches DriftAlertDismissedCancelled with the underlying recurringSeriesId', function (): void {
    Event::fake([DriftAlertDismissedCancelled::class]);

    $alert = ddacAlert($this->user, 'open');

    /** @var DismissDriftAlertAsCancelled $action */
    $action = $this->app->make(DismissDriftAlertAsCancelled::class);
    ($action)($alert->id, $this->user);

    Event::assertDispatched(DriftAlertDismissedCancelled::class, function (DriftAlertDismissedCancelled $e) use ($alert): bool {
        return $e->driftAlertId === $alert->id
            && $e->userId === $this->user->id
            && $e->recurringSeriesId === $alert->recurring_series_id;
    });
});

it('does NOT mutate recurring_series.state', function (): void {
    $alert = ddacAlert($this->user, 'open');

    /** @var RecurringSeries $seriesBefore */
    $seriesBefore = RecurringSeries::query()->findOrFail($alert->recurring_series_id);
    $stateBefore = $seriesBefore->state;

    /** @var DismissDriftAlertAsCancelled $action */
    $action = $this->app->make(DismissDriftAlertAsCancelled::class);
    ($action)($alert->id, $this->user);

    /** @var RecurringSeries $seriesAfter */
    $seriesAfter = RecurringSeries::query()->findOrFail($alert->recurring_series_id);
    expect($seriesAfter->state)->toBe($stateBefore);
});

it('is idempotent when already dismissed (no second transitions row, no event)', function (): void {
    Event::fake([DriftAlertDismissedCancelled::class]);

    $alert = ddacAlert($this->user, 'dismissed_cancelled');

    /** @var DismissDriftAlertAsCancelled $action */
    $action = $this->app->make(DismissDriftAlertAsCancelled::class);
    ($action)($alert->id, $this->user);

    $count = DriftAlertTransition::query()
        ->where('drift_alert_id', $alert->id)
        ->count();
    expect($count)->toBe(0);

    Event::assertNotDispatched(DriftAlertDismissedCancelled::class);
});

it('throws NotFoundHttpException for a cross-user alert id', function (): void {
    $intruder = ddacUser('ddac-intruder');
    $alert = ddacAlert($this->user, 'open');

    /** @var DismissDriftAlertAsCancelled $action */
    $action = $this->app->make(DismissDriftAlertAsCancelled::class);
    expect(fn () => ($action)($alert->id, $intruder))
        ->toThrow(NotFoundHttpException::class);

    /** @var DriftAlert $fresh */
    $fresh = DriftAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('open');
});
