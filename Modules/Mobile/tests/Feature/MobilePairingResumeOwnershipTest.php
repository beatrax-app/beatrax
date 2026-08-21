<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// Prefixed rather than sharing the desktop file's helper names: Pest declares
// these globally, and two files declaring resumeUser() would fatal on a full run.
function pairingOwnershipUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{id: int, tokenHash: string}
 */
function pairingOwnershipRow(int $userId, string $initiatorDeviceId, ?string $responderDeviceId, string $state): array
{
    $tokenHash = hash('sha256', 'mobile-resume-'.$userId.'-'.$initiatorDeviceId.'-'.$state);

    $id = (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $userId,
        'token_hash' => $tokenHash,
        'initiator_device_id' => $initiatorDeviceId,
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => $responderDeviceId,
        'responder_ed25519_pub_hex' => $responderDeviceId === null ? null : str_repeat('c', 64),
        'responder_x25519_pub_hex' => $responderDeviceId === null ? null : str_repeat('d', 64),
        'state' => $state,
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        'created_at' => CarbonImmutable::now()->toIso8601String(),
    ]);

    return ['id' => $id, 'tokenHash' => $tokenHash];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->user = pairingOwnershipUser('mobile-resume-user');
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    $this->localDeviceId = (string) app(PairingGateway::class)->currentDeviceId((int) $this->user->id, $session);
    expect($this->localDeviceId)->not->toBe('');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// PairingGateway::inFlightFor() answers whether a ceremony is running for the
// account, not for this device. Two other devices mid-handshake produced a row
// this screen adopted whole, dropping the user onto a trust gate for a pairing
// between two machines neither of which was the phone.

it('does not adopt a ceremony between two other devices on the same account', function (): void {
    pairingOwnershipRow((int) $this->user->id, 'device-one', 'device-two', 'awaiting_confirm');

    Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'enter_code')
        ->assertSet('pairingTokenId', '')
        ->assertSet('safetyWords', [])
        ->assertSet('importResponderTokenHash', '')
        ->assertSet('importDesktopDeviceId', '');
});

it('does not adopt a ceremony whose responder side is still unclaimed', function (): void {
    // The initiator has issued a code nobody has scanned yet, and this phone is no
    // more part of that row than any other device the user owns.
    pairingOwnershipRow((int) $this->user->id, 'device-one', null, 'awaiting_confirm');

    Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'enter_code')
        ->assertSet('pairingTokenId', '');
});

it('still resumes the ceremony this device is the responder of', function (): void {
    $row = pairingOwnershipRow((int) $this->user->id, 'the-other-device', (string) $this->localDeviceId, 'awaiting_confirm');

    Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'confirm')
        ->assertSet('side', 'responder')
        ->assertSet('pairingTokenId', (string) $row['id'])
        ->assertSet('importResponderTokenHash', $row['tokenHash'])
        ->assertSet('importDesktopDeviceId', 'the-other-device');
});
