<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire\Concerns;

use Livewire\Attributes\Locked;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PeerLanAddressBook;
use Modules\Sync\Public\Support\PeerAddress;

// Rung two of the transport ladder, kept off the host component: it was already
// sitting on the method ceiling the analyser enforces, and this arm is four
// methods that only ever move together.
trait ManagesManualPeerAddress
{
    // As `host:port`. Empty means the ladder has two rungs on this device, not
    // three: discovery, then a guess at the peer derived from the relay host.
    public string $manualPeerAddress = '';

    public string $manualPeerFlashMessage = '';

    // Without a confirmed peer there is nothing for an address to point at, and
    // a field that can only ever answer "no peer yet" is worse than no field.
    #[Locked]
    public bool $hasPeerDevice = false;

    // The one rung of the ladder a reader can supply. A network that blocks
    // mDNS leaves discovery with nothing to return, and without this the dial
    // falls through to an address guessed from the relay endpoint's host. An
    // empty field takes the rung back out.
    public function saveManualPeerAddress(
        PeerLanAddressBook $addresses,
        DeviceRegistryService $registry,
        CurrentUser $currentUser,
    ): void {
        $userId = $currentUser->user()->id;
        $peerDeviceId = $this->firstPeerDeviceId($registry, $userId);

        if ($peerDeviceId === null) {
            $this->manualPeerFlashMessage = Lang::get('sync::devices.flash.manual_peer_no_peer');

            return;
        }

        $typed = trim($this->manualPeerAddress);

        if ($typed === '') {
            $addresses->setManual($userId, $peerDeviceId, null, null);
            $this->manualPeerAddress = '';
            $this->manualPeerFlashMessage = Lang::get('sync::devices.flash.manual_peer_cleared');

            return;
        }

        $parsed = PeerAddress::parse($typed);

        if ($parsed === null) {
            $this->manualPeerFlashMessage = Lang::get('sync::devices.flash.manual_peer_invalid');

            return;
        }

        $addresses->setManual($userId, $peerDeviceId, $parsed->host, $parsed->port);
        $this->manualPeerAddress = $parsed->value();
        $this->manualPeerFlashMessage = Lang::get('sync::devices.flash.manual_peer_saved');
    }

    // One peer, matching what the dial itself assumes: PeerLanAddress reads the
    // first other device and no other, so a second one here would offer a
    // reader an address nothing would ever dial.
    private function firstPeerDeviceId(DeviceRegistryService $registry, int $userId): ?string
    {
        return array_key_first($registry->otherDeviceNames($userId));
    }
}
