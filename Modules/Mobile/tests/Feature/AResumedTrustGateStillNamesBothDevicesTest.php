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

// Measured on an iPhone beside a Mac: the app lock fired mid-ceremony, and the
// trust gate the reader came back to read "↔" with nothing on either side of
// it, while the desktop said "Mac ↔ iPhone". The words prove the channel; the
// names say which two devices it joins, and one screen stopped claiming that.

// Helper names are prefixed because Pest declares them globally and a sibling
// pairing test file already owns the unprefixed ones.

function resumedGateUser(): User
{
    return User::query()->create([
        'username' => 'resumed-gate-user',
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function resumedGateRow(int $userId, string $initiatorDeviceId, string $responderDeviceId, ?string $initiatorName): int
{
    return (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $userId,
        'token_hash' => hash('sha256', 'resumed-gate-'.$userId.'-'.$initiatorDeviceId),
        'initiator_device_id' => $initiatorDeviceId,
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'initiator_name' => $initiatorName,
        'responder_device_id' => $responderDeviceId,
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'state' => 'awaiting_confirm',
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        'created_at' => CarbonImmutable::now()->toIso8601String(),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-04 18:05:00');

    $this->user = resumedGateUser();
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    $this->localDeviceId = (string) app(PairingGateway::class)->currentDeviceId((int) $this->user->id, $session);

    DB::table('device_registry')
        ->where('user_id', $this->user->id)
        ->where('is_self', 1)
        ->update(['name' => 'iPhone']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('names both devices on a trust gate resumed after the app lock, not just the arrow between them', function (): void {
    resumedGateRow((int) $this->user->id, 'the-mac', (string) $this->localDeviceId, 'Mac');

    Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'confirm')
        ->assertSet('selfDeviceName', 'iPhone')
        ->assertSet('peerDeviceName', 'Mac')
        ->assertSeeText('iPhone')
        ->assertSeeText('Mac');
});

it('falls back to the placeholder rather than a blank when the row carries no initiator name', function (): void {
    resumedGateRow((int) $this->user->id, 'the-mac', (string) $this->localDeviceId, null);

    $component = Livewire::test(MobilePairingScan::class)->assertSet('step', 'confirm');

    expect($component->get('peerDeviceName'))->not->toBe('');
    expect($component->get('selfDeviceName'))->toBe('iPhone');
});
