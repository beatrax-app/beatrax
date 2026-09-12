<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
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
    // the one state in which its help text can be read at all — and a self row
    // alone is not that state: without the key-file beside it the section reads
    // as a restored database, which it is.
    foreach ((array) glob(UserDataPathService::appPath(sprintf('sync/identity/%s.enc*', $this->reader->id))) as $stale) {
        @unlink((string) $stale);
    }

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->reader->id, $session);
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
