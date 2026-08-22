<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// Refuses a mint that would overwrite a key-file the held KEK cannot open.
// The secret keys inside it still sign this device's whole op-log history,
// and the database that wraps the KEK opening them may yet be restored, so
// replacing them is a decision only the user gets to take.
final class DeviceIdentityUnreadableException extends RuntimeException
{
    public static function willNotOverwrite(int $userId): self
    {
        return new self(
            "The device identity key-file for user {$userId} exists but does not open under the app-lock key this device holds; it is not overwritten.",
        );
    }

    public static function couldNotRetire(string $path): self
    {
        return new self("Could not move the unreadable device identity key-file aside: {$path}");
    }
}
