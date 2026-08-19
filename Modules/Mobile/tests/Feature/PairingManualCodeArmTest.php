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

it('submits a typed code without asking the container for the QR payload', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Mobile/Resources/views/livewire/mobile-pairing-scan.blade.php')
    );

    expect($blade)->toContain('wire:click="submitCode(null)"')
        ->and($blade)->not->toContain('wire:click="submitCode"');
});

it('gates the typed-code arm on import mode', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Mobile/Resources/views/livewire/mobile-pairing-scan.blade.php')
    );

    // The arm cannot complete an import, so it is not offered there. Asserted
    // on the blade because $importMode is #[Locked] — a test cannot set it,
    // and the conditional IS the behaviour.
    expect($blade)->toContain('@unless ($importMode)')
        ->and($blade)->toContain('wire:click="useWordCode"');

    $armStart = strpos($blade, '@unless ($importMode)');
    $armEnd = strpos($blade, '@endunless');
    expect($armEnd)->toBeGreaterThan($armStart);
    expect(substr($blade, $armStart, $armEnd - $armStart))->toContain('useWordCode');
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
