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

// The resume path assumed this device was the initiator, which decides which
// column a confirmation is written to and whose name is shown as the peer's;
// and it sent even an already-confirmed row to the trust gate, which confirm()
// then refuses as already given, leaving the modal nowhere to go.

function resumeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// A row on which THIS device is the responder — the case the resume path
// never considered, since only the initiator shows a code.
function resumeRowAsResponder(int $userId, string $localDeviceId, string $state): int
{
    return (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $userId,
        'token_hash' => hash('sha256', 'resume-'.$userId.'-'.$state),
        'initiator_device_id' => 'the-other-device',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => $localDeviceId,
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'state' => $state,
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        'created_at' => CarbonImmutable::now()->toIso8601String(),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->user = resumeUser('resume-user');
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    $this->localDeviceId = app(PairingGateway::class)->currentDeviceId((int) $this->user->id, $session);
    expect($this->localDeviceId)->not->toBeNull();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('resumes a confirmed pairing on the success step rather than re-asking for a confirmation already given', function (): void {
    resumeRowAsResponder((int) $this->user->id, (string) $this->localDeviceId, 'confirmed');

    Livewire::test(PairingFlowModal::class)
        ->call('openModal')
        ->assertSet('step', 'success');
});

it('resumes as the side this device actually is, not as the initiator', function (): void {
    resumeRowAsResponder((int) $this->user->id, (string) $this->localDeviceId, 'awaiting_confirm');

    Livewire::test(PairingFlowModal::class)
        ->call('openModal')
        ->assertSet('side', 'responder')
        ->assertSet('step', 'confirm');
});

it('does not resume a row this device owns neither side of', function (): void {
    // A token belonging to the account but to two OTHER devices. Adopting it
    // would put this device into a ceremony it is not part of.
    DB::table('pairing_tokens')->insert([
        'user_id' => $this->user->id,
        'token_hash' => hash('sha256', 'resume-foreign'),
        'initiator_device_id' => 'device-one',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => 'device-two',
        'state' => 'awaiting_confirm',
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        'created_at' => CarbonImmutable::now()->toIso8601String(),
    ]);

    Livewire::test(PairingFlowModal::class)
        ->call('openModal')
        ->assertSet('step', 'choose_direction')
        ->assertSet('side', '');
});
