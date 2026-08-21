<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Services\GoalContributionWriter;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Models\Pot;

uses(RefreshDatabase::class);

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
        'kind' => 'bank',
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

function goalTx(int $userId, int $accountId, int $runId, int $amountMinor, string $type, string $postedAt): Transaction
{
    static $i = 0;
    $i++;

    return Transaction::create([
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

function goalContribution(User $user, int $accountId, int $runId, int $goalId, int $amountMinor, string $postedAt): void
{
    $tx = goalTx($user->id, $accountId, $runId, $amountMinor, 'transfer_in', $postedAt);
    app(GoalContributionWriter::class)->attribute($user, $goalId, $tx->id);
}

it('computes fractionComplete and reports in_progress when below target', function (): void {
    $startDate = CarbonImmutable::now()->subDays(5)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(60)->toDateString(),
        'status' => 'active',
    ]);

    goalContribution($this->user, $this->account->id, $this->run->id, $goal->id, 40000, CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->fractionComplete)->toBe(0.4);
    expect($rows[0]->progressState)->toBe('in_progress');
});

it('reports reached when contributions meet or exceed target', function (): void {
    $startDate = CarbonImmutable::now()->subDays(5)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 50000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(30)->toDateString(),
        'status' => 'active',
    ]);

    goalContribution($this->user, $this->account->id, $this->run->id, $goal->id, 50000, CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->fractionComplete)->toBeGreaterThanOrEqual(1.0);
    expect($rows[0]->progressState)->toBe('reached');
});

it('reports overdue when past target_date without reaching target', function (): void {
    $startDate = CarbonImmutable::now()->subDays(60)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->subDay()->toDateString(), // yesterday = past due
        'status' => 'active',
    ]);

    goalContribution($this->user, $this->account->id, $this->run->id, $goal->id, 30000, CarbonImmutable::now()->subDays(10)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->progressState)->toBe('overdue');
});

it('returns 0 contributed and no projection for a goal nothing is attributed to', function (): void {
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(5)->toDateString(),
        'status' => 'active',
    ]);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->contributedMinor)->toBe(0);
    expect($rows[0]->fractionComplete)->toBe(0.0);
    expect($rows[0]->projectedFinishDate)->toBeNull();
});

it('excludes another users transactions on the same account from the contribution sum', function (): void {
    $other = User::create([
        'username' => 'mallory',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $otherAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'Mallory ASN',
        'slug' => 'mallory-asn',
        'kind' => 'bank',
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
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'status' => 'active',
    ]);

    goalContribution($this->user, $this->account->id, $this->run->id, $goal->id, 20000, CarbonImmutable::now()->toDateString());

    // Mallory's transaction on the same account, aimed at Wessel's goal — the
    // writer refuses it, so it never reaches the sum.
    $foreign = goalTx($other->id, $this->account->id, $otherRun->id, 80000, 'transfer_in', CarbonImmutable::now()->toDateString());
    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $foreign->id);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->contributedMinor)->toBe(20000);
});

it('returns an empty list when the user has no active goals', function (): void {
    expect(app(GoalProgressQuery::class)->forUser($this->user))->toBe([]);
});

it('converts contributions into the goal target_currency when it diverges from base_currency', function (): void {
    $this->user->update(['base_currency' => 'EUR']);

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
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 110000,        // 1 100.00 USD
        'target_currency' => 'USD',
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(30)->toDateString(),
        'status' => 'active',
    ]);

    // A 1 000.00 EUR contribution → 1 100.00 USD at 1.10 → exactly the target.
    goalContribution($this->user, $this->account->id, $this->run->id, $goal->id, 100000, CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // If the contribution were (wrongly) converted to base EUR it would stay
    // 100000 and the goal would read 0.909 / in_progress. Converted to USD it is
    // 110000 → fraction 1.0 → reached.
    expect($rows[0]->contributedMinor)->toBe(110000);
    expect($rows[0]->currency)->toBe('USD');
    expect($rows[0]->fractionComplete)->toBe(1.0);
    expect($rows[0]->progressState)->toBe('reached');
});

// The inverse and commoner direction: a USD credit landing in a EUR goal, which
// has to go through the EUR-based provider's derived cross-rate. The divergence
// case above only exercises the other way round.
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
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 200000,        // 2 000.00 EUR
        'target_currency' => 'EUR',
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(60)->toDateString(),
        'status' => 'active',
    ]);

    // A 1 100.00 USD transfer_in → 1 000.00 EUR at 1/1.10 (exact, HALF_UP).
    $usdTx = Transaction::create([
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
    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $usdTx->id);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // Converted to EUR: 110000 USD / 1.10 = 100000 EUR (not the raw 110000,
    // which would wrongly read 0.55 in EUR minor units).
    expect($rows[0]->contributedMinor)->toBe(100000);
    expect($rows[0]->currency)->toBe('EUR');
    expect($rows[0]->fractionComplete)->toBe(0.5);
    expect($rows[0]->progressState)->toBe('in_progress');
});

it('uses the linked pot balance as contributedMinor instead of the attributed sum', function (): void {
    $startDate = CarbonImmutable::now()->subDays(10)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'status' => 'active',
    ]);

    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $goal->id,
    ]);

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

    // Also attributed to the same goal, and must not count: the linked-pot path
    // overrides the attributed-transaction path.
    goalContribution($this->user, $this->account->id, $this->run->id, $goal->id, 70000, CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

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

it('falls back to attributed transactions for a goal with no linked pot', function (): void {
    $startDate = CarbonImmutable::now()->subDays(10)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => $startDate,
        'status' => 'active',
    ]);

    goalContribution($this->user, $this->account->id, $this->run->id, $goal->id, 30000, CarbonImmutable::now()->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->contributedMinor)->toBe(30000);
});
