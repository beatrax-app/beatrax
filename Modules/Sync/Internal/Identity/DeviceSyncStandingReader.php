<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Modules\Sync\Public\Services\DeviceRegistryService;

// The one place the two halves of "is sync on here" are read together. The
// capture sink asked the key-file and the settings screen asked the registry,
// so a restored database — one half and not the other — read as enabled on
// screen and as never-enabled at the sink, which is where the writes went.
/**
 * @link ../../../../.docs/features/sync/device-identity-key-files.md#a-self-row-and-no-key-file-is-a-restored-database
 */
final readonly class DeviceSyncStandingReader
{
    public function __construct(
        private DeviceIdentityLoader $loader,
        private DeviceRegistryService $registry,
    ) {}

    // A file_exists and one covered index read. The capture path reaches this
    // on every mutation it cannot sign, and the read is what a device with a
    // restored self row costs to stop losing the reader's writes.
    public function forUser(int $userId): DeviceSyncStanding
    {
        $registered = $this->registry->localDeviceId($userId) !== null;

        if ($this->loader->exists($userId)) {
            return $registered ? DeviceSyncStanding::Enabled : DeviceSyncStanding::RegistrationMissing;
        }

        return $registered ? DeviceSyncStanding::IdentityMissing : DeviceSyncStanding::NeverEnabled;
    }
}
