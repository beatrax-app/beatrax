<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

final readonly class MobileSyncCursor
{
    public function __construct(
        public int $recordsApplied,
        public int $recordsExpected,
        public int $lastHlcL,
        public int $lastHlcC,
        public string $phase,
        public ?string $reprojectedAt,
    ) {}
}
