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

// The corpus holds more fixtures than this file replays. Each name here is one
// of them, with the guard that does replay it: a fixture covered nowhere is the
// gap this pin exists to make visible, and the case below fails when a name
// drifts off the corpus or a new fixture arrives in neither list.
const DDCT_REPLAYED_ELSEWHERE = [
    'cadence-restructure' => 'Modules/DriftAlerts/tests/Feature/TheDriftCorpusWasNeverFedToTheEvaluatorTest.php',
    'exactly-at-threshold' => 'Modules/DriftAlerts/tests/Feature/TheDriftCorpusWasNeverFedToTheEvaluatorTest.php',
    'mixed-currency-within-series' => 'Modules/DriftAlerts/tests/Feature/TheDriftCorpusWasNeverFedToTheEvaluatorTest.php',
    'sign-flip-refund' => 'Modules/DriftAlerts/tests/Feature/TheDriftCorpusWasNeverFedToTheEvaluatorTest.php',
    'sub-five-global-threshold' => 'Modules/DriftAlerts/tests/Feature/TheDriftCorpusWasNeverFedToTheEvaluatorTest.php',
];

/** @return list<string> every fixture name the corpus directory holds */
function ddctCorpusNames(): array
{
    $names = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob(base_path('Modules/DriftAlerts/tests/fixtures/drift-corpus/*.php')) ?: [],
    );

    sort($names);

    return array_values($names);
}

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
        'snooze-expiry-revival' => ['snooze-expiry-revival', 'revival'],
    ];
}

function ddctUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
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
        'kind' => 'asn',
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
        // A series with snoozed_until in the future is in state='snoozed' by
        // construction, and the evaluator filters on state — so the corpus's
        // "excludes snoozed series" intent needs both columns set.
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

// The multi-drift fixture depends on this queueing more than one alert.
function ddctEvaluateAllOccurrencePairs(DatabaseManager $db, DriftEvaluator $evaluator, int $seriesId, User $user): void
{
    $rows = $db->connection()->table('recurring_series_occurrences')
        ->where('recurring_series_id', $seriesId)
        ->where('user_id', $user->id)
        ->orderBy('observed_at')
        ->orderBy('id')
        ->get(['id']);

    // Simulate "the i-th occurrence has just arrived" by temporarily deleting
    // every later occurrence, evaluating, then restoring them — the evaluator
    // always reads the two most recent rows for the series.
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
        $deleted = [];
        for ($j = $i + 1; $j < $count; $j++) {
            $row = $allRows[$j];
            $db->connection()->table('recurring_series_occurrences')
                ->where('id', $row['id'])
                ->delete();
            $deleted[] = $row;
        }

        $evaluator->evaluateForSeries($seriesId, $user);

        foreach ($deleted as $row) {
            $db->connection()->table('recurring_series_occurrences')->insert($row);
        }
    }
}

