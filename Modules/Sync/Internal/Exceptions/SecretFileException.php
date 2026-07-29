<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// Key material could not be staged, locked down, read back or renamed
// into place. Worth its own type because the recovery differs from every
// other failure here: the secret may be on disk in a state the caller has
// to reason about, and one site deliberately leaves its temp file behind.
final class SecretFileException extends RuntimeException
{
    public static function couldNotStage(string $path): self
    {
        return new self("Failed to stage secret material at: {$path}");
    }

    public static function couldNotLockDown(string $path): self
    {
        return new self("Cannot chmod secret material to 0600 (would be left readable): {$path}");
    }

    public static function couldNotFinalizeKeyring(int $userId): self
    {
        return new self("Could not finalize the GDK keyring file for user {$userId}.");
    }

    public static function couldNotReadIdentity(): self
    {
        return new self('Failed to read the decrypted device identity key-file.');
    }
}
