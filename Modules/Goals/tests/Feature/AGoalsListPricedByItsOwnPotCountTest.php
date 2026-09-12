<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Public\Services\GoalProgressQuery;

// Each bar's projection measures its pot's trailing window, and that was a sum
// per card: the statement count of the goals page was the reader's goal count.
// The window is the same fixed span for every goal, so one read answers all.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

$gpcUser = static function (string $username): User {
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
};

$gpcAccount = static function (DatabaseManager $db, User $user): int {
    $hex = bin2hex(random_bytes(4));

    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'GPC ASN',
        'slug' => 'gpc-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00GPC'.strtoupper($hex),
        'default_currency' => 'EUR',
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '2024-01-01 00:00:00',
    ]);
};

// One goal, its funding pot, and the deposits named as (day, minor, currency).
$gpcLinkedGoal = static function (DatabaseManager $db, User $user, int $accountId, int $n, int $targetMinor, array $deposits): int {
    $goalId = (int) $db->connection()->table('goals')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Goal '.$n,
        'target_minor' => $targetMinor,
        'target_currency' => 'EUR',
        'start_date' => '2025-01-01',
        'target_date' => '2027-01-01',
        'status' => 'active',
        'created_at' => '2025-01-01 00:00:00',
        'updated_at' => '2025-01-01 00:00:00',
    ]);

    $potId = (int) $db->connection()->table('pots')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'goal_id' => $goalId,
        'name' => 'Pot '.$n,
        'currency' => 'EUR',
        'status' => 'active',
        'created_at' => '2025-01-01 00:00:00',
        'updated_at' => '2025-01-01 00:00:00',
    ]);

    foreach ($deposits as [$day, $minor, $currency]) {
        $db->connection()->table('pot_movements')->insert([
            'user_id' => $user->id,
            'pot_id' => $potId,
            'amount_minor' => $minor,
            'currency' => $currency,
            'kind' => 'deposit',
            'created_at' => $day.' 12:00:00',
            'updated_at' => $day.' 12:00:00',
        ]);
    }

    return $goalId;
};

$gpcStatements = static function (callable $run): array {
    $statements = [];
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $run();

    return $statements;
};

it('costs the same statements for twelve funded goals as for one', function () use ($gpcUser, $gpcAccount, $gpcLinkedGoal, $gpcStatements): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $deposits = [['2026-05-16', 20000, 'EUR']];

    $one = $gpcUser('gpc-one');
    $gpcLinkedGoal($db, $one, $gpcAccount($db, $one), 1, 200000, $deposits);

    $many = $gpcUser('gpc-many');
    $manyAccount = $gpcAccount($db, $many);
    foreach (range(1, 12) as $n) {
        $gpcLinkedGoal($db, $many, $manyAccount, $n, 200000, $deposits);
    }

    $query = app(GoalProgressQuery::class);
    $oneCost = $gpcStatements(static fn () => $query->forUser($one));
    $manyCost = $gpcStatements(static fn () => $query->forUser($many));

    $movementReads = static fn (array $sql): int => count(array_filter(
        $sql,
        static fn (string $s): bool => str_contains($s, 'from "pot_movements"'),
    ));

    expect(count($manyCost))->toBe(count($oneCost))
        ->and($movementReads($manyCost))->toBe($movementReads($oneCost));
});

// The batched read is bounded to the widest window any goal uses and then
// filtered per goal, so the day a deposit falls on decides which side of that
// goal's own window it lands. Ninety days back from 2026-06-15 is 2026-03-17.
it('counts a deposit on the window edge towards the rate and the one before it only towards the level', function () use ($gpcUser, $gpcAccount, $gpcLinkedGoal): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = $gpcUser('gpc-window');
    $gpcLinkedGoal($db, $user, $gpcAccount($db, $user), 1, 200000, [
        ['2026-03-16', 40000, 'EUR'],
        ['2026-03-17', 10000, 'EUR'],
        ['2026-05-16', 20000, 'EUR'],
        // A movement the pot is not denominated in: outside the level and
        // outside the rate, exactly as the per-pot sum left it out.
        ['2026-05-17', 999999, 'USD'],
    ]);

    $rows = app(GoalProgressQuery::class)->forUser($user);

    expect($rows[0]->contributedMinor)->toBe(70000)
        ->and($rows[0]->projectedFinishDate)->toBe(
            CarbonImmutable::today()->addDays((int) ceil(130000 / (30000 / 90)))->format('Y-m-d'),
        );
});

it('calls a pot with history but nothing inside the window stalled rather than unprojectable', function () use ($gpcUser, $gpcAccount, $gpcLinkedGoal): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = $gpcUser('gpc-stalled');
    $gpcLinkedGoal($db, $user, $gpcAccount($db, $user), 1, 200000, [['2026-01-10', 40000, 'EUR']]);

    $rows = app(GoalProgressQuery::class)->forUser($user);

    expect($rows[0]->contributedMinor)->toBe(40000)
        ->and($rows[0]->projectedFinishDate)->toBeNull()
        ->and($rows[0]->projectionStalled)->toBeTrue();
});
