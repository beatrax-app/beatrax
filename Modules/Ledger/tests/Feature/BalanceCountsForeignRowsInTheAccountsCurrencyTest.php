<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\AccountBalanceQuery;

// A row bought in another currency carries both figures: amount_minor as the
// merchant charged it, settled_amount_minor as the account was debited. The
// balance summed the first, so a USD purchase on a euro account added dollar
// cents to a euro total. Measured on the desktop: a card holding two USD rows
// read -853.81 where its own statement said -847.32.

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'settled-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
});

function settledAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Settled EUR',
        'slug' => 'settled-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00SET'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function settledRow(
    DatabaseManager $db,
    int $userId,
    int $accountId,
    int $amountMinor,
    string $currency,
    int $settledMinor,
): void {
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/set-'.$hex.'.csv',
        'sha256' => hash('sha256', 'set-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'set-fp-'.$hex),
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'set',
        'counterparty_name' => 'SET',
        'normalization_version' => 1,
        'description' => 'settled fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'fingerprint_version' => 3,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('counts a foreign-currency row at what the account was actually debited', function (): void {
    $accountId = settledAccount($this->db, $this->user->id);

    settledRow($this->db, $this->user->id, $accountId, 10_000, Currency::Eur->value, 10_000);
    settledRow($this->db, $this->user->id, $accountId, -3_695, Currency::Usd->value, -3_399);

    expect(app(AccountBalanceQuery::class)->currentBalance($accountId, $this->user))->toBe(6_601);
});

// The cleared figures reconcile against a printed bank statement, which is
// denominated in the account's currency throughout.
it('uses the settled figure for the cleared balance too', function (): void {
    $accountId = settledAccount($this->db, $this->user->id);

    settledRow($this->db, $this->user->id, $accountId, -3_695, Currency::Usd->value, -3_399);

    expect(app(AccountBalanceQuery::class)->clearedBalance($accountId, $this->user))->toBe(-3_399);
});
