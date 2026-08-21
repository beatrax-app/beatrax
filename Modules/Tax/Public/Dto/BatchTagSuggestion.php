<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

// untaggedCount is the counterparty's OTHER untagged transactions in the same
// tax year, counted right after a row is tagged.
final class BatchTagSuggestion extends Data
{
    public function __construct(
        public readonly int $counterpartyId,
        public readonly string $counterpartyName,
        public readonly int $untaggedCount,
    ) {}
}
