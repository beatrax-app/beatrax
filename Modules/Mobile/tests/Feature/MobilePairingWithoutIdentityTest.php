<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\RelayBootstrap;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

/*
 * The phone that has NEVER minted an identity — which is every phone that did
 * not arrive through the /mobile/import bootstrap, that being the only other
 * caller that mints one. The existing scan tests all call
 * generateAndPersist() for the phone first, so none of them could see this.
 *
 * Measured on a Galaxy S24 before the fix: sync/ held only relay.json, with
 * no identity/ directory, and device_registry, sync_encryption_state and
 * sync_sessions all empty. Every scan of a valid desktop QR answered "deze
 * code is ongeldig of verlopen" — sending the user to fix the one device that
 * was working.
 */

const MPWI_DESKTOP_USER_ID = 70051;

function mpwiUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  Closure(string, Closure): mixed  $asDevice  The harness's own
 *                                                     device switch, passed
 *                                                     in because it is
 *                                                     protected on the test.
 * @return array{payload: string, token: string}
 */
function mpwiDesktopQr(Closure $asDevice, Session $session): array
{
    $desktopIdentity = $asDevice('desktop', fn () => app(DeviceIdentityService::class)
        ->generateAndPersist(MPWI_DESKTOP_USER_ID, $session));

    $token = $asDevice('desktop', fn () => app(PairingTokenService::class)->issue(
        MPWI_DESKTOP_USER_ID,
        $desktopIdentity->deviceId,
        $desktopIdentity->ed25519PublicKeyHex,
        $desktopIdentity->x25519PublicKeyHex,
    ));

    return [
        'payload' => app(QrPayloadBuilder::class)->buildUri(
            $desktopIdentity->deviceId,
            $desktopIdentity->ed25519PublicKeyHex,
            $desktopIdentity->x25519PublicKeyHex,
            $token,
            'Wessel MacBook',
            new RelayBootstrap('https://relay.test', 'cross-device-harness-relay-secret'),
        ),
        'token' => $token,
    ];
}

afterEach(function (): void {
    $this->crossDevicePairingTearDown();
});

it('mints the responder identity on a phone that never had one, instead of rejecting the code', function (): void {
    $this->crossDevicePairingSetUp();

    $user = mpwiUser('mpwi-fresh-phone');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));

    // The whole point: the phone has NO identity, exactly as on the device.
    expect(app(PairingGateway::class)->hasIdentityFile((int) $user->id))->toBeFalse();

    $qr = mpwiDesktopQr(fn (string $d, Closure $fn) => $this->asDevice($d, $fn), $session);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    Livewire::test(MobilePairingScan::class)
        ->call('submitCode', $qr['payload'])
        ->assertSet('flashMessage', '')
        ->assertSet('step', 'confirm');

    expect(app(PairingGateway::class)->hasIdentityFile((int) $user->id))->toBeTrue();
});

it('does not regenerate the identity of a phone that already has one', function (): void {
    $this->crossDevicePairingSetUp();

    $user = mpwiUser('mpwi-existing-identity');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));

    $original = app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);
    $path = UserDataPathService::appPath("sync/identity/{$user->id}.enc");
    $before = (string) file_get_contents($path);

    $qr = mpwiDesktopQr(fn (string $d, Closure $fn) => $this->asDevice($d, $fn), $session);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    Livewire::test(MobilePairingScan::class)
        ->call('submitCode', $qr['payload'])
        ->assertSet('step', 'confirm');

    // Same bytes, same device id — a re-mint here would orphan every pairing
    // this device already had.
    expect((string) file_get_contents($path))->toBe($before)
        ->and(app(PairingGateway::class)->currentDeviceId((int) $user->id, $session))
        ->toBe($original->deviceId);
});

it('says the device is locked rather than blaming the code, when the identity cannot be opened', function (): void {
    $this->crossDevicePairingSetUp();

    $user = mpwiUser('mpwi-locked-phone');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);

    // A real device can only mint an identity while an app lock is provisioned
    // — the identity is sealed under that lock's key — so the fixture has to
    // have one, or the branch under test is not the one that runs.
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'fixture', $session);

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);
    $qr = mpwiDesktopQr(fn (string $d, Closure $fn) => $this->asDevice($d, $fn), $session);

    // Losing the KEK is what a locked app looks like from here.
    (new LockStateManager)->lock($session);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    Livewire::test(MobilePairingScan::class)
        ->call('submitCode', $qr['payload'])
        ->assertRedirect(route('mobile.lock'));

    // Flashed rather than set on the component: the redirect is a full page
    // load into the lock screen, so a public property of the screen being
    // left behind arrives nowhere and the PIN pad explains nothing.
    expect($session->get(MobilePairingScan::LOCKED_IDENTITY_FLASH))
        ->toBe(Lang::get('mobile::pairing.errors.identity_locked'));
});

it('pairs from the plain scan screen too, the only sync entry point a phone has', function (): void {
    $this->crossDevicePairingSetUp();

    $user = mpwiUser('mpwi-plain-scan');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));

    $qr = mpwiDesktopQr(fn (string $d, Closure $fn) => $this->asDevice($d, $fn), $session);

    // No ?mode=import — this is what /data-devices renders on a phone.
    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));

    Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', false)
        ->call('submitCode', $qr['payload'])
        ->assertSet('flashMessage', '')
        ->assertSet('step', 'confirm');
});

it('does not send a device with no app lock to a PIN pad it cannot pass', function (): void {
    $this->crossDevicePairingSetUp();

    $user = mpwiUser('mpwi-no-lock');
    test()->actingAs($user);

    // No app lock was ever provisioned, so there is no key to seal an identity
    // with and no PIN to unlock with. The identity mint throws, and redirecting
    // to the lock screen would strand the reader on a pad with nothing to enter.
    Livewire::test(MobilePairingScan::class)
        ->call('submitCode', null)
        ->assertNoRedirect()
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.identity_needs_lock'));
});
