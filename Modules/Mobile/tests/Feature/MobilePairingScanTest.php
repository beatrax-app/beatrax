<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

/*
 * MOBILE-01 (D-01/D-02) — QrScanBridge + MobilePairingScan (15-07-PLAN.md).
 *
 * Task 1 pins QrScanBridge's narrow envelope-unwrap-then-delegate contract:
 * a good decoded QR string reaches PairingGateway::acceptToken() (which
 * itself routes through the UNCHANGED WordCodeEncoder/PairingTokenService
 * validation boundary), a bad one yields the same generic invalid-code
 * outcome. Task 2 (this slice) proves the full MobilePairingScan wiring:
 * camera unavailable falls through to the enter_code step (this repo-root
 * toolchain never resolves Native\Mobile\Facades\Scanner — mirrors
 * BiometricUnlockBridge::isAvailable()'s identical "always false here"
 * precedent, 15-06 test file), QR-decode success and the typed word-code
 * both auto-advance to the SAME confirm step, and both funnel into the
 * IDENTICAL PairingGateway::confirm() (-> PairingTokenService::confirm())
 * both-confirm trust gate — no new trust mechanism.
 *
 * `Native\Mobile\Facades\Scanner` is installed ONLY under mobile-app/
 * vendor (Plan 03) — unreachable from this repo-root toolchain. The real
 * native camera decode is exercised only by a manual on-device UAT pass
 * (15-11), exactly like BiometricUnlockBridge's own precedent.
 */

function pairingScanTestUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('whatever-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Prime the app-lock session as unlocked (mirrors MobileBiometricUnlockTest
 * / DeviceIdentityServiceTest) and generate a real device identity for
 * $user — DeviceIdentityLoader (reached only via PairingGateway) needs a
 * genuine key-file to load.
 *
 * @return array{deviceId: string, edHex: string}
 */
function pairingScanSetUpIdentity(User $user, Session $session): array
{
    (new LockStateManager)->unlock($session, str_repeat('k', 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $dto = $identityService->generateAndPersist((int) $user->id, $session);

    return ['deviceId' => $dto->deviceId, 'edHex' => $dto->ed25519PublicKeyHex];
}

/**
 * Issue a pairing token as "the other device" (same user — pairing links a
 * user's OWN devices) and return both the plaintext token and the
 * `beatrax://pair` QR envelope a scan of that token would decode to.
 *
 * @return array{token: string, qrPayload: string, initiatorDeviceId: string}
 */
function pairingScanIssueToken(User $user): array
{
    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);

    $initiatorDeviceId = 'device-initiator';
    $initiatorEd = bin2hex(random_bytes(32));
    $initiatorKx = bin2hex(random_bytes(32));

    $token = $service->issue((int) $user->id, $initiatorDeviceId, $initiatorEd, $initiatorKx);

    /** @var QrPayloadBuilder $qrBuilder */
    $qrBuilder = app(QrPayloadBuilder::class);
    $qrPayload = $qrBuilder->buildUri($initiatorDeviceId, $initiatorEd, $initiatorKx, $token);

    return ['token' => $token, 'qrPayload' => $qrPayload, 'initiatorDeviceId' => $initiatorDeviceId];
}

// -------------------------------------------------------------------------
// Task 1: QrScanBridge — thin envelope-unwrap-then-delegate contract
// -------------------------------------------------------------------------

it('QrScanBridge isAvailable() returns false without the native facade — never fatal in tests/web', function (): void {
    $bridge = app(QrScanBridge::class);

    expect($bridge->isAvailable())->toBeFalse();
});

it('QrScanBridge routes a good decoded QR string to PairingGateway::acceptToken() — no bespoke trust logic', function (): void {
    $user = pairingScanTestUser('qr-bridge-good');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);
    $issued = pairingScanIssueToken($user);

    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    $result = $bridge->accept($issued['qrPayload'], (int) $user->id, $session);

    expect($result)->not->toBeNull();
    expect($result['pairingTokenId'])->not->toBe('');
    expect($result['safetyWords'])->toHaveCount(6);
});

