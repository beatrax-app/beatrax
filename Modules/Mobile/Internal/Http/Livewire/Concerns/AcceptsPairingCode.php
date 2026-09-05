<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire\Concerns;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use LogicException;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
use Modules\Sync\Public\Dto\PairingPeerIdentity;
use Modules\Sync\Public\Enums\PairingAcceptRefusal;
use Modules\Sync\Public\Enums\PairingOfferLookup;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Psr\Log\LoggerInterface;
use Throwable;

// The steps submitCode() walks: read who the code names, adopt them, check this
// device has an identity of its own, then either report the rejection or
// announce the acceptance. They sit here rather than on the component because
// extracting them took it past the method ceiling, and they move as one group.
/**
 * @phpstan-type InitiatorIdentity array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: ?string, relayPin: ?string, lanHost?: string, lanPort?: int}
 */
trait AcceptsPairingCode
{
    // Read the QR for EVERY scan, not just an import: the desktop learns of
    // this device only through the relay frame that follows acceptance, so
    // gating that on import mode made pairing from /sync a silent dead end.
    /**
     * @return InitiatorIdentity|false|null `false` when the code named an
     *                                      identity that could not be read,
     *                                      the reason already flashed; `null`
     *                                      when the entry box was empty.
     */
    private function initiatorIdentity(?string $scannedPayload, QrScanBridge $qrBridge, PairingGateway $gateway): array|false|null
    {
        if ($scannedPayload !== null) {
            $scanned = $qrBridge->extractIdentity($scannedPayload);

            if ($scanned === null) {
                $this->flashMessage = Lang::get($this->acceptRefusalKey(PairingAcceptRefusal::NotLiveHere));
            }

            return $scanned ?? false;
        }

        return $this->typedCodeIdentity($gateway);
    }

    // Asked for EVERY typed code, not just an import, for the reason the QR is
    // read on every scan: a typed code names a row that only ever existed on the
    // desktop that issued it, so with nothing seeded here accept() has nothing
    // to bind and a good code comes back "invalid or expired".
    /**
     * @return InitiatorIdentity|false|null `false` when the LAN was asked and
     *                                      answered no usable identity, the
     *                                      reason already flashed; `null` when
     *                                      it was never asked.
     */
    private function typedCodeIdentity(PairingGateway $gateway): array|false|null
    {
        // An empty box is not a code the network refused, and answering it with
        // a code error buries the real blocker: a phone with no app lock can
        // mint no identity at all, and that is what it needs to be told.
        if ($this->wordCode === '') {
            return null;
        }

        // A typed code carries the token alone, so ask the LAN for the public
        // half it cannot carry. An mDNS answer proves nothing — the
        // safety-number comparison is still the only trust gate.
        $discovered = $gateway->discoverInitiatorOnLan($this->wordCode);

        if ($discovered instanceof PairingOfferLookup) {
            // A typed code names no device, so a peer that answered and refused
            // may simply be the wrong desktop. "Invalid or expired" was the
            // confident version of that guess; this one is true either way.
            $this->flashMessage = Lang::get(match ($discovered) {
                PairingOfferLookup::CodeNotAccepted => 'mobile::pairing.errors.code_not_accepted',
                PairingOfferLookup::CodeMalformed => 'mobile::pairing.errors.code_incomplete',
                PairingOfferLookup::NoPeerReached => $this->nothingAnsweredKey($gateway),
                PairingOfferLookup::RateLimited => 'mobile::pairing.errors.rate_limited',
            });

            return false;
        }

        return $discovered;
    }

    // Nothing answered is the EXPECTED outcome on iOS, which drops the app's
    // own multicast query, so that reader is sent to the camera rather than to
    // their router. No line names a cause this device cannot observe: it knows
    // only that it asked and heard nothing back.
    /**
     * @link ../../../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
     */
    // Asked of the transport, not of the platform: reach() flips on its own the
    // day the entitlement lands, so the advice retires itself. The third line is
    // for the reader whose camera is the road that was refused — sending them
    // back to it is the order the amber notice above has already ruled out.
    private function nothingAnsweredKey(PairingGateway $gateway): string
    {
        if ($gateway->lanDiscoveryReach()->silenceMeansNoPeers()) {
            return 'mobile::pairing.errors.no_peer_answered';
        }

        return $this->cameraUnavailableNotice
            ? 'mobile::pairing.errors.no_peer_answered_camera_off'
            : 'mobile::pairing.errors.no_peer_answered_ios';
    }

    // The same rule one step later, for an accept that could not be handed
    // over. "Check the network" is advice only where a road existed to try; on
    // a phone that cannot browse, holding a code that named no address and no
    // relay, it sends the reader to fix what was never the reason.
    private function undeliveredAcceptKey(PairingGateway $gateway, string $tokenHash, string $peerDeviceId): string
    {
        return $gateway->hadAnyRoadTo($tokenHash, $peerDeviceId)
            ? 'mobile::pairing.errors.relay_unreachable'
            : 'mobile::pairing.errors.no_road_home';
    }

