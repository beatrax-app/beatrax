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

    // The single question a caller persisting key material must ask. Only the
    // operating-system case answers yes: the other two say the key bytes are
    // recoverable from the same disk the ciphertext sits on.
    public function protectsAtRest(): bool
    {
        return $this === self::OperatingSystem;
    }
}
