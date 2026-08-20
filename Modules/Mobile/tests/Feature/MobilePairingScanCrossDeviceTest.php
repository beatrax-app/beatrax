<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\RelayBootstrap;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

/*
 * MobilePairingScanCrossDeviceTest — Phase 15 HIGH-01 (Task 6, C5 phone
 * wiring). Drives the ACTING (phone) side through the real MobilePairingScan
 * Livewire component — submitCode() propagates PAIR_RESPONDER_ACCEPT,
 * confirmMatch()/checkPairingState() propagate/apply PAIR_CONFIRM — against
 * a genuinely separate DESKTOP database (driven directly via
 * PairingTokenService/PairingGateway, mirroring PairingFlowModalCrossDeviceTest's
 * desktop half). The acting Livewire user IS "the phone" — its identity/
 * pairing rows live on the app's normal default test connection, itself a
 * genuinely separate SQLite database from the harness's `desktop`
 * connection.
 *
 * Also covers plan case 8: the full happy path THROUGH to epoch delivery —
 * the "truth #1 the review said was unreachable on real hardware."
 */

function mpsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

const MPS_DESKTOP_USER_ID = 70001;

afterEach(function (): void {
    $this->crossDevicePairingTearDown();
});

it('submitCode() import branch sends PAIR_RESPONDER_ACCEPT to the desktop\'s own separate database', function (): void {
    $this->crossDevicePairingSetUp();

    $user = mpsUser('mps-submit-relay');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $desktopIdentity = $this->asDevice('desktop', fn () => app(DeviceIdentityService::class)->generateAndPersist(MPS_DESKTOP_USER_ID, $session));
    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)->issue(
        MPS_DESKTOP_USER_ID,
        $desktopIdentity->deviceId,
        $desktopIdentity->ed25519PublicKeyHex,
        $desktopIdentity->x25519PublicKeyHex,
    ));

    $qrPayload = app(QrPayloadBuilder::class)->buildUri(
        $desktopIdentity->deviceId,
        $desktopIdentity->ed25519PublicKeyHex,
        $desktopIdentity->x25519PublicKeyHex,
        $issuedToken,
        relay: new RelayBootstrap('https://relay.test', 'cross-device-harness-relay-secret'),
    );

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', true)
        ->call('submitCode', $qrPayload)
        ->assertSet('step', 'confirm')
        ->assertSet('flashMessage', '');

    // DESKTOP drains its own mailbox — applies the phone's responder-accept.
    $this->asDevice('desktop', function (): void {
        app(PairingGateway::class)->drainPairingFrames(MPS_DESKTOP_USER_ID);
    });

    $this->asDevice('desktop', function () use ($issuedToken): void {
        $row = app(DatabaseManager::class)->connection()->table('pairing_tokens')
            ->where('token_hash', hash('sha256', $issuedToken))
            ->first();
        expect($row->state)->toBe(PairingState::AwaitingConfirm->value);
    });
});

