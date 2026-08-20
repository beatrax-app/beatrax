<?php

declare(strict_types=1);

/*
 * The manual pairing-code arm on a phone, and why it is offered again.
 *
 * The submit button was `wire:click="submitCode"` with no argument, and
 * submitCode()'s first parameter is the scanned QR payload — a `?string`.
 * Livewire therefore tried to resolve it from the container and the request
 * died with a BindingResolutionException, 500, no message on screen. Typed
 * codes could not be submitted at all.
 *
 * The arm was then hidden from the import flow, because a typed code carries
 * the token alone and the desktop never learned the joining device's
 * identity. That left a fresh phone with a camera it could not use — or a
 * user who would not use one — no route in at all. The importing device now
 * asks the LAN for the public half the code cannot carry, so the arm can
 * finish rather than only ever error, and it is offered again.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;

uses(RefreshDatabase::class);

function manualArmUser(string $prefix): User
{
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function manualArmBlade(): string
{
    return (string) file_get_contents(
        base_path('Modules/Mobile/Resources/views/livewire/mobile-pairing-scan.blade.php')
    );
}

it('submits a typed code without asking the container for the QR payload', function (): void {
    $blade = manualArmBlade();

    expect($blade)->toContain('wire:click="submitCode(null)"')
        ->and($blade)->not->toContain('wire:click="submitCode"');
});

it('reads the import mode off the request that opened the screen', function (): void {
    $user = manualArmUser('armgate');
    test()->actingAs($user);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));
    Livewire::test(MobilePairingScan::class)->assertSet('importMode', false);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));
    Livewire::test(MobilePairingScan::class)->assertSet('importMode', true);
});

it('offers the typed-code arm while importing, not only outside import', function (): void {
    $blade = manualArmBlade();

    // Asserted on the blade because the arm lives on the `scan` step, and a
    // test never reaches it: with no native scanner the component falls
    // through to `enter_code` at mount. The conditional IS the behaviour.
    //
    // Walked as balanced directives rather than by the offset of the first
    // @unless, which said nothing about whether the arm was inside that one.
    $armAt = strpos($blade, 'wire:click="useWordCode"');
    expect($armAt)->not->toBeFalse('the typed-code control is gone entirely');

    $before = substr($blade, 0, (int) $armAt);
    preg_match_all('/@unless\s*\(([^)]*)\)|@endunless/', $before, $prior, PREG_SET_ORDER);

    $stack = [];
    foreach ($prior as $directive) {
        if (str_starts_with($directive[0], '@unless')) {
            $stack[] = trim($directive[1]);
        } else {
            array_pop($stack);
        }
    }

    expect(in_array('$importMode', $stack, true))->toBeFalse(
        'a phone whose camera is unusable must still have a route into the import',
    );
});

it('answers a typed code in import mode by looking for the other device on the network', function (): void {
    $user = manualArmUser('armlan');
    test()->actingAs($user);

    // Whatever the multicast question turns up on the machine running this,
    // no peer holds this token — so the arm must end in the "cannot reach
    // the other device" message, never a spinner and never a 500.
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    $wordCode = (new WordCodeEncoder)->encode(bin2hex(random_bytes(16)));

    Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', true)
        ->set('wordCode', $wordCode)
        ->call('submitCode', null)
        ->assertSet('step', 'enter_code')
        ->assertSet('pairingTokenId', '')
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.relay_unreachable'));
});

it('leaves no reference to the removed dead-end copy', function (): void {
    $en = require base_path('Modules/Mobile/Resources/lang/en/pairing.php');

    expect($en['errors'] ?? [])->not->toHaveKey('import_needs_qr');
});
