<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Public\Enums\PairingWizardStep;

uses(RefreshDatabase::class);

// The QR is echoed raw, because an inline SVG cannot be escaped and still
// draw. That is sound only while the markup stays server-built, and a public
// Livewire property is rehydrated from the client payload on every request —
// so the raw echo and the lock attribute are a pair, and losing one is an XSS sink.

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

// A <dialog> with no accessible name is announced as "dialog". Flux forwards
// only class/style/autofocus to the dialog element, so the name is bound from
// inside the modal to the heading the current step shows.
it('names the pairing dialog after the step the reader is on', function (): void {
    $user = lockedQrUser('pairing-dialog-name');

    $html = (string) Livewire::actingAs($user)->test(PairingFlowModal::class)->html();

    expect($html)->toContain('id="pairing-modal-title"')
        ->and($html)->toContain("setAttribute('aria-labelledby', 'pairing-modal-title')");
});

// $step carries no #[Locked], so the client decides what arrives in it. Typing
// it as a backed enum would make a crafted value a 500 rather than a harmless
// fallback, which is why the property stays a string and is read back with
// tryFrom().
it('renders the first step when a step outside the wizard arrives from the wire', function (): void {
    $user = lockedQrUser('pairing-bogus-step');

    $html = (string) Livewire::actingAs($user)
        ->test(PairingFlowModal::class)
        ->set('step', 'not-a-step')
        ->assertSet('step', 'not-a-step')
        ->html();

    expect($html)->toContain('pairing-step-'.PairingWizardStep::ChooseDirection->value)
        ->and($html)->toContain('id="pairing-modal-title"');
});
