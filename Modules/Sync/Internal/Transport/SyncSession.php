<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Internal\Exceptions\SessionNotAuthenticatedException;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\PriorAuthorship;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;
use Throwable;

final class SyncSession
{
    // NOT a singleton — mutable crypto state per peer, so each connection
    // gets a new SyncSession.

    private ?NoiseSession $noiseSession = null;

    // A peer reconnects every couple of seconds; without this it would mean
    // a database write every couple of seconds, purely for bookkeeping.
    // How often a live session bothers to write its last-seen stamp.
    private static function lastSeenThrottleSeconds(): int
    {
        return Duration::Minute->seconds();
    }

    private ?string $peerDeviceId = null;

    private ?string $localDeviceId = null;

    private ?PriorAuthorship $authorship = null;

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
        $matchedDeviceId = array_find_key($confirmedKeys, fn ($keyHex): bool => hash_equals($keyHex, $peerKeyHex));

        $now = Instant::zulu($this->clock->now());

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
        $this->localDeviceId = $localDeviceId;
        $this->status = 'active';

        // Bookkeeping only, deliberately best-effort: losing a race for the
        // SQLite write lock used to throw straight out of the handshake and
        // close the connection, so the catch-up that was about to run never
        // did and no epoch ever reached the peer.
        $this->touchLastSeen($userId, $matchedDeviceId, $now);

        $this->upsertSessionRow(
            userId: $userId,
            localDeviceId: $localDeviceId,
            peerDeviceId: $matchedDeviceId,
            status: 'active',
            errorMessage: null,
            connectedAt: $now,
            lastSeenAt: $now,
        );

