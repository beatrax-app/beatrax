<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// Livewire runs a poll whose tab is hidden at one tick in twenty. This ceremony
// asks the reader to pick up the other device, so the window that has to notice
// the peer is the one guaranteed not to be in front — and a desktop showing a
// code sat on step 2 while its own row had already advanced.

const PAIRING_KEEP_ALIVE_POLL = 'wire:poll.3s.keep-alive="checkPairingState"';

function backgroundPollUser(): User
{
    return User::query()->create([
        'username' => 'background-poll',
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->user = backgroundPollUser();
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    $this->localDeviceId = (string) app(PairingGateway::class)->currentDeviceId((int) $this->user->id, $session);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('keeps the show-code poll alive while the window sits behind the phone', function (): void {
    Livewire::test(PairingFlowModal::class)
        ->call('showMyCode')
        ->assertSet('step', 'show_code')
        ->assertSee(PAIRING_KEEP_ALIVE_POLL, escape: false);
});

it('keeps the safety-word poll alive too, which is where the second device is waited on', function (): void {
    DB::table('pairing_tokens')->insert([
        'user_id' => $this->user->id,
        'token_hash' => hash('sha256', 'background-poll-confirm'),
        'initiator_device_id' => 'the-other-device',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => $this->localDeviceId,
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'state' => 'awaiting_confirm',
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601ZuluString(),
        'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
    ]);

    Livewire::test(PairingFlowModal::class)
        ->call('openModal')
        ->assertSet('step', 'confirm')
        ->assertSee(PAIRING_KEEP_ALIVE_POLL, escape: false);
});
