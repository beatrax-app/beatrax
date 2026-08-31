<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

// Unlike CounterpartyIndexRow this does carry `iban` for personal rows,
// so every rendering path must gate on the user's Show-IBAN opt-in.
final readonly class CounterpartyProfileDto
{
    /**
     * @param  string  $currency  the reader's reporting currency, which every figure
     *                            on this profile and its tabs is denominated in
     * @param  list<string>  $unconvertedCurrencies  codes left out for want of a rate
     * @param  bool  $isBankFee  narrows type='bank', which the chain writes both for a
     *                           charge the bank levies and for an institution the reader
     *                           transacts through
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $displayName,
        public string $type,
        public ?string $iban,
        public ?string $merchantName,
        public int $total12mMinor,
        public int $transactionCount,
        public ?string $firstSeenDate,
        public ?string $lastSeenDate,
        public string $currency = '',
        public array $unconvertedCurrencies = [],
        public bool $isBankFee = false,
    ) {}

    public function isPartial(): bool
    {
        return $this->unconvertedCurrencies !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconvertedCurrencies);
    }
}
