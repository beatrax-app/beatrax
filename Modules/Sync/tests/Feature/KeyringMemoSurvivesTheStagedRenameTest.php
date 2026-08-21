<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

uses(RefreshDatabase::class);

// loadKeyring() memoises the decrypted keyring against the KEK, because
// decrypting it per value made a 164-row page take minutes. The memo is
// cleared where the keyring is REWRITTEN — and epoch 1 does not arrive that
// way. It is staged to a .tmp inside the enable transaction and renamed into
// place only after the commit, and the rename cleared nothing, so a memo taken
// while the real file did not exist yet is an empty keyring that outlives the
// epoch it is missing.

function keyringMemoUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('keyring-memo-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('does not answer from a memo taken before the staged keyring was renamed into place', function (): void {
    $userId = (int) keyringMemoUser('keyring-memo-staged')->id;

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);

    // What any reader of the keyring does while the enable transaction is open:
    // the file is not there yet, so this is an empty keyring, and it is the
    // value that gets memoised.
    expect($keyringService->loadKeyring($userId, $session)->epochs())->toBe([]);

    $stage = $keyringService->stageFirstEpoch($userId, $session);
    $keyringService->finalizeStagedEpoch($stage);

    expect($keyringService->loadKeyring($userId, $session)->keyFor($stage->epoch->epochId))
        ->toBe($stage->epoch->keyHex);
});

// The same rename happens on a device adopting a peer's keyring, where the
// memo it would poison is the one the sync session reads every op through.
it('sees the staged blind-index key after the rename, not the absence memoised before it', function (): void {
    $userId = (int) keyringMemoUser('keyring-memo-blind-index')->id;

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);

    expect($keyringService->blindIndexKeyHex($userId, $session))->toBeNull();

    $stage = $keyringService->stageFirstEpoch($userId, $session);
    $keyringService->finalizeStagedEpoch($stage);

    expect($keyringService->blindIndexKeyHex($userId, $session))->toBe($stage->blindIndexKeyHex);
});

// currentEpoch() runs on every write hook, and it read and decrypted the
// keyring file itself rather than going through the memo — the one thing the
// memo exists to stop. It could not be routed through loadKeyring() while a
// staged rename left the memo stale, which is what the two tests above fixed.
it('resolves the current epoch without decrypting the keyring file again', function (): void {
    $userId = (int) keyringMemoUser('keyring-memo-current-epoch')->id;

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);

    $stage = $keyringService->stageFirstEpoch($userId, $session);
    $keyringService->finalizeStagedEpoch($stage);

    $keyringService->loadKeyring($userId, $session);

    // With the file gone, only the memo can answer. The real caller never sees
    // this state; it is how the test tells a memo read from a file read.
    unlink(UserDataPathService::appPath("sync/gdk/{$userId}.enc"));

    $epoch = $keyringService->currentEpoch($userId, $session);

    expect($epoch->epochId)->toBe($stage->epoch->epochId);
    expect($epoch->keyHex)->toBe($stage->epoch->keyHex);
});
