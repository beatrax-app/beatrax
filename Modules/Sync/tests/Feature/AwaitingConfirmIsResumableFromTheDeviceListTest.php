<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// An app-lock firing mid-ceremony is not a bug to suppress — a pairing is
// exactly when the reader has walked away from this keyboard. What was broken
// is that the page came back saying nothing: the handshake sat in
// awaiting_confirm, unmentioned, until its TTL took it.

function resumableUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function resumableRow(int $userId, string $localDeviceId, array $overrides = []): int
{
    return (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $userId,
        'token_hash' => hash('sha256', 'resumable-'.$userId.'-'.json_encode($overrides)),
        'initiator_device_id' => $localDeviceId,
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => 'the-phone',
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'responder_name' => "Wessel's S24 Ultra",
        'state' => 'awaiting_confirm',
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601ZuluString(),
        'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-22 10:00:00');

    $this->user = resumableUser('resumable-user');
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    $this->localDeviceId = (string) app(PairingGateway::class)->currentDeviceId((int) $this->user->id, $session);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('names the device waiting on this one, and offers the way back into the ceremony', function (): void {
    resumableRow((int) $this->user->id, $this->localDeviceId);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('pairingWaitingOnPeer', "Wessel's S24 Ultra")
        ->assertSee(Lang::get('sync::devices.pairing_waiting', ['name' => "Wessel's S24 Ultra"]))
        ->assertSee('pairing-waiting-resume');
});

it('says nothing when the only handshake belongs to two other devices', function (): void {
    DB::table('pairing_tokens')->insert([
        'user_id' => $this->user->id,
        'token_hash' => hash('sha256', 'resumable-foreign'),
        'initiator_device_id' => 'device-one',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => 'device-two',
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'state' => 'awaiting_confirm',
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601ZuluString(),
        'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
    ]);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('pairingWaitingOnPeer', '');
});

it('says nothing about a pairing that already completed', function (): void {
    resumableRow((int) $this->user->id, $this->localDeviceId, ['state' => 'confirmed']);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('pairingWaitingOnPeer', '');
});

// The lock fires at five minutes idle and a ceremony has at most ten, so the
// reader who walks to the other device can come back to a window that closed
// while they were away. Reopening it is a stated exception to their own lock
// policy, which is why the same render discloses it.
it('reopens a ceremony whose window closed while the app sat locked', function (): void {
    $rowId = resumableRow((int) $this->user->id, $this->localDeviceId);

    CarbonImmutable::setTestNow('2026-08-22 10:30:00');

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('pairingWaitingOnPeer', "Wessel's S24 Ultra")
        ->assertSee(Lang::get('sync::devices.pairing_waiting_lock_override'));

    expect((string) DB::table('pairing_tokens')->where('id', $rowId)->value('expires_at'))
        ->toBe(CarbonImmutable::now()->addMinutes(5)->toIso8601ZuluString());
});

it('never shortens a ceremony that still has longer to run', function (): void {
    $rowId = resumableRow((int) $this->user->id, $this->localDeviceId);
    $untouched = (string) DB::table('pairing_tokens')->where('id', $rowId)->value('expires_at');

    Livewire::test(DevicesAndSyncSettingsSection::class);

    expect((string) DB::table('pairing_tokens')->where('id', $rowId)->value('expires_at'))
        ->toBe($untouched);
});

// A code shown but never answered binds no responder, so there are no words to
// compare and a longer window only lengthens the race for the responder slot.
it('leaves a lapsed pending row to die', function (): void {
    $rowId = resumableRow((int) $this->user->id, $this->localDeviceId, [
        'state' => 'pending',
        'responder_device_id' => null,
        'responder_ed25519_pub_hex' => null,
        'responder_x25519_pub_hex' => null,
    ]);
    $lapsed = (string) DB::table('pairing_tokens')->where('id', $rowId)->value('expires_at');

    CarbonImmutable::setTestNow('2026-08-22 10:30:00');

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('pairingWaitingOnPeer', '');

    expect((string) DB::table('pairing_tokens')->where('id', $rowId)->value('expires_at'))
        ->toBe($lapsed);
});

it('leaves a lapsed ceremony belonging to two other devices to die', function (): void {
    $rowId = (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $this->user->id,
        'token_hash' => hash('sha256', 'resumable-foreign-lapsed'),
        'initiator_device_id' => 'device-one',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => 'device-two',
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'state' => 'awaiting_confirm',
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601ZuluString(),
        'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
    ]);
    $lapsed = (string) DB::table('pairing_tokens')->where('id', $rowId)->value('expires_at');

    CarbonImmutable::setTestNow('2026-08-22 10:30:00');

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('pairingWaitingOnPeer', '');

    expect((string) DB::table('pairing_tokens')->where('id', $rowId)->value('expires_at'))
        ->toBe($lapsed);
});

it('drops the notice when the resumed ceremony is cancelled from the modal', function (): void {
    resumableRow((int) $this->user->id, $this->localDeviceId);

    Livewire::test(PairingFlowModal::class)
        ->call('openModal')
        ->assertSet('step', 'confirm')
        ->call('cancelPairing');

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('pairingWaitingOnPeer', '');
});

// The mount-time extension only reaches a reader who opens this screen. The
// unlock itself is earlier and unconditional, so the listener is asserted with
// no Livewire render anywhere in the test — a render would hide a dead listener
// behind the very call it is meant to complement.
it('revives a ceremony that lapsed behind the lock at the unlock, with no visit to the devices screen', function (): void {
    $tokenId = resumableRow((int) $this->user->id, $this->localDeviceId, [
        'expires_at' => CarbonImmutable::now()->subMinutes(5)->toIso8601ZuluString(),
    ]);

    /** @var Session $session */
    $session = app(Session::class);

    AppLockTestHarness::lock($session);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $expiresAt = DB::table('pairing_tokens')->where('id', $tokenId)->value('expires_at');

    expect(is_string($expiresAt))->toBeTrue();
    expect(CarbonImmutable::parse((string) $expiresAt)->greaterThan(CarbonImmutable::now()))->toBeTrue();
});

// A locked session cannot resolve a device id, so the listener has nothing to
// match a ceremony side against and must leave the row alone.
it('leaves the ceremony alone when the lock closes rather than opens', function (): void {
    $tokenId = resumableRow((int) $this->user->id, $this->localDeviceId, [
        'expires_at' => CarbonImmutable::now()->subMinutes(5)->toIso8601ZuluString(),
    ]);

    /** @var Session $session */
    $session = app(Session::class);

    AppLockTestHarness::lock($session);

    $expiresAt = DB::table('pairing_tokens')->where('id', $tokenId)->value('expires_at');

    expect(CarbonImmutable::parse((string) $expiresAt)->lessThan(CarbonImmutable::now()))->toBeTrue();
});
