<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Http\Livewire\DashboardDriftBadge;

uses(RefreshDatabase::class);

function ddbUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function ddbTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'ddb-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ddb-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'ddb-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'ddb-'.bin2hex(random_bytes(8))),
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
        'description' => 'ddb fixture',
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
function ddbAlert(User $user, array $alertOverrides = []): DriftAlert
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
        'cluster_key' => 'ddb::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => ddbTransaction($db, $user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return DriftAlert::factory()->create(array_merge([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'state' => 'open',
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
    ], $alertOverrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = ddbUser('ddb');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders nothing when the open count is zero', function (): void {
    $component = Livewire::actingAs($this->user)->test(DashboardDriftBadge::class);

    $openCount = $component->viewData('openCount');
    expect($openCount)->toBe(0);

    $component->assertDontSee('Drift alerts');
});

it('renders the tile with the open count when openCount > 0', function (): void {
    ddbAlert($this->user);
    ddbAlert($this->user);
    ddbAlert($this->user);

    $component = Livewire::actingAs($this->user)->test(DashboardDriftBadge::class);

    $openCount = $component->viewData('openCount');
    expect($openCount)->toBe(3);

    $component->assertSee('Drift alerts');
    $component->assertSee('>3<', false);
    $component->assertSee(route('drift.index'), false);
});

it('sums annualized impact across open alerts and renders an EUR-roll-up helper line', function (): void {
    ddbAlert($this->user, ['annualized_impact_minor' => -1800]);
    ddbAlert($this->user, ['annualized_impact_minor' => -600]);

    $component = Livewire::actingAs($this->user)->test(DashboardDriftBadge::class);

    $total = $component->viewData('totalAnnualizedImpact');
    expect((int) $total)->toBe(-2400);

    // Asserted in halves: the symbol and the amount are separated by a space
    // whose bytes survive Blade escaping, so the two are matched apart. The
    // marks are the reader's, and this suite reads in English.
    $component->assertSee('24.00');
    $component->assertSee('€');
});

it('does not surface another user\'s open alerts (cross-user isolation)', function (): void {
    $other = ddbUser('ddb-other');
    ddbAlert($other);

    $component = Livewire::actingAs($this->user)->test(DashboardDriftBadge::class);

    $openCount = $component->viewData('openCount');
    expect($openCount)->toBe(0);

    $component->assertDontSee('Drift alerts');
});
