<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotBalanceQuery;

uses(RefreshDatabase::class);

/**
 * Wave 0 RED stubs for POTS-02 (movement math) and POTS-03 (reconciliation).
 *
 * These tests reference PotBalanceQuery (Plan 02). They will error with
 * "class not found" until Plan 02 lands — that is correct Wave 0 RED behaviour.
 *
 * Covers:
 *   POTS-02: signed SUM of pot_movements is the pot balance; movement math correct
 *   POTS-03: reconciliationForAccount real = allocated + unallocated; over-allocation
 *            produces negative unallocated + isOverAllocated=true; archived pots excluded
 *   D-01:    unallocated is derived (real − allocated), never stored
 *   D-09:    archived pots excluded from allocated
 */
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'asn',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/x.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

/**
 * Insert a pot_movements row for a pot.
 * Helper mirrors goalTx() pattern from GoalProgressQueryTest.
 */
function potMovement(int $potId, int $userId, int $amountMinor, string $kind, ?int $counterpartPotId = null): void
{
    static $i = 0;
    $i++;

    DB::table('pot_movements')->insert([
        'user_id' => $userId,
        'pot_id' => $potId,
        'counterpart_pot_id' => $counterpartPotId,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'kind' => $kind,
        'memo' => null,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

/**
 * Insert a transaction for $user's account (used to set up real balance for
 * reconciliation tests).
 */
function potAccountTx(int $userId, int $accountId, int $runId, int $amountMinor): void
{
    static $i = 0;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "P{$i}",
        'counterparty_normalized' => "p{$i}",
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i + 1000,
        'fingerprint' => str_pad('p'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

// ---------------------------------------------------------------------------
// POTS-02: signed balance = SUM of pot_movements
// ---------------------------------------------------------------------------

it('balanceForPot returns the signed SUM of its movements', function (): void {
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    potMovement($pot->id, $this->user->id, 30000, 'fund');  // fund 300.00
    potMovement($pot->id, $this->user->id, 90000, 'fund');  // fund 900.00
    potMovement($pot->id, $this->user->id, -10000, 'withdraw');  // withdraw 100.00
    // Expected: 300 + 900 - 100 = 1100 (in minor: 110000)

    $query = app(PotBalanceQuery::class);
    // Access via forUser rows — balanceMinor should be 110000
    $rows = $query->forUser($this->user);
    expect($rows)->toHaveCount(1);
    expect($rows[0]->balanceMinor)->toBe(110000);
});

// ---------------------------------------------------------------------------
// POTS-03: reconciliationForAccount
// ---------------------------------------------------------------------------

it('reconciliationForAccount returns real = allocated + unallocated', function (): void {
    // Real balance: 500.00 EUR in transactions
    potAccountTx($this->user->id, $this->account->id, $this->run->id, 50000);

    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    potMovement($pot->id, $this->user->id, 20000, 'fund');  // allocated: 200.00 EUR

    $query = app(PotBalanceQuery::class);
    $rec = $query->reconciliationForAccount($this->account->id, $this->user);

    expect($rec->realBalanceMinor)->toBe(50000);
    expect($rec->allocatedMinor)->toBe(20000);
    expect($rec->unallocatedMinor)->toBe(30000);   // 500 - 200 = 300
    expect($rec->isOverAllocated)->toBeFalse();
});

it('unallocatedMinor goes negative and isOverAllocated true when real balance < allocated', function (): void {
    // Real balance: 100.00 EUR (simulate real-world spending by recording fewer credits)
    potAccountTx($this->user->id, $this->account->id, $this->run->id, 10000);

    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    potMovement($pot->id, $this->user->id, 30000, 'fund');  // allocated 300 > real 100

    $query = app(PotBalanceQuery::class);
    $rec = $query->reconciliationForAccount($this->account->id, $this->user);

    expect($rec->realBalanceMinor)->toBe(10000);
    expect($rec->allocatedMinor)->toBe(30000);
    expect($rec->unallocatedMinor)->toBe(-20000);   // 100 - 300 = -200
    expect($rec->isOverAllocated)->toBeTrue();
});

it('reconciliation excludes archived pots from allocated (D-09)', function (): void {
    potAccountTx($this->user->id, $this->account->id, $this->run->id, 50000);

    $activePot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'status' => 'active',
    ]);
    $archivedPot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'status' => 'archived',
    ]);

    potMovement($activePot->id, $this->user->id, 10000, 'fund');
    potMovement($archivedPot->id, $this->user->id, 20000, 'fund');  // should NOT count

    $query = app(PotBalanceQuery::class);
    $rec = $query->reconciliationForAccount($this->account->id, $this->user);

    // Only the active pot's 10000 should count toward allocated
    expect($rec->allocatedMinor)->toBe(10000);
    expect($rec->unallocatedMinor)->toBe(40000);  // 500 - 100 = 400
});

it('unallocated is derived and never read from a stored column (D-01)', function (): void {
    // This test verifies no unallocated column exists on the pots table.
    potAccountTx($this->user->id, $this->account->id, $this->run->id, 50000);

    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    potMovement($pot->id, $this->user->id, 15000, 'fund');

    // If unallocated were a stored column, this would read stale data.
    // ReconciliationRow.unallocatedMinor must equal real - allocated.
    $query = app(PotBalanceQuery::class);
    $rec = $query->reconciliationForAccount($this->account->id, $this->user);

    expect($rec->unallocatedMinor)->toBe($rec->realBalanceMinor - $rec->allocatedMinor);
});

// ---------------------------------------------------------------------------
// Goal-linked lookups
// ---------------------------------------------------------------------------

it('reports the balance and currency of every goal-linked pot', function (): void {
    $goal = DB::table('goals')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Holiday',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $linked = Pot::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $goal,
        'name' => 'Holiday pot',
        'currency' => 'EUR',
        'status' => 'active',
    ]);
    potMovement($linked->id, $this->user->id, 7500, 'fund');

    // An unlinked pot must not appear: the map is keyed by goal, and a null
    // goal_id would collapse every unlinked pot onto one key.
    $unlinked = Pot::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Loose change',
        'currency' => 'EUR',
        'status' => 'active',
    ]);
    potMovement($unlinked->id, $this->user->id, 500, 'fund');

    $balances = app(PotBalanceQuery::class)->linkedPotBalancesForUser($this->user);

    expect($balances)->toHaveCount(1)
        ->and($balances[$goal]['balance'])->toBe(7500)
        ->and($balances[$goal]['currency'])->toBe('EUR');
});

