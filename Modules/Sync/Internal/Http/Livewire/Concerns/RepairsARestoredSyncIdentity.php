<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire\Concerns;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;

// The way out of a restored database that registered this device as a sync
// peer and could not bring the key-file that answers for it. Kept off the host
// component, which sits on the method ceiling the analyser enforces, and
// beside the three arms that were moved off it for the same reason.
/**
 * @link ../../../../../../.docs/features/sync/device-identity-key-files.md#a-self-row-and-no-key-file-is-a-restored-database
 */
trait RepairsARestoredSyncIdentity
{
    // A self row this device holds no key-file for: the database says this
    // device is a peer and nothing it writes can be signed yet. Locked,
    // because a payload naming it false sends the ordinary enable at a
    // restored self row, and that mints a second row claiming to be self.
    #[Locked]
    public bool $registeredWithoutIdentity = false;

    // Not the ordinary enable, which would mint beside the restored row and
    // leave two rows claiming is_self — a second identity to every reader that
    // takes the first one it finds. The old registration is retired first, in
    // the order the destructive half cannot be skipped.
    public function repairRestoredSyncIdentity(
        CurrentUser $currentUser,
        DeviceIdentityService $identityService,
        Session $session,
        DatabaseManager $db,
        DeviceRegistryService $registry,
        EncryptionMigrationService $migrationService,
        LoggerInterface $logger,
    ): void {
        // Offered only where the blade offers it, and refused where a key-file
        // has since appeared: the service refuses that too, and a refusal that
        // reached enableSync() would mint the second self row this exists to
        // prevent.
        if (! $this->registeredWithoutIdentity || $this->syncEnabled) {
            return;
        }

        // Before the retirement, not after it: the enable below refuses on
        // this and the retirement is what keeps the writes deferring, so
        // retiring first would leave a device that owes nothing and drops
        // what it writes — the state this whole file exists to end.
        if (! $this->appLockConfigured) {
            $this->flashMessage = Lang::get('sync::devices.flash.app_lock_first');

            return;
        }

        if (! $identityService->retireSelfRegistration($currentUser->user()->id)) {
            $this->flashMessage = Lang::get('sync::devices.flash.identity_replace_failed');

            return;
        }

        // Straight on through the ordinary enable, so a restored device lands
        // in the state a first-time enable leaves: minted identity, one self
        // row, at-rest encryption activated. The coordinates its writes left
        // behind drain on the next request, which can now sign them.
        $this->registeredWithoutIdentity = false;
        $this->enableSync($currentUser, $identityService, $session, $db, $registry, $migrationService, $logger);

        if ($this->syncEnabled) {
            $this->flashMessage = Lang::get('sync::devices.flash.identity_replaced');
        }
    }
}
