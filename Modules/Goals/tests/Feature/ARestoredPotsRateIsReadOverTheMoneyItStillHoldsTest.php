<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Pots\Public\Services\PotWriter;

uses(RefreshDatabase::class);

// Archiving settles a pot, so its balance is what has moved since. The card's
// projected finish read the same pot's movements without that cutoff, so a pot
// archived and restored had its rate measured over the release as well -- money
// the level beside it had already let go of.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function restoredPotRateUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'rpr-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

/**
 * @return array{0: int, 1: int} the goal and the pot backing it
 */
function restoredPotRateGoalWithPot(User $user): array
{
    $hex = bin2hex(random_bytes(4));

    $accountId = (int) DB::table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'ASN rpr', 'slug' => 'rpr-'.$hex,
        'kind' => 'bank', 'iban' => 'NL00RPR'.strtoupper($hex), 'default_currency' => 'EUR',
        'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
    ]);

    $goalId = (int) DB::table('goals')->insertGetId([
        'user_id' => $user->id, 'name' => 'Nieuwe fiets',
        'target_minor' => 100_000, 'target_currency' => 'EUR',
        'start_date' => '2025-01-01', 'target_date' => '2027-01-01', 'status' => 'active',
        'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
    ]);

    $potId = (int) DB::table('pots')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'goal_id' => $goalId,
        'name' => 'Fietspot', 'currency' => 'EUR', 'status' => 'active',
        'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
    ]);

    return [$goalId, $potId];
}

function restoredPotRateDeposit(User $user, int $potId, int $minor, string $day): void
{
    DB::table('pot_movements')->insert([
        'user_id' => $user->id, 'pot_id' => $potId,
        'amount_minor' => $minor, 'currency' => 'EUR', 'kind' => 'deposit',
        'created_at' => $day.' 12:00:00', 'updated_at' => $day.' 12:00:00',
    ]);
}

// 400 before the ninety-day window opens, released inside it, then 180 put back
// after the restore. The level is 180; a rate that also reads the release is
// measured over minus 220, which is not a slower goal but a stalled one.
it('dates a restored pot from the deposits its balance still counts', function (): void {
    $user = restoredPotRateUser();
    [, $potId] = restoredPotRateGoalWithPot($user);
    $writer = app(PotWriter::class);

    restoredPotRateDeposit($user, $potId, 40_000, '2026-01-10');

    CarbonImmutable::setTestNow('2026-04-25 10:00:00');
    $writer->archive($user, $potId);

    CarbonImmutable::setTestNow('2026-04-26 10:00:00');
    $writer->restore($user, $potId);

    restoredPotRateDeposit($user, $potId, 9_000, '2026-05-01');
    restoredPotRateDeposit($user, $potId, 9_000, '2026-05-15');

    CarbonImmutable::setTestNow('2026-06-15 09:00:00');
    $row = app(GoalProgressQuery::class)->forUser($user)[0];

    // 18000 over the ninety days the window has been open is 200 a day, and
    // 82000 still to go is 410 more of them.
    expect($row->contributedMinor)->toBe(18_000)
        ->and($row->projectionStalled)->toBeFalse()
        ->and($row->projectedFinishDate)->toBe('2027-07-30');
});

// The control: the same pot, never archived, keeps every deposit in both
// readings, so the cutoff is not silently dropping money from a live pot.
it('leaves a pot that was never archived measured over all of it', function (): void {
    $user = restoredPotRateUser();
    [, $potId] = restoredPotRateGoalWithPot($user);

    restoredPotRateDeposit($user, $potId, 9_000, '2026-05-01');
    restoredPotRateDeposit($user, $potId, 9_000, '2026-05-15');

    $row = app(GoalProgressQuery::class)->forUser($user)[0];

    expect($row->contributedMinor)->toBe(18_000)
        ->and($row->projectionStalled)->toBeFalse()
        ->and($row->projectedFinishDate)->toBe('2027-07-30');
});
