<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One row in the preview table. The wizard renders a NEW/DUPLICATE/ERROR
 * badge per row based on `status`. `error` carries the per-row failure
 * message when `status === 'error'`.
 */
final class PreviewRowDto extends Data
{
    public function __construct(
        public readonly int $rowIndex,
        /** 'new' | 'duplicate' | 'error' */
        public readonly string $status,
        public readonly ?int $accountId,
        public readonly ?string $bookedAt,
        public readonly ?string $counterpartyName,
        public readonly ?string $categoryName,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?string $error,
    ) {}
}
