<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Http\Livewire\DriftThresholdEditor;

uses(RefreshDatabase::class);

function thqUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function thqTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'thq-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/thq-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'thq-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'thq-'.bin2hex(random_bytes(8))),
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
        'description' => 'thq fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function thqSeries(User $user, string $detectedName, ?int $threshold): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $detectedName,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1149,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'drift_threshold_percent' => $threshold,
        'cluster_key' => 'thq::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function thqAlert(User $user, int $seriesId): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => thqTransaction($db, $user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    DriftAlert::factory()->create([
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

/** @return list<string> */
function thqThresholdQueries(callable $render): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seen = [];
    $db->connection()->listen(static function (QueryExecuted $query) use (&$seen): void {
        if (str_contains($query->sql, 'drift_threshold_percent')) {
            $seen[] = $query->sql;
        }
    });

    $render();

    return $seen;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
});

// Every series with an open alert mounts a threshold editor, so the page grew a
// query per series: bounded only by how many subscriptions have drifted at once,
// and paid again on every re-render of the tab.
it('reads every series threshold in one query however many editors the page mounts', function (): void {
    $user = thqUser('thq-count');

    foreach (['Netflix' => 10, 'Spotify' => null, 'Vodafone' => 25, 'Ziggo' => null] as $name => $threshold) {
        thqAlert($user, thqSeries($user, $name, $threshold));
    }

    $grouped = thqSeries($user, 'Disney Plus', 50);
    thqAlert($user, $grouped);
    thqAlert($user, $grouped);

    $queries = thqThresholdQueries(function () use ($user): void {
        $this->actingAs($user)->get('/drift')->assertOk();
    });

    expect($queries)->toHaveCount(1);
});

it('shows each series the same threshold the editor would have read for itself', function (): void {
    $user = thqUser('thq-values');

    thqAlert($user, thqSeries($user, 'Netflix', 10));
    thqAlert($user, thqSeries($user, 'Spotify', null));

    $grouped = thqSeries($user, 'Disney Plus', 50);
    thqAlert($user, $grouped);
    thqAlert($user, $grouped);

    $content = (string) $this->actingAs($user)->get('/drift')->assertOk()->getContent();

    expect(substr_count($content, '±10%'))->toBeGreaterThan(0)
        ->and(substr_count($content, '±50%'))->toBeGreaterThan(0)
        ->and($content)->toContain('global');
});

// The /recurring/series/{id} drill-in mounts the same editor with no parent to
// load the column for it, so the row read has to survive as the fallback.
it('still reads its own row when nothing hands it a threshold', function (): void {
    $user = thqUser('thq-standalone');
    $seriesId = thqSeries($user, 'Netflix', 25);

    $component = Livewire\Livewire::actingAs($user)
        ->test(DriftThresholdEditor::class, ['recurringSeriesId' => $seriesId]);

    $component->assertSet('currentValue', 25);
    $component->assertSee('±25%');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});
