<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// The two ids one arriving row has: the number the peer minted for it, and the
// number it is addressed by here. They differ wherever an alias between them
// has already been recorded, and a question put to the wrong one of the two is
// answered about a row nobody named.
/**
 * @link ../../../../.docs/features/sync/architecture.md#an-alias-the-answer-could-never-find
 */
final readonly class ArrivingRowId
{
    public function __construct(
        public int|string $peer,
        public int|string $here,
    ) {}
}
