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
    $user = gdkUser('gdk-rewrap-user');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $service->generateAndPersist((int) $user->id, $session);

    $oldKek = sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $newKek = sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

    $service->rewrapUnderNewKek((int) $user->id, $oldKek, $newKek);

    // The keyring must still resolve to the same epoch/key after the rewrap —
    // only the at-rest wrapping key changed, not the GDK material itself.
    $current = $service->currentEpoch((int) $user->id, $session);
    expect($current->epochId)->toBeGreaterThanOrEqual(1);
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
