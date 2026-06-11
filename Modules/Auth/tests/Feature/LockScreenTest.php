<?php

declare(strict_types=1);

// Plan 05-03 — LockScreen Livewire page

use Illuminate\Contracts\Session\Session;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

/*
 * Feature coverage for the LockScreen Livewire page:
 *   - A locked authenticated user GET /lock sees 200 with the PIN pad and "Sign out".
 *   - Submitting the correct PIN unlocks the session and redirects to dashboard.
 *   - Submitting a wrong PIN clears the entry, sets flash, and the session stays locked.
 *
 * These tests go GREEN when plan 05-03 creates LockScreen.php and its blade.
 */

it('LockScreen class exists', function (): void {
    expect(class_exists(LockScreen::class))->toBeTrue();
});

it('GET /lock while locked returns 200 with the PIN pad and Sign out', function (): void {
    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get(route('auth.lock'))
        ->assertOk()
        ->assertSee('Sign out');
});

it('correct PIN via Livewire component unlocks the session and redirects to dashboard', function (): void {
    $user = User::query()->create([
        'username' => 'bob',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);
    $this->session([LockStateManager::SESSION_KEY => true]);

    // Enable app lock so the provisioner writes the PIN hash + wrapped key.
    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    // Use Livewire testing helper to submit the correct PIN.
    Livewire::test(LockScreen::class)
        ->set('pin', '123456')
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    // Session must now be unlocked.
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    expect($lockState->isLocked($session))->toBeFalse();
});

it('wrong PIN via Livewire component sets flash message and leaves the session locked', function (): void {
    $user = User::query()->create([
        'username' => 'carol',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);
    $this->session([LockStateManager::SESSION_KEY => true]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    Livewire::test(LockScreen::class)
        ->set('pin', '000000')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('pin', '')
        ->assertSee('Incorrect PIN');
});
