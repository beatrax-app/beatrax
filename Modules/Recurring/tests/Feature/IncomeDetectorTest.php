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

function idtUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
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
        'kind' => 'bank',
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
    $this->user = idtUser('income-detector');
    // Wide enough for the fixture's earliest 2025-04-25 occurrence to sit
    // inside the look-back from the frozen 2026-05-17 clock.
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
    // The default threshold is €2000; every row seeded below is €500.
    $start = CarbonImmutable::parse('2025-04-25');
    for ($i = 0; $i < 12; $i++) {
        $date = $start->addMonthsNoOverflow($i)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            50000, 'EUR', 50000, 'EUR',
            'small refund client', 'NL56SMAL0000000001',
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

it('produces two distinct income series for multi-IBAN payroll', function (): void {
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

it('falls back to counterparty_normalized when IBAN is null', function (): void {
    $start = CarbonImmutable::parse('2025-04-25');
    for ($i = 0; $i < 12; $i++) {
        $date = $start->addMonthsNoOverflow($i)->toDateString();
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

it('clusters mixed-currency income (EUR vs USD same employer) into two separate series', function (): void {
    // Both runs below share one IBAN; only the currency differs.
    $start = CarbonImmutable::parse('2025-10-25');
    for ($i = 0; $i < 6; $i++) {
        $date = $start->addMonthsNoOverflow($i)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            350000, 'EUR', 350000, 'EUR',
            'global employer', 'NL94GLOB0000000001',
            'income',
            800 + $i,
            'gl-eur-'.$i,
        );
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            250000, 'USD', 230000, 'EUR',
            'global employer', 'NL94GLOB0000000001',
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

it('leaves a snoozed series untouched — refreshing metrics during snooze would wake the row up', function (): void {
    $start = CarbonImmutable::parse('2024-04-25');
    for ($i = 0; $i < 12; $i++) {
        $date = $start->addMonthsNoOverflow($i)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            280000, 'EUR', 280000, 'EUR',
            'snooze probe employer', 'NL47SNZ00000000001',
            'income',
            7000 + $i,
            'snz-'.$i,
        );
    }

    idtRunJob($this->user);

    /** @var RecurringSeries $row */
    $row = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'income')
        ->firstOrFail();
    expect($row->latest_amount_minor)->toBe(280000);

    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);
    $machine->transition(
        $row,
        'snoozed',
        'user_action',
        'user',
        notes: 'snoozed_until=2026-09-01 00:00:00',
        extraColumns: ['snoozed_until' => '2026-09-01 00:00:00'],
    );

    // A different amount, so a refresh would visibly change the row.
    idtSeedTx(
        $this->db, $this->user, $this->account, $this->run,
        '2025-05-25',
        290000, 'EUR', 290000, 'EUR',
        'snooze probe employer', 'NL47SNZ00000000001',
        'income',
        7999,
        'snz-new',
    );

    idtRunJob($this->user);

    /** @var RecurringSeries $fresh */
    $fresh = RecurringSeries::query()->findOrFail($row->id);
    expect($fresh->state)->toBe('snoozed');
    expect($fresh->latest_amount_minor)->toBe(280000);
})->group('snoozed-series-skipped-on-sweep');

it('suppresses every cadence variant when the counterparty has a rejected series — partial cadence-only un-rejection is not supported', function (): void {
    $start = CarbonImmutable::parse('2024-04-25');
    for ($i = 0; $i < 12; $i++) {
        $date = $start->addMonthsNoOverflow($i)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            260000, 'EUR', 260000, 'EUR',
            'rejected employer', 'NL07REJE0000000001',
            'income',
            5000 + $i,
            'rej-m-'.$i,
        );
    }

    idtRunJob($this->user);

    /** @var RecurringSeries $monthly */
    $monthly = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'income')
        ->firstOrFail();
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);
    $machine->transition($monthly, 'rejected', 'user_action', 'user');

    $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('counterparty_iban', 'NL07REJE0000000001')
        ->delete();
    $quarterlyStart = CarbonImmutable::parse('2025-06-01');
    for ($i = 0; $i < 4; $i++) {
        $date = $quarterlyStart->addMonthsNoOverflow($i * 3)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            260000, 'EUR', 260000, 'EUR',
            'rejected employer', 'NL07REJE0000000001',
            'income',
            6000 + $i,
            'rej-q-'.$i,
        );
    }

    idtRunJob($this->user);

    $count = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'income')
        ->count();
    expect($count)->toBe(1);
    /** @var RecurringSeries $only */
    $only = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->firstOrFail();
    expect($only->state)->toBe('rejected');
})->group('rejection-covers-every-cadence');

