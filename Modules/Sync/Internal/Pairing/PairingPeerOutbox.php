<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use JsonException;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;

// A local holding place for frames this device cannot push, because the peer it
// is addressed to runs no listener. A phone never advertises and never accepts a
// connection, so the desktop's PAIR_CONFIRM has no road home; it waits here and
// the phone collects it on the poll it already runs.
//
// It reuses `relay_mailbox` rather than adding a table of its own: the shape is
// already exactly right — routed by device id, one pending index, its own
// garbage collection — and a second table with the same columns is how two
// expiry policies drift apart.
//
// Only pairing frames are ever served out of it. Epoch wraps wait in the same
// mailbox for the authenticated sync session to carry them, and handing one to
// whoever asked would mark it delivered and strand the peer without that
// epoch's key — the exact failure the relay drain already guards against.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
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

        return $this->mailbox->deliverIfUnderQuota($senderDid, $recipientDid, $blob, self::MAX_PENDING_PER_PEER);
    }

    /**
     * Hands over the pairing frames waiting for $recipientDid and marks them
     * delivered. Anything that is not a pairing frame is left where it is.
     *
     * @return list<array<string, mixed>>
     */
    public function takeFor(string $recipientDid, int $limit): array
    {
        $taken = [];

        foreach ($this->mailbox->drain($recipientDid, $limit) as $row) {
            $frame = self::pairingFrameFrom($row->blob ?? null);

            if ($frame === null) {
                continue;
            }

            $taken[] = $frame;

            if (is_int($row->id ?? null)) {
                $this->mailbox->confirm($row->id);
            }
        }

        return $taken;
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

        if (! is_array($decoded)) {
            return null;
        }

        // Rebuilt key by key rather than passed through: a JSON array decodes
        // to a list with integer keys, which is not a frame, and refusing it
        // here means nothing downstream has to wonder.
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
