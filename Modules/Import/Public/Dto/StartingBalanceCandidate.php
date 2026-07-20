<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/import/architecture.md#starting-balance-detection
 */
final class StartingBalanceCandidate extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $openingBalanceMinor,
        public readonly string $openingBalanceDate,
        public readonly string $sourceFormat,
    ) {}
}
