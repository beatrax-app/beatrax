<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Internal\Jobs\RevivedExpiredDriftSnoozesJob;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

/*
 * Drift detection end-to-end contract.
 *
 * Loads each fixture from Modules/DriftAlerts/tests/fixtures/drift-corpus,
 * seeds the matching User + Account + ImportRun + Transaction rows + a
 * single recurring_series row with its two-or-more occurrences, then
 * runs DriftEvaluator over each occurrence pair and asserts the
 * resulting drift_alerts row count + key column values against the
 * fixture's `expected.alerts` array.
 *
 * The "snooze-expiry-revival" fixture exercises the Wave 4 hourly
 * revival job: the contract test seeds the detected drift, transitions
 * it through snoozed (snoozed_until in the past), then dispatches
 * `RevivedExpiredDriftSnoozesJob` and asserts the post-revival shape
 * declared by the fixture's `expected.transitions` block.
 *
 * The "volatile-series" fixture asserts a >=3 lower bound on alert
 * count rather than an exact figure (the fixture's `expected.alerts`
 * declares "multiple" semantics).
 */

/**
 * @return array<string, array{0: string, 1: int|string}>
 */
function ddctFixtureExpectations(): array
{
    return [
        'stable-monthly' => ['stable-monthly', 0],
        'small-drift-below-threshold' => ['small-drift-below-threshold', 0],
        'large-drift-above-threshold' => ['large-drift-above-threshold', 1],
        'income-raise' => ['income-raise', 0],
        'income-raise-large' => ['income-raise-large', 1],
        'income-cut' => ['income-cut', 1],
        'fx-only-swing' => ['fx-only-swing', 0],
        'cadence-changed' => ['cadence-changed', 1],
        'multi-drift' => ['multi-drift', 2],
        'per-series-override' => ['per-series-override', 0],
        'prior-null' => ['prior-null', 0],
        'prior-zero' => ['prior-zero', 0],
        'volatile-series' => ['volatile-series', 'multiple'],
        'volatile-with-override' => ['volatile-with-override', 0],
        'weekly-cadence' => ['weekly-cadence', 1],
        'quarterly-cadence' => ['quarterly-cadence', 1],
        'yearly-cadence' => ['yearly-cadence', 1],
        'mixed-currency-stable-usd' => ['mixed-currency-stable-usd', 0],
        'mixed-currency-real-usd-drift' => ['mixed-currency-real-usd-drift', 1],
        'pending-state-ignored' => ['pending-state-ignored', 0],
        'rejected-state-ignored' => ['rejected-state-ignored', 0],
        'snoozed-at-series-level-ignored' => ['snoozed-at-series-level-ignored', 0],
        'irregular-cadence-ignored' => ['irregular-cadence-ignored', 0],
        // Wave 4 revival flow — Plan 09-05 (the test detects the drift,
        // transitions through snoozed-with-past-timestamp, then dispatches
        // RevivedExpiredDriftSnoozesJob and asserts the revival).
        'snooze-expiry-revival' => ['snooze-expiry-revival', 'revival'],
    ];
}

function ddctUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function ddctAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ddct '.$slug,
        'slug' => $slug,
        'kind' => 'asn_bank',
        'iban' => 'NL00DDCT'.str_pad(substr($slug, 0, 8), 10, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function ddctImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ddct.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-05-19 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * Seeds the fixture's transactions + a recurring_series row + the
 * matching recurring_series_occurrences. Returns the seeded series id.
 *
 * The series's state, cadence, currency, and per-series threshold
 * override (when set on the fixture's `expected` block) are honored so
 * the evaluator sees the same shape the corpus describes.
 *
 * @param  array{transactions: array<int, array<string, mixed>>, expected: array<string, mixed>}  $fixture
 */
function ddctSeedFixture(DatabaseManager $db, User $user, Account $account, ImportRun $run, array $fixture, string $name): int
{
    $now = '2026-05-19 12:00:00';

    $txCount = count($fixture['transactions']);
    $firstRow = $txCount > 0 ? $fixture['transactions'][0] : null;
    $lastRow = $txCount > 0 ? $fixture['transactions'][$txCount - 1] : null;

    $direction = is_array($firstRow) && isset($firstRow['type']) && $firstRow['type'] === 'income' ? 'income' : 'expense';
    /** @var array<string, mixed> $expected */
    $expected = $fixture['expected'];
    $seriesState = isset($expected['series_state']) && is_string($expected['series_state']) ? $expected['series_state'] : 'approved';
    $seriesCadence = isset($expected['series_cadence']) && is_string($expected['series_cadence']) ? $expected['series_cadence'] : 'monthly';
    $seriesCurrency = isset($expected['series_currency']) && is_string($expected['series_currency'])
        ? $expected['series_currency']
        : (is_array($lastRow) && isset($lastRow['original_currency']) ? (string) $lastRow['original_currency'] : 'EUR');
    $latestAmountMinor = is_array($lastRow) && isset($lastRow['original_amount_minor']) ? (int) $lastRow['original_amount_minor'] : 0;

    $seriesRow = [
        'user_id' => $user->id,
        'direction' => $direction,
        'detected_name' => 'fixture-'.$name,
        'state' => $seriesState,
        'cadence' => $seriesCadence,
        'latest_amount_minor' => $latestAmountMinor,
        'latest_currency' => $seriesCurrency,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'ddct|'.$name,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    if (isset($expected['series_drift_threshold_percent']) && is_numeric($expected['series_drift_threshold_percent'])) {
        $seriesRow['drift_threshold_percent'] = (int) $expected['series_drift_threshold_percent'];
    }
    if (isset($expected['series_snoozed']) && $expected['series_snoozed'] === true) {
        // The fixture's intent: "Recurring excludes snoozed series" so
        // the evaluator must never see it. Phase 8's state machine
        // makes 'snoozed' a distinct state — a series with snoozed_until
        // in the future is in state='snoozed' by construction. Use the
        // canonical Phase 8 shape so the evaluator's state filter
        // catches it.
        $seriesRow['state'] = 'snoozed';
        $seriesRow['snoozed_until'] = '2099-01-01 00:00:00';
    }

    $seriesId = $db->connection()->table('recurring_series')->insertGetId($seriesRow);

    foreach ($fixture['transactions'] as $i => $row) {
        /** @var array<string, mixed> $row */
        $originalAmount = isset($row['original_amount_minor']) ? (int) $row['original_amount_minor'] : 0;
        $originalCurrency = isset($row['original_currency']) ? (string) $row['original_currency'] : 'EUR';
        $settledAmount = isset($row['amount_minor']) ? (int) $row['amount_minor'] : 0;
        $settledCurrency = isset($row['currency']) ? (string) $row['currency'] : 'EUR';
        $postedAt = isset($row['posted_at']) ? (string) $row['posted_at'] : '2026-05-15';
        $type = isset($row['type']) ? (string) $row['type'] : 'expense';
        $counterparty = isset($row['counterparty_normalized']) ? (string) $row['counterparty_normalized'] : 'fixture';

        $txId = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_run_id' => $run->id,
            'fingerprint' => str_pad('ddct-'.$name.'-'.$i, 64, 'f', STR_PAD_LEFT),
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'amount_minor' => $settledAmount,
            'currency' => $settledCurrency,
            'settled_amount_minor' => $settledAmount,
            'settled_currency' => $settledCurrency,
            'counterparty_normalized' => $counterparty,
            'counterparty_name' => strtoupper($counterparty),
            'normalization_version' => 3,
            'description' => 'ddct fixture',
            'type' => $type,
            'source_format' => 'asn-csv',
            'source_row_index' => $i + 1,
            'fingerprint_version' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $db->connection()->table('recurring_series_occurrences')->insertGetId([
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'transaction_id' => $txId,
            'observed_at' => $postedAt,
            'observed_amount_minor' => $originalAmount,
            'observed_currency' => $originalCurrency,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return $seriesId;
}

/**
 * Walks every pair (prior, latest) in observation order and invokes
 * DriftEvaluator. Each pair is a candidate; the multi-drift fixture
 * relies on this to queue more than one alert.
 */
function ddctEvaluateAllOccurrencePairs(DatabaseManager $db, DriftEvaluator $evaluator, int $seriesId, User $user): void
{
    $rows = $db->connection()->table('recurring_series_occurrences')
        ->where('recurring_series_id', $seriesId)
        ->where('user_id', $user->id)
        ->orderBy('observed_at')
        ->orderBy('id')
        ->get(['id']);

    // For every pair (i, i+1), simulate "the i+1 occurrence has just
    // arrived" by deleting the future occurrences (i+2..n) before
    // calling the evaluator, then restoring them afterward. Cleaner
    // path here is to walk pair-by-pair and trim — but for the
    // contract's purpose we only need to ensure the evaluator is
    // invoked once per terminal pair. The simplest faithful semantic:
    // delete forward occurrences in a copy, evaluate, restore.
    //
    // SQLite refuses to delete + re-insert because of the
    // recurring_series_occurrences UNIQUE constraint on
    // (recurring_series_id, transaction_id). The trick is to evaluate
    // for the FULL ordered series with progressive "horizon" pointers.
    //
    // Implementation: re-evaluate from a window pointer. We drop
    // newer-than-pointer rows, call evaluator, then restore them.
    $allRows = [];
    foreach ($rows as $row) {
        $orig = $db->connection()->table('recurring_series_occurrences')
            ->where('id', $row->id)
            ->first();
        if ($orig !== null) {
            $allRows[] = (array) $orig;
        }
    }
    $count = count($allRows);

    if ($count < 2) {
        return;
    }

    for ($i = 1; $i < $count; $i++) {
        // Delete every occurrence whose observed_at is strictly after
        // the i-th row's observed_at (or same observed_at but greater
        // id). Then run evaluator. Then restore.
        $cutoff = $allRows[$i];
        $deleted = [];
        for ($j = $i + 1; $j < $count; $j++) {
            $row = $allRows[$j];
            $db->connection()->table('recurring_series_occurrences')
                ->where('id', $row['id'])
                ->delete();
            $deleted[] = $row;
        }

        $evaluator->evaluateForSeries($seriesId, $user);

        // Restore deleted rows in original order.
        foreach ($deleted as $row) {
            $db->connection()->table('recurring_series_occurrences')->insert($row);
        }

        unset($cutoff);
    }
}

it('runs the drift evaluator against every fixture in the 24-scenario corpus and produces the documented alert counts', function (string $fixtureName, int|string $expectedAlertCount): void {
    CarbonImmutable::setTestNow('2026-05-19 12:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = ddctUser('ddct-'.$fixtureName.'@diederik.test');
    $account = ddctAccount($user, 'ddct-'.substr(md5($fixtureName), 0, 8));
    $run = ddctImportRun($user, str_pad('ddct-'.$fixtureName, 64, 'g', STR_PAD_LEFT));

    $fixturePath = base_path('Modules/DriftAlerts/tests/fixtures/drift-corpus/'.$fixtureName.'.php');
    /** @var array{transactions: array<int, array<string, mixed>>, expected: array<string, mixed>} $fixture */
    $fixture = require $fixturePath;

    $seriesId = ddctSeedFixture($db, $user, $account, $run, $fixture, $fixtureName);

    /** @var DriftEvaluator $evaluator */
    $evaluator = app(DriftEvaluator::class);
    ddctEvaluateAllOccurrencePairs($db, $evaluator, $seriesId, $user);

    $actualCount = DriftAlert::query()
        ->where('user_id', $user->id)
        ->where('recurring_series_id', $seriesId)
        ->count();

    if ($expectedAlertCount === 'multiple') {
        expect($actualCount)->toBeGreaterThanOrEqual(3);
    } elseif ($expectedAlertCount === 'revival') {
        // Wave 4 revival flow: the detected alert is snoozed with a
        // past snoozed_until, then the hourly RevivedExpiredDriftSnoozesJob
        // flips it back to 'open' with the audit transition the fixture
        // declares. The fixture's transactions produce exactly one drift
        // alert (3× -999 → 3× -1149, a ~15% drift > 5% global threshold).
        expect($actualCount)->toBe(1);

        /** @var DriftAlert $detected */
        $detected = DriftAlert::query()
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->firstOrFail();

        /** @var DriftAlertStateMachine $stateMachine */
        $stateMachine = app(DriftAlertStateMachine::class);
        $stateMachine->transition(
            $detected,
            'snoozed',
            'user_action',
            'user',
            'snoozed_until=2026-05-19 06:00:00',
            ['snoozed_until' => '2026-05-19 06:00:00'],
        );

        /** @var RevivedExpiredDriftSnoozesJob $job */
        $job = app(RevivedExpiredDriftSnoozesJob::class);
        $job->handle($db, $stateMachine, app(Clock::class));

        $revived = DriftAlert::query()->findOrFail($detected->id);
        expect($revived->state)->toBe('open');
        expect($revived->snoozed_until)->toBeNull();

        $revivalTransition = DriftAlertTransition::query()
            ->where('drift_alert_id', $detected->id)
            ->where('transition_reason', 'detector_revived_snooze')
            ->first();
        expect($revivalTransition)->not->toBeNull();
        expect($revivalTransition?->from_state)->toBe('snoozed');
        expect($revivalTransition?->to_state)->toBe('open');
        expect($revivalTransition?->actor)->toBe('detector');

        CarbonImmutable::setTestNow();

        return;
    } else {
        expect($actualCount)->toBe($expectedAlertCount);
    }

    // For drift-positive fixtures, check the first expected.alerts entry
    // against at least one persisted row (by signed delta + currency).
    if (is_int($expectedAlertCount) && $expectedAlertCount > 0 && isset($fixture['expected']['alerts']) && is_array($fixture['expected']['alerts']) && $fixture['expected']['alerts'] !== []) {
        /** @var array<string, mixed> $firstExpected */
        $firstExpected = $fixture['expected']['alerts'][0];
        if (isset($firstExpected['delta_minor'], $firstExpected['currency'])) {
            $expectedDelta = (int) $firstExpected['delta_minor'];
            $expectedCurrency = (string) $firstExpected['currency'];
            $hit = DriftAlert::query()
                ->where('user_id', $user->id)
                ->where('recurring_series_id', $seriesId)
                ->where('delta_minor', $expectedDelta)
                ->where('currency', $expectedCurrency)
                ->exists();
            expect($hit)->toBeTrue();
        }
        if (isset($firstExpected['threshold_percent_used'])) {
            $expectedThreshold = (int) $firstExpected['threshold_percent_used'];
            $row = DriftAlert::query()
                ->where('user_id', $user->id)
                ->where('recurring_series_id', $seriesId)
                ->first();
            expect($row?->threshold_percent_used)->toBe($expectedThreshold);
            if (isset($firstExpected['threshold_source'])) {
                expect($row?->threshold_source)->toBe((string) $firstExpected['threshold_source']);
            }
        }
    }

    CarbonImmutable::setTestNow();
})->with(ddctFixtureExpectations());
