<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalyAlertTransition;
use Modules\Anomaly\Public\Actions\SnoozeAnomalyAlert;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;

function relapseUser(): User
{
    return User::query()->create([
        'username' => 'relapse-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function relapseTxn(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'rel-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/rel-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'rel-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'rel-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify', 'counterparty_name' => 'SPOTIFY', 'normalization_version' => 1,
        'description' => 'relapse fixture', 'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
}

// A snooze whose window has already run out, on a row the revival sweep has
// not reached yet. That is exactly what the Open tab lists.
function relapseLapsedAlert(User $user): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return AnomalyAlert::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => relapseTxn($db, (int) $user->id),
        'state' => 'snoozed',
        'snoozed_until' => CarbonImmutable::parse('2026-06-18 09:00:00'),
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
    $this->user = relapseUser();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lists a lapsed snooze in the Open tab, which is what makes re-snoozing reachable', function (): void {
    $alert = relapseLapsedAlert($this->user);

    /** @var AnomalyAlertQuery $query */
    $query = app(AnomalyAlertQuery::class);
    $ids = array_map(static fn ($dto): int => $dto->anomalyAlertId, $query->openForUser($this->user));

    expect($ids)->toContain($alert->id);
});

it('moves a lapsed anomaly snooze to a new date instead of throwing', function (): void {
    $alert = relapseLapsedAlert($this->user);
    /** @var SnoozeAnomalyAlert $action */
    $action = app(SnoozeAnomalyAlert::class);

    ($action)($alert->id, $this->user, CarbonImmutable::parse('2026-06-27 09:00:00'));

    /** @var AnomalyAlert $fresh */
    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('snoozed')
        ->and($fresh->snoozed_until?->toDateTimeString())->toBe('2026-06-27 09:00:00');
});

it('records the re-snooze as a transition row, like every other lifecycle write', function (): void {
    $alert = relapseLapsedAlert($this->user);
    /** @var SnoozeAnomalyAlert $action */
    $action = app(SnoozeAnomalyAlert::class);

    ($action)($alert->id, $this->user, CarbonImmutable::parse('2026-06-27 09:00:00'));

    $transition = AnomalyAlertTransition::query()->where('anomaly_alert_id', $alert->id)->firstOrFail();
    expect($transition->from_state)->toBe('snoozed')
        ->and($transition->to_state)->toBe('snoozed')
        ->and($transition->notes)->toContain('snoozed_until=2026-06-27');
});

it('survives the same click made from the screen the row is listed on', function (): void {
    $alert = relapseLapsedAlert($this->user);

    Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => 'anomaly'])
        ->call('snoozeAnomaly', (string) $alert->id, '2026-06-27T09:00:00+00:00')
        ->assertOk();

    expect(AnomalyAlert::query()->findOrFail($alert->id)->snoozed_until?->toDateTimeString())
        ->toBe('2026-06-27 09:00:00');
});
