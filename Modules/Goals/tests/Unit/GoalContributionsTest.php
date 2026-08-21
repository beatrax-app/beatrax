<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Services\GoalContributionQuery;
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
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function contributionTx(int $userId, int $accountId, int $runId, int $amountMinor, string $postedAt, string $currency = 'EUR'): Transaction
{
    static $i = 1000;
    $i++;

    return Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => "C{$i}",
        'counterparty_normalized' => "c{$i}",
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad((string) $i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

/** @return array<int, int> goal id => contributed minor */
function contributedByGoalId(User $user): array
{
    $rows = app(GoalProgressQuery::class)->forUser($user);
    $out = [];
    foreach ($rows as $row) {
        $out[$row->id] = $row->contributedMinor;
    }

    return $out;
}

it('gives two goals over one account their own contributed figures', function (): void {
    $tyres = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Winter tyres',
        'target_minor' => 60000,
        'start_date' => CarbonImmutable::today()->subDays(30)->toDateString(),
        'status' => 'active',
    ]);
    $holiday = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Holiday',
        'target_minor' => 200000,
        'start_date' => CarbonImmutable::today()->subDays(30)->toDateString(),
        'status' => 'active',
    ]);

    $tyreTx = contributionTx($this->user->id, $this->account->id, $this->run->id, 20000, CarbonImmutable::today()->subDays(5)->toDateString());
    $holidayTx = contributionTx($this->user->id, $this->account->id, $this->run->id, 500000, CarbonImmutable::today()->subDays(4)->toDateString());
    contributionTx($this->user->id, $this->account->id, $this->run->id, 385000, CarbonImmutable::today()->subDays(3)->toDateString());

    $writer = app(GoalContributionWriter::class);
    $writer->attribute($this->user, $tyres->id, $tyreTx->id);
    $writer->attribute($this->user, $holiday->id, $holidayTx->id);

    $contributed = contributedByGoalId($this->user);

    expect($contributed[$tyres->id])->toBe(20000);
    expect($contributed[$holiday->id])->toBe(500000);
});

it('reports zero contributed for a goal nothing has been attributed to', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 60000,
        'start_date' => CarbonImmutable::today()->subDays(30)->toDateString(),
        'status' => 'active',
    ]);

    contributionTx($this->user->id, $this->account->id, $this->run->id, 770000, CarbonImmutable::today()->subDays(2)->toDateString());

    expect(contributedByGoalId($this->user)[$goal->id])->toBe(0);
});

it('counts an attributed transaction that posted before the goal start_date', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::today()->toDateString(),
        'status' => 'active',
    ]);

    $tx = contributionTx($this->user->id, $this->account->id, $this->run->id, 30000, CarbonImmutable::today()->subDays(20)->toDateString());
    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $tx->id);

    expect(contributedByGoalId($this->user)[$goal->id])->toBe(30000);
});

it('attributing the same transaction twice records one contribution', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'status' => 'active',
    ]);
    $tx = contributionTx($this->user->id, $this->account->id, $this->run->id, 25000, CarbonImmutable::today()->toDateString());

    $writer = app(GoalContributionWriter::class);
    $writer->attribute($this->user, $goal->id, $tx->id);
    $writer->attribute($this->user, $goal->id, $tx->id);

    expect(DB::table('goal_contributions')->where('goal_id', $goal->id)->count())->toBe(1);
    expect(contributedByGoalId($this->user)[$goal->id])->toBe(25000);
});

it('lets one transaction fund two goals at once', function (): void {
    $first = Goal::factory()->create(['user_id' => $this->user->id, 'name' => 'First', 'target_minor' => 100000, 'status' => 'active']);
    $second = Goal::factory()->create(['user_id' => $this->user->id, 'name' => 'Second', 'target_minor' => 100000, 'status' => 'active']);
    $tx = contributionTx($this->user->id, $this->account->id, $this->run->id, 25000, CarbonImmutable::today()->toDateString());

    $writer = app(GoalContributionWriter::class);
    $writer->attribute($this->user, $first->id, $tx->id);
    $writer->attribute($this->user, $second->id, $tx->id);

    $contributed = contributedByGoalId($this->user);
    expect($contributed[$first->id])->toBe(25000);
    expect($contributed[$second->id])->toBe(25000);
});

