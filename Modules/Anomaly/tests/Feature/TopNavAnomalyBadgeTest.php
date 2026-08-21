<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function tabUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function tabAlert(User $user, string $state = 'open', array $overrides = []): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'tab-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/tab-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tab-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tab-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify', 'counterparty_name' => 'SPOTIFY', 'normalization_version' => 1,
        'description' => 'tab', 'type' => 'expense', 'source_format' => 'asn-csv',
        'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return AnomalyAlert::factory()->create(array_merge([
        'user_id' => $user->id, 'transaction_id' => $txnId, 'state' => $state, 'direction' => 'expense',
        'reasons' => ['large'], 'baseline_amount_minor' => -999, 'latest_amount_minor' => -2349,
        'currency' => 'EUR', 'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = tabUser('tab-a');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders no anomaly badge when the user has no open anomalies', function (): void {
    $response = $this->actingAs($this->user)->get(route('settings'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    expect($content)->toContain('Unusual charges');
    expect($content)->not->toContain('open unusual charges');
});

it('renders the amber side-badge with the open count when anomalies exist', function (): void {
    tabAlert($this->user);
    tabAlert($this->user);

    $response = $this->actingAs($this->user)->get(route('settings'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    expect($content)->toContain('2 open unusual charges');
    expect($content)->toContain('side-badge alert');
});

it('sets navCounts[anomaly] equal to AnomalyAlertQuery::openCountForUser (revival-aware)', function (): void {
    tabAlert($this->user);
    // A snoozed-but-expired alert counts as open (revival-aware).
    tabAlert($this->user, 'snoozed', ['snoozed_until' => CarbonImmutable::parse('2026-06-18 09:00:00')]);
    // A still-snoozed alert does NOT count.
    tabAlert($this->user, 'snoozed', ['snoozed_until' => CarbonImmutable::parse('2026-06-25 09:00:00')]);

    $expected = $this->app->make(AnomalyAlertQuery::class)->openCountForUser($this->user);
    expect($expected)->toBe(2);

    $response = $this->actingAs($this->user)->get(route('settings'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    expect($content)->toContain($expected.' open unusual charges');
});
