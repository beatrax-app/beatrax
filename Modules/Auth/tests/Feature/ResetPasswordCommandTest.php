<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

it('updates the password and flags a forced change on a valid interactive run', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'owner',
        'password' => $hasher->make('original-password-12'),
        'period_start_day' => 1,
        'force_password_change_at_next_login' => false,
    ]);

    $this->artisan('beatrax:reset-password', ['username' => 'owner'])
        ->expectsQuestion('New password', 'a-brand-new-password')
        ->expectsQuestion('Confirm new password', 'a-brand-new-password')
        ->expectsOutputToContain('Password updated for owner.')
        ->assertSuccessful();

    $fresh = $user->fresh();
    expect($hasher->check('a-brand-new-password', $fresh->password))->toBeTrue();
    expect($fresh->force_password_change_at_next_login)->toBeTrue();
});

it('exits non-zero for an unknown username', function (): void {
    $this->artisan('beatrax:reset-password', ['username' => 'ghost'])
        ->expectsOutputToContain('No user with that username.')
        ->assertFailed();
});

it('rejects a new password shorter than twelve characters', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'owner',
        'password' => $hasher->make('original-password-12'),
        'period_start_day' => 1,
    ]);

    $this->artisan('beatrax:reset-password', ['username' => 'owner'])
        ->expectsQuestion('New password', 'short')
        ->expectsQuestion('Confirm new password', 'short')
        ->assertFailed();

    expect($hasher->check('original-password-12', $user->fresh()->password))->toBeTrue();
});

it('rejects a confirmation that does not match the new password', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'owner',
        'password' => $hasher->make('original-password-12'),
        'period_start_day' => 1,
    ]);

    $this->artisan('beatrax:reset-password', ['username' => 'owner'])
        ->expectsQuestion('New password', 'a-brand-new-password')
        ->expectsQuestion('Confirm new password', 'a-different-password')
        ->assertFailed();

    expect($hasher->check('original-password-12', $user->fresh()->password))->toBeTrue();
});

it('refuses non-interactive invocation and changes nothing', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'owner',
        'password' => $hasher->make('original-password-12'),
        'period_start_day' => 1,
    ]);

    // The command must refuse rather than fall through to an empty password.
    $this->artisan('beatrax:reset-password', ['username' => 'owner', '--no-interaction' => true])
        ->assertFailed();

    expect($hasher->check('original-password-12', $user->fresh()->password))->toBeTrue();
});

// The lock screen offers this road by name — "sign back in with your account
// password and set a new PIN" — and a CLI reset is the one path that leaves no
// old password to re-wrap the recovery blob with.
it('stamps the app-lock recovery wrap it can no longer carry over', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'owner',
        'password' => $hasher->make('original-password-12'),
        'period_start_day' => 1,
    ]);

    $connection = $this->app->make(DatabaseManager::class)->connection();

    $connection->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'kdf_salt' => 'salt-bytes',
        'pin_wrapped_key' => 'pin-wrapped',
        'password_wrapped_key' => 'password-wrapped',
        'lock_enabled' => true,
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $this->artisan('beatrax:reset-password', ['username' => 'owner'])
        ->expectsQuestion('New password', 'a-brand-new-password')
        ->expectsQuestion('Confirm new password', 'a-brand-new-password')
        ->assertSuccessful();

    $stale = $connection->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->value('password_wrap_stale_at');

    expect($stale)->not->toBeNull();
});
