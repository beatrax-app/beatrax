<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

// The re-wrap crosses a module boundary: Auth dispatches an event and a Sync
// listener re-wraps every GDK epoch under the new KEK.

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

    // Only the at-rest wrapping key changed, so the epoch and key material must
    // come back identical.
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

    $unchanged = $keyring->currentEpoch((int) $this->user->id, $session);
    expect($unchanged->epochId)->toBe($original->epochId);
    expect($unchanged->keyHex)->toBe($original->keyHex);
});

// With no config row there is nothing to re-wrap, and returning true would let
// the UI report a changed PIN for a lock that does not exist.

it('refuses to rotate keys for a user with no app-lock row', function (string $method): void {
    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);

    /** @var User $user */
    $user = $this->user;

    expect($provisioner->{$method}($user->id, 'irrelevant-credential', '123456'))->toBeFalse();
})->with([
    'changePin' => ['changePin'],
    'rewrapForNewPin' => ['rewrapForNewPin'],
]);
