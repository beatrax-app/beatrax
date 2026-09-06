<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\UpdateChannel;
use Modules\Core\Public\Http\Livewire\UpdateChannelSettingsSection;

// Both channels existed in the fetcher and neither could be reached from the
// app: the answer was an environment variable baked into the bundle's own .env,
// so opting into preview meant building your own bundle. These hold the control
// that replaced it — and hold it closed on a store build, where the stores own
// the update path and this application must not present one at all.

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');

    $this->reader = User::create([
        'username' => 'channel-chooser',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->reader);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('opens on stable, which is what a reader who has chosen nothing gets', function (): void {
    Livewire::test(UpdateChannelSettingsSection::class)
        ->assertSet('onPhone', false)
        ->assertSet('channel', UpdateChannel::Stable->value)
        ->assertSee('Update channel')
        ->assertDontSee('Preview builds are tested less');
});

it('stores the reader\'s choice, so a later mount opens on it', function (): void {
    Livewire::test(UpdateChannelSettingsSection::class)
        ->set('channel', UpdateChannel::Preview->value)
        ->call('choose')
        ->assertSee('Preview builds are tested less');

    expect($this->reader->fresh()->update_channel)->toBe(UpdateChannel::Preview->value);

    Livewire::test(UpdateChannelSettingsSection::class)
        ->assertSet('channel', UpdateChannel::Preview->value);
});

// A bound property is whatever the wire sends it, and a channel this bundle has
// never published is a manifest name nothing answers. Refused at the write.
it('refuses a channel no release publishes and puts the control back', function (): void {
    Livewire::test(UpdateChannelSettingsSection::class)
        ->set('channel', 'nightly')
        ->call('choose')
        ->assertSet('channel', UpdateChannel::Stable->value);

    expect($this->reader->fresh()->update_channel)->toBe(UpdateChannel::Stable->value);
});

it('draws no channel control on a store build, where there is no update path to aim', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(UpdateChannelSettingsSection::class)
        ->assertSet('onPhone', true)
        ->assertDontSee('Update channel')
        ->assertDontSee('Preview builds are tested less');
});

// The control is gone from the markup; the method it called is still an
// addressable endpoint, and the view is not what decides who may reach it.
it('writes nothing when the channel endpoint is called on a store build anyway', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');

    Livewire::test(UpdateChannelSettingsSection::class)
        ->set('channel', UpdateChannel::Preview->value)
        ->call('choose')
        ->assertSet('channel', UpdateChannel::Stable->value);

    expect($this->reader->fresh()->update_channel)->toBe(UpdateChannel::Stable->value);
});

// A shell NativePHP names and MobilePlatform does not model is still a store
// build; the section beside this one was handed the desktop copy by asking the
// narrower question, and this one asks the same broad one it settled on.
it('treats a shell the enum does not model as the store build it is', function (): void {
    putenv('NATIVEPHP_PLATFORM=ipados');

    Livewire::test(UpdateChannelSettingsSection::class)
        ->assertSet('onPhone', true)
        ->assertDontSee('Update channel');
});
