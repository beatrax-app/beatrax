<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;

uses(RefreshDatabase::class);

/*
 * PairingFlowModal — $qrSvg is the component's only raw-echoed property.
 *
 * pairing-flow-modal.blade.php renders it with {!! !!} because an inline
 * SVG cannot be escaped and still draw. That is sound only while the
 * markup is server-built, and a public Livewire property is rehydrated
 * from the client payload on every request — so the raw echo and the
 * #[Locked] attribute are a pair. Removing the lock turns the QR tile
 * into an XSS sink, which is what this file guards.
 */

function lockedQrUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('qr-lock-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('refuses a client-side write to the raw-echoed $qrSvg property', function (): void {
    $user = lockedQrUser('qr-lock-reject');

    Livewire::actingAs($user)
        ->test(PairingFlowModal::class)
        ->set('qrSvg', '<svg onload="alert(1)"></svg>');
})->throws(CannotUpdateLockedPropertyException::class);

it('leaves $qrSvg empty on a freshly opened modal so nothing stale is raw-echoed', function (): void {
    $user = lockedQrUser('qr-lock-empty');

    Livewire::actingAs($user)
        ->test(PairingFlowModal::class)
        ->dispatch('open-pairing-modal')
        ->assertSet('qrSvg', '')
        ->assertSet('step', 'choose_direction');
});
