<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Core\Models\User;

/*
 * The desktop vault answers isEnrolled() from a file keyed on nothing but the
 * user id. A database reset or restore behind it leaves that file in place, so
 * the next account to take the id was offered Touch ID it never enrolled —
 * and the key behind it opened no keyring of that account's, which surfaced as
 * a raw BackupDecryptionException on every screen.
 *
 * `cold_start_biometric_enrolled` is the record of the enrolment against the
 * account. The mobile vault already answers from it; the lock screen now
 * requires both, so material that outlived its row cannot be offered.
 */

function coldStartOrphanUser(bool $flag): User
{
    $user = User::query()->create([
        'username' => 'coldstart-orphan-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('coldstart-orphan-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'cold_start_biometric_enrolled' => $flag,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return $user;
}

/**
 * A vault that reports enrolled — standing in for material left on disk.
 */
function coldStartVaultReporting(bool $enrolled): ColdStartVault
{
    $vault = Mockery::mock(ColdStartVault::class);
    $vault->shouldReceive('isAvailable')->andReturn(true);
    $vault->shouldReceive('isEnrolled')->andReturn($enrolled);

    return $vault;
}

it('does not offer the native unlock for an enrolment the account never recorded', function (): void {
    $this->app->instance(ColdStartVault::class, coldStartVaultReporting(true));

    $user = coldStartOrphanUser(flag: false);

    Livewire::actingAs($user)
        ->test(LockScreen::class)
        ->assertSet('nativeUnlockAvailable', false);
});

it('offers the native unlock when the account did record it', function (): void {
    $this->app->instance(ColdStartVault::class, coldStartVaultReporting(true));

    $user = coldStartOrphanUser(flag: true);

    Livewire::actingAs($user)
        ->test(LockScreen::class)
        ->assertSet('nativeUnlockAvailable', true);
});
