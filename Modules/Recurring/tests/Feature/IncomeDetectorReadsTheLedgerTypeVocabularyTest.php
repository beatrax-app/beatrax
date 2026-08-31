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
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;

// The income detector selects on transactions.type — a column Ledger owns and
// writes. Spelled as a bare string it fails silently: the query stays valid,
// no row matches, and a user whose salary stopped clustering looks identical
// to a user who has no recurring income.

function ivtSeedMonthly(
    DatabaseManager $db,
    User $user,
    Account $account,
    ImportRun $run,
    TransactionType $type,
    string $counterparty,
    string $iban,
): void {
    $start = CarbonImmutable::parse('2025-05-25');

    for ($i = 0; $i < 12; $i++) {
        $postedAt = $start->addMonthsNoOverflow($i)->toDateString();

        $db->connection()->table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => $type->value,
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'amount_minor' => 350000,
            'currency' => Currency::Eur->value,
            'settled_amount_minor' => 350000,
            'settled_currency' => Currency::Eur->value,
            'counterparty_name' => $counterparty,
            'counterparty_iban' => $iban,
            'counterparty_normalized' => $counterparty,
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => 200 + $i,
            'fingerprint' => str_pad($type->value.'-'.$i, 64, 'v', STR_PAD_LEFT),
            'fingerprint_version' => 3,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
    }
}

function ivtRunJob(User $user): void
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

    $this->user = User::query()->create([
        'username' => 'income-vocabulary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => 36,
    ]);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'vocab asn',
        'slug' => 'ivt-asn',
        'kind' => 'bank',
        'iban' => 'NL00IVT0000000001',
        'default_currency' => Currency::Eur->value,
    ]);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ivt.csv',
        'sha256' => str_repeat('v', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => ImportRunStatus::Previewed->value,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('clusters a series from rows stored under the type the owning enum names', function (): void {
    ivtSeedMonthly($this->db, $this->user, $this->account, $this->run, TransactionType::Income, 'vocab payer', 'NL56VOCA0000000001');

    ivtRunJob($this->user);

    expect(RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', Direction::Income->value)
        ->count())->toBe(1);
});

// Refund carries Direction::Income too, so a spelling that drifted onto it
// would widen the detector rather than empty it.
it('clusters nothing from the refund rows the same column accepts', function (): void {
    ivtSeedMonthly($this->db, $this->user, $this->account, $this->run, TransactionType::Refund, 'refund payer', 'NL56VOCA0000000002');

    ivtRunJob($this->user);

    expect(RecurringSeries::query()->where('user_id', $this->user->id)->count())->toBe(0);
});
