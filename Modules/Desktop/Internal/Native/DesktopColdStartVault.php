<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Public\Services\UserDataPathService;
use Native\Desktop\System;

// A Touch ID prompt only yields a bool, so the data key is wrapped by the shared
// codec and stored under Electron safeStorage: recovering it needs BOTH this
// machine's keychain and a live Touch ID authentication.
final readonly class DesktopColdStartVault implements ColdStartVault
{
    private const FILE_PREFIX = 'coldstart-datakey-';

    public function __construct(
        private NativeBiometricUnlock $biometrics,
        private BiometricKeyBlobCodec $codec,
        private System $system,
    ) {}

    public function isAvailable(): bool
    {
        return $this->biometrics->isAvailable();
    }

    public function isEnrolled(int $userId): bool
    {
        return is_file($this->path($userId));
    }

    public function enroll(int $userId, string $dataKey): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        // The blob carries its own wrapping secret, so a base64 copy of it is as
        // good as the data key — held in a variable purely so it can be zeroed.
        $blob = $this->codec->wrap($dataKey);
        $encoded = base64_encode($blob);
        $encrypted = $this->system->encrypt($encoded);
        sodium_memzero($encoded);
        sodium_memzero($blob);

        // A safeStorage refusal must never fall back to writing the key in the
        // clear: no keychain, no enrollment.
        if (! is_string($encrypted) || $encrypted === '') {
            return false;
        }

        return $this->writePrivate($this->path($userId), $encrypted);
    }

    public function recover(int $userId, string $reason): ?string
    {
        if (! $this->isEnrolled($userId) || ! $this->biometrics->prompt($reason)) {
            return null;
        }

        $blob = $this->readBlob($userId);

        if ($blob === null) {
            return null;
        }

        $dataKey = $this->codec->unwrap($blob);
        sodium_memzero($blob);

        return $dataKey;
    }

    // Any failing step means this machine cannot open the file — most often
    // because another machine wrote it — so the lock screen gets a null.
    private function readBlob(int $userId): ?string
    {
        $stored = @file_get_contents($this->path($userId));

        if ($stored === false || $stored === '') {
            return null;
        }

        $decrypted = $this->system->decrypt($stored);

        if (! is_string($decrypted) || $decrypted === '') {
            return null;
        }

        $blob = base64_decode($decrypted, true);
        sodium_memzero($decrypted);

        return $blob === false ? null : $blob;
    }

    public function forget(int $userId): void
    {
        $path = $this->path($userId);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function path(int $userId): string
    {
        return UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.self::FILE_PREFIX.$userId.'.bin';
    }

    // chmod runs before any content lands, so the wrapped key is never briefly
    // world-readable.
    private function writePrivate(string $path, string $contents): bool
    {
        $directory = dirname($path);

        // Suppressed so the guard can decide: unsuppressed, Laravel's error handler
        // turns the E_WARNING into an ErrorException before mkdir() returns, and
        // enroll() throws out of a method whose contract is to answer false.
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return false;
        }

        if (! is_file($path)) {
            @touch($path);
        }

        @chmod($path, 0600);

        return @file_put_contents($path, $contents, LOCK_EX) !== false;
    }
}
