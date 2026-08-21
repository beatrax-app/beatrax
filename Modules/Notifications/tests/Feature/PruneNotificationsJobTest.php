<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Notifications\Internal\Jobs\PruneNotificationsJob;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// Fixture timestamps are stamped against real wall-clock time, never
// CarbonImmutable::setTestNow(): the job's predicate is evaluated by SQLite's
// own `now()`, which freezing Carbon in PHP does not touch.

function pnjUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function pnjNotification(int $userId, int $daysAgo, string $state = 'open'): string
{
    $id = hash('sha256', $userId.'-'.$daysAgo.'-'.$state.'-'.bin2hex(random_bytes(8)));
    $createdAt = now()->subDays($daysAgo)->toDateTimeString();

    DB::table('notifications')->insert([
        'id' => $id,
        'user_id' => $userId,
        'state' => $state,
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'PNJ fixture title',
        'body' => 'PNJ fixture body',
        'params' => null,
        'trigger_type' => 'payment_reminder',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

function pnjExists(string $id): bool
{
    return DB::table('notifications')->where('id', $id)->exists();
}

it('prunes a notification created 400 days ago', function (): void {
    $user = pnjUser('pnj-400-days');
    $id = pnjNotification($user->id, 400);

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    expect(pnjExists($id))->toBeFalse();
});

it('does not prune a notification created 364 days ago', function (): void {
    $user = pnjUser('pnj-364-days');
    $id = pnjNotification($user->id, 364);

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    expect(pnjExists($id))->toBeTrue();
});

it('leaves a row exactly at the 365-day boundary alone (the cutoff is strictly less-than)', function (): void {
    $user = pnjUser('pnj-365-boundary');
    $id = pnjNotification($user->id, 365);

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    // The predicate is a strict `<`, so a row stamped at exactly the cutoff
    // belongs to the kept side.
    expect(pnjExists($id))->toBeTrue();
});

it('prunes unread and resolved rows past the cutoff alike — retention is age-based, not state-based', function (): void {
    $user = pnjUser('pnj-state-blind');
    $unreadOldId = pnjNotification($user->id, 400, state: 'open');
    $resolvedOldId = pnjNotification($user->id, 400, state: 'resolved');

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    expect(pnjExists($unreadOldId))->toBeFalse();
    expect(pnjExists($resolvedOldId))->toBeFalse();
});

it('prunes only the dispatching user\'s old rows, leaving another user\'s old rows untouched', function (): void {
    $userA = pnjUser('pnj-cross-user-a');
    $userB = pnjUser('pnj-cross-user-b');

    $aOldId = pnjNotification($userA->id, 400);
    $bOldId = pnjNotification($userB->id, 400);

    $job = new PruneNotificationsJob($userA->id);
    $job->handle($this->app->make(DatabaseManager::class));

    expect(pnjExists($aOldId))->toBeFalse();
    expect(pnjExists($bOldId))->toBeTrue();
});

it('prunes with no KEK available (locked/headless device)', function (): void {
    $user = pnjUser('pnj-kek-less');
    $session = $this->enablesEncryptionForUser($user);

    // The daemon's real shape: app lock engaged, headless worker, no KEK.
    $this->app->make(AppLockKeyService::class)->withhold($session);

    $oldId = pnjNotification($user->id, 400);
    $recentId = pnjNotification($user->id, 10);

    $job = new PruneNotificationsJob($user->id);
    $job->handle(
        $this->app->make(DatabaseManager::class),
        $session,
        $this->app->make(AppLockKeyService::class),
        $this->app->make(EncryptionMigrationService::class),
        $this->app->make(LoggerInterface::class),
    );

    expect(pnjExists($oldId))->toBeFalse();
    expect(pnjExists($recentId))->toBeTrue();
});

it('is idempotent — running the job twice prunes nothing new on the second run and does not error', function (): void {
    $user = pnjUser('pnj-idempotent');
    $oldId = pnjNotification($user->id, 400);
    $recentId = pnjNotification($user->id, 10);

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    expect(pnjExists($oldId))->toBeFalse();
    expect(pnjExists($recentId))->toBeTrue();

    $job2 = new PruneNotificationsJob($user->id);
    $job2->handle($this->app->make(DatabaseManager::class));

    expect(pnjExists($recentId))->toBeTrue();
});

it('declares ShouldBeUniqueUntilProcessing with uniqueId=userId, uniqueFor=3600s, uniqueVia=LockStore::forUniqueJobs', function (): void {
    $job = new PruneNotificationsJob(userId: 42);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
    expect($job->uniqueId())->toBe('42');
    expect($job->uniqueFor())->toBe(3600);
    expect($job->uniqueVia())->toBeInstanceOf(Repository::class);
    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([60, 300, 900]);
});

it('bounds the prune to a batch larger than one chunk (WR-style bounded chunked delete)', function (): void {
    $user = pnjUser('pnj-chunked');

    $oldIds = [];
    foreach (range(1, 12) as $i) {
        $oldIds[] = pnjNotification($user->id, 400 + $i);
    }
    $recentId = pnjNotification($user->id, 1);

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    foreach ($oldIds as $id) {
        expect(pnjExists($id))->toBeFalse();
    }
    expect(pnjExists($recentId))->toBeTrue();
});