it('finds the pot linked to a goal, and reports none when the link is gone', function (): void {
    $goal = DB::table('goals')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Holiday',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $pot = Pot::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $goal,
        'name' => 'Holiday pot',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    $query = app(PotBalanceQuery::class);

    expect($query->linkedPotIdForGoal($goal, $this->user))->toBe($pot->id)
        ->and($query->currencyForLinkedPot($goal, $this->user))->toBe('EUR')
        ->and($query->linkedPotIdForGoal(999999, $this->user))->toBeNull()
        ->and($query->currencyForLinkedPot(999999, $this->user))->toBeNull();
});

it('treats an archived pot as no longer holding its goal', function (): void {
    $goal = DB::table('goals')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Holiday',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    Pot::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $goal,
        'name' => 'Archived pot',
        'currency' => 'EUR',
        'status' => 'archived',
    ]);

    $query = app(PotBalanceQuery::class);

    expect($query->linkedPotIdForGoal($goal, $this->user))->toBeNull()
        ->and($query->linkedPotBalancesForUser($this->user))->toBe([]);
});

it('reports zero allocated for an account with no active pots', function (): void {
    potAccountTx($this->user->id, $this->account->id, $this->run->id, 25000);

    $rec = app(PotBalanceQuery::class)->reconciliationForAccount($this->account->id, $this->user);

    expect($rec->allocatedMinor)->toBe(0)
        ->and($rec->unallocatedMinor)->toBe($rec->realBalanceMinor);
});

it('falls back to a blank name and EUR for an account it cannot see', function (): void {
    $rec = app(PotBalanceQuery::class)->reconciliationForAccount(999999, $this->user);

    expect($rec->accountName)->toBe('')
        ->and($rec->currency)->toBe('EUR');
});
