<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Notifications\Public\Contracts\SystemNotificationGrantState;
use Modules\Notifications\Public\Enums\SystemNotificationGrant;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;

uses(RefreshDatabase::class);

// Every toggle on this screen belongs to the reader. Whether anything the
// screen decides can appear at all belongs to the operating system, and a
// page showing nine switches on while the platform drops all of it is saying
// something untrue.

function grantStateReader(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($user);

    return $user;
}

function bindGrantState(SystemNotificationGrant $state): void
{
    app()->instance(SystemNotificationGrantState::class, new class($state) implements SystemNotificationGrantState
    {
        public function __construct(private SystemNotificationGrant $state) {}

        public function current(): SystemNotificationGrant
        {
            return $this->state;
        }
    });
}

it('tells the reader when their device is refusing everything the screen decides', function (): void {
    grantStateReader('grant-refused');
    bindGrantState(SystemNotificationGrant::Refused);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertSet('saveError', '')
        ->assertSee('notifications-system-grant-refused', escape: false);
});

it('says nothing about the platform when the platform has allowed it', function (): void {
    grantStateReader('grant-granted');
    bindGrantState(SystemNotificationGrant::Granted);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertDontSee('notifications-system-grant-refused', escape: false);
});

it('says nothing while the answer is outstanding, which is not a refusal', function (): void {
    grantStateReader('grant-awaiting');
    bindGrantState(SystemNotificationGrant::Awaiting);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertDontSee('notifications-system-grant-refused', escape: false);
});

it('says nothing on a platform that gates nothing', function (): void {
    grantStateReader('grant-not-applicable');
    bindGrantState(SystemNotificationGrant::NotApplicable);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertDontSee('notifications-system-grant-refused', escape: false);
});
