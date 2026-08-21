<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
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
