<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\Concerns\ConfirmsAcrossTheLock;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Enums\PairingFrameSend;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// `mobile.pair` is exempt from the lock redirect on purpose, so the idle
// timeout takes the identity out from under a trust gate that keeps its screen.
// Measured on an iPhone 12 mini: fifty-eight relay drains, zero frames sent,
// four minutes of a live Confirm button, and a tap that went nowhere.

const LOCK_MID_CEREMONY_DESKTOP = 'the-desktop-that-issued-it';

function lockMidCeremonyUser(): User
{
    return User::query()->create([
        'username' => 'lock-mid-ceremony-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{id: int, tokenHash: string}
 */
function lockMidCeremonyRow(int $userId, string $responderDeviceId): array
{
    $tokenHash = hash('sha256', 'lock-mid-ceremony-'.$userId);

    $id = (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $userId,
        'token_hash' => $tokenHash,
        'initiator_device_id' => LOCK_MID_CEREMONY_DESKTOP,
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => $responderDeviceId,
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'state' => 'awaiting_confirm',
        // Zulu, because ceremonyIsLive() orders this column lexically against a
        // Zulu now and an offset form sorts by its own local hour digits.
        'expires_at' => Instant::zulu(CarbonImmutable::now()->addMinutes(10)),
        'created_at' => Instant::zulu(CarbonImmutable::now()),
    ]);

    return ['id' => $id, 'tokenHash' => $tokenHash];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-05 10:00:00');

    $this->user = lockMidCeremonyUser();
    $this->actingAs($this->user);
    $this->dataKey = str_repeat('k', 32);

    DB::table('user_app_lock_configs')->insert([
        'user_id' => $this->user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    $this->session = $session;

    AppLockTestHarness::unlock($session, $this->dataKey);
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    $this->deviceId = (string) app(PairingGateway::class)->currentDeviceId((int) $this->user->id, $session);
    $this->row = lockMidCeremonyRow((int) $this->user->id, $this->deviceId);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// Without the typed return the poll's re-emit is a no-op that throws nothing,
// and the line below it set flashMessage to '' — so this assertion reads ''
// against a screen that has sent nothing for as long as it is left open.
it('tells the reader the identity is locked instead of redrawing a live confirm step', function (): void {
    $component = Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'confirm')
        ->assertSet('flashMessage', '');

    AppLockTestHarness::lock($this->session);

    $component->call('checkPairingState')
        ->assertSet('step', 'confirm')
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.identity_locked'));
});

// The companion half: the line is about the lock and nothing else, so an
// unlocked poll must never reach for it whatever the transports did.
it('does not call an unlocked ceremony locked', function (): void {
    Livewire::test(MobilePairingScan::class)
        ->call('checkPairingState')
        ->assertSet('step', 'confirm')
        ->assertNotSet('flashMessage', Lang::get('mobile::pairing.errors.identity_locked'));
});

it('names the ending rather than returning quietly when the identity is sealed', function (): void {
    AppLockTestHarness::lock($this->session);

    $sent = app(PairingGateway::class)->sendResponderAccept(
        (int) $this->user->id,
        $this->row['tokenHash'],
        LOCK_MID_CEREMONY_DESKTOP,
        $this->session,
    );

    expect($sent)->toBe(PairingFrameSend::NoUsableIdentity);
});

// The tap used to bounce to the PIN pad and be forgotten, so the reader paid
// for the unlock and still had to compare six words and tap a second time.
it('carries a confirm tap made while locked across the unlock', function (): void {
    $component = Livewire::test(MobilePairingScan::class)->assertSet('step', 'confirm');

    AppLockTestHarness::lock($this->session);

    $component->call('confirmMatch')->assertRedirect(route('mobile.lock'));

    expect(DB::table('pairing_tokens')->where('id', $this->row['id'])->value('responder_confirmed_at'))->toBeNull()
        ->and($this->session->get(ConfirmsAcrossTheLock::DEFERRED_CONFIRM_SESSION))->toBeArray();

    AppLockTestHarness::unlock($this->session, $this->dataKey);

    Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'confirm')
        ->assertSet('awaitingPeer', true);

    expect(DB::table('pairing_tokens')->where('id', $this->row['id'])->value('responder_confirmed_at'))->not->toBeNull()
        ->and($this->session->get(ConfirmsAcrossTheLock::DEFERRED_CONFIRM_SESSION))->toBeNull();
});

// Carrying the tap must not carry the trust decision with it. The digest is
// the fingerprint of the six words the human compared, so a peer that rebinds
// while the reader is at the PIN pad inherits nothing.
it('refuses a carried tap whose safety number moved while the reader was away', function (): void {
    $component = Livewire::test(MobilePairingScan::class)->assertSet('step', 'confirm');

    AppLockTestHarness::lock($this->session);
    $component->call('confirmMatch');

    DB::table('pairing_tokens')
        ->where('id', $this->row['id'])
        ->update(['initiator_ed25519_pub_hex' => str_repeat('e', 64)]);

    AppLockTestHarness::unlock($this->session, $this->dataKey);

    Livewire::test(MobilePairingScan::class)
        ->assertSet('awaitingPeer', false)
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.safety_number_changed'));

    expect(DB::table('pairing_tokens')->where('id', $this->row['id'])->value('responder_confirmed_at'))->toBeNull();
});
