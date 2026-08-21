<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Jobs\ReviveExpiredAnomalySnoozesJob;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalyAlertTransition;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

uses(RefreshDatabase::class);

function asrUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function asrTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASR ASN',
        'slug' => 'asr-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASR'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/asr-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'asr-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'asr-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -2349,
        'currency' => 'EUR',
        'settled_amount_minor' => -2349,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'asr-merchant',
        'counterparty_name' => 'ASR MERCHANT',
        'normalization_version' => 1,
        'description' => 'asr fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function asrAlert(User $user, string $state = 'open', ?CarbonImmutable $snoozedUntil = null): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return AnomalyAlert::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => asrTransaction($db, (int) $user->id),
        'state' => $state,
        'direction' => 'expense',
        'reasons' => ['large'],
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'snoozed_until' => $snoozedUntil,
        'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ]);
}

function asrRunJob(): void
{
    /** @var ReviveExpiredAnomalySnoozesJob $job */
    $job = app(ReviveExpiredAnomalySnoozesJob::class);
    $job->handle(
        app(DatabaseManager::class),
        app(AnomalyAlertStateMachine::class),
        app(Clock::class),
    );
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('flips snoozed -> open when snoozed_until is in the past and writes the audit transition', function (): void {
    $user = asrUser('asr-flip');
    $alert = asrAlert($user, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-20 08:00:00'));

    asrRunJob();

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('open');
    expect($fresh->snoozed_until)->toBeNull();

    $transition = AnomalyAlertTransition::query()
        ->where('anomaly_alert_id', $alert->id)
        ->orderByDesc('id')
        ->first();
    expect($transition)->not->toBeNull();
    expect($transition?->from_state)->toBe('snoozed');
    expect($transition?->to_state)->toBe('open');
    expect($transition?->transition_reason)->toBe('detector_revived_snooze');
    expect($transition?->actor)->toBe('detector');
});

it('does NOT flip snoozed alerts whose snoozed_until is in the future', function (): void {
    $user = asrUser('asr-future');
    $alert = asrAlert($user, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-21 12:00:00'));

    asrRunJob();

    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('snoozed');
    expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $alert->id)->count())->toBe(0);
});

it('revives every expired snooze across users in one chunked sweep', function (): void {
    $userA = asrUser('asr-chunk-a');
    $userB = asrUser('asr-chunk-b');

    $expired = [];
    foreach (range(1, 6) as $n) {
        $owner = $n % 2 === 0 ? $userA : $userB;
        $expired[] = asrAlert(
            $owner,
            state: 'snoozed',
            snoozedUntil: CarbonImmutable::parse('2026-05-20 08:00:00'),
        )->id;
    }

    $future = asrAlert($userA, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-25 12:00:00'))->id;

    asrRunJob();

    foreach ($expired as $id) {
        $fresh = AnomalyAlert::query()->findOrFail($id);
        expect($fresh->state)->toBe('open')
            ->and($fresh->snoozed_until)->toBeNull();
        expect(AnomalyAlertTransition::query()->where('anomaly_alert_id', $id)->count())->toBe(1);
    }

    expect(AnomalyAlert::query()->findOrFail($future)->state)->toBe('snoozed');
});

it('AnomalyAlertQuery::openForUser surfaces the expired snooze before the sweep runs', function (): void {
    $user = asrUser('asr-querytime');
    $alert = asrAlert($user, state: 'snoozed', snoozedUntil: CarbonImmutable::parse('2026-05-20 08:00:00'));

    /** @var AnomalyAlertQuery $query */
    $query = app(AnomalyAlertQuery::class);

    $rows = $query->openForUser($user);
    $ids = array_map(static fn ($r) => $r->anomalyAlertId, $rows);

    expect($ids)->toContain($alert->id);
});
