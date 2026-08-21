<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;
use Modules\Recurring\Models\RecurringSeriesTransition;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;

function drsjUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function drsjAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'drsj '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00DRSJ'.str_pad(substr($slug, 0, 8), 10, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function drsjImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/drsj.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
}

function drsjSeedTx(
    DatabaseManager $db,
    User $user,
    Account $account,
    ImportRun $run,
    string $postedAt,
    int $nativeAmountMinor,
    string $nativeCurrency,
    int $settledAmountMinor,
    string $settledCurrency,
    string $counterpartyNormalized,
    string $type,
    int $rowIndex,
    string $fingerprintSeed,
): int {
    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $nativeAmountMinor,
        'currency' => $nativeCurrency,
        'settled_amount_minor' => $settledAmountMinor,
        'settled_currency' => $settledCurrency,
        'counterparty_name' => $counterpartyNormalized,
        'counterparty_normalized' => $counterpartyNormalized,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'd', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

// The fixture's original_* pair becomes the transaction's native amount and its
// amount_minor/currency becomes the settled one. That crossover is deliberate:
// it is how the schema models USD settling to EUR.
function drsjSeedFixture(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $fixtureName): int
{
    $fixture = require base_path('Modules/Recurring/tests/fixtures/synthesised/'.$fixtureName.'.php');
    $count = 0;
    foreach ($fixture['transactions'] as $i => $row) {
        drsjSeedTx(
            $db,
            $user,
            $account,
            $run,
            $row['posted_at'],
            (int) $row['original_amount_minor'],
            (string) $row['original_currency'],
            (int) $row['amount_minor'],
            (string) $row['currency'],
            (string) $row['counterparty_normalized'],
            (string) $row['type'],
            $i + 1,
            $fixtureName.'-'.$i,
        );
        $count++;
    }

    return $count;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = drsjUser('detect-recurring');
    $this->account = drsjAccount($this->user, 'drsj-asn');
    $this->run = drsjImportRun($this->user, str_repeat('e', 64));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('runs the job for the user and detects one stable monthly expense series', function (): void {
    // The 2-month default would clip everything but the newest occurrence.
    $this->user->recurring_detection_window_months = 36;
    $this->user->save();

    $seeded = drsjSeedFixture($this->db, $this->user, $this->account, $this->run, 'stable-monthly-spotify');
    expect($seeded)->toBeGreaterThan(0);

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    /** @var list<RecurringSeries> $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->get()->all();

    expect($series)->toHaveCount(1);
    expect($series[0]->direction)->toBe('expense');
    expect($series[0]->state)->toBe('pending');
    expect($series[0]->cadence)->toBe('monthly');
    expect($series[0]->detected_name)->toBe('spotify');

    $occurrences = RecurringSeriesOccurrence::query()
        ->where('recurring_series_id', $series[0]->id)
        ->count();
    expect($occurrences)->toBe(18);
})->group('expense-cluster');

it('clusters the drifting-monthly fixture as ONE series under the 25% variance tolerance', function (): void {
    // Past the 2-month default: the fixture spans a year and more.
    $this->user->recurring_detection_window_months = 36;
    $this->user->save();

    drsjSeedFixture($this->db, $this->user, $this->account, $this->run, 'drifting-monthly-spotify');

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    /** @var list<RecurringSeries> $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'expense')
        ->get()->all();

    expect($series)->toHaveCount(1);
    expect($series[0]->cadence)->toBe('monthly');
    expect($series[0]->latest_amount_minor)->toBe(-1149);
})->group('variance-tolerance');

it('fragments the variable-amount-beyond-tolerance fixture so no stable series survives', function (): void {
    drsjSeedFixture($this->db, $this->user, $this->account, $this->run, 'variable-amount-beyond-tolerance-bills');

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    /** @var list<RecurringSeries> $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'expense')
        ->get()->all();

    expect($series)->toHaveCount(0);
})->group('variance-exceeded');

it('skips the irregular-gym fixture (cadence=irregular = no candidate)', function (): void {
    drsjSeedFixture($this->db, $this->user, $this->account, $this->run, 'irregular-gym-must-not-cluster');

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    expect(RecurringSeries::query()->where('user_id', $this->user->id)->count())->toBe(0);
})->group('irregular-skipped');

it('is idempotent across re-runs (same series + occurrence row counts)', function (): void {
    drsjSeedFixture($this->db, $this->user, $this->account, $this->run, 'stable-monthly-spotify');

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    $job = new DetectRecurringSeriesJob($this->user->id);
    $job->handle($this->db, $clock, [$detector], $machine);
    $seriesCountAfter1 = RecurringSeries::query()->where('user_id', $this->user->id)->count();
    $occCountAfter1 = RecurringSeriesOccurrence::query()->where('user_id', $this->user->id)->count();

    $job->handle($this->db, $clock, [$detector], $machine);
    $seriesCountAfter2 = RecurringSeries::query()->where('user_id', $this->user->id)->count();
    $occCountAfter2 = RecurringSeriesOccurrence::query()->where('user_id', $this->user->id)->count();

    expect($seriesCountAfter2)->toBe($seriesCountAfter1);
    expect($occCountAfter2)->toBe($occCountAfter1);
})->group('idempotent-re-run');

it('flips a snoozed series back to pending when snoozed_until has elapsed and records the transition', function (): void {
    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'snoozed-probe',
        'state' => 'snoozed',
        'cadence' => 'monthly',
        'latest_amount_minor' => -999,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::snoozed-probe::eur::monthly',
        'snoozed_until' => '2026-05-17 11:00:00',
    ]);

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('pending');

    /** @var RecurringSeriesTransition $transition */
    $transition = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->where('transition_reason', 'snooze_expired')
        ->firstOrFail();
    expect($transition->actor)->toBe('detector');
    expect($transition->to_state)->toBe('pending');
})->group('snooze-expiry');

it('leaves a snoozed series alone when snoozed_until is still in the future', function (): void {
    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'snoozed-future',
        'state' => 'snoozed',
        'cadence' => 'monthly',
        'latest_amount_minor' => -999,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::snoozed-future::eur::monthly',
        'snoozed_until' => '2026-06-17 00:00:00',
    ]);

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('snoozed');

    $transitionCount = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->count();
    expect($transitionCount)->toBe(0);
})->group('snooze-future');

