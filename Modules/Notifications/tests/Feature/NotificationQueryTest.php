<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Services\NotificationQuery;

uses(RefreshDatabase::class);

/*
 * NotificationQueryTest — the inbox read model with a compound
 * (created_at, id) cursor (Req 2) and the KEK-less nav-badge unread count.
 *
 * Covers: newest-first ordering; unreadForUser excludes read AND dismissed
 * rows; dismissedForUser returns only dismissed rows; the compound cursor
 * pages correctly across 60 rows created within the SAME second (no dropped,
 * no duplicated row); a malformed cursor returns the first page rather than
 * throwing; user A never sees user B's rows; unreadCountForUser matches the
 * unread row count.
 */

function queryUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertNotification(DatabaseManager $db, int $userId, string $id, array $overrides = []): void
{
    $db->connection()->table('notifications')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Import finished',
        'body' => '3 transactions imported.',
        'params' => null,
        'trigger_type' => DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ], $overrides));
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);
    $this->query = $query;
});

it('returns seeded rows newest-first', function (): void {
    $user = queryUser('query-newest-first');
    insertNotification($this->db, $user->id, str_repeat('a', 64), ['created_at' => '2026-07-18 09:00:00', 'updated_at' => '2026-07-18 09:00:00']);
    insertNotification($this->db, $user->id, str_repeat('b', 64), ['created_at' => '2026-07-18 10:00:00', 'updated_at' => '2026-07-18 10:00:00']);
    insertNotification($this->db, $user->id, str_repeat('c', 64), ['created_at' => '2026-07-18 08:00:00', 'updated_at' => '2026-07-18 08:00:00']);

    $rows = $this->query->allForUser($user);

    expect($rows)->toHaveCount(3);
    expect($rows[0]->id)->toBe(str_repeat('b', 64));
    expect($rows[1]->id)->toBe(str_repeat('a', 64));
    expect($rows[2]->id)->toBe(str_repeat('c', 64));
});

it('excludes read and dismissed rows from unreadForUser', function (): void {
    $user = queryUser('query-unread-excludes');
    insertNotification($this->db, $user->id, str_repeat('1', 64)); // unread, not dismissed
    insertNotification($this->db, $user->id, str_repeat('2', 64), ['read_at' => '2026-07-18 09:05:00']); // read
    insertNotification($this->db, $user->id, str_repeat('3', 64), ['dismissed_at' => '2026-07-18 09:05:00']); // dismissed

    $rows = $this->query->unreadForUser($user);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->id)->toBe(str_repeat('1', 64));
});

it('returns only dismissed rows from dismissedForUser', function (): void {
    $user = queryUser('query-dismissed-only');
    insertNotification($this->db, $user->id, str_repeat('4', 64));
    insertNotification($this->db, $user->id, str_repeat('5', 64), ['dismissed_at' => '2026-07-18 09:05:00']);

    $rows = $this->query->dismissedForUser($user);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->id)->toBe(str_repeat('5', 64));
});

it('pages correctly across 60 rows created within the same second with no dropped or duplicated row', function (): void {
    $user = queryUser('query-compound-cursor');

    $ids = [];
    for ($i = 0; $i < 60; $i++) {
        $id = hash('sha256', 'compound-cursor-'.$i);
        $ids[] = $id;
        insertNotification($this->db, $user->id, $id, [
            'created_at' => '2026-07-18 09:00:00',
            'updated_at' => '2026-07-18 09:00:00',
        ]);
    }

    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = $this->query->allForUser($user, $cursor);
        $pages++;
        expect($pages)->toBeLessThan(10); // guard against an infinite loop on a broken cursor

        foreach ($page as $dto) {
            $seen[] = $dto->id;
        }

        if ($page === []) {
            break;
        }

        $last = $page[count($page) - 1];
        $cursor = NotificationQuery::encodeCursor($last->createdAt->toDateTimeString(), $last->id);
    } while (count($page) === 26);

    expect($seen)->toHaveCount(60);
    expect(array_unique($seen))->toHaveCount(60);
    expect(array_diff($ids, $seen))->toBe([]);
});

it('returns the first page rather than throwing on a malformed cursor', function (): void {
    $user = queryUser('query-malformed-cursor');
    insertNotification($this->db, $user->id, str_repeat('6', 64));

    $rows = $this->query->allForUser($user, 'not-a-valid-cursor!!!');

    expect($rows)->toHaveCount(1);
});

it('returns the first page when the cursor is valid base64 but not valid JSON', function (): void {
    $user = queryUser('query-cursor-bad-json');
    insertNotification($this->db, $user->id, str_repeat('7', 64));

    $rows = $this->query->allForUser($user, base64_encode('not json at all'));

    expect($rows)->toHaveCount(1);
});

it('does not let user A see user B rows', function (): void {
    $userA = queryUser('query-user-a');
    $userB = queryUser('query-user-b');
    insertNotification($this->db, $userA->id, str_repeat('7', 64));
    insertNotification($this->db, $userB->id, str_repeat('8', 64));

    $rowsA = $this->query->allForUser($userA);

    expect($rowsA)->toHaveCount(1);
    expect($rowsA[0]->id)->toBe(str_repeat('7', 64));
});

it('matches the unread row count via unreadCountForUser', function (): void {
    $user = queryUser('query-unread-count');
    insertNotification($this->db, $user->id, str_repeat('9', 64)); // unread
    insertNotification($this->db, $user->id, str_repeat('a', 64), ['read_at' => '2026-07-18 09:05:00']); // read
    insertNotification($this->db, $user->id, str_repeat('b', 64)); // unread
    insertNotification($this->db, $user->id, str_repeat('c', 64), ['dismissed_at' => '2026-07-18 09:05:00']); // dismissed, still unread technically but excluded

    expect($this->query->unreadCountForUser($user))->toBe(2);
});
