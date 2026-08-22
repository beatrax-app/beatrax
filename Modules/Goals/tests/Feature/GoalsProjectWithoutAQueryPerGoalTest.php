<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Internal\Enums\GoalProgressState;
use Modules\Goals\Public\Services\GoalProgressQuery;

// The goals list already reads every goal's attributions in one statement to
// total them. The projection beside each bar then asked for the same rows a
// goal at a time, narrowed to its own trailing window.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    /** @var User $user */
    $user = User::query()->create([
        'username' => 'goal-projection-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->user = $user;

    $this->accountId = (int) $this->conn->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'GPR ASN',
        'slug' => 'gpr-asn',
        'kind' => 'bank',
        'iban' => 'NL00ASNBGPR00001',
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->runId = (int) $this->conn->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/gpr.csv',
        'sha256' => hash('sha256', 'gpr'),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->gprContribution = function (int $goalId, string $postedAt, int $amountMinor, string $currency = 'EUR'): void {
        $txId = $this->conn->table('transactions')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $this->accountId,
            'import_run_id' => $this->runId,
            'fingerprint' => hash('sha256', 'gpr-'.$goalId.'-'.$postedAt.'-'.$amountMinor.'-'.bin2hex(random_bytes(4))),
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 00:00:00',
            'value_date' => $postedAt,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => $currency,
            'counterparty_normalized' => 'gpr-saver',
            'counterparty_name' => 'GPR Saver',
            'normalization_version' => 1,
            'description' => 'gpr contribution',
            'type' => 'income',
            'source_format' => 'asn-csv',
            'source_row_index' => 1,
            'fingerprint_version' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->conn->table('goal_contributions')->insert([
            'user_id' => $this->user->id,
            'goal_id' => $goalId,
            'transaction_id' => $txId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

$gprGoal = static function (ConnectionInterface $conn, User $user, string $name, string $startDate, int $targetMinor = 100000, string $currency = 'EUR'): int {
    return (int) $conn->table('goals')->insertGetId([
        'user_id' => $user->id,
        'name' => $name,
        'target_minor' => $targetMinor,
        'target_currency' => $currency,
        'start_date' => $startDate,
        'target_date' => '2027-01-01',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
};

$gprContributionReads = static function (callable $work): int {
    $reads = 0;
    DB::listen(static function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, 'from "goal_contributions"')) {
            $reads++;
        }
    });

    $work();

    return $reads;
};

it('reads the attributions once for the whole list, not once per goal', function () use ($gprGoal, $gprContributionReads): void {
    for ($i = 0; $i < 30; $i++) {
        $goalId = $gprGoal($this->conn, $this->user, 'Goal '.$i, '2026-01-01');
        ($this->gprContribution)($goalId, '2026-05-01', 5000 + $i);
    }

    $query = app(GoalProgressQuery::class);
    $reads = $gprContributionReads(function () use ($query): void {
        $query->forUser($this->user);
    });

    expect($reads)->toBe(1);
});

it('projects off the trailing window and ignores contributions older than it', function () use ($gprGoal): void {
    // 90 days back from 2026-06-15 is 2026-03-17; the goal started before that,
    // so the window is the 90 days and the older deposit sits outside it.
    $windowed = $gprGoal($this->conn, $this->user, 'Windowed', '2025-01-01');
    ($this->gprContribution)($windowed, '2025-02-01', 40000);
    ($this->gprContribution)($windowed, '2026-05-16', 20000);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // Level counts both deposits: 600.00 of 1000.00. The rate counts only the
    // 200.00 inside the window, over 90 days.
    expect($rows[0]->contributedMinor)->toBe(60000)
        ->and($rows[0]->projectedFinishDate)->toBe(
            CarbonImmutable::today()->addDays((int) ceil(40000 / (20000 / 90)))->format('Y-m-d'),
        );
});

it('calls a goal with history but nothing recent stalled rather than unprojectable', function () use ($gprGoal): void {
    $stalled = $gprGoal($this->conn, $this->user, 'Stalled', '2025-01-01');
    ($this->gprContribution)($stalled, '2025-02-01', 40000);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectedFinishDate)->toBeNull()
        ->and($rows[0]->projectionStalled)->toBeTrue();
});

it('refuses to project a goal younger than the observation window', function () use ($gprGoal): void {
    $fresh = $gprGoal($this->conn, $this->user, 'Fresh', CarbonImmutable::today()->subDays(3)->toDateString());
    ($this->gprContribution)($fresh, CarbonImmutable::today()->subDays(1)->toDateString(), 20000);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectedFinishDate)->toBeNull()
        ->and($rows[0]->projectionStalled)->toBeFalse();
});

it('projects nothing for a goal already at its target', function () use ($gprGoal): void {
    $done = $gprGoal($this->conn, $this->user, 'Done', '2025-01-01');
    ($this->gprContribution)($done, '2026-05-01', 100000);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->progressState)->toBe(GoalProgressState::Reached->value)
        ->and($rows[0]->projectedFinishDate)->toBeNull()
        ->and($rows[0]->projectionStalled)->toBeFalse();
});

it('counts a contribution posted exactly on the window edge', function () use ($gprGoal): void {
    $edge = $gprGoal($this->conn, $this->user, 'Edge', '2025-01-01');
    ($this->gprContribution)($edge, CarbonImmutable::today()->subDays(90)->toDateString(), 20000);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->projectionStalled)->toBeFalse()
        ->and($rows[0]->projectedFinishDate)->not->toBeNull();
});

it('keeps one goal s attributions off another s', function () use ($gprGoal): void {
    $first = $gprGoal($this->conn, $this->user, 'First', '2025-01-01');
    $second = $gprGoal($this->conn, $this->user, 'Second', '2025-01-01');
    ($this->gprContribution)($first, '2026-05-01', 30000);
    ($this->gprContribution)($second, '2026-05-02', 10000);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    expect($rows[0]->contributedMinor)->toBe(30000)
        ->and($rows[1]->contributedMinor)->toBe(10000);
});

it('hands an archived goal the same projection the active list would', function () use ($gprGoal): void {
    $goalId = $gprGoal($this->conn, $this->user, 'Archivable', '2025-01-01');
    ($this->gprContribution)($goalId, '2026-05-16', 20000);

    $active = app(GoalProgressQuery::class)->forUser($this->user);
    $this->conn->table('goals')->where('id', $goalId)->update(['status' => 'archived']);
    $archived = app(GoalProgressQuery::class)->archivedForUser($this->user);

    expect($archived[0]->projectedFinishDate)->toBe($active[0]->projectedFinishDate)
        ->and($archived[0]->contributedMinor)->toBe($active[0]->contributedMinor);
});
