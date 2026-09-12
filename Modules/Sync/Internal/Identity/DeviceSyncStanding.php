<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

// What this device is to a peer, which neither the key-file nor the registry
// answers alone. A restored database carries the old machine's self row and
// never its key-file, so the two disagree — and the sink that read only the
// file called that install a device nobody was owed anything by.
/**
 * @link ../../../../.docs/features/sync/device-identity-key-files.md#a-self-row-and-no-key-file-is-a-restored-database
 */
enum DeviceSyncStanding
{
    case NeverEnabled;

    case IdentityMissing;

    case RegistrationMissing;

    case Enabled;

    // Enabled is the only standing that may present as sync being on: the
    // other three each have a different way back, and two of them would mint
    // over something if the ordinary enable were offered.
    public function presentsAsEnabled(): bool
    {
        return $this === self::Enabled;
    }

    // Every standing but NeverEnabled owes a peer the writes it cannot sign.
    // A device with neither half has no peer to owe, and switching sync on
    // walks the whole database anyway.
    public function owesAPeerItsWrites(): bool
    {
        return $this !== self::NeverEnabled;
    }
}
