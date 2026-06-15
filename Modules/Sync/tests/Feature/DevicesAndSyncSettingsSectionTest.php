<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\DevicesAndSyncSettingsSection;

uses(RefreshDatabase::class);

/*
 * DevicesAndSyncSettingsSectionTest — PAIR-03 Livewire device list + rename,
 * plus the D-02 "set an app-lock first" enable-sync gate.
 *
 * RED until Plan 03 ships the Livewire component
 * Modules\Sync\Internal\Http\Livewire\DevicesAndSyncSettingsSection and its
 * registration. Failure is "class not found" / "Unable to find component".
 */

function settingsUser(string $username = 'devices-settings-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('devices-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('mounts with a 200 status for an authenticated user', function (): void {
    $user = settingsUser();
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertStatus(200);
});

it('blocks enable-sync with the app-lock gate copy when no app-lock is configured (D-02)', function (): void {
    $user = settingsUser('devices-nolock');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->call('enableSync')
        ->assertSet('flashMessage', 'Set an app lock first to enable sync.');
});

it('it_can_rename_a_device', function (): void {
    $user = settingsUser('devices-rename');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $deviceRowId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $user->id,
        'device_id' => 'device-rename',
        'name' => 'Old Name',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 1,
        'paired_at' => '2026-06-15T10:00:00Z',
        'confirmed_at' => '2026-06-15T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-06-15T10:00:00Z',
        'updated_at' => '2026-06-15T10:00:00Z',
    ]);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->call('renameDevice', $deviceRowId, 'New Name')
        ->assertHasNoErrors();

    $name = $db->connection()->table('device_registry')
        ->where('id', $deviceRowId)
        ->where('user_id', $user->id)
        ->value('name');

    expect($name)->toBe('New Name');
});
