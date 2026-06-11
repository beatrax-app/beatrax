<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-06

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Desktop\Internal\Listeners\LockOnWindowHideOrClose;

/*
 * Feature coverage for the desktop window-hide lock trigger (D-06):
 * when the NativePHP WindowHidden event fires, AppLockKeyService->withhold()
 * is called and the session becomes locked.
 *
 * Scope caveat (WR-09): these tests run in a single-session test context, so
 * they verify the LISTENER's behavior against its injected session. Whether
 * the NativePHP event request inside the Electron bundle carries the user
 * window's session is an open runtime question documented on the listener.
 */

it('LockOnWindowHideOrClose listener class exists (RED until 05-06)', function (): void {
    expect(class_exists(LockOnWindowHideOrClose::class))->toBeTrue();
});

it('handling WindowHidden withholds the key and the session becomes locked', function (): void {
    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // Start from an unlocked session holding a data key. The session keys are
    // written literally — this Desktop test must not import Auth Internal
    // symbols (BoundaryArchTest: Auth\Internal is only used inside Auth).
    $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $session->put('beatrax_locked', false);
    $session->put('beatrax_data_key', $dataKey);
    expect($keyService->release($session))->not->toBeNull();

    /** @var LockOnWindowHideOrClose $listener */
    $listener = $this->app->make(LockOnWindowHideOrClose::class);

    // Any window-hide/close event object locks unconditionally.
    $listener->handle(new stdClass);

    // Observable outcome: the session is locked and the key is withheld.
    expect($keyService->release($session))->toBeNull();
    expect($session->get('beatrax_data_key'))->toBeNull();
    sodium_memzero($dataKey);
});
