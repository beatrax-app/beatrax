<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\AccountBalanceQuery;

// An account that genuinely holds several currencies -- the Revolut preset
// reads a currency per row -- has no one balance. Summing settled_amount_minor
// across them produced 328885 for an account holding EUR3,509.85 and
// -USD221.00, and the dashboard printed it as a euro net worth.

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'per-currency',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function lpcAccount(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId(array_merge([
        'user_id' => $userId,
        'name' => 'Revolut',
        'slug' => 'lpc-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'GB00LPC'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ], $overrides));
}

function lpcRow(
    DatabaseManager $db,
    int $userId,
    int $accountId,
    int $minor,
    string $currency,
    string $postedAt = '2026-05-10',
    ClearedStatus $status = ClearedStatus::Cleared,
): void {
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'revolut-csv',
        'raw_file_path' => '/tmp/lpc-'.$hex.'.csv',
        'sha256' => hash('sha256', 'lpc-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'lpc-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_normalized' => 'lpc',
        'counterparty_name' => 'LPC',
        'normalization_version' => 1,
        'description' => 'per-currency fixture',
        'type' => $minor >= 0 ? TransactionType::Income->value : TransactionType::Expense->value,
        'source_format' => 'revolut-csv',
        'source_row_index' => $row,
        'status' => $status->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function lpcRevolutRows(DatabaseManager $db, int $userId, int $accountId): void
{
    foreach ([200_000, 100_000, 30_000, 15_000, 5_000, 985] as $minor) {
        lpcRow($db, $userId, $accountId, $minor, Currency::Eur->value);
    }
    foreach ([-10_000, -7_100, -5_000] as $minor) {
        lpcRow($db, $userId, $accountId, $minor, Currency::Usd->value);
    }
}

it('answers with a line per currency and never with their sum', function (): void {
    $accountId = lpcAccount($this->db, $this->user->id);
    lpcRevolutRows($this->db, $this->user->id, $accountId);

    $balance = app(AccountBalanceQuery::class)->currentBalance($accountId, $this->user);

    expect($balance->lines())->toBe([Currency::Eur->value => 350_985, Currency::Usd->value => -22_100])
        ->and($balance->in(Currency::Eur->value))->toBe(350_985)
        ->and($balance->in(Currency::Usd->value))->toBe(-22_100)
        ->and($balance->lines())->not->toContain(328_885);
});

// The baseline is denominated in the account's default_currency and the rows
// in whatever each settled in, so a euro baseline under dollar rows opens the
// euro line rather than being absorbed into the dollars.
it('opens the baseline in the account own currency, not in the currency of its rows', function (): void {
    $accountId = lpcAccount($this->db, $this->user->id, ['starting_balance_minor' => 100_000]);
    lpcRow($this->db, $this->user->id, $accountId, -22_100, Currency::Usd->value);

    $balance = app(AccountBalanceQuery::class)->currentBalance($accountId, $this->user);

    expect($balance->lines())->toBe([Currency::Eur->value => 100_000, Currency::Usd->value => -22_100]);
});

// A currency the account does not hold is zero, which is what it holds of it.
it('reports zero for a currency the account holds none of', function (): void {
    $accountId = lpcAccount($this->db, $this->user->id);
    lpcRow($this->db, $this->user->id, $accountId, 5_000, Currency::Eur->value);

    expect(app(AccountBalanceQuery::class)->currentBalance($accountId, $this->user)->in(Currency::Gbp->value))->toBe(0);
});

it('gives a single-currency account exactly one line, at the figure it always reported', function (): void {
    $accountId = lpcAccount($this->db, $this->user->id, ['starting_balance_minor' => 150_000]);
    lpcRow($this->db, $this->user->id, $accountId, 60_000, Currency::Eur->value);
    lpcRow($this->db, $this->user->id, $accountId, -15_891, Currency::Eur->value);
    lpcRow($this->db, $this->user->id, $accountId, -2_000, Currency::Eur->value);

    expect(app(AccountBalanceQuery::class)->currentBalance($accountId, $this->user)->lines())
        ->toBe([Currency::Eur->value => 192_109]);
});

// The account with nothing on it names its currency at zero rather than
// vanishing, so a caller iterating the lines still sees it.
it('names the currency of an account carrying no rows at all', function (): void {
    $accountId = lpcAccount($this->db, $this->user->id, ['default_currency' => Currency::Gbp->value]);

    expect(app(AccountBalanceQuery::class)->currentBalance($accountId, $this->user)->lines())
        ->toBe([Currency::Gbp->value => 0]);
});

it('keeps the currencies apart in the cleared and as-of figures too', function (): void {
    $accountId = lpcAccount($this->db, $this->user->id);
    lpcRow($this->db, $this->user->id, $accountId, 350_985, Currency::Eur->value, '2026-05-10');
    lpcRow($this->db, $this->user->id, $accountId, -22_100, Currency::Usd->value, '2026-05-10');
    lpcRow($this->db, $this->user->id, $accountId, -9_000, Currency::Usd->value, '2026-05-10', ClearedStatus::Uncleared);
    lpcRow($this->db, $this->user->id, $accountId, 1_000, Currency::Eur->value, '2026-05-31');

    $balances = app(AccountBalanceQuery::class);
    $asOf = CarbonImmutable::parse('2026-05-15');

    expect($balances->clearedBalance($accountId, $this->user)->lines())
        ->toBe([Currency::Eur->value => 351_985, Currency::Usd->value => -22_100])
        ->and($balances->clearedBalanceAsOf($accountId, $this->user, $asOf)->lines())
        ->toBe([Currency::Eur->value => 350_985, Currency::Usd->value => -22_100]);
});

// A foreign account is not a balance of zero in some assumed currency: it is
// no lines at all, because nothing about it is the caller's to read.
it('gives a foreign user no lines at all', function (): void {
    $intruder = User::query()->create([
        'username' => 'per-currency-intruder',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $accountId = lpcAccount($this->db, $this->user->id, ['starting_balance_minor' => 285_000]);
    lpcRevolutRows($this->db, $this->user->id, $accountId);

    expect(app(AccountBalanceQuery::class)->currentBalance($accountId, $intruder)->lines())->toBe([]);
});