it('keeps two IBAN-distinct payroll series isolated when both share a detected_name and one cadence flips', function (): void {
    // Two employers share a normalised detected_name but not an IBAN, so the
    // cadence-flip fallback must resolve B via cluster_counterparty_key rather
    // than latch onto A on the strength of the shared name.
    $start = CarbonImmutable::parse('2024-04-25');
    for ($i = 0; $i < 13; $i++) {
        $date = $start->addMonthsNoOverflow($i)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            300000, 'EUR', 300000, 'EUR',
            'shared name', 'NL44EMPA0000000001',
            'income',
            1000 + $i,
            'sn-a-'.$i,
        );
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            320000, 'EUR', 320000, 'EUR',
            'shared name', 'NL52EMPB0000000002',
            'income',
            2000 + $i,
            'sn-b-'.$i,
        );
    }

    idtRunJob($this->user);

    /** @var list<RecurringSeries> $afterFirstPass */
    $afterFirstPass = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', 'income')
        ->orderBy('cluster_counterparty_key')
        ->get()->all();
    expect($afterFirstPass)->toHaveCount(2);
    expect($afterFirstPass[0]->cluster_counterparty_key)->toBe('NL44EMPA0000000001');
    expect($afterFirstPass[1]->cluster_counterparty_key)->toBe('NL52EMPB0000000002');

    // Approved first, so the flip travels the approved→cadence_changed seam —
    // where the bug was: the wrong row got demoted.
    /** @var RecurringSeriesStateMachine $machine */
    $machine = $this->app->make(RecurringSeriesStateMachine::class);
    foreach ($afterFirstPass as $row) {
        $machine->transition($row, 'approved', 'user_action', 'user');
    }

    // Employer B's recent occurrences become quarterly, so the cluster_key
    // (which encodes the cadence band) flips for B but not for A.
    $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('counterparty_iban', 'NL52EMPB0000000002')
        ->delete();
    $quarterlyStart = CarbonImmutable::parse('2025-06-01');
    for ($i = 0; $i < 4; $i++) {
        $date = $quarterlyStart->addMonthsNoOverflow($i * 3)->toDateString();
        idtSeedTx(
            $this->db, $this->user, $this->account, $this->run,
            $date,
            320000, 'EUR', 320000, 'EUR',
            'shared name', 'NL52EMPB0000000002',
            'income',
            3000 + $i,
            'sn-b-q-'.$i,
        );
    }

    idtRunJob($this->user);

    /** @var RecurringSeries $employerA */
    $employerA = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('cluster_counterparty_key', 'NL44EMPA0000000001')
        ->firstOrFail();
    /** @var RecurringSeries $employerB */
    $employerB = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('cluster_counterparty_key', 'NL52EMPB0000000002')
        ->firstOrFail();

    expect($employerA->state)->toBe('approved');
    expect($employerA->cadence)->toBe('monthly');
    expect($employerA->latest_amount_minor)->toBe(300000);

    expect($employerB->cadence)->toBe('quarterly');
    expect($employerB->state)->toBe('cadence_changed');
})->group('cadence-flip-isolated-by-cluster-counterparty-key');
