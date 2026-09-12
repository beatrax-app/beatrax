<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Notifications\Internal\Jobs\PruneNotificationsJob;
use Modules\Sync\Public\Events\NotificationMutated;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// The retention edge is derived from the app clock, so a fixture and the sweep
// that reads it share one frame. Tests that turn on where exactly the edge
// falls freeze that clock; the rest are stamped off real wall-clock time,
// because a row 400 days old is 400 days old on either.

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

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
    $job->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

    expect(pnjExists($id))->toBeFalse();
});

it('does not prune a notification created 364 days ago', function (): void {
    $user = pnjUser('pnj-364-days');
    $id = pnjNotification($user->id, 364);

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

    expect(pnjExists($id))->toBeTrue();
});

it('leaves a row exactly at the 365-day boundary alone (the cutoff is strictly less-than)', function (): void {
    // Frozen so "exactly" is exact: on a running clock the sweep reads a cutoff
    // a few milliseconds later than the stamp the fixture wrote.
    CarbonImmutable::setTestNow('2026-08-31 12:00:00');

    $user = pnjUser('pnj-365-boundary');
    $id = pnjNotification($user->id, 365);

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

    // The predicate is a strict `<`, so a row stamped at exactly the cutoff
    // belongs to the kept side.
    expect(pnjExists($id))->toBeTrue();
});

it('prunes unread and resolved rows past the cutoff alike — retention is age-based, not state-based', function (): void {
    $user = pnjUser('pnj-state-blind');
    $unreadOldId = pnjNotification($user->id, 400, state: 'open');
    $resolvedOldId = pnjNotification($user->id, 400, state: 'resolved');

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

    expect(pnjExists($unreadOldId))->toBeFalse();
    expect(pnjExists($resolvedOldId))->toBeFalse();
});

it('prunes only the dispatching user\'s old rows, leaving another user\'s old rows untouched', function (): void {
    $userA = pnjUser('pnj-cross-user-a');
    $userB = pnjUser('pnj-cross-user-b');

    $aOldId = pnjNotification($userA->id, 400);
    $bOldId = pnjNotification($userB->id, 400);

    $job = new PruneNotificationsJob($userA->id);
    $job->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

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
        $this->app->make(Clock::class),
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
    $job->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

    expect(pnjExists($oldId))->toBeFalse();
    expect(pnjExists($recentId))->toBeTrue();

    $job2 = new PruneNotificationsJob($user->id);
    $job2->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

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
    $job->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

    foreach ($oldIds as $id) {
        expect(pnjExists($id))->toBeFalse();
    }
    expect(pnjExists($recentId))->toBeTrue();
});

// The rows are written by NotificationWriter through the app clock, in the app's
// own timezone. Asking SQLite for the cutoff asked a UTC clock instead, so the
// retention edge sat one or two hours off in whichever direction the offset
// pointed — the reader's own timezone deciding how long a year is.
it('draws the retention edge on the clock the row was written by', function (): void {
    $user = pnjUser('pnj-retention-frame');

    $id = hash('sha256', 'pnj-retention-frame');
    $createdAt = now()->subDays(365)->subMinutes(30)->toDateTimeString();
    DB::table('notifications')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'PNJ fixture title',
        'body' => 'PNJ fixture body',
        'params' => null,
        'trigger_type' => 'payment_reminder',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    $job = new PruneNotificationsJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

    expect(pnjExists($id))->toBeFalse();
});

// The command dispatches the job and the queue calls handle() through the
// container, so every dependency it takes has to be resolvable by type — the
// signature is not only what the tests above spell out by hand.
it('runs with every dependency resolved from the container', function (): void {
    $user = pnjUser('pnj-dispatched');
    $oldId = pnjNotification($user->id, 400);
    $recentId = pnjNotification($user->id, 10);

    $this->app->make(Dispatcher::class)->dispatchSync(new PruneNotificationsJob($user->id));

    expect(pnjExists($oldId))->toBeFalse()
        ->and(pnjExists($recentId))->toBeTrue();
});

// Retiring a notification is the deletion AND the tombstone that retires it on
// the peer: `notifications` carries `_delete_wins`, which settles a tombstone
// against a create, so without one the peer's own history of the row has the
// last word and a swept notification comes back. The announce ran after the
// delete, and the next run's query finds nothing left to announce them from.

/**
 * @param  list<string>  $seen  filled as the sweep announces, so the caller reads it after the run
 */
function pnjRecordAnnouncements(EventDispatcher $events, array &$seen): void
{
    $events->listen(NotificationMutated::class, static function (NotificationMutated $event) use (&$seen): void {
        if ($event->mutationType === 'delete') {
            $seen[] = $event->notificationId;
        }
    });
}

// The positive control this file never had: a sweep that deletes and announces
// nothing is indistinguishable from one that deleted and announced everything.
it('announces a delete for every row it retires', function (): void {
    $user = pnjUser('pnj-announced');

    $oldIds = [];
    foreach (range(1, 3) as $i) {
        $oldIds[] = pnjNotification($user->id, 400 + $i);
    }
    $recentId = pnjNotification($user->id, 10);

    /** @var EventDispatcher $events */
    $events = $this->app->make(EventDispatcher::class);

    /** @var list<string> $announced */
    $announced = [];
    pnjRecordAnnouncements($events, $announced);

    $job = new PruneNotificationsJob($user->id);
    $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        events: $events,
    );

    sort($oldIds);
    sort($announced);

    expect($announced)->toBe($oldIds)
        ->and(pnjExists($recentId))->toBeTrue();
});

it('keeps the rows it could not announce, so the next run can announce them', function (): void {
    $user = pnjUser('pnj-announce-failed');

    $oldIds = [];
    foreach (range(1, 3) as $i) {
        $oldIds[] = pnjNotification($user->id, 400 + $i);
    }

    /** @var EventDispatcher $events */
    $events = $this->app->make(EventDispatcher::class);

    // Aborts the announce part-way through the chunk, which is the shape a
    // worker killed on the seam between the two writes leaves behind.
    $events->listen(NotificationMutated::class, static function (): void {
        throw new RuntimeException('the worker was killed before the announce finished');
    });

    $job = new PruneNotificationsJob($user->id);

    expect(fn () => $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        events: $events,
    ))->toThrow(RuntimeException::class);

    // Gone here with nothing to say so on the peer is the one outcome that has
    // no repair: the rows are still here, and the sweep runs again tomorrow.
    foreach ($oldIds as $id) {
        expect(pnjExists($id))->toBeTrue();
    }
});
