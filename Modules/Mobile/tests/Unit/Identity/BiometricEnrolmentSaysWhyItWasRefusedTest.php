<?php

declare(strict_types=1);

use Beatrax\BiometricVault\BiometricVault;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// Found on an iPhone 12 mini: "Use Face ID → Enroll" failed every time, the
// screen said only "Your device declined to store the key", and NOTHING was
// written anywhere. The bridge had answered {status: error, message: …} and
// three layers in a row reduced that to false. The only way to read the real
// reason was to patch this file onto the phone and take the tap again.

// Every fake below is anonymous on purpose. A named subclass would be resolved
// when the file is COMPILED, and the native vault package is autoloaded only
// from the mobile-app root — so the repo-rooted run would fatal instead of skip.

it('keeps the reason the bridge gave for refusing', function (string $reply, string $expected): void {
    $vault = new class($reply) extends BiometricVault
    {
        public function __construct(private readonly string $reply) {}

        protected function bridge(string $function, string $payload): ?string
        {
            return $this->reply;
        }
    };

    expect($vault->set('slot', 'value'))->toBeFalse()
        ->and($vault->lastError())->toBe($expected);
})->with([
    'the shape the iPhone actually returned' => [
        '{"code":"EXECUTION_FAILED","data":{},"status":"error","message":"BiometricVault.Set failed: Keychain save failed (-25293)"}',
        'BiometricVault.Set failed: Keychain save failed (-25293)',
    ],
    'an error with no message' => ['{"status":"error"}', 'the native bridge reported an error'],
    'not an object' => ['"nope"', 'the native bridge answered with something that is not an object'],
    'empty' => ['', 'the native bridge did not answer'],
])->skip(
    fn (): bool => ! class_exists(BiometricVault::class),
    'The native vault package is autoloaded only from the mobile-app root.',
);

it('clears the reason once a call succeeds', function (): void {
    $vault = new class extends BiometricVault
    {
        protected function bridge(string $function, string $payload): ?string
        {
            return '{"success":true}';
        }
    };

    expect($vault->set('slot', 'value'))->toBeTrue()
        ->and($vault->lastError())->toBeNull();
})->skip(
    fn (): bool => ! class_exists(BiometricVault::class),
    'The native vault package is autoloaded only from the mobile-app root.',
);

/**
 * @param  list<?string>  $logged
 */
function refusingKeyVault(?string $nativeError, bool $stored, array &$logged): BiometricKeyVault
{
    $currentUser = new class implements CurrentUser
    {
        public function id(): int
        {
            return 1;
        }

        public function user(): User
        {
            throw new NotAuthenticatedException('The vault only ever asks for the id.');
        }

        public function periodStartDay(): int
        {
            return 1;
        }

        public function isAuthenticated(): bool
        {
            return true;
        }
    };

    return new class(app(BiometricKeyBlobCodec::class), $currentUser, new NullLogger, $nativeError, $stored, $logged) extends BiometricKeyVault
    {
        /**
         * @param  list<?string>  $logged
         */
        public function __construct(
            BiometricKeyBlobCodec $codec,
            CurrentUser $currentUser,
            LoggerInterface $log,
            private readonly ?string $nativeError,
            private readonly bool $stored,
            private array &$logged,
        ) {
            parent::__construct($codec, $currentUser, $log);
        }

        protected function runtimeAvailable(): bool
        {
            return true;
        }

        protected function vaultSet(string $key, string $value): bool
        {
            return $this->stored;
        }

        protected function lastNativeError(): ?string
        {
            return $this->nativeError;
        }

        protected function logRefusal(?string $reason): void
        {
            $this->logged[] = $reason;
        }
    };
}

it('writes the reason down when enrolment is refused', function (?string $reason): void {
    $logged = [];

    expect(refusingKeyVault($reason, false, $logged)->enroll('a-data-key'))->toBeFalse()
        ->and($logged)->toBe([$reason]);
})->with([
    'the native side named a reason' => ['Keychain save failed (-25293)'],
    'the native side gave none' => [null],
]);

it('says nothing on the happy path', function (): void {
    $logged = [];

    expect(refusingKeyVault(null, true, $logged)->enroll('a-data-key'))->toBeTrue()
        ->and($logged)->toBe([]);
});

// The iPhone reported "BiometricKeychainError error 0" — an ordinal no reader
// can map back to a case, because the throw sites catch `Error` and Swift's
// NSError bridging answers before a plain localizedDescription property does.
it('makes the native keychain diagnostic reachable', function (): void {
    $relative = 'nativephp-plugins/biometric-vault/resources/ios/BiometricVaultFunctions.swift';

    // Both composer roots run this directory, and only one of them is mobile-app.
    $path = collect([base_path($relative), base_path('mobile-app/'.$relative)])
        ->first(static fn (string $candidate): bool => is_file($candidate));

    expect($path)->not->toBeNull('The iOS BiometricVault plugin source moved.');

    $swift = (string) file_get_contents((string) $path);

    expect($swift)->toContain('private enum BiometricKeychainError: Error, LocalizedError {')
        ->and($swift)->toContain('var errorDescription: String? {')
        ->and($swift)->toContain('Keychain save failed (\(s))');
});
