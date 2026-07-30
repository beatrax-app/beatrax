<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Exceptions\SessionNotAuthenticatedException;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class SyncSession
{
    // NOT a singleton — mutable crypto state per peer, so each connection
    // gets a new SyncSession.

    private ?NoiseSession $noiseSession = null;

    private ?string $peerDeviceId = null;

    private string $status = 'handshaking';

    private ?int $sessionRowId = null;

    public function __construct(
        private readonly DeviceRegistryService $registryService,
        private readonly DeviceKeySigner $signer,
        private readonly OpLogReplayer $replayer,
        private readonly TransportFramer $framer,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    // Compares the 32-byte raw X25519 key against the confirmed-device map
    // from DeviceRegistryService::deviceX25519Keys(). On success, writes
    // sync_sessions (status='active') and updates device_registry.last_seen_at.
    // On failure, writes sync_sessions (status='failed') and returns false.
    /**
     * @param  NoiseSession  $noiseSession  Completed handshake session.
     * @param  int  $userId  Owner user — all queries are scoped to this user.
     * @param  string  $localDeviceId  Our device_id for the session row.
     * @return bool True when the peer is a confirmed device and the session is admitted.
     */
    public function authenticate(
        NoiseSession $noiseSession,
        int $userId,
        string $localDeviceId,
    ): bool {
        $peerKeyHex = sodium_bin2hex($noiseSession->peerStaticPublicKey());
        $confirmedKeys = $this->registryService->deviceX25519Keys($userId);

        $matchedDeviceId = null;
        foreach ($confirmedKeys as $deviceId => $keyHex) {
            if (hash_equals($keyHex, $peerKeyHex)) {
                $matchedDeviceId = $deviceId;
                break;
            }
        }

        $now = $this->clock->now()->toIso8601String();

        if ($matchedDeviceId === null) {
            $this->status = 'failed';
            $this->upsertSessionRow(
                userId: $userId,
                localDeviceId: $localDeviceId,
                peerDeviceId: 'unknown',
                status: 'failed',
                errorMessage: 'Peer X25519 static key not in confirmed device_registry.',
                connectedAt: null,
                lastSeenAt: $now,
            );

            return false;
        }

        $this->noiseSession = $noiseSession;
        $this->peerDeviceId = $matchedDeviceId;
        $this->status = 'active';

        $this->upsertSessionRow(
            userId: $userId,
            localDeviceId: $localDeviceId,
            peerDeviceId: $matchedDeviceId,
            status: 'active',
            errorMessage: null,
            connectedAt: $now,
            lastSeenAt: $now,
        );

        $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $matchedDeviceId)
            ->update(['last_seen_at' => $now, 'updated_at' => $now]);

        return true;
    }

    /**
     * @throws \RuntimeException if session not authenticated yet or on AEAD failure.
     */
    public function encrypt(string $plaintext): string
    {
        if ($this->noiseSession === null) {
            throw SessionNotAuthenticatedException::forOperation('encrypt');
        }

        return $this->noiseSession->encrypt($plaintext);
    }

    /**
     * @throws \RuntimeException if session not authenticated or AEAD fails.
     */
    public function decrypt(string $ciphertext): string
    {
        if ($this->noiseSession === null) {
            throw SessionNotAuthenticatedException::forOperation('decrypt');
        }

        return $this->noiseSession->decrypt($ciphertext);
    }

    /**
     * @param  list<OpLogEntry>  $entries
     * @return string Noise ciphertext ready to send over WebSocket.
     */
    public function sendOps(array $entries): string
    {
        if ($this->noiseSession === null) {
            throw SessionNotAuthenticatedException::forOperation('sendOps');
        }

        $frame = $this->framer->encode($entries);

        return $this->noiseSession->encrypt($frame);
    }

    // Noise authenticates the socket; Ed25519 additionally authenticates
    // who originally signed each op, since entries can be forwarded via
    // relay/replayed from disk — the transport channel is not the signing
    // boundary. Entries with an unknown key or bad signature are dropped.
    /**
     * @param  string  $ciphertext  Received Noise ciphertext from the wire.
     * @param  int  $userId  Owner user — passed to replayer for scope guard.
     * @param  array<string, string>  $deviceKeys  device_id => hex Ed25519 public key
     *                                             (from DeviceRegistryService::deviceKeys()).
     */
    public function receiveOps(string $ciphertext, int $userId, array $deviceKeys): void
    {
        if ($this->noiseSession === null) {
            throw SessionNotAuthenticatedException::forOperation('receiveOps');
        }

        $frame = $this->noiseSession->decrypt($ciphertext);
        $entries = $this->framer->decode($frame);

        $verified = [];
        foreach ($entries as $entry) {
            $pubKeyHex = $deviceKeys[$entry->deviceId] ?? null;
            if ($pubKeyHex === null) {
                $this->logger?->warning('SyncSession: dropped entry with unknown device key.', [
                    'device_id' => $entry->deviceId,
                    'reason' => 'missing_device_key',
                ]);

                continue;
            }

            $pubKeyBin = sodium_hex2bin($pubKeyHex);
            if (! $this->signer->verify($entry->signingPayload(), $entry->signature, $pubKeyBin)) {
                $this->logger?->warning('SyncSession: dropped entry with invalid signature.', [
                    'device_id' => $entry->deviceId,
                    'reason' => 'signature_invalid',
                ]);

                continue;
            }

            $verified[] = $entry;
        }

        if ($verified === []) {
            return;
        }

        // Synchronous: SyncWebSocketHandler bounds attacker pacing
        // out-of-band via a per-receive TimeoutCancellation and a
        // MAX_CATCHUP_FRAMES cap, so a malicious peer cannot pin the fiber
        // or grow the op_log unboundedly via this call.
        $this->replayer->replay($verified, $userId);
    }

    public function close(): void
    {
        $this->status = 'closed';
        $this->noiseSession = null;

        if ($this->sessionRowId !== null) {
            $now = $this->clock->now()->toIso8601String();
            $this->db->connection()
                ->table('sync_sessions')
                ->where('id', $this->sessionRowId)
                ->update([
                    'status' => 'closed',
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * @return 'handshaking'|'active'|'closed'|'failed'
     */
    public function status(): string
    {
        /** @var 'handshaking'|'active'|'closed'|'failed' */
        return $this->status;
    }

    public function peerDeviceId(): ?string
    {
        return $this->peerDeviceId;
    }

    private function upsertSessionRow(
        int $userId,
        string $localDeviceId,
        string $peerDeviceId,
        string $status,
        ?string $errorMessage,
        ?string $connectedAt,
        string $lastSeenAt,
    ): void {
        $now = $this->clock->now()->toIso8601String();

        if ($this->sessionRowId !== null) {
            $this->db->connection()
                ->table('sync_sessions')
                ->where('id', $this->sessionRowId)
                ->update([
                    'status' => $status,
                    'error_message' => $errorMessage,
                    'connected_at' => $connectedAt,
                    'last_seen_at' => $lastSeenAt,
                    'updated_at' => $now,
                ]);

            return;
        }

        $rowId = $this->db->connection()
            ->table('sync_sessions')
            ->insertGetId([
                'user_id' => $userId,
                'local_device_id' => $localDeviceId,
                'peer_device_id' => $peerDeviceId,
                'status' => $status,
                'error_message' => $errorMessage,
                'connected_at' => $connectedAt,
                'last_seen_at' => $lastSeenAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->sessionRowId = $rowId;
    }
}