it('QrScanBridge yields the same invalid-code outcome (null) for a malformed/non-beatrax decoded string', function (): void {
    $user = pairingScanTestUser('qr-bridge-bad');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    expect($bridge->accept('not-a-qr-payload-at-all', (int) $user->id, $session))->toBeNull();
    expect($bridge->accept('https://example.com/not-beatrax?token=abc', (int) $user->id, $session))->toBeNull();
    expect($bridge->accept('beatrax://pair?v=1&ed=aa&kx=bb', (int) $user->id, $session))->toBeNull(); // no token param
});

it('QrScanBridge yields null for a well-formed envelope carrying an unknown/expired token', function (): void {
    $user = pairingScanTestUser('qr-bridge-expired');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    $fakePayload = 'beatrax://pair?v=1&token='.bin2hex(random_bytes(16)).'&ed=aa&kx=bb&device=x';

    expect($bridge->accept($fakePayload, (int) $user->id, $session))->toBeNull();
});

// -------------------------------------------------------------------------
// Task 2: MobilePairingScan — camera-first landing + word-code fallback
// -------------------------------------------------------------------------

it('MobilePairingScan class exists and is Livewire-registered', function (): void {
    expect(class_exists(MobilePairingScan::class))->toBeTrue();
});

it('lands on the enter_code fallback with the amber notice when the camera is unavailable (D-02, never a dead end)', function (): void {
    $user = pairingScanTestUser('mobile-pair-no-camera');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'enter_code')
        ->assertSet('cameraUnavailableNotice', true)
        ->assertSee('Camera access is off. Enter the code from the other device instead.');
});

it('cameraDenied() falls through to the enter_code step with the amber notice', function (): void {
    $user = pairingScanTestUser('mobile-pair-denied');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    Livewire::test(MobilePairingScan::class)
        ->call('cameraDenied')
        ->assertSet('step', 'enter_code')
        ->assertSet('cameraUnavailableNotice', true);
});

it('a decoded QR string auto-advances to the confirm step (D-01) — no new confirmation screen', function (): void {
    $user = pairingScanTestUser('mobile-pair-qr-ok');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);
    $issued = pairingScanIssueToken($user);

    Livewire::test(MobilePairingScan::class)
        ->call('submitCode', $issued['qrPayload'])
        ->assertSet('step', 'confirm')
        ->assertSet('flashMessage', '');
});

it('an invalid decoded QR string surfaces the same invalid-or-expired flash as a bad word-code', function (): void {
    $user = pairingScanTestUser('mobile-pair-qr-bad');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    Livewire::test(MobilePairingScan::class)
        ->call('submitCode', 'not-a-real-qr-payload')
        ->assertSet('step', 'enter_code')
        ->assertSee('This code is invalid or has expired.');
});

it('the typed word-code fallback also auto-advances to the confirm step (D-02)', function (): void {
    $user = pairingScanTestUser('mobile-pair-wordcode-ok');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);
    $issued = pairingScanIssueToken($user);

    /** @var WordCodeEncoder $encoder */
    $encoder = app(WordCodeEncoder::class);
    $wordCode = $encoder->encode($issued['token']);

    Livewire::test(MobilePairingScan::class)
        ->set('wordCode', $wordCode)
        ->call('submitCode', null)
        ->assertSet('step', 'confirm')
        ->assertSet('flashMessage', '');
});

it('a bad typed word-code stays on enter_code with the invalid-code flash', function (): void {
    $user = pairingScanTestUser('mobile-pair-wordcode-bad');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    Livewire::test(MobilePairingScan::class)
        ->set('wordCode', 'ZZZZ-ZZZZ-ZZZZ-ZZZZ')
        ->call('submitCode', null)
        ->assertSet('step', 'enter_code')
        ->assertSee('This code is invalid or has expired.');
});

