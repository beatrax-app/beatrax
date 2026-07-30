<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\SodiumPrimitives;
use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

/*
 * The crypto failure paths the seam in #96 left uncovered.
 *
 * Rotation translates a libsodium failure the same way the keyring and identity
 * paths do, and neither of its two catches was reached. The keyring's other
 * rename — the one inside writeKeyringFile() rather than finalizeStagedEpoch()
 * — was not either, and nor was the identity read-back.
 */
function remainingUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('remaining-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function remainingFailingSodium(): SodiumPrimitives
{
    return new class implements SodiumPrimitives
    {
        public function binToHex(string $bin): string
        {
            throw new SodiumException('bin2hex refused');
        }

        public function hexToBin(string $hex): string
        {
            throw new SodiumException('hex2bin refused');
        }
    };
}

// Both services are container singletons, so a double bound after the first
// resolve is ignored and the test would run against the real primitives.
function forgetRemainingSingletons(mixed $app): void
{
    $app->forgetInstance(GdkKeyringService::class);
    $app->forgetInstance(GdkRotationService::class);
    $app->forgetInstance(DeviceIdentityService::class);
    $app->forgetInstance(DeviceIdentityLoader::class);
}

it('translates a libsodium failure while rotating after a device removal', function (): void {
    $user = remainingUser('rotate-fail');

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    $this->app->instance(SodiumPrimitives::class, remainingFailingSodium());
    forgetRemainingSingletons($this->app);

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    expect(fn () => $rotation->rotateAndRevoke((int) $user->id, 1, $session))
        ->toThrow(CryptoOperationFailedException::class, 'GDK rotation');
});

// The add-device fan-out translates the same way, and that now includes the
// recipient-key conversion. It used to sit outside the try and escape as a raw
// SodiumException, while the per-epoch conversion a few lines later came back
// as CryptoOperationFailedException — one fault, two types.
it('translates a libsodium failure while fanning out epochs to a new device', function (): void {
    $user = remainingUser('fanout-fail');

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $recipientId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => (int) $user->id,
        'device_id' => 'fanout-recipient',
        'name' => 'fanout-recipient',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => bin2hex(random_bytes(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-11T10:00:00Z',
        'confirmed_at' => '2026-07-11T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-11T10:00:00Z',
        'updated_at' => '2026-07-11T10:00:00Z',
    ]);

    $this->app->instance(SodiumPrimitives::class, remainingFailingSodium());
    forgetRemainingSingletons($this->app);

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    expect(fn () => $rotation->fanOutAllEpochsToDevice((int) $user->id, $recipientId, $session))
        ->toThrow(CryptoOperationFailedException::class, 'GDK epoch fan-out to a device');
});

// finalizeStagedEpoch() is not the only place the keyring is renamed into
// position: writeKeyringFile() does its own, on the path generateAndPersist()
// takes. A directory where the keyring belongs makes that rename fail without
// the read that appendEpoch() would attempt first.
it('reports a keyring it cannot rename into place on first write', function (): void {
    $user = remainingUser('write-rename-fail');

    $encPath = UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc');
    mkdir($encPath, 0700, true);
    file_put_contents($encPath.DIRECTORY_SEPARATOR.'occupied', 'x');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    expect(fn () => $service->generateAndPersist((int) $user->id, $session))
        ->toThrow(SecretFileException::class, 'Could not finalize the GDK keyring file');

    @unlink($encPath.DIRECTORY_SEPARATOR.'occupied');
    @rmdir($encPath);
});

/**
 * Sets up a device identity, then swaps in an encryptor whose decrypt does
 * whatever $decrypt does, and returns a loader wired to it.
 */
function loaderWithDecrypt(mixed $app, User $user, Session $session, Closure $decrypt): DeviceIdentityLoader
{
    /** @var DeviceIdentityService $identity */
    $identity = $app->make(DeviceIdentityService::class);
    $identity->generateAndPersist((int) $user->id, $session);

    $app->instance(FileEncryptor::class, new class($decrypt) implements FileEncryptor
    {
        public function __construct(private Closure $decrypt) {}

        public function encrypt(string $plainPath, string $encPath, string $passphrase): void {}

        public function decrypt(string $encPath, string $plainPath, string $passphrase): void
        {
            ($this->decrypt)($plainPath);
        }
    });
    forgetRemainingSingletons($app);

    /** @var DeviceIdentityLoader $loader */
    $loader = $app->make(DeviceIdentityLoader::class);

    return $loader;
}

// The plaintext lands at the process umask default, so the loader narrows it to
// 0600 before reading a secret back out. A decrypt that produces no file at all
// makes that chmod fail, and the loader must refuse rather than read on.
it('refuses to read an identity key-file it could not lock down', function (): void {
    $user = remainingUser('identity-lockdown-fail');
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $loader = loaderWithDecrypt($this->app, $user, $session, static function (string $plainPath): void {});

    expect(fn () => $loader->load((int) $user->id, $session))
        ->toThrow(SecretFileException::class, 'Cannot chmod secret material');
});

// One guard in this area stays uncovered, for a reason no test can work
// around:
//
//   - the read-back guard in DeviceIdentityLoader. Reaching it needs a
//     plaintext file that survives the lock-down chmod and then fails to read.
//     A directory satisfies the chmod but yields an empty read rather than
//     false, so the JSON decode objects first.
