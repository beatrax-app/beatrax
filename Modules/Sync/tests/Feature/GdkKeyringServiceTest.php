<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Exceptions\KeyringStateException;
use Modules\Sync\Internal\Exceptions\SecretFileException;

uses(RefreshDatabase::class);

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
    expect($epoch->epochId)->toBeGreaterThan(0);
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

it('appends a new epoch to the keyring without discarding the old one', function (): void {
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

    // The old epoch's key must survive the append: a full rebuild decrypts
    // entries written under any epoch the device ever held.
    $keyring = $service->loadKeyring((int) $user->id, $session);
    expect($keyring->keyFor($epoch1->epochId))->toBe($epoch1->keyHex);
    expect($keyring->keyFor($epoch2->epochId))->toBe($epoch2->keyHex);
});

it('re-wraps every keyring epoch under a new KEK on a passphrase change', function (): void {
    $userId = (int) gdkUser('gdk-rewrap-user')->id;

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // The rewrap takes both wrap keys as raw bytes rather than going through
    // the session, which only ever exposes the one currently-active key. The
    // old key here is the same dummy the module TestCase primed.
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

        // A container singleton, so the cached instance has to be forgotten or
        // it keeps the fake bound before this one.
        $this->app->forgetInstance(GdkKeyringService::class);

        /** @var GdkKeyringService $service */
        $service = $this->app->make(GdkKeyringService::class);

        return $service;
    };

    $generated = $bindReleasedKek($oldKek)->generateAndPersist($userId, $session);

    /** @var GdkKeyringService $rewrapService */
    $rewrapService = $this->app->make(GdkKeyringService::class);
    $rewrapService->rewrapUnderNewKek($userId, $oldKek, $newKek);

    // Only the at-rest wrapping key changed, so the epoch and its key must
    // come back identical.
    $current = $bindReleasedKek($newKek)->currentEpoch($userId, $session);
    expect($current->epochId)->toBe($generated->epochId);
    expect($current->keyHex)->toBe($generated->keyHex);

    expect(fn () => $bindReleasedKek($oldKek)->currentEpoch($userId, $session))
        ->toThrow(RuntimeException::class);
});

it('hard-throws instead of writing a keyring when the app-lock KEK is null (weak-key-window guard)', function (): void {
    $user = gdkUser('gdk-nokek-user');

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

// The keyring and the current_epoch pointer live in two places, an encrypted
// file and a database row, so they can disagree. Neither disagreement was
// exercised, and both would otherwise surface as a null dereference further
// down.

it('refuses to hand out a current epoch when none is recorded', function (): void {
    $user = gdkUser('gdk-no-epoch-user');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    expect(fn () => $service->currentEpoch((int) $user->id, $session))
        ->toThrow(KeyringStateException::class, 'No current GDK epoch recorded');
});

// The pointer moving ahead of the file is the dangerous direction: it means a
// write landed in one place and not the other, and reading on regardless would
// encrypt under a key the keyring cannot reproduce.
it('refuses a current epoch the keyring holds no key for', function (): void {
    $user = gdkUser('gdk-missing-key-user');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $service->generateAndPersist((int) $user->id, $session);

    DB::table('sync_encryption_state')
        ->where('user_id', $user->id)
        ->update(['current_epoch' => 99]);

    expect(fn () => $service->currentEpoch((int) $user->id, $session))
        ->toThrow(KeyringStateException::class, 'has no key for current epoch 99');
});

// The keyring is written to a sibling file and renamed, so the rename is the
// moment the new epoch becomes real. Neither caller of it had been exercised
// against a failure.

// finalizeStagedEpoch() deliberately does NOT delete the staged file when the
// rename fails: current_epoch is already committed by then, so the .tmp is the
// only copy of the epoch key in existence. Losing it would lock the user out
// of everything encrypted under that epoch.
it('keeps the staged keyring when it cannot be moved into place', function (): void {
    $user = gdkUser('gdk-finalize-fail');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $stage = $service->stageFirstEpoch((int) $user->id, $session);

    $encPath = UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc');
    mkdir($encPath, 0700, true);
    file_put_contents($encPath.DIRECTORY_SEPARATOR.'occupied', 'x');

    expect(fn () => $service->finalizeStagedEpoch($stage))
        ->toThrow(SecretFileException::class, 'Could not finalize');

    expect(is_file($stage->tmpEncPath))->toBeTrue('the staged keyring is the only copy of the epoch key');

    @unlink($encPath.DIRECTORY_SEPARATOR.'occupied');
    @rmdir($encPath);
});

// A keyring file that decrypts cleanly but does not hold an object is a
// different failure from one that will not decrypt at all: the passphrase was
// right, so re-prompting would be the wrong response.
it('refuses a keyring payload that decrypts to something other than an object', function (): void {
    $user = gdkUser('gdk-corrupt-payload');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $service->generateAndPersist((int) $user->id, $session);

    /** @var AppLockKeyService $keys */
    $keys = $this->app->make(AppLockKeyService::class);
    $kek = (string) $keys->release($session);

    // Re-encrypted under the same key, so the read gets past decryption before
    // it objects to the shape.
    $plain = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gdk-bad-'.bin2hex(random_bytes(4));
    file_put_contents($plain, '"a bare string, not the keyring object"');
    $this->app->make(BackupEncryptor::class)->encrypt(
        $plain,
        UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'),
        $kek,
    );
    @unlink($plain);

    expect(fn () => $service->currentEpoch((int) $user->id, $session))
        ->toThrow(KeyringStateException::class, 'Corrupt GDK keyring payload');
});
