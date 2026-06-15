<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Pairing\Bip39WordList;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;

/**
 * Public façade over the trusted-device registry (PAIR-01/PAIR-03).
 *
 * This is the ONLY input OpLogReplayer accepts for its $deviceKeys map. The
 * deviceKeys() query MUST filter `confirmed_at IS NOT NULL` (T-12-04, Pitfall
 * 2): an unconfirmed device key in the replayer is a forged-op vector — the
 * device was never safety-number verified (D-07). Every query is also scoped
 * to user_id (T-12-08) so no cross-user key material leaks.
 */
final readonly class DeviceRegistryService
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    /**
     * Confirmed-only device-id → hex Ed25519 public-key map for $userId.
     *
     * CRITICAL: only rows where confirmed_at IS NOT NULL are returned. This is
     * the exact shape OpLogReplayer's $deviceKeys constructor arg expects.
     *
     * @return array<string, string> device_id => hex Ed25519 public key.
     */
    public function deviceKeys(int $userId): array
    {
        /** @var array<string, string> $keys */
        $keys = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->pluck('ed25519_public_key_hex', 'device_id')
            ->all();

        return $keys;
    }

    /**
     * Confirmed device rows for the device-list UI (PAIR-03), user-scoped.
     *
     * @return array<int, \stdClass>
     */
    public function confirmedDevices(int $userId): array
    {
        return $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->orderBy('paired_at')
            ->get()
            ->all();
    }

    /**
     * Confirmed-only device-id → hex X25519 public-key map for $userId.
     *
     * Used by the Noise handshake authenticator (Phase 13 XPORT-01): the
     * Noise static key is the X25519 keypair, NOT the Ed25519 signing key.
     * Only rows where confirmed_at IS NOT NULL are returned — the same trust
     * anchor as deviceKeys() (T-13-01, D-01 of Phase 12). An unconfirmed
     * device key can never reach the Noise handshake.
     *
     * @return array<string, string> device_id => hex X25519 public key (Noise handshake auth).
     */
    public function deviceX25519Keys(int $userId): array
    {
        /** @var array<string, string> $keys */
        $keys = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->pluck('x25519_public_key_hex', 'device_id')
            ->all();

        return $keys;
    }

    /**
     * Compute the shared safety-number for two hex public keys (D-07/D-08).
     *
     * @return string 6 BIP39 words, space-separated.
     */
    public function safetyNumberFor(string $selfPubHex, string $peerPubHex): string
    {
        $deriver = new SafetyNumberDeriver(Bip39WordList::WORDS);

        return implode(' ', $deriver->deriveWords($selfPubHex, $peerPubHex));
    }
}
