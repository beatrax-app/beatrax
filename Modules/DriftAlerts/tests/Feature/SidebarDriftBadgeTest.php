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

/**
 * @param  array<string, mixed>  $overrides
 */
function tdbAlert(User $user, array $overrides = []): DriftAlert
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
        ...$overrides,
    ]);
}

// The two badges the Commitments section carries for this data: rose for the
// alerts, outlined for the series inventory. Asserted whole so a number
// landing on the wrong row cannot pass.
function tdbDriftBadge(int $count): string
{
    return '<span role="img" class="side-badge alert" aria-label="'
        .$count.' open drift alerts">'.$count.'</span>';
}

function tdbRecurringBadge(int $count): string
{
    return '<span role="img" class="side-badge muted" aria-label="'
        .$count.' recurring series">'.$count.'</span>';
}

// One sidebar anchor, from its href to the closing tag.
function tdbNavRow(string $content, string $href): string
{
    $start = strpos($content, 'href="'.$href.'"');
    expect($start)->toBeInt();

    $end = strpos($content, '</a>', (int) $start);
    expect($end)->toBeInt();

    return substr($content, (int) $start, (int) $end - (int) $start);
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

it('renders the rose alert badge carrying the open-alert count', function (): void {
    tdbAlert($this->user);
    tdbAlert($this->user);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    expect($content)->toContain(tdbDriftBadge(2));
})->group('drift-badge-shows-rose-pill-when-non-zero');

it('renders the muted series badge beside the rose drift badge, and leaves a pending series out of both', function (): void {
    tdbPendingSeries($this->user);
    // Each alert brings an approved series with it, which is what the muted
    // Recurring badge counts. The pending one is in neither number.
    tdbAlert($this->user);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    expect($content)->toContain(tdbDriftBadge(1));
    expect($content)->toContain(tdbRecurringBadge(1));
    expect($content)->not->toContain(tdbRecurringBadge(2));
})->group('compound-pill-when-both-non-zero');

it('keeps the alert badge on the Drift row and the muted badge on the Recurring row', function (): void {
    tdbAlert($this->user);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    // Sliced at the anchor so "which row" is part of what is asserted, not
    // just "somewhere in the page".
    $recurringRow = tdbNavRow($content, route('recurring.index'));
    expect($recurringRow)->toContain('side-badge muted');
    expect($recurringRow)->not->toContain('side-badge alert');

    $driftRow = tdbNavRow($content, route('drift.index'));
    expect($driftRow)->toContain('side-badge alert');
    expect($driftRow)->not->toContain('side-badge muted');
})->group('drift-only-pill-when-no-pending');

it('counts a snoozed alert whose deadline has passed — the badge matches the list it points at', function (): void {
    tdbAlert($this->user, [
        'state' => 'snoozed',
        'snoozed_until' => CarbonImmutable::parse('2026-05-19 12:00:00'),
        'actioned_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ]);
    tdbAlert($this->user, [
        'state' => 'snoozed',
        'snoozed_until' => CarbonImmutable::parse('2026-06-30 12:00:00'),
        'actioned_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ]);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    // The revived one counts; the one still asleep until June does not.
    expect($content)->toContain(tdbDriftBadge(1));
})->group('drift-badge-counts-revived-snooze');

it('never counts another household member\'s revived alert', function (): void {
    $other = tdbUser('tdb-other');
    tdbAlert($other, [
        'state' => 'snoozed',
        'snoozed_until' => CarbonImmutable::parse('2026-05-19 12:00:00'),
        'actioned_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ]);
    tdbAlert($other);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk();
    $content = $response->getContent() ?: '';

    // An OR appended flat rather than grouped would escape the user_id
    // predicate and hand this user the other one's revived alert.
    expect($content)->not->toContain('open drift alerts');
})->group('drift-badge-is-user-scoped');
