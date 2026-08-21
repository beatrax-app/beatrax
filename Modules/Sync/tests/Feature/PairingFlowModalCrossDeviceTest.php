<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

// The acting Livewire user is the desktop, and its rows live on the default
// connection — itself a genuinely separate database from the harness's phone
// connection, so the Livewire calls need no device wrapping.

function pfmUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

const PFM_PHONE_USER_ID = 90210;

afterEach(function (): void {
    $this->crossDevicePairingTearDown();
});

it('showMyCode() embeds the configured relay endpoint into the QR — a different relay config yields a different QR', function (): void {
    $this->crossDevicePairingSetUp();

    $user = pfmUser('pfm-qr-relay');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    // The harness configures a relay, so this first call clears it again.
    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl(null);

    $withoutRelay = Livewire::test(PairingFlowModal::class)->call('showMyCode')->get('qrSvg');

    // A configured relay changes the encoded URI, and therefore the QR bit
    // pattern, deterministically.
    $relayConfig->setEndpointUrl('https://relay.example.com');
    $relayConfig->setAuthToken('shared-secret');

    $withRelay = Livewire::test(PairingFlowModal::class)->call('showMyCode')->get('qrSvg');

    expect($withRelay)->not->toBe($withoutRelay);
});

it('checkPairingState() drains the phone\'s frames and confirmMatch() sends this desktop\'s own signed PAIR_CONFIRM, reaching CONFIRMED symmetrically', function (): void {
    $this->crossDevicePairingSetUp();

    $user = pfmUser('pfm-crossdevice-confirm');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    $desktopIdentity = app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $component = Livewire::test(PairingFlowModal::class)->call('showMyCode');
    $pairingTokenId = (int) $component->get('pairingTokenId');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $tokenRow = $db->connection()->table('pairing_tokens')->where('id', $pairingTokenId)->first();
    $tokenHash = (string) $tokenRow->token_hash;

    // The QR's token param is this same raw hex, so recovering it from the word
    // code mirrors what a real scan hands over.
    $plainToken = app(WordCodeEncoder::class)->decode($component->get('wordCode'));

    $phoneIdentity = $this->asDevice('phone', function () use ($session) {
        return app(DeviceIdentityService::class)->generateAndPersist(PFM_PHONE_USER_ID, $session);
    });

    $this->asDevice('phone', function () use ($desktopIdentity, $phoneIdentity, $plainToken, $tokenHash, $session): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(PFM_PHONE_USER_ID, $desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex, $plainToken);
        $service->accept($plainToken, PFM_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        app(PairingGateway::class)->sendResponderAccept(PFM_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    $component->call('checkPairingState')->assertSet('step', 'confirm');

    // This side is not both-confirmed yet, but sends its own signed confirm
    // regardless.
    $component->call('confirmMatch')->assertSet('awaitingPeer', true);

    $this->asDevice('phone', function () use ($tokenHash, $phoneIdentity, $desktopIdentity, $session): void {
        $row = app(DatabaseManager::class)->connection()->table('pairing_tokens')->where('token_hash', $tokenHash)->first();
        $state = app(PairingTokenService::class)->confirm((int) $row->id, PFM_PHONE_USER_ID, $phoneIdentity->deviceId);
        expect($state)->toBe(PairingState::AwaitingConfirm->value);

        app(PairingGateway::class)->sendConfirm(PFM_PHONE_USER_ID, (int) $row->id, $desktopIdentity->deviceId, $session);
    });

    // The desktop's own side was already confirmed above, so the phone's frame
    // applies immediately rather than being deferred.
    $component->call('checkPairingState')->assertSet('step', 'success');

    expect(app(DeviceRegistryService::class)->deviceKeys((int) $user->id))->toHaveKey($phoneIdentity->deviceId);

    $this->asDevice('phone', function (): void {
        app(PairingGateway::class)->drainPairingFrames(PFM_PHONE_USER_ID);
    });
    $this->asDevice('phone', function () use ($desktopIdentity): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(PFM_PHONE_USER_ID))->toHaveKey($desktopIdentity->deviceId);
    });
});

it('confirmMatch() never dead-ends when no relay is configured — the ordinary LAN-direct pairing case is unaffected', function (): void {
    // No relay configured at all: the LAN-only path every other modal test
    // exercises.
    $user = pfmUser('pfm-no-relay');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $component = Livewire::test(PairingFlowModal::class)->call('showMyCode');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $token = app(PairingTokenService::class)->accept(
        app(WordCodeEncoder::class)->decode($component->get('wordCode')),
        (int) $user->id,
        'lan-responder',
        str_repeat('c', 64),
        str_repeat('d', 64),
    );
    expect($token)->not->toBeFalse();

    // With no relay the confirm send is a silent no-op, never an exception.
    $component->call('confirmMatch')->assertSet('awaitingPeer', true);
});
