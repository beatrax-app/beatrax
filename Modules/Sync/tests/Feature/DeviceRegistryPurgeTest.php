<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

/*
 * Revoking a device only cleared confirmed_at, so every other table still
 * held it: the sync-status section lists sync_sessions by peer device id, and
 * a removed device kept appearing there under its own UUID.
 */

function purgeFixture(DatabaseManager $db): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'purge-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $now = '2026-06-14T00:00:00+00:00';
    $selfDeviceId = 'self-'.bin2hex(random_bytes(4));
    $peerDeviceId = 'peer-'.bin2hex(random_bytes(4));

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $selfDeviceId,
        'name' => 'This device',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => '',
        'is_self' => 1,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $peerRowId = (int) $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $peerDeviceId,
        'name' => 'Removed phone',
        'ed25519_public_key_hex' => str_repeat('c', 64),
        'x25519_public_key_hex' => str_repeat('d', 64),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $userId,
        'local_device_id' => $selfDeviceId,
        'peer_device_id' => $peerDeviceId,
        'status' => 'closed',
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('relay_mailbox')->insert([
        'sender_did' => $selfDeviceId,
        'recipient_did' => $peerDeviceId,
        'blob' => '{}',
        'created_at' => $now,
        'expires_at' => '2026-07-14T00:00:00+00:00',
    ]);

    return ['userId' => $userId, 'peerRowId' => $peerRowId, 'peerDeviceId' => $peerDeviceId, 'selfDeviceId' => $selfDeviceId];
}

it('removes every trace of a purged device', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    ['userId' => $userId, 'peerRowId' => $peerRowId, 'peerDeviceId' => $peerDeviceId] = purgeFixture($db);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);
    $registry->purge($userId, $peerRowId);

    expect($db->connection()->table('device_registry')->where('id', $peerRowId)->exists())->toBeFalse()
        ->and($db->connection()->table('sync_sessions')->where('peer_device_id', $peerDeviceId)->exists())->toBeFalse()
        ->and($db->connection()->table('relay_mailbox')->where('recipient_did', $peerDeviceId)->exists())->toBeFalse();
});

it('never purges this device itself', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    ['userId' => $userId, 'selfDeviceId' => $selfDeviceId] = purgeFixture($db);

    $selfRowId = (int) $db->connection()->table('device_registry')
        ->where('device_id', $selfDeviceId)
        ->value('id');

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);
    $registry->purge($userId, $selfRowId);

    expect($db->connection()->table('device_registry')->where('id', $selfRowId)->exists())->toBeTrue();
});
