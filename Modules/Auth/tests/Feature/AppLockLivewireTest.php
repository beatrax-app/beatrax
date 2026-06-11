<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-02

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;

/*
 * Feature coverage for AppLockMiddleware applied as Livewire persistent
 * middleware: a /livewire/update request from a locked session must be
 * gated (the middleware is registered via Livewire::addPersistentMiddleware).
 *
 * This test goes GREEN when plan 05-02 registers AppLockMiddleware via
 * Livewire::addPersistentMiddleware() in AuthServiceProvider::boot().
 */

it('AppLockMiddleware is registered as Livewire persistent middleware (RED until 05-02)', function (): void {
    // This will fail RED because AppLockMiddleware doesn't exist yet AND
    // is not registered as persistent middleware.
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();

    $persistentMiddleware = Livewire::getPersistentMiddleware();
    expect($persistentMiddleware)->toContain(AppLockMiddleware::class);
});
