<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use SodiumException;

// The libsodium conversions the keyring, rotation and identity paths run
// inside their try-blocks, behind an interface so a test can make them fail.
// Called directly the surrounding catch is unreachable: every argument has had
// its length fixed upstream, so nothing a caller builds makes libsodium throw.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
interface SodiumPrimitives
{
    /**
     * @throws SodiumException
     */
    public function binToHex(string $bin): string;

    /**
     * @throws SodiumException
     */
    public function hexToBin(string $hex): string;
}
