<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use JsonException;

// A PAIR_CONFIRM this device could not act on yet, parked on its own pairing
// row. It is the frame as it arrived and nothing else: replaying it runs the
// whole verifier again, so what is stored grants exactly what an inbound copy
// would (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#a-deferred-confirm-is-held-not-dropped
 */
final readonly class HeldPeerConfirm
{
    public const string COLUMN = 'deferred_peer_confirm';

    public function __construct(private DatabaseManager $db) {}

    public function hold(int $rowId, int $userId, string $confirmingDeviceId, string $peerDeviceId, string $sigHex): void
    {
        try {
            $blob = json_encode([
                'confirming_device_id' => $confirmingDeviceId,
                'peer_device_id' => $peerDeviceId,
                'sig_hex' => $sigHex,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        $this->db->connection()->table('pairing_tokens')
            ->where('id', $rowId)
            ->where('user_id', $userId)
            ->update([self::COLUMN => $blob]);
    }

    // Read off a row the caller already holds, so the replay costs no second
    // query on the ordinary path where nothing was ever held.
    /**
     * @return array{confirming_device_id: string, peer_device_id: string, sig_hex: string}|null
     */
    public function on(\stdClass $row): ?array
    {
        $blob = $row->{self::COLUMN} ?? null;

        if (! is_string($blob) || $blob === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($blob, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? self::frameFrom($decoded) : null;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return array{confirming_device_id: string, peer_device_id: string, sig_hex: string}|null
     */
    private static function frameFrom(array $decoded): ?array
    {
        $confirming = $decoded['confirming_device_id'] ?? null;
        $peer = $decoded['peer_device_id'] ?? null;
        $sig = $decoded['sig_hex'] ?? null;

        if (! is_string($confirming) || ! is_string($peer) || ! is_string($sig)) {
            return null;
        }

        return ['confirming_device_id' => $confirming, 'peer_device_id' => $peer, 'sig_hex' => $sig];
    }
}
