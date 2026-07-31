<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Models\Pot;

uses(RefreshDatabase::class);

/**
 * Wave 0 RED stubs for GOAL-02 and GOAL-03.
 *
 * These tests reference GoalProgressQuery (Plan 02) and the GoalProgressRow DTO
 * (Plan 02). They will error with "class not found" until Plan 02 lands — that
 * is correct Wave 0 RED behaviour.
 *
 * Covers:
 *   GOAL-02: linked-account contribution sum = credits (type IN transfer_in,
 *            income) posted >= start_date; FX-converted to base currency.
 *   GOAL-03: fractionComplete / progressState buckets (in_progress / reached /
 *            overdue); unlinked goal yields 0/target with no projection;
 *            cross-user isolation (another user's transactions excluded).
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

/** Persist a transaction for $user. Reused verbatim from BudgetProgressQueryTest. */
function goalTx(int $userId, int $accountId, int $runId, int $amountMinor, string $type, string $postedAt): void
{
    static $i = 0;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "M{$i}",
        'counterparty_normalized' => "m{$i}",
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad((string) $i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

// ---------------------------------------------------------------------------
// GOAL-02: contribution sum from linked account since start_date
// ---------------------------------------------------------------------------

it('sums transfer_in and income credits since start_date for a linked goal', function (): void {
    $startDate = CarbonImmutable::now()->subDays(10)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 200000,
        'start_date' => $startDate,
        'status' => 'active',
    ]);

    // These should count: transfer_in and income on/after start_date.
    goalTx($this->user->id, $this->account->id, $this->run->id, 50000, 'transfer_in', $startDate);
    goalTx($this->user->id, $this->account->id, $this->run->id, 30000, 'income', CarbonImmutable::now()->toDateString());

    // This should NOT count: expense type.
    goalTx($this->user->id, $this->account->id, $this->run->id, -10000, 'expense', CarbonImmutable::now()->toDateString());

    // This should NOT count: before start_date.
    goalTx($this->user->id, $this->account->id, $this->run->id, 20000, 'transfer_in', CarbonImmutable::now()->subDays(20)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->contributedMinor)->toBe(80000);
});

it('excludes transfer_out and expense from contribution sum', function (): void {
    $startDate = CarbonImmutable::now()->subDays(5)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'status' => 'active',
    ]);

    goalTx($this->user->id, $this->account->id, $this->run->id, 40000, 'transfer_in', CarbonImmutable::now()->toDateString());
    goalTx($this->user->id, $this->account->id, $this->run->id, -15000, 'expense', CarbonImmutable::now()->toDateString());
    goalTx($this->user->id, $this->account->id, $this->run->id, -25000, 'transfer_out', CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->contributedMinor)->toBe(40000);
});

// ---------------------------------------------------------------------------
// GOAL-03: fractionComplete, progressState buckets
// ---------------------------------------------------------------------------

it('computes fractionComplete and reports in_progress when below target', function (): void {
    $startDate = CarbonImmutable::now()->subDays(5)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(60)->toDateString(),
        'status' => 'active',
    ]);

    goalTx($this->user->id, $this->account->id, $this->run->id, 40000, 'transfer_in', CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->fractionComplete)->toBe(0.4);
    expect($rows[0]->progressState)->toBe('in_progress');
});

it('reports reached when contributions meet or exceed target', function (): void {
    $startDate = CarbonImmutable::now()->subDays(5)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 50000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(30)->toDateString(),
        'status' => 'active',
    ]);

    goalTx($this->user->id, $this->account->id, $this->run->id, 50000, 'transfer_in', CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->fractionComplete)->toBeGreaterThanOrEqual(1.0);
    expect($rows[0]->progressState)->toBe('reached');
});

it('reports overdue when past target_date without reaching target', function (): void {
    $startDate = CarbonImmutable::now()->subDays(60)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->subDay()->toDateString(), // yesterday = past due
        'status' => 'active',
    ]);

    goalTx($this->user->id, $this->account->id, $this->run->id, 30000, 'transfer_in', CarbonImmutable::now()->subDays(10)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->progressState)->toBe('overdue');
});

it('returns 0 contributed and no projection for an unlinked goal', function (): void {
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => null, // unlinked
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(5)->toDateString(),
        'status' => 'active',
    ]);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->contributedMinor)->toBe(0);
    expect($rows[0]->fractionComplete)->toBe(0.0);
    expect($rows[0]->projectedFinishDate)->toBeNull();
});

it('excludes another users transactions from contribution sum', function (): void {
    $other = User::create([
        'username' => 'mallory',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $otherAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'Mallory ASN',
        'slug' => 'mallory-asn',
        'kind' => 'asn',
        'iban' => 'NL57ASNB9876543210',
        'default_currency' => 'EUR',
    ]);
    $otherRun = ImportRun::create([
        'user_id' => $other->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/y.xml',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $startDate = CarbonImmutable::now()->subDays(5)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'status' => 'active',
    ]);

    // Wessel's legit contribution.
    goalTx($this->user->id, $this->account->id, $this->run->id, 20000, 'transfer_in', CarbonImmutable::now()->toDateString());

    // Mallory's transaction on the SAME account — should be excluded.
    goalTx($other->id, $this->account->id, $otherRun->id, 80000, 'transfer_in', CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->contributedMinor)->toBe(20000);
});

it('returns an empty list when the user has no active goals', function (): void {
    expect(app(GoalProgressQuery::class)->forUser($this->user))->toBe([]);
});

// ---------------------------------------------------------------------------
// CR-02: contributions are converted into the goal's target_currency, NOT the
// user's current base_currency (D-05 makes target_currency immutable so the two
// can diverge). Numerator and denominator must share a unit.
// ---------------------------------------------------------------------------

