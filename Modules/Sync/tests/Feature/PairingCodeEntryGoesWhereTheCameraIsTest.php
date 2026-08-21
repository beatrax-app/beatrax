<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;

uses(RefreshDatabase::class);

// This modal has never had a scanner, on any platform, while the button that
// leads into it offered to scan. On a phone the offer can be made true — there
// is a camera-first pairing screen — so the phone is sent there instead of to a
// text field. On the desktop the offer was simply withdrawn.

function codeEntryUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('code-entry-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('sends a phone to the camera-first pairing screen instead of a text field', function (): void {
    $this->actingAs(codeEntryUser('code-entry-phone'));

    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

    try {
        Livewire::test(PairingFlowModal::class)
            ->call('enterACode')
            ->assertRedirect(route('mobile.pair'));
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }
});

it('opens the text field on the desktop, which has no camera path at all', function (): void {
    $this->actingAs(codeEntryUser('code-entry-desktop'));

    Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->assertSet('step', 'enter_code')
        ->assertNoRedirect();
});

it('no longer offers to scan on a surface that cannot', function (): void {
    expect(trans('sync::pairing.enter_a_code_help', [], 'en'))
        ->not->toContain('scan');
});
