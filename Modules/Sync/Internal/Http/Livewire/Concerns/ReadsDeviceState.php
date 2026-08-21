<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire\Concerns;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Public\Services\DeviceRegistryService;

trait ReadsDeviceState
{
    private function selfRowExists(DatabaseManager $db, int $userId): bool
    {
        return $db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->exists();
    }

    // sync_encryption_state is device-local and never synced, so a raw
    // scoped read here mirrors selfRowExists()'s own precedent rather than
    // reaching into EncryptionMigrationService::isEnabled() for a value this
    // component can read in one query already.
    private function encryptionEnabled(DatabaseManager $db, int $userId): bool
    {
        $value = $db->connection()->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->value('current_epoch');

        return $value !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDevices(DeviceRegistryService $registry, int $userId): array
    {
        $rows = $registry->confirmedDevices($userId);

        $devices = [];
        foreach ($rows as $row) {
            $devices[] = [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
                'safety_number_words' => is_string($row->safety_number_words) ? $row->safety_number_words : '',
                'paired_at' => is_string($row->paired_at) ? $row->paired_at : '',
                'is_self' => is_numeric($row->is_self) && (int) $row->is_self === 1,
                'confirmed' => $row->confirmed_at !== null,
                'removed' => false,
            ];
        }

        return $devices;
    }
}
