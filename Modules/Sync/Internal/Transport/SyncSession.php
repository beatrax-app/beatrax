<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Public\Services\DeviceRegistryService;

/**
 * Per-peer transport session: Noise auth gate + additive Ed25519 op verification.
 *
 * ## Lifecycle
 *
 *   1. Caller completes a Noise IK or XX handshake and has a NoiseSession.
 *   2. authenticate($noiseSession, $userId, $localDeviceId): verifies the peer's X25519
 *      static key against DeviceRegistryService::deviceX25519Keys() (confirmed-only,
 *      user-scoped). If the key is not found → status='failed'.
 *      If the key is found → status='active'; device_registry.last_seen_at updated.
 *   3. sendOps(): Noise-encrypts a TransportFramer frame and returns the ciphertext.
 *   4. receiveOps(): Noise-decrypts → TransportFramer::decode → DeviceKeySigner::verify
 *      per entry (additive Ed25519 — Pitfall 7, T-13-09b) → OpLogReplayer::replay for
 *      verified entries. Entries that fail verify are silently dropped.
 *
 * ## Status transitions
 *
 *   handshaking → active (after successful authenticate())
 *   handshaking → failed (after failed authenticate())
 *   active → closed (after close())
 *
 * ## Database writes
 *
 *   - sync_sessions.status + timestamps written on transition.
 *   - device_registry.last_seen_at written on successful authenticate() (closes Phase 12 stub).
 *
 * ## Design notes
 *
 *   - NOT a singleton (mutable crypto state per peer). Each connection gets a new SyncSession.
 *   - receiveOps() calls OpLogReplayer::replay() inline. The SyncWebSocketHandler wraps
 *     large catch-up receive calls in Amp\async() so the event loop is not blocked
 *     (Pitfall 3, T-13-12). This class stays synchronous for testability.
 *
 * @internal Plan 04 — used by SyncWebSocketHandler.
 */
final class SyncSession
{
    /**
     * Active Noise session (set after successful authenticate()).
     */
    private ?NoiseSession $noiseSession = null;

    /**
     * The peer's device_id from device_registry (set after successful authenticate()).
     */
    private ?string $peerDeviceId = null;

    /**
     * The current session status.
     */
    private string $status = 'handshaking';

    /**
     * The database row id for this session record (set once the row is inserted).
     */
    private ?int $sessionRowId = null;

    public function __construct(
        private readonly DeviceRegistryService $registryService,
        private readonly DeviceKeySigner $signer,
        private readonly OpLogReplayer $replayer,
        private readonly TransportFramer $framer,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Authenticate the peer whose static key is revealed in $noiseSession.
     *
     * Compares the 32-byte raw X25519 key (hex-encoded) against the confirmed-device
     * map from DeviceRegistryService::deviceX25519Keys(). On success, writes
     * sync_sessions (status='active') and updates device_registry.last_seen_at.
     * On failure, writes sync_sessions (status='failed') and returns false.
     *
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

        // Find the device_id whose X25519 key matches the peer's revealed key.
        $matchedDeviceId = null;
        foreach ($confirmedKeys as $deviceId => $keyHex) {
            if (hash_equals($keyHex, $peerKeyHex)) {
                $matchedDeviceId = $deviceId;
                break;
            }
        }

        $now = $this->clock->now()->toIso8601String();

        if ($matchedDeviceId === null) {
            // Unknown or unconfirmed device — reject (T-13-01b, D-01 trust anchor).
            $this->status = 'failed';
            $this->upsertSessionRow(
                userId: $userId,
                localDeviceId: $localDeviceId,
                peerDeviceId: 'unknown',
                status: 'failed',
                errorMessage: 'Peer X25519 static key not in confirmed device_registry (T-13-01b).',
                connectedAt: null,
                lastSeenAt: $now,
            );

            return false;
        }

        // Admitted — mark active.
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

        // Close the Phase 12 stub: write device_registry.last_seen_at on connect.
        $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $matchedDeviceId)
            ->update(['last_seen_at' => $now, 'updated_at' => $now]);

