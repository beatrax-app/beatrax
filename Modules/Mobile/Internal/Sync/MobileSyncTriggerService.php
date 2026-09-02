<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Illuminate\Contracts\Session\Session;
use Modules\Mobile\Internal\Exceptions\LanSyncException;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityState;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class MobileSyncTriggerService
{
    public function __construct(
        private DeviceIdentityLoader $identityLoader,
        private NetworkPolicyResolver $networkPolicy,
        private LanSyncClient $lanSyncClient,
        private RelayClient $relayClient,
        private RelayConfig $relayConfig,
        private GdkEpochDeliveryGateway $epochDelivery,
        private ?LoggerInterface $logger = null,
    ) {}

    // The narrow answer, kept for the callers that only branch on it. A skip
    // and a failed dial are both `null` here, which is why a screen reporting
    // to a reader asks attempt() instead.
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
        return match ($this->attempt($userId, $session, $lanHost, $lanPort)) {
            SyncAttemptOutcome::Synced => true,
            SyncAttemptOutcome::Unreachable => false,
            default => null,
        };
    }

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
     */
    public function attempt(
        int $userId,
        Session $session,
        ?string $lanHost = null,
        ?int $lanPort = null,
    ): SyncAttemptOutcome {
        [$state, $identity] = $this->identityLoader->loadWithState($userId, $session);

        // The first of two reasons to skip, keeping its own log line. No
        // identity means locked, no key, or sync never enabled — data stays
        // encrypted and no key is cached outside the session — and which of
        // the three it is decides what the reader is told.
        if ($identity === null) {
            $this->logger?->info('MobileSyncTriggerService: no usable device identity — skipping tick.');

            return match ($state) {
                DeviceIdentityState::Absent => SyncAttemptOutcome::NotEnabled,
                DeviceIdentityState::Unreadable => SyncAttemptOutcome::Unreadable,
                default => SyncAttemptOutcome::Locked,
            };
        }

        // The second: pause-on-cellular is ON and the link is confirmed
        // expensive. Asked only once an identity opened, so an unpaired
        // device reports what it actually lacks.
        if (! $this->networkPolicy->shouldSyncNow()) {
            $this->logger?->info('MobileSyncTriggerService: pause-on-cellular gate — skipping tick.');

            return SyncAttemptOutcome::PausedOnCellular;
        }

        // ALWAYS drain the relay, never only as a LAN fallback. A working
        // LAN leg used to skip this entirely, leaving anything queued in the
        // mailbox — epoch wraps included — unread for as long as the LAN
        // stayed reachable.
        $relayReached = $this->relayLeg($identity);

        $lanReached = $lanHost !== null
            && $lanPort !== null
            && $this->dialLanWithBoundedRetry($lanHost, $lanPort, $identity, $session);

        // This tick holds the app-lock key by construction — the identity
        // above would be null otherwise — so it is the pass that can open a
        // wrap an earlier, locked one had to leave in the mailbox.
        $this->epochDelivery->drainInbox($userId, $identity->deviceId, $session);

        return $lanReached || $relayReached
            ? SyncAttemptOutcome::Synced
            : SyncAttemptOutcome::Unreachable;
    }

    // Re-drives exactly ONCE on a retryable outcome (the iOS Local
    // Network Privacy first-attempt denial). Never an unbounded loop: at
    // most two LanSyncClient::syncOnce() calls per invocation.
    private function dialLanWithBoundedRetry(string $host, int $port, DeviceIdentityDto $identity, Session $session): bool
    {
        try {
            if ($this->lanSyncClient->syncOnce($host, $port, $identity, $session)) {
                return true;
            }

            // Single bounded retry — the OS local-network permission prompt may
            // resolve between the first and second attempt.
            return $this->lanSyncClient->syncOnce($host, $port, $identity, $session);
        } catch (LanSyncException $e) {
            // The relay leg below degrades to "not reached" on any failure and
            // this one did not, so a peer refusing the auth gate left the
            // button raising instead of reporting. Never reached is the truth
            // either way; the reason belongs in the log, not at the reader.
            $this->logger?->info('MobileSyncTriggerService: LAN leg refused (not reached).', [
                'reason' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
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

        $token = $this->drainSecret();

        return $token !== null && $this->drainMailbox($identity->deviceId, $token);
    }

    // This device presents its OWN per-device drain secret, TOFU-verified by
    // the relay and matching PairingFrameCourier — the shared HMAC token every
    // relay peer could recompute is gone. Minting can only fail on a
    // secrets-file write error, treated here as nothing to dial.
    private function drainSecret(): ?string
    {
        try {
            return $this->relayConfig->deviceDrainSecret();
        } catch (Throwable $e) {
            $this->logger?->info('MobileSyncTriggerService: could not resolve device drain secret.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function drainMailbox(string $deviceId, string $token): bool
    {
        try {
            $this->relayClient->drain($deviceId, $token);

            return true;
        } catch (Throwable $e) {
            // The class alone says "connection failed" and nothing about why;
            // the message carries the curl error that distinguishes a refused
            // TLS handshake from a timeout or a wrong address.
            $this->logger?->info('MobileSyncTriggerService: relay leg unreachable (retryable).', [
                'reason' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
