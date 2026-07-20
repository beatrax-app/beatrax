<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Pairing\Bip39WordList;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class DeviceRegistryService
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    // The ONLY confirmed-only source OpLogReplayer accepts for its
    // $deviceKeys map — an unconfirmed key here would be a forged-op vector.
    /**
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

    // Used by the Noise handshake authenticator: the Noise static key is the
    // X25519 keypair, NOT the Ed25519 signing key. Same confirmed-only trust
    // anchor as deviceKeys() — an unconfirmed key can never reach handshake.
    /**
     * @return array<string, string> device_id => hex X25519 public key.
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
     * @return string 6 BIP39 words, space-separated.
     */
    public function safetyNumberFor(string $selfPubHex, string $peerPubHex): string
    {
        $deriver = new SafetyNumberDeriver(Bip39WordList::WORDS);

        return implode(' ', $deriver->deriveWords($selfPubHex, $peerPubHex));
    }

    // Sanctioned crossing for Modules\Notifications, which may not reach
    // Modules\Sync\Internal\Identity directly. Reads only public data (no
    // key material, no app-lock unlock). Null (unpaired) is non-error.
    public function localDeviceId(int $userId): ?string
    {
        $deviceId = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        return is_string($deviceId) && $deviceId !== '' ? $deviceId : null;
    }

    // Sanctioned crossing for Modules\Notifications; excludes the local
    // device's own row. Mirrors confirmedDevices()'s confirmed-only filter.
    /**
     * @return array<string, string> device_id => name.
     */
    public function otherDeviceNames(int $userId): array
    {
        /** @var array<string, string> $names */
        $names = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->where('is_self', 0)
            ->pluck('name', 'device_id')
            ->all();

        return $names;
    }
}
