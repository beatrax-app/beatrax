<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use JsonException;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;

// Holds frames addressed to a peer that runs no listener. Reuses `relay_mailbox`
// rather than a second table with the same columns, and serves only pairing
// frames out of it — an epoch wrap handed over here would be marked delivered and
// strand the peer without that key (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#the-two-roads-and-why-the-lan-one-had-to-be-built
 */
final readonly class PairingPeerOutbox
{
    // A handshake puts at most two frames in flight per peer. The cap is a
    // flood guard, not a working limit.
    private const int MAX_PENDING_PER_PEER = 16;

    public function __construct(private RelayMailbox $mailbox) {}

    /**
     * @param  array<string, mixed>  $frame
     * @return bool false when this peer's holding space is already full
     */
    public function queueFor(string $senderDid, string $recipientDid, array $frame): bool
    {
        try {
            $blob = json_encode($frame, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return $this->mailbox->deliverIfUnderQuota($senderDid, $recipientDid, $blob, self::MAX_PENDING_PER_PEER, foldIdentical: true);
    }

    // Anything that is not a pairing frame is left where it is. Nothing is
    // marked delivered here: the caller confirms once it has actually handed
    // the frames over, so a failed answer leaves them waiting.
    /**
     * @return list<array{id: int, frame: array<string, mixed>}>
     */
    public function takeFor(string $recipientDid, int $limit): array
    {
        $taken = [];

        foreach ($this->mailbox->drain($recipientDid, $limit) as $row) {
            $frame = self::pairingFrameFrom($row->blob ?? null);
            $id = $row->id ?? null;

            if ($frame === null || ! is_int($id)) {
                continue;
            }

            $taken[] = ['id' => $id, 'frame' => $frame];
        }

        return $taken;
    }

    /**
     * @param  list<int>  $ids
     */
    public function confirmDelivered(array $ids): void
    {
        foreach ($ids as $id) {
            $this->mailbox->confirm($id);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function pairingFrameFrom(mixed $blob): ?array
    {
        if (! is_string($blob)) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($blob, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? self::frameOfKnownType($decoded) : null;
    }

    // Rebuilt key by key rather than passed through: a JSON array decodes to a
    // list with integer keys, which is not a frame, and refusing it here means
    // nothing downstream has to wonder.
    /**
     * @param  array<mixed>  $decoded
     * @return array<string, mixed>|null
     */
    private static function frameOfKnownType(array $decoded): ?array
    {
        $frame = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $frame[$key] = $value;
        }

        $type = isset($frame['type']) && is_string($frame['type'])
            ? PairingFrameType::tryFrom($frame['type'])
            : null;

        return $type === null ? null : $frame;
    }
}
