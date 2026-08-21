<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Actions\EditScenarioMutation;
use Modules\Forecasting\Public\Actions\RemoveScenarioMutation;
use Modules\Forecasting\Public\Actions\RenameScenario;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

uses(RefreshDatabase::class);

// The compile-time half of this proof is BoundaryArchTest's
// noScenarioMutationsJoinedToTransactionQueries, which greps for the forbidden
// JOIN. This is the runtime half: every scenario Public Action runs against a
// seeded substrate, and the substrate row counts must never move.

/**
 * @return array{user: User, accountId: int, asnAccountId: int, seriesIds: list<int>, importRunId: int, baseline: array<string, int>}
 */
function sisSeedSubstrate(DatabaseManager $db): array
{
    $user = User::query()->create([
        'username' => 'sis-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'SIS ASN',
        'slug' => 'sis-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'asn',
        'iban' => 'SIS'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 200000,
        'opening_balance_as_of_date' => '2026-05-01',
    ]);

    $ics = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'SIS ICS',
        'slug' => 'sis-ics-'.bin2hex(random_bytes(3)),
        'kind' => 'ics_card',
        'iban' => 'SIS'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sis-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => str_repeat('s', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $seriesIds = [];
    $row = 0;
    for ($s = 0; $s < 5; $s++) {
        $series = RecurringSeries::query()->create([
            'user_id' => $user->id,
            'cadence' => 'monthly',
            'direction' => 'expense',
            'detected_name' => 'sis-series-'.$s,
            'state' => 'approved',
            'variance_tolerance_percent' => 5,
            'latest_amount_minor' => -1200 - ($s * 100),
            'latest_currency' => 'EUR',
            'cluster_key' => 'sis-cluster-'.$s.'-'.$user->id,
            'next_expected_at' => '2026-05-15',
            'monthly_equivalent_minor' => -1200 - ($s * 100),
        ]);
        $seriesIds[] = $series->id;

        foreach (['2026-02-15', '2026-03-15', '2026-04-15'] as $occDate) {
            $row++;
            $txn = Transaction::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'type' => 'expense',
                'posted_at' => $occDate,
                'booked_at' => $occDate.' 09:00:00',
                'value_date' => $occDate,
                'amount_minor' => -1200 - ($s * 100),
                'currency' => 'EUR',
                'settled_amount_minor' => -1200 - ($s * 100),
                'settled_currency' => 'EUR',
                'counterparty_name' => 'sis-cp-'.$s,
                'counterparty_normalized' => 'sis-cp-'.$s,
                'normalization_version' => 3,
                'source_format' => 'asn-csv',
                'import_run_id' => $run->id,
                'source_row_index' => $row,
                'fingerprint' => str_pad('sis-'.$row, 64, 'f', STR_PAD_LEFT),
                'fingerprint_version' => 3,
            ]);
            RecurringSeriesOccurrence::query()->create([
                'user_id' => $user->id,
                'recurring_series_id' => $series->id,
                'transaction_id' => $txn->id,
                'observed_at' => $occDate,
                'observed_amount_minor' => -1200 - ($s * 100),
                'observed_currency' => 'EUR',
            ]);
        }
    }

    $txns = Transaction::query()->where('user_id', $user->id)->orderBy('id')->limit(6)->get();
    for ($i = 0; $i < 3; $i++) {
        ChainLink::query()->create([
            'user_id' => $user->id,
            'from_transaction_id' => $txns[$i * 2]->id,
            'to_transaction_id' => $txns[($i * 2) + 1]->id,
            'kind' => 'paypal_funding',
            'state' => 'confirmed',
            'confidence' => 1.0,
            'resolver' => 'user',
            'evidence' => json_encode(['signature_hash' => 'sis-'.$i]),
        ]);
    }

    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -120000,
        'open_balance_minor' => 120000,
        'state' => 'open',
    ]);

    $occurrences = RecurringSeriesOccurrence::query()
        ->where('user_id', $user->id)
        ->orderBy('id')
        ->limit(2)
        ->get();
    foreach ($occurrences as $occ) {
        DriftAlert::query()->create([
            'user_id' => $user->id,
            'recurring_series_id' => $occ->recurring_series_id,
            'latest_occurrence_id' => $occ->id,
            'state' => 'open',
            'direction' => 'expense',
            'baseline_amount_minor' => -1199,
            'latest_amount_minor' => -1499,
            'currency' => 'EUR',
            'delta_minor' => 300,
            'annualized_impact_minor' => 3600,
            'threshold_percent_used' => 20,
            'threshold_source' => 'global',
            'detected_at' => CarbonImmutable::parse('2026-05-01'),
        ]);
    }

    return [
        'user' => $user,
        'accountId' => $account->id,
        'asnAccountId' => $account->id,
        'seriesIds' => $seriesIds,
        'importRunId' => $run->id,
        'baseline' => sisCounts($db, $user->id),
    ];
}

/**
 * @return array<string, int>
 */
