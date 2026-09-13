<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// The confirm step has two writers of one line: a refusal of the reader's own
// tap, and the three-second poll reporting whether its frame went out. The poll
// writes '' on every success, so whatever the first one said lived under three
// seconds — including the one line that says the device on the other end is no
// longer the device those six words were derived from.

const POLL_OVERWRITE_DESKTOP = 'the-desktop-that-issued-it';

function pollOverwriteUser(): User
{
    return User::query()->create([
        'username' => 'poll-overwrite-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function pollOverwriteRow(int $userId, string $responderDeviceId): int
{
    return (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $userId,
        'token_hash' => hash('sha256', 'poll-overwrite-'.$userId),
        'initiator_device_id' => POLL_OVERWRITE_DESKTOP,
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => $responderDeviceId,
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'state' => 'awaiting_confirm',
        // Zulu, because the liveness check orders this column lexically
        // against a Zulu now.
        'expires_at' => Instant::zulu(CarbonImmutable::now()->addMinutes(10)),
        'created_at' => Instant::zulu(CarbonImmutable::now()),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-05 10:00:00');

    $this->user = pollOverwriteUser();
    $this->actingAs($this->user);

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

    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    app(PairingGateway::class)->enableSyncIdentityWithoutEpoch((int) $this->user->id, $session);

    $this->deviceId = (string) app(PairingGateway::class)->currentDeviceId((int) $this->user->id, $session);
    $this->tokenId = pollOverwriteRow((int) $this->user->id, $this->deviceId);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// A responder that rebinds between the reading and the tap is the one thing the
// six words on screen cannot show, and this line is the whole of what says so.
it('leaves the safety-number warning on screen when the next poll re-emits the accept', function (): void {
    $component = Livewire::test(MobilePairingScan::class)->assertSet('step', 'confirm');

    // The keys behind the words this reader compared are no longer the ones
    // the row binds, which is exactly what confirm() refuses for.
    DB::table('pairing_tokens')->where('id', $this->tokenId)->update([
        'initiator_ed25519_pub_hex' => str_repeat('e', 64),
    ]);

    $component->call('confirmMatch')
        ->assertSet('step', 'confirm')
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.safety_number_changed'));

    $component->call('checkPairingState')
        ->assertSet('step', 'confirm')
        ->assertSet(
            'flashMessage',
            Lang::get('mobile::pairing.errors.safety_number_changed'),
            'the poll reports its own delivery and owns no other line on this step',
        );
});

// The companion: the poll must still retire its OWN line, or a reader who
// unlocks goes on reading "this device cannot open its identity" forever.
it('still clears its own delivery notice once the send goes through', function (): void {
    $component = Livewire::test(MobilePairingScan::class)->assertSet('step', 'confirm');

    AppLockTestHarness::lock($this->session);
    $component->call('checkPairingState')
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.identity_locked'));

    AppLockTestHarness::unlock($this->session, str_repeat('k', 32));
    $component->call('checkPairingState')->assertSet('flashMessage', '');
});

// Cancelling is the reader's own ending, and the local row has to stop being a
// ceremony — a later scan is judged against whatever this table still holds.
it('ends the local ceremony when the reader cancels out of it', function (): void {
    Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'confirm')
        ->call('cancelPairing')
        ->assertRedirect(route(Destination::DataDevices->routeName()));

    expect(DB::table('pairing_tokens')->where('id', $this->tokenId)->value('state'))
        ->toBe('expired', 'a cancelled ceremony that stays live is one the next scan is refused against');
});
