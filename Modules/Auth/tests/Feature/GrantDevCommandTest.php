<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Core\Models\User;

/*
 * beatrax:grant-dev CLI invariants (Phase 16 plan 04 Task 3).
 *
 * The CLI counterpart of the Dev Console's DESTRUCTIVE-tier
 * grant-dev action. Phase 12 auto-promotes the FIRST signed-up user
 * to developer; this command promotes any subsequently-added user
 * after the operator has decided to share Dev Console access (the
 * common case once a partner account exists).
 *
 * Idempotent: a re-grant against a user who is already a developer
 * reports it and exits SUCCESS without writing.
 */

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
