<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;

uses(RefreshDatabase::class);

/*
 * ImportKeyDeliveryTrustGateTest — Task 3 UI half (Phase 15 import-join):
 * proves epoch delivery (deliverAllEpochsToDevice, driven from
 * PairingFlowModal) is reachable ONLY from the `state === CONFIRMED`
 * branch, i.e. only AFTER PairingTokenService::confirm()'s bothConfirmed()
 * transition. A pending/awaiting/expired/rejected token must enqueue ZERO
 * relay_mailbox wraps.
 *
 * 6b per 15-import-join-PLAN.md's test-plan. Mirrors
 * SyncEnableForcesEncryptionTest's Livewire(PairingFlowModal) fixture idiom.
 */

function trustGateFlowUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function trustGateAppLockRow(DatabaseManager $db, int $userId): void
{
    $db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $userId,
        'lock_enabled' => 1,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => '2026-07-14T10:00:00Z',
        'updated_at' => '2026-07-14T10:00:00Z',
    ]);
}

it('delivers zero wraps while only ONE side has confirmed (awaiting_confirm)', function (): void {
    $user = trustGateFlowUser('trust-gate-awaiting');
    test()->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var Session $session */
    $session = app(Session::class);

    trustGateAppLockRow($db, (int) $user->id);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    $pairing = Livewire::test(PairingFlowModal::class)
        ->call('showMyCode')
        ->assertSet('step', 'show_code');

    $tokenId = (int) $pairing->get('pairingTokenId');

    /** @var WordCodeEncoder $wordEncoder */
    $wordEncoder = app(WordCodeEncoder::class);
    $plaintextToken = $wordEncoder->decode($pairing->get('wordCode'));

    /** @var PairingTokenService $tokenService */
    $tokenService = app(PairingTokenService::class);
    $tokenService->accept($plaintextToken, (int) $user->id, 'device-resp-awaiting', str_repeat('c', 64), str_repeat('d', 64));

    // Only the RESPONDER side confirms — bothConfirmed() is not yet true.
    $tokenService->confirm($tokenId, (int) $user->id, 'device-resp-awaiting');

    // The desktop side has not yet called confirmMatch()/checkPairingState()
    // — the CONFIRMED branch (and therefore deliverAllEpochsToDevice) was
    // never reached.
    expect($db->connection()->table('relay_mailbox')->where('recipient_did', 'device-resp-awaiting')->count())->toBe(0);

    // The desktop's OWN poll observes the state is still awaiting_confirm
    // (not confirmed) — checkPairingState() must not fan out either.
    $pairing->call('checkPairingState')->assertSet('step', 'confirm');
    expect($db->connection()->table('relay_mailbox')->where('recipient_did', 'device-resp-awaiting')->count())->toBe(0);
});

it('delivers wraps to the newly-confirmed device ONLY after both-confirm (CONFIRMED)', function (): void {
    $user = trustGateFlowUser('trust-gate-confirmed');
    test()->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var Session $session */
    $session = app(Session::class);

    trustGateAppLockRow($db, (int) $user->id);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    expect($db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->exists())->toBeFalse();

    $pairing = Livewire::test(PairingFlowModal::class)
        ->call('showMyCode')
        ->assertSet('step', 'show_code');

    $tokenId = (int) $pairing->get('pairingTokenId');

    /** @var WordCodeEncoder $wordEncoder */
    $wordEncoder = app(WordCodeEncoder::class);
    $plaintextToken = $wordEncoder->decode($pairing->get('wordCode'));

    /** @var PairingTokenService $tokenService */
    $tokenService = app(PairingTokenService::class);
    $tokenService->accept($plaintextToken, (int) $user->id, 'device-resp-confirmed', str_repeat('e', 64), str_repeat('f', 64));
    $tokenService->confirm($tokenId, (int) $user->id, 'device-resp-confirmed');

    // Zero wraps BEFORE the deciding confirm.
    expect($db->connection()->table('relay_mailbox')->where('recipient_did', 'device-resp-confirmed')->count())->toBe(0);

    // The desktop's own confirmMatch() call is the SECOND/deciding
    // confirmation — bothConfirmed() flips to CONFIRMED here.
    $pairing->call('confirmMatch')
        ->assertSet('step', 'success')
        ->assertSet('fanOutFailed', false);

    // migrate() (called first, per Task 3 ordering) mints epoch 1 for this
    // previously-unencrypted desktop, so exactly ONE wrap (epoch 1) is now
    // enqueued for the newly-confirmed responder.
    $wraps = $db->connection()->table('relay_mailbox')
        ->where('recipient_did', 'device-resp-confirmed')
        ->whereNull('delivered_at')
        ->get();

    expect($wraps)->toHaveCount(1);
    /** @var array<string, mixed> $wrap */
    $wrap = json_decode((string) $wraps->first()->blob, true, 8, JSON_THROW_ON_ERROR);
    expect($wrap['type'])->toBe('GDK_EPOCH_WRAP');
    // Epoch ids are minted, not counted, so a device holding exactly one
    // epoch is what this proves — the number itself carries no meaning.
    expect($wrap['epoch_id'])->toBeGreaterThan(0);
    expect($wrap['recipient_device_id'])->toBe('device-resp-confirmed');
});

it('delivers zero wraps for a token expired/cancelled BEFORE both-confirm', function (): void {
    $user = trustGateFlowUser('trust-gate-expired');
    test()->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var Session $session */
    $session = app(Session::class);

    trustGateAppLockRow($db, (int) $user->id);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    $pairing = Livewire::test(PairingFlowModal::class)
        ->call('showMyCode')
        ->assertSet('step', 'show_code');

    $tokenId = (int) $pairing->get('pairingTokenId');

    /** @var WordCodeEncoder $wordEncoder */
    $wordEncoder = app(WordCodeEncoder::class);
    $plaintextToken = $wordEncoder->decode($pairing->get('wordCode'));

    /** @var PairingTokenService $tokenService */
    $tokenService = app(PairingTokenService::class);
    $tokenService->accept($plaintextToken, (int) $user->id, 'device-resp-expired', str_repeat('1', 64), str_repeat('2', 64));

    // Cancel BEFORE either side confirms — mirrors PairingFlowModal::cancelPairing().
    $pairing->call('cancelPairing');

    $row = $db->connection()->table('pairing_tokens')->where('id', $tokenId)->first();
    expect($row->state)->toBe('expired');

    // A confirm attempt against the now-expired token must never reach
    // bothConfirmed() / CONFIRMED (PairingTokenService::confirm() has no
    // expiry re-check of its own — the state machine's canAccept()/prune()
    // gates are upstream of confirm() — so this asserts the REAL end-to-end
    // outcome: zero wraps regardless).
    $tokenService->confirm($tokenId, (int) $user->id, 'device-resp-expired');

    expect($db->connection()->table('relay_mailbox')->where('recipient_did', 'device-resp-expired')->count())->toBe(0);
});
