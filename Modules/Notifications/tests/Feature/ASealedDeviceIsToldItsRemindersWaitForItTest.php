<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// A phone with an app lock produces no reminders, nudges or digest until it is
// opened, and every switch on this screen reads as though it does. The note is
// what stops the reader concluding, from an inbox that stays empty, that the
// feature is broken rather than waiting for them.

function srwUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('tells a reader whose ledger is sealed that these are prepared while the app is open', function (): void {
    $user = srwUser('srw-sealed');
    $this->enablesEncryptionForUser($user);
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertSet('preparedOnlyWhileOpen', true)
        ->assertSee(Lang::get('notifications::settings.background_note'));
});

// The other half of the same rule. An install that never enabled encryption has
// a scheduler that writes these fine, so telling that reader they only arrive on
// open would name a limit they do not have.
it('says nothing of the kind on an install whose columns are plaintext by design', function (): void {
    $user = srwUser('srw-plain');
    $this->actingAs($user);

    Livewire::test(NotificationsSettingsSection::class)
        ->assertSet('preparedOnlyWhileOpen', false)
        ->assertDontSee(Lang::get('notifications::settings.background_note'));
});
