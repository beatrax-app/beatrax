<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Anomaly\Internal\Http\Livewire\DashboardAnomalyBadge;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function dabUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function dabAlert(User $user, string $merchantSlug, array $reasons): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'dab-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/dab-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'dab-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id, 'type' => 'merchant', 'slug' => $merchantSlug.'-'.$suffix, 'display_name' => ucfirst($merchantSlug),
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'dab-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_id' => $cpId, 'counterparty_normalized' => $merchantSlug, 'counterparty_name' => strtoupper($merchantSlug),
        'normalization_version' => 1, 'description' => 'dab', 'type' => 'expense', 'source_format' => 'asn-csv',
        'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return AnomalyAlert::factory()->create([
        'user_id' => $user->id, 'transaction_id' => $txnId, 'state' => 'open', 'direction' => 'expense',
        'reasons' => $reasons, 'baseline_amount_minor' => -999, 'latest_amount_minor' => -2349,
        'currency' => 'EUR', 'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = dabUser('dab-a');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders nothing when there are no open anomalies', function (): void {
    Livewire::actingAs($this->user)
        ->test(DashboardAnomalyBadge::class)
        ->assertDontSee('Unusual charges')
        ->assertSet('openCount', 0);
});

it('shows the open count and the detector breakdown when anomalies are open', function (): void {
    dabAlert($this->user, 'spotify', ['large']);
    dabAlert($this->user, 'netflix', ['large', 'first_time']);

    Livewire::actingAs($this->user)
        ->test(DashboardAnomalyBadge::class)
        ->assertSee('Unusual charges')
        ->assertSee('2 open')
        ->assertSee('2 large')
        ->assertSee('1 first-time');
});
