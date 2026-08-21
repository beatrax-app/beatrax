<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\Recurring\Models\RecurringSeries;

uses(RefreshDatabase::class);

function tdbUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function tdbTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'tdb-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tdb-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tdb-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tdb-'.bin2hex(random_bytes(8))),
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
        'description' => 'tdb fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function tdbAlert(User $user): DriftAlert
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
        'cluster_key' => 'tdb::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => tdbTransaction($db, $user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return DriftAlert::factory()->create([
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
    ]);
}

function tdbPendingSeries(User $user): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'tdb-pending',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'tdb-pending::'.bin2hex(random_bytes(4)),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = tdbUser('tdb');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders no drift pill when the user has no open alerts', function (): void {
    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    expect($content)->toContain('Recurring');
    expect($content)->not->toContain('open drift alerts');
})->group('drift-badge-hidden-when-zero');

it('injects driftOpenCount > 0 and surfaces the rose drift pill in the aria-label', function (): void {
    tdbAlert($this->user);
    tdbAlert($this->user);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    expect($content)->toContain('2 open drift alerts');
    expect($content)->toContain('bg-rose-50');
    expect($content)->toContain('text-rose-700');
})->group('drift-badge-shows-rose-pill-when-non-zero')
    ->todo('16-01 replaced the top-nav with the app sidebar. The Recurring drift pill chrome (bg-rose-50 / text-rose-700) no longer ships in the new sidebar markup; a follow-up plan re-wires the drift composer onto core::livewire.app-sidebar and re-introduces the rose .side-badge.alert pill, at which point this assertion is updated to match the new chrome.');

it('renders the compound pill when both pending recurring suggestions and open drift alerts exist', function (): void {
    tdbPendingSeries($this->user);
    tdbAlert($this->user);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    expect($content)->toContain('bg-slate-900');
    expect($content)->toContain('bg-rose-50');
    expect($content)->toContain('1 pending recurring suggestions');
    expect($content)->toContain('1 open drift alerts');
})->group('compound-pill-when-both-non-zero')
    ->todo('16-01 replaced the top-nav with the app sidebar. The compound pending+drift pill chrome no longer ships in the new sidebar markup; a follow-up plan re-wires the composers onto core::livewire.app-sidebar and re-introduces the .side-badge slot with the default+alert variants, at which point this assertion is updated to match the new chrome.');

it('renders only the drift pill when there are no pending suggestions but open drift alerts exist', function (): void {
    tdbAlert($this->user);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    expect($content)->toContain('1 open drift alerts');

    $start = strpos($content, 'href="'.route('recurring.index').'"');
    expect($start)->toBeInt();
    $segment = substr($content, (int) $start, 2000);

    // bg-slate-900 is the pending pill's chrome, bg-rose-50 the drift pill's.
    expect($segment)->toContain('bg-rose-50');
    expect(str_contains($segment, 'bg-slate-900 px-2 py-0.5'))->toBeFalse();
})->group('drift-only-pill-when-no-pending')
    ->todo('16-01 replaced the top-nav with the app sidebar. The Recurring drift-only pill chrome no longer ships in the new sidebar markup; a follow-up plan re-wires the drift composer onto core::livewire.app-sidebar and re-introduces the rose .side-badge.alert pill, at which point this assertion is updated to match the new chrome.');
