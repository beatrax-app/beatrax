<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

// "Beatrax updates itself automatically once installed" is the desktop's
// electron-updater chain. All three listeners behind it —
// VerifyAndAnnounceUpdate, TriggerUpdateDownload and VerifyAndInstallDownload —
// open with `if (UserDataPathService::isMobileRuntime()) { return; }`, and the
// mobile composer root does not even require nativephp/desktop. On a phone the
// stores own that path, and the section said otherwise.

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');

    $this->reader = User::create([
        'username' => 'about-updates',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->reader);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('keeps the self-updating promise on the desktop, where the updater chain runs', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSet('onPhone', false)
        ->assertSee('Beatrax updates itself automatically once installed');
});

it('names the store that updates a phone, instead of an in-app banner it never shows', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(SettingsPage::class)
        ->assertSet('onPhone', true)
        ->assertDontSee('Beatrax updates itself automatically once installed')
        ->assertDontSee('in-app banner')
        ->assertSee('App Store or Google Play');
});

it('still offers the releases page on a phone, which is the one thing that does work there', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');

    Livewire::test(SettingsPage::class)
        ->assertSee('Open releases page');
});
