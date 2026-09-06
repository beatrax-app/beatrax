<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire\Concerns;

use Livewire\Attributes\Locked;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PeerLanAddressBook;

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

        $parsed = self::parseManualAddress($typed);

        if ($parsed === null) {
            $this->manualPeerFlashMessage = Lang::get('sync::devices.flash.manual_peer_invalid');

            return;
        }

        $addresses->setManual($userId, $peerDeviceId, $parsed['host'], $parsed['port']);
        $this->manualPeerAddress = $parsed['host'].':'.$parsed['port'];
        $this->manualPeerFlashMessage = Lang::get('sync::devices.flash.manual_peer_saved');
    }

    // An address, never a URL: the dial builds its own `ws://` around this, so
    // a scheme or a path typed here would be carried into a string nothing can
    // parse back out. Split at the LAST colon, the port separator on every
    // form this accepts.
    /**
     * @return array{host: string, port: int}|null
     */
    private static function parseManualAddress(string $typed): ?array
    {
        $at = strrpos($typed, ':');

        if ($at === false || $at === 0 || $at === strlen($typed) - 1) {
            return null;
        }

        $host = substr($typed, 0, $at);
        $port = substr($typed, $at + 1);

        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            return null;
        }

        return self::isDialableHost($host) ? ['host' => $host, 'port' => (int) $port] : null;
    }

    private static function isDialableHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return PatternScan::matches(
            '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/',
            $host,
        );
    }

    // One peer, matching what the dial itself assumes: PeerLanAddress reads the
    // first other device and no other, so a second one here would offer a
    // reader an address nothing would ever dial.
    private function firstPeerDeviceId(DeviceRegistryService $registry, int $userId): ?string
    {
        return array_key_first($registry->otherDeviceNames($userId));
    }
}
