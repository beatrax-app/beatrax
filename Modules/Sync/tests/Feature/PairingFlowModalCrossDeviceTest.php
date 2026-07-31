<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingStateMachine;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

/*
 * PairingFlowModalCrossDeviceTest — Phase 15 HIGH-01 (Task 5, C4 desktop
 * wiring). Drives the ACTING (desktop) side through the real
 * PairingFlowModal Livewire component — showMyCode() embeds the relay
 * config into the QR, checkPairingState() drains + applies inbound
 * cross-device frames, confirmMatch() sends this device's own signed
 * PAIR_CONFIRM — against a genuinely separate PHONE database (driven
 * directly via PairingTokenService/PairingGateway, mirroring what
 * MobilePairingScan will do once its own C5 wiring lands).
 *
 * The acting Livewire user IS "the desktop" here — its identity/pairing
 * rows live on the app's normal default test connection (itself a
 * genuinely separate SQLite database from the harness's `phone`
 * connection), so no `asDevice('desktop', ...)` wrapping is needed for the
 * Livewire calls.
 */

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

    // Baseline: no relay configured (harness sets one up in
    // crossDevicePairingSetUp(); clear it for this first call).
    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl(null);

    $withoutRelay = Livewire::test(PairingFlowModal::class)->call('showMyCode')->get('qrSvg');

    // Configure a relay, then regenerate — the QR-encoded URI now differs
    // (it carries &relay=...), which deterministically changes the
    // rendered QR bit pattern, hence the SVG.
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

    // DESKTOP shows its code.
    $component = Livewire::test(PairingFlowModal::class)->call('showMyCode');
    $pairingTokenId = (int) $component->get('pairingTokenId');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $tokenRow = $db->connection()->table('pairing_tokens')->where('id', $pairingTokenId)->first();
    $tokenHash = (string) $tokenRow->token_hash;

    // Recover the raw plaintext token from the SAME word-code the QR itself
    // encodes (the QR's `token` param IS this raw hex, unencoded) — mirrors
    // how a real scanned QR hands the raw token to seedFromInitiator().
    $plainToken = app(WordCodeEncoder::class)->decode($component->get('wordCode'));

    // PHONE (a genuinely separate database + identity) "scans" the code:
    // seeds + accepts, then sends PAIR_RESPONDER_ACCEPT over the relay.
    $phoneIdentity = $this->asDevice('phone', function () use ($session) {
        return app(DeviceIdentityService::class)->generateAndPersist(PFM_PHONE_USER_ID, $session);
    });

    $this->asDevice('phone', function () use ($desktopIdentity, $phoneIdentity, $plainToken, $tokenHash, $session): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(PFM_PHONE_USER_ID, $desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex, $plainToken);
        $service->accept($plainToken, PFM_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        app(PairingGateway::class)->sendResponderAccept(PFM_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    // DESKTOP polls — drains the responder-accept, advances show_code -> confirm.
    $component->call('checkPairingState')->assertSet('step', 'confirm');

    // DESKTOP's human confirms — this side is NOT yet both-confirmed
    // (awaits the peer), but its own signed PAIR_CONFIRM is sent regardless.
    $component->call('confirmMatch')->assertSet('awaitingPeer', true);

    // PHONE confirms too, and sends its own signed PAIR_CONFIRM back.
    $this->asDevice('phone', function () use ($tokenHash, $phoneIdentity, $desktopIdentity, $session): void {
        $row = app(DatabaseManager::class)->connection()->table('pairing_tokens')->where('token_hash', $tokenHash)->first();
        $state = app(PairingTokenService::class)->confirm((int) $row->id, PFM_PHONE_USER_ID, $phoneIdentity->deviceId);
        expect($state)->toBe(PairingStateMachine::AWAITING_CONFIRM);

        app(PairingGateway::class)->sendConfirm(PFM_PHONE_USER_ID, (int) $row->id, $desktopIdentity->deviceId, $session);
    });

    // DESKTOP polls again — drains the phone's PAIR_CONFIRM; the desktop's
    // OWN local side was already confirmed above, so this applies
    // immediately (no defer needed this time) and reaches CONFIRMED.
    $component->call('checkPairingState')->assertSet('step', 'success');

    // DESKTOP admits the phone.
    expect(app(DeviceRegistryService::class)->deviceKeys((int) $user->id))->toHaveKey($phoneIdentity->deviceId);

    // PHONE, on its OWN separate database, admits the desktop once it
    // drains the desktop's PAIR_CONFIRM too.
    $this->asDevice('phone', function (): void {
        app(PairingGateway::class)->drainPairingFrames(PFM_PHONE_USER_ID);
    });
    $this->asDevice('phone', function () use ($desktopIdentity): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(PFM_PHONE_USER_ID))->toHaveKey($desktopIdentity->deviceId);
    });
});

it('confirmMatch() never dead-ends when no relay is configured — the ordinary LAN-direct pairing case is unaffected', function (): void {
    // Deliberately do NOT call crossDevicePairingSetUp() — no relay
    // configured at all, mirroring the existing (pre-HIGH-01) LAN-only
    // pairing path every other PairingFlowModal test already exercises.
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

    // confirmMatch() must complete normally — no relay configured means
    // sendConfirmOverRelay() is a silent no-op, never an exception.
    $component->call('confirmMatch')->assertSet('awaitingPeer', true);
});
