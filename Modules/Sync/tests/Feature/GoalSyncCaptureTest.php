<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Sync\Internal\OpLog\OpLogWriter;

uses(RefreshDatabase::class);

/*
 * Goals were absent from the capture wiring, so a goal created on one device
 * wrote zero op-log rows and stayed on that device forever. Measured on a
 * paired Galaxy S24: creating a goal left op_log_entries at exactly the 6156
 * rows received from the desktop, none of them carrying the phone's own
 * device id, while a transaction note on the same device did produce one.
 *
 * These drive the real GoalWriter rather than the listener, so the dispatch
 * itself is under test — a handler nothing calls is what was already there.
 */

function goalCaptureUser(): User
{
    return User::query()->create([
        'username' => 'goal-sync-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function bindGoalCaptureWriter(int $userId): void
{
    $keypair = sodium_crypto_sign_keypair();

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'goal-capture-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));
}

/** @return Collection<int, stdClass> */
function goalOps(DatabaseManager $db, int $userId)
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'goals')
        ->get();
}

it('captures a goal the moment it is created', function (): void {
    $user = goalCaptureUser();
    bindGoalCaptureWriter((int) $user->id);

    app(GoalWriter::class)->save($user, 'Winterbanden', '600,00', '2026-12-31');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $ops = goalOps($db, (int) $user->id);

    expect($ops)->not->toBeEmpty('a created goal must reach the op log or it can never sync')
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($ops->pluck('field')->all())->toContain('name', 'target_minor', 'target_date');
});

it('captures an edit as one op per changed column', function (): void {
    $user = goalCaptureUser();
    $goal = app(GoalWriter::class)->save($user, 'Winterbanden', '600,00', '2026-12-31');

    // Bound after the create so only the edit's ops are in view.
    bindGoalCaptureWriter((int) $user->id);

    app(GoalWriter::class)->update($user, (int) $goal->id, 'Zomerbanden', '750,00', '2027-03-01');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $ops = goalOps($db, (int) $user->id);

    expect($ops->pluck('op_type')->unique()->all())->toBe(['set'])
        ->and($ops->pluck('field')->sort()->values()->all())
        ->toBe(['name', 'target_date', 'target_minor']);
});

it('captures archiving, completing and restoring, which only move status', function (string $method): void {
    $user = goalCaptureUser();
    $goal = app(GoalWriter::class)->save($user, 'Winterbanden', '600,00', '2026-12-31');

    bindGoalCaptureWriter((int) $user->id);

    app(GoalWriter::class)->{$method}($user, (int) $goal->id);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $ops = goalOps($db, (int) $user->id);

    expect($ops->pluck('field')->all())->toBe(['status'])
        ->and($ops->pluck('op_type')->all())->toBe(['set']);
})->with(['archive', 'markComplete', 'restore']);

it('writes nothing for a goal that does not belong to the user', function (): void {
    $owner = goalCaptureUser();
    $stranger = goalCaptureUser();
    $goal = app(GoalWriter::class)->save($owner, 'Winterbanden', '600,00', '2026-12-31');

    bindGoalCaptureWriter((int) $stranger->id);

    app(GoalWriter::class)->archive($stranger, (int) $goal->id);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect(goalOps($db, (int) $stranger->id))->toBeEmpty();
});
