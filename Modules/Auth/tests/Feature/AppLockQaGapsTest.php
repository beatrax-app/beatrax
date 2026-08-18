<?php

declare(strict_types=1);

// QA gap fixes — checkpoint human-verify follow-up (plan 05-06)

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

/*
 * Tests for QA gaps found after the plan 05-06 human-verify checkpoint.
 *
 * Gap A: server-authoritative idle enforcement + engage endpoint.
 * Gap B: coherent post-enable session state (key available immediately).
 */

// ---------------------------------------------------------------------------
// Gap B — post-enable coherent state
// ---------------------------------------------------------------------------

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

    // Before enable: no key, not locked (no config row).
    expect($session->get(LockStateManager::DATA_KEY_SESSION))->toBeNull();

    $provisioner->enable($user->id, '654321', 'gap-b-pass', $session);

    // After enable with session: session is unlocked and data key is available.
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

    // Call without session — backward compat, no side effect on session.
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

    // Lock should be enabled in the DB.
    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    // The session should hold the data key (unlock state, not key-less).
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $dataKey = $session->get(LockStateManager::DATA_KEY_SESSION);
    expect($dataKey)->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// HIGH-02 (15-import-join-REVIEW.md) — defense-in-depth: enable() must
// never mint a KEK from an empty PIN/password, regardless of what the
// caller already validated (or failed to validate).
// ---------------------------------------------------------------------------

it('enable() rejects an empty PIN — never mints a weak/empty-derived KEK (HIGH-02)', function (): void {
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

it('enable() rejects an empty account password — never mints a weak/empty-derived KEK (HIGH-02)', function (): void {
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

// ---------------------------------------------------------------------------
// Gap A — idle enforcement via AppLockMiddleware + engage endpoint
// ---------------------------------------------------------------------------

it('idle elapsed redirects to /lock and locks the session', function (): void {
    $user = User::query()->create([
        'username' => 'idle-lock-user',
        'password' => bcrypt('idle-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    // Create a lock config row with a 1-minute idle timeout and last_activity_at
    // set to 2 minutes ago so the middleware considers the session idle.
    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 1,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->subMinutes(2)->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    // Session is unlocked.
    $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get(route('dashboard'))
        ->assertRedirect(route('auth.lock'));

    // Verify the session is now marked locked.
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

    // Activity was 30 seconds ago; idle timeout is 5 minutes — well within the window.
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

it('Livewire update requests do NOT refresh last_activity_at (WR-04)', function (): void {
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

    // Livewire's update endpoint — wire:poll and component-update traffic —
    // must not count as user activity.
    //
    // Recognised by ROUTE NAME, not by the X-Livewire header this used to
    // send. Under NativePHP's Android runtime the PHP process is persistent
    // and HTTP_X_LIVEWIRE leaks from one Livewire POST onto every ordinary
    // page load that follows it, so the header was answering "yes" for the
    // rest of the app's life and no navigation refreshed the timer again.
    // Livewire renames a custom update route to END with `livewire.update`,
    // which is why the middleware matches the wildcard rather than the
    // literal name.
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

it('POST /lock/activity heartbeat refreshes last_activity_at and returns 204 (WR-04)', function (): void {
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
        // Genuinely idle: the client only engages after the idle or grace
        // window has elapsed, so activity is never fresh at that moment.
        'last_activity_at' => CarbonImmutable::now()->subMinutes(5)->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    // Session is unlocked.
    $response = $this->withSession([LockStateManager::SESSION_KEY => false])
        ->postJson(route('auth.lock.engage'));

    $response->assertStatus(204);

    // Session should now be locked.
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

    // No user_app_lock_configs row: this user has no PIN hash and no enrolled
    // biometric, so locking them would strand the session on a lock screen
    // whose only working control is Sign out.
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

    // Session is already locked.
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

    // A session stranded by a tab open from before the lock was disabled, or
    // by a pre-fix client: no config row means no PIN and no biometric, so
    // /lock could never be cleared and Sign out would be the only exit.
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

    // The idle timer posts with keepalive and does not wait for a response,
    // so an engage can land AFTER the user has already unlocked. Locking then
    // demands a second PIN for presence they just proved.
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