        return true;
    }

    /**
     * Noise-encrypt an arbitrary binary or JSON string.
     *
     * Used by SyncWebSocketHandler to encrypt catch-up control messages
     * (CATCH_UP_REQUEST / CATCH_UP_RESPONSE / CATCH_UP_COMPLETE JSON).
     *
     * @throws \RuntimeException if session not authenticated yet or on AEAD failure.
     */
    public function encrypt(string $plaintext): string
    {
        if ($this->noiseSession === null) {
            throw new \RuntimeException('SyncSession::encrypt — session not authenticated yet.');
        }

        return $this->noiseSession->encrypt($plaintext);
    }

    /**
     * Noise-decrypt a binary ciphertext to a plaintext string.
     *
     * Used by SyncWebSocketHandler to decrypt catch-up control messages.
     *
     * @throws \RuntimeException if session not authenticated or AEAD fails.
     */
    public function decrypt(string $ciphertext): string
    {
        if ($this->noiseSession === null) {
            throw new \RuntimeException('SyncSession::decrypt — session not authenticated yet.');
        }

        return $this->noiseSession->decrypt($ciphertext);
    }

    /**
     * Encrypt and frame a list of OpLogEntry objects for sending over the wire.
     *
     * Frames are encoded via TransportFramer then Noise-encrypted.
     *
     * @param  list<OpLogEntry>  $entries
     * @return string Noise ciphertext ready to send over WebSocket.
     */
    public function sendOps(array $entries): string
    {
        if ($this->noiseSession === null) {
            throw new \RuntimeException('SyncSession::sendOps — session not authenticated yet.');
        }

        $frame = $this->framer->encode($entries);

        return $this->noiseSession->encrypt($frame);
    }

    /**
     * Decrypt, deserialize, and replay received ciphertext.
     *
     * Security posture (additive Ed25519 — RESEARCH Pitfall 7, T-13-09b):
     *   1. Noise-decrypt the ciphertext.
     *   2. TransportFramer::decode into OpLogEntry objects.
     *   3. For each entry, call DeviceKeySigner::verify() against deviceKeys().
     *      Entries that fail verification are SILENTLY DROPPED (not replayed).
     *      The replayer's own quarantine handles the audit trail for forged entries.
     *   4. Verified entries go to OpLogReplayer::replay().
     *
     * @param  string  $ciphertext  Received Noise ciphertext from the wire.
     * @param  int  $userId  Owner user — passed to replayer for I1/I2 scope guard.
     * @param  array<string, string>  $deviceKeys  device_id => hex Ed25519 public key
     *                                             (from DeviceRegistryService::deviceKeys()).
     */
    public function receiveOps(string $ciphertext, int $userId, array $deviceKeys): void
    {
        if ($this->noiseSession === null) {
            throw new \RuntimeException('SyncSession::receiveOps — session not authenticated yet.');
        }

        // Step 1: Noise-decrypt.
        $frame = $this->noiseSession->decrypt($ciphertext);

        // Step 2: TransportFramer::decode.
        $entries = $this->framer->decode($frame);

        // Step 3: Additive Ed25519 gate — verify every entry before replay (Pitfall 7, T-13-09b).
        // Noise authentication proves who sent the message over this socket; Ed25519 proves
        // who originally signed each op. Both are required — Noise auth never replaces per-op
        // signatures (entries may be forwarded via relay, played back from disk, or forwarded
        // through a third device, so the transport channel is not the signing boundary).
        $verified = [];
        foreach ($entries as $entry) {
            $pubKeyHex = $deviceKeys[$entry->deviceId] ?? null;
            if ($pubKeyHex === null) {
                // Missing device key → not replayed (replayer would quarantine 'missing_device_key').
                continue;
            }

            $pubKeyBin = sodium_hex2bin($pubKeyHex);
            if (! $this->signer->verify($entry->signingPayload(), $entry->signature, $pubKeyBin)) {
                // Forged or tampered signature → not replayed (T-13-09b).
                continue;
            }

            $verified[] = $entry;
        }

        if ($verified === []) {
            return;
        }

        // Step 4: replay verified entries.
        // This call is synchronous. When running inside the amphp event loop
        // (SyncWebSocketHandler), the handler wraps large catch-up batches in
        // Amp\async() so the fiber yields and other connections remain responsive
        // (Pitfall 3, T-13-12 accept/mitigate).
        $this->replayer->replay($verified, $userId);
    }

    /**
     * Mark the session closed and update sync_sessions.status.
     */
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
     * Current session status.
     *
     * @return 'handshaking'|'active'|'closed'|'failed'
     */
    public function status(): string
    {
        /** @var 'handshaking'|'active'|'closed'|'failed' */
        return $this->status;
    }

    /**
     * The authenticated peer's device_id (null before successful authenticate()).
     */
    public function peerDeviceId(): ?string
    {
        return $this->peerDeviceId;
    }

    /**
     * Upsert the sync_sessions row for this session.
     *
     * Uses UPDATE if we already have a row id, otherwise INSERT.
     */
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
