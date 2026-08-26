<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

// The desktop runs the app server, the queue worker, the sync listener and the
// relay against one SQLite file. `busy_timeout` does not cover this collision:
// a transaction that read before another connection committed is refused the
// write outright rather than made to wait for it.
function unlockRaceDatabase(): string
{
    $stub = (string) tempnam(sys_get_temp_dir(), 'beatrax-unlock-race-');
    $file = $stub.'.sqlite';
    @unlink($stub);
    touch($file);

    /** @var Repository $config */
    $config = app(Repository::class);
    foreach (['unlock_race', 'unlock_race_rival'] as $name) {
        $config->set('database.connections.'.$name, [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => false,
            'journal_mode' => 'WAL',
            'busy_timeout' => 30_000,
        ]);
    }

    // A virtual table brings its own shadow tables into being, so replaying
    // every sqlite_master row would try to create them a second time.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    foreach (DB::connection()->select('SELECT name, sql FROM sqlite_master WHERE sql IS NOT NULL') as $object) {
        $sql = is_string($object->sql) ? $object->sql : '';
        $name = is_string($object->name) ? $object->name : '';
        $exists = $db->connection('unlock_race')
            ->selectOne('SELECT 1 AS present FROM sqlite_master WHERE name = ?', [$name]) !== null;

        if ($sql !== '' && ! $exists) {
            $db->connection('unlock_race')->statement($sql);
        }
    }

    $config->set('database.default', 'unlock_race');

    return $file;
}

function unlockRaceCleanup(string $file, string $previousDefault): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->purge('unlock_race');
    $db->purge('unlock_race_rival');
    app(Repository::class)->set('database.default', $previousDefault);

    foreach (['', '-wal', '-shm'] as $suffix) {
        @unlink($file.$suffix);
    }
}

// The rival commits once, on the read the unlock takes inside its own
// transaction. It writes a column the unlock does not read and a value the row
// does not already hold — an update to the value already there leaves the WAL
// alone and races nothing.
function unlockRaceRivalCommitsOnceOnItsRead(): Closure
{
    $committed = false;

    Event::listen(function (QueryExecuted $event) use (&$committed): void {
        if ($committed || $event->connectionName !== 'unlock_race') {
            return;
        }
        if (! str_contains($event->sql, 'user_app_lock_configs') || ! str_starts_with(strtolower($event->sql), 'select')) {
            return;
        }

        $committed = true;
        DB::connection('unlock_race_rival')->table('user_app_lock_configs')
            ->update(['last_activity_at' => '2020-01-01 00:00:00']);
    });

    return function () use (&$committed): bool {
        return $committed === true;
    };
}

it('unlocks even when another process commits between its read and its write', function (): void {
    $previousDefault = (string) config('database.default');
    $file = unlockRaceDatabase();

    try {
        $user = User::query()->create([
            'username' => 'race',
            'password' => 'whatever-password',
            'period_start_day' => 1,
        ]);
        $this->actingAs($user);

        app(AppLockProvisioner::class)->enable($user->id, '123456', 'whatever-password');
        DB::connection('unlock_race')->table('user_app_lock_configs')
            ->where('user_id', $user->id)
            ->update(['failed_attempts' => 4]);

        /** @var Session $session */
        $session = app(Session::class);
        app(LockStateManager::class)->lock($session);

        $rivalCommitted = unlockRaceRivalCommitsOnceOnItsRead();

        $dataKey = app(PinVerificationService::class)->verify($user->id, '123456', $session);

        expect($rivalCommitted())->toBeTrue()
            ->and($dataKey)->toBeString()
            ->and(app(LockStateManager::class)->isLocked($session))->toBeFalse();

        // The counter the unlock is there to clear is cleared, which the write
        // it was refused the first time around is the only thing that does.
        $row = DB::connection('unlock_race')->table('user_app_lock_configs')->where('user_id', $user->id)->first();
        expect((int) $row->failed_attempts)->toBe(0);
    } finally {
        unlockRaceCleanup($file, $previousDefault);
    }
});

it('raises one lockout alert for one wrong PIN, not one per retry', function (): void {
    $previousDefault = (string) config('database.default');
    $file = unlockRaceDatabase();

    try {
        $user = User::query()->create([
            'username' => 'race-alerts',
            'password' => 'whatever-password',
            'period_start_day' => 1,
        ]);
        $this->actingAs($user);

        app(AppLockProvisioner::class)->enable($user->id, '123456', 'whatever-password');
        DB::connection('unlock_race')->table('user_app_lock_configs')
            ->where('user_id', $user->id)
            ->update(['failed_attempts' => PinVerificationService::HARD_CAP - 1]);

        /** @var Session $session */
        $session = app(Session::class);
        app(LockStateManager::class)->lock($session);

        $rivalCommitted = unlockRaceRivalCommitsOnceOnItsRead();

        expect(app(PinVerificationService::class)->verify($user->id, '999999', $session))->toBeNull()
            ->and($rivalCommitted())->toBeTrue();

        expect(SystemAlert::query()->where('user_id', $user->id)->count())->toBe(1);
    } finally {
        unlockRaceCleanup($file, $previousDefault);
    }
});
