<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

// The two answers one enrichment phase owes, which are not the same number:
// `count` is what the reader is shown and what the run records, `adopted` is
// what a peer has to be told. An enrichment that strengthens a reference moves
// no booking term and belongs to the first alone.
final class AppliedEnrichments extends Data
{
    /**
     * @param  list<AdoptedBooking>  $adopted
     */
    public function __construct(
        public readonly int $count,
        public readonly array $adopted = [],
    ) {}
}
