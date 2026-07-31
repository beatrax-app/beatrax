<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final readonly class MobileSyncCursor
{
    // The durable per-(user, peer) initial-sync progress state persisted to
    // mobile_sync_progress. Groups the six advancing fields so the writer
    // takes one snapshot rather than a long positional argument list.
    public function __construct(
        public int $recordsApplied,
        public int $recordsExpected,
        public int $lastHlcL,
        public int $lastHlcC,
        public string $phase,
        public ?string $reprojectedAt,
    ) {}
}
