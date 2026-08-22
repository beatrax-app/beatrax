<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Support;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\OpLog\SyncBacklogState;
use Modules\Sync\Public\Enums\PairingSide;
use Modules\Sync\Public\Services\EncryptionRecoveryMarkers;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Modules\Sync\Public\Services\HistoryReprojector;
use Modules\Sync\Public\Services\PairingGateway;
use Throwable;

// What opening the devices screen is worth to the sync machinery, as opposed to
// what it puts on screen. Every answer here needs an unlocked session, which is
// exactly what the listener and the daemon that would otherwise do this work
// never have.
final readonly class DevicesScreenOpening
{
    public function __construct(
        private PairingGateway $pairing,
        private GdkEpochDeliveryGateway $epochDelivery,
        private HistoryReprojector $reprojector,
        private EncryptionRecoveryMarkers $markers,
    ) {}

    // The listener that receives these can never open one: it resolves a
    // session no middleware ever started, so its app-lock key is absent by
    // construction. This is the unlocked pass that comes back for what it had
    // to leave in the mailbox.
    public function applyHeldKeyWraps(int $userId, Session $session): void
    {
        $deviceId = $this->pairing->currentDeviceId($userId, $session);
        if ($deviceId === null) {
            return;
        }

        $this->epochDelivery->drainInbox($userId, $deviceId, $session);
    }

    // A ceremony lives on the row, not in the modal that started it, so an
    // auto-lock or a navigation loses the screen and not the handshake. The
    // page has to say so: the token expires in minutes, and the modal's own
    // resume is behind a button that reads as starting a new pairing.
    /**
     * @link ../../../../.docs/features/sync/pairing-handshake.md#a-ceremony-outlives-the-screen-that-started-it
     */
    public function peerWaitingOnThisDevice(int $userId, Session $session): string
    {
        $inFlight = $this->pairing->inFlightFor($userId);

        if ($inFlight === null || $inFlight['state'] === PairingGateway::STATE_CONFIRMED) {
            return '';
        }

        $side = $this->pairing->sideOwnedBySelf(
            $inFlight['initiator_device_id'],
            $inFlight['responder_device_id'],
            $userId,
            $session,
        );

        if ($side === null) {
            return '';
        }

        $names = $this->pairing->deviceNamesFor($inFlight['id'], $userId);

        return match ($side->peer()) {
            PairingSide::Initiator => $names['initiator'],
            PairingSide::Responder => $names['responder'],
        } ?? Lang::get('sync::devices.peer_default_name');
    }

    // Asked on open rather than on render: render runs on every poll, and the
    // question costs an index seek plus a keyring read. The recovery pass runs
    // after that response, so a Deferred reported here is gone by the next one
    // — which is the transient the reader is being shown.
    public function backlog(int $userId, Session $session): SyncBacklogState
    {
        if (! $this->markers->isEnrolled($userId)) {
            return SyncBacklogState::None;
        }

        try {
            return $this->reprojector->backlogState(
                $userId,
                $session,
                $this->markers->historyReprojectedAt($userId),
                $this->markers->reprojectedKeyringFingerprint($userId),
            );
        } catch (Throwable) {
            return SyncBacklogState::None;
        }
    }
}
