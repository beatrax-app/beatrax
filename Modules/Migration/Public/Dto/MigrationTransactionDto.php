<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class MigrationTransactionDto extends Data
{
    /**
     * @param  array<int, array{category_source_external_id: ?string, amount: Money, note: ?string}>  $splits
     * @param  array<int|string, mixed>  $rawPayload
     */
    public function __construct(
        public readonly ?string $sourceExternalId,
        public readonly string $accountSourceExternalId,
        public readonly CarbonImmutable $postedAt,
        public readonly Money $amount,
        public readonly ?string $payeeSourceExternalId,
        public readonly ?string $categorySourceExternalId,
        public readonly ?string $description,
        public readonly string $clearedStatus,
        public readonly int $sourceRowIndex,
        public readonly array $rawPayload,
        public readonly ?string $transferCounterpartSourceExternalId = null,
        public readonly array $splits = [],
    ) {}
}
