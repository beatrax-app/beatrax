<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Enums;

// PlatformStoreDoesNotProtect is kept apart from Session because both leave the
// key in session custody. Read as one, a bundle whose keychain is a plaintext
// stand-in looks like a self-hosted install that never had one, and the build
// that shipped it reports the protection it was supposed to add.
enum KeyCustody: string
{
    case Session = 'session';

    case OperatingSystem = 'operating_system';

    case PlatformStoreDoesNotProtect = 'platform_store_does_not_protect';

    // Kept apart from the case above for the same reason that one is kept apart
    // from Session: a store that refused the write is holding nothing at all,
    // where PlatformStoreDoesNotProtect is holding the key under protection
    // worth nothing. A reader told they are unprotected is owed the difference.
    case PlatformStoreRefusedTheWrite = 'platform_store_refused_the_write';

    // The single question a caller persisting key material must ask. Only the
    // operating-system case answers yes: the other two say the key bytes are
    // recoverable from the same disk the ciphertext sits on.
    public function protectsAtRest(): bool
    {
        return $this === self::OperatingSystem;
    }
}
