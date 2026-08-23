<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Modules\Forecasting\Public\Dto\BalanceAnchorDto;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

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
        'default_currency' => Currency::Eur->value,
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
        'opening_balance_currency' => Currency::Eur->value,
        'opening_balance_date' => '2026-04-01 00:00:00',
        'closing_balance_minor' => $closingMinor,
        'closing_balance_currency' => Currency::Eur->value,
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
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => Currency::Eur->value,
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

// The anchor is where the projection opens, and it opens on the money the
// account holds today. Frozen on a statement that closed on 11 April, the
// forecast page read EUR2,011.11 while the dashboard, pots and /reconcile all
// read EUR2,941.09 from the very same rows.
it('opens on the ledger balance today, not on a statement summary months old', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 09:00:00'));

    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::Bank->value, [
        'starting_balance_minor' => 150000,
        'starting_balance_date' => '2026-01-01',
    ]);
    barInsertStatementSummary($this->db, $this->user->id, $accountId, 201111, '2026-04-11');
    barInsertTransaction($this->db, $this->user->id, $accountId, 60000, '2026-04-20');
    barInsertTransaction($this->db, $this->user->id, $accountId, -15891, '2026-08-20');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor)->toBeInstanceOf(BalanceAnchorDto::class)
        ->and($anchor->openingBalanceMinor)->toBe(194109)
        ->and($anchor->source)->toBe('sum_of_transactions')
        ->and($anchor->currency)->toBe(Currency::Eur->value);
});

it('sums the history of a bank account that has no statement at all', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::Bank->value);
    barInsertTransaction($this->db, $this->user->id, $accountId, -1000, '2026-05-10');
    barInsertTransaction($this->db, $this->user->id, $accountId, 5000, '2026-05-11');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(4000);
    expect($anchor->source)->toBe('sum_of_transactions');
});

// The sum answers "what is on this account today", so a row dated after today
// is not on it yet. Unbounded, the balance line rose by the whole of the coming
// week on today and then ran flat through the days that carried the money.
it('leaves a future-dated transaction out of the sum, and says which day it is for', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 09:00:00'));

    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::Bank->value);
    barInsertTransaction($this->db, $this->user->id, $accountId, 5000, '2026-05-10');
    barInsertTransaction($this->db, $this->user->id, $accountId, 2500, '2026-05-15');
    barInsertTransaction($this->db, $this->user->id, $accountId, 360800, '2026-05-25');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(7500)
        ->and($anchor->source)->toBe('sum_of_transactions');
});

it('routes ics_card to the most recent card_statements row (negated open_balance)', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::IcsCard->value);
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
    barInsertTransaction($this->db, $this->user->id, $accountId, -70400, '2026-05-04');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    // An open balance of 50000 is money owed, so the signed running-balance
    // position is its negation, and the 70400 charged since the statement
    // closed is owed on top of it. The projector emits occurrences from today
    // forward, so a charge dated 4 May is never re-emitted and never doubled.
    expect($anchor->openingBalanceMinor)->toBe(-120400);
    expect($anchor->source)->toBe('ics_card_statement');
});

// Summing a card's own history would double-count the billing events the
// projection is about to re-emit, so a card with nothing to anchor on takes
// zero rather than the ledger balance every other kind takes.
it('keeps a card with no statement and no entered balance at zero', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::IcsCard->value);
    barInsertTransaction($this->db, $this->user->id, $accountId, -70400, '2026-05-04');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(0);
    expect($anchor->source)->toBe('ics_card_zero_anchor');
});

it('routes a card to accounts.opening_balance_minor when the user set one', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::IcsCard->value, [
        'opening_balance_minor' => 25000,
        'opening_balance_as_of_date' => '2026-05-01',
    ]);

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(25000);
    expect($anchor->source)->toBe('sum_of_transactions');
});

// The figure the reader types in Settings is a baseline with a date, not the
// position today: every row posted since has to be carried onto it. Frozen on
// the baseline, the forecast opened at EUR1,000 and ran flat for thirty days
// while the dashboard's own net worth, one card above it, read EUR3,706.72.
it('carries the reader-typed opening balance forward through the rows posted since its date', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 09:00:00'));

    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::Paypal->value, [
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-07-01',
    ]);
    barInsertTransaction($this->db, $this->user->id, $accountId, -99900, '2026-06-30');
    barInsertTransaction($this->db, $this->user->id, $accountId, 320000, '2026-07-01');
    barInsertTransaction($this->db, $this->user->id, $accountId, -145000, '2026-08-01');
    barInsertTransaction($this->db, $this->user->id, $accountId, -4120, '2026-08-23');
    barInsertTransaction($this->db, $this->user->id, $accountId, 320000, '2026-08-25');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(270880);
});

it('sums the history of a paypal account with no opening balance', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::Paypal->value);
    barInsertTransaction($this->db, $this->user->id, $accountId, 8000, '2026-05-12');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(8000);
    expect($anchor->source)->toBe('sum_of_transactions');
});

it('defaults to the base currency on the user-input path when the account has no default currency', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::IcsCard->value, [
        'default_currency' => '',
        'opening_balance_minor' => 25000,
        'opening_balance_as_of_date' => '2026-05-01',
    ]);

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->source)->toBe('sum_of_transactions');
    expect($anchor->currency)->toBe(Currency::Eur->value);
});

it('defaults to the base currency on the ledger-balance path when the account has no default currency', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::Paypal->value, ['default_currency' => '']);
    barInsertTransaction($this->db, $this->user->id, $accountId, 8000, '2026-05-12');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->source)->toBe('sum_of_transactions');
    expect($anchor->currency)->toBe(Currency::Eur->value);
});

it('defaults to the base currency on the card zero anchor when the account has no default currency', function (): void {
    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::IcsCard->value, ['default_currency' => '']);

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->source)->toBe('ics_card_zero_anchor');
    expect($anchor->currency)->toBe(Currency::Eur->value);
});

it('raises ModelNotFoundException for a missing or cross-user account id', function (): void {
    $otherUser = User::query()->create([
        'username' => 'anchor-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $otherAccountId = barInsertAccount($this->db, $otherUser->id, AccountKind::Bank->value);

    $call = fn (): BalanceAnchorDto => $this->resolver->forAccount($otherAccountId, $this->user);

    expect($call)->toThrow(ModelNotFoundException::class);
});

// The anchor carried an asOfDate saying which day its figure was true for, and
// nothing read it: a statement that closed in April was drawn as today's
// position. Every path resolves to today now, so there is no date to carry.
it('leaves a card charge dated after today out of what the card owes', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 09:00:00'));

    $accountId = barInsertAccount($this->db, $this->user->id, AccountKind::IcsCard->value);
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
    barInsertTransaction($this->db, $this->user->id, $accountId, -1000, '2026-05-04');
    barInsertTransaction($this->db, $this->user->id, $accountId, -90000, '2026-09-15');

    $anchor = $this->resolver->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(-51000);
});
