<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of a source statement, parsed into a typed shape but NOT yet
 * normalized into a CanonicalTransaction. The Import pipeline's
 * NormalizeStage maps these to canonical rows (counterparty_normalized,
 * fingerprint composition, account_id resolution, etc.).
 *
 * `rawPayload` preserves the original source cells (indexed by column
 * position) for ING-08 audit. `sourceRowIndex` is monotonically increasing
 * starting at 0 across one parse run.
 */
final class SourceTransactionDto extends Data
{
    /**
     * @param  array<int|string,string>  $rawPayload
     */
    public function __construct(
        public readonly CarbonImmutable $bookedAt,
        public readonly CarbonImmutable $postedAt,
        public readonly CarbonImmutable $valueDate,
        public readonly string $ownIban,
        public readonly ?string $counterpartyIban,
        public readonly ?string $counterpartyName,
        public readonly string $currency,
        public readonly int $amountMinor,
        public readonly ?string $sourceRef,
        public readonly ?string $description,
        public readonly array $rawPayload,
        public readonly int $sourceRowIndex,
    ) {}
}
