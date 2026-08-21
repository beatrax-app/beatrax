<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Desktop\Internal\Native\DesktopColdStartVault;
use Modules\Desktop\Internal\Native\NativeBiometricUnlock;
use Native\Desktop\Facades\System as SystemFacade;
use Native\Desktop\System;

const COLD_START_USER_ID = 7;

beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-coldstart-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

/**
 * @param  bool  $available  Whether Touch ID reports itself usable.
 * @param  bool  $prompted  Whether the user completes the authentication.
 */
function coldStartVault(bool $available = true, bool $prompted = true): DesktopColdStartVault
{
    // NativeBiometricUnlock is final, so the same System mock is both swapped
    // into the facade it reads availability from and passed to the constructor.
    // safeStorage is faked as a reversible prefix rather than an echo: enroll()
    // zeroes the plaintext right after encrypting, and an echo would return it.
    $system = Mockery::mock(System::class);
    $system->shouldReceive('canPromptTouchID')->andReturn($available);
    $system->shouldReceive('promptTouchID')->andReturn($prompted);
    $system->shouldReceive('encrypt')->andReturnUsing(static fn (string $plain): string => 'enc:'.$plain);
    $system->shouldReceive('decrypt')->andReturnUsing(
        static fn (string $stored): ?string => str_starts_with($stored, 'enc:') ? substr($stored, 4) : null,
    );

    SystemFacade::swap($system);

    return new DesktopColdStartVault(
        new NativeBiometricUnlock(new Repository(['nativephp-internal' => ['running' => $available]])),
        new BiometricKeyBlobCodec(new AppLockKeyWrap),
        $system,
    );
}

function coldStartKeyFile(int $userId = COLD_START_USER_ID): string
{
    return UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.'coldstart-datakey-'.$userId.'.bin';
}

// The prompt alone only answers yes/no, so the data key is wrapped, handed to
// safeStorage and written 0600: recovering it needs both this machine's
// keychain and a live authentication, and every refusal must fail closed.
it('reports availability from the biometric gate', function (): void {
    expect(coldStartVault(available: true)->isAvailable())->toBeTrue()
        ->and(coldStartVault(available: false)->isAvailable())->toBeFalse();
});

it('is not enrolled until a key has been stored', function (): void {
    $vault = coldStartVault();

    expect($vault->isEnrolled(COLD_START_USER_ID))->toBeFalse();

    $vault->enroll(COLD_START_USER_ID, random_bytes(32));

    expect($vault->isEnrolled(COLD_START_USER_ID))->toBeTrue();
});

it('round-trips the data key through the keychain and the prompt', function (): void {
    $vault = coldStartVault();
    $dataKey = random_bytes(32);

    expect($vault->enroll(COLD_START_USER_ID, $dataKey))->toBeTrue()
        ->and($vault->recover(COLD_START_USER_ID, 'Unlock Beatrax'))->toBe($dataKey);
});

it('scopes the stored key per user so two accounts never collide', function (): void {
    $vault = coldStartVault();
    $first = random_bytes(32);
    $second = random_bytes(32);

    $vault->enroll(1, $first);
    $vault->enroll(2, $second);

    expect($vault->recover(1, 'Unlock Beatrax'))->toBe($first)
        ->and($vault->recover(2, 'Unlock Beatrax'))->toBe($second);
});

it('writes the wrapped key 0600 and never in the clear', function (): void {
    $dataKey = random_bytes(32);
    coldStartVault()->enroll(COLD_START_USER_ID, $dataKey);

    $path = coldStartKeyFile();
    $onDisk = (string) file_get_contents($path);

    expect(substr(sprintf('%o', (int) fileperms($path)), -4))->toBe('0600')
        ->and($onDisk)->not->toContain($dataKey)
        ->and($onDisk)->not->toContain(base64_encode($dataKey));
});

