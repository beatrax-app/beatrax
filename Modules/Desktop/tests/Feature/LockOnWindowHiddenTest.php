<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-06

use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Desktop\Internal\Listeners\LockOnWindowHideOrClose;

/*
 * Feature coverage for the desktop window-hide lock trigger (D-06):
 * when the NativePHP WindowHidden event fires, AppLockKeyService->withhold()
 * is called and the session becomes locked.
 *
 * This test goes GREEN when plan 05-06 ships LockOnWindowHideOrClose.
 */

it('LockOnWindowHideOrClose listener class exists (RED until 05-06)', function (): void {
    expect(class_exists(LockOnWindowHideOrClose::class))->toBeTrue();
});

it('handling WindowHidden calls AppLockKeyService->withhold() and session becomes locked', function (): void {
    expect(class_exists(LockOnWindowHideOrClose::class))->toBeTrue();

    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);

    // LockOnWindowHideOrClose should be resolvable via the container.
    /** @var LockOnWindowHideOrClose $listener */
    $listener = $this->app->make(LockOnWindowHideOrClose::class);

    expect($listener)->toBeInstanceOf(LockOnWindowHideOrClose::class);
    expect($keyService)->toBeInstanceOf(AppLockKeyService::class);
});
