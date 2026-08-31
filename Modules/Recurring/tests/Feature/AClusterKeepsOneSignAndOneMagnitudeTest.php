<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Models\RecurringSeries;

// A row carries its magnitude and its direction in one signed integer. The
// expense cluster compared abs() and so ranked a refund as its newest charge;
// the income cluster applied no variance filter at all and let a one-off
// holiday allowance become both the latest amount and the monthly equivalent.
function acsUser(): User
{
    return User::query()->create([
        'username' => 'acs-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => 36,
    ]);
}

function acsTx(
    DatabaseManager $db,
    User $user,
    Account $account,
    ImportRun $run,
    string $postedAt,
    int $amountMinor,
    string $counterparty,
    TransactionType $type,
    string $seed,
    ?string $iban = null,
): void {
    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type->value,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => ucfirst($counterparty),
        'counterparty_iban' => $iban,
        'counterparty_normalized' => $counterparty,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => crc32($seed) % 100000,
        'fingerprint' => str_pad($seed, 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = acsUser();
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'acs asn',
        'slug' => 'acs-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ACS00000000001',
        'default_currency' => Currency::Eur->value,
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/acs.csv',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('never lets a refund become the newest charge of an expense series', function (): void {
    foreach (['2026-01-04', '2026-02-04', '2026-03-04', '2026-04-04', '2026-05-04'] as $i => $postedAt) {
        acsTx($this->db, $this->user, $this->account, $this->run, $postedAt, -1099, 'netflix', TransactionType::Expense, 'nf'.$i);
    }
    acsTx($this->db, $this->user, $this->account, $this->run, '2026-05-09', 1099, 'netflix', TransactionType::Refund, 'nf-refund');

    $this->app->make(ExpenseSeriesDetector::class)->detectForUser($this->user);

    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()->where('user_id', $this->user->id)->firstOrFail();

    expect($series->direction)->toBe(Direction::Expense->value);
    expect($series->latest_amount_minor)->toBe(-1099);
    expect($series->monthly_equivalent_minor)->toBe(-1099);
});

// The type filter is one guard; the sign filter is the other. A chargeback
// booked against the same merchant under an expense type reaches the cluster
// whatever the type list says.
it('drops a sign-flipped row from an expense cluster rather than ranking it on magnitude', function (): void {
    foreach (['2026-01-04', '2026-02-04', '2026-03-04', '2026-04-04', '2026-05-04'] as $i => $postedAt) {
        acsTx($this->db, $this->user, $this->account, $this->run, $postedAt, -1099, 'spotify', TransactionType::Expense, 'sp'.$i);
    }
    acsTx($this->db, $this->user, $this->account, $this->run, '2026-05-11', 1099, 'spotify', TransactionType::Expense, 'sp-reversal');

    $this->app->make(ExpenseSeriesDetector::class)->detectForUser($this->user);

    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()->where('user_id', $this->user->id)->firstOrFail();

    expect($series->latest_amount_minor)->toBe(-1099);
    expect($series->monthly_equivalent_minor)->toBe(-1099);
});

it('keeps a holiday allowance out of the salary the income series reports', function (): void {
    $iban = 'NL56PAYR0000000001';
    foreach (['2026-01-25', '2026-02-25', '2026-03-25', '2026-04-25'] as $i => $postedAt) {
        acsTx($this->db, $this->user, $this->account, $this->run, $postedAt, 350000, 'acme payroll', TransactionType::Income, 'sal'.$i, $iban);
    }
    // Paid alongside May's salary, and the last row of the cluster.
    acsTx($this->db, $this->user, $this->account, $this->run, '2026-05-25', 350000, 'acme payroll', TransactionType::Income, 'sal4', $iban);
    acsTx($this->db, $this->user, $this->account, $this->run, '2026-05-26', 1200000, 'acme payroll', TransactionType::Income, 'holiday', $iban);

    $this->app->make(IncomeSeriesDetector::class)->detectForUser($this->user);

    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', Direction::Income->value)
        ->firstOrFail();

    expect($series->latest_amount_minor)->toBe(350000);
    expect($series->monthly_equivalent_minor)->toBe(350000);
});