it('BOTH the QR path and the word-code path resolve to the identical PairingGateway::confirm() trust gate', function (): void {
    // ----- QR path: this device confirms, then the peer confirms, then the
    // poll (checkPairingState) observes the both-confirm CONFIRMED state. -----
    $qrUser = pairingScanTestUser('mobile-pair-confirm-qr');
    test()->actingAs($qrUser);

    /** @var Session $qrSession */
    $qrSession = app(Session::class);
    pairingScanSetUpIdentity($qrUser, $qrSession);
    $qrIssued = pairingScanIssueToken($qrUser);

    $qrComponent = Livewire::test(MobilePairingScan::class)
        ->call('submitCode', $qrIssued['qrPayload'])
        ->assertSet('step', 'confirm');

    // This device (the responder) confirms first — the gate is not yet both-
    // confirmed, so it must wait for the peer.
    $qrComponent->call('confirmMatch')
        ->assertSet('awaitingPeer', true)
        ->assertSet('step', 'confirm');

    // The peer (the initiator device) confirms via the SAME
    // PairingTokenService::confirm() gate — simulated directly here exactly
    // as PairingFlowTest does for the two-sided flow.
    /** @var PairingTokenService $tokenService */
    $tokenService = app(PairingTokenService::class);
    $pairingTokenId = (int) $qrComponent->get('pairingTokenId');
    $tokenService->confirm($pairingTokenId, (int) $qrUser->id, $qrIssued['initiatorDeviceId']);

    // The poll observes the completed both-confirm transition.
    $qrComponent->call('checkPairingState')
        ->assertSet('step', 'success');

    // ----- Word-code path: identical gate, reached via the enter_code
    // fallback instead of the camera. -----
    $wcUser = pairingScanTestUser('mobile-pair-confirm-wordcode');
    test()->actingAs($wcUser);

    /** @var Session $wcSession */
    $wcSession = app(Session::class);
    pairingScanSetUpIdentity($wcUser, $wcSession);
    $wcIssued = pairingScanIssueToken($wcUser);

    /** @var WordCodeEncoder $encoder */
    $encoder = app(WordCodeEncoder::class);
    $wordCode = $encoder->encode($wcIssued['token']);

    $wcComponent = Livewire::test(MobilePairingScan::class)
        ->set('wordCode', $wordCode)
        ->call('submitCode', null)
        ->assertSet('step', 'confirm');

    $wcComponent->call('confirmMatch')->assertSet('awaitingPeer', true);

    /** @var PairingTokenService $tokenService2 */
    $tokenService2 = app(PairingTokenService::class);
    $wcPairingTokenId = (int) $wcComponent->get('pairingTokenId');
    $tokenService2->confirm($wcPairingTokenId, (int) $wcUser->id, $wcIssued['initiatorDeviceId']);

    $wcComponent->call('checkPairingState')->assertSet('step', 'success');

    // Both flows resulted in a CONFIRMED device_registry admission — the
    // sole trust gate, reached two different ways.
    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);
    expect($gateway->tokenState($pairingTokenId, (int) $qrUser->id))->toBe(PairingGateway::STATE_CONFIRMED);
    expect($gateway->tokenState($wcPairingTokenId, (int) $wcUser->id))->toBe(PairingGateway::STATE_CONFIRMED);
});

it('GET /mobile/pair renders 200 for an authenticated user', function (): void {
    $user = pairingScanTestUser('mobile-pair-get');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    test()->get('/mobile/pair')
        ->assertOk();
});

// -------------------------------------------------------------------------
// Phase 15 import-join (Task 2) — importMode: self-mint deferral (B2, 6c)
// -------------------------------------------------------------------------

it('import mode reads ?mode=import at mount() and seeds a local token from the scanned QR identity (G1)', function (): void {
    $user = pairingScanTestUser('mobile-pair-import-seed');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    // Simulate a genuinely fresh phone: the scanned QR carries a token +
    // initiator identity that NEVER existed in this device's own local
    // pairing_tokens table (no issue() call on this device at all — the
    // token was issued on the DESKTOP's OWN separate database).
    $initiatorDeviceId = 'desktop-import-initiator';
    $initiatorEd = bin2hex(random_bytes(32));
    $initiatorKx = bin2hex(random_bytes(32));
    $token = bin2hex(random_bytes(16));

    /** @var QrPayloadBuilder $qrBuilder */
    $qrBuilder = app(QrPayloadBuilder::class);
    $qrPayload = $qrBuilder->buildUri($initiatorDeviceId, $initiatorEd, $initiatorKx, $token);

    // Set mode=import on the container-bound Request BEFORE mount() runs
    // (Livewire::test() resolves mount()'s Request param from the SAME
    // container-bound instance the app would use for a real GET request).
    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', true)
        ->call('submitCode', $qrPayload)
        ->assertSet('step', 'confirm')
        ->assertSet('flashMessage', '');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')
        ->where('user_id', $user->id)
        ->where('initiator_device_id', $initiatorDeviceId)
        ->first();

    expect($row)->not->toBeNull('seedFromInitiator() must have created a local row from the scanned QR identity');
    expect($row->initiator_seeded_at)->not->toBeNull();
    expect($row->state)->toBe('awaiting_confirm', 'accept() must have bound the responder side on top of the seeded row');
});

