<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;

/*
 * Recurring detection end-to-end contract.
 *
 * Loads each Wave 0 expense-side fixture, seeds the matching
 * transactions, runs the sweep job synchronously, and asserts the
 * detector produces the expected expense-series count per fixture.
 *
 * Income-side fixtures stay skipped until Plan 04 ships the
 * IncomeSeriesDetector — flagged by the per-fixture
 * `skip_until_income_detector` marker in this contract's expectation
 * table.
 */

/**
 * @return array<string, array{0: string, 1: int}>
 */
function rdctExpenseFixtureExpectations(): array
{
    return [
        'stable-monthly-spotify' => ['stable-monthly-spotify', 1],
        'drifting-monthly-spotify' => ['drifting-monthly-spotify', 1],
        'quarterly-insurance' => ['quarterly-insurance', 1],
        'yearly-domain' => ['yearly-domain', 1],
        'weekly-streaming' => ['weekly-streaming', 1],
        'irregular-gym-must-not-cluster' => ['irregular-gym-must-not-cluster', 0],
        'missing-month-subscription' => ['missing-month-subscription', 1],
        'mixed-currency-netflix-usd' => ['mixed-currency-netflix-usd', 1],
        'variable-amount-beyond-tolerance-bills' => ['variable-amount-beyond-tolerance-bills', 0],
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
        'kind' => 'asn_bank',
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
    $fixture = require base_path('Modules/Recurring/tests/fixtures/synthesised/'.$fixtureName.'.php');
    foreach ($fixture['transactions'] as $i => $row) {
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

it('asserts the expected expense-series count for each Wave 0 fixture', function (string $fixtureName, int $expectedExpenseSeriesCount): void {
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

    /** @var ExpenseSeriesDetector $detector */
    $detector = app(ExpenseSeriesDetector::class);
    /** @var Clock $clock */
    $clock = app(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = app(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($user->id))->handle($db, $clock, [$detector], $machine);

    $actual = RecurringSeries::query()
        ->where('user_id', $user->id)
        ->where('direction', 'expense')
        ->count();

    expect($actual)->toBe($expectedExpenseSeriesCount);

    CarbonImmutable::setTestNow();
})->with(rdctExpenseFixtureExpectations());
