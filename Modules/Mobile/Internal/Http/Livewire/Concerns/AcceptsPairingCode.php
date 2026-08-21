<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire\Concerns;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use LogicException;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
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
 * @phpstan-type InitiatorIdentity array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: ?string, relayAuthToken: ?string, relayPin: ?string}
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
     *                                      when a typed code carries none.
     */
    private function initiatorIdentity(?string $scannedPayload, QrScanBridge $qrBridge, PairingGateway $gateway): array|false|null
    {
        if ($scannedPayload !== null) {
            $scanned = $qrBridge->extractIdentity($scannedPayload);

            if ($scanned === null) {
                $this->flashMessage = Lang::get('mobile::pairing.errors.invalid_code');
            }

            return $scanned ?? false;
        }

        if (! $this->importMode) {
            return null;
        }

        // A typed code carries the token alone, so ask the LAN for the public
        // half it cannot carry. An mDNS answer proves nothing — the
        // safety-number comparison is still the only trust gate.
        $discovered = $gateway->discoverInitiatorOnLan($this->wordCode);

        if ($discovered instanceof PairingOfferLookup) {
            // Both endings used to say "cannot reach the other device", which
            // is advice that can only ever work for one of them: a reader whose
            // code had expired was sent to debug a network that was fine.
            $this->flashMessage = Lang::get(match ($discovered) {
                PairingOfferLookup::CodeNotAccepted => 'mobile::pairing.errors.invalid_code',
                PairingOfferLookup::NoPeerReached => 'mobile::pairing.errors.relay_unreachable',
            });

            return false;
        }

        return $discovered;
    }

    /**
     * @param  InitiatorIdentity  $identity
     */
    private function adoptInitiator(PairingGateway $gateway, array $identity, int $userId): void
    {
        // Before accepting, so the send that follows has somewhere to deliver.
        $gateway->configureRelayFromQr($identity['relayEndpoint'], $identity['relayAuthToken'], $identity['relayPin']);

        // Every phone holds a separate database from the desktop, so the
        // token issued over there is never present here. No trust decision:
        // the seeded row is Pending and still faces the whole ceremony.
        $gateway->seedResponderToken(
            $identity['token'],
            $identity['deviceId'],
            $identity['ed25519PubHex'],
            $identity['x25519PubHex'],
            $userId,
            // Without it the desktop is admitted as "Paired device".
            $identity['deviceName'],
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

    private function reportRejectedCode(
        PairingGateway $gateway,
        UrlGenerator $urls,
        Session $session,
        AppLockClientConfig $lock,
        int $userId,
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

        $this->flashMessage = Lang::get('mobile::pairing.errors.invalid_code');
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

        // Stashed so the poll can re-emit if this delivery is lost.
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