it('import mode defers self-mint on both-confirm — sync_encryption_state stays absent and the GDK keyring stays empty (B2)', function (): void {
    $user = pairingScanTestUser('mobile-pair-import-no-selfmint');
    test()->actingAs($user);

    // A prior, unrelated test process may have left a keyring file on disk
    // for this same reused numeric user id (SQLite rowids are reused
    // across RefreshDatabase transaction rollbacks — GdkEpochDeliveryTest's
    // own documented cross-test filesystem-isolation gap). Start from a
    // genuinely empty on-disk state.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    $initiatorDeviceId = 'desktop-import-confirm';
    $initiatorEd = bin2hex(random_bytes(32));
    $initiatorKx = bin2hex(random_bytes(32));
    $token = bin2hex(random_bytes(16));

    /** @var QrPayloadBuilder $qrBuilder */
    $qrBuilder = app(QrPayloadBuilder::class);
    $qrPayload = $qrBuilder->buildUri($initiatorDeviceId, $initiatorEd, $initiatorKx, $token);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    $component = Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', true)
        ->call('submitCode', $qrPayload)
        ->assertSet('step', 'confirm');

    // Phone (responder) confirms first — awaits the peer.
    $component->call('confirmMatch')->assertSet('awaitingPeer', true);

    // The desktop (initiator) side confirms — simulated directly here
    // exactly as CrossDevicePairingAdmissionTest / PairingFlowTest do for
    // the two-sided flow (this codebase's established single-database
    // pairing-confirm test convention).
    /** @var PairingTokenService $tokenService */
    $tokenService = app(PairingTokenService::class);
    $pairingTokenId = (int) $component->get('pairingTokenId');
    $tokenService->confirm($pairingTokenId, (int) $user->id, $initiatorDeviceId);

    // The poll observes the completed both-confirm — the SAME transition
    // that (in non-import mode) would auto-run migrate().
    $component->call('checkPairingState')->assertSet('step', 'success');

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);
    expect($gateway->tokenState($pairingTokenId, (int) $user->id))->toBe(PairingGateway::STATE_CONFIRMED);

    // B2: migrate() was NEVER called on the import path — no
    // sync_encryption_state row, no GDK epoch, no keyring file.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->exists())->toBeFalse();

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring((int) $user->id, $session)->epochs())->toBe([], 'the import path must never self-mint a GDK epoch (B2)');

    // G2: the initiator (desktop) is admitted into this device's registry.
    $initiatorRow = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', $initiatorDeviceId)
        ->first();
    expect($initiatorRow)->not->toBeNull();
    expect($initiatorRow->confirmed_at)->not->toBeNull();
});