    /**
     * @param  InitiatorIdentity  $identity
     */
    private function adoptInitiator(PairingGateway $gateway, array $identity, int $userId): void
    {
        // Before accepting, so the responder-accept that follows already has
        // somewhere to deliver rather than failing on an unconfigured relay.
        $gateway->configureRelayFromQr($identity['relayEndpoint'], $identity['relayPin']);

        // Every phone holds a separate database from the desktop, so the
        // token issued over there is never present here. No trust decision:
        // the seeded row is Pending and still faces the whole ceremony.
        $gateway->seedResponderToken(
            $identity['token'],
            new PairingPeerIdentity(
                $identity['deviceId'],
                $identity['ed25519PubHex'],
                $identity['x25519PubHex'],
                // The scanned name, so the desktop is not admitted under the
                // "Paired device" placeholder the registry falls back to.
                $identity['deviceName'],
                // Absent on the QR road, which never touched the initiator: that
                // one arrives with a relay endpoint instead, and the sync dial
                // falls back to browsing for an address it was not handed.
                $identity['lanHost'] ?? null,
                $identity['lanPort'] ?? null,
            ),
            $userId,
        );
    }

    // Gated on the FILE, never on a null: null also means "locked", and
    // minting over a locked device's identity would orphan every pairing it
    // had.
    private function ownIdentityReady(PairingGateway $gateway, Session $session, int $userId): bool
    {
        if ($gateway->hasIdentityFile($userId)) {
            return true;
        }

        try {
            // Identity only, no epoch — a responder receives the initiator's
            // epochs on confirm; self-minting strands them.
            $gateway->enableSyncIdentityWithoutEpoch($userId, $session);

            return true;
        } catch (LogicException) {
            return false;
        }
    }

    // Only one ending may call a code unknown or expired. The other two are
    // refuted by what this submit saw — a live local row, or the minting
    // device answering for the code — and sending either reader off for a
    // fresh one ends a ceremony that was still running.
    private function acceptRefusalKey(PairingAcceptRefusal $refusal): string
    {
        return match ($refusal) {
            PairingAcceptRefusal::AlreadyUnderWay => 'mobile::pairing.errors.already_under_way',
            PairingAcceptRefusal::VouchedByIssuer => 'mobile::pairing.errors.vouched_but_refused',
            PairingAcceptRefusal::NotLiveHere => 'mobile::pairing.errors.invalid_code',
        };
    }

    /**
     * @param  InitiatorIdentity|null  $identity  The code's own identity where
     *                                            this submit read one, so the
     *                                            refusal can be classified.
     * @param  bool  $issuerServedItsOffer  True only on the typed arm, where
     *                                      the minting device answered for the
     *                                      code on this very submit.
     */
    private function reportRejectedCode(
        PairingGateway $gateway,
        UrlGenerator $urls,
        Session $session,
        AppLockClientConfig $lock,
        int $userId,
        ?array $identity,
        bool $issuerServedItsOffer,
    ): void {
        // Clear the attempt, not just the message: a token expiring mid-flow
        // otherwise leaves stale addressing in place and the next scan is
        // judged against a pairing that no longer exists.
        $this->resetPairingAttempt();

        // An unopenable identity means locked, not a bad code — sending that
        // user for a fresh QR is advice that can never work.
        if (! $gateway->hasUsableIdentity($userId, $session)) {
            $this->sendToUnlock($urls, $session, $lock, $userId);

            return;
        }

        $refusal = $identity === null
            ? PairingAcceptRefusal::NotLiveHere
            : $gateway->classifyAcceptRefusal($identity['token'], $userId, $issuerServedItsOffer);

        $this->flashMessage = Lang::get($this->acceptRefusalKey($refusal));
    }

    /**
     * @param  InitiatorIdentity  $identity
     */
    private function announceResponderAccept(
        PairingGateway $gateway,
        LoggerInterface $logger,
        array $identity,
        int $userId,
        Session $session,
    ): void {
        $tokenHash = hash('sha256', $identity['token']);

        // Stashed because the addressing dies with the component state and the
        // ceremony does not: without it the poll has nothing to re-emit to.
        $this->importResponderTokenHash = $tokenHash;
        $this->importDesktopDeviceId = $identity['deviceId'];

        try {
            $gateway->sendResponderAccept($userId, $tokenHash, $identity['deviceId'], $session);
        } catch (Throwable $e) {
            $logger->warning('MobilePairingScan: cross-device PAIR_RESPONDER_ACCEPT relay delivery failed.', [
                'pairing_token_id' => $this->pairingTokenId,
                'exception' => $e::class,
            ]);
        }
    }

    // This screen only ever plays responder, so the peer is the initiator —
    // the device whose QR was scanned.
    private function hydrateDeviceNames(PairingGateway $gateway, DeviceRegistryService $devices, int $userId): void
    {
        $names = $gateway->deviceNamesFor((int) $this->pairingTokenId, $userId);
        $fallback = Lang::get('mobile::pairing.peer_default_name');

        $this->selfDeviceName = $devices->localDeviceName($userId) ?? $fallback;
        $this->peerDeviceName = $names['initiator'] ?? $fallback;
    }
}
