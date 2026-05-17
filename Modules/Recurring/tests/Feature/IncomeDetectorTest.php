<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

/*
 * Feature-level coverage for the IncomeSeriesDetector.
 *
 * Income clusters by counterparty IBAN with a fallback to the
 * normalized counterparty description, scoped to type='income'
 * transactions above the per-user `recurring_income_min_amount_minor`
 * threshold (default 200000 minor units = €2000).
 */

function idtUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function idtAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'idt '.$slug,
        'slug' => $slug,
        'kind' => 'asn_bank',
        'iban' => 'NL00IDT0'.str_pad(substr($slug, 0, 8), 10, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function idtImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/idt.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
}

function idtSeedTx(
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
    ?string $counterpartyIban,
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
        'counterparty_iban' => $counterpartyIban,
        'counterparty_normalized' => $counterpartyNormalized,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'i', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

function idtSeedFixture(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $fixtureName): int
{
    $fixture = require base_path('Modules/Recurring/tests/fixtures/synthesised/'.$fixtureName.'.php');
    $count = 0;
    foreach ($fixture['transactions'] as $i => $row) {
        $iban = isset($row['counterparty_iban']) ? (string) $row['counterparty_iban'] : null;
        if ($iban === '') {
            $iban = null;
        }
        idtSeedTx(
            $db,
            $user,
            $account,
            $run,
            (string) $row['posted_at'],
            (int) $row['original_amount_minor'],
            (string) $row['original_currency'],
            (int) $row['amount_minor'],
            (string) $row['currency'],
            (string) $row['counterparty_normalized'],
            $iban,
            (string) $row['type'],
            $i + 1,
            $fixtureName.'-'.$i,
        );
        $count++;
    }

    return $count;
}

function idtRunJob(User $user): void
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
    $this->user = idtUser('income-detector@diederik.test');
    // Widen the detection window so the monthly-salary fixture's earliest
    // 2025-04-25 occurrence sits inside the look-back window relative to
    // the 2026-05-17 frozen clock.
    $this->user->recurring_detection_window_months = 36;
    $this->user->save();
    $this->account = idtAccount($this->user, 'idt-asn');
    $this->run = idtImportRun($this->user, str_repeat('i', 64));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('clusters a monthly-salary fixture into one approved-pending income series', function (): void {
    idtSeedFixture($this->db, $this->user, $this->account, $this->run, 'monthly-salary');

    idtRunJob($this->user);

    /** @var list<RecurringSeries> $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'income')
        ->get()->all();

    expect($series)->toHaveCount(1);
    expect($series[0]->state)->toBe('pending');
    expect($series[0]->cadence)->toBe('monthly');
    expect($series[0]->latest_amount_minor)->toBe(350000);
    expect($series[0]->latest_currency)->toBe('EUR');
    expect($series[0]->detected_name)->toBe('acme bv');

    $occurrences = RecurringSeriesOccurrence::query()
        ->where('recurring_series_id', $series[0]->id)
        ->count();
    expect($occurrences)->toBe(12);
})->group('income-cluster');

it('drops income below the recurring_income_min_amount_minor threshold so small refunds never cluster', function (): void {
    // Default threshold = 200000 (€2000). Seed monthly +€500 income for
    // 12 months — every row is below threshold.
    $start = CarbonImmutable::parse('2025-04-25');
    for ($i = 0; $i < 12; $i++) {
        $date = $start->addMonths($i)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            50000, 'EUR', 50000, 'EUR',
            'small refund client', 'NL00SMAL0000000001',
            'income',
            100 + $i,
            'small-'.$i,
        );
    }

    idtRunJob($this->user);

    $count = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->count();
    expect($count)->toBe(0);
})->group('income-threshold');

it('produces two distinct income series for multi-IBAN payroll (D-821)', function (): void {
    idtSeedFixture($this->db, $this->user, $this->account, $this->run, 'two-employer-salary');

    idtRunJob($this->user);

    /** @var list<RecurringSeries> $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'income')
        ->orderBy('detected_name')
        ->get()->all();

    expect($series)->toHaveCount(2);
    expect($series[0]->cadence)->toBe('monthly');
    expect($series[1]->cadence)->toBe('monthly');
    expect($series[0]->cluster_key)->not->toBe($series[1]->cluster_key);
})->group('two-employer');

it('falls back to counterparty_normalized when IBAN is null (D-817 fallback)', function (): void {
    // 12 monthly income rows from a freelance client with NULL IBAN but
    // consistent normalized description; should cluster on description.
    $start = CarbonImmutable::parse('2025-04-25');
    for ($i = 0; $i < 12; $i++) {
        $date = $start->addMonths($i)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            250000, 'EUR', 250000, 'EUR',
            'freelance client x', null,
            'income',
            500 + $i,
            'fl-'.$i,
        );
    }

    idtRunJob($this->user);

    /** @var list<RecurringSeries> $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'income')
        ->get()->all();

    expect($series)->toHaveCount(1);
    expect($series[0]->detected_name)->toBe('freelance client x');
})->group('iban-missing-falls-back-to-description');

it('clusters mixed-currency income (EUR vs USD same employer) into two separate series per D-839', function (): void {
    // Six monthly EUR + six monthly USD income rows from the same IBAN.
    // Each currency must cluster as its own series.
    $start = CarbonImmutable::parse('2025-10-25');
    for ($i = 0; $i < 6; $i++) {
        $date = $start->addMonths($i)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            350000, 'EUR', 350000, 'EUR',
            'global employer', 'NL00GLOB0000000001',
            'income',
            800 + $i,
            'gl-eur-'.$i,
        );
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            250000, 'USD', 230000, 'EUR',
            'global employer', 'NL00GLOB0000000001',
            'income',
            900 + $i,
            'gl-usd-'.$i,
        );
    }

    idtRunJob($this->user);

    /** @var list<RecurringSeries> $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'income')
        ->get()->all();

    expect($series)->toHaveCount(2);
    $currencies = collect($series)->pluck('latest_currency')->sort()->values()->all();
    expect($currencies)->toBe(['EUR', 'USD']);
})->group('mixed-currency-income');

it('is idempotent across re-runs (same income series + occurrence row counts)', function (): void {
    idtSeedFixture($this->db, $this->user, $this->account, $this->run, 'monthly-salary');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var IncomeSeriesDetector $detector */
    $detector = $this->app->make(IncomeSeriesDetector::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);

    $job = new DetectRecurringSeriesJob($this->user->id);
    $job->handle($db, $clock, [$detector], $machine);
    $seriesAfter1 = RecurringSeries::query()->where('user_id', $this->user->id)->where('direction', 'income')->count();
    $occAfter1 = RecurringSeriesOccurrence::query()->where('user_id', $this->user->id)->count();

    $job->handle($db, $clock, [$detector], $machine);
    $seriesAfter2 = RecurringSeries::query()->where('user_id', $this->user->id)->where('direction', 'income')->count();
    $occAfter2 = RecurringSeriesOccurrence::query()->where('user_id', $this->user->id)->count();

    expect($seriesAfter2)->toBe($seriesAfter1);
    expect($occAfter2)->toBe($occAfter1);
})->group('idempotent-re-run');

it('does not touch expense-type transactions', function (): void {
    // Seed an expense-type row that would otherwise cluster well.
    idtSeedFixture($this->db, $this->user, $this->account, $this->run, 'stable-monthly-spotify');

    idtRunJob($this->user);

    $count = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->count();
    expect($count)->toBe(0);
})->group('income-detector-ignores-expenses');
