<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;

/**
 * @return array<string, array{0: string, 1: int, 2: int}>
 *                                                         [fixtureName, expectedExpenseSeriesCount, expectedIncomeSeriesCount]
 */
function rdctExpenseFixtureExpectations(): array
{
    return [
        'stable-monthly-spotify' => ['stable-monthly-spotify', 1, 0],
        'drifting-monthly-spotify' => ['drifting-monthly-spotify', 1, 0],
        'quarterly-insurance' => ['quarterly-insurance', 1, 0],
        'yearly-domain' => ['yearly-domain', 1, 0],
        'weekly-streaming' => ['weekly-streaming', 1, 0],
        'irregular-gym-must-not-cluster' => ['irregular-gym-must-not-cluster', 0, 0],
        'missing-month-subscription' => ['missing-month-subscription', 1, 0],
        'mixed-currency-netflix-usd' => ['mixed-currency-netflix-usd', 1, 0],
        'variable-amount-beyond-tolerance-bills' => ['variable-amount-beyond-tolerance-bills', 0, 0],
        'monthly-salary' => ['monthly-salary', 0, 1],
        'two-employer-salary' => ['two-employer-salary', 0, 2],
    ];
}

function rdctUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rdctAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'rdct '.$slug,
        'slug' => $slug,
        'kind' => 'asn',
        'iban' => 'NL00RDCT'.str_pad(substr($slug, 0, 8), 10, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function rdctImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rdct.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
}

function rdctSeedFixture(
    DatabaseManager $db,
    User $user,
    Account $account,
    ImportRun $run,
    string $fixtureName,
): void {
    $fixturePath = base_path('Modules/Recurring/tests/fixtures/synthesised/'.$fixtureName.'.php');
    if (! file_exists($fixturePath)) {
        $fixturePath = base_path('Modules/Recurring/tests/fixtures/real/'.$fixtureName.'.php');
    }
    $fixture = require $fixturePath;
    foreach ($fixture['transactions'] as $i => $row) {
        $iban = isset($row['counterparty_iban']) ? (string) $row['counterparty_iban'] : null;
        if ($iban === '') {
            $iban = null;
        }
        $db->connection()->table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => (string) $row['type'],
            'posted_at' => (string) $row['posted_at'],
            'booked_at' => $row['posted_at'].' 12:00:00',
            'value_date' => (string) $row['posted_at'],
            'amount_minor' => (int) $row['original_amount_minor'],
            'currency' => (string) $row['original_currency'],
            'settled_amount_minor' => (int) $row['amount_minor'],
            'settled_currency' => (string) $row['currency'],
            'counterparty_name' => (string) $row['counterparty_normalized'],
            'counterparty_iban' => $iban,
            'counterparty_normalized' => (string) $row['counterparty_normalized'],
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $i + 1,
            'fingerprint' => str_pad($fixtureName.'-'.$i, 64, 'f', STR_PAD_LEFT),
            'fingerprint_version' => 3,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
    }
}

it('asserts the expected expense + income series counts for each synthesised fixture', function (string $fixtureName, int $expectedExpenseSeriesCount, int $expectedIncomeSeriesCount): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rdctUser('rdct-'.$fixtureName);
    // yearly-domain's earliest occurrence (2024-06-12) sits beyond the 18-month
    // default look-back, so the window is widened for every fixture.
    $user->recurring_detection_window_months = 36;
    $user->save();
    $account = rdctAccount($user, 'rdct-'.substr($fixtureName, 0, 8));
    $run = rdctImportRun($user, str_pad($fixtureName, 64, 'g', STR_PAD_LEFT));

    rdctSeedFixture($db, $user, $account, $run, $fixtureName);

    /** @var ExpenseSeriesDetector $expense */
    $expense = app(ExpenseSeriesDetector::class);
    /** @var IncomeSeriesDetector $income */
    $income = app(IncomeSeriesDetector::class);
    /** @var Clock $clock */
    $clock = app(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = app(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($user->id))->handle($db, $clock, [$expense, $income], $machine);

    $actualExpense = RecurringSeries::query()
        ->where('user_id', $user->id)
        ->where('direction', 'expense')
        ->count();
    $actualIncome = RecurringSeries::query()
        ->where('user_id', $user->id)
        ->where('direction', 'income')
        ->count();

    expect($actualExpense)->toBe(
        $expectedExpenseSeriesCount,
        "{$fixtureName}: the expense detector clustered {$actualExpense} series where this fixture is built to yield {$expectedExpenseSeriesCount}. Too few and a subscription the reader pays every month stops being watched for drift; too many and one payment is announced as several.",
    );
    expect($actualIncome)->toBe(
        $expectedIncomeSeriesCount,
        "{$fixtureName}: the income detector clustered {$actualIncome} series where this fixture is built to yield {$expectedIncomeSeriesCount}. A salary read as two series doubles every forecast that reaches for it.",
    );

    CarbonImmutable::setTestNow();
})->with(rdctExpenseFixtureExpectations());

