<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingState;

uses(RefreshDatabase::class);

// The countdown is drawn only inside the show_code block, and it runs on a
// setInterval -- which is not bound to the element it was started from. When the
// peer scans, the poll flips the wizard to confirm and the block is removed,
// leaving the timer ticking on a scope nobody can see. Ten minutes later it
// fired onCodeExpired() against a token the confirm step is still using.

function countdownOutlivedUser(): User
{
    return User::query()->create([
        'username' => 'countdown-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function countdownOutlivedState(int $userId): ?string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var object{state: string}|null $row */
    $row = $db->connection()->table('pairing_tokens')
        ->where('user_id', $userId)
        ->latest('id')
        ->first();

    return $row?->state;
}

beforeEach(function (): void {
    $this->user = countdownOutlivedUser();
    test()->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);
});

it('retires nothing once the screen the countdown belonged to is gone', function (): void {
    $component = Livewire::test(PairingFlowModal::class)
        ->call('showMyCode')
        ->assertSet('step', 'show_code');

    expect(countdownOutlivedState((int) $this->user->id))->toBe(PairingState::Pending->value);

    // What the poll does the moment the peer answers. The token id stays set,
    // which is exactly why the id alone could not tell these two apart.
    $component->set('step', 'confirm')->call('onCodeExpired');

    expect(countdownOutlivedState((int) $this->user->id))->toBe(PairingState::Pending->value);
    $component->assertSet('flashMessage', '');
});

// The positive control. A gate that refused every call would satisfy the case
// above and leave the countdown unable to retire anything at all.
it('retires the code when the countdown is still the screen', function (): void {
    $component = Livewire::test(PairingFlowModal::class)
        ->call('showMyCode')
        ->assertSet('step', 'show_code')
        ->call('onCodeExpired');

    expect(countdownOutlivedState((int) $this->user->id))->toBe(PairingState::Expired->value);
    $component->assertSet('expiresInSeconds', 0);
});

it('retires nothing from the success screen either', function (): void {
    $component = Livewire::test(PairingFlowModal::class)
        ->call('showMyCode')
        ->set('step', 'success')
        ->call('onCodeExpired');

    expect(countdownOutlivedState((int) $this->user->id))->toBe(PairingState::Pending->value);
    $component->assertSet('expiresInSeconds', fn (int $s): bool => $s > 0);
});
