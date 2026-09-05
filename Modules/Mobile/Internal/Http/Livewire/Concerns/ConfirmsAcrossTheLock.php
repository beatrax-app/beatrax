<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire\Concerns;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Public\Enums\PairingFrameSend;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;

// What the trust gate does when the app-lock lands on top of it. `mobile.pair`
// is exempt from the lock redirect on purpose, so the ceremony keeps its screen
// and loses its key at the same moment — and everything here exists so that
// neither the sending nor the reader's own tap goes quietly missing.
/**
 * @link ../../../../../../.docs/features/sync/pairing-handshake.md#a-ceremony-that-cannot-speak-says-so
 */
trait ConfirmsAcrossTheLock
{
    // The one place that turns "this device holds no identity it can open" into
    // a sentence, because the remedy differs: a PIN pad answers a locked one and
    // strands a device that never had a lock to unlock.
    private function identityUnavailableNotice(AppLockClientConfig $lock, int $userId): string
    {
        return Lang::get($lock->isEnabled($userId)
            ? 'mobile::pairing.errors.identity_locked'
            : 'mobile::pairing.errors.identity_needs_lock');
    }

    // The line a send that never left puts on the confirm step. Two endings
    // draw none: Sent has nothing to report, and NoLocalCeremony is what the
    // poll's own state branch answers a breath later, where saying it here too
    // would be two messages for one fact.
    private function frameSendNotice(PairingFrameSend $send, AppLockClientConfig $lock, int $userId): string
    {
        return match ($send) {
            PairingFrameSend::Sent, PairingFrameSend::NoLocalCeremony => '',
            PairingFrameSend::NoUsableIdentity => $this->identityUnavailableNotice($lock, $userId),
        };
    }

    // The fingerprint of the six words the human actually compared, kept
    // because the tap outlives the unlock and the row may not: re-deriving on
    // the way back binds the confirmation to whatever the row says then, which
    // is the rebind confirm() refuses for.
    private function deferConfirmAcrossUnlock(Session $session, string $digest): void
    {
        $session->put(self::DEFERRED_CONFIRM_SESSION, [
            'pairing_token_id' => $this->pairingTokenId,
            'safety_digest' => $digest,
        ]);
    }

    // Finishes the tap the lock interrupted, on the page load the unlock
    // redirects into. Deliberately does not advance to success even when the
    // peer had already confirmed: the poll owns that transition and the
    // settlement behind it, and a second owner is a second policy.
    private function applyDeferredConfirm(
        PairingGateway $gateway,
        Session $session,
        DatabaseManager $db,
        LoggerInterface $logger,
        AppLockClientConfig $lock,
        int $userId,
    ): void {
        $deferred = $session->get(self::DEFERRED_CONFIRM_SESSION);

        if (! is_array($deferred)) {
            return;
        }

        $tokenId = $deferred['pairing_token_id'] ?? null;
        $digest = $deferred['safety_digest'] ?? null;

        // Bound to the token the tap was made against: a ceremony that ended
        // and a fresh one that started while the reader was at the PIN pad must
        // not inherit a confirmation given to the old one.
        if (! is_string($tokenId) || ! is_string($digest) || $tokenId !== $this->pairingTokenId) {
            return;
        }

        $deviceId = $gateway->currentDeviceId($userId, $session);

        // Kept rather than spent while the identity is still sealed. This
        // screen is reachable locked, so discarding here would lose the same
        // tap a second time.
        if ($deviceId === null) {
            return;
        }

        $session->forget(self::DEFERRED_CONFIRM_SESSION);

        if ($this->recordConfirmation($digest, $deviceId, $gateway, $userId) !== null) {
            $this->sendConfirmToPeer($gateway, $userId, $db, $session, $logger, $lock);
        }
    }

    // The single writer of this device's own confirmation, so a tap made now
    // and a tap carried across an unlock are bound the same way and refused the
    // same way. The peer frame is the caller's to send: only it knows whether
    // there is a wizard to advance behind it.
    /**
     * @return string|null the resulting pairing state, or null where the tap
     *                     was refused because the keys behind the compared
     *                     words are no longer the ones the row binds.
     */
    private function recordConfirmation(
        string $digest,
        string $deviceId,
        PairingGateway $gateway,
        int $userId,
    ): ?string {
        $state = $gateway->confirm((int) $this->pairingTokenId, $userId, $deviceId, $digest);

        // Silence here reads as "waiting for the other device", which is how a
        // responder that rebinds stalls a ceremony unseen.
        if ($state === null) {
            $this->awaitingPeer = false;
            $this->safetyWords = $gateway->safetyWordsFor((int) $this->pairingTokenId, $userId);
            $this->flashMessage = Lang::get('mobile::pairing.errors.safety_number_changed');

            return null;
        }

        $this->awaitingPeer = $state !== PairingGateway::STATE_CONFIRMED;

        return $state;
    }
}
