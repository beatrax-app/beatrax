<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// The stored group-device keyring does not describe a usable state: no
// current epoch, an epoch with no key, or a payload that will not parse.
// Distinct from a crypto failure — the primitives worked and returned
// something the keyring's own invariants reject.
final class KeyringStateException extends RuntimeException
{
    private function __construct(public readonly KeyringState $state, string $message)
    {
        parent::__construct($message);
    }

    public static function noCurrentEpoch(int $userId): self
    {
        return new self(KeyringState::NoCurrentEpoch, "No current GDK epoch recorded for user {$userId}.");
    }

    public static function missingKeyForEpoch(int $userId, int $epochId): self
    {
        return new self(KeyringState::MissingKeyForEpoch, "GDK keyring for user {$userId} has no key for current epoch {$epochId}.");
    }

    public static function corruptPayload(int $userId): self
    {
        return new self(KeyringState::CorruptPayload, "Corrupt GDK keyring payload for user {$userId}.");
    }
}
