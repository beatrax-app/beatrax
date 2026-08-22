<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;
use Throwable;

// Carries an open ceremony forward with no pairing screen anywhere. It only
// collects what is already addressed to this device and re-sends a confirmation
// this device's own human already gave; it mints no confirmation of its own,
// which is what lets a driver holding no signing key run it (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#redelivery-must-not-depend-on-an-open-screen
 */
final readonly class PendingPairingCourier
{
    public function __construct(
        private PairingTokenRowReader $rows,
        private DeviceRegistryService $devices,
        private PairingFrameCourier $frameCourier,
        private LanPairingFramePuller $puller,
        private ?LoggerInterface $logger = null,
    ) {}

    // False means nothing was open to carry, and that is the entire stop rule:
    // confirming, cancelling and lapsing all leave the live states behind, so a
    // ceremony the user abandoned has nothing here to resurrect it.
    /**
     * @param  ?DeviceIdentityDto  $identity  Null on a driver that cannot open
     *                                        the sealed key file — the daemon.
     *                                        Collection still runs; nothing
     *                                        that needs a signature does.
     */
    public function tick(int $userId, ?DeviceIdentityDto $identity): bool
    {
        $selfDeviceId = $identity->deviceId ?? $this->devices->localDeviceId($userId);

        if ($selfDeviceId === null || $selfDeviceId === '') {
            return false;
        }

        $live = $this->rows->liveCeremonyOwnedBy($userId, $selfDeviceId);

        if ($live === null) {
            return false;
        }

        // Outward before inward, and the order is load-bearing: collecting first
        // can finish the ceremony here, and a finished row is this courier's stop
        // signal, so the tick that completed it would exit without ever offering
        // this device's own confirmation.
        $this->reEmitOwnConfirm($userId, $live, $identity);
        $this->collect($userId, $identity);

        return true;
    }

    // Both inbound roads, in the order they can deliver: the relay mailbox any
    // device may read, then the peer's holding space, which only a device that
    // can prove its own identity may ask for.
    private function collect(int $userId, ?DeviceIdentityDto $identity): void
    {
        $this->frameCourier->drainAndApply($userId);

        if ($identity === null) {
            return;
        }

        try {
            $this->puller->pullAndApply($userId, $identity);
        } catch (Throwable $e) {
            $this->warn('PendingPairingCourier: collecting held frames from a peer failed.', $userId, $e);
        }
    }

    // Re-sends the frame this device's tap authorised, and nothing else. The
    // stamp read here is written by confirm() alone, behind the safety-number
    // match, so this can carry a confirmation across but never be the thing
    // that gives one.
    /**
     * @param  array{id: int, state: string, token_hash: string, peer_device_id: string|null, self_confirmed: bool, peer_confirmed: bool}  $live
     */
    private function reEmitOwnConfirm(int $userId, array $live, ?DeviceIdentityDto $identity): void
    {
        if ($identity === null || $live['peer_device_id'] === null) {
            return;
        }

        if (! $live['self_confirmed'] || $live['peer_confirmed']) {
            return;
        }

        try {
            $this->frameCourier->sendConfirm($identity, $live['peer_device_id'], $live['token_hash']);
        } catch (Throwable $e) {
            $this->warn('PendingPairingCourier: re-emitting this device\'s confirm failed.', $userId, $e);
        }
    }

    private function warn(string $message, int $userId, Throwable $e): void
    {
        $this->logger?->warning($message, [
            'user_id' => $userId,
            'exception' => $e::class,
            ...SafeExceptionContext::describe($e),
        ]);
    }
}
