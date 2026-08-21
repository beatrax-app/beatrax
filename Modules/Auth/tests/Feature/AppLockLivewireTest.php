<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;

it('AppLockMiddleware is registered as Livewire persistent middleware (RED until 05-02)', function (): void {
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();

    $persistentMiddleware = Livewire::getPersistentMiddleware();
    expect($persistentMiddleware)->toContain(AppLockMiddleware::class);
});