it('the full happy path reaches CONFIRMED on both databases AND epoch delivery — the phone keyring converges (case 8)', function (): void {
    $this->crossDevicePairingSetUp();

    $user = mpsUser('mps-full-happy-path');
    test()->actingAs($user);

    // Cross-test filesystem-isolation gap (documented in GdkEpochDeliveryTest
    // / MobilePairingScanTest): SQLite rowids can be reused across
    // RefreshDatabase transaction rollbacks within one process, so a prior
    // test's on-disk GDK keyring file may already exist for this same
    // reused numeric user id. Start from a genuinely empty on-disk state so
    // the epoch-1 idempotency guard (MEDIUM-02) cannot silently drop the
    // desktop's delivered wrap as an already-present epoch_id.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));
    @unlink(UserDataPathService::appPath('sync/gdk/'.MPS_DESKTOP_USER_ID.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));
    $phoneIdentity = app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    // DESKTOP: real identity + encryption ALREADY enabled (epoch 1) —
    // mirrors a desktop that has been using encrypted sync before this
    // phone ever existed.
    $desktopIdentity = $this->asDevice('desktop', fn () => app(DeviceIdentityService::class)->generateAndPersist(MPS_DESKTOP_USER_ID, $session));
    $this->asDevice('desktop', fn () => app(GdkKeyringService::class)->generateAndPersist(MPS_DESKTOP_USER_ID, $session));
    $desktopEpoch = $this->asDevice('desktop', fn () => app(GdkKeyringService::class)->currentEpoch(MPS_DESKTOP_USER_ID, $session));
    $desktopEpochKeyHex = $desktopEpoch->keyHex;

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)->issue(
        MPS_DESKTOP_USER_ID,
        $desktopIdentity->deviceId,
        $desktopIdentity->ed25519PublicKeyHex,
        $desktopIdentity->x25519PublicKeyHex,
    ));

    $qrPayload = app(QrPayloadBuilder::class)->buildUri(
        $desktopIdentity->deviceId,
        $desktopIdentity->ed25519PublicKeyHex,
        $desktopIdentity->x25519PublicKeyHex,
        $issuedToken,
        relay: new RelayBootstrap('https://relay.test', 'cross-device-harness-relay-secret'),
    );

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    // PHONE scans and accepts — sends PAIR_RESPONDER_ACCEPT.
    $component = Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', true)
        ->call('submitCode', $qrPayload)
        ->assertSet('step', 'confirm');

    // DESKTOP drains, advances to confirm, and its human confirms — sends
    // its own signed PAIR_CONFIRM to the phone.
    $this->asDevice('desktop', function (): void {
        app(PairingGateway::class)->drainPairingFrames(MPS_DESKTOP_USER_ID);
    });
    $this->asDevice('desktop', function () use ($issuedToken, $desktopIdentity, $phoneIdentity, $session): void {
        $row = app(DatabaseManager::class)->connection()->table('pairing_tokens')
            ->where('token_hash', hash('sha256', $issuedToken))
            ->first();
        $state = app(PairingTokenService::class)->confirm((int) $row->id, MPS_DESKTOP_USER_ID, $desktopIdentity->deviceId);
        expect($state)->toBe(PairingState::AwaitingConfirm->value);

        app(PairingGateway::class)->sendConfirm(MPS_DESKTOP_USER_ID, (int) $row->id, $phoneIdentity->deviceId, $session);
    });

    // PHONE's human confirms — confirmMatch() drains-independent send of its
    // own PAIR_CONFIRM to the desktop; checkPairingState() will drain the
    // desktop's already-sent PAIR_CONFIRM (delivered above) and reach
    // CONFIRMED.
    $component->call('confirmMatch')->assertSet('awaitingPeer', true);
    $component->call('checkPairingState')->assertSet('step', 'success');

    // PHONE admits the desktop; import mode deferred self-mint — no
    // sync_encryption_state row of its own yet.
    expect(app(DeviceRegistryService::class)->deviceKeys((int) $user->id))->toHaveKey($desktopIdentity->deviceId);
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->exists())->toBeFalse();

    // DESKTOP drains the phone's PAIR_CONFIRM too — admits the phone on
    // ITS OWN database.
    $this->asDevice('desktop', function (): void {
        app(PairingGateway::class)->drainPairingFrames(MPS_DESKTOP_USER_ID);
    });
    $this->asDevice('desktop', function () use ($phoneIdentity): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(MPS_DESKTOP_USER_ID))->toHaveKey($phoneIdentity->deviceId);
    });

    // --- Case 8: epoch delivery. The desktop fans out every keyring epoch
    // to the newly-confirmed phone (existing, UNCHANGED Phase 14/15
    // machinery — GdkRotationService::fanOutAllEpochsToDevice(), reached in
    // production from PairingFlowModal::fanOutToNewlyConfirmedDevice()).
    // This writes into the DESKTOP's OWN relay_mailbox (that primitive is
    // untouched by this plan — GDK epoch transport is Phase 14/15 scope,
    // not the pairing-handshake propagation this plan adds). The bridge
    // step below mirrors GdkEpochDeliveryTest's own established technique
    // for simulating "the wrap reached the other device via some
    // transport" without inventing a new cross-device epoch-wrap relay
    // client in this plan.
    $wraps = $this->asDevice('desktop', function () use ($phoneIdentity, $session) {
        $db = app(DatabaseManager::class);
        $deviceRegistryId = $db->connection()->table('device_registry')
            ->where('user_id', MPS_DESKTOP_USER_ID)
            ->where('device_id', $phoneIdentity->deviceId)
            ->value('id');

        app(GdkRotationService::class)->fanOutAllEpochsToDevice(MPS_DESKTOP_USER_ID, (int) $deviceRegistryId, $session);

        return $db->connection()->table('relay_mailbox')
            ->where('recipient_did', $phoneIdentity->deviceId)
            ->get();
    });

    expect($wraps)->toHaveCount(1, 'exactly one epoch (epoch 1) must be fanned out');

    // The phone's OWN inbound relay_mailbox receives the SAME opaque blob.
    foreach ($wraps as $wrap) {
        $db->connection()->table('relay_mailbox')->insert([
            'sender_did' => $wrap->sender_did,
            'recipient_did' => $wrap->recipient_did,
            'blob' => $wrap->blob,
            'created_at' => $wrap->created_at,
            'delivered_at' => null,
            'expires_at' => $wrap->expires_at,
        ]);
    }

    // PHONE's own daemon drains ITS OWN inbound mailbox and routes each
    // wrap through GdkEpochControlHandler, converging its local keyring
    // under its own KEK (mirrors GdkEpochDeliveryTest's receive-side).
    $pending = $db->connection()->table('relay_mailbox')
        ->where('recipient_did', $phoneIdentity->deviceId)
        ->whereNull('delivered_at')
        ->get();

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    foreach ($pending as $row) {
        $handler->handle($row->blob, (int) $user->id, $session);
        $db->connection()->table('relay_mailbox')->where('id', $row->id)->update(['delivered_at' => '2026-07-14T10:00:00Z']);
    }

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->epochs())->not->toBe([], 'the phone keyring must converge from the desktop\'s delivered epoch (this plan\'s truth #1)');
    // Under the desktop's OWN epoch id: ids are minted, so the phone adopts
    // the number the desktop minted rather than starting its own count at 1.
    expect($loaded->keyFor($desktopEpoch->epochId))
        ->toBe($desktopEpochKeyHex, 'the delivered epoch key must match the desktop\'s real key');
});
