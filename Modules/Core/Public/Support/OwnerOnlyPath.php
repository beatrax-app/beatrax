<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/architecture/owner-only-paths.md
 */
final readonly class OwnerOnlyPath
{
    // 0666 & ~0177 is 0600, so a file opened under this mask is born private
    // rather than spending the window between creation and chmod at 0644,
    // which is where a cohabiting reader gets its handle.
    private const int BIRTH_UMASK = 0o177;

    public function __construct(private ?LoggerInterface $logger = null) {}

    public function directory(string $path): bool
    {
        if (! is_dir($path) && ! @mkdir($path, SecretFileMode::DIRECTORY, true) && ! is_dir($path)) {
            return $this->refuse($path, SecretFileMode::DIRECTORY);
        }

        return $this->settle($path, SecretFileMode::DIRECTORY);
    }

    public function file(string $path): bool
    {
        if (! is_file($path)) {
            $previous = umask(self::BIRTH_UMASK);
            $handle = @fopen($path, 'cb');
            umask($previous);

            if ($handle === false) {
                return $this->refuse($path, SecretFileMode::FILE);
            }

            fclose($handle);
        }

        return $this->settle($path, SecretFileMode::FILE);
    }

    // The observed mode decides, not chmod()'s answer: a filesystem that
    // reports success and stores something wider — exFAT, SMB, a Windows
    // share — is exactly the case a return-value check cannot see.
    private function settle(string $path, int $mode): bool
    {
        @chmod($path, $mode);
        clearstatcache(true, $path);

        if (self::observedMode($path) === $mode) {
            return true;
        }

        return $this->refuse($path, $mode);
    }

    private function refuse(string $path, int $mode): bool
    {
        $observed = self::observedMode($path);

        $this->logger?->error('A path holding private user data is not owner-only.', [
            'path' => $path,
            'expected_mode' => sprintf('%04o', $mode),
            'observed_mode' => $observed === null ? 'unreadable' : sprintf('%04o', $observed),
            'remedy' => sprintf('chmod %o %s', $mode, $path),
        ]);

        return false;
    }

    private static function observedMode(string $path): ?int
    {
        $permissions = @fileperms($path);

        return $permissions === false ? null : $permissions & 0o777;
    }
}
