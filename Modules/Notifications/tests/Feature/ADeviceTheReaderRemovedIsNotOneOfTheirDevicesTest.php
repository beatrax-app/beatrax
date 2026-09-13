<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// Removing a device takes its trust, its sessions, its mailbox and its tokens.
// It does not take its notification_preferences row, and the panel excluded
// only THIS device rather than admitting the ones the registry still answers
// for -- so the removed device stayed on the list for ever, and nameless, since
// the registry stops naming a device it no longer confirms.

function removedDeviceReaderUser(): User
{
    return User::query()->create([
        'username' => 'removed-device-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function removedDeviceRegistryRow(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf): int
{
    return (int) $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'The kitchen laptop',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
}

function removedDevicePreferenceRow(DatabaseManager $db, int $userId, string $deviceId): void
{
    $db->connection()->table('notification_preferences')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'reminders_enabled' => 1,
        'budget_nudges_enabled' => 1,
        'digest_cadence' => DigestCadence::Daily->value,
        'savings_prompts_enabled' => 0,
        'reminder_lead_days' => 3,
        'quiet_hours_enabled' => 0,
        'quiet_hours_from' => '22:00',
        'quiet_hours_to' => '08:00',
        'hide_details' => 0,
        'created_at' => '2026-07-01 10:00:00',
        'updated_at' => '2026-07-01 10:00:00',
    ]);
}

it('drops a removed peer from the other-devices panel, and keeps a confirmed one', function (): void {
    $user = removedDeviceReaderUser();
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    removedDeviceRegistryRow($db, (int) $user->id, 'self-device', isSelf: true);
    $removedId = removedDeviceRegistryRow($db, (int) $user->id, 'removed-peer', isSelf: false);
    removedDeviceRegistryRow($db, (int) $user->id, 'kept-peer', isSelf: false);
    removedDevicePreferenceRow($db, (int) $user->id, 'removed-peer');
    removedDevicePreferenceRow($db, (int) $user->id, 'kept-peer');

    /** @var NotificationPreferenceQuery $prefs */
    $prefs = $this->app->make(NotificationPreferenceQuery::class);
    $listed = static function () use ($prefs, $user): array {
        $ids = array_map(static fn ($dto): ?string => $dto->deviceId, $prefs->forOtherDevices($user));
        sort($ids);

        return $ids;
    };

    expect($listed())->toBe(['kept-peer', 'removed-peer']);

    $this->app->make(DeviceRegistryService::class)->purge((int) $user->id, $removedId);

    expect($listed())->toBe(['kept-peer']);

    // The row is still on disk: this is the reading seam answering for the
    // registry, not a delete the removal never promised.
    expect($db->connection()->table('notification_preferences')->where('device_id', 'removed-peer')->exists())
        ->toBeTrue();
});

it('stops listing the removed device on the settings screen', function (): void {
    $user = removedDeviceReaderUser();
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    removedDeviceRegistryRow($db, (int) $user->id, 'self-device', isSelf: true);
    $removedId = removedDeviceRegistryRow($db, (int) $user->id, 'removed-peer', isSelf: false);
    removedDevicePreferenceRow($db, (int) $user->id, 'removed-peer');

    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)->assertSee('The kitchen laptop');

    $this->app->make(DeviceRegistryService::class)->purge((int) $user->id, $removedId);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertDontSee('The kitchen laptop')
        ->assertDontSee('Unnamed device')
        ->assertSee('No other devices paired yet.');
});
