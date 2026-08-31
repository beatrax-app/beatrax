<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

// The lock screen's own "forgot your PIN" copy sends the reader to sign out and
// back in with the account password. Doing exactly that used to leave the
// failure meter at the cap that signed them out, so the next mistyped digit
// signed them out again and the screen said they had none left.

function pinMeterUser(): User
{
    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    return User::query()->create([
        'username' => 'meter-'.bin2hex(random_bytes(4)),
        'password' => $hasher->make('account-password-12'),
        'period_start_day' => 1,
    ]);
}

it('clears a spent PIN failure meter when the account password signs the reader back in', function (): void {
    $user = pinMeterUser();
    $this->actingAs($user);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'account-password-12');

    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['failed_attempts' => 12, 'locked_until' => null]);

    $provisioner->primeSessionAfterLogin($user->id, 'account-password-12', $session);

    $row = DB::connection()->table('user_app_lock_configs')->where('user_id', $user->id)->first();

    expect((int) $row->failed_attempts)->toBe(0)
        ->and($row->locked_until)->toBeNull();
});

it('gives the reader their attempts back rather than telling them nought remain', function (): void {
    $user = pinMeterUser();
    $this->actingAs($user);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'account-password-12');

    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['failed_attempts' => 12]);

    $provisioner->primeSessionAfterLogin($user->id, 'account-password-12', $session);

    $session->put(LockStateManager::SESSION_KEY, true);

    Livewire::test(LockScreen::class)
        ->call('submit', '999999')
        ->assertSet('flashMessage', 'Incorrect PIN. 9 attempts remaining.');
});

it('clears the meter even when the account password no longer opens the recovery wrap', function (): void {
    $user = pinMeterUser();
    $this->actingAs($user);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'account-password-12');

    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['failed_attempts' => 12]);

    // A stale wrap starts the session locked, which is the state that most
    // needs the meter cleared: the PIN screen is the only way on from here.
    $provisioner->primeSessionAfterLogin($user->id, 'a-different-password', $session);

    expect((int) DB::connection()->table('user_app_lock_configs')->where('user_id', $user->id)->value('failed_attempts'))
        ->toBe(0);
});
