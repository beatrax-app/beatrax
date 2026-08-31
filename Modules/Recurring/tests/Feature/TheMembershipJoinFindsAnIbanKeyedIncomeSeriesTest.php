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
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Services\TransactionSeriesMembershipQuery;

// The income detector writes an IBAN-derived key into cluster_counterparty_key
// while the membership join compares that column against counterparty_normalized
// — a different blind-index domain. Nothing matched, so a booked future-dated
// salary counted once as a real row and again as a projected occurrence.
function mjiUser(): User
{
    return User::query()->create([
        'username' => 'mji-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => 36,
    ]);
}

function mjiTx(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $postedAt, string $iban, string $seed): int
{
    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => TransactionType::Income->value,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => 350000,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => 350000,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'Acme Payroll',
        'counterparty_iban' => $iban,
        'counterparty_normalized' => 'acme payroll',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => crc32($seed) % 100000,
        'fingerprint' => str_pad($seed, 64, 'm', STR_PAD_LEFT),
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
    $this->user = mjiUser();
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'mji asn',
        'slug' => 'mji-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00MJI0000000001',
        'default_currency' => Currency::Eur->value,
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/mji.csv',
        'sha256' => str_repeat('m', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('resolves a future-dated salary onto the IBAN-keyed series the detector wrote', function (): void {
    $iban = 'NL56ACME0000000001';
    foreach (['2026-02-25', '2026-03-25', '2026-04-25'] as $i => $postedAt) {
        mjiTx($this->db, $this->user, $this->account, $this->run, $postedAt, $iban, 'seed'.$i);
    }

    $this->app->make(IncomeSeriesDetector::class)->detectForUser($this->user);

    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('direction', Direction::Income->value)
        ->firstOrFail();
    $this->db->connection()->table('recurring_series')
        ->where('id', $series->id)
        ->update(['state' => RecurringSeriesState::Approved->value]);

    // Imported after the sweep, so no occurrence link exists for it yet — the
    // exact window the cluster-identity arm is there to cover.
    $futureId = mjiTx($this->db, $this->user, $this->account, $this->run, '2026-05-25', $iban, 'future');

    /** @var TransactionSeriesMembershipQuery $query */
    $query = $this->app->make(TransactionSeriesMembershipQuery::class);

    expect($query->seriesIdsForTransactionIds([$futureId], $this->user))
        ->toBe([$futureId => (int) $series->id]);
});

it('leaves a salary from another payer unresolved', function (): void {
    foreach (['2026-02-25', '2026-03-25', '2026-04-25'] as $i => $postedAt) {
        mjiTx($this->db, $this->user, $this->account, $this->run, $postedAt, 'NL56ACME0000000001', 'own'.$i);
    }

    $this->app->make(IncomeSeriesDetector::class)->detectForUser($this->user);
    $this->db->connection()->table('recurring_series')->update(['state' => RecurringSeriesState::Approved->value]);

    $strangerId = mjiTx($this->db, $this->user, $this->account, $this->run, '2026-05-25', 'NL56OTHR0000000009', 'stranger');

    /** @var TransactionSeriesMembershipQuery $query */
    $query = $this->app->make(TransactionSeriesMembershipQuery::class);

    expect($query->seriesIdsForTransactionIds([$strangerId], $this->user))->toBe([]);
});

// The triple the join matches on carries a plain INDEX, so two series can share
// it and the untie-broken join picked whichever the planner handed back first.
it('picks the same series every run when two share the joined cluster identity', function (): void {
    $shared = [
        'user_id' => $this->user->id,
        'direction' => Direction::Expense->value,
        'detected_name' => 'Twin Merchant',
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => Currency::Eur->value,
        'variance_tolerance_percent' => 25,
        'cluster_counterparty_key' => 'twin merchant',
        'created_at' => '2026-05-01 12:00:00',
        'updated_at' => '2026-05-01 12:00:00',
    ];
    $first = $this->db->connection()->table('recurring_series')->insertGetId($shared + ['cluster_key' => 'expense::twin::eur::monthly']);
    $this->db->connection()->table('recurring_series')->insertGetId($shared + ['cluster_key' => 'expense::twin::eur::weekly']);

    $txId = (int) $this->db->connection()->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => TransactionType::Expense->value,
        'posted_at' => '2026-05-25',
        'booked_at' => '2026-05-25 12:00:00',
        'value_date' => '2026-05-25',
        'amount_minor' => -1099,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => -1099,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'Twin Merchant',
        'counterparty_normalized' => 'twin merchant',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->run->id,
        'source_row_index' => 4242,
        'fingerprint' => str_pad('twin', 64, 't', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    /** @var TransactionSeriesMembershipQuery $query */
    $query = $this->app->make(TransactionSeriesMembershipQuery::class);

    expect($query->seriesIdsForTransactionIds([$txId], $this->user))->toBe([$txId => (int) $first]);
});