it('detaching a contribution drops it from the goal total', function (): void {
    $goal = Goal::factory()->create(['user_id' => $this->user->id, 'target_minor' => 100000, 'status' => 'active']);
    $tx = contributionTx($this->user->id, $this->account->id, $this->run->id, 25000, CarbonImmutable::today()->toDateString());

    $writer = app(GoalContributionWriter::class);
    $writer->attribute($this->user, $goal->id, $tx->id);
    $writer->detach($this->user, $goal->id, $tx->id);

    expect(DB::table('goal_contributions')->count())->toBe(0);
    expect(contributedByGoalId($this->user)[$goal->id])->toBe(0);
});

it('refuses to attribute another users transaction', function (): void {
    $other = User::create(['username' => 'other', 'password' => 'opensesame', 'period_start_day' => 1]);
    $otherAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'Other ASN',
        'slug' => 'other-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0999999999',
        'default_currency' => 'EUR',
    ]);
    $otherRun = ImportRun::create([
        'user_id' => $other->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/o.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $goal = Goal::factory()->create(['user_id' => $this->user->id, 'target_minor' => 100000, 'status' => 'active']);
    $foreignTx = contributionTx($other->id, $otherAccount->id, $otherRun->id, 25000, CarbonImmutable::today()->toDateString());

    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $foreignTx->id);

    expect(DB::table('goal_contributions')->count())->toBe(0);
});

it('refuses to attribute to another users goal', function (): void {
    $other = User::create(['username' => 'other', 'password' => 'opensesame', 'period_start_day' => 1]);
    $foreignGoal = Goal::factory()->create(['user_id' => $other->id, 'target_minor' => 100000, 'status' => 'active']);
    $tx = contributionTx($this->user->id, $this->account->id, $this->run->id, 25000, CarbonImmutable::today()->toDateString());

    app(GoalContributionWriter::class)->attribute($this->user, $foreignGoal->id, $tx->id);

    expect(DB::table('goal_contributions')->count())->toBe(0);
});

it('refuses to detach another users contribution', function (): void {
    $other = User::create(['username' => 'other', 'password' => 'opensesame', 'period_start_day' => 1]);
    $goal = Goal::factory()->create(['user_id' => $this->user->id, 'target_minor' => 100000, 'status' => 'active']);
    $tx = contributionTx($this->user->id, $this->account->id, $this->run->id, 25000, CarbonImmutable::today()->toDateString());

    $writer = app(GoalContributionWriter::class);
    $writer->attribute($this->user, $goal->id, $tx->id);

    expect($writer->detach($other, $goal->id, $tx->id))->toBeFalse();
    expect(DB::table('goal_contributions')->count())->toBe(1);
    expect(contributedByGoalId($this->user)[$goal->id])->toBe(25000);
});

it('lists the goals a transaction has been attributed to', function (): void {
    $goal = Goal::factory()->create(['user_id' => $this->user->id, 'name' => 'Winter tyres', 'target_minor' => 60000, 'status' => 'active']);
    $tx = contributionTx($this->user->id, $this->account->id, $this->run->id, 25000, CarbonImmutable::today()->toDateString());

    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $tx->id);

    $rows = app(GoalContributionQuery::class)->forTransaction($this->user, $tx->id);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->goalId)->toBe($goal->id);
    expect($rows[0]->goalName)->toBe('Winter tyres');
});

it('offers only the users own active goals as attribution targets', function (): void {
    $active = Goal::factory()->create(['user_id' => $this->user->id, 'name' => 'Active', 'target_minor' => 60000, 'status' => 'active']);
    Goal::factory()->create(['user_id' => $this->user->id, 'name' => 'Archived', 'target_minor' => 60000, 'status' => 'archived']);
    $other = User::create(['username' => 'other', 'password' => 'opensesame', 'period_start_day' => 1]);
    Goal::factory()->create(['user_id' => $other->id, 'name' => 'Foreign', 'target_minor' => 60000, 'status' => 'active']);

    $options = app(GoalContributionQuery::class)->attributableGoals($this->user);

    expect(array_map(static fn (object $row): int => $row->goalId, $options))->toBe([$active->id]);
});

it('keeps the linked pot balance as the source of truth over attributions', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'status' => 'active',
    ]);

    $pot = Pot::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $goal->id,
        'name' => 'Tyres pot',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    DB::table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => $pot->id,
        'amount_minor' => 40000,
        'currency' => 'EUR',
        'kind' => 'fund',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $tx = contributionTx($this->user->id, $this->account->id, $this->run->id, 99900, CarbonImmutable::today()->toDateString());
    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $tx->id);

    expect(contributedByGoalId($this->user)[$goal->id])->toBe(40000);
});
