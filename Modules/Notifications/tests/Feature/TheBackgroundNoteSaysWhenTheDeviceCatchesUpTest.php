<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// The note was gated on encryption alone, so one sentence covered two very
// different waits. RunDeferredNotificationPasses is web middleware on both
// roots: a desktop reader is already inside the app that replays the pass, and
// telling them to open it names a step they are past.

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');

    $this->reader = User::query()->create([
        'username' => 'background-note-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->enablesEncryptionForUser($this->reader);
    $this->actingAs($this->reader);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('tells a desktop reader the catch-up happens as they go on using the open app', function (): void {
    Livewire::test(NotificationsSettingsSection::class)
        ->assertSet('preparedOnlyWhileOpen', true)
        ->assertSet('onPhone', false)
        ->assertSee('as you carry on using the app')
        ->assertDontSee('the next time you open the app');
});

it('tells a phone reader they arrive on the next open, which is the wait they have', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');

    Livewire::test(NotificationsSettingsSection::class)
        ->assertSet('onPhone', true)
        ->assertSee('the next time you open the app')
        ->assertDontSee('as you carry on using the app');
});

it('says neither on an install whose columns are plaintext, on either platform', function (): void {
    $plain = User::query()->create([
        'username' => 'background-note-plaintext',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($plain);

    foreach ([null, 'ios'] as $platform) {
        $platform === null ? putenv('NATIVEPHP_PLATFORM') : putenv('NATIVEPHP_PLATFORM='.$platform);

        Livewire::test(NotificationsSettingsSection::class)
            ->assertSet('preparedOnlyWhileOpen', false)
            ->assertDontSee('as you carry on using the app')
            ->assertDontSee('the next time you open the app');
    }
});
