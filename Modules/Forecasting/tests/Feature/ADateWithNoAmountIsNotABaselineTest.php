<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\AccountStartingBalanceQuery;

uses(RefreshDatabase::class);

// The rule is written in AccountStartingBalanceQuery::forAccount(): a date
// without an amount is not a baseline, because honouring its lower bound drops
// every earlier row and adds nothing back. It was spelled twice more and both
// copies said the opposite -- the SQL const bounded on starting_balance_date
// whatever the amount held, and positionOn() defaulted the amount to zero and
// then bounded anyway. Amount and date are separately merged synced columns, so
// one device clearing the amount leaves the other's date standing on the row.

function dwnaAccount(DatabaseManager $db, int $userId, ?int $minor): Account
{
    $account = Account::query()->create([
        'user_id' => $userId,
        'name' => 'Anchored card',
        'slug' => 'dwna-'.bin2hex(random_bytes(4)),
        'kind' => 'ics_card',
        'iban' => 'DWNA-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'starting_balance_minor' => $minor,
        'starting_balance_date' => '2026-04-17',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/dwna-'.bin2hex(random_bytes(4)).'.pdf',
        'sha256' => hash('sha256', 'dwna-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-04-25 00:00:00',
        'status' => 'confirmed',
    ]);

    foreach ([['2026-04-10', -2500], ['2026-04-20', -700]] as $index => [$postedAt, $amount]) {
        $db->connection()->table('transactions')->insert([
            'user_id' => $userId,
            'account_id' => $account->id,
            'import_run_id' => $runId,
            'type' => 'expense',
            'status' => 'cleared',
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'settled_amount_minor' => $amount,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'dwna merchant',
            'normalization_version' => 1,
            'source_format' => 'ics-pdf',
            'source_row_index' => $index,
            'fingerprint' => hash('sha256', 'dwna-tx-'.bin2hex(random_bytes(8))),
            'fingerprint_version' => 3,
        ]);
    }

    return $account;
}

function dwnaCountedByTheSql(DatabaseManager $db, int $userId): int
{
    return $db->connection()
        ->table('transactions')
        ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
        ->where('transactions.user_id', $userId)
        ->whereRaw(AccountStartingBalanceQuery::AT_OR_AFTER_BASELINE_SQL)
        ->count();
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'dwna-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('bounds nothing in SQL when the anchor names a day and no amount', function (): void {
    dwnaAccount($this->db, (int) $this->user->id, null);

    expect(dwnaCountedByTheSql($this->db, (int) $this->user->id))->toBe(2);
});

// The positive control. A date that DOES carry its amount still bounds, or the
// fix above would have turned every baseline in the product off.
it('still bounds in SQL when the anchor carries its amount', function (): void {
    dwnaAccount($this->db, (int) $this->user->id, -1000);

    expect(dwnaCountedByTheSql($this->db, (int) $this->user->id))->toBe(1);
});

it('sums from the beginning when the suggestion finds a day and no amount', function (): void {
    $account = dwnaAccount($this->db, (int) $this->user->id, null);

    /** @var SetAccountOpeningBalance $action */
    $action = $this->app->make(SetAccountOpeningBalance::class);

    expect($action->positionOn((int) $account->id, $this->user, CarbonImmutable::parse('2026-04-25')))
        ->toBe(-3200);
});

it('sums from the anchor when the suggestion finds a day with its amount', function (): void {
    $account = dwnaAccount($this->db, (int) $this->user->id, -1000);

    /** @var SetAccountOpeningBalance $action */
    $action = $this->app->make(SetAccountOpeningBalance::class);

    expect($action->positionOn((int) $account->id, $this->user, CarbonImmutable::parse('2026-04-25')))
        ->toBe(-1700);
});

it('answers no baseline date for an account that names a day and no amount', function (): void {
    $account = dwnaAccount($this->db, (int) $this->user->id, null);

    /** @var AccountStartingBalanceQuery $query */
    $query = $this->app->make(AccountStartingBalanceQuery::class);

    expect($query->forAccount((int) $account->id, $this->user))
        ->toMatchArray(['minorUnits' => 0, 'currency' => 'EUR', 'date' => null]);
});
