<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityState;
use Modules\Sync\Internal\Pairing\PairingRefusalCopy;

// load() folds three states into one null, and the copy folded two of them
// into one sentence: a device that never minted an identity was told its
// identity was locked. There is no lock on such a device, so the middleware
// shows no lock screen and the reader is sent looking for a PIN pad.

function refusalCopy(): PairingRefusalCopy
{
    return app(PairingRefusalCopy::class);
}

it('names a device that never set up sync, rather than calling it locked', function (): void {
    $absent = refusalCopy()->identityUnavailable(DeviceIdentityState::Absent);

    expect($absent)->toBe(Lang::get('sync::pairing.identity_absent'));
    expect($absent)->not->toBe(Lang::get('sync::pairing.identity_locked'));
})->group('PairingRefusal');

it('still names a locked identity as locked', function (): void {
    expect(refusalCopy()->identityUnavailable(DeviceIdentityState::Locked))
        ->toBe(Lang::get('sync::pairing.identity_locked'));
})->group('PairingRefusal');

it('still names a key-file no unlock can open', function (): void {
    expect(refusalCopy()->identityUnavailable(DeviceIdentityState::Unreadable))
        ->toBe(Lang::get('sync::devices.identity_unreadable'));
})->group('PairingRefusal');

it('refuses to invent a refusal for an identity that is usable', function (): void {
    // One read yields the identity and the state together, so this pairing is
    // impossible; a wrong sentence would be worse than a loud stop.
    expect(fn (): string => refusalCopy()->identityUnavailable(DeviceIdentityState::Usable))
        ->toThrow(LogicException::class);
})->group('PairingRefusal');

it('tells a reader with no sync identity what is actually missing', function (): void {
    // What the desktop pairing screen does on an install where sync was never
    // turned on: no key-file exists, so showMyCode() has nothing to issue.
    $user = User::query()->create([
        'username' => 'refusal-no-identity',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(PairingFlowModal::class)
        ->call('showMyCode')
        ->assertSet('flashMessage', Lang::get('sync::pairing.identity_absent'));
})->group('PairingRefusal');
