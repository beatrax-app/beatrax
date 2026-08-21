<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;

function regenUser(string $username): User
{
    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    return User::query()->create([
        'username' => $username,
        'password' => $hasher->make('placeholder-password'),
        'period_start_day' => 1,
    ]);
}

function seedRecoveryCodes(User $user, int $count = 10): void
{
    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    for ($i = 0; $i < $count; $i++) {
        UserRecoveryCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => $hasher->make('OLD-CODE-'.$i.'-'.bin2hex(random_bytes(4))),
            'used_at' => null,
        ]);
    }
}

it('regenerates 10 fresh codes and burns the existing unused ones', function (): void {
    $user = regenUser('rotator');
    seedRecoveryCodes($user, 10);

    expect(UserRecoveryCode::query()
        ->where('user_id', $user->id)
        ->whereNull('used_at')
        ->count())->toBe(10);

    $this->artisan('beatrax:regenerate-recovery-codes', ['username' => 'rotator'])
        ->expectsOutputToContain('Regenerated rotator recovery codes')
        ->assertSuccessful();

    $stillUnusedFromOld = UserRecoveryCode::query()
        ->where('user_id', $user->id)
        ->whereNull('used_at')
        ->count();
    expect($stillUnusedFromOld)->toBe(10);

    // Twenty rows in total: the old ten survive, stamped used.
    expect(UserRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(20);
});

it('exits non-zero for an unknown username', function (): void {
    $this->artisan('beatrax:regenerate-recovery-codes', ['username' => 'ghost'])
        ->expectsOutputToContain('User not found: ghost')
        ->assertFailed();
});

it('issues 10 cryptographically distinct hyphenated codes on a user with no prior history', function (): void {
    regenUser('fresh-user');

    $this->artisan('beatrax:regenerate-recovery-codes', ['username' => 'fresh-user'])
        ->assertSuccessful();

    $user = User::query()->where('username', 'fresh-user')->firstOrFail();

    $codes = UserRecoveryCode::query()
        ->where('user_id', $user->id)
        ->whereNull('used_at')
        ->get();

    expect($codes)->toHaveCount(10);
    foreach ($codes as $code) {
        expect($code->code_hash)->toStartWith('$2y$');
    }
});

it('case-normalises the username argument', function (): void {
    regenUser('mixed-case-user');

    $this->artisan('beatrax:regenerate-recovery-codes', ['username' => 'MIXED-CASE-User'])
        ->expectsOutputToContain('Regenerated mixed-case-user')
        ->assertSuccessful();
});
