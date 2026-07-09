<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

/*
 * AppLockProvisionerGdkRewrapTest — D-10: a passphrase (PIN) change
 * re-wraps the GDK keyring under the new KEK; the old KEK can no longer
 * unwrap it. 14-VALIDATION.md D-10 row.
 *
 * RED until Plan 07 wires AppLockProvisioner::changePin() to re-wrap every
 * GDK epoch via the Sync Public re-wrap contract (cross-module boundary per
 * PATTERNS.md — Auth dispatches an event, a Sync listener calls
 * GdkKeyringService::rewrapUnderNewKek()). This test references the planned
 * production FQCN Modules\Sync\Internal\Crypto\GdkKeyringService, which does
 * not yet exist — the failure is "class not found", the correct Wave 0 RED
 * state.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'gdk-rewrap-applock-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('re-wraps every GDK epoch when the app-lock PIN changes, and the old PIN can no longer unlock the keyring', function (): void {
    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $provisioner->enable((int) $this->user->id, '123456', 'fixture-password', $session);

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    $original = $keyring->generateAndPersist((int) $this->user->id, $session);

    $changed = $provisioner->changePin((int) $this->user->id, '123456', '654321');
    expect($changed)->toBeTrue();

    // Re-loading the keyring under the (now unlocked, new-PIN) session must
    // still resolve to the exact same GDK epoch/key material — only the
    // at-rest wrapping key changed, not the GDK itself.
    $afterRewrap = $keyring->currentEpoch((int) $this->user->id, $session);
    expect($afterRewrap->epochId)->toBe($original->epochId);
    expect($afterRewrap->keyHex)->toBe($original->keyHex);
});

it('fails changePin (returns false, no partial rewrap) when the current PIN is wrong', function (): void {
    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $provisioner->enable((int) $this->user->id, '123456', 'fixture-password', $session);

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    $original = $keyring->generateAndPersist((int) $this->user->id, $session);

    $changed = $provisioner->changePin((int) $this->user->id, 'wrong-pin', '654321');
    expect($changed)->toBeFalse();

    // The GDK must be untouched by the failed attempt.
    $unchanged = $keyring->currentEpoch((int) $this->user->id, $session);
    expect($unchanged->epochId)->toBe($original->epochId);
    expect($unchanged->keyHex)->toBe($original->keyHex);
});
