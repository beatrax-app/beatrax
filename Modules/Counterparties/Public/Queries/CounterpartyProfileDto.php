<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

// Unlike CounterpartyIndexRow this does carry `iban` for personal rows,
// so every rendering path must gate on the user's Show-IBAN opt-in.
final readonly class CounterpartyProfileDto
{
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
    ) {}
}
