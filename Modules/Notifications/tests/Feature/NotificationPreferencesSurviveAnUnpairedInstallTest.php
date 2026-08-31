<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

uses(RefreshDatabase::class);

// A single-device install never pairs, so it never has a device_registry row
// with is_self=1. The whole notifications settings section wrote nothing on
// such an install and said "Saved", quiet hours included — so the OS kept
// posting at 03:00 with the amounts in the banner.

function unpairedPreferenceUser(): User
{
    return User::query()->create([
        'username' => 'unpaired-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function unpairedQuietHours(): NotificationPreferencesDto
{
    return new NotificationPreferencesDto(
        remindersEnabled: false,
        budgetNudgesEnabled: false,
        digestCadence: DigestCadence::Weekly,
        savingsPromptsEnabled: false,
        reminderLeadDays: 9,
        quietHoursEnabled: true,
        quietHoursFrom: '22:00',
        quietHoursTo: '08:00',
        hideDetails: true,
    );
}

function pairThisInstall(DatabaseManager $db, int $userId, string $deviceId): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'This device',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 1,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
}

it('keeps a preference written on an install that never paired', function (): void {
    $user = unpairedPreferenceUser();

    /** @var NotificationPreferenceQuery $query */
    $query = $this->app->make(NotificationPreferenceQuery::class);
    $query->saveForCurrentDevice($user, unpairedQuietHours());

    $reloaded = $query->forCurrentDevice($user);

    expect($reloaded->quietHoursEnabled)->toBeTrue()
        ->and($reloaded->hideDetails)->toBeTrue()
        ->and($reloaded->reminderLeadDays)->toBe(9)
        ->and($reloaded->remindersEnabled)->toBeFalse();
});

it('suppresses a banner inside the quiet hours an unpaired install chose', function (): void {
    $user = unpairedPreferenceUser();

    /** @var NotificationPreferenceQuery $query */
    $query = $this->app->make(NotificationPreferenceQuery::class);
    $query->saveForCurrentDevice($user, unpairedQuietHours());

    /** @var SuppressionEvaluator $evaluator */
    $evaluator = $this->app->make(SuppressionEvaluator::class);

    $decision = $evaluator->shouldDeliver(
        $user->id,
        NotificationTrigger::PaymentReminder,
        CarbonImmutable::parse('2026-07-18 03:00:00'),
    );

    expect($decision->deliver)->toBeFalse()
        ->and($decision->hideDetails)->toBeTrue();
});

it('reloads the settings section with what the reader chose rather than the defaults', function (): void {
    $user = unpairedPreferenceUser();

    Livewire::actingAs($user)->test(NotificationsSettingsSection::class)
        ->set('quietHoursEnabled', true)
        ->set('hideDetails', true)
        ->set('reminderLeadDays', 9)
        ->call('save')
        ->assertSet('saveError', '')
        ->assertSet('saved', true);

    Livewire::actingAs($user)->test(NotificationsSettingsSection::class)
        ->assertSet('quietHoursEnabled', true)
        ->assertSet('hideDetails', true)
        ->assertSet('reminderLeadDays', 9);
});

it('carries the pre-pairing row onto the sync identity rather than losing it', function (): void {
    $user = unpairedPreferenceUser();

    /** @var NotificationPreferenceQuery $query */
    $query = $this->app->make(NotificationPreferenceQuery::class);
    $query->saveForCurrentDevice($user, unpairedQuietHours());

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    pairThisInstall($db, $user->id, 'a-real-device-id');

    expect($query->forCurrentDevice($user)->quietHoursEnabled)->toBeTrue();

    $query->saveForCurrentDevice($user, unpairedQuietHours());

    $rows = $db->connection()->table('notification_preferences')->where('user_id', $user->id);

    expect((clone $rows)->count())->toBe(1)
        ->and((clone $rows)->value('device_id'))->toBe('a-real-device-id')
        ->and($query->forOtherDevices($user))->toBe([]);
});
