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
 * Orchestrates ONE bounded foreground/manual sync burst for the mobile peer
 * (D-07/D-08, MOBILE-01). NEVER a listener/daemon — every call is a single
 * KEK-gated, network-policy-gated dial-out-or-relay attempt that returns.
 *
 * ## KEK gate (LOCK-04, RESEARCH Anti-Pattern #3, T-15-12)
 *
 * `syncOnce()` loads the device identity via `DeviceIdentityLoader`. A
 * `null` identity — locked app-lock, no KEK, or sync never enabled for this
 * user — SKIPS the tick entirely: no data is touched, no key is cached
 * anywhere outside the session. This mirrors the exact "KEK-null hard-
 * throws inside the crypto layer, but the CALLER treats it as skip-not-
 * fatal" posture established across Phases 12-14.
 *
 * ## Transport selection
 *
 * When a LAN host/port is supplied (resolved by the caller — peer
 * discovery is out of this plan's scope) AND the network policy allows it,
 * `LanSyncClient::syncOnce()` is dispatched. A single RETRYABLE outcome
 * (see `LanSyncClient`'s docblock — this covers the iOS Local Network
 * Privacy first-attempt denial, T-15-33) is re-driven exactly ONCE — never
 * an unbounded loop. When LAN sync is unavailable or still fails after the
 * bounded retry, the off-LAN leg consults the configured relay
 * (`RelayClient`/`RelayConfig`).
 *
 * @internal Plan 05 — the single sync-burst entry point Plan 08's initial-
 *           sync puller and Plan 09's background-pull command both call.
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

    /**
     * Run one bounded sync burst for $userId.
     *
     * `syncOnce()` is headless-safe — it is container-resolved and takes no
     * dependency on a request-scoped global (only the explicit `$session`
     * argument), so a background artisan command (Plan 09) can call it
     * directly outside any HTTP request lifecycle.
     *
     * @param  string|null  $lanHost  The desktop peer's LAN host, when
     *                                already known/discovered by the
     *                                caller. Peer discovery itself is out
     *                                of this plan's scope.
     * @param  int|null  $lanPort  The desktop's `sync:serve` port.
     * @return bool|null `null` when the tick was SKIPPED (locked / no KEK /
     *                   sync never enabled / D-10 pause-on-cellular gate).
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
            // RESEARCH Anti-Pattern #3 / T-15-12: locked, no KEK, or sync
            // never enabled for this user — skip the tick entirely. Data
            // stays encrypted; no key is cached anywhere outside the
            // session DeviceIdentityLoader/AppLockKeyService already own.
            $this->logger?->info('MobileSyncTriggerService: no usable device identity — skipping tick.');

            return null;
        }

        if (! $this->networkPolicy->shouldSyncNow()) {
            // D-10 pause-on-cellular is ON and the current connection is
            // positively confirmed cellular/expensive — skip this tick.
            // D-09 (sync on any network) remains the default posture.
            $this->logger?->info('MobileSyncTriggerService: D-10 pause-on-cellular gate — skipping tick.');

            return null;
        }

        if ($lanHost !== null && $lanPort !== null) {
            if ($this->dialLanWithBoundedRetry($lanHost, $lanPort, $identity)) {
                return true;
            }
        }

        return $this->relayLeg($identity);
    }

    /**
     * Dial the LAN peer, re-driving exactly ONCE on a retryable outcome
     * (T-15-33 — the iOS Local Network Privacy first-attempt denial).
     * Never an unbounded loop: at most two `LanSyncClient::syncOnce()`
     * calls per `syncOnce()` invocation.
     */
    private function dialLanWithBoundedRetry(string $host, int $port, DeviceIdentityDto $identity): bool
    {
        if ($this->lanSyncClient->syncOnce($host, $port, $identity)) {
            return true;
        }

        // Single bounded retry — the OS local-network permission prompt may
        // resolve between the first and second attempt.
        return $this->lanSyncClient->syncOnce($host, $port, $identity);
    }

    /**
     * Off-LAN leg (D-01 relay is opt-in, zero-knowledge). A real, bounded
     * `RelayClient::drain()` round-trip — never a fabricated success.
     *
     * NOTE (scope decision, logged in deferred-items.md): `RelayClient`'s
     * only established content type anywhere in this codebase is the GDK
     * epoch-wrap sealed-box flow (Phase 14 Plan 08,
     * `SyncWebSocketHandler::deliverGdkEpochWraps()`, which reads the LOCAL
     * `RelayMailbox` table on the desktop that already holds a live
     * session). A general op-log catch-up burst over a store-and-forward
     * relay would need its own durable, non-ephemeral-Noise-session
     * encryption scheme that does not exist yet anywhere in this codebase
     * — inventing one here would cross the SPEC's "wire protocol consumed
     * as-is" boundary. This leg therefore proves the relay is
     * reachable/configured and drains this device's mailbox (a real, safe,
     * already-established `RelayClient` operation); it does not yet
     * interpret or confirm drained rows. Full off-LAN op-log delivery is
     * deferred to a future plan.
     */
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
