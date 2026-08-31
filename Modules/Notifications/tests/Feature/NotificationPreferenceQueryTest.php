<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;

uses(RefreshDatabase::class);

function prefUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function seedRegistryDevice(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf, ?string $confirmedAt = '2026-07-01T10:05:00Z'): void
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
        'confirmed_at' => $confirmedAt,
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    /** @var NotificationPreferenceQuery $query */
    $query = $this->app->make(NotificationPreferenceQuery::class);
    $this->query = $query;
});

it('reads reminders on, weekly digest, savings prompts off and quiet hours off for a device with no row', function (): void {
    $user = prefUser('pref-defaults');
    seedRegistryDevice($this->db, $user->id, 'self-device', isSelf: true);

    $prefs = $this->query->forCurrentDevice($user);

    expect($prefs->remindersEnabled)->toBeTrue();
    expect($prefs->budgetNudgesEnabled)->toBeTrue();
    expect($prefs->digestCadence)->toBe(DigestCadence::Weekly);
    expect($prefs->savingsPromptsEnabled)->toBeFalse();
    expect($prefs->reminderLeadDays)->toBe(3);
    expect($prefs->quietHoursEnabled)->toBeFalse();
    expect($prefs->quietHoursFrom)->toBe('22:00');
    expect($prefs->quietHoursTo)->toBe('08:00');
    expect($prefs->hideDetails)->toBeFalse();
    expect($prefs->deviceId)->toBe('self-device');
});

// An install with no sync identity is the default state, not an error state,
// so it writes under the reserved key rather than being refused a row.
it('returns defaults under the unpaired key for a device with no sync identity', function (): void {
    $user = prefUser('pref-unpaired');

    $prefs = $this->query->forCurrentDevice($user);

    expect($prefs->deviceId)->toBe(NotificationPreferenceQuery::UNPAIRED_DEVICE_ID);
    expect($prefs->digestCadence)->toBe(DigestCadence::Weekly);
    expect($prefs->reminderLeadDays)->toBe(3);
});

it('round-trips a saved row', function (): void {
    Event::fake([NotificationPreferenceMutated::class]);

    $user = prefUser('pref-roundtrip');
    seedRegistryDevice($this->db, $user->id, 'self-device', isSelf: true);

    $this->query->saveForCurrentDevice($user, new NotificationPreferencesDto(
        remindersEnabled: false,
        budgetNudgesEnabled: false,
        digestCadence: DigestCadence::Daily,
        savingsPromptsEnabled: true,
        reminderLeadDays: 7,
        quietHoursEnabled: true,
        quietHoursFrom: '23:00',
        quietHoursTo: '07:30',
        hideDetails: true,
    ));

    $prefs = $this->query->forCurrentDevice($user);

    expect($prefs->remindersEnabled)->toBeFalse();
    expect($prefs->budgetNudgesEnabled)->toBeFalse();
    expect($prefs->digestCadence)->toBe(DigestCadence::Daily);
    expect($prefs->savingsPromptsEnabled)->toBeTrue();
    expect($prefs->reminderLeadDays)->toBe(7);
    expect($prefs->quietHoursEnabled)->toBeTrue();
    expect($prefs->quietHoursFrom)->toBe('23:00');
    expect($prefs->quietHoursTo)->toBe('07:30');
    expect($prefs->hideDetails)->toBeTrue();
});

