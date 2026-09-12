<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// Two callers take the FIRST entry of otherDeviceNames() as "the peer":
// PeerLanAddress dials it, and ManagesManualPeerAddress offers the reader an
// address for it. Both say so. What neither could say was WHICH device that is.
//
// The read carried no order at all, and the order it appeared to have was the
// planner's: `device_registry_user_idx (user_id, confirmed_at)` happens to
// satisfy the predicate, so rows came back by confirmation time. That is not a
// contract — a second predicate, a new index or an ANALYZE moves it — and it
// says nothing at all when two devices were confirmed in the same second,
// which the column's granularity makes an ordinary state rather than a rare
// one. Then the first entry is whichever row the scan reached first, and a
// household with two confirmed desktops gets one dialled, its address
// remembered, and that address cleared on behalf of the other.

function confirmedPeer(DatabaseManager $db, int $userId, string $deviceId, string $name, string $confirmedAt): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $name,
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => '',
        'is_self' => 0,
        'paired_at' => $confirmedAt,
        'confirmed_at' => $confirmedAt,
        'created_at' => $confirmedAt,
        'updated_at' => $confirmedAt,
    ]);
}

function peerFixtureUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'peers-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('breaks a same-second tie by device id rather than by scan order', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = peerFixtureUser($db);

    // Confirmed in the same second, inserted with the later device id first,
    // so the index the planner reaches for cannot separate them and rowid
    // order is the only thing left to answer with.
    $sameSecond = '2026-06-14T09:30:00+00:00';
    confirmedPeer($db, $userId, 'zz-study-desktop', 'Study desktop', $sameSecond);
    confirmedPeer($db, $userId, 'aa-kitchen-desktop', 'Kitchen desktop', $sameSecond);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    expect(array_keys($registry->otherDeviceNames($userId)))->toBe(
        ['aa-kitchen-desktop', 'zz-study-desktop'],
        'two devices confirmed in the same second still have to come back in one stated order, or "the peer" is whichever row the scan reached first',
    );
});

it('gives the same answer after an unrelated device is added and removed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = peerFixtureUser($db);

    $sameSecond = '2026-06-14T09:30:00+00:00';
    confirmedPeer($db, $userId, 'zz-study-desktop', 'Study desktop', $sameSecond);
    confirmedPeer($db, $userId, 'aa-kitchen-desktop', 'Kitchen desktop', $sameSecond);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    $before = array_keys($registry->otherDeviceNames($userId));

    confirmedPeer($db, $userId, 'mm-loft-desktop', 'Loft desktop', $sameSecond);
    $db->connection()->table('device_registry')->where('device_id', 'mm-loft-desktop')->delete();

    expect(array_keys($registry->otherDeviceNames($userId)))->toBe(
        $before,
        'a device that came and went must not move the peer the next press dials',
    );
});
