<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;

uses(RefreshDatabase::class);

// "offline devices sync via this relay" promised two things at once, and the
// relay does neither. It carries pairing frames and GDK epoch wraps; op-log
// frames only ever cross the Noise socket. And on a phone there is no
// unattended pull at all: MobileBackgroundSchedule::impossibleOnDevice() names
// mobile.sync-pull as work no device schedule can complete, so the only caller
// of the burst outside setup is the Sync now tap on this very screen.

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');

    $this->reader = User::query()->create([
        'username' => 'relay-help-reader',
        'password' => bcrypt('relay-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->reader);

    // The relay field only renders once this device is a sync peer, which is
    // the one state in which its help text can be read at all.
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $this->reader->id,
        'device_id' => 'relay-help-self',
        'name' => 'This device',
        'ed25519_public_key_hex' => str_repeat('ab', 32),
        'x25519_public_key_hex' => str_repeat('cd', 32),
        'safety_number_words' => '',
        'is_self' => 1,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:00:00Z',
        'last_seen_at' => '2026-08-01T10:00:00Z',
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:00:00Z',
    ]);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('has no background sync to describe on a device, by the scheduler declaration itself', function (): void {
    expect(MobileBackgroundSchedule::impossibleOnDevice())->toHaveKey('mobile.sync-pull');
});

it('never offers the relay as a road a transaction can take', function (): void {
    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('onPhone', false)
        ->assertDontSee('offline devices sync via this relay')
        ->assertSee('complete pairing and exchange encryption keys')
        ->assertSee('Transactions themselves still sync only when both devices are on the same network');
});

it('tells a phone reader the relay still waits on a sync they start', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('onPhone', true)
        ->assertSee('Transactions themselves still sync only when both devices are on the same network')
        ->assertSee('when you sync from this screen');
});

it('says the same thing about transactions as the sentence one screen along', function (): void {
    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSee('when both devices are on the same network');

    expect(Lang::get('mobile::sync.result.unreachable'))->toContain('same network');
});