it('MEDIUM-01: a re-entry to /mobile/pair WITHOUT ?mode=import still defers self-mint once the durable import-intent marker was set on an earlier visit', function (): void {
    $user = pairingScanTestUser('mobile-pair-import-durable-reentry');
    test()->actingAs($user);

    // A prior, unrelated test process may have left a keyring file on disk
    // for this same reused numeric user id (documented cross-test
    // filesystem-isolation gap — see the sibling B2 test's identical
    // comment).
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    $initiatorDeviceId = 'desktop-import-reentry';
    $initiatorEd = bin2hex(random_bytes(32));
    $initiatorKx = bin2hex(random_bytes(32));
    $token = bin2hex(random_bytes(16));

    // Simulate exactly what an EARLIER visit (WITH ?mode=import) already
    // durably left behind before the app was killed/relaunched/navigated
    // away mid-flow: (1) the responder token seeded from the scanned QR
    // (G1 — a bare table row, still PENDING, never accepted yet) and (2)
    // the durable import-intent marker (MEDIUM-01's own fix). Mirrors
    // exactly what `MobilePairingScan::submitCode()`'s import branch does
    // internally — used directly here as a fixture, the same convention
    // `pairingScanIssueToken()` already establishes in this file for
    // `PairingTokenService::issue()`.
    /** @var MobileImportIntentGate $importIntent */
    $importIntent = app(MobileImportIntentGate::class);
    $importIntent->markImporting((int) $user->id);

    /** @var PairingGateway $seedGateway */
    $seedGateway = app(PairingGateway::class);
    $seedGateway->seedResponderToken($token, $initiatorDeviceId, $initiatorEd, $initiatorKx, (int) $user->id);

    /** @var QrPayloadBuilder $qrBuilder */
    $qrBuilder = app(QrPayloadBuilder::class);
    $qrPayload = $qrBuilder->buildUri($initiatorDeviceId, $initiatorEd, $initiatorKx, $token);

    // Crucially: NO ?mode=import on THIS request — a genuine re-entry
    // (back button / bookmark / relaunched process) that lost the query
    // param. `submitCode()`'s G1 re-seed is correctly skipped (the row
    // already exists, still PENDING); `accept()` still succeeds against
    // it. Without the MEDIUM-01 fix, the self-mint decision below would
    // incorrectly follow this request's (false) `importMode` and silently
    // strand the desktop's delivered epoch-1 history.
    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));

    $component = Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', false)
        ->call('submitCode', $qrPayload)
        ->assertSet('step', 'confirm');

    $component->call('confirmMatch')->assertSet('awaitingPeer', true);

    /** @var PairingTokenService $tokenService */
    $tokenService = app(PairingTokenService::class);
    $pairingTokenId = (int) $component->get('pairingTokenId');
    $tokenService->confirm($pairingTokenId, (int) $user->id, $initiatorDeviceId);

    $component->call('checkPairingState')->assertSet('step', 'success');

    // The durable marker — NOT the (false) query-string importMode — must
    // have deferred self-mint: no sync_encryption_state row, no GDK epoch.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->exists())
        ->toBeFalse('the durable import-intent marker must defer self-mint even when ?mode=import is absent on THIS request');

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring((int) $user->id, $session)->epochs())->toBe([]);
});

it('non-import mode (CREATE-ACCOUNT path) is UNCHANGED — both-confirm still self-mints the GDK epoch', function (): void {
    $user = pairingScanTestUser('mobile-pair-nonimport-selfmint');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);
    $issued = pairingScanIssueToken($user);

    // importMode defaults to false — no ?mode=import query set.
    $component = Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', false)
        ->call('submitCode', $issued['qrPayload'])
        ->assertSet('step', 'confirm');

    $component->call('confirmMatch')->assertSet('awaitingPeer', true);

    /** @var PairingTokenService $tokenService */
    $tokenService = app(PairingTokenService::class);
    $pairingTokenId = (int) $component->get('pairingTokenId');
    $tokenService->confirm($pairingTokenId, (int) $user->id, $issued['initiatorDeviceId']);

    $component->call('checkPairingState')->assertSet('step', 'success');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->first();
    expect($state)->not->toBeNull('the non-import CREATE-ACCOUNT path must still self-mint on both-confirm — unchanged (D-07)');
    expect($state->current_epoch)->toBe(1);
});

/*
 * The scanner entry points. Every one of these is reachable from the view
 * or from a native event, so leaving them unexercised means the paths a
 * user actually takes into the camera are only ever proven by hand on a
 * device. The native facade stays absent here (see the file header), which
 * is exactly the condition each of these has to survive.
 */

it('useWordCode() reaches enter_code WITHOUT the amber notice — a choice, not a failure', function (): void {
    $user = pairingScanTestUser('mobile-pair-word-choice');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    Livewire::test(MobilePairingScan::class)
        ->call('cameraDenied')
        ->assertSet('cameraUnavailableNotice', true)
        ->call('useWordCode')
        ->assertSet('step', 'enter_code')
        // The distinction that makes this a separate method: nothing failed,
        // so the camera-unavailable notice must be cleared rather than left
        // accusing the device of a problem it does not have.
        ->assertSet('cameraUnavailableNotice', false)
        ->assertSet('flashMessage', '');
});