it('flips an approved series to cadence_changed and dispatches RecurringSeriesCadenceFlipped on a cadence flip', function (): void {
    Event::fake([RecurringSeriesCadenceFlipped::class]);

    // Past the 2-month default: the flip probe seeds back to 2024-08.
    $this->user->recurring_detection_window_months = 36;
    $this->user->save();

    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'cadence-flip-probe',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -8999,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::cadence-flip-probe::eur::quarterly',
    ]);

    // Quarterly spacing, so the detector infers a cadence change.
    $start = CarbonImmutable::parse('2024-08-15');
    for ($i = 0; $i < 6; $i++) {
        $date = $start->addDays($i * 90)->toDateString();
        drsjSeedTx(
            $this->db,
            $this->user,
            $this->account,
            $this->run,
            $date,
            -8999,
            'EUR',
            -8999,
            'EUR',
            'cadence-flip-probe',
            'expense',
            $i + 1,
            'flip-'.$i,
        );
    }

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('cadence_changed');
    expect($fresh->cadence)->toBe('quarterly');

    $transition = RecurringSeriesTransition::query()
        ->where('recurring_series_id', $series->id)
        ->where('transition_reason', 'detector_cadence_flip')
        ->first();
    expect($transition)->not->toBeNull();
    expect($transition?->actor)->toBe('detector');

    Event::assertDispatched(RecurringSeriesCadenceFlipped::class);
})->group('cadence-flip-flips-to-cadence_changed');

it('skips rejected clusters and never re-prompts them', function (): void {
    // The exact key the fixture would produce, so the detector must skip it.
    $cluster = 'expense::spotify::eur::monthly';
    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'spotify',
        'state' => 'rejected',
        'cadence' => 'monthly',
        'latest_amount_minor' => -999,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => $cluster,
    ]);

    drsjSeedFixture($this->db, $this->user, $this->account, $this->run, 'stable-monthly-spotify');

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($series->id);
    expect($fresh->state)->toBe('rejected');

    expect(RecurringSeries::query()->where('user_id', $this->user->id)->count())->toBe(1);
})->group('rejected-skipped');

it('clusters mixed-currency Netflix on the original USD amount, not settled EUR', function (): void {
    // Past the 2-month default: the fixture spans a year of charges.
    $this->user->recurring_detection_window_months = 36;
    $this->user->save();

    drsjSeedFixture($this->db, $this->user, $this->account, $this->run, 'mixed-currency-netflix-usd');

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    /** @var list<RecurringSeries> $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'expense')
        ->get()->all();

    expect($series)->toHaveCount(1);
    expect($series[0]->latest_currency)->toBe('USD');
    expect($series[0]->latest_amount_minor)->toBe(-1199);
})->group('original-currency-clustering');

it('respects the per-user recurring_detection_window_months when filtering transactions', function (): void {
    // Pinned explicitly so this isolates clipping from the model default.
    $this->user->recurring_detection_window_months = 18;
    $this->user->save();

    // Starts 2023-01, over three years before the pinned now — outside 18.
    $oldStart = CarbonImmutable::parse('2024-01-15')->subMonths(12);
    for ($i = 0; $i < 3; $i++) {
        drsjSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $oldStart->addMonths($i)->toDateString(),
            -999, 'EUR', -999, 'EUR',
            'old-spotify', 'expense', 100 + $i, 'old-'.$i,
        );
    }
    // Inside the window.
    $newStart = CarbonImmutable::parse('2026-02-15');
    for ($i = 0; $i < 3; $i++) {
        drsjSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $newStart->addMonths($i)->toDateString(),
            -1099, 'EUR', -1099, 'EUR',
            'new-spotify', 'expense', 200 + $i, 'new-'.$i,
        );
    }

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    $names = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->pluck('detected_name')->all();
    expect($names)->toContain('new-spotify');
    expect($names)->not->toContain('old-spotify');
})->group('window-filter');

it('uses container-tag dispatch — DetectRecurringSeriesJob receives the recurring.detector tag set', function (): void {
    /** @var iterable<SeriesDetector> $detectors */
    $detectors = $this->app->tagged('recurring.detector');
    $collected = [];
    foreach ($detectors as $detector) {
        $collected[] = $detector::class;
    }

    expect($collected)->toContain(ExpenseSeriesDetector::class);
})->group('container-tag');

it('does not detect income-type transactions when only the expense detector is wired', function (): void {
    drsjSeedFixture($this->db, $this->user, $this->account, $this->run, 'monthly-salary');

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($this->user->id))->handle($this->db, $clock, [$detector], $machine);

    $incomeSeries = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'income')
        ->count();
    expect($incomeSeries)->toBe(0);
})->group('income-not-yet');

it('DetectRecurringSeriesJob is ShouldBeUniqueUntilProcessing and locks via the LockStore helper', function (): void {
    $contents = file_get_contents(base_path('Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php'));
    expect($contents)->toContain('ShouldBeUniqueUntilProcessing');
    expect($contents)->toContain('LockStore::forUniqueJobs()');
})->group('job-shape');