it('excludes the self row and includes a peer device in forOtherDevices()', function (): void {
    $user = prefUser('pref-others');
    seedRegistryDevice($this->db, $user->id, 'self-device', isSelf: true);
    seedRegistryDevice($this->db, $user->id, 'peer-device', isSelf: false);

    $this->db->connection()->table('notification_preferences')->insert([
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

    // A self row, which forOtherDevices() must exclude.
    $this->db->connection()->table('notification_preferences')->insert([
        'user_id' => $user->id,
        'device_id' => 'self-device',
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

    $others = $this->query->forOtherDevices($user);

    expect($others)->toHaveCount(1);
    expect($others[0]->deviceId)->toBe('peer-device');
    expect($others[0]->deviceName)->toBe('Device peer-device');
    expect($others[0]->digestCadence)->toBe(DigestCadence::Off);
});

it('does not let user A read user B preferences', function (): void {
    $userA = prefUser('pref-a');
    $userB = prefUser('pref-b');
    seedRegistryDevice($this->db, $userA->id, 'shared-device-id', isSelf: true);
    seedRegistryDevice($this->db, $userB->id, 'shared-device-id', isSelf: true);

    // User B stores a distinctive row under the SAME device id string.
    $this->db->connection()->table('notification_preferences')->insert([
        'user_id' => $userB->id,
        'device_id' => 'shared-device-id',
        'reminders_enabled' => false,
        'budget_nudges_enabled' => false,
        'digest_cadence' => 'daily',
        'savings_prompts_enabled' => true,
        'reminder_lead_days' => 10,
        'quiet_hours_enabled' => true,
        'quiet_hours_from' => '20:00',
        'quiet_hours_to' => '05:00',
        'hide_details' => true,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);

    // User A has no row → must read defaults, NOT user B's stored values.
    $prefsA = $this->query->forCurrentDevice($userA);

    expect($prefsA->digestCadence)->toBe(DigestCadence::Weekly);
    expect($prefsA->reminderLeadDays)->toBe(3);
    expect($prefsA->savingsPromptsEnabled)->toBeFalse();
});

it('refuses a cadence the enum cannot represent, at the column', function (): void {
    $user = prefUser('pref-bad-cadence');
    seedRegistryDevice($this->db, $user->id, 'self-device', isSelf: true);

    $this->query->saveForCurrentDevice($user, new NotificationPreferencesDto(
        remindersEnabled: true,
        budgetNudgesEnabled: true,
        digestCadence: DigestCadence::Weekly,
        savingsPromptsEnabled: false,
        reminderLeadDays: 3,
        quietHoursEnabled: false,
        quietHoursFrom: '22:00',
        quietHoursTo: '08:00',
        hideDetails: false,
    ));

    // The DTO can no longer carry an invalid cadence, so what is left to
    // protect is the guarantee against writes from outside the application:
    // the column itself refuses the value.
    expect(fn () => $this->db->connection()->table('notification_preferences')
        ->where('user_id', $user->id)
        ->update(['digest_cadence' => 'monthly']))
        ->toThrow(QueryException::class);
});

it('throws on a lead time of 0', function (): void {
    $user = prefUser('pref-lead-0');
    seedRegistryDevice($this->db, $user->id, 'self-device', isSelf: true);

    $prefs = new NotificationPreferencesDto(
        remindersEnabled: true,
        budgetNudgesEnabled: true,
        digestCadence: DigestCadence::Weekly,
        savingsPromptsEnabled: false,
        reminderLeadDays: 0,
        quietHoursEnabled: false,
        quietHoursFrom: '22:00',
        quietHoursTo: '08:00',
        hideDetails: false,
    );

    expect(fn () => $this->query->saveForCurrentDevice($user, $prefs))
        ->toThrow(InvalidArgumentException::class);
});

it('throws on a lead time of 31', function (): void {
    $user = prefUser('pref-lead-31');
    seedRegistryDevice($this->db, $user->id, 'self-device', isSelf: true);

    $prefs = new NotificationPreferencesDto(
        remindersEnabled: true,
        budgetNudgesEnabled: true,
        digestCadence: DigestCadence::Weekly,
        savingsPromptsEnabled: false,
        reminderLeadDays: 31,
        quietHoursEnabled: false,
        quietHoursFrom: '22:00',
        quietHoursTo: '08:00',
        hideDetails: false,
    );

    expect(fn () => $this->query->saveForCurrentDevice($user, $prefs))
        ->toThrow(InvalidArgumentException::class);
});

it('throws on a malformed quiet-hours time', function (): void {
    $user = prefUser('pref-bad-time');
    seedRegistryDevice($this->db, $user->id, 'self-device', isSelf: true);

    $prefs = new NotificationPreferencesDto(
        remindersEnabled: true,
        budgetNudgesEnabled: true,
        digestCadence: DigestCadence::Weekly,
        savingsPromptsEnabled: false,
        reminderLeadDays: 3,
        quietHoursEnabled: true,
        quietHoursFrom: '9am',
        quietHoursTo: '08:00',
        hideDetails: false,
    );

    expect(fn () => $this->query->saveForCurrentDevice($user, $prefs))
        ->toThrow(InvalidArgumentException::class);
});

it('dispatches exactly one NotificationPreferenceMutated on save', function (): void {
    Event::fake([NotificationPreferenceMutated::class]);
    // The beforeEach singleton captured the real dispatcher, so it has to be
    // rebuilt after Event::fake to see the faked one.
    $this->app->forgetInstance(NotificationPreferenceQuery::class);
    /** @var NotificationPreferenceQuery $query */
    $query = $this->app->make(NotificationPreferenceQuery::class);

    $user = prefUser('pref-event');
    seedRegistryDevice($this->db, $user->id, 'self-device', isSelf: true);

    $query->saveForCurrentDevice($user, NotificationPreferencesDto::defaults());

    Event::assertDispatchedTimes(NotificationPreferenceMutated::class, 1);
    Event::assertDispatched(NotificationPreferenceMutated::class, function (NotificationPreferenceMutated $event) use ($user): bool {
        return $event->userId === $user->id && $event->mutationType === 'create';
    });
});

it('writes and announces a row for a device with no sync identity', function (): void {
    Event::fake([NotificationPreferenceMutated::class]);

    $user = prefUser('pref-noop');

    // Rebuilt after the fake: the query is a singleton, so the instance
    // beforeEach() resolved still holds the real dispatcher.
    $this->app->forgetInstance(NotificationPreferenceQuery::class);

    /** @var NotificationPreferenceQuery $query */
    $query = $this->app->make(NotificationPreferenceQuery::class);
    $query->saveForCurrentDevice($user, NotificationPreferencesDto::defaults());

    Event::assertDispatched(NotificationPreferenceMutated::class);
    expect($this->db->connection()->table('notification_preferences')->count())->toBe(1);
});