        return true;
    }

    // Throttled: a reconnect every couple of seconds does not need a write
    // every couple of seconds, and each one competes for the single SQLite
    // writer that the app itself is using.
    private function touchLastSeen(int $userId, string $deviceId, string $now): void
    {
        try {
            $connection = $this->db->connection();

            $lastSeen = $connection->table('device_registry')
                ->where('user_id', $userId)
                ->where('device_id', $deviceId)
                ->value('last_seen_at');

            if (is_string($lastSeen) && $lastSeen !== '') {
                $age = $this->clock->now()->diffInSeconds(CarbonImmutable::parse($lastSeen), absolute: true);

                if ($age < self::lastSeenThrottleSeconds()) {
                    return;
                }
            }

            $connection->table('device_registry')
                ->where('user_id', $userId)
                ->where('device_id', $deviceId)
                ->update(['last_seen_at' => $now, 'updated_at' => $now]);
        } catch (Throwable $e) {
            $this->logger?->debug('SyncSession: last_seen_at write skipped.', [
                'reason' => $e::class,
            ]);
        }
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

    // Noise authenticates the socket; Ed25519 additionally authenticates
    // who originally signed each op, since entries can be forwarded via
    // relay/replayed from disk — the transport channel is not the signing
    // boundary. Entries with an unknown key or bad signature are dropped.
    /**
     * @param  string  $ciphertext  Received Noise ciphertext from the wire.
     * @param  int  $userId  Owner user — passed to replayer for scope guard.
     * @param  array<string, string>  $deviceKeys  device_id => hex Ed25519 public key
     *                                             (DeviceRegistryService::signatureVerificationKeys()).
     */
    public function receiveOps(string $ciphertext, int $userId, array $deviceKeys): void
    {
        if ($this->noiseSession === null) {
            throw SessionNotAuthenticatedException::forOperation('receiveOps');
        }

        $frame = $this->noiseSession->decrypt($ciphertext);
        $entries = $this->framer->decode($frame);

        // The caller's map is the whole gate. A key the registry merely RETAINS
        // belongs to a device nothing confirms, so the replayer refuses its new
        // work anyway — and admitting it here spent this peer's cursor on an
        // entry no later confirmation could bring back.
        $verified = [];

        // Kept apart from the refusals below: these are not refused, they are
        // already ours. They advance the peer's cursor and reach no strategy.
        $ownHistory = [];

        // Counted per author and reported once. A line per entry is why this
        // went unread: a peer whose history was signed by a retired identity
        // wrote the same warning six thousand times, and the run still looked
        // like an ordinary sync from every surface above it.
        $unverifiableAuthors = [];

        foreach ($entries as $entry) {
            $pubKeyHex = $deviceKeys[$entry->deviceId] ?? null;
            if ($pubKeyHex === null) {
                $unverifiableAuthors[$entry->deviceId] = ($unverifiableAuthors[$entry->deviceId] ?? 0) + 1;

                continue;
            }

            $pubKeyBin = sodium_hex2bin($pubKeyHex);
            if (! $this->signer->verifyAny($entry->signatureCandidates(), $entry->signature, $pubKeyBin)) {
                $this->logger?->warning('SyncSession: dropped entry with invalid signature.', [
                    'device_id' => $entry->deviceId,
                    'reason' => 'signature_invalid',
                ]);

                continue;
            }

            if ($this->isOwnHistoryComingBack($entry, $userId)) {
                $ownHistory[] = $entry;

                continue;
            }

            $verified[] = $entry;
        }

        if ($unverifiableAuthors !== []) {
            // Error, not warning: nothing downstream reports this, so an
            // exchange that delivered thousands of entries and applied none
            // of them otherwise reads as a clean sync. HELD, not dropped —
            // the cursor below never sees them, so a peer offers them again.
            $this->logger?->error('SyncSession: held entries no key here verifies the author of.', [
                'reason' => 'author_not_verifiable',
                'held' => array_sum($unverifiableAuthors),
                'received' => count($entries),
                'device_ids' => array_keys($unverifiableAuthors),
                // The two states a reader acts on differently: an author this
                // install once trusted is one an introduction can restore; one
                // it has never heard of is not, and saying so would be a guess.
                // Read HERE, so the common frame costs no query for it.
                'known_to_registry' => array_values(array_intersect(
                    array_keys($unverifiableAuthors),
                    array_keys($this->registryService->retainedDeviceKeys($userId)),
                )),
            ]);
        }

        if ($ownHistory !== []) {
            $this->logger?->debug('SyncSession: skipped this device\'s own entries offered back by a peer.', [
                'device_id' => $this->localDeviceId,
                'skipped' => count($ownHistory),
                'received' => count($entries),
            ]);
        }

        if ($verified === [] && $ownHistory === []) {
            return;
        }

        if ($verified !== []) {
            // Synchronous: SyncWebSocketHandler bounds attacker pacing
            // out-of-band via a per-receive TimeoutCancellation and a
            // MAX_CATCHUP_FRAMES cap, so a malicious peer cannot pin the fiber
            // or grow the op_log unboundedly via this call.
            $this->replayer->replay($verified, $userId);
        }

        // After the replay, never before, and only over what this device
        // accounted for: a cursor advanced past an entry it REFUSED asks the
        // peer to skip it forever. Our own history back is accounted for —
        // withholding it re-offers the same echo on every reconnect.
        $this->watermarks()->advance(
            $userId,
            $this->peerDeviceId ?? '',
            [...$verified, ...$ownHistory],
            Instant::zulu($this->clock->now()),
        );
    }

    // An op THIS device signed, offered back by a peer we sent it to: nothing
    // to merge, since the row was written here before the op was. The durable
    // log is asked rather than assumed, so a device missing its own history
    // still takes it.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-entries-a-locked-desktop-quarantines
     */
    private function isOwnHistoryComingBack(OpLogEntry $entry, int $userId): bool
    {
        if ($this->localDeviceId === null || $entry->deviceId !== $this->localDeviceId) {
            return false;
        }

        $this->authorship ??= new PriorAuthorship($this->db, $this->registryService);

        return $this->authorship->alreadyAccepted($entry, $userId);
    }

    // Built per call rather than injected: this class is constructed by hand in
    // three places, one of them outside this module.
    private function watermarks(): PeerCatchUpWatermarks
    {
        return new PeerCatchUpWatermarks($this->db);
    }

    public function close(): void
    {
        $this->status = 'closed';
        $this->noiseSession = null;

        if ($this->sessionRowId !== null) {
            $now = Instant::zulu($this->clock->now());
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
        $now = Instant::zulu($this->clock->now());

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

        // Keyed on the table's own unique index, not a plain insert: this
        // object lives for ONE connection, so its cached row id is null on
        // every reconnect, and the second connection to a peer died on the
        // (user, local, peer) unique constraint.
        $connection = $this->db->connection();
        $identity = [
            'user_id' => $userId,
            'local_device_id' => $localDeviceId,
            'peer_device_id' => $peerDeviceId,
        ];

        // Status bookkeeping, never a precondition for the exchange: losing a
        // race for the single SQLite writer must not close a session that is
        // otherwise ready to sync.
        try {
            $connection->table('sync_sessions')->updateOrInsert($identity, [
                'status' => $status,
                'error_message' => $errorMessage,
                'connected_at' => $connectedAt,
                'last_seen_at' => $lastSeenAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rowId = $connection->table('sync_sessions')->where($identity)->value('id');

            $this->sessionRowId = is_numeric($rowId) ? (int) $rowId : null;
        } catch (Throwable $e) {
            $this->logger?->debug('SyncSession: session-row write skipped.', [
                'reason' => $e::class,
            ]);
        }
    }
}
