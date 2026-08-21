<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;

uses(RefreshDatabase::class);

// The field advertised four groups while every code the desktop hands out is
// seven, so a reader typing a real one had every reason to believe they were
// copying the wrong value — beside an error that blames their network when the
// code is rejected.

// Derived, not written out: a 16-byte token in RFC 4648 base-32 is five bits a
// character, grouped in fours — seven groups, the last of two. A change to the
// token size fails here instead of shipping another mask that lies.
function pairingCodeMask(): string
{
    $characters = (int) ceil(16 * 8 / 5);

    return implode('-', str_split(str_repeat('X', $characters), 4));
}

it('shows a mask the shape of a code the other device actually hands out', function (): void {
    $user = User::query()->create([
        'username' => 'placeholder-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));

    Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'enter_code')
        ->assertSee('placeholder="'.pairingCodeMask().'"', false);
});

it('caps the field at the length of a whole code', function (): void {
    $user = User::query()->create([
        'username' => 'placeholder-cap-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));

    Livewire::test(MobilePairingScan::class)
        ->assertSee('maxlength="'.strlen(pairingCodeMask()).'"', false);
});
