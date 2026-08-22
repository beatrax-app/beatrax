<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing\Concerns;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Pairing\PairingRowGuards;
use Modules\Sync\Public\Enums\PairingSide;

// Every question a pairing screen asks PairingGateway that is answered out of
// this device's own storage: no frame leaves, and no trust state moves. That
// rule is what makes these safe to call from a poll handler on every tick.
/**
 * @link ../../../../../.docs/features/sync/pairing-handshake.md
 */
trait ReportsLocalPairingState
{
    // False both when the identity was never minted and when the app-lock holds
    // the KEK. Callers that MINT must ask hasIdentityFile() instead.
    public function hasUsableIdentity(int $userId, Session $session): bool
    {
        return $this->identityLoader->load($userId, $session) !== null;
    }

    // Callers that mint must gate on this, not on a null from acceptToken() or the
    // loader — that null also means "locked".
    public function hasIdentityFile(int $userId): bool
    {
        return $this->identityLoader->exists($userId);
    }

    // The confirming side must be derived from this device's own identity, never
    // from a client-supplied value. Null when locked or sync was never enabled.
    public function currentDeviceId(int $userId, Session $session): ?string
    {
        return $this->identityLoader->load($userId, $session)?->deviceId;
    }

    // Null means neither side: the row belongs to two other devices, or this one is
    // locked and cannot say who it is. Exposed so no caller derives the side twice.
    public function sideOwnedBySelf(
        ?string $initiatorDeviceId,
        ?string $responderDeviceId,
        int $userId,
        Session $session,
    ): ?PairingSide {
        return PairingRowGuards::sideOwnedByIds(
            $initiatorDeviceId,
            $responderDeviceId,
            $this->currentDeviceId($userId, $session) ?? '',
        );
    }

    // Whether THIS device has already confirmed, asked of the row rather than
    // of a screen: a component that forgot it tapped would stop re-emitting the
    // confirm, and the peer would wait for a frame that never comes again.
    public function hasConfirmedLocally(int $tokenId, int $userId, Session $session): bool
    {
        $deviceId = $this->currentDeviceId($userId, $session);

        return $deviceId !== null && $this->rows->selfConfirmedAt($tokenId, $userId, $deviceId) !== null;
    }

    // Either name may be null on a row written before that column existed, so
    // the caller decides the placeholder rather than this gateway.
    /**
     * @return array{initiator: ?string, responder: ?string}
     */
    public function deviceNamesFor(int $pairingTokenId, int $userId): array
    {
        return $this->rows->deviceNames($pairingTokenId, $userId);
    }

    // The six words the row's currently bound keys derive to, so a screen can
    // re-present what is actually being confirmed rather than what it captured
    // before a rebind moved the keys underneath it.
    /**
     * @return list<string>
     */
    public function safetyWordsFor(int $tokenId, int $userId): array
    {
        return $this->rows->safetyWords((string) $tokenId, $userId);
    }

    // Asked before anything that restarts the listener daemon: a handshake is
    // served by the process holding the socket, so replacing it mid-ceremony
    // drops the very connection the ceremony is waiting on (see @link).
    /**
     * @link ../../../../../.docs/features/sync/pairing-handshake.md#opening-the-pairing-screen-must-not-restart-the-listener
     */
    public function hasLiveHandshake(int $userId): bool
    {
        return $this->rows->hasLiveHandshake($userId);
    }

    // Screens hold their step in component state, which a reload wipes while the row
    // carries on, so callers resume from here rather than restarting the ceremony.
    /**
     * @return array{id: int, state: string, safety_words: list<string>, token_hash: string, peer_device_id: string, initiator_device_id: string|null, responder_device_id: string|null, initiator_confirmed: bool, responder_confirmed: bool}|null
     */
    public function inFlightFor(int $userId): ?array
    {
        return $this->rows->inFlight($userId);
    }

    public function tokenState(int $tokenId, int $userId): ?string
    {
        return $this->rows->state($tokenId, $userId);
    }
}
