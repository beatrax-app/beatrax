<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Modules\Forecasting\Public\Dto\BalanceAnchorDto;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    /** @var BalanceAnchorResolver $resolver */
    $resolver = $this->app->make(BalanceAnchorResolver::class);
    $this->resolver = $resolver;

    $this->user = User::query()->create([
        'username' => 'anchor',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function barInsertAccount(DatabaseManager $db, int $userId, string $kind, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(4));
    $base = [
        'user_id' => $userId,
        'name' => 'Test '.$kind,
        'slug' => $kind.'-'.$suffix,
        'kind' => $kind,
        'iban' => 'NL00TEST'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ];

    return $db->connection()->table('accounts')->insertGetId(array_merge($base, $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function barInsertStatementSummary(DatabaseManager $db, int $userId, int $accountId, int $closingMinor, string $periodEnd, array $overrides = []): void
{
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/anchor-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'anchor-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
    $db->connection()->table('statement_summaries')->insert(array_merge([
        'user_id' => $userId,
        'import_run_id' => $runId,
        'account_id' => $accountId,
        'iban_owner' => 'Test',
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => $periodEnd.' 00:00:00',
        'opening_balance_minor' => 100000,
        'opening_balance_currency' => 'EUR',
        'opening_balance_date' => '2026-04-01 00:00:00',
        'closing_balance_minor' => $closingMinor,
        'closing_balance_currency' => 'EUR',
        'closing_balance_date' => $periodEnd.' 00:00:00',
        'entry_count' => 1,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ], $overrides));
}

function barInsertTransaction(DatabaseManager $db, int $userId, int $accountId, int $amountMinor, string $postedAt): void
{
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/anchor-tx-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'anchor-tx-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'anchor-'.bin2hex(random_bytes(8))),
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'anchor-test',
        'counterparty_name' => 'Anchor Test',
        'normalization_version' => 1,
        'description' => 'anchor fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

it('routes asn to the most recent statement_summaries.closing_balance_minor', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, 'bank');
    barInsertStatementSummary($this->db, $this->user->id, $accountId, 123456, '2026-04-30');
    barInsertStatementSummary($this->db, $this->user->id, $accountId, 145000, '2026-05-31');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor)->toBeInstanceOf(BalanceAnchorDto::class);
    expect($anchor->openingBalanceMinor)->toBe(145000);
    expect($anchor->source)->toBe('asn_statement_summary');
    expect($anchor->currency)->toBe('EUR');
    expect($anchor->asOfDate->toDateString())->toBe('2026-05-31');
});

it('falls through to the transactions sum for an asn account with no statement_summaries row', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, 'bank');
    barInsertTransaction($this->db, $this->user->id, $accountId, -1000, '2026-05-10');
    barInsertTransaction($this->db, $this->user->id, $accountId, 5000, '2026-05-11');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(4000);
    expect($anchor->source)->toBe('sum_of_transactions');
    expect($anchor->asOfDate->toDateString())->toBe('1970-01-01');
});

it('routes ics_card to the most recent card_statements row (negated open_balance)', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, 'ics_card');
    $this->db->connection()->table('card_statements')->insert([
        'user_id' => $this->user->id,
        'account_id' => $accountId,
        'import_run_id' => null,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 00:00:00',
        'total_amount_minor' => -50000,
        'open_balance_minor' => 50000,
        'state' => 'open',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    // An open balance of 50000 is money owed, so the signed running-balance
    // position is its negation.
    expect($anchor->openingBalanceMinor)->toBe(-50000);
    expect($anchor->source)->toBe('ics_card_statement');
});

it('routes paypal to accounts.opening_balance_minor when the user set one', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, 'paypal', [
        'opening_balance_minor' => 25000,
        'opening_balance_as_of_date' => '2026-05-01',
    ]);

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(25000);
    expect($anchor->source)->toBe('user_input_opening_balance');
    expect($anchor->asOfDate->toDateString())->toBe('2026-05-01');
});

it('falls through to the transactions sum for a paypal account with no opening balance', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, 'paypal');
    barInsertTransaction($this->db, $this->user->id, $accountId, 8000, '2026-05-12');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(8000);
    expect($anchor->source)->toBe('sum_of_transactions');
});

it('defaults to the base currency when the statement summary carries no closing currency', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, 'asn');
    barInsertStatementSummary($this->db, $this->user->id, $accountId, 50000, '2026-04-30', ['closing_balance_currency' => '']);

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->source)->toBe('asn_statement_summary');
    expect($anchor->currency)->toBe('EUR');
});

it('defaults to the base currency on the user-input path when the account has no default currency', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, 'paypal', [
        'default_currency' => '',
        'opening_balance_minor' => 25000,
        'opening_balance_as_of_date' => '2026-05-01',
    ]);

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->source)->toBe('user_input_opening_balance');
    expect($anchor->currency)->toBe('EUR');
});

it('defaults to the base currency on the transactions-sum path when the account has no default currency', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, 'paypal', ['default_currency' => '']);
    barInsertTransaction($this->db, $this->user->id, $accountId, 8000, '2026-05-12');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->source)->toBe('sum_of_transactions');
    expect($anchor->currency)->toBe('EUR');
});

it('raises ModelNotFoundException for a missing or cross-user account id', function (): void {
    $otherUser = User::query()->create([
        'username' => 'anchor-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $otherAccountId = barInsertAccount($this->db, $otherUser->id, 'bank');

    $call = fn (): BalanceAnchorDto => $this->resolver->forAccount($otherAccountId, $this->user);

    expect($call)->toThrow(ModelNotFoundException::class);
});
