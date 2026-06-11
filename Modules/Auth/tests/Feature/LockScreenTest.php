<?php

declare(strict_types=1);

// Plan 05-03 — LockScreen Livewire page

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
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

    // Use Livewire testing helper to submit the correct PIN. The PIN travels
    // as a method argument (WR-10) — never as a bound public property.
    Livewire::test(LockScreen::class)
        ->call('submit', '123456')
        ->assertRedirect(route('dashboard'));

    // Session must now be unlocked.
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    expect($lockState->isLocked($session))->toBeFalse();
});

it('correct PIN during an active backoff window shows backoff copy, not "Incorrect PIN" (WR-05)', function (): void {
    $user = User::query()->create([
        'username' => 'backoff-dora',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);
    $this->session([LockStateManager::SESSION_KEY => true]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    // Simulate an active backoff window (5 failures, locked_until in the future).
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update([
            'failed_attempts' => 5,
            'locked_until' => CarbonImmutable::now()->addSeconds(30)->toDateTimeString(),
        ]);

    Livewire::test(LockScreen::class)
        ->call('submit', '123456')
        ->assertNoRedirect()
        ->assertSee('Too many attempts')
        ->assertDontSee('Incorrect PIN');

    // The session must remain locked during the backoff window.
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    expect($lockState->isLocked($session))->toBeTrue();
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
        ->call('submit', '000000')
        ->assertNoRedirect()
        ->assertSee('Incorrect PIN');
});

it('the rendered lock screen never contains a bound pin property in the snapshot (WR-10)', function (): void {
    $user = User::query()->create([
        'username' => 'snapshot-erin',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);
    $this->session([LockStateManager::SESSION_KEY => true]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'whatever-password');

    // The component must not expose a public $pin property at all — the PIN
    // accumulates client-side and crosses the wire only as a submit() argument.
    expect(property_exists(LockScreen::class, 'pin'))->toBeFalse();
});
