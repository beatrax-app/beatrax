<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class SourceTransactionDto extends Data
{
    /**
     * @param  array<int|string, mixed>  $rawPayload
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
        public readonly ?int $settledAmountMinor = null,
        public readonly ?string $settledCurrency = null,
    ) {}

    // What the account itself moved by, which is the settled leg wherever the
    // bank converted and the row's own figure wherever it did not. Adding the
    // native leg instead puts a dollar figure into a euro total, and that total
    // is what a statement's closing balance is checked against.
    public function accountSideMinor(): int
    {
        return $this->settledAmountMinor ?? $this->amountMinor;
    }

    public function accountSideCurrency(): string
    {
        return $this->settledCurrency ?? $this->currency;
    }
}
