<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class MobileSyncTriggerService
{
    public function __construct(
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly NetworkPolicyResolver $networkPolicy,
        private readonly LanSyncClient $lanSyncClient,
        private readonly RelayClient $relayClient,
        private readonly RelayConfig $relayConfig,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    // Headless-safe - container-resolved with no dependency on a
    // request-scoped global (only the explicit $session argument), so a
    // background artisan command can call it directly outside any HTTP
    // request lifecycle.
    /**
     * @param  string|null  $lanHost  The desktop peer's LAN host, when
     *                                already known/discovered by the
     *                                caller. Peer discovery itself is out
     *                                of scope here.
     * @param  int|null  $lanPort  The desktop's `sync:serve` port.
     * @return bool|null `null` when the tick was skipped (locked / no key /
     *                   sync never enabled / pause-on-cellular gate).
     *                   `true` when a sync attempt completed (LAN or
     *                   relay). `false` when every attempted transport
     *                   failed (including a LAN dial that stayed retryable
     *                   after the bounded single retry).
     */
    public function syncOnce(
        int $userId,
        Session $session,
        ?string $lanHost = null,
        ?int $lanPort = null,
    ): ?bool {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            // Locked, no key, or sync never enabled for this user - skip
            // the tick entirely. Data stays encrypted; no key is cached
            // anywhere outside the session.
            $this->logger?->info('MobileSyncTriggerService: no usable device identity — skipping tick.');

            return null;
        }

        if (! $this->networkPolicy->shouldSyncNow()) {
            // Pause-on-cellular is ON and the current connection is
            // positively confirmed cellular/expensive - skip this tick.
            // Sync-on-any-network remains the default posture.
            $this->logger?->info('MobileSyncTriggerService: D-10 pause-on-cellular gate — skipping tick.');

            return null;
        }

        if ($lanHost !== null && $lanPort !== null) {
            if ($this->dialLanWithBoundedRetry($lanHost, $lanPort, $identity, $session)) {
                return true;
            }
        }

        return $this->relayLeg($identity);
    }

    // Re-drives exactly ONCE on a retryable outcome (the iOS Local
    // Network Privacy first-attempt denial). Never an unbounded loop: at
    // most two LanSyncClient::syncOnce() calls per invocation.
    private function dialLanWithBoundedRetry(string $host, int $port, DeviceIdentityDto $identity, Session $session): bool
    {
        if ($this->lanSyncClient->syncOnce($host, $port, $identity, $session)) {
            return true;
        }

        // Single bounded retry — the OS local-network permission prompt may
        // resolve between the first and second attempt.
        return $this->lanSyncClient->syncOnce($host, $port, $identity, $session);
    }

    // Off-LAN leg (relay is opt-in, zero-knowledge). A real, bounded
    // RelayClient::drain() round-trip, never a fabricated success. This
    // leg proves the relay is reachable/configured and drains this
    // device's mailbox; it does not yet interpret or confirm drained rows.
    private function relayLeg(DeviceIdentityDto $identity): bool
    {
        if (! $this->relayConfig->isConfigured()) {
            return false;
        }

        $token = $this->relayConfig->deriveDeviceToken($identity->deviceId);
        if ($token === null) {
            return false;
        }

        try {
            $this->relayClient->drain($identity->deviceId, $token);

            return true;
        } catch (Throwable $e) {
            $this->logger?->info('MobileSyncTriggerService: relay leg unreachable (retryable).', [
                'reason' => $e::class,
            ]);

            return false;
        }
    }
}
