<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Desktop\Internal\Listeners\LockOnWindowHideOrClose;

// The tests run in a single-session context, so they pin the listener's
// behaviour against its injected session. Whether the NativePHP event request
// inside the Electron bundle carries the user window's session is unproven.
it('LockOnWindowHideOrClose listener class exists (RED until 05-06)', function (): void {
    expect(class_exists(LockOnWindowHideOrClose::class))->toBeTrue();
});

it('handling WindowHidden withholds the key and the session becomes locked', function (): void {
    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    AppLockTestHarness::unlock($session, $dataKey);
    expect($keyService->release($session))->not->toBeNull();

    /** @var LockOnWindowHideOrClose $listener */
    $listener = $this->app->make(LockOnWindowHideOrClose::class);

    // Any window-hide/close event object locks unconditionally.
    $listener->handle(new stdClass);

    expect($keyService->release($session))->toBeNull();
    expect($session->get(AppLockTestHarness::HELD_KEY_SESSION_KEY))->toBeNull();
    sodium_memzero($dataKey);
});