it('refuses to enroll when Touch ID is unavailable', function (): void {
    $vault = coldStartVault(available: false);

    expect($vault->enroll(COLD_START_USER_ID, random_bytes(32)))->toBeFalse()
        ->and($vault->isEnrolled(COLD_START_USER_ID))->toBeFalse()
        ->and(is_file(coldStartKeyFile()))->toBeFalse();
});

// A keychain that refuses must never fall back to writing the key in the
// clear: no safeStorage, no enrollment. Both refusal shapes the native side
// can produce — a null and an empty string — have to fail the same way.
it('refuses to enroll when safeStorage returns nothing', function (?string $refusal): void {
    $system = Mockery::mock(System::class);
    $system->shouldReceive('canPromptTouchID')->andReturn(true);
    $system->shouldReceive('encrypt')->andReturn($refusal);
    SystemFacade::swap($system);

    $vault = new DesktopColdStartVault(
        new NativeBiometricUnlock(new Repository(['nativephp-internal' => ['running' => true]])),
        new BiometricKeyBlobCodec(new AppLockKeyWrap),
        $system,
    );

    expect($vault->enroll(COLD_START_USER_ID, random_bytes(32)))->toBeFalse()
        ->and(is_file(coldStartKeyFile()))->toBeFalse();
})->with([[null], ['']]);

it('recovers nothing when the user was never enrolled', function (): void {
    expect(coldStartVault()->recover(COLD_START_USER_ID, 'Unlock Beatrax'))->toBeNull();
});

it('recovers nothing when the authentication is refused', function (): void {
    coldStartVault()->enroll(COLD_START_USER_ID, random_bytes(32));

    expect(coldStartVault(prompted: false)->recover(COLD_START_USER_ID, 'Unlock Beatrax'))->toBeNull();
});

it('recovers nothing when the stored file is empty', function (): void {
    $vault = coldStartVault();
    $vault->enroll(COLD_START_USER_ID, random_bytes(32));
    file_put_contents(coldStartKeyFile(), '');

    expect($vault->recover(COLD_START_USER_ID, 'Unlock Beatrax'))->toBeNull();
});

// The keychain refusing to decrypt is the ordinary shape of "this file was
// written on another machine", so it has to be a null rather than a throw.
it('recovers nothing when the keychain will not decrypt', function (): void {
    $vault = coldStartVault();
    $vault->enroll(COLD_START_USER_ID, random_bytes(32));
    file_put_contents(coldStartKeyFile(), 'written-by-another-machine');

    expect($vault->recover(COLD_START_USER_ID, 'Unlock Beatrax'))->toBeNull();
});

it('recovers nothing when the decrypted payload is not the blob it wrote', function (): void {
    $vault = coldStartVault();
    $vault->enroll(COLD_START_USER_ID, random_bytes(32));
    file_put_contents(coldStartKeyFile(), 'enc:'.base64_encode('too-short'));

    expect($vault->recover(COLD_START_USER_ID, 'Unlock Beatrax'))->toBeNull();
});

it('forgets an enrollment and stays silent when there is nothing to forget', function (): void {
    $vault = coldStartVault();
    $vault->enroll(COLD_START_USER_ID, random_bytes(32));

    $vault->forget(COLD_START_USER_ID);

    expect($vault->isEnrolled(COLD_START_USER_ID))->toBeFalse()
        ->and(fn () => $vault->forget(COLD_START_USER_ID))->not->toThrow(Throwable::class);
});

// mkdir cannot create the secrets directory when a file already occupies the
// path, and is_dir stays false — the pair of conditions the write guard needs.
it('refuses to enroll when the secrets directory cannot be created', function (): void {
    $dir = UserDataPathService::secretsPath();
    mkdir(dirname($dir), 0700, true);
    file_put_contents($dir, 'not a directory');

    expect(coldStartVault()->enroll(COLD_START_USER_ID, random_bytes(32)))->toBeFalse();

    @unlink($dir);
});
