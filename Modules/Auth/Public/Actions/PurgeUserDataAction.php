<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Database\Connection;
use Modules\Auth\Internal\Account\UserScopedDataPurge;

// Credential-free on purpose: a developer command wiping its own fixtures has
// no password to check, and without this seam it wrote a second, shorter table
// list that went stale and left 9,765 orphaned rows on a reseeded device.
/**
 * @link ../../../../.docs/features/auth/user-scoped-purge.md
 */
final readonly class PurgeUserDataAction
{
    public function __construct(private UserScopedDataPurge $purge) {}

    public function __invoke(Connection $connection, int $userId): void
    {
        ($this->purge)($connection, $userId, $this->deviceIdsOf($connection, $userId));
    }

    // Read before the sweep runs: relay_mailbox is addressed by device id, and
    // the registry rows naming those ids are gone by the time it is swept.
    /** @return list<string> this account's own device identifiers */
    private function deviceIdsOf(Connection $connection, int $userId): array
    {
        if (! $connection->getSchemaBuilder()->hasTable('device_registry')) {
            return [];
        }

        $deviceIds = [];

        foreach ($connection->table('device_registry')->where('user_id', $userId)->pluck('device_id') as $deviceId) {
            if (is_string($deviceId)) {
                $deviceIds[] = $deviceId;
            }
        }

        return $deviceIds;
    }
}