it('converts contributions into the goal target_currency when it diverges from base_currency', function (): void {
    // User's base currency is EUR; the goal's target currency is USD.
    $this->user->update(['base_currency' => 'EUR']);

    // Latest EUR->USD rate so the FX service can convert EUR contributions to USD.
    DB::table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => 'USD',
        'rate_date' => CarbonImmutable::now()->toDateString(),
        'rate' => '1.10000000',
        'source' => 'test',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $startDate = CarbonImmutable::now()->subDays(5)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 110000,        // 1 100.00 USD
        'target_currency' => 'USD',
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(30)->toDateString(),
        'status' => 'active',
    ]);

    // A 1 000.00 EUR contribution → 1 100.00 USD at 1.10 → exactly the target.
    goalTx($this->user->id, $this->account->id, $this->run->id, 100000, 'transfer_in', CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // If the contribution were (wrongly) converted to base EUR it would stay
    // 100000 and the goal would read 0.909 / in_progress. Converted to USD it is
    // 110000 → fraction 1.0 → reached.
    expect($rows[0]->contributedMinor)->toBe(110000);
    expect($rows[0]->currency)->toBe('USD');
    expect($rows[0]->fractionComplete)->toBe(1.0);
    expect($rows[0]->progressState)->toBe('reached');
});

// CR-02 (inverse, common real case): a foreign-currency CONTRIBUTION is
// converted INTO an EUR goal's target_currency via the EUR-based provider's
// derived cross-rate. Validates the conversion direction the typical user hits
// (USD merchant credit landing in a EUR goal), which the divergence test above
// only exercises in the opposite direction.
it('converts a USD contribution into the goal target_currency for a EUR goal', function (): void {
    $this->user->update(['base_currency' => 'EUR']);

    // EUR->USD = 1.10, so the derived USD->EUR cross-rate is 1/1.10.
    DB::table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => 'USD',
        'rate_date' => CarbonImmutable::now()->toDateString(),
        'rate' => '1.10000000',
        'source' => 'test',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $startDate = CarbonImmutable::now()->subDays(5)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 200000,        // 2 000.00 EUR
        'target_currency' => 'EUR',
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(60)->toDateString(),
        'status' => 'active',
    ]);

    // A 1 100.00 USD transfer_in → 1 000.00 EUR at 1/1.10 (exact, HALF_UP).
    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => 110000,
        'currency' => 'USD',
        'settled_amount_minor' => 110000,
        'settled_currency' => 'USD',
        'counterparty_name' => 'USD merchant',
        'counterparty_normalized' => 'usd merchant',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 9001,
        'fingerprint' => str_pad('9001', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // Converted to EUR: 110000 USD / 1.10 = 100000 EUR (not the raw 110000,
    // which would wrongly read 0.55 in EUR minor units).
    expect($rows[0]->contributedMinor)->toBe(100000);
    expect($rows[0]->currency)->toBe('EUR');
    expect($rows[0]->fractionComplete)->toBe(0.5);
    expect($rows[0]->progressState)->toBe('in_progress');
});

// ---------------------------------------------------------------------------
// POTS-04 / D-10: linked pot balance drives goal progress
//
// These tests reference PotBalanceQuery (Plan 02) and GoalProgressQuery being
// wired to inject PotBalanceQuery (Plan 04). They will fail until Plan 04
// lands — that is correct Wave 0 RED behaviour.
// ---------------------------------------------------------------------------

it('uses the linked pot balance as contributedMinor instead of the transaction sum', function (): void {
    $startDate = CarbonImmutable::now()->subDays(10)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'status' => 'active',
    ]);

    // Create a pot linked to this goal (goal_id on pots table = the link, D-10 / POTS-04)
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $goal->id,
    ]);

    // Fund the pot — this is the contribution via the linked-pot path.
    DB::table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => $pot->id,
        'counterpart_pot_id' => null,
        'amount_minor' => 40000,
        'currency' => 'EUR',
        'kind' => 'fund',
        'memo' => null,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    // Also add transfer_in transactions on the goal's account — these should
    // NOT count because the linked-pot path overrides the transaction-sum path.
    goalTx($this->user->id, $this->account->id, $this->run->id, 70000, 'transfer_in', CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // contributedMinor must be the pot balance (40000), not the transaction sum (70000).
    expect($rows[0]->contributedMinor)->toBe(40000);
});

it('converts a linked pot balance into the goal target_currency when they differ', function (): void {
    // EUR->USD = 1.10 gives the derived USD->EUR cross-rate the pot balance
    // is converted with (a USD pot funding a EUR goal).
    DB::table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => 'USD',
        'rate_date' => CarbonImmutable::now()->toDateString(),
        'rate' => '1.10000000',
        'source' => 'test',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 200000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'status' => 'active',
    ]);

    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $goal->id,
        'currency' => 'USD',
    ]);

    DB::table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => $pot->id,
        'counterpart_pot_id' => null,
        'amount_minor' => 110000,
        'currency' => 'USD',
        'kind' => 'fund',
        'memo' => null,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // 110000 USD / 1.10 = 100000 EUR — the pot path FX-converts into the
    // goal's target_currency rather than reporting the raw USD minor units.
    expect($rows[0]->contributedMinor)->toBe(100000);
    expect($rows[0]->currency)->toBe('EUR');
});

it('falls back to transfer tracking for a goal with no linked pot', function (): void {
    $startDate = CarbonImmutable::now()->subDays(10)->toDateString();
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'status' => 'active',
    ]);

    // No pot linked — Phase 2 transfer-tracking behavior is unchanged.
    goalTx($this->user->id, $this->account->id, $this->run->id, 30000, 'transfer_in', CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // contributedMinor must be the transaction sum (30000), not zero.
    expect($rows[0]->contributedMinor)->toBe(30000);
});
