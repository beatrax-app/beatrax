<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;

// The card answered with the forecast anchor, which is where a projection
// starts rather than what the account holds: a statement closing balance
// months old, and zero for a card carrying a real debt. Measured on the
// desktop, net worth read EUR 1,238.04 against a true position of EUR 5,065.53.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'nwtoday',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function nwtAccount(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId(array_merge([
        'user_id' => $userId,
        'name' => 'NWT Bank',
        'slug' => 'nwt-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00NWT'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ], $overrides));
}

function nwtTransaction(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $amountMinor): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nwt-'.$hex.'.csv',
        'sha256' => hash('sha256', 'nwt-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'nwt-fp-'.$hex),
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'nwt',
        'counterparty_name' => 'NWT',
        'normalization_version' => 1,
        'description' => 'nwt fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

// The shipped symptom: a statement summary anchored the card months back, so
// every transaction imported since was missing from the headline figure.
it('counts what has landed since the last statement, not the statement alone', function (): void {
    $accountId = nwtAccount($this->db, $this->user->id, ['starting_balance_minor' => 285_000]);

    $summaryRunId = $this->db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nwt-summary.csv',
        'sha256' => hash('sha256', 'nwt-summary'),
        'uploaded_at' => '2026-04-11 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-04-11 00:00:00',
        'updated_at' => '2026-04-11 00:00:00',
    ]);

    $this->db->connection()->table('statement_summaries')->insert([
        'user_id' => $this->user->id,
        'account_id' => $accountId,
        'import_run_id' => $summaryRunId,
        'iban_owner' => 'NL57ASNB0123456789',
        'period_start' => '2026-03-11 00:00:00',
        'period_end' => '2026-04-11 00:00:00',
        'closing_balance_minor' => 201_111,
        'closing_balance_currency' => Currency::Eur->value,
        'closing_balance_date' => '2026-04-11 00:00:00',
        'created_at' => '2026-04-11 00:00:00',
        'updated_at' => '2026-04-11 00:00:00',
    ]);

    nwtTransaction($this->db, $this->user->id, $accountId, '2026-06-01', 9_109);

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBe(294_109)
        ->and($netWorth->accounts[0]->balanceMinor)->toBe(294_109);
});

// A card with no statement anchored at zero while owing real money, so the
// liability simply left the roll-up. Asserting the roll-up alone proved
// nothing: the zero anchor lived in BalanceAnchorResolver, which this never
// called, so /forecast read EUR0.00 for the card that the dashboard one click
// away carried in full -- EUR6,681.85 against EUR6,127.85 on the same seed.
it('carries a card debt into the total instead of anchoring it at zero', function (): void {
    $accountId = nwtAccount($this->db, $this->user->id, [
        'name' => 'NWT Card',
        'kind' => AccountKind::IcsCard->value,
    ]);

    nwtTransaction($this->db, $this->user->id, $accountId, '2026-07-04', -70_400);

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);
    $anchor = app(BalanceAnchorResolver::class)->forAccount($accountId, $this->user);

    expect($netWorth->totalMinor)->toBe(-70_400)
        ->and($anchor->openingBalanceMinor)->toBe(-70_400)
        ->and($anchor->openingBalanceMinor)->toBe($netWorth->totalMinor);
});

// The two surfaces one click apart, on the shape that split them: a card whose
// only anchor is the rows themselves.
it('opens the forecast on the same card balance the dashboard rolls up', function (): void {
    $bankId = nwtAccount($this->db, $this->user->id, ['starting_balance_minor' => 612_785]);
    $cardId = nwtAccount($this->db, $this->user->id, [
        'name' => 'NWT Card',
        'kind' => AccountKind::IcsCard->value,
    ]);

    nwtTransaction($this->db, $this->user->id, $cardId, '2026-07-04', -55_400);

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);
    $resolver = app(BalanceAnchorResolver::class);
    $anchorSum = $resolver->forAccount($bankId, $this->user)->openingBalanceMinor
        + $resolver->forAccount($cardId, $this->user)->openingBalanceMinor;

    expect($netWorth->totalMinor)->toBe(557_385)
        ->and($anchorSum)->toBe($netWorth->totalMinor);
});

it('leaves a transaction dated after today out of the position', function (): void {
    $accountId = nwtAccount($this->db, $this->user->id, ['starting_balance_minor' => 100_000]);

    nwtTransaction($this->db, $this->user->id, $accountId, '2026-08-23', 2_500);
    nwtTransaction($this->db, $this->user->id, $accountId, '2026-09-15', 360_800);

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBe(102_500);
});

// The balance the reader typed in Settings is the only number they entered
// deliberately, so it outranks one detected from an import.
it('prefers the balance the reader entered over the one an import detected', function (): void {
    $accountId = nwtAccount($this->db, $this->user->id, [
        'starting_balance_minor' => 285_000,
        'opening_balance_minor' => 50_000,
        'opening_balance_as_of_date' => '2026-06-01',
    ]);

    nwtTransaction($this->db, $this->user->id, $accountId, '2026-05-01', 7_000);
    nwtTransaction($this->db, $this->user->id, $accountId, '2026-06-01', 1_000);

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBe(51_000);
});
