<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
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
