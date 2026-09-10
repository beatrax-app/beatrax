<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Dto;

use Modules\Sync\Public\Events\EntityMutated;

// The ops are handed back rather than dispatched: the fold runs inside the
// caller's transaction, and an op log describing a merge no local row matches
// is one the paired device performs anyway.
final readonly class CounterpartyMergeDto
{
    /**
     * @param  list<array{name: string, slug: string, moved: int}>  $absorbed
     * @param  list<EntityMutated>  $events
     */
    public function __construct(
        public ?int $survivingId,
        public array $absorbed,
        public int $movedTransactions,
        public array $events,
    ) {}

    public static function nothingToFold(): self
    {
        return new self(null, [], 0, []);
    }
}
