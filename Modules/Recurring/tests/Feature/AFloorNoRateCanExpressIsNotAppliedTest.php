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

// The floor is an amount in the reader's money. Where no rate reaches the
// currency a row is denominated in, it cannot be expressed there at all --
// and the reader's bare integer is not a smaller version of it, it is a
// number meaning something else. AnomalyEvaluator::floorIn() already said so.

function unpricedFloorSeed(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $postedAt, int $minor, int $rowIndex): void
{
    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'income',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => Currency::Jpy->value,
        'settled_amount_minor' => $minor,
        'settled_currency' => Currency::Jpy->value,
        'counterparty_name' => 'tokyo stipend',
        'counterparty_iban' => null,
        'counterparty_normalized' => 'tokyo stipend',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad('unpriced'.$rowIndex, 64, 'u', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

function unpricedFloorDetect(User $user): void
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

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// An install that has not fetched rates yet -- a fresh one, or one that has
// been offline since setup. Every currency the enum admits is priced once the
// table is filled, so this is the state the fallback was written for.
it('detects an income in a currency the reader\'s floor cannot be converted into', function (): void {
    $user = User::query()->create([
        'username' => 'unpriced-floor',
        'password' => 'fixture',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
        'recurring_income_min_amount_minor' => 200_000,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Tokyo Bank',
        'slug' => 'unpriced-floor',
        'kind' => 'bank',
        'iban' => 'NL00UNPR0000000001',
        'default_currency' => Currency::Jpy->value,
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/unpriced-floor.csv',
        'sha256' => str_pad('unpriced', 64, '0', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    $this->db->connection()->table('exchange_rates')->delete();

    foreach (['2026-03-25', '2026-04-25', '2026-05-15'] as $i => $postedAt) {
        unpricedFloorSeed($this->db, $user, $account, $run, $postedAt, 150_000, $i + 1);
    }

    $detector = Container::getInstance()->make(IncomeSeriesDetector::class);
    $m = (new ReflectionClass($detector))->getMethod('floorsByCurrency');
    unpricedFloorDetect($user);

    expect(RecurringSeries::query()->where('user_id', $user->id)->count())->toBe(1);
});

// The reader's own currency still carries the reader's own floor, so the
// change cannot be read as switching the floor off everywhere.
it('still applies the floor in the currency the reader set it in', function (): void {
    $user = User::query()->create([
        'username' => 'priced-floor',
        'password' => 'fixture',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
        'recurring_income_min_amount_minor' => 200_000,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Home Bank',
        'slug' => 'priced-floor',
        'kind' => 'bank',
        'iban' => 'NL00PRIC0000000001',
        'default_currency' => Currency::Eur->value,
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/priced-floor.csv',
        'sha256' => str_pad('priced', 64, '0', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    foreach (['2026-03-25', '2026-04-25', '2026-05-15'] as $i => $postedAt) {
        $this->db->connection()->table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'income',
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'amount_minor' => 90_000,
            'currency' => Currency::Eur->value,
            'settled_amount_minor' => 90_000,
            'settled_currency' => Currency::Eur->value,
            'counterparty_name' => 'small retainer',
            'counterparty_iban' => null,
            'counterparty_normalized' => 'small retainer',
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $i + 1,
            'fingerprint' => str_pad('priced'.$i, 64, 'p', STR_PAD_LEFT),
            'fingerprint_version' => 3,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
    }

    unpricedFloorDetect($user);

    expect(RecurringSeries::query()->where('user_id', $user->id)->count())->toBe(0);
});
