<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;

uses(RefreshDatabase::class);

// `device_registry.last_seen_at` is written by SyncSession on every exchange
// and was read by nothing: the list showed a name, a fingerprint and a paired
// date, and a reader deciding whether a device is still theirs had no way to
// tell a phone that synced this morning from one that has not answered in
// months. The column was there the whole time.

function lastSeenUser(string $suffix): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'last-seen-'.$suffix,
        'password' => bcrypt('fixture-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    return $user;
}

function lastSeenPeer(DatabaseManager $db, int $userId, string $deviceId, ?string $lastSeenAt): int
{
    return (int) $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Old Laptop',
        'ed25519_public_key_hex' => str_repeat('c', 64),
        'x25519_public_key_hex' => str_repeat('d', 64),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-06-14T00:00:00+00:00',
        'confirmed_at' => '2026-06-14T00:00:00+00:00',
        'last_seen_at' => $lastSeenAt,
        'created_at' => '2026-06-14T00:00:00+00:00',
        'updated_at' => '2026-06-14T00:00:00+00:00',
    ]);
}

it('shows when a paired device was last heard from', function (): void {
    $user = lastSeenUser('shown');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $seenAt = now()->subHours(3);
    lastSeenPeer($db, $user->id, 'peer-seen-recently', $seenAt->toIso8601String());

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('syncEnabled', true)
        ->assertSee(Lang::get('sync::devices.last_seen', ['when' => $seenAt->diffForHumans()]));
});

// A device confirmed but never yet connected has a null column, and "Last seen
// 56 years ago" is what reading that as a date would produce.
it('says so when a device has never connected, rather than reading null as a date', function (): void {
    $user = lastSeenUser('never');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    lastSeenPeer($db, $user->id, 'peer-never-seen', null);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('syncEnabled', true)
        ->assertSee(Lang::get('sync::devices.last_seen_never'))
        ->assertDontSee('1970');
});
