<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// A locked phone could reach the pairing screen, type a live code and submit
// it, and only then be shown the PIN pad. The token is single-use with a
// ten-minute life and only one exists at a time, so that submit spent it on an
// operation the sealed identity made impossible.

function pairingUnlockUser(string $prefix): User
{
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function pairingUnlockLockRow(int $userId, bool $enabled): void
{
    DB::table('user_app_lock_configs')->insert([
        'user_id' => $userId,
        'lock_enabled' => $enabled,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// Only existence is read on the redirect path, so the bytes never matter: a
// sealed identity is one the loader can see and cannot open.
function pairingUnlockSealedIdentity(int $userId): void
{
    $path = UserDataPathService::appPath("sync/identity/{$userId}.enc");
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, 'sealed');
}

it('sends a locked reader to the PIN pad instead of letting the code field take a code', function (): void {
    $user = pairingUnlockUser('pair-locked');
    test()->actingAs($user);
    pairingUnlockLockRow((int) $user->id, true);
    pairingUnlockSealedIdentity((int) $user->id);

    $response = test()
        ->withSession([AppLockTestHarness::LOCKED_SESSION_KEY => true])
        ->get('/mobile/pair?mode=import');

    $response->assertRedirect(route('mobile.lock'));
    $response->assertSessionHas(
        MobilePairingScan::LOCKED_IDENTITY_FLASH,
        Lang::get('mobile::pairing.errors.identity_locked'),
    );
});

it('returns an importing device to the arm it was on once it unlocks', function (): void {
    $user = pairingUnlockUser('pair-locked-import');
    test()->actingAs($user);
    pairingUnlockLockRow((int) $user->id, true);
    pairingUnlockSealedIdentity((int) $user->id);

    test()
        ->withSession([AppLockTestHarness::LOCKED_SESSION_KEY => true])
        ->get('/mobile/pair?mode=import');

    $intended = session(MobileLockGateway::SESSION_INTENDED_URL);

    expect($intended)->toBeString()
        ->and($intended)->toContain('/mobile/pair')
        ->and($intended)->toContain('mode=import');
});

// The fresh-device import chain has no identity to seal yet and mints one at
// submit, so a redirect here would block the only path that can create it.
it('lets a device that has no identity of its own through', function (): void {
    $user = pairingUnlockUser('pair-no-identity');
    test()->actingAs($user);
    pairingUnlockLockRow((int) $user->id, true);

    test()
        ->withSession([AppLockTestHarness::LOCKED_SESSION_KEY => true])
        ->get('/mobile/pair')
        ->assertOk();
});

// Without a lock the identity is unreadable because none was ever set up, and
// the PIN-only lock screen would be a dead end.
it('does not send a user with no app lock to a PIN pad they cannot use', function (): void {
    $user = pairingUnlockUser('pair-no-lock');
    test()->actingAs($user);
    pairingUnlockLockRow((int) $user->id, false);
    pairingUnlockSealedIdentity((int) $user->id);

    test()
        ->withSession([AppLockTestHarness::LOCKED_SESSION_KEY => true])
        ->get('/mobile/pair')
        ->assertOk();
});

it('leaves an unlocked device on the pairing screen', function (): void {
    $user = pairingUnlockUser('pair-unlocked');
    test()->actingAs($user);
    pairingUnlockLockRow((int) $user->id, true);

    $dataKey = str_repeat('k', 32);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, $dataKey);
    app(PairingGateway::class)->enableSyncIdentityWithoutEpoch((int) $user->id, $session);

    test()
        ->withSession([
            AppLockTestHarness::LOCKED_SESSION_KEY => false,
            AppLockTestHarness::HELD_KEY_SESSION_KEY => $dataKey,
        ])
        ->get('/mobile/pair')
        ->assertOk();
});
