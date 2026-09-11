<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// What one replay batch is, as against which field of it is being applied: the
// reader it is scoped to, the moment it lands, and the split leg amounts it
// will write. OpLogEntryApplier is readonly and cannot hold them itself.
final readonly class ArrivingBatch
{
    /**
     * @param  array<int|string, int>  $splitAmounts
     */
    public function __construct(
        public int $userId,
        public string $now,
        public array $splitAmounts = [],
    ) {}
}
