<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
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
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

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

// The acting Livewire user is the phone: its identity and pairing rows live on
// the default test connection, a genuinely separate SQLite database from the
// harness's `desktop` connection, which is driven straight through
// PairingTokenService and PairingGateway instead.

it('submitCode() import branch sends PAIR_RESPONDER_ACCEPT to the desktop\'s own separate database', function (): void {
    $this->crossDevicePairingSetUp();

    $user = mpsUser('mps-submit-relay');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
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

    // The desktop drains its own mailbox and applies the phone's responder-accept.
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

    // SQLite rowids get reused across RefreshDatabase rollbacks inside one process,
    // so a prior test's on-disk GDK keyring can already exist for this same numeric
    // user id. Left there, the epoch-1 idempotency guard silently drops the
    // desktop's delivered wrap as an epoch that is already present.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));
    @unlink(UserDataPathService::appPath('sync/gdk/'.MPS_DESKTOP_USER_ID.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    $phoneIdentity = app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    // A desktop that has been using encrypted sync since before this phone existed.
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

    // The phone scans and accepts, which sends PAIR_RESPONDER_ACCEPT.
    $component = Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', true)
        ->call('submitCode', $qrPayload)
        ->assertSet('step', 'confirm');

    // The desktop drains, its human confirms, and it sends its own signed
    // PAIR_CONFIRM back to the phone.
    $this->asDevice('desktop', function (): void {
        app(PairingGateway::class)->drainPairingFrames(MPS_DESKTOP_USER_ID);
    });
    $this->asDevice('desktop', function () use ($issuedToken, $desktopIdentity, $phoneIdentity, $session): void {
        $row = app(DatabaseManager::class)->connection()->table('pairing_tokens')
            ->where('token_hash', hash('sha256', $issuedToken))
            ->first();
        $state = app(PairingTokenService::class)->confirm((int) $row->id, MPS_DESKTOP_USER_ID, $desktopIdentity->deviceId, PairingSafetyDigest::forToken((int) $row->id, MPS_DESKTOP_USER_ID));
        expect($state)->toBe(PairingState::AwaitingConfirm->value);

        app(PairingGateway::class)->sendConfirm(MPS_DESKTOP_USER_ID, (int) $row->id, $phoneIdentity->deviceId, $session);
    });

    // confirmMatch() sends the phone's own PAIR_CONFIRM independently of any
    // drain; checkPairingState() then drains the desktop's, delivered above, and
    // reaches CONFIRMED.
    $component->call('confirmMatch')->assertSet('awaitingPeer', true);
    $component->call('checkPairingState')->assertSet('step', 'success');

    // Import mode defers the phone's own self-mint, so it has no
    // sync_encryption_state row yet.
    expect(app(DeviceRegistryService::class)->deviceKeys((int) $user->id))->toHaveKey($desktopIdentity->deviceId);
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->exists())->toBeFalse();

    // The desktop drains the phone's PAIR_CONFIRM too, admitting it on its own
    // database.
    $this->asDevice('desktop', function (): void {
        app(PairingGateway::class)->drainPairingFrames(MPS_DESKTOP_USER_ID);
    });
    $this->asDevice('desktop', function () use ($phoneIdentity): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(MPS_DESKTOP_USER_ID))->toHaveKey($phoneIdentity->deviceId);
    });

    // fanOutAllEpochsToDevice() writes into the desktop's own relay_mailbox, so the
    // copy further down stands in for whatever transport carries the wrap to the
    // phone, rather than inventing a cross-device epoch-wrap relay client here.
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

    // The phone's own inbound relay_mailbox receives the same opaque blob.
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

    // The phone's own daemon drains its inbound mailbox and routes each wrap
    // through GdkEpochControlHandler, converging its keyring under its own KEK.
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
    // Epoch ids are minted, so the phone adopts the desktop's number rather than
    // starting its own count at 1.
    expect($loaded->keyFor($desktopEpoch->epochId))
        ->toBe($desktopEpochKeyHex, 'the delivered epoch key must match the desktop\'s real key');
});
