<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Sync\Internal\Pairing\Bip39WordList;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;

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

    // Every key this registry still remembers, CONFIRMED OR REVOKED: the map
    // that verifies HISTORY, never the one that admits a peer. Removal shuts
    // the Noise transport to the device, so retention grants it nothing and
    // deviceKeys() stays the admission anchor.
    /**
     * @return array<string, string> device_id => hex Ed25519 public key.
     */
    public function retainedDeviceKeys(int $userId): array
    {
        /** @var array<string, string> $keys */
        $keys = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->pluck('ed25519_public_key_hex', 'device_id')
            ->all();

        return $keys;
    }

    // Whether this device is STILL a confirmed peer, asked live rather than
    // read from a connect-time snapshot. Removing a device cleared the row
    // but never reached the open connection, so a revoked peer kept syncing
    // until it happened to reconnect.
    public function isStillConfirmed(int $userId, string $deviceId): bool
    {
        if ($deviceId === '') {
            return false;
        }

        return $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    // Whether a removal is something this registry can report about the device
    // behind that handshake key: a row it holds with the confirmation taken
    // off. Never an admission gate — nothing is let in on a `true`, and the
    // `false` separates "I removed you" from "I have never met you".
    /**
     * @link ../../../../.docs/features/sync/device-removal-and-epoch-rotation.md
     */
    public function holdsRevokedDeviceWithKeyAgreementKey(int $userId, string $x25519PublicKeyHex): bool
    {
        if ($x25519PublicKeyHex === '') {
            return false;
        }

        return $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->where('x25519_public_key_hex', $x25519PublicKeyHex)
            ->whereNull('confirmed_at')
            ->exists();
    }

    // Drops this side's confirmation of a peer that has said it no longer
    // confirms us. Never the self row: a device answering for itself here
    // would revoke the only identity this install has.
    public function forgetPeerConfirmation(int $userId, string $deviceId): void
    {
        if ($deviceId === '') {
            return;
        }

        $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('is_self', 0)
            ->update(['confirmed_at' => null]);
    }

    // Every REACHABLE trace of a device, AFTER its trust is revoked and the
    // keyring rotated. The device_registry row itself stays: already revoked,
    // so every confirmed-only query steps over it, and its public key is the
    // only thing that can still verify the history the device wrote.
    /**
     * @link ../../../../.docs/features/sync/device-removal-and-epoch-rotation.md
     */
    public function purge(int $userId, int $deviceRegistryId): void
    {
        $connection = $this->db->connection();

        $deviceId = $connection->table('device_registry')
            ->where('id', $deviceRegistryId)
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->value('device_id');

        if (! is_string($deviceId) || $deviceId === '') {
            return;
        }

        $connection->table('sync_sessions')
            ->where('user_id', $userId)
            ->where('peer_device_id', $deviceId)
            ->delete();

        $connection->table('relay_mailbox')
            ->where('recipient_did', $deviceId)
            ->orWhere('sender_did', $deviceId)
            ->delete();

        $connection->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where(function (Builder $query) use ($deviceId): void {
                $query->where('responder_device_id', $deviceId)
                    ->orWhere('initiator_device_id', $deviceId);
            })
            ->delete();

        // GdkRotationService clears this first, and purge() must not DEPEND on
        // that having happened: a purge is a removal, and a removal that leaves
        // confirmed_at standing leaves the device trusted.
        $connection->table('device_registry')
            ->where('id', $deviceRegistryId)
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->update(['confirmed_at' => null]);
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

    // This device's own display name, for the QR the peer scans. Null when
    // sync was never enabled here, in which case the peer keeps its
    // placeholder rather than being told something wrong.
    public function localDeviceName(int $userId): ?string
    {
        $name = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    // User-agnostic form of localDeviceId(), for callers that run before any
    // session exists — the desktop boot hook decides whether to start the
    // sync listener at all, and a listener is only worth running once some
    // account on this device has actually enabled sync.
    public function hasLocalDevice(): bool
    {
        return $this->db->connection()
            ->table('device_registry')
            ->where('is_self', 1)
            ->exists();
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
