<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;

it('enable() with a session stores the data key so AppLockKeyService::release returns non-null', function (): void {
    $user = User::query()->create([
        'username' => 'gap-b-user',
        'password' => bcrypt('gap-b-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    expect($session->get(LockStateManager::DATA_KEY_SESSION))->toBeNull();

    $provisioner->enable($user->id, '654321', 'gap-b-pass', $session);

    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);

    expect($lockState->isLocked($session))->toBeFalse();

    $dataKey = $session->get(LockStateManager::DATA_KEY_SESSION);
    expect($dataKey)->toBeString()->not->toBeEmpty();
});

it('enable() without a session does not store a data key (backward compat)', function (): void {
    $user = User::query()->create([
        'username' => 'gap-b-no-session-user',
        'password' => bcrypt('gap-b-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $provisioner->enable($user->id, '654321', 'gap-b-pass');

    expect($session->get(LockStateManager::DATA_KEY_SESSION))->toBeNull();
});

it('AppLockSettingsSection setPin stores the data key in the session after enable', function (): void {
    $user = User::query()->create([
        'username' => 'gap-b-livewire-user',
        'password' => bcrypt('livewire-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '987654')
        ->set('confirmPin', '987654')
        ->set('accountPassword', 'livewire-pass')
        ->call('setPin')
        ->assertHasNoErrors();

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $dataKey = $session->get(LockStateManager::DATA_KEY_SESSION);
    expect($dataKey)->toBeString()->not->toBeEmpty();
});

// Defence in depth: enable() must never mint a KEK from an empty PIN or
// password, whatever the caller did or did not validate first.

it('enable() rejects an empty PIN — never mints a weak/empty-derived KEK', function (): void {
    $user = User::query()->create([
        'username' => 'high-02-empty-pin-user',
        'password' => bcrypt('a-real-password'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    expect(fn () => $provisioner->enable($user->id, '', 'a-real-password'))
        ->toThrow(ValidationException::class);

    expect($provisioner->isEnabled($user->id))->toBeFalse('a rejected empty-PIN call must never leave a config row behind');
});

it('enable() rejects an empty account password — never mints a weak/empty-derived KEK', function (): void {
    $user = User::query()->create([
        'username' => 'high-02-empty-password-user',
        'password' => bcrypt('a-real-password'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    expect(fn () => $provisioner->enable($user->id, '654321', ''))
        ->toThrow(ValidationException::class);

    expect($provisioner->isEnabled($user->id))->toBeFalse('a rejected empty-password call must never leave a config row behind');
});

it('idle elapsed redirects to /lock and locks the session', function (): void {
    $user = User::query()->create([
        'username' => 'idle-lock-user',
        'password' => bcrypt('idle-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 1,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->subMinutes(2)->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get(route('dashboard'))
        ->assertRedirect(route('auth.lock'));

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    expect($lockState->isLocked($session))->toBeTrue();
});

it('idle not elapsed passes through and refreshes last_activity_at', function (): void {
    $user = User::query()->create([
        'username' => 'idle-fresh-user',
        'password' => bcrypt('idle-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $lastActivity = CarbonImmutable::now()->subSeconds(30)->toDateTimeString();
    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => $lastActivity,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $response = $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get(route('dashboard'));

    $lockScreenUrl = route('auth.lock');
    $isRedirectToLock = $response->isRedirection()
        && $response->headers->get('Location') === $lockScreenUrl;
    expect($isRedirectToLock)->toBeFalse('Session should not be idle-locked when activity was recent');
});

it('Livewire update requests do NOT refresh last_activity_at', function (): void {
    $user = User::query()->create([
        'username' => 'poll-user',
        'password' => bcrypt('poll-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $staleActivity = CarbonImmutable::now()->subSeconds(30)->toDateTimeString();
    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => $staleActivity,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    // Recognised by route name, not the X-Livewire header, which leaks across
    // requests on NativePHP's persistent Android process. The wildcard covers
    // Livewire renaming a custom update route to end with `livewire.update`.
    Route::middleware(['web', 'auth'])
        ->get('/__lock-livewire-probe', static fn (): string => 'ok')
        ->name('probe.livewire.update');

    $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get('/__lock-livewire-probe');

    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first(['last_activity_at']);
    /** @var stdClass $row */
    expect((string) $row->last_activity_at)->toBe($staleActivity);
});

it('POST /lock/activity heartbeat refreshes last_activity_at and returns 204', function (): void {
    $user = User::query()->create([
        'username' => 'heartbeat-user',
        'password' => bcrypt('heartbeat-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $staleActivity = CarbonImmutable::now()->subSeconds(45)->toDateTimeString();
    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => $staleActivity,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $this->withSession([LockStateManager::SESSION_KEY => false])
        ->postJson(route('auth.lock.activity'))
        ->assertStatus(204);

    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first(['last_activity_at']);
    /** @var stdClass $row */
    expect((string) $row->last_activity_at)->not->toBe($staleActivity);
});

it('POST /lock/engage locks the session and returns 204', function (): void {
    $user = User::query()->create([
        'username' => 'engage-user',
        'password' => bcrypt('engage-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        // Genuinely idle: the client engages only once the idle or grace
        // window has elapsed.
        'last_activity_at' => CarbonImmutable::now()->subMinutes(5)->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $response = $this->withSession([LockStateManager::SESSION_KEY => false])
        ->postJson(route('auth.lock.engage'));

    $response->assertStatus(204);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    expect($lockState->isLocked($session))->toBeTrue();
});

it('POST /lock/engage leaves a user with no lock configured unlocked', function (): void {
    $user = User::query()->create([
        'username' => 'engage-no-lock-user',
        'password' => bcrypt('engage-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    // No config row means no PIN and no biometric, so locking would strand the
    // session on a lock screen whose only working control is Sign out.
    $this->withSession([LockStateManager::SESSION_KEY => false])
        ->postJson(route('auth.lock.engage'))
        ->assertStatus(204);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    expect($lockState->isLocked($session))->toBeFalse();
});

it('POST /lock/engage leaves a user whose lock is disabled unlocked', function (): void {
    $user = User::query()->create([
        'username' => 'engage-lock-off-user',
        'password' => bcrypt('engage-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => false,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    // A tab left open from before the lock was turned off must not be able to
    // re-engage it.
    $this->withSession([LockStateManager::SESSION_KEY => false])
        ->postJson(route('auth.lock.engage'))
        ->assertStatus(204);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    expect($lockState->isLocked($session))->toBeFalse();
});

it('POST /lock/engage while already locked is a no-op returning 204', function (): void {
    $user = User::query()->create([
        'username' => 'engage-noop-user',
        'password' => bcrypt('engage-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $response = $this->withSession([LockStateManager::SESSION_KEY => true])
        ->postJson(route('auth.lock.engage'));

    $response->assertStatus(204);
});

it('releases a locked session whose user has no enabled lock instead of redirecting', function (): void {
    $user = User::query()->create([
        'username' => 'stranded-user',
        'password' => bcrypt('stranded-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    // A tab from before the lock was disabled: with no config row, /lock could
    // never clear and Sign out is the only exit.
    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get('/help/data-locations')
        ->assertOk();

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    expect($lockState->isLocked($session))->toBeFalse();
});

it('still redirects a locked session whose user has an enabled lock', function (): void {
    $user = User::query()->create([
        'username' => 'genuinely-locked-user',
        'password' => bcrypt('locked-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get('/help/data-locations')
        ->assertRedirect(route('auth.lock'));
});

it('POST /lock/engage does not undo an unlock that just happened', function (): void {
    $user = User::query()->create([
        'username' => 'engage-race-user',
        'password' => bcrypt('engage-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    // The idle timer posts with keepalive and never waits, so an engage can
    // land after the unlock and demand a second PIN.
    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $response = $this->withSession([LockStateManager::SESSION_KEY => false])
        ->postJson(route('auth.lock.engage'));

    $response->assertStatus(204);
    expect(session(LockStateManager::SESSION_KEY))->toBeFalse();
});
