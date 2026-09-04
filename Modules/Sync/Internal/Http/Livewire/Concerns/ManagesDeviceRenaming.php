<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire\Concerns;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Sync\Public\Services\DeviceRegistryService;

trait ManagesDeviceRenaming
{
    public function startRename(int|string $deviceId): void
    {
        $deviceId = DerivedRowId::fromWire($deviceId);

        $this->renamingDeviceId = $deviceId;
        $this->renameValue = $this->currentNameFor($deviceId);
    }

    public function cancelRename(): void
    {
        $this->renamingDeviceId = null;
        $this->renameValue = '';
    }

    // Both the inline-edit path (renamingDeviceId + renameValue) and the
    // direct (id, name) call shape route through here so a direct
    // call('renameDevice', $id, 'New Name') also works.
    public function renameDevice(
        DatabaseManager $db,
        CurrentUser $currentUser,
        DeviceRegistryService $registry,
        ?int $deviceId = null,
        ?string $name = null,
    ): void {
        $targetId = $deviceId ?? $this->renamingDeviceId;
        $newName = trim($name ?? $this->renameValue);

        if ($targetId === null || $newName === '') {
            $this->cancelRename();

            return;
        }

        $userId = $currentUser->user()->id;

        $db->connection()->table('device_registry')
            ->where('id', $targetId)
            ->where('user_id', $userId)
            ->update(['name' => $newName]);

        $this->devices = $this->loadDevices($registry, $userId);
        $this->cancelRename();
    }

    // Public — Surface D's revocation modal sub-label ("Removing: {name}")
    // reads this from the blade view.
    public function currentNameFor(int $deviceId): string
    {
        foreach ($this->devices as $device) {
            if (($device['id'] ?? null) === $deviceId) {
                $name = $device['name'] ?? '';

                return is_string($name) ? $name : '';
            }
        }

        return '';
    }
}
