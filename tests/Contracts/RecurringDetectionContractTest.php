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

/*
 * Recurring detection end-to-end contract.
 *
 * Loads each synthesised fixture, seeds the matching transactions,
 * runs the sweep job synchronously over both the expense AND income
 * detectors, and asserts each detector produces the expected per-
 * direction series count per fixture.
 */

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

function rdctUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
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
    $user = rdctUser('rdct-'.$fixtureName.'@diederik.test');
    // Widen the detection window so a fixture that places its earliest
    // occurrence beyond the 18-month default (e.g. yearly-domain at
    // 2024-06-12) still produces every documented occurrence inside
    // the look-back window.
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

    expect($actualExpense)->toBe($expectedExpenseSeriesCount);
    expect($actualIncome)->toBe($expectedIncomeSeriesCount);

    CarbonImmutable::setTestNow();
})->with(rdctExpenseFixtureExpectations());

it('produces no duplicate series rows when the full fixture corpus runs twice through DetectRecurringSeriesJob (full-corpus idempotency)', function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rdctUser('rdct-full-corpus@diederik.test');
    $user->recurring_detection_window_months = 36;
    $user->save();

    $fixtureNames = array_keys(rdctExpenseFixtureExpectations());
    // Plus the empty real-export stub — its empty transactions list is
    // a no-op for the detector but the load path must succeed so the
    // contract covers the file lookup wiring.
    $fixtureNames[] = 'anonymised-asn-ics-6mo';

    // One account + import-run per fixture so the
    // (user_id, account_id, posted_at, booked_at, amount_minor,
    // currency, counterparty_normalized) unique constraint on
    // `transactions` does not collide across fixtures that share
    // counterparty names (e.g. stable + drifting spotify both seed a
    // 2024-11-15 spotify row).
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

    // Re-running the sweep against the same transaction set must NOT
    // create additional series rows. The detector keys on
    // (user_id, direction, cluster_key, latest_currency) so duplicate
    // detection collapses cleanly.
    expect($afterSecondRun)->toBe($afterFirstRun);

    // Sanity check: the corpus produces a non-zero series set. The
    // exact count is not asserted here — merging every fixture into a
    // single user/account namespace can legitimately collapse series
    // that share a counterparty across fixtures (e.g. the stable +
    // drifting Spotify rows cluster into one series under the
    // detector's cluster_key + currency + cadence key). The
    // per-fixture variant of this test (the one driven by
    // rdctExpenseFixtureExpectations) covers exact counts; this slice
    // covers the load-bearing idempotency-on-re-run contract.
    expect($afterFirstRun)->toBeGreaterThan(0);

    CarbonImmutable::setTestNow();
})->group('full-corpus-idempotency');
