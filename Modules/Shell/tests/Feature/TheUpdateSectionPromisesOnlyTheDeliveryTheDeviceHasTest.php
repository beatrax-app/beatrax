<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\UpdateCheckSettingsSection;
use Native\Desktop\Contracts\Shell as ShellContract;
use Native\Desktop\Fakes\ShellFake;

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
    Livewire::test(UpdateCheckSettingsSection::class)
        ->assertSet('onPhone', false)
        ->assertSee('Beatrax updates itself automatically once installed');
});

it('names the store that updates a phone, instead of an in-app banner it never shows', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(UpdateCheckSettingsSection::class)
        ->assertSet('onPhone', true)
        ->assertDontSee('Beatrax updates itself automatically once installed')
        ->assertDontSee('in-app banner')
        ->assertSee('App Store or Google Play');
});

// This case used to assert the opposite, on the reasoning that opening the
// releases page is the one update affordance that works on a phone. It is also
// where the installers are, so on a store build it is an in-app route to an
// out-of-store binary — and a sentence naming the store above a control that
// bypasses it is precisely the shape a switched sentence is not allowed to
// excuse.
it('offers no route to the releases page on a phone, where that page is where the installers are', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');

    Livewire::test(UpdateCheckSettingsSection::class)
        ->assertDontSee('Open releases page');
});

// The button is gone from the markup; the method it called is still an
// addressable endpoint, and the view is not what decides who may reach it.
it('opens nothing when the releases endpoint is called on a phone anyway', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    $shell = new ShellFake;
    $this->app->instance(ShellContract::class, $shell);

    Livewire::test(UpdateCheckSettingsSection::class)->call('openReleasesPage');

    expect($shell->openExternalCalls)->toBe([]);
});

// The section read `MobilePlatform::tryFrom()`, so a shell NativePHP names and
// the enum does not model answered "desktop": the self-update copy, a live
// switch, and a link to the page the installers are on. The three listeners it
// cites have always asked the broader question.
it('treats a shell the enum does not model as the store build it is', function (): void {
    putenv('NATIVEPHP_PLATFORM=ipados');

    $shell = new ShellFake;
    $this->app->instance(ShellContract::class, $shell);

    Livewire::test(UpdateCheckSettingsSection::class)
        ->assertSet('onPhone', true)
        ->assertDontSee('Beatrax updates itself automatically once installed')
        ->assertDontSee('Check for updates automatically')
        ->assertDontSee('Open releases page')
        ->assertSee('App Store or Google Play')
        ->call('openReleasesPage')
        ->call('toggle')
        ->assertSet('enabled', true);

    expect($shell->openExternalCalls)->toBe([])
        ->and($this->reader->fresh()->auto_update_check_enabled)->not->toBeFalse();
});

it('does not write an update preference the phone has no updater for', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(UpdateCheckSettingsSection::class)
        ->call('toggle')
        ->assertSet('enabled', true);

    expect($this->reader->fresh()->auto_update_check_enabled)->not->toBeFalse();
});

it('offers no update switch on a phone, where it would govern nothing', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');

    Livewire::test(UpdateCheckSettingsSection::class)
        ->assertDontSee('Check for updates automatically');
});

// The same promise read the other way: with the check off nothing arrives by
// itself, so the sentence saying it does must go with it rather than sit above
// a switch that contradicts it.
it('drops the self-updating promise once the reader switches the check off', function (): void {
    Livewire::test(UpdateCheckSettingsSection::class)
        ->call('toggle')
        ->assertDontSee('Beatrax updates itself automatically once installed')
        ->assertSee('No update check is made');
});
