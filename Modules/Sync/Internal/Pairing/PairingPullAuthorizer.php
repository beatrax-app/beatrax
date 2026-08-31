<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

// Who may collect what is waiting on this listener. The pull route is served on
// every interface, so being on the wifi must not be the qualification (see
// @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#proving-you-are-the-device-the-frames-are-for
 */
final readonly class PairingPullAuthorizer
{
    // A device has one handshake in flight; the bound is a flood guard against
    // a table that prune() is expected to keep short but does not police.
    private const int MAX_ROWS_CONSIDERED = 8;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private DeviceKeySigner $signer,
    ) {}

    public function mayCollect(int $userId, string $collectingDeviceId, string $proofSigHex): bool
    {
        if ($collectingDeviceId === '' || $proofSigHex === '') {
            return false;
        }

        $message = PairingFrame::pullProofMessage($collectingDeviceId);

        foreach ($this->keysBoundToDevice($userId, $collectingDeviceId) as $publicKeyHex) {
            try {
                $publicKey = SafetyNumberDeriver::hexToRawKey($publicKeyHex);
            } catch (InvalidPublicKeyException) {
                continue;
            }

            if ($this->signer->verify($message, $proofSigHex, $publicKey)) {
                return true;
            }
        }

        return false;
    }

    // Every Ed25519 key a live handshake on this device has bound to that
    // device id. Only a row this device is itself a party to can qualify a
    // caller, so another household's pairing cannot be collected here.
    /**
     * @return list<string>
     */
    private function keysBoundToDevice(int $userId, string $collectingDeviceId): array
    {
        $rows = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('expires_at', '>', Instant::zulu($this->clock->now()))
            ->orderByDesc('id')
            ->limit(self::MAX_ROWS_CONSIDERED)
            ->get([
                'initiator_device_id',
                'initiator_ed25519_pub_hex',
                'responder_device_id',
                'responder_ed25519_pub_hex',
            ]);

        $keys = [];

        foreach ($rows as $row) {
            $side = PairingRowGuards::sideOwnedBy($row, $collectingDeviceId);
            $key = $side === null ? null : $row->{$side->columnPrefix().'ed25519_pub_hex'};

            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
