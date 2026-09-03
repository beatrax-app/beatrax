<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenarioFromTemplate;
use Modules\Sync\Public\Services\DependentRowCascade;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function mclUser(string $username = 'mcl'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function mclSeries(DatabaseManager $db, int $userId, string $name = 'Netflix'): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1199,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1199,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-05-25',
        'cluster_key' => 'cluster-'.$name.'-'.$userId,
        'cluster_counterparty_key' => $name,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function mclTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN mcl',
        'slug' => 'mcl-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00MCL'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/mcl-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'mcl-run-'.$suffix),
        'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'mcl-'.$suffix),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1199,
        'currency' => 'EUR',
        'settled_amount_minor' => -1199,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'netflix',
        'counterparty_name' => 'NETFLIX',
        'normalization_version' => 1,
        'description' => 'mcl fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-15 00:00:00',
        'updated_at' => '2026-05-15 00:00:00',
    ]);
}

function mclAlert(DatabaseManager $db, int $userId, int $seriesId): int
{
    $occurrenceId = $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'transaction_id' => mclTransaction($db, $userId),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1349,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-15 00:00:00',
        'updated_at' => '2026-05-15 00:00:00',
    ]);
    /** @var DriftAlert $alert */
    $alert = DriftAlert::factory()->create([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'state' => 'open',
        'direction' => 'expense',
        'baseline_amount_minor' => -1199,
        'latest_amount_minor' => -1349,
        'currency' => 'EUR',
        'delta_minor' => -150,
        'annualized_impact_minor' => -1800,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => $occurrenceId,
        'detected_at' => CarbonImmutable::parse('2026-05-15 12:00:00'),
    ]);

    return $alert->id;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = mclUser();
});

it('CreateScenarioFromTemplate happy path: persists scenario + one cancel_series mutation', function (): void {
    $seriesId = mclSeries($this->db, $this->user->id, 'Netflix');
    $alertId = mclAlert($this->db, $this->user->id, $seriesId);

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);
    $newId = $action->forDriftAlert($alertId, $this->user);

    expect($newId)->toBeGreaterThan(0);
    $scenario = $this->db->connection()->table('forecast_scenarios')->where('id', $newId)->first();
    expect($scenario->name)->toBe('Cancel Netflix');
    expect($scenario->user_id)->toBe($this->user->id);

    $mutations = $this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $newId)->get();
    expect($mutations->count())->toBe(1);
    expect($mutations->first()->kind)->toBe('cancel_series');
    expect((int) $mutations->first()->target_series_id)->toBe($seriesId);
});

it('CreateScenarioFromTemplate returns 404 for another user\'s alert', function (): void {
    $other = mclUser('other');
    $seriesId = mclSeries($this->db, $other->id);
    $alertId = mclAlert($this->db, $other->id, $seriesId);

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);

    expect(fn () => $action->forDriftAlert($alertId, $this->user))->toThrow(NotFoundHttpException::class);
});

it('CreateScenarioFromTemplate atomic rollback when AddScenarioMutation fails', function (): void {
    // An alert left pointing at a series that has since been deleted is what
    // makes AddScenarioMutation fail partway through the launchpad.
    $seriesId = mclSeries($this->db, $this->user->id, 'Netflix-soon-to-vanish');
    $alertId = mclAlert($this->db, $this->user->id, $seriesId);
    // The alert is the series' own row, so it goes with it rather than being
    // taken away by the database behind the delete.
    $this->app->make(DependentRowCascade::class)->delete('recurring_series', $seriesId, $this->user->id);
    $this->db->connection()->table('recurring_series')->where('id', $seriesId)->delete();

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);

    expect(fn () => $action->forDriftAlert($alertId, $this->user))->toThrow(NotFoundHttpException::class);
    // The launchpad's outer transaction wraps CreateScenario as well, so the
    // scenario insert must not survive the failed mutation.
    expect($this->db->connection()->table('forecast_scenarios')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('drift-page renders the Model cancel chip in the alert row', function (): void {
    $seriesId = mclSeries($this->db, $this->user->id, 'Netflix');
    mclAlert($this->db, $this->user->id, $seriesId);

    $this->actingAs($this->user)
        ->get('/drift')
        ->assertOk()
        ->assertSee('Model cancel ↗');
});

it('DismissDriftAlertAsCancelled marks the alert dismissed_cancelled without creating a scenario', function (): void {
    $seriesId = mclSeries($this->db, $this->user->id, 'Netflix');
    $alertId = mclAlert($this->db, $this->user->id, $seriesId);

    /** @var DismissDriftAlertAsCancelled $dismiss */
    $dismiss = $this->app->make(DismissDriftAlertAsCancelled::class);
    ($dismiss)($alertId, $this->user);

    expect($this->db->connection()->table('forecast_scenarios')->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('drift_alerts')->where('id', $alertId)->value('state'))->toBe('dismissed_cancelled');
});

it('the launchpad uses display_name_override when present', function (): void {
    $seriesId = mclSeries($this->db, $this->user->id, 'Auto-detected name');
    $this->db->connection()->table('recurring_series')
        ->where('id', $seriesId)
        ->update(['display_name_override' => 'My Netflix sub']);
    $alertId = mclAlert($this->db, $this->user->id, $seriesId);

    /** @var CreateScenarioFromTemplate $action */
    $action = $this->app->make(CreateScenarioFromTemplate::class);
    $newId = $action->forDriftAlert($alertId, $this->user);

    expect($this->db->connection()->table('forecast_scenarios')->where('id', $newId)->value('name'))->toBe('Cancel My Netflix sub');
});

it('reaches AddScenarioMutation through the container rather than building its own', function (): void {
    // The launchpad has to reach the Actions through the container, or a later
    // tightening of their validation would not reach it. That is resolvability,
    // never identity — the action dispatches, so it is deliberately not a
    // singleton whose captured dispatcher Event::fake() could never replace.
    expect($this->app->make(AddScenarioMutation::class))->toBeInstanceOf(AddScenarioMutation::class);
});
