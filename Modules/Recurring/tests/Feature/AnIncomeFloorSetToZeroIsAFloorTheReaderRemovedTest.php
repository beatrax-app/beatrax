<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;

// The settings field admits zero, the column comment names zero as the way to
// switch the floor off, and the help text under the box says so in twenty-six
// languages. The detector read zero as "unset" and substituted the two
// thousand euro default, so the one input the screen offered to disable the
// threshold applied the strictest threshold there is.

function zeroFloorSeedIncome(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $postedAt, int $minor, int $rowIndex): void
{
    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'income',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $minor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'freelance retainer',
        'counterparty_iban' => null,
        'counterparty_normalized' => 'freelance retainer',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad('zerofloor'.$rowIndex, 64, 'z', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

function zeroFloorRunDetection(User $user): void
{
    $app = Container::getInstance();
    /** @var DatabaseManager $db */
    $db = $app->make(DatabaseManager::class);
    /** @var IncomeSeriesDetector $detector */
    $detector = $app->make(IncomeSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $app->make(RecurringSeriesStateMachine::class);

    (new DetectRecurringSeriesJob($user->id))->handle($db, $clock, [$detector], $machine);
}

function zeroFloorUser(int $floorMinor, string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
        'recurring_income_min_amount_minor' => $floorMinor,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// Nine hundred euros a month is real freelance income and is nowhere near the
// two thousand euro default, so it can only be detected by a floor the reader
// actually removed.
it('detects an income under the default floor once the reader sets the floor to zero', function (): void {
    $user = zeroFloorUser(0, 'income-floor-zero');

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Retainer Bank',
        'slug' => 'income-floor-zero',
        'kind' => 'bank',
        'iban' => 'NL00ZERO0000000001',
        'default_currency' => Currency::Eur->value,
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/zero-floor.csv',
        'sha256' => str_pad('zerofloor', 64, '0', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    foreach (['2026-03-25', '2026-04-25', '2026-05-15'] as $i => $postedAt) {
        zeroFloorSeedIncome($this->db, $user, $account, $run, $postedAt, 90_000, $i + 1);
    }

    zeroFloorRunDetection($user);

    expect(RecurringSeries::query()->where('user_id', $user->id)->count())->toBe(1);
});

// The other half: the default is still the default. A reader who never touched
// the field keeps the floor the column ships with.
it('keeps the shipped floor for a reader who has not moved it', function (): void {
    $user = zeroFloorUser(User::DEFAULT_RECURRING_INCOME_MIN_AMOUNT_MINOR, 'income-floor-default');

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Retainer Bank',
        'slug' => 'income-floor-default',
        'kind' => 'bank',
        'iban' => 'NL00ZERO0000000002',
        'default_currency' => Currency::Eur->value,
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/default-floor.csv',
        'sha256' => str_pad('defaultfloor', 64, '0', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    foreach (['2026-03-25', '2026-04-25', '2026-05-15'] as $i => $postedAt) {
        zeroFloorSeedIncome($this->db, $user, $account, $run, $postedAt, 90_000, $i + 20);
    }

    zeroFloorRunDetection($user);

    expect(RecurringSeries::query()->where('user_id', $user->id)->count())->toBe(0);
});
