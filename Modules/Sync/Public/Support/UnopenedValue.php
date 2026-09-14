<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Support;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#what-the-residue-sweep-cannot-see
 */
final class UnopenedValue
{
    // The codec answers '' for a value shaped like ciphertext that no epoch
    // here opened. The STORED value decides it, not the `decrypted` flag:
    // that flag is false for a pre-encryption row too, where the value handed
    // back IS the value and must not be refused.
    public static function wasBlanked(mixed $stored, ?string $opened): bool
    {
        return is_string($stored) && $stored !== '' && $opened === '';
    }
}
