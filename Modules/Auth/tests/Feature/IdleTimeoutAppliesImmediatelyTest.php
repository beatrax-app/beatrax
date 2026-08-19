<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Core\Models\User;

/*
 * Choosing a longer auto-lock window did not take effect: the value was saved
 * correctly, but nothing that enforces it was told.
 *
 * The client's idle watcher reads window.beatraxIdleMs, which the layout emits
 * once at render — a Livewire action never re-renders that — and the
 * middleware caches the config in the session. So the lock kept firing on the
 * previous window, which reads exactly like "it did not save".
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'idle-'.bin2hex(random_bytes(4)),
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('user_app_lock_configs')->insert([
        'user_id' => $this->user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('persists the chosen window', function (): void {
    Livewire::test(AppLockSettingsSection::class)
        ->set('idleTimeoutMinutes', 30)
        ->call('setIdleTimeout');

    expect((int) DB::table('user_app_lock_configs')->where('user_id', $this->user->id)->value('idle_timeout_minutes'))
        ->toBe(30);
});

it('tells the client the new window, so the idle watcher stops using the old one', function (): void {
    Livewire::test(AppLockSettingsSection::class)
        ->set('idleTimeoutMinutes', 30)
        ->call('setIdleTimeout')
        ->assertDispatched('beatrax-idle-timeout-changed', ms: 30 * 60_000);
});

it('drops the middleware config cache so the server does not enforce the old window', function (): void {
    /** @var Session $session */
    $session = app(Session::class);
    $session->put(AppLockMiddleware::SESSION_CONFIG_CACHE, [
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'last_activity_at' => null,
        'cached_at' => time(),
    ]);

    Livewire::test(AppLockSettingsSection::class)
        ->set('idleTimeoutMinutes', 30)
        ->call('setIdleTimeout');

    expect($session->has(AppLockMiddleware::SESSION_CONFIG_CACHE))->toBeFalse();
});

it('confirms the change, because an instant-apply control that says nothing reads as broken', function (): void {
    Livewire::test(AppLockSettingsSection::class)
        ->set('idleTimeoutMinutes', 30)
        ->call('setIdleTimeout')
        ->assertDispatched('toast');
});

it('rejects a window that is not one of the offered presets', function (): void {
    Livewire::test(AppLockSettingsSection::class)
        ->set('idleTimeoutMinutes', 7)
        ->call('setIdleTimeout')
        ->assertHasErrors('idleTimeoutMinutes');

    expect((int) DB::table('user_app_lock_configs')->where('user_id', $this->user->id)->value('idle_timeout_minutes'))
        ->toBe(5);
});
