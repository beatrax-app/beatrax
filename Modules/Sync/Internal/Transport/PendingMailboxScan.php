<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Modules\Sync\Internal\Transport\Relay\RelayMailbox;

// A bounded, cursor-paged walk over one device's pending mailbox rows. Paging
// PAST a row the reader cannot retire is the point: a deferred wrap and
// another protocol's blob both stay pending, and a window that could not move
// past them showed the same first hundred rows on every pass, forever.
final readonly class PendingMailboxScan
{
    public function __construct(private RelayMailbox $mailbox) {}

    /**
     * @param  int  $pageSize  Rows fetched per query.
     * @param  int  $maxRows  Hard cap on rows one walk will read.
     * @return \Generator<int, \stdClass>
     */
    public function rows(string $deviceId, int $pageSize, int $maxRows): \Generator
    {
        $scanned = 0;
        $cursorCreatedAt = null;
        $cursorId = null;

        while ($scanned < $maxRows) {
            $rows = $this->mailbox->drain($deviceId, $pageSize, $cursorCreatedAt, $cursorId);
            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $scanned++;
                [$cursorCreatedAt, $cursorId] = self::cursorAt($row);

                yield $row;
            }

            // A last row whose cursor halves are unreadable cannot be paged
            // past, so the walk ends rather than re-reading the same page.
            if ($cursorCreatedAt === null || $cursorId === null) {
                return;
            }
        }
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private static function cursorAt(\stdClass $row): array
    {
        return [
            is_string($row->created_at ?? null) ? $row->created_at : null,
            is_numeric($row->id ?? null) ? (int) $row->id : null,
        ];
    }
}
