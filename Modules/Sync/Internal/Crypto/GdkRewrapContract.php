<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
interface GdkRewrapContract
{
    // Re-wraps the user's entire GDK keyring (every epoch) under $newKek. A
    // clean no-op when the user has no keyring file yet — never fabricates a
    // keyring where none existed. $oldKek/$newKek are raw wrap-key bytes;
    // implementations must sodium_memzero() both before returning.
    public function rewrap(int $userId, string $oldKek, string $newKek): void;
}
