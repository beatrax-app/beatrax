<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Crypto\InboundGdkWrapDrain;
use Modules\Sync\Internal\Transport\PendingMailboxScan;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;

// The door a transport outside this module uses to move GDK wraps. It owns
// the mailbox side of the exchange so a caller never has to reason about what
// an outcome means: hand it a wrap and it says whether the SENDER may retire
// its copy, having kept one here if it could not be applied.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
final class GdkEpochDeliveryGateway
{
    // The wire vocabulary of the authenticated session, named on the public
    // surface so a transport in another module compares against a constant
    // rather than its own copy of the string.
    public const string MSG_EPOCH_WRAP = GdkEpochControlHandler::MSG_GDK_EPOCH_WRAP;

    public const string MSG_EPOCH_PUSH = 'GDK_EPOCH_PUSH';

    public const string MSG_EPOCH_ACK = 'GDK_EPOCH_ACK';

    public const string MSG_PEER_REVOKED = 'PEER_REVOKED';

    // Clamped identically by the side that announces a catch-up stream and the
    // side that reads it: two budgets could disagree, and the reader's is the
    // one standing between a confirmed-but-malicious peer and an endless loop.
    public const int MAX_CATCHUP_FRAMES = 100_000;

    public function __construct(
        private readonly InboundGdkWrapDrain $drain,
        private readonly RelayMailbox $mailbox,
        private readonly PendingMailboxScan $scan,
    ) {}

    public static function maxWrapsPerPass(): int
    {
        return InboundGdkWrapDrain::MAX_WRAPS_PER_PASS;
    }

    // Never throws on a malformed/foreign/tampered blob. SECURITY
    // PRECONDITION: $json MUST have arrived over a channel that has already
    // authenticated the sender as a CONFIRMED peer — never wire this to an
    // unauthenticated source.
    /**
     * @param  string  $senderDeviceId  Routing metadata for the copy kept on a non-terminal outcome.
     * @param  string  $localDeviceId  This device's mailbox address.
     * @return bool Whether the wrap is durably accounted for, so the sender may retire its copy.
     */
    public function receiveEpochWrap(
        string $json,
        int $userId,
        string $senderDeviceId,
        string $localDeviceId,
        Session $session,
    ): bool {
        if ($this->drain->apply($json, $userId, $session)->consumesCarrier()) {
            return true;
        }

        if ($localDeviceId === '') {
            return false;
        }

        // A key the sender is about to forget, that this process could not
        // open, has exactly one place left to live: this device's own inbox,
        // where an unlocked pass comes back for it.
        $this->drain->retain($senderDeviceId, $localDeviceId, $json);

        return true;
    }

    // The wraps this device has queued FOR a peer. Symmetric delivery is what
    // makes the tie-break converge: a device that only ever receives leaves
    // the other side deciding over a keyed-rows flag it was never sent.
    /**
     * @return list<array{id: int, blob: string}>
     */
    public function pendingWrapsFor(string $peerDeviceId): array
    {
        if ($peerDeviceId === '') {
            return [];
        }

        $wraps = [];

        foreach ($this->scan->rows($peerDeviceId, InboundGdkWrapDrain::MAX_WRAPS_PER_PASS, InboundGdkWrapDrain::MAX_ROWS_SCANNED_PER_PASS) as $row) {
            $id = is_numeric($row->id ?? null) ? (int) $row->id : null;
            $blob = is_string($row->blob ?? null) ? $row->blob : '';

            if ($id === null || $blob === '' || ! InboundGdkWrapDrain::isEpochWrap($blob)) {
                continue;
            }

            $wraps[] = ['id' => $id, 'blob' => $blob];

            if (count($wraps) >= InboundGdkWrapDrain::MAX_WRAPS_PER_PASS) {
                break;
            }
        }

        return $wraps;
    }

    // Called only once the peer has said how many it accounted for, so an
    // interrupted push really is retried on the next connect rather than
    // retired the instant it was handed to the transport.
    public function confirmDelivered(int $id): void
    {
        $this->mailbox->confirm($id);
    }

    public function drainInbox(int $userId, string $localDeviceId, Session $session): int
    {
        return $this->drain->drainFor($userId, $localDeviceId, $session);
    }
}
