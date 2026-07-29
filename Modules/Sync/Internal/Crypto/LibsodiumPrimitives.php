<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

// The real implementation: a direct pass-through to libsodium. It holds no
// state and makes no decisions, so there is nothing here to test beyond the
// fact that it forwards.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class LibsodiumPrimitives implements SodiumPrimitives
{
    public function binToHex(string $bin): string
    {
        return sodium_bin2hex($bin);
    }

    public function hexToBin(string $hex): string
    {
        return sodium_hex2bin($hex);
    }
}
