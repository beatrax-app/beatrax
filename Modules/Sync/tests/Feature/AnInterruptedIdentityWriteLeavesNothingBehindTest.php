<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Identity\DeviceIdentityState;
use Modules\Sync\Internal\Identity\SealedJsonFile;

uses(RefreshDatabase::class);

// The GDK keyring beside it stages a sibling and renames; the encryptor's own
// decrypt half does the same. The identity file sealed straight onto the live
// path, and the encryptor opens its destination 'wb' — so a write cut short
// left a truncated key-file, which reads back as an identity that will not
// open. The mint path refuses to overwrite one of those, by design, so a device
// interrupted on its first run could never make an identity again.

function interruptedIdentityUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('interrupted-identity-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // RefreshDatabase resets rows, not files, and ids are reused across runs.
    foreach ((array) glob(UserDataPathService::appPath("sync/identity/{$user->id}.enc*")) as $stale) {
        @unlink((string) $stale);
    }

    return $user;
}

function interruptedIdentityPath(User $user): string
{
    return UserDataPathService::appPath("sync/identity/{$user->id}.enc");
}

// Writes a plausible prefix and then dies, which is what a killed process or a
// full disk does to a streaming write. Everything else delegates to the real
// encryptor, so the second attempt below is the real mint path.
function interruptedIdentityEncryptor(bool &$armed): FileEncryptor
{
    /** @var FileEncryptor $real */
    $real = app(FileEncryptor::class);

    return new class($real, $armed) implements FileEncryptor
    {
        public function __construct(private FileEncryptor $real, private bool &$armed) {}

        public function encryptWithKey(string $plainPath, string $encPath, string $key): void
        {
            if ($this->armed) {
                $this->armed = false;
                file_put_contents($encPath, 'BTRXBAK1');

                throw new RuntimeException('the disk filled up half way through the header');
            }

            $this->real->encryptWithKey($plainPath, $encPath, $key);
        }

        public function encrypt(string $plainPath, string $encPath, string $passphrase): void
        {
            $this->real->encrypt($plainPath, $encPath, $passphrase);
        }

        public function decrypt(string $encPath, string $plainPath, string $passphrase): void
        {
            $this->real->decrypt($encPath, $plainPath, $passphrase);
        }

        /**
         * @return array{0: int, 1: int}
         */
        public function kdfParams(string $encPath): array
        {
            return $this->real->kdfParams($encPath);
        }
    };
}

it('leaves no key-file at all when the sealing write dies part-way', function (): void {
    $armed = true;
    $this->app->instance(FileEncryptor::class, interruptedIdentityEncryptor($armed));

    $user = interruptedIdentityUser('interrupted-identity-absent');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    /** @var DeviceIdentityLoader $loader */
    $loader = $this->app->make(DeviceIdentityLoader::class);

    expect(fn () => $identityService->generateAndPersist((int) $user->id, $session))
        ->toThrow(RuntimeException::class);

    // Absent, not Unreadable: the difference is the whole finding. Unreadable
    // means secret keys somebody may still recover, and the mint path will not
    // write over it; a write that never landed holds nothing to protect.
    expect($armed)->toBeFalse('the interrupted write never happened, so this proves nothing')
        ->and(interruptedIdentityPath($user))->not->toBeFile()
        ->and($loader->state((int) $user->id, $session))->toBe(DeviceIdentityState::Absent)
        ->and((array) glob(interruptedIdentityPath($user).'.*.tmp'))->toBe([]);
});

it('mints an identity on the next attempt rather than refusing over its own debris', function (): void {
    $armed = true;
    $this->app->instance(FileEncryptor::class, interruptedIdentityEncryptor($armed));

    $user = interruptedIdentityUser('interrupted-identity-retry');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    /** @var DeviceIdentityLoader $loader */
    $loader = $this->app->make(DeviceIdentityLoader::class);

    expect(fn () => $identityService->generateAndPersist((int) $user->id, $session))
        ->toThrow(RuntimeException::class);

    // Disarmed by the first call, so this is the ordinary mint the reader gets
    // by opening the app again.
    $identity = $identityService->generateAndPersist((int) $user->id, $session);

    expect($identity->userId)->toBe((int) $user->id)
        ->and($loader->state((int) $user->id, $session))->toBe(DeviceIdentityState::Usable)
        ->and($loader->load((int) $user->id, $session)?->deviceId)->toBe($identity->deviceId);
});

// The positive control for the staging: an ordinary mint must leave the live
// path and nothing beside it, or every install accumulates readable debris.
it('leaves no staged sibling behind on an ordinary mint', function (): void {
    $user = interruptedIdentityUser('interrupted-identity-clean');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);

    $identityService->generateAndPersist((int) $user->id, $session);

    expect(interruptedIdentityPath($user))->toBeFile()
        ->and((array) glob(interruptedIdentityPath($user).'.*.tmp'))->toBe([])
        ->and((array) glob(dirname(interruptedIdentityPath($user)).'/beatrax_identity_*.tmp'))->toBe([]);
});

// The finalize can fail on its own, after a seal that worked. The staged copy
// is unlinked before the refusal leaves, so the live path is whatever it
// already was and no readable debris sits beside it.
it('refuses a finalize it cannot rename, and takes its staged copy with it', function (): void {
    $directory = UserDataPathService::appPath('sync/identity');
    @mkdir($directory, 0o700, true);

    // A directory wearing the destination's name. POSIX rename() refuses to
    // move a file onto one, which is the finalize failing after a clean seal.
    $occupied = $directory.DIRECTORY_SEPARATOR.'interrupted-identity-rename.enc';
    @mkdir($occupied, 0o700);

    $sealed = new SealedJsonFile($this->app->make(FileEncryptor::class));

    expect(fn () => $sealed->writeSealed($occupied, '{"identity":"never lands"}', str_repeat("\x11", 32), 'beatrax_identity_'))
        ->toThrow(SecretFileException::class);

    expect((array) glob($occupied.'.*.tmp'))->toBe([])
        ->and((array) glob($directory.DIRECTORY_SEPARATOR.'beatrax_identity_*.tmp'))->toBe([]);

    @rmdir($occupied);
});