function sisCounts(DatabaseManager $db, int $userId): array
{
    return [
        'transactions' => $db->connection()->table('transactions')->where('user_id', $userId)->count(),
        'recurring_series' => $db->connection()->table('recurring_series')->where('user_id', $userId)->count(),
        'recurring_series_occurrences' => $db->connection()->table('recurring_series_occurrences')->where('user_id', $userId)->count(),
        'chain_links' => $db->connection()->table('chain_links')->where('user_id', $userId)->count(),
        'card_statements' => $db->connection()->table('card_statements')->where('user_id', $userId)->count(),
        'drift_alerts' => $db->connection()->table('drift_alerts')->where('user_id', $userId)->count(),
        'accounts' => $db->connection()->table('accounts')->where('user_id', $userId)->count(),
    ];
}

/**
 * @param  array<string, int>  $expected
 */
function sisAssertCounts(DatabaseManager $db, int $userId, array $expected, string $context): void
{
    $actual = sisCounts($db, $userId);
    foreach ($expected as $table => $count) {
        expect($actual[$table])->toBe(
            $count,
            "Transaction-substrate row count for {$table} changed during {$context}: expected {$count}, got {$actual[$table]}",
        );
    }
}

it('keeps transaction-substrate row counts unchanged across the full scenario lifecycle', function (): void {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $seed = sisSeedSubstrate($db);
    /** @var User $user */
    $user = $seed['user'];
    /** @var array<string, int> $baseline */
    $baseline = $seed['baseline'];
    /** @var list<int> $seriesIds */
    $seriesIds = $seed['seriesIds'];

    $create = $this->app->make(CreateScenario::class);
    $scenarioId = ($create)($user, 'SIS scenario');
    expect($scenarioId)->toBeGreaterThan(0);
    expect($db->connection()->table('forecast_scenarios')->where('user_id', $user->id)->count())->toBe(1);
    sisAssertCounts($db, $user->id, $baseline, 'after CreateScenario');

    $add = $this->app->make(AddScenarioMutation::class);

    $mutationIds = [];
    $mutationIds['cancel'] = ($add)($scenarioId, $user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesIds[0]));
    sisAssertCounts($db, $user->id, $baseline, 'after cancel_series add');

    $mutationIds['oneoff'] = ($add)($scenarioId, $user, 'add_one_off', new AddOneOffPayload(
        date: '2026-05-20',
        amountMinor: -5000,
        currency: 'EUR',
        direction: 'expense',
    ));
    sisAssertCounts($db, $user->id, $baseline, 'after add_one_off add');

    $mutationIds['recurring'] = ($add)($scenarioId, $user, 'add_recurring', new AddRecurringPayload(
        startDate: '2026-05-10',
        amountMinor: -1500,
        currency: 'EUR',
        direction: 'expense',
        cadence: 'monthly',
    ));
    sisAssertCounts($db, $user->id, $baseline, 'after add_recurring add');

    $mutationIds['change'] = ($add)($scenarioId, $user, 'change_series_amount', new ChangeSeriesAmountPayload(
        seriesId: $seriesIds[1],
        newAmountMinor: -2500,
    ));
    sisAssertCounts($db, $user->id, $baseline, 'after change_series_amount add');

    $mutationIds['shift'] = ($add)($scenarioId, $user, 'shift_series_date', new ShiftSeriesDatePayload(
        seriesId: $seriesIds[2],
        newNextDate: '2026-05-10',
        scope: 'next',
    ));
    sisAssertCounts($db, $user->id, $baseline, 'after shift_series_date add');

    expect($db->connection()->table('forecast_scenario_mutations')->where('user_id', $user->id)->count())->toBe(5);

    $edit = $this->app->make(EditScenarioMutation::class);
    ($edit)($mutationIds['cancel'], $user, new CancelSeriesPayload(seriesId: $seriesIds[3]));
    sisAssertCounts($db, $user->id, $baseline, 'after EditScenarioMutation');

    $remove = $this->app->make(RemoveScenarioMutation::class);
    ($remove)($mutationIds['oneoff'], $user);
    sisAssertCounts($db, $user->id, $baseline, 'after RemoveScenarioMutation');
    expect($db->connection()->table('forecast_scenario_mutations')->where('user_id', $user->id)->count())->toBe(4);

    $rename = $this->app->make(RenameScenario::class);
    ($rename)($scenarioId, $user, 'SIS renamed scenario');
    sisAssertCounts($db, $user->id, $baseline, 'after RenameScenario');

    // Drop the Bus fake so the projection pipeline actually executes inline.
    Bus::swap($this->app->make(Dispatcher::class));
    $job = new ProjectForecastJob(userId: $user->id, scenarioId: $scenarioId, horizonDays: 30);
    Bus::dispatchSync($job);
    sisAssertCounts($db, $user->id, $baseline, 'after ProjectForecastJob run');
    expect($db->connection()->table('forecast_runs')
        ->where('user_id', $user->id)
        ->where('scenario_id', $scenarioId)
        ->where('horizon_days', 30)
        ->count())->toBeGreaterThanOrEqual(1);

    /** @var ForecastQuery $forecastQuery */
    $forecastQuery = $this->app->make(ForecastQuery::class);
    $dto = $forecastQuery->forUser($seed['accountId'], 30, $scenarioId, $user);
    expect($dto)->not->toBeNull();
    sisAssertCounts($db, $user->id, $baseline, 'after ForecastQuery::forUser scenario read');

    /** @var ScenarioQuery $scenarioQuery */
    $scenarioQuery = $this->app->make(ScenarioQuery::class);
    $scenarios = $scenarioQuery->forUser($user);
    expect($scenarios)->toHaveCount(1);
    $mutations = $scenarioQuery->mutationsFor($scenarioId, $user);
    expect($mutations)->toHaveCount(4);
    sisAssertCounts($db, $user->id, $baseline, 'after ScenarioQuery reads');

    // Composes CardStatementQuery and forecast_shortfall_windows — substrate reads.
    /** @var ForecastHighlightsQuery $highlights */
    $highlights = $this->app->make(ForecastHighlightsQuery::class);
    $highlights->forUser($user);
    sisAssertCounts($db, $user->id, $baseline, 'after ForecastHighlightsQuery::forUser');

    // Cascade-on-delete wipes the scenario, its mutations, runs and shortfall
    // windows; the transaction substrate must still not move.
    $delete = $this->app->make(DeleteScenario::class);
    ($delete)($scenarioId, $user);
    sisAssertCounts($db, $user->id, $baseline, 'after DeleteScenario');
    expect($db->connection()->table('forecast_scenarios')->where('user_id', $user->id)->count())->toBe(0);
    expect($db->connection()->table('forecast_scenario_mutations')->where('user_id', $user->id)->count())->toBe(0);

    CarbonImmutable::setTestNow();
});

