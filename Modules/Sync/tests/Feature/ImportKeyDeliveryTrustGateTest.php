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

// Epoch delivery is reachable only from the confirmed branch, so it cannot run
// before both sides have matched the safety number. Any earlier state must
// enqueue nothing: a wrap sent to a half-confirmed peer hands group keys to a
// device the user never verified.

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

    // Only one side has confirmed so far.
    $tokenService->confirm($tokenId, (int) $user->id, 'device-resp-awaiting');

    expect($db->connection()->table('relay_mailbox')->where('recipient_did', 'device-resp-awaiting')->count())->toBe(0);

    // The desktop's own poll sees the same unconfirmed state, and must not fan
    // out from there either.
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

    expect($db->connection()->table('relay_mailbox')->where('recipient_did', 'device-resp-confirmed')->count())->toBe(0);

    // The second and deciding confirmation.
    $pairing->call('confirmMatch')
        ->assertSet('step', 'success')
        ->assertSet('fanOutFailed', false);

    // The migration runs first and mints this desktop's first epoch, so exactly
    // one wrap is enqueued for the newly-confirmed peer.
    $wraps = $db->connection()->table('relay_mailbox')
        ->where('recipient_did', 'device-resp-confirmed')
        ->whereNull('delivered_at')
        ->get();

    expect($wraps)->toHaveCount(1);
    /** @var array<string, mixed> $wrap */
    $wrap = json_decode((string) $wraps->first()->blob, true, 8, JSON_THROW_ON_ERROR);
    expect($wrap['type'])->toBe('GDK_EPOCH_WRAP');
    // Epoch ids are minted rather than counted, so what matters is that the
    // device holds exactly one, not which number it reads.
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

    $pairing->call('cancelPairing');

    $row = $db->connection()->table('pairing_tokens')->where('id', $tokenId)->first();
    expect($row->state)->toBe('expired');

    // confirm() has no expiry re-check of its own; the gates are upstream. So
    // this pins the end-to-end outcome rather than the branch: no wraps.
    $tokenService->confirm($tokenId, (int) $user->id, 'device-resp-expired');

    expect($db->connection()->table('relay_mailbox')->where('recipient_did', 'device-resp-expired')->count())->toBe(0);
});