it('startScan() falls back to enter_code when the bridge cannot open a camera', function (): void {
    $user = pairingScanTestUser('mobile-pair-startscan');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    // QrScanBridge::open() returns false in this toolchain (no native
    // facade), which is the same answer a real device gives when the
    // camera is refused — so the retry button can never dead-end.
    Livewire::test(MobilePairingScan::class)
        ->call('startScan', app(QrScanBridge::class))
        ->assertSet('step', 'enter_code')
        ->assertSet('cameraUnavailableNotice', true);
});

it('the native ScannerCancelled event lands on the typed-code fallback', function (): void {
    $user = pairingScanTestUser('mobile-pair-cancelled');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    Livewire::test(MobilePairingScan::class)
        ->call('onScannerCancelled', true, 'user-cancelled', null)
        ->assertSet('step', 'enter_code')
        ->assertSet('cameraUnavailableNotice', true);
});

it('an empty CodeScanned payload is ignored rather than treated as a bad code', function (): void {
    $user = pairingScanTestUser('mobile-pair-empty-scan');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    // A native scanner that fires with no data must not be reported to the
    // user as an invalid code — there is nothing to invalidate, and the
    // scan step should stay put so the next frame can decode.
    Livewire::test(MobilePairingScan::class)
        ->call('cameraDenied')
        ->set('step', 'scan')
        ->call('onCodeScanned', data: '')
        ->assertSet('step', 'scan')
        ->assertSet('flashMessage', '');
});

/*
 * Pairing is a one-way door.
 *
 * With a confirmed peer already in the registry and no ceremony in flight,
 * the pairing screen has nothing left to do — but it still rendered, so the
 * back button dropped a fully-paired device back into the wizard it had
 * already finished, mid-sync.
 */
it('refuses to re-enter the wizard once a peer is already confirmed', function (): void {
    $user = pairingScanTestUser('paired-'.bin2hex(random_bytes(3)));
    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);
    test()->actingAs($user);

    $now = CarbonImmutable::now()->toIso8601String();

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'already-paired-desktop',
        'name' => 'Desktop',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::test(MobilePairingScan::class)->assertRedirect(route('data-devices.index'));
});

// A device still finishing its import owes the setup gate, not the app: it has
// a peer but not yet the history that peer is sending.
it('sends a still-importing device to the setup gate rather than the app', function (): void {
    $user = pairingScanTestUser('paired-'.bin2hex(random_bytes(3)));
    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);

    test()->actingAs($user);
    app(MobileImportIntentGate::class)->markImporting((int) $user->id);

    $now = CarbonImmutable::now()->toIso8601String();

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'importing-desktop',
        'name' => 'Desktop',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::test(MobilePairingScan::class)->assertRedirect(route('mobile.setup'));
});

// The back button landed on the passed "device paired" step because the
// ceremony row still existed, merely finished. A confirmed peer plus a
// confirmed token is done twice over.
it('refuses to re-show the passed success step after the ceremony confirmed', function (): void {
    $user = pairingScanTestUser('confirmed-'.bin2hex(random_bytes(3)));
    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);
    test()->actingAs($user);

    $now = CarbonImmutable::now()->toIso8601String();
    $db = app(DatabaseManager::class)->connection();

    $db->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'confirmed-desktop',
        'name' => 'Desktop',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // A finished ceremony row that has not been pruned yet.
    $db->table('pairing_tokens')->insert([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'done-token'),
        'initiator_device_id' => 'confirmed-desktop',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'state' => 'confirmed',
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        'created_at' => $now,
    ]);

    Livewire::test(MobilePairingScan::class)->assertRedirect(route('data-devices.index'));
});

// The wizard is blocking by design; app navigation wrapped around it let the
// user tap straight out of a ceremony they had to finish.
it('renders without the app navigation chrome', function (): void {
    $user = pairingScanTestUser('chrome-'.bin2hex(random_bytes(3)));
    /** @var Session $session */
    $session = app(Session::class);
    pairingScanSetUpIdentity($user, $session);
    test()->actingAs($user);

    $source = (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Http/Livewire/MobilePairingScan.php'),
    );

    expect($source)->toContain("extends('layouts.lock'")
        ->and($source)->not->toContain("extends('layouts.app'");
});
