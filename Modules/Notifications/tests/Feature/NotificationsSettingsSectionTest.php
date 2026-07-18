<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Http\Livewire\NotificationsSettingsSection;
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;

uses(RefreshDatabase::class);

/*
 * NotificationsSettingsSectionTest — the Settings "Notifications" section
 * (D-36): the ~9-control preferences form + the D-35 read-only "Other
 * devices" panel.
 *
 * Local helpers are uniquely named (settingsSectionUser /
 * settingsSeedRegistryDevice) rather than reusing prefUser() /
 * seedRegistryDevice() from NotificationPreferenceQueryTest.php — this file
 * must also pass when run standalone (`pest <this file>`), so it cannot
 * depend on another test file having been loaded first, and a same-named
 * global function declared in both files would fatal on redeclaration when
 * the full suite runs both.
 */

function settingsSectionUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function settingsSeedRegistryDevice(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Device '.$deviceId,
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

it('mounts showing exactly the D-16/D-19/D-15/D-24 defaults for a device with no preference row', function (): void {
    $user = settingsSectionUser('settings-defaults');
    settingsSeedRegistryDevice($this->app->make(DatabaseManager::class), $user->id, 'self-device', isSelf: true);
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertSet('remindersEnabled', true)
        ->assertSet('budgetNudgesEnabled', true)
        ->assertSet('digestCadence', 'weekly')
        ->assertSet('savingsPromptsEnabled', false)
        ->assertSet('reminderLeadDays', 3)
        ->assertSet('quietHoursEnabled', false)
        ->assertSet('quietHoursFrom', '22:00')
        ->assertSet('quietHoursTo', '08:00')
        ->assertSet('hideDetails', false);
});

it('persists a valid save and sets $saved, and a re-mount reads the saved values back', function (): void {
    $user = settingsSectionUser('settings-valid-save');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    settingsSeedRegistryDevice($db, $user->id, 'self-device', isSelf: true);
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->set('remindersEnabled', false)
        ->set('budgetNudgesEnabled', false)
        ->set('digestCadence', 'daily')
        ->set('savingsPromptsEnabled', true)
        ->set('reminderLeadDays', 7)
        ->set('quietHoursEnabled', true)
        ->set('quietHoursFrom', '23:00')
        ->set('quietHoursTo', '07:30')
        ->set('hideDetails', true)
        ->call('save')
        ->assertSet('saved', true)
        ->assertSet('saveError', '');

    $row = $db->connection()->table('notification_preferences')
        ->where('user_id', $user->id)
        ->where('device_id', 'self-device')
        ->first();

    expect($row)->not->toBeNull();
    expect((bool) $row->reminders_enabled)->toBeFalse();
    expect((bool) $row->budget_nudges_enabled)->toBeFalse();
    expect($row->digest_cadence)->toBe('daily');
    expect((bool) $row->savings_prompts_enabled)->toBeTrue();
    expect((int) $row->reminder_lead_days)->toBe(7);
    expect((bool) $row->quiet_hours_enabled)->toBeTrue();
    expect($row->quiet_hours_from)->toBe('23:00');
    expect($row->quiet_hours_to)->toBe('07:30');
    expect((bool) $row->hide_details)->toBeTrue();

    // Re-mounting reads the saved values back.
    Livewire::test(NotificationsSettingsSection::class)
        ->assertSet('remindersEnabled', false)
        ->assertSet('budgetNudgesEnabled', false)
        ->assertSet('digestCadence', 'daily')
        ->assertSet('savingsPromptsEnabled', true)
        ->assertSet('reminderLeadDays', 7)
        ->assertSet('quietHoursEnabled', true)
        ->assertSet('quietHoursFrom', '23:00')
        ->assertSet('quietHoursTo', '07:30')
        ->assertSet('hideDetails', true);
});

it('rejects an invalid digest cadence, sets $saveError, persists nothing (T-18-06)', function (): void {
    $user = settingsSectionUser('settings-bad-cadence');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    settingsSeedRegistryDevice($db, $user->id, 'self-device', isSelf: true);
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->set('digestCadence', 'hourly')
        ->call('save')
        ->assertSet('saved', false)
        ->assertSet('saveError', "Couldn't save your notification settings. Try again.");

    expect($db->connection()->table('notification_preferences')->count())->toBe(0);
});

it('rejects a reminder lead time of 0, sets $saveError, persists nothing (T-18-06)', function (): void {
    $user = settingsSectionUser('settings-lead-0');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    settingsSeedRegistryDevice($db, $user->id, 'self-device', isSelf: true);
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->set('reminderLeadDays', 0)
        ->call('save')
        ->assertSet('saved', false)
        ->assertSet('saveError', "Couldn't save your notification settings. Try again.");

    expect($db->connection()->table('notification_preferences')->count())->toBe(0);
});

it('rejects a reminder lead time of 31, sets $saveError, persists nothing (T-18-06)', function (): void {
    $user = settingsSectionUser('settings-lead-31');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    settingsSeedRegistryDevice($db, $user->id, 'self-device', isSelf: true);
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->set('reminderLeadDays', 31)
        ->call('save')
        ->assertSet('saved', false)
        ->assertSet('saveError', "Couldn't save your notification settings. Try again.");

    expect($db->connection()->table('notification_preferences')->count())->toBe(0);
});

it('rejects a malformed quiet-hours time, sets $saveError, persists nothing (T-18-06)', function (): void {
    $user = settingsSectionUser('settings-bad-time');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    settingsSeedRegistryDevice($db, $user->id, 'self-device', isSelf: true);
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->set('quietHoursFrom', '99:99')
        ->call('save')
        ->assertSet('saved', false)
        ->assertSet('saveError', "Couldn't save your notification settings. Try again.");

    expect($db->connection()->table('notification_preferences')->count())->toBe(0);
});

it('dispatches exactly one NotificationPreferenceMutated on a valid save (D-34)', function (): void {
    Event::fake([NotificationPreferenceMutated::class]);

    $user = settingsSectionUser('settings-event');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    settingsSeedRegistryDevice($db, $user->id, 'self-device', isSelf: true);
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->call('save')
        ->assertSet('saved', true);

    Event::assertDispatchedTimes(NotificationPreferenceMutated::class, 1);
});

it('lists a second paired device in the other-devices panel and excludes the self row (D-35)', function (): void {
    $user = settingsSectionUser('settings-other-devices');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    settingsSeedRegistryDevice($db, $user->id, 'self-device', isSelf: true);
    settingsSeedRegistryDevice($db, $user->id, 'peer-device', isSelf: false);

    $db->connection()->table('notification_preferences')->insert([
        'user_id' => $user->id,
        'device_id' => 'peer-device',
        'reminders_enabled' => false,
        'budget_nudges_enabled' => true,
        'digest_cadence' => 'off',
        'savings_prompts_enabled' => true,
        'reminder_lead_days' => 5,
        'quiet_hours_enabled' => true,
        'quiet_hours_from' => '21:00',
        'quiet_hours_to' => '06:00',
        'hide_details' => true,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);

    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertSee('Device peer-device')
        ->assertSee('reminders off')
        ->assertSee('digest off')
        ->assertDontSee('Device self-device');
});

it('renders the empty-state string when no other devices are paired', function (): void {
    $user = settingsSectionUser('settings-no-other-devices');
    settingsSeedRegistryDevice($this->app->make(DatabaseManager::class), $user->id, 'self-device', isSelf: true);
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertSee('No other devices paired yet.');
});

it('never changes the other device row when saving locally (D-07/D-35)', function (): void {
    $user = settingsSectionUser('settings-other-untouched');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    settingsSeedRegistryDevice($db, $user->id, 'self-device', isSelf: true);
    settingsSeedRegistryDevice($db, $user->id, 'peer-device', isSelf: false);

    $peerRow = [
        'user_id' => $user->id,
        'device_id' => 'peer-device',
        'reminders_enabled' => false,
        'budget_nudges_enabled' => true,
        'digest_cadence' => 'off',
        'savings_prompts_enabled' => true,
        'reminder_lead_days' => 5,
        'quiet_hours_enabled' => true,
        'quiet_hours_from' => '21:00',
        'quiet_hours_to' => '06:00',
        'hide_details' => true,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ];
    $db->connection()->table('notification_preferences')->insert($peerRow);

    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->set('remindersEnabled', false)
        ->set('digestCadence', 'daily')
        ->call('save')
        ->assertSet('saved', true);

    $peerAfter = $db->connection()->table('notification_preferences')
        ->where('user_id', $user->id)
        ->where('device_id', 'peer-device')
        ->first();

    expect((bool) $peerAfter->reminders_enabled)->toBeFalse();
    expect((bool) $peerAfter->budget_nudges_enabled)->toBeTrue();
    expect($peerAfter->digest_cadence)->toBe('off');
    expect((bool) $peerAfter->savings_prompts_enabled)->toBeTrue();
    expect((int) $peerAfter->reminder_lead_days)->toBe(5);
    expect((bool) $peerAfter->quiet_hours_enabled)->toBeTrue();
    expect($peerAfter->quiet_hours_from)->toBe('21:00');
    expect($peerAfter->quiet_hours_to)->toBe('06:00');
    expect((bool) $peerAfter->hide_details)->toBeTrue();
});

it('never renders user B device rows on user A\'s section (cross-user)', function (): void {
    $userA = settingsSectionUser('settings-cross-a');
    $userB = settingsSectionUser('settings-cross-b');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    settingsSeedRegistryDevice($db, $userA->id, 'a-self-device', isSelf: true);
    settingsSeedRegistryDevice($db, $userB->id, 'b-self-device', isSelf: true);
    settingsSeedRegistryDevice($db, $userB->id, 'b-peer-device', isSelf: false);

    $db->connection()->table('notification_preferences')->insert([
        'user_id' => $userB->id,
        'device_id' => 'b-peer-device',
        'reminders_enabled' => true,
        'budget_nudges_enabled' => true,
        'digest_cadence' => 'weekly',
        'savings_prompts_enabled' => false,
        'reminder_lead_days' => 3,
        'quiet_hours_enabled' => false,
        'quiet_hours_from' => '22:00',
        'quiet_hours_to' => '08:00',
        'hide_details' => false,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);

    $this->actingAs($userA);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertDontSee('Device b-peer-device')
        ->assertDontSee('Device b-self-device')
        ->assertSee('No other devices paired yet.');
});
