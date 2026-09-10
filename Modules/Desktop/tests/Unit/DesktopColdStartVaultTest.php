<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\Desktop\Internal\Native\DesktopColdStartVault;
use Modules\Desktop\Internal\Native\NativeBiometricUnlock;
use Native\Desktop\Facades\System as SystemFacade;
use Native\Desktop\System;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
 * @param  LoggerInterface|null  $log  Supplied only where a case reads what was logged.
 */
function coldStartVault(bool $available = true, bool $prompted = true, ?LoggerInterface $log = null): DesktopColdStartVault
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
        new OwnerOnlyPath,
        $log ?? new NullLogger,
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
        new OwnerOnlyPath,
        new NullLogger,
    );

    expect($vault->enroll(COLD_START_USER_ID, random_bytes(32)))->toBeFalse()
        ->and(is_file(coldStartKeyFile()))->toBeFalse();
})->with([[null], ['']]);

it('recovers nothing when the user was never enrolled', function (): void {
    expect(coldStartVault()->recover(COLD_START_USER_ID, 'Unlock Beatrax'))->toBeNull();
});

// A blob that survived a successful prompt and still would not open is not an
// authentication that failed: nothing on this machine will ever open it, and
// isEnrolled() reads the same file — so a survivor keeps the lock screen
// offering an unlock whose only possible answer is another refusal.
it('drops a stored key it cannot open, so nothing goes on offering it', function (string $unreadable): void {
    $vault = coldStartVault();
    $vault->enroll(COLD_START_USER_ID, random_bytes(32));
    file_put_contents(coldStartKeyFile(), $unreadable);

    expect($vault->recover(COLD_START_USER_ID, 'Unlock Beatrax'))->toBeNull()
        ->and($vault->isEnrolled(COLD_START_USER_ID))->toBeFalse();
})->with([
    'the keychain will not decrypt it' => ['written-by-another-machine'],
    'it is not the blob that was written' => ['enc:'.base64_encode('too-short')],
    'it is empty' => [''],
]);

// A prompt the reader declined leaves the entry exactly where it was: the file
// is never read, so nothing about it has been learned.
it('keeps a stored key whose prompt was declined', function (): void {
    coldStartVault()->enroll(COLD_START_USER_ID, random_bytes(32));

    $declined = coldStartVault(prompted: false);

    expect($declined->recover(COLD_START_USER_ID, 'Unlock Beatrax'))->toBeNull()
        ->and($declined->isEnrolled(COLD_START_USER_ID))->toBeTrue();
});

it('forgets an enrollment and stays silent when there is nothing to forget', function (): void {
    $vault = coldStartVault();
    $vault->enroll(COLD_START_USER_ID, random_bytes(32));

    expect($vault->forget(COLD_START_USER_ID))->toBeTrue()
        ->and($vault->isEnrolled(COLD_START_USER_ID))->toBeFalse()
        ->and($vault->forget(COLD_START_USER_ID))->toBeTrue('nothing left to delete is not a refusal to delete');
});

// isEnrolled() reads this same file, so a survivor keeps offering an unlock
// that now returns a key nothing opens. Reported as success, the settings
// screen says the key is gone while the OS is still holding it.
it('answers false and names it when the stored key will not delete', function (): void {
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'could not be deleted'));

    $vault = coldStartVault(log: $log);
    $vault->enroll(COLD_START_USER_ID, random_bytes(32));

    // A directory nothing may write is how a file survives its own unlink on
    // POSIX; on Windows the same survivor comes of another process holding it
    // open. Either way the class only learns of it by looking again.
    $directory = dirname(coldStartKeyFile());
    chmod($directory, 0500);

    try {
        expect(is_file(coldStartKeyFile()))->toBeTrue('the fixture must leave a file behind, or a false below proves nothing');

        expect($vault->forget(COLD_START_USER_ID))->toBeFalse()
            ->and($vault->isEnrolled(COLD_START_USER_ID))->toBeTrue('the enrolment is still there, and the screen has to be able to say so');
    } finally {
        chmod($directory, 0700);
    }
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

// Every other secret on this machine shares the directory — the relay token,
// the drain secret, the loopback TLS key — so a tree left readable by whoever
// created it first is an enumeration of all of them, and an enrollment that
// only ever created the directory would never narrow one it inherited.
it('narrows a secrets directory that already existed wider', function (): void {
    $secrets = UserDataPathService::secretsPath();
    mkdir($secrets, 0755, true);
    chmod($secrets, 0755);

    coldStartVault()->enroll(COLD_START_USER_ID, random_bytes(32));

    clearstatcache(true, $secrets);

    expect((int) fileperms($secrets) & 0777)->toBe(0700)
        ->and((int) fileperms(coldStartKeyFile()) & 0777)->toBe(0600);
});

// A write may succeed on a path a chmod cannot touch, and /dev/null is the one
// such path every POSIX machine has. Reported as enrolled, the lock screen
// stops asking for the code that was protecting the key it just published.
it('refuses to report an enrollment it could not make owner-only', function (): void {
    mkdir(UserDataPathService::secretsPath(), 0700, true);
    symlink('/dev/null', coldStartKeyFile());

    expect(coldStartVault()->enroll(COLD_START_USER_ID, random_bytes(32)))->toBeFalse();
});