it('runs the drift evaluator against each pinned corpus scenario and produces the documented alert counts', function (string $fixtureName, int|string $expectedAlertCount): void {
    CarbonImmutable::setTestNow('2026-05-19 12:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = ddctUser('ddct-'.$fixtureName);
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
        expect($actualCount)->toBeGreaterThanOrEqual(
            3,
            $fixtureName.' is a volatile series and has to raise an alert on more than one arrival; it raised '.$actualCount.'.'
        );
    } elseif ($expectedAlertCount === 'revival') {
        // The fixture's 3× -999 → 3× -1149 is a ~15% drift against the 5% global
        // threshold, so exactly one alert exists to snooze and revive.
        expect($actualCount)->toBe(1, $fixtureName.' must raise exactly one alert for the snooze-and-revive path to have something to act on.');

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
        expect($revived->state)->toBe('open', 'A snooze whose date has passed left the alert snoozed, so the reader is never told again.');
        expect($revived->snoozed_until)->toBeNull('The revived alert still carries the lapsed snooze date, so the next pass re-revives it forever.');

        $revivalTransition = DriftAlertTransition::query()
            ->where('drift_alert_id', $detected->id)
            ->where('transition_reason', 'detector_revived_snooze')
            ->first();
        expect($revivalTransition)->not->toBeNull('The revival wrote no transition row, so the audit trail says the alert reopened itself.');
        expect($revivalTransition?->from_state)->toBe('snoozed', 'The revival transition does not record the state it revived from.');
        expect($revivalTransition?->to_state)->toBe('open', 'The revival transition does not record the state it revived into.');
        expect($revivalTransition?->actor)->toBe('detector', 'The revival is attributed to somebody other than the detector that performed it.');

        CarbonImmutable::setTestNow();

        return;
    } else {
        expect($actualCount)->toBe(
            $expectedAlertCount,
            $fixtureName.' expects '.$expectedAlertCount.' alert(s) and the evaluator raised '.$actualCount.'.'
        );
    }

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
            expect($hit)->toBeTrue(
                $fixtureName.' expects an alert of '.$expectedDelta.' '.$expectedCurrency.' and no row carries that pair.'
            );
        }
        if (isset($firstExpected['threshold_percent_used'])) {
            $expectedThreshold = (int) $firstExpected['threshold_percent_used'];
            $row = DriftAlert::query()
                ->where('user_id', $user->id)
                ->where('recurring_series_id', $seriesId)
                ->first();
            expect($row?->threshold_percent_used)->toBe(
                $expectedThreshold,
                $fixtureName.' was judged against a different threshold than the one it declares.'
            );
            if (isset($firstExpected['threshold_source'])) {
                expect($row?->threshold_source)->toBe(
                    (string) $firstExpected['threshold_source'],
                    $fixtureName.' records the threshold as coming from somewhere other than where it declares.'
                );
            }
        }
    }

    CarbonImmutable::setTestNow();
})->with(ddctFixtureExpectations());

// The dataset above is a hand-maintained list against a directory that grows.
// Read one way it is fine — every name in it is a file — and the direction that
// costs something is the other: a fixture added to the corpus and to neither
// list runs nowhere, and this file's name still promises the corpus.
it('accounts for every fixture the drift corpus holds', function (): void {
    $corpus = ddctCorpusNames();

    expect(count($corpus))->toBeGreaterThan(
        10,
        'The corpus directory came back all but empty, so the accounting below compares nothing.'
    );

    $replayedHere = array_keys(ddctFixtureExpectations());

    $unaccounted = array_values(array_diff(
        $corpus,
        $replayedHere,
        array_keys(DDCT_REPLAYED_ELSEWHERE),
    ));

    $missingFile = array_values(array_diff(
        [...$replayedHere, ...array_keys(DDCT_REPLAYED_ELSEWHERE)],
        $corpus,
    ));

    expect($unaccounted)->toBe([], implode("\n  ", [
        'These corpus fixtures are replayed by nothing this file knows about:',
        ...$unaccounted,
        '',
        'A fixture nobody feeds to the evaluator is a scenario somebody wrote down and',
        'no build ever runs. Add it to ddctFixtureExpectations() with the alert count it',
        'must produce, or name in DDCT_REPLAYED_ELSEWHERE the guard that does replay it.',
    ]));

    expect($missingFile)->toBe([], implode("\n  ", [
        'These names are expected here and the corpus no longer holds them:',
        ...$missingFile,
        '',
        'A renamed or deleted fixture leaves a row in the dataset that requires a file',
        'nothing writes, and the entry reads as coverage of a scenario that is gone.',
    ]));
});

it('names a real guard for every fixture it hands off', function (): void {
    foreach (DDCT_REPLAYED_ELSEWHERE as $fixture => $guard) {
        expect(is_file(base_path($guard)))->toBeTrue(
            $fixture.' is handed off to '.$guard.', which is not a file. A hand-off to a guard that does not '.
            'exist is the same as no coverage, written so that it reads as coverage.'
        );
    }
});
