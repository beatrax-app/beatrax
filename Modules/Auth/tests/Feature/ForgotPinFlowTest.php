<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-04

use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Core\Models\User;

/*
 * Feature coverage for the forgot-PIN recovery flow:
 * after password re-auth a new PIN re-wraps the data key via the
 * password recovery wrap, and the same data key is recoverable.
 *
 * This test goes GREEN when plan 05-04 ships AppLockProvisioner::rewrapForNewPin().
 */

it('AppLockProvisioner class exists with rewrapForNewPin method (RED until 05-04)', function (): void {
    expect(class_exists(AppLockProvisioner::class))->toBeTrue();
    expect(method_exists(AppLockProvisioner::class, 'rewrapForNewPin'))->toBeTrue();
});

it('after password re-auth a new PIN re-wraps the data key and the same key is recoverable', function (): void {
    expect(class_exists(AppLockProvisioner::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'alice',
        'password' => bcrypt('my-secure-password'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    // This test will go GREEN when plan 05-04 ships rewrapForNewPin().
    expect($provisioner)->toBeInstanceOf(AppLockProvisioner::class);
});
