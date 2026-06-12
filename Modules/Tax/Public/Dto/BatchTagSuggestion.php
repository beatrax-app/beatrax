<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One counterparty suggestion for the batch-tag prompt on TaxPage.
 *
 * TaxPage (Plan 05) shows "this counterparty has N other untagged
 * transactions in this year — tag them all?" after tagging a row.
 */
final class BatchTagSuggestion extends Data
{
    public function __construct(
        public readonly int $counterpartyId,
        public readonly string $counterpartyName,
        public readonly int $untaggedCount,
    ) {}
}
