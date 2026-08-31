<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\CardStatementStateMachine;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

// A credit carried into a statement is money the reader has already paid: the
// carry-forward pass points it at the next open statement, and the resolver
// settles that statement to zero with open_balance MINUS the credit. Reading
// the balance raw, the forecast deducted a settlement no pass would match.

function creditCarryUser(): User
{
    return User::query()->create([
        'username' => 'credit-carry-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function creditCarryAccount(User $user, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'carry '.$kind,
        'slug' => 'carry-'.$kind.'-'.bin2hex(random_bytes(3)),
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function creditCarryStatement(DatabaseManager $db, User $user, Account $account, string $start, string $end, int $totalMinor, int $openMinor, string $state, string $currency = 'EUR'): int
{
    $now = CarbonImmutable::now()->toDateTimeString();

    return (int) $db->connection()->table('card_statements')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => null,
        'period_start' => $start,
        'period_end' => $end,
        'total_amount_minor' => $totalMinor,
        'open_balance_minor' => $openMinor,
        'currency' => $currency,
        'state' => $state,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function creditCarryCredit(DatabaseManager $db, User $user, int $fromStatementId, ?int $toStatementId, int $amountMinor, string $currency = 'EUR'): void
{
    $now = CarbonImmutable::now()->toDateTimeString();
    $db->connection()->table('card_statement_credits')->insert([
        'user_id' => $user->id,
        'from_statement_id' => $fromStatementId,
        'to_statement_id' => $toStatementId,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'reason' => 'refund_after_close',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = creditCarryUser();
    $this->bank = creditCarryAccount($this->user, 'bank', 'NL57ASNB0123456789');
    $this->ics = creditCarryAccount($this->user, 'ics_card', 'ICS-CARRY');

    /** @var CardStatementQuery $query */
    $query = $this->app->make(CardStatementQuery::class);
    $this->query = $query;
});

it('projects the payment the resolver will settle the statement with, not the statement total', function (): void {
    $closed = creditCarryStatement($this->db, $this->user, $this->ics, '2026-03-18 00:00:00', '2026-04-17 00:00:00', -20000, 0, 'settled');
    $open = creditCarryStatement($this->db, $this->user, $this->ics, '2026-04-18 00:00:00', '2026-05-17 00:00:00', -50000, 50000, 'open');
    creditCarryCredit($this->db, $this->user, $closed, $open, 7500);

    $next = $this->query->nextSettlementForUser($this->user);

    expect($next)->not->toBeNull();
    expect($next->amount->toMinor())->toBe(42500);

    // The figure above is exactly the payment that closes the statement: the
    // state machine is handed payment + credits and lands on zero.
    /** @var CardStatementStateMachine $machine */
    $machine = $this->app->make(CardStatementStateMachine::class);
    $settlement = $machine->applySettlement($open, 42500 + 7500, $this->user);

    expect($settlement->newOpenMinor)->toBe(0);
    expect($settlement->newState)->toBe('settled');
});

// priorCreditsMinor() sums only credits denominated in the statement's own
// currency, because a credit in another money is a different quantity. The
// projection has to draw the same line or it deducts an unlike amount.
it('ignores a credit denominated in another currency', function (): void {
    $closed = creditCarryStatement($this->db, $this->user, $this->ics, '2026-03-18 00:00:00', '2026-04-17 00:00:00', -20000, 0, 'settled', 'USD');
    $open = creditCarryStatement($this->db, $this->user, $this->ics, '2026-04-18 00:00:00', '2026-05-17 00:00:00', -50000, 50000, 'open');
    creditCarryCredit($this->db, $this->user, $closed, $open, 7500, 'USD');

    $next = $this->query->nextSettlementForUser($this->user);

    expect($next)->not->toBeNull();
    expect($next->amount->toMinor())->toBe(50000);
});

// A credit still waiting for a destination is invisible to the resolver's sum
// too, so the projection must not spend it either.
it('ignores a credit that has not been pointed at a statement yet', function (): void {
    $closed = creditCarryStatement($this->db, $this->user, $this->ics, '2026-03-18 00:00:00', '2026-04-17 00:00:00', -20000, 0, 'overpaid');
    creditCarryStatement($this->db, $this->user, $this->ics, '2026-04-18 00:00:00', '2026-05-17 00:00:00', -50000, 50000, 'open');
    creditCarryCredit($this->db, $this->user, $closed, null, 7500);

    $next = $this->query->nextSettlementForUser($this->user);

    expect($next)->not->toBeNull();
    expect($next->amount->toMinor())->toBe(50000);
});

// A surplus larger than what the next statement asks for leaves nothing to pay,
// and a projection cannot deduct a negative settlement from the bank account.
it('floors the projection at zero when the credit covers the whole statement', function (): void {
    $closed = creditCarryStatement($this->db, $this->user, $this->ics, '2026-03-18 00:00:00', '2026-04-17 00:00:00', -90000, 0, 'overpaid');
    $open = creditCarryStatement($this->db, $this->user, $this->ics, '2026-04-18 00:00:00', '2026-05-17 00:00:00', -50000, 50000, 'open');
    creditCarryCredit($this->db, $this->user, $closed, $open, 60000);

    $next = $this->query->nextSettlementForUser($this->user);

    expect($next)->not->toBeNull();
    expect($next->amount->toMinor())->toBe(0);
});

// Another reader's credit must not reduce this reader's projection, which is
// the one predicate a sum over a shared table is easiest to lose.
it('spends only the reader s own credits', function (): void {
    $other = creditCarryUser();
    $closed = creditCarryStatement($this->db, $this->user, $this->ics, '2026-03-18 00:00:00', '2026-04-17 00:00:00', -20000, 0, 'settled');
    $open = creditCarryStatement($this->db, $this->user, $this->ics, '2026-04-18 00:00:00', '2026-05-17 00:00:00', -50000, 50000, 'open');
    creditCarryCredit($this->db, $other, $closed, $open, 7500);

    $next = $this->query->nextSettlementForUser($this->user);

    expect($next)->not->toBeNull();
    expect($next->amount->toMinor())->toBe(50000);
});
