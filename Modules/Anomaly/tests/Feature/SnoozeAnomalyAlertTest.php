<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalyAlertTransition;
use Modules\Anomaly\Public\Actions\SnoozeAnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertSnoozed;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function snzUser(): User
{
    return User::query()->create([
        'username' => 'snz-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function snzTxn(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'snz-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/snz-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'snz-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'snz-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify', 'counterparty_name' => 'SPOTIFY', 'normalization_version' => 1,
        'description' => 'snz fixture', 'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function snzAlert(User $user, string $state = 'open', array $overrides = []): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return AnomalyAlert::factory()->create(array_merge([
        'user_id' => $user->id, 'transaction_id' => snzTxn($db, (int) $user->id), 'state' => $state,
        'direction' => 'expense', 'reasons' => ['large'], 'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349, 'currency' => 'EUR', 'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = snzUser();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('snoozes an open alert to an in-range target with the snoozed_until patch + event', function (): void {
    Event::fake([AnomalyAlertSnoozed::class]);
    $action = $this->app->make(SnoozeAnomalyAlert::class);
    $alert = snzAlert($this->user, 'open');
    $until = CarbonImmutable::parse('2026-06-27 09:00:00');

    ($action)($alert->id, $this->user, $until);

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('snoozed')
        ->and($fresh->snoozed_until?->getTimestamp())->toBe($until->getTimestamp());

    Event::assertDispatched(AnomalyAlertSnoozed::class);
});

it('rejects a snooze target beyond now+6mo without touching the row', function (): void {
    $action = $this->app->make(SnoozeAnomalyAlert::class);
    $alert = snzAlert($this->user, 'open');
    $beyond = CarbonImmutable::parse('2026-06-20 09:00:00')->addMonthsNoOverflow(6)->addDay();

    expect(fn () => ($action)($alert->id, $this->user, $beyond))
        ->toThrow(InvalidArgumentException::class);

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('open')
        ->and($fresh->snoozed_until)->toBeNull();
    expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $alert->id)->count())->toBe(0);
});

it('rejects a snooze target in the past', function (): void {
    $action = $this->app->make(SnoozeAnomalyAlert::class);
    $alert = snzAlert($this->user, 'open');

    expect(fn () => ($action)($alert->id, $this->user, CarbonImmutable::parse('2026-06-19 09:00:00')))
        ->toThrow(InvalidArgumentException::class);

    expect(AnomalyAlert::query()->findOrFail($alert->id)->state)->toBe('open');
});

it('is idempotent when re-snoozing to the exact same target timestamp', function (): void {
    $action = $this->app->make(SnoozeAnomalyAlert::class);
    $until = CarbonImmutable::parse('2026-06-27 09:00:00');
    $alert = snzAlert($this->user, 'snoozed', ['snoozed_until' => $until]);

    ($action)($alert->id, $this->user, $until);

    expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $alert->id)->count())->toBe(0);
});

it('is idempotent when re-snoozing with a non-app source offset that round-trips to the same wall-clock', function (): void {
    Event::fake([AnomalyAlertSnoozed::class]);
    $action = $this->app->make(SnoozeAnomalyAlert::class);

    // The stored value is $until->toDateTimeString(), re-hydrated as app-tz
    // wall-clock, so a re-snooze to the same New York 09:00 must compare equal
    // and short-circuit. The earlier getTimestamp() comparison saw two
    // distinct instants and wrote a redundant transition.
    $until = CarbonImmutable::parse('2026-06-27 09:00:00', 'America/New_York');
    $alert = snzAlert($this->user, 'snoozed', ['snoozed_until' => $until->toDateTimeString()]);

    ($action)($alert->id, $this->user, $until);

    expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $alert->id)->count())->toBe(0);
    Event::assertNotDispatched(AnomalyAlertSnoozed::class);
});
