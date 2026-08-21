<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\SodiumPrimitives;
use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

// Every argument these paths hand libsodium has had its length fixed upstream,
// so no input a caller can construct reaches the SodiumException catches. The
// conversions and the file encryptor are injected purely so a double can fail
// on purpose and the translation can be asserted at all.
function failingSodium(): SodiumPrimitives
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

function failingEncryptor(): FileEncryptor
{
    return new class implements FileEncryptor
    {
        public function encrypt(string $plainPath, string $encPath, string $passphrase): void
        {
            throw new SodiumException('encrypt refused');
        }

        public function encryptWithKey(string $plainPath, string $encPath, string $key): void
        {
            throw new SodiumException('encrypt refused');
        }

        /** @return array{0: int, 1: int} */
        public function kdfParams(string $encPath): array
        {
            return [1, 8192];
        }

        public function decrypt(string $encPath, string $plainPath, string $passphrase): void
        {
            throw new SodiumException('decrypt refused');
        }
    };
}

// Both services are container singletons, so a double bound after the first
// resolve is ignored and the test would silently run the real primitives.
function forgetCryptoSingletons(mixed $app): void
{
    $app->forgetInstance(GdkKeyringService::class);
    $app->forgetInstance(DeviceIdentityService::class);
}

function cryptoFailureUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('crypto-fail-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('translates a libsodium failure while generating a keyring', function (): void {
    $user = cryptoFailureUser('crypto-fail-generate');
    $this->app->instance(SodiumPrimitives::class, failingSodium());
    forgetCryptoSingletons($this->app);

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    expect(fn () => $service->generateAndPersist((int) $user->id, $session))
        ->toThrow(CryptoOperationFailedException::class, 'GDK keyring generation');
});

it('translates a libsodium failure while staging the first epoch', function (): void {
    $user = cryptoFailureUser('crypto-fail-stage');
    $this->app->instance(SodiumPrimitives::class, failingSodium());
    forgetCryptoSingletons($this->app);

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    expect(fn () => $service->stageFirstEpoch((int) $user->id, $session))
        ->toThrow(CryptoOperationFailedException::class, 'GDK keyring generation');
});

// appendEpoch and rewrapUnderNewKek do no conversion of their own — the only
// libsodium they reach is inside the file encryptor, which is why that had to
// become substitutable too.
it('translates a libsodium failure while appending an epoch', function (): void {
    $user = cryptoFailureUser('crypto-fail-append');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $service->generateAndPersist((int) $user->id, $session);

    $this->app->instance(FileEncryptor::class, failingEncryptor());
    forgetCryptoSingletons($this->app);
    /** @var GdkKeyringService $broken */
    $broken = $this->app->make(GdkKeyringService::class);

    expect(fn () => $broken->appendEpoch((int) $user->id, new GdkEpoch(epochId: 2, keyHex: str_repeat('ab', 32)), $session))
        ->toThrow(CryptoOperationFailedException::class, 'GDK epoch append');
});

it('translates a libsodium failure while re-wrapping under a new KEK', function (): void {
    $user = cryptoFailureUser('crypto-fail-rewrap');

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $service->generateAndPersist((int) $user->id, $session);

    $this->app->instance(FileEncryptor::class, failingEncryptor());
    forgetCryptoSingletons($this->app);
    /** @var GdkKeyringService $broken */
    $broken = $this->app->make(GdkKeyringService::class);

    expect(fn () => $broken->rewrapUnderNewKek((int) $user->id, str_repeat('a', 32), str_repeat('b', 32)))
        ->toThrow(CryptoOperationFailedException::class, 'GDK keyring re-wrap');
});

it('translates a libsodium failure while generating a device identity', function (): void {
    $user = cryptoFailureUser('crypto-fail-identity');
    $this->app->instance(SodiumPrimitives::class, failingSodium());
    forgetCryptoSingletons($this->app);

    /** @var DeviceIdentityService $service */
    $service = $this->app->make(DeviceIdentityService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    expect(fn () => $service->generateAndPersist((int) $user->id, $session))
        ->toThrow(CryptoOperationFailedException::class, 'device identity generation');
});

// The cause is what tells a maintainer which primitive gave up. The operation
// name alone is the part they already knew from the stack trace.
it('keeps the libsodium failure as the cause', function (): void {
    $user = cryptoFailureUser('crypto-fail-cause');
    $this->app->instance(SodiumPrimitives::class, failingSodium());
    forgetCryptoSingletons($this->app);

    /** @var GdkKeyringService $service */
    $service = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    try {
        $service->generateAndPersist((int) $user->id, $session);
        $thrown = null;
    } catch (CryptoOperationFailedException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getPrevious())->toBeInstanceOf(SodiumException::class)
        ->and($thrown->getPrevious()?->getMessage())->toBe('bin2hex refused');
});
