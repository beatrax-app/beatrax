<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Services\SealedLedgerRecovery;
use Modules\Core\Public\Support\SafeExceptionContext;
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
        private SealedLedgerRecovery $sealedLedgerRecovery,
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

        // The local leg first, with its own bounded retry: an op log only ever
        // travels over the LAN, and that leg hands over the epoch keys ahead of
        // the entries they decrypt. Draining first put a round-trip to a remote
        // host in front of every tick a peer on this network could have served.
        $lanReached = $lanHost !== null
            && $lanPort !== null
            && $this->dialLanWithBoundedRetry($lanHost, $lanPort, $identity, $session);

        // Then the relay, whether or not the LAN answered: it is a fallback in
        // ORDER, not in whether it runs. It carries no ops — only epoch wraps —
        // and skipping it on a working LAN left a wrap from a device that is
        // not this one's LAN peer unread for as long as the LAN held up.
        $relayReached = $this->relayLeg($identity, $session);

        // This tick holds the app-lock key by construction — the identity
        // above would be null otherwise — so it is the pass that can open a
        // wrap an earlier, locked one had to leave in the mailbox.
        $this->epochDelivery->drainInbox($userId, $identity->deviceId, $session);

        // A key this tick just took is what a held entry was waiting for. The
        // desktop runs this after every response; the phone has no such
        // middleware, so a gdk_decrypt_failed row — recoverable by definition —
        // had nothing to retire it once setup was over.
        $this->recoverHeldEntries($userId, $session);

        return $lanReached || $relayReached
            ? SyncAttemptOutcome::Synced
            : SyncAttemptOutcome::Unreachable;
    }

    // Never fails the tick: both legs have already run and been accounted for,
    // and an entry this pass could not place is placed by the next one.
    private function recoverHeldEntries(int $userId, Session $session): void
    {
        try {
            $this->sealedLedgerRecovery->recover($userId, $session);
        } catch (Throwable $e) {
            $this->logger?->warning(
                'MobileSyncTriggerService: held-entry recovery pass failed.',
                SafeExceptionContext::describe($e),
            );
        }
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

    // Off-LAN leg (relay is opt-in, zero-knowledge). Reached means the relay
    // answered AND every wrap it held for this device is off it, in the keyring
    // or in this device's own inbox. It used to mean a bare round-trip that
    // discarded what it downloaded, so the mailbox refilled the phone forever.
    private function relayLeg(DeviceIdentityDto $identity, Session $session): bool
    {
        if (! $this->relayConfig->isConfigured()) {
            return false;
        }

        $token = $this->drainToken($identity->deviceId);

        return $token !== null && $this->drainMailbox($identity, $token, $session);
    }

    // A token minted for THIS device id and no other, matching what
    // PairingFrameCourier presents for the same id. Minting can only fail on a
    // secrets-file write error, treated here as nothing to dial.
    private function drainToken(string $deviceId): ?string
    {
        try {
            return $this->relayConfig->deviceDrainToken($deviceId);
        } catch (Throwable $e) {
            $this->logger?->info('MobileSyncTriggerService: could not resolve device drain token.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function drainMailbox(DeviceIdentityDto $identity, string $token, Session $session): bool
    {
        $rows = $this->drainRows($identity->deviceId, $token);

        if ($rows === null) {
            return false;
        }

        $owed = 0;
        $settled = 0;

        foreach ($rows as $row) {
            $wrap = self::epochWrapOf($row);

            // Another protocol's frame, left where PairingFrameCourier polls
            // for it. Handing one to the wrap gateway gets it Refused and then
            // deleted, which is a handshake step the peer is still waiting on.
            if ($wrap === null) {
                continue;
            }

            $owed++;
            $settled += $this->settleWrap($identity, $token, $wrap, $session) ? 1 : 0;
        }

        return $settled === $owed;
    }

    /**
     * @return list<array<string, mixed>>|null null when the round-trip failed
     */
    private function drainRows(string $deviceId, string $token): ?array
    {
        try {
            return $this->relayClient->drain($deviceId, $token);
        } catch (Throwable $e) {
            // The class alone says "connection failed" and nothing about why;
            // the message carries the curl error that distinguishes a refused
            // TLS handshake from a timeout or a wrong address.
            $this->logger?->info('MobileSyncTriggerService: relay leg unreachable (retryable).', [
                'reason' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // The gateway keeps a copy in this device's OWN inbox when this pass
    // cannot open the wrap, so its true is the promise that the relay's copy
    // may go — never "we looked at it". Confirming ahead of that answer is how
    // the only copy of an epoch key gets deleted off the one device holding it.
    /**
     * @param  array{id: int, blob: string, senderDid: string}  $wrap
     */
    private function settleWrap(DeviceIdentityDto $identity, string $token, array $wrap, Session $session): bool
    {
        if (! $this->epochDelivery->receiveEpochWrap($wrap['blob'], $identity->userId, $wrap['senderDid'], $identity->deviceId, $session)) {
            return false;
        }

        try {
            $this->relayClient->confirm($wrap['id'], $token);

            return true;
        } catch (Throwable $e) {
            $this->logger?->info('MobileSyncTriggerService: drained wrap accounted for but not confirmed off the relay.', [
                'reason' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // Null for every row this leg is not the reader of: an unusable id, a blob
    // that is not base64, or another protocol's envelope type.
    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int, blob: string, senderDid: string}|null
     */
    private static function epochWrapOf(array $row): ?array
    {
        $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
        $blobB64 = isset($row['blob']) && is_string($row['blob']) ? $row['blob'] : '';
        $blob = base64_decode($blobB64, true);

        if ($id === null || $blob === false) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($blob, true);

        if (! is_array($decoded) || ($decoded['type'] ?? null) !== GdkEpochDeliveryGateway::MSG_EPOCH_WRAP) {
            return null;
        }

        $senderDid = $row['sender_did'] ?? null;

        return ['id' => $id, 'blob' => $blob, 'senderDid' => is_string($senderDid) ? $senderDid : ''];
    }
}
