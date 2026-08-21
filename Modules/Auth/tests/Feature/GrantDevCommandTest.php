<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Core\Models\User;

// Signup auto-promotes the first user; this command is how any later account
// gets Dev Console access once the operator decides to share it.

function makeGrantDevUser(string $username, bool $isDeveloper = false): User
{
    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    return User::query()->create([
        'username' => $username,
        'password' => $hasher->make('placeholder-password'),
        'period_start_day' => 1,
        'is_developer' => $isDeveloper,
    ]);
}

it('flips is_developer=true on a known non-developer user', function (): void {
    $user = makeGrantDevUser('partner', isDeveloper: false);

    expect($user->is_developer)->toBeFalse();

    $this->artisan('beatrax:grant-dev', ['username' => 'partner'])
        ->expectsOutputToContain('Granted developer to partner')
        ->assertSuccessful();

    expect($user->fresh()->is_developer)->toBeTrue();
});

it('exits non-zero for an unknown username', function (): void {
    $this->artisan('beatrax:grant-dev', ['username' => 'unknown-user'])
        ->expectsOutputToContain('User not found: unknown-user')
        ->assertFailed();
});

it('is idempotent — a re-grant on an existing developer succeeds without rewriting', function (): void {
    $user = makeGrantDevUser('already-dev', isDeveloper: true);

    $this->artisan('beatrax:grant-dev', ['username' => 'already-dev'])
        ->expectsOutputToContain('Already a developer: already-dev')
        ->assertSuccessful();

    expect($user->fresh()->is_developer)->toBeTrue();
});

it('case-normalises the username argument to lowercase before lookup', function (): void {
    makeGrantDevUser('lower-partner', isDeveloper: false);

    $this->artisan('beatrax:grant-dev', ['username' => 'LOWER-PARTNER'])
        ->expectsOutputToContain('Granted developer to lower-partner')
        ->assertSuccessful();
});
