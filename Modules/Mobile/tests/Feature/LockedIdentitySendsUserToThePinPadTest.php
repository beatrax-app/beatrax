<?php

declare(strict_types=1);

/*
 * A locked app-lock is the one pairing failure the user can actually fix, and
 * the flow used to be a dead end about it: the confirm button answered "Your
 * device identity is locked. Unlock the app and try again." and nothing on
 * that screen opened the PIN pad. Driving a real iPhone, the only way through
 * was to type the lock URL by hand — which a user cannot do.
 *
 * Every locked branch now redirects to the lock screen, so the advice and the
 * means arrive together.
 */

it('routes every locked-identity branch to the lock screen', function (): void {
    $component = (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Http/Livewire/MobilePairingScan.php')
    );

    expect($component)->toContain('private function sendToUnlock(')
        ->and($component)->toContain("route('mobile.lock')");

    // The message alone must not remain as a terminal outcome anywhere: the
    // only place it is set is inside the helper that also redirects.
    $flashes = preg_match_all(
        "/flashMessage = Lang::get\('mobile::pairing\.errors\.identity_locked'\)/",
        $component
    );

    expect($flashes)->toBe(1, 'identity_locked is still set somewhere that does not open the PIN pad');
});

it('still tells the user why they were sent there', function (): void {
    $component = (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Http/Livewire/MobilePairingScan.php')
    );

    // Redirecting silently would be its own dead end — the reader would land
    // on a PIN pad with no idea what asked for it.
    $helper = substr(
        $component,
        (int) strpos($component, 'private function sendToUnlock('),
        400
    );

    expect($helper)->toContain('identity_locked')
        ->and($helper)->toContain('redirect');
});

it('keeps a lock route to send them to', function (): void {
    $routes = (string) file_get_contents(base_path('Modules/Mobile/Routes/web.php'));

    expect($routes)->toContain("name('mobile.lock')");
});