it('isolates cross-user scenario activity from another users row counts', function (): void {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $seedA = sisSeedSubstrate($db);
    $seedB = sisSeedSubstrate($db);
    /** @var User $userA */
    $userA = $seedA['user'];
    /** @var User $userB */
    $userB = $seedB['user'];
    /** @var array<string, int> $baselineB */
    $baselineB = $seedB['baseline'];

    $create = $this->app->make(CreateScenario::class);
    $scenarioId = ($create)($userA, 'A scenario');

    $add = $this->app->make(AddScenarioMutation::class);
    ($add)($scenarioId, $userA, 'cancel_series', new CancelSeriesPayload(seriesId: $seedA['seriesIds'][0]));
    ($add)($scenarioId, $userA, 'add_one_off', new AddOneOffPayload(
        date: '2026-05-20',
        amountMinor: -5000,
        currency: 'EUR',
        direction: 'expense',
    ));

    Bus::swap($this->app->make(Dispatcher::class));
    Bus::dispatchSync(new ProjectForecastJob(userId: $userA->id, scenarioId: $scenarioId, horizonDays: 30));

    $delete = $this->app->make(DeleteScenario::class);
    ($delete)($scenarioId, $userA);

    sisAssertCounts($db, $userB->id, $baselineB, 'after user-A full lifecycle vs user-B substrate');

    CarbonImmutable::setTestNow();
});

it('confirms zero forbidden JOIN constructs at the source level (defensive grep complement)', function (): void {
    // The arch invariant scans every module; this narrows the same scan to
    // Forecasting, the module the boundary most directly protects.
    $forbiddenJoinAndTable = 0;
    $forecastingDir = base_path('Modules/Forecasting');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($forecastingDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match("/->(join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]forecast_scenario_mutations['\"]/", $stripped) === 1) {
            if (preg_match("/['\"](transactions|recurring_series_occurrences|chain_links|card_statements)['\"]/", $stripped) === 1) {
                $forbiddenJoinAndTable++;
            }
        }
    }
    expect($forbiddenJoinAndTable)->toBe(0);
});

it('ensures ScenarioQuery is the only Public-API path to the mutations table', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $seed = sisSeedSubstrate($db);
    /** @var User $user */
    $user = $seed['user'];

    $create = $this->app->make(CreateScenario::class);
    $scenarioId = ($create)($user, 'Public-surface read scenario');

    $add = $this->app->make(AddScenarioMutation::class);
    ($add)($scenarioId, $user, 'cancel_series', new CancelSeriesPayload(seriesId: $seed['seriesIds'][0]));

    /** @var ScenarioQuery $scenarioQuery */
    $scenarioQuery = $this->app->make(ScenarioQuery::class);
    $mutations = $scenarioQuery->mutationsFor($scenarioId, $user);
    expect($mutations)->toHaveCount(1);
    expect($mutations[0]->payload)->toBeInstanceOf(CancelSeriesPayload::class);
    expect($mutations[0]->payload->seriesId)->toBe($seed['seriesIds'][0]);
});
