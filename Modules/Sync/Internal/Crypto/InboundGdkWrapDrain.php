<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Psr\Log\LoggerInterface;

// The retry the outcome enum exists for. A wrap that could not be opened is
// held in this device's own inbox, and this drain is what comes back for it
// from a context that holds the app-lock key — which the listener daemon
// never does, so without a caller outside it Deferred was a permanent state.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
final readonly class InboundGdkWrapDrain
{
    public const int MAX_WRAPS_PER_PASS = 100;

    // How far one pass will page to find those wraps. A row it cannot retire —
    // another protocol's blob, or a wrap it could not open — stays pending, so
    // without paging past them the first hundred hold the window against every
    // legitimate wrap behind them, for as long as they live.
    public const int MAX_ROWS_SCANNED_PER_PASS = 1000;

    public function __construct(
        private RelayMailbox $mailbox,
        private GdkEpochControlHandler $handler,
        private LoggerInterface $logger,
    ) {}

    // Applies every wrap addressed to THIS device that it can, retires only
    // the carriers whose outcome allows it, and expires what the mailbox TTL
    // says is dead. Returns how many keys this pass actually applied.
    public function drainFor(int $userId, string $localDeviceId, Session $session): int
    {
        if ($localDeviceId === '') {
            return 0;
        }

        $this->mailbox->garbageCollect();

        $applied = 0;
        $handled = 0;
        $scanned = 0;
        $cursorCreatedAt = null;
        $cursorId = null;

        while ($scanned < self::MAX_ROWS_SCANNED_PER_PASS && $handled < self::MAX_WRAPS_PER_PASS) {
            $rows = $this->mailbox->drain($localDeviceId, self::MAX_WRAPS_PER_PASS, $cursorCreatedAt, $cursorId);

            if ($rows === []) {
                return $applied;
            }

            foreach ($rows as $row) {
                $scanned++;
                $cursorCreatedAt = is_string($row->created_at ?? null) ? $row->created_at : null;
                $cursorId = is_numeric($row->id ?? null) ? (int) $row->id : null;

                $outcome = $this->consumeRow($row, $userId, $session);
                if ($outcome === null) {
                    continue;
                }

                $handled++;
                $applied += $outcome === GdkWrapOutcome::Applied ? 1 : 0;
            }

            if ($cursorCreatedAt === null || $cursorId === null) {
                return $applied;
            }
        }

        return $applied;
    }

    // Keeps a wrap that arrived over a live session but could not be retired,
    // so the sender may retire ITS copy: without this the phone's push path
    // destroyed a deferred key on both sides at once.
    public function retain(string $senderDeviceId, string $localDeviceId, string $blob): void
    {
        $this->mailbox->deliver($senderDeviceId, $localDeviceId, $blob);
    }

    public function apply(string $blob, int $userId, Session $session): GdkWrapOutcome
    {
        return $this->handler->handle($blob, $userId, $session);
    }

    // Reads only the envelope type of a blob this device is a party to. Not a
    // ZK violation: the relay's own store-and-forward path never calls this,
    // and two protocols share one mailbox, so handing a pairing frame to the
    // wrap handler got it refused and then confirmed away.
    public static function isEpochWrap(string $blob): bool
    {
        /** @var mixed $decoded */
        $decoded = json_decode($blob, true);

        return is_array($decoded)
            && isset($decoded['type'])
            && $decoded['type'] === GdkEpochControlHandler::MSG_GDK_EPOCH_WRAP;
    }

    // Null for a row this drain is not the reader of, so another protocol's
    // blob never spends the wrap budget — it only costs a scan.
    private function consumeRow(\stdClass $row, int $userId, Session $session): ?GdkWrapOutcome
    {
        $blob = is_string($row->blob ?? null) ? $row->blob : '';
        $rowId = is_numeric($row->id ?? null) ? (int) $row->id : null;

        if ($blob === '' || $rowId === null || ! self::isEpochWrap($blob)) {
            return null;
        }

        $outcome = $this->handler->handle($blob, $userId, $session);

        if (! $outcome->consumesCarrier()) {
            $this->logger->info('InboundGdkWrapDrain: inbound epoch wrap held in the mailbox for a later pass.');

            return $outcome;
        }

        $this->mailbox->confirm($rowId);

        return $outcome;
    }
}
