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

function projContribution(User $user, int $accountId, int $runId, int $goalId, int $amountMinor, string $postedAt): void
{
    static $i = 0;
    $i++;

    $tx = Transaction::create([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
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
        'source_row_index' => $i,
        'fingerprint' => str_pad('p'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    app(GoalContributionWriter::class)->attribute($user, $goalId, $tx->id);
}

it('returns a projected finish date when there is a contribution history', function (): void {
    $startDate = CarbonImmutable::now()->subDays(90)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 300000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    projContribution($this->user, $this->account->id, $this->run->id, $goal->id, 20000, CarbonImmutable::now()->subDays(90)->toDateString());
    projContribution($this->user, $this->account->id, $this->run->id, $goal->id, 20000, CarbonImmutable::now()->subDays(60)->toDateString());
    projContribution($this->user, $this->account->id, $this->run->id, $goal->id, 20000, CarbonImmutable::now()->subDays(30)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectedFinishDate)->not->toBeNull();
    expect($rows[0]->contributedMinor)->toBe(60000);
});

// A goal younger than the minimum observation window must not project: one large
// early deposit over a 1-3 day window extrapolates a misleadingly soon finish,
// so the card shows "building a projection" until enough history accrues.
it('suppresses the projected date until the minimum observation window has elapsed', function (): void {
    $startDate = CarbonImmutable::now()->subDays(3)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 500000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(60)->toDateString(),
        'status' => 'active',
    ]);

    projContribution($this->user, $this->account->id, $this->run->id, $goal->id, 200000, CarbonImmutable::now()->subDays(1)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->contributedMinor)->toBe(200000);
    expect($rows[0]->projectedFinishDate)->toBeNull();
    expect($rows[0]->progressState)->toBe('in_progress');
});

it('sets projectionBeyondHorizon to false when projected finish is within 90 days', function (): void {
    $startDate = CarbonImmutable::now()->subDays(10)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 50000,
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addDays(20)->toDateString(),
        'status' => 'active',
    ]);

    // 40 000 contributed over 10 days = 4 000/day rate → 10 days to finish remaining 10 000.
    projContribution($this->user, $this->account->id, $this->run->id, $goal->id, 40000, CarbonImmutable::now()->subDays(5)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectionBeyondHorizon)->toBeFalse();
});

it('sets projectionBeyondHorizon to true when projected finish exceeds 90 days', function (): void {
    $startDate = CarbonImmutable::now()->subDays(30)->toDateString();
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 1000000, // 10 000 EUR — large target
        'start_date' => $startDate,
        'target_date' => CarbonImmutable::now()->addYears(3)->toDateString(),
        'status' => 'active',
    ]);

    // Only 1 000 minor contributed over 30 days = 33 minor/day → ~30 000 days to finish.
    projContribution($this->user, $this->account->id, $this->run->id, $goal->id, 1000, CarbonImmutable::now()->subDays(15)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectionBeyondHorizon)->toBeTrue();
});

// A pot-linked goal reads its progress from the pot balance, so its run-rate
// has to come from the pot's own movements — an attributed transaction would
// measure money the progress figure never counted.
it('derives the run-rate from pot movements for a pot-linked goal', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
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
        'amount_minor' => 30000,
        'currency' => 'EUR',
        'kind' => 'fund',
        'memo' => null,
        'created_at' => CarbonImmutable::now()->subDays(10),
        'updated_at' => CarbonImmutable::now()->subDays(10),
    ]);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->contributedMinor)->toBe(30000);
    expect($rows[0]->projectedFinishDate)->not->toBeNull();
});

it('returns null projectedFinishDate when there is no contribution history', function (): void {
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectedFinishDate)->toBeNull();
    expect($rows[0]->contributedMinor)->toBe(0);
});

it('marks progressState as reached when contributions meet the target', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 50000,
        'start_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'target_date' => CarbonImmutable::now()->addDays(60)->toDateString(),
        'status' => 'active',
    ]);

    projContribution($this->user, $this->account->id, $this->run->id, $goal->id, 50000, CarbonImmutable::now()->subDays(5)->toDateString());

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->progressState)->toBe('reached');
});

it('returns null projectedFinishDate for a goal with nothing attributed and no pot', function (): void {
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'status' => 'active',
    ]);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectedFinishDate)->toBeNull();
    expect($rows[0]->projectionBeyondHorizon)->toBeFalse();
});
