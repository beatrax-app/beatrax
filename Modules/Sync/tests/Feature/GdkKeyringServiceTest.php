<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

uses(RefreshDatabase::class);

/*
 * GdkKeyringServiceTest — CRYPT-01: GDK epoch keyring generate/load/append/
 * rewrap round-trips + KEK-null hard-throw (weak-key-window) guard.
 * 14-VALIDATION.md CRYPT-01 row 1.
 *
 * RED until Plan 02 ships Modules\Sync\Internal\Crypto\GdkKeyringService.
 * These tests reference the planned production FQCN, which does not yet
 * exist — the failure is "class not found", the correct Wave 0 RED state
 * (mirrors Modules/Sync/tests/Feature/DeviceIdentityServiceTest.php).
 */

function gdkUser(string $username = 'gdk-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('gdk-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('generates a GDK epoch and persists an encrypted keyring file under the app-lock KEK', function (): void {
    $user = gdkUser();

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $epoch = $service->generateAndPersist((int) $user->id, $session);

    expect($epoch)->toBeInstanceOf(GdkEpoch::class);
    expect($epoch->epochId)->toBe(1);
    expect($epoch->keyHex)->toHaveLength(64);
});

it('loads the persisted keyring and returns the same current epoch that was generated', function (): void {
    $user = gdkUser('gdk-load-user');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $generated = $service->generateAndPersist((int) $user->id, $session);
    $current = $service->currentEpoch((int) $user->id, $session);

    expect($current->epochId)->toBe($generated->epochId);
    expect($current->keyHex)->toBe($generated->keyHex);
});

it('appends a new epoch to the keyring without discarding the old one (D-04 append-only forever)', function (): void {
    $user = gdkUser('gdk-append-user');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $epoch1 = $service->generateAndPersist((int) $user->id, $session);
    $epoch2 = new GdkEpoch(epochId: $epoch1->epochId + 1, keyHex: bin2hex(random_bytes(32)));
    $service->appendEpoch((int) $user->id, $epoch2, $session);

    $current = $service->currentEpoch((int) $user->id, $session);
    expect($current->epochId)->toBe($epoch2->epochId);

    // The keyring must still hold epoch1's key — a full op-log rebuild has
    // to decrypt entries written under ANY historical epoch (Pitfall 4).
    $keyring = $service->loadKeyring((int) $user->id, $session);
    expect($keyring->keyFor($epoch1->epochId))->toBe($epoch1->keyHex);
    expect($keyring->keyFor($epoch2->epochId))->toBe($epoch2->keyHex);
});

it('re-wraps every keyring epoch under a new KEK (D-10 passphrase change)', function (): void {
    $userId = (int) gdkUser('gdk-rewrap-user')->id;

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // rewrapUnderNewKek() takes raw old/new wrap-key bytes directly — no
    // Session — mirroring how AppLockProvisioner::rewrapForNewPin() already
    // holds both the old and new KEK derived straight from the old/new
    // passphrase, without going through AppLockKeyService::release() (which
    // only ever exposes ONE currently-active key). The "old" KEK here is the
    // exact 32-byte dummy key Modules/Sync/tests/TestCase.php primed the
    // session with, so it round-trips against the file GdkKeyringService
    // wrote through the real AppLockKeyService binding.
    $oldKek = str_repeat("\x2a", 32);
    $newKek = random_bytes(32);

    $bindReleasedKek = function (string $kek): GdkKeyringService {
        $this->app->bind(AppLockKeyService::class, fn () => new class($kek) extends AppLockKeyService
        {
            public function __construct(private readonly string $key) {}

            public function release(Session $session): ?string
            {
                return $this->key;
            }
        });

        // GdkKeyringService is registered as a singleton (SyncServiceProvider) —
        // forget the cached instance so it re-resolves against the AppLockKeyService
        // binding just replaced above, rather than returning a stale instance
        // still holding the PREVIOUS fake.
        $this->app->forgetInstance(GdkKeyringService::class);

        /** @var GdkKeyringService $service */
        $service = $this->app->make(GdkKeyringService::class);

        return $service;
    };

    $generated = $bindReleasedKek($oldKek)->generateAndPersist($userId, $session);

    /** @var GdkKeyringService $rewrapService */
    $rewrapService = $this->app->make(GdkKeyringService::class);
    $rewrapService->rewrapUnderNewKek($userId, $oldKek, $newKek);

    // The keyring must still resolve to the SAME epoch/key after the rewrap —
    // only the at-rest wrapping key changed, not the GDK material itself.
    $current = $bindReleasedKek($newKek)->currentEpoch($userId, $session);
    expect($current->epochId)->toBe($generated->epochId);
    expect($current->keyHex)->toBe($generated->keyHex);

    // The OLD KEK can no longer decrypt the rewrapped keyring file.
    expect(fn () => $bindReleasedKek($oldKek)->currentEpoch($userId, $session))
        ->toThrow(RuntimeException::class);
});

it('hard-throws instead of writing a keyring when the app-lock KEK is null (weak-key-window guard)', function (): void {
    $user = gdkUser('gdk-nokek-user');

    // A fake AppLockKeyService whose release() returns null (locked / no app-lock).
    $this->app->bind(AppLockKeyService::class, fn () => new class extends AppLockKeyService
    {
        public function __construct() {}

        public function release(Session $session): ?string
        {
            return null;
        }
    });

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    expect(fn () => $service->generateAndPersist((int) $user->id, $session))
        ->toThrow(LogicException::class);
});
