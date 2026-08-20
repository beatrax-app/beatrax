<?php

declare(strict_types=1);

/*
 * Two defects in the manual pairing-code arm, found by driving a wiped phone.
 *
 * The submit button was `wire:click="submitCode"` with no argument, and
 * submitCode()'s first parameter is the scanned QR payload — a `?string`.
 * Livewire therefore tried to resolve it from the container and the request
 * died with a BindingResolutionException, 500, no message on screen. Typed
 * codes could not be submitted at all.
 *
 * And in import mode the arm could never have worked even once the 500 was
 * fixed: a typed code carries the token alone, so the desktop never learns
 * the joining device's identity. The flow offered a route whose only possible
 * outcome was an error, so it is no longer offered.
 */

use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('submits a typed code without asking the container for the QR payload', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Mobile/Resources/views/livewire/mobile-pairing-scan.blade.php')
    );

    expect($blade)->toContain('wire:click="submitCode(null)"')
        ->and($blade)->not->toContain('wire:click="submitCode"');
});

it('reads the import mode off the request that opened the screen', function (): void {
    $user = User::query()->create([
        'username' => 'armgate-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));
    Livewire::test(MobilePairingScan::class)->assertSet('importMode', false);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));
    Livewire::test(MobilePairingScan::class)->assertSet('importMode', true);
});

it('keeps the typed-code arm inside the import-mode guard', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Mobile/Resources/views/livewire/mobile-pairing-scan.blade.php')
    );

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

    expect(in_array('$importMode', $stack, true))->toBeTrue(
        'the typed-code arm is offered in import mode, where it can only ever error',
    );
});

it('still refuses a typed code in import mode on the server', function (): void {
    $component = (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Http/Livewire/MobilePairingScan.php')
    );

    // A Livewire action is callable from the client whatever the UI renders,
    // so the guard outlives the button it used to explain.
    expect($component)->toContain('if ($this->importMode && $scannedPayload === null)')
        ->and($component)->toContain("Lang::get('mobile::pairing.errors.invalid_code')");
});

it('leaves no reference to the removed dead-end copy', function (): void {
    $en = require base_path('Modules/Mobile/Resources/lang/en/pairing.php');

    expect($en['errors'] ?? [])->not->toHaveKey('import_needs_qr');
});
