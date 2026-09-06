<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Mobile\Internal\Http\Livewire\MobileNotificationPermission;
use Modules\Mobile\Internal\Notifications\NotificationGrantRecord;
use Modules\Mobile\Tests\Support\RecordingSystemNotificationConsent;
use Modules\Notifications\Public\Contracts\SystemNotificationConsent;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Enums\SystemNotificationGrant;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;

uses(RefreshDatabase::class);

// The shipped defaults have reminders and budget nudges on, and the OS drops
// every notification until it has been asked. Asking only inside the settings
// form meant a reader who never opened it was never asked, for the life of
// the install.

function permissionReader(string $username = 'grant-reader'): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    test()->actingAs($user);

    return $user;
}

function permissionConsentRecorder(): RecordingSystemNotificationConsent
{
    $recorder = new RecordingSystemNotificationConsent;
    app()->instance(SystemNotificationConsent::class, $recorder);

    return $recorder;
}

it('asks the device on a page every reader reaches, not only inside the settings form', function (): void {
    permissionReader();
    $consent = permissionConsentRecorder();

    Livewire::test(MobileNotificationPermission::class)
        ->assertSet('askOnLoad', true)
        ->call('askTheDevice');

    expect($consent->asks)->toBe(1);
});

it('stamps the ask before the dialog, so a reader who leaves it open is not asked every page', function (): void {
    $user = permissionReader();
    permissionConsentRecorder();

    Livewire::test(MobileNotificationPermission::class)->call('askTheDevice');

    expect(app(NotificationGrantRecord::class)->state((int) $user->id))
        ->toBe(SystemNotificationGrant::Awaiting);
});

it('re-asks while the answer is outstanding, because a repeat ask shows nothing and returns the settled value', function (): void {
    $user = permissionReader();
    app(NotificationGrantRecord::class)->markAsked((int) $user->id);

    Livewire::test(MobileNotificationPermission::class)->assertSet('askOnLoad', true);
});

it('asks nothing once the device has answered', function (): void {
    $user = permissionReader();
    app(NotificationGrantRecord::class)->recordAnswer((int) $user->id, true);

    Livewire::test(MobileNotificationPermission::class)->assertSet('askOnLoad', false);
});

it('spends the one prompt an install gets on nothing when every trigger is off', function (): void {
    $user = permissionReader();

    app(NotificationPreferenceQuery::class)->saveForCurrentDevice($user, new NotificationPreferencesDto(
        remindersEnabled: false,
        budgetNudgesEnabled: false,
        digestCadence: DigestCadence::Off,
        savingsPromptsEnabled: false,
        reminderLeadDays: 3,
        quietHoursEnabled: false,
        quietHoursFrom: '22:00',
        quietHoursTo: '08:00',
        hideDetails: false,
    ));

    Livewire::test(MobileNotificationPermission::class)->assertSet('askOnLoad', false);
});

it('records the refusal the page hands back, which is what the settings screen reads', function (): void {
    $user = permissionReader();

    Livewire::test(MobileNotificationPermission::class)
        ->call('recordDeviceAnswer', false)
        ->assertSet('askOnLoad', false);

    expect(app(NotificationGrantRecord::class)->state((int) $user->id))
        ->toBe(SystemNotificationGrant::Refused);
});

it('records a grant as a grant', function (): void {
    $user = permissionReader();

    Livewire::test(MobileNotificationPermission::class)->call('recordDeviceAnswer', true);

    expect(app(NotificationGrantRecord::class)->state((int) $user->id))
        ->toBe(SystemNotificationGrant::Granted);
});
