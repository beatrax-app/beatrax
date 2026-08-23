<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-22 10:00:00');

    $this->user = User::query()->create([
        'username' => 'stale-ceremony-user',
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    $this->localDeviceId = (string) app(PairingGateway::class)
        ->currentDeviceId((int) $this->user->id, $session);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function staleCeremonyRow(int $userId, string $localDeviceId, array $overrides = []): int
{
    return (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $userId,
        'token_hash' => hash('sha256', 'stale-'.$userId.'-'.json_encode($overrides)),
        'initiator_device_id' => $localDeviceId,
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => 'a-phone-wiped-two-rounds-ago',
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'responder_name' => 'Gone',
        'state' => 'awaiting_confirm',
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601ZuluString(),
        'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
        ...$overrides,
    ]);
}

function unlockTheApp(): void
{
    /** @var Session $session */
    $session = app(Session::class);

    AppLockTestHarness::lock($session);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
}

// The revival is renewed from two human moments and bounded by neither, so a
// ceremony nobody ever completed was revived forever. That kept
// hasLiveHandshake() true, which suppressed the daemon credentialling a new
// pairing code needs — a dead row from round 4 broke every round after it.
it('stops reviving a ceremony that no human has completed within the ceiling', function (): void {
    $tokenId = staleCeremonyRow((int) $this->user->id, $this->localDeviceId, [
        'created_at' => CarbonImmutable::now()->subHours(2)->toIso8601ZuluString(),
        'expires_at' => CarbonImmutable::now()->subMinutes(5)->toIso8601ZuluString(),
    ]);

    unlockTheApp();

    expect(app(PairingGateway::class)->hasLiveHandshake((int) $this->user->id))->toBeFalse()
        ->and(DB::table('pairing_tokens')->where('id', $tokenId)->value('state'))->toBe('expired');
});

// The ceiling must not swallow the case the revival exists for: a ceremony whose
// ten minutes lapsed behind a five-minute idle lock is still being attended.
it('still revives a ceremony that only lapsed behind the lock', function (): void {
    $tokenId = staleCeremonyRow((int) $this->user->id, $this->localDeviceId, [
        'expires_at' => CarbonImmutable::now()->subMinutes(5)->toIso8601ZuluString(),
    ]);

    unlockTheApp();

    $expiresAt = DB::table('pairing_tokens')->where('id', $tokenId)->value('expires_at');

    expect(CarbonImmutable::parse((string) $expiresAt)->greaterThan(CarbonImmutable::now()))->toBeTrue()
        ->and(app(PairingGateway::class)->hasLiveHandshake((int) $this->user->id))->toBeTrue();
});

// inFlight() excludes `pending`, so the modal never resumes such a row and had no
// id to cancel — while hasLiveHandshake() counts it, so it blocked the next
// attempt. Cancel was the reader's only way out and could not reach it.
it('lets the reader cancel a pending row the modal never resumed', function (): void {
    staleCeremonyRow((int) $this->user->id, $this->localDeviceId, [
        'state' => 'pending',
        'responder_device_id' => null,
    ]);

    expect(app(PairingGateway::class)->hasLiveHandshake((int) $this->user->id))->toBeTrue();

    Livewire::test(PairingFlowModal::class)
        ->call('openModal')
        ->assertSet('step', 'choose_direction')
        ->assertSet('pairingTokenId', '')
        ->call('cancelPairing');

    expect(app(PairingGateway::class)->hasLiveHandshake((int) $this->user->id))->toBeFalse();
});
