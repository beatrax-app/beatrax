<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\SodiumPrimitives;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

/*
 * What the crypto paths do when a primitive misbehaves.
 *
 * Each of these operations wraps its work in a `catch (SodiumException)` and
 * re-throws a typed failure naming the operation, so a caller is not left
 * matching on a libsodium message. None of those catches could be reached
 * before: every argument reaching libsodium has had its length fixed upstream,
 * so no input the callers can construct makes the real implementation throw.
 *
 * The conversions and the file encryptor are injected now, which is what makes
 * the translation testable. The doubles below fail on purpose; the assertions
 * are that the failure is translated at all, names the operation, and keeps
 * the original as its cause. They assert RuntimeException because that is what
 * these paths still throw — #90 narrows them to the module's own types, and
 * those are subclasses, so these assertions keep holding afterwards.
 */
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

        public function decrypt(string $encPath, string $plainPath, string $passphrase): void
        {
            throw new SodiumException('decrypt refused');
        }
    };
}

/**
 * Drops the cached singleton so the next resolve picks up the doubles. Both
 * services are registered with singleton(), so binding a replacement after one
 * has already been resolved would otherwise have no effect and the test would
 * silently exercise the real primitives.
 */
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
        ->toThrow(RuntimeException::class, 'Failed to generate GDK keyring');
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
        ->toThrow(RuntimeException::class, 'Failed to generate GDK keyring');
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
        ->toThrow(RuntimeException::class, 'Failed to append GDK epoch');
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
        ->toThrow(RuntimeException::class, 'Failed to re-wrap GDK keyring');
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
        ->toThrow(RuntimeException::class, 'Failed to generate device identity');
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
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getPrevious())->toBeInstanceOf(SodiumException::class)
        ->and($thrown->getPrevious()?->getMessage())->toBe('bin2hex refused');
});
