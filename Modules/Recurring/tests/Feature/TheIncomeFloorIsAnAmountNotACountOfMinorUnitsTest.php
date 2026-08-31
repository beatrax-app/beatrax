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

// The reader's income floor is a figure in their own money. Applied as a bare
// count of minor units to a yen-native row it means ¥200,000 (about €1,257),
// so a stipend far under the €2,000 floor was banked as a salary series.

function ifcSeedIncome(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $postedAt, int $minor, string $payee, int $rowIndex): void
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
        'counterparty_name' => $payee,
        'counterparty_iban' => null,
        'counterparty_normalized' => $payee,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad($payee.$rowIndex, 64, 'i', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

function ifcRunDetection(User $user): void
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

    $db->connection()->table('exchange_rates')->updateOrInsert(
        ['base_currency' => 'EUR', 'quote_currency' => Currency::Jpy->value, 'rate_date' => '2026-05-01', 'source' => 'ecb'],
        ['rate' => '159.10', 'created_at' => now(), 'updated_at' => now()],
    );

    $this->user = User::query()->create([
        'username' => 'income-floor-currency',
        'password' => 'fixture',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Japan Trip Bank',
        'slug' => 'income-floor-jpy',
        'kind' => 'bank',
        'iban' => 'JP00IFC0000000001',
        'default_currency' => Currency::Jpy->value,
    ]);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ifc.csv',
        'sha256' => str_pad('incomefloor', 64, 'f', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('leaves a yen income under the reader’s own floor out of the series list', function (): void {
    // ¥250,000 a month is about €1,571 — under the €2,000 floor, but 250000
    // clears the bare integer 200000.
    foreach (['2026-03-25', '2026-04-25', '2026-05-15'] as $i => $postedAt) {
        ifcSeedIncome($this->db, $this->user, $this->account, $this->run, $postedAt, 250_000, 'tokyo stipend', $i + 1);
    }

    ifcRunDetection($this->user);

    expect(RecurringSeries::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('still detects a yen income that clears the reader’s floor once converted', function (): void {
    // ¥400,000 a month is about €2,514 — over the €2,000 floor.
    foreach (['2026-03-25', '2026-04-25', '2026-05-15'] as $i => $postedAt) {
        ifcSeedIncome($this->db, $this->user, $this->account, $this->run, $postedAt, 400_000, 'tokyo salary', $i + 10);
    }

    ifcRunDetection($this->user);

    expect(RecurringSeries::query()->where('user_id', $this->user->id)->count())->toBe(1);
});