// The table above is a hand-written list, and a fixture nobody added to it is a
// case the detector is never asked about while the corpus reads as covered.
it('states an expectation for every synthesised fixture on disk', function (): void {
    $onDisk = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob(base_path('Modules/Recurring/tests/fixtures/synthesised/*.php')) ?: [],
    );
    sort($onDisk);

    $expected = array_keys(rdctExpenseFixtureExpectations());
    sort($expected);

    expect(count($onDisk))->toBeGreaterThan(5, 'The fixture glob matched almost nothing, so the comparison below is vacuous.');

    expect($expected)->toBe(
        $onDisk,
        'The expectation table and the synthesised fixture corpus have drifted apart. A fixture on disk with '
        .'no row above is a detector behaviour nobody asserts; a row above with no fixture is a case that '
        ."fails on a missing file rather than on the detector.\n  on disk:   ".implode(', ', $onDisk)
        ."\n  expected:  ".implode(', ', $expected),
    );
});

it('produces no duplicate series rows when the full fixture corpus runs twice through DetectRecurringSeriesJob (full-corpus idempotency)', function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rdctUser('rdct-full-corpus');
    $user->recurring_detection_window_months = 36;
    $user->save();

    $fixtureNames = array_keys(rdctExpenseFixtureExpectations());
    // The empty real-export stub is a detector no-op, but its load path has to
    // succeed so the fixture lookup wiring is covered.
    $fixtureNames[] = 'anonymised-asn-ics-6mo';

    // One account + import-run per fixture: two fixtures sharing a counterparty
    // (stable and drifting spotify both seed a 2024-11-15 row) would otherwise
    // collide on the transactions unique constraint over
    // (user_id, account_id, posted_at, booked_at, amount_minor, currency, ...).
    foreach ($fixtureNames as $idx => $fixtureName) {
        $slug = 'fc-'.substr(md5($fixtureName), 0, 6);
        $account = rdctAccount($user, $slug);
        $run = rdctImportRun($user, str_pad('fc-'.$idx.'-'.$fixtureName, 64, 'y', STR_PAD_LEFT));
        rdctSeedFixture($db, $user, $account, $run, $fixtureName);
    }

    /** @var ExpenseSeriesDetector $expense */
    $expense = app(ExpenseSeriesDetector::class);
    /** @var IncomeSeriesDetector $income */
    $income = app(IncomeSeriesDetector::class);
    /** @var Clock $clock */
    $clock = app(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = app(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($user->id))->handle($db, $clock, [$expense, $income], $machine);
    $afterFirstRun = RecurringSeries::query()->where('user_id', $user->id)->count();

    (new DetectRecurringSeriesJob($user->id))->handle($db, $clock, [$expense, $income], $machine);
    $afterSecondRun = RecurringSeries::query()->where('user_id', $user->id)->count();

    // The detector keys on (user_id, direction, cluster_key, latest_currency), so
    // a re-run collapses onto the same rows.
    expect($afterSecondRun)->toBe(
        $afterFirstRun,
        "A second detection run over the same ledger left {$afterSecondRun} series where the first left {$afterFirstRun}. Detection runs on every import, so a run that adds rows duplicates every series the reader already approved.",
    );

    // No exact count: merging every fixture into one user namespace legitimately
    // collapses series that share a counterparty across fixtures. The per-fixture
    // test above covers exact counts; this one covers idempotency on re-run.
    expect($afterFirstRun)->toBeGreaterThan(3, 'The whole fixture corpus detected almost no series, so the equality above is two zeroes agreeing rather than a re-run being absorbed.');

    CarbonImmutable::setTestNow();
})->group('full-corpus-idempotency');
